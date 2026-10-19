"""Deploy notification services, endpoints, assets and additive migration via verified FTPS."""
from pathlib import Path
import argparse, importlib.util, io, datetime, json, re

ROOT = Path(__file__).resolve().parent.parent
def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value); return value
ftp_tools = module('notification_ftp', ROOT/'scripts/deploy-mobile-api.py')
migrations = module('notification_migrations', ROOT/'scripts/deploy-physical-rfid.py')
DOMAIN = 'domains/jsmkj.space'

def mappings():
    # Dependencies first, then hooks/layout. Never expose server keys.
    private = ['src/Services/FcmService.php','src/Services/NotificationService.php','templates/partials/notification_inbox.php','scripts/notification-worker.php']
    public = ['api/notifications.php','assets/css/notifications.css','assets/js/notifications.js','firebase-messaging-sw.php']
    result = [(ROOT/p, DOMAIN+'/'+p) for p in private]
    result += [(ROOT/'public'/p, DOMAIN+'/public_html/'+p) for p in public]
    result += [(p, DOMAIN+'/public_html/rfid-attendance-web/public/notifications/'+p.name) for p in sorted((ROOT/'mobile-api/public/notifications').glob('*.php'))]
    result += [(ROOT/p, DOMAIN+'/'+p) for p in ['src/Services/AttendanceService.php','src/Services/EventService.php','src/Services/RecollectionService.php','src/Core/Auth.php','mobile-api/src/StudentApi.php','templates/layouts/app.php']]
    return result

def main():
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--deploy',action='store_true'); args=parser.parse_args()
    pairs=mappings()
    if not args.deploy:
        for source,target in pairs: print(f'{source.relative_to(ROOT)} -> {target}')
        return
    backup=ROOT/'storage/notification-backups'/datetime.datetime.now().strftime('%Y%m%d-%H%M%S'); backup.mkdir(parents=True)
    ftp=ftp_tools.connect()
    try:
        for source,target in pairs:
            body=io.BytesIO()
            try: ftp.retrbinary('RETR '+target,body.write)
            except Exception as error:
                if not str(error).startswith('550'): raise
            else:
                saved=backup/target; saved.parent.mkdir(parents=True,exist_ok=True); saved.write_bytes(body.getvalue())
        sql=re.sub(r'^\s*--.*$', '', (ROOT/'database/migrations/20260907_notifications.sql').read_text(), flags=re.M)
        migrations.migrate(ftp,[statement.strip() for statement in sql.split(';') if statement.strip()],'notification-tables')
        for source,target in pairs:
            ftp_tools.mkdirs(ftp,target.rsplit('/',1)[0]); body=source.read_bytes()
            ftp.storbinary('STOR '+target,io.BytesIO(body))
            verify=io.BytesIO(); ftp.retrbinary('RETR '+target,verify.write)
            if verify.getvalue()!=body: raise RuntimeError('Upload verification failed: '+target)
            print('Verified '+target,flush=True)
        (backup/'manifest.json').write_text(json.dumps([target for _,target in pairs],indent=2))
    finally: ftp.close()

if __name__=='__main__': main()
