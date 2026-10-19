"""Deploy role preview and disciplinary violation support via verified FTPS."""
from pathlib import Path
import argparse,datetime,importlib.util,io,json,re
ROOT=Path(__file__).resolve().parent.parent
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path);value=importlib.util.module_from_spec(spec);spec.loader.exec_module(value);return value
ftp_tools=module('violation_ftp',ROOT/'scripts/deploy-mobile-api.py')
migrations=module('violation_migrations',ROOT/'scripts/deploy-physical-rfid.py')
DOMAIN='domains/jsmkj.space'
FILES=[
    'config/violations.php','src/Core/Auth.php','src/Services/ViolationService.php','src/Controllers/AdminController.php',
    'templates/layouts/app.php','templates/admin/compliance.php','templates/admin/partials/compliance_table.php',
    'database/schema.sql','database/migrations/20260908_disciplinary_violations.sql',
]
PUBLIC=['auth/view-as.php','assets/js/compliance.js','assets/js/app.js','assets/css/app.css']
def mappings():
    return [(ROOT/path,DOMAIN+'/'+path) for path in FILES]+[(ROOT/'public'/path,DOMAIN+'/public_html/'+path) for path in PUBLIC]
def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--deploy',action='store_true');args=parser.parse_args()
    pairs=mappings()
    if not args.deploy:
        for source,target in pairs:print(f'{source.relative_to(ROOT)} -> {target}')
        return
    backup=ROOT/'storage/violation-backups'/datetime.datetime.now().strftime('%Y%m%d-%H%M%S');backup.mkdir(parents=True)
    ftp=ftp_tools.connect()
    try:
        for source,target in pairs:
            old=io.BytesIO()
            try:ftp.retrbinary('RETR '+target,old.write)
            except Exception as error:
                if not str(error).startswith('550'):raise
            else:
                saved=backup/target;saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(old.getvalue())
        sql=re.sub(r'^\s*--.*$','',(ROOT/'database/migrations/20260908_disciplinary_violations.sql').read_text(),flags=re.M)
        migrations.migrate(ftp,[statement.strip() for statement in sql.split(';') if statement.strip()],'disciplinary-violations')
        for source,target in pairs:
            ftp_tools.mkdirs(ftp,target.rsplit('/',1)[0]);body=source.read_bytes()
            ftp.storbinary('STOR '+target,io.BytesIO(body))
            check=io.BytesIO();ftp.retrbinary('RETR '+target,check.write)
            if check.getvalue()!=body:raise RuntimeError('Upload verification failed: '+target)
            print('Verified '+target,flush=True)
        (backup/'manifest.json').write_text(json.dumps([target for _,target in pairs],indent=2))
    finally:ftp.close()
if __name__=='__main__':main()
