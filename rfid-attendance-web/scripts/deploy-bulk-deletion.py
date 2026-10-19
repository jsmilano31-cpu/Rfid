"""Deploy only bulk deletion web files, with baseline drift checks and verified backups."""
from pathlib import Path
import argparse, datetime, ftplib, hashlib, importlib.util, io, json, subprocess

ROOT=Path(__file__).resolve().parent.parent
DOMAIN='domains/jsmkj.space'
FILES=[
    'src/Services/DeletionConflictService.php','src/Services/UserService.php','src/Services/EventService.php','src/Services/BulkDeletionService.php',
    'public/api/bulk-delete.php','public/admin/deletion-conflicts.php','src/Controllers/AdminController.php',
    'public/assets/css/bulk-delete.css','public/assets/js/bulk-delete.js','public/assets/js/app.js','public/assets/js/seat-plans.js',
    'public/assets/js/academic-management.js','public/assets/js/rooms.js','public/assets/js/recollection-management.js',
    'templates/admin/academics.php','templates/admin/events.php','templates/admin/partials/user_accounts.php','templates/admin/recollections.php',
    'templates/admin/rooms.php','templates/admin/users.php','templates/partials/seat_plan_editor.php','templates/layouts/app.php',
]
spec=importlib.util.spec_from_file_location('ftp_tools',ROOT/'scripts/deploy-mobile-api.py')
ftp_tools=importlib.util.module_from_spec(spec); spec.loader.exec_module(ftp_tools)

def remote(path):
    return DOMAIN+('/public_html/'+path.removeprefix('public/') if path.startswith('public/') else '/'+path)

def baseline(path):
    result=subprocess.run(['git','show','HEAD:'+path],cwd=ROOT,capture_output=True)
    return result.stdout if result.returncode==0 else None

def equal(a,b):
    return a is not None and b is not None and a.replace(b'\r\n',b'\n')==b.replace(b'\r\n',b'\n')

def main():
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--deploy',action='store_true'); parser.add_argument('--only',action='append',choices=FILES); args=parser.parse_args()
    files=args.only or FILES
    if not args.deploy:
        for path in files: print(path+' -> '+remote(path))
        return
    backup=ROOT/'storage/bulk-deletion-backups'/datetime.datetime.now().strftime('%Y%m%d-%H%M%S'); backup.mkdir(parents=True,exist_ok=False)
    ftp=ftp_tools.connect(); previous={}; uploaded=[]
    try:
        # Read and validate every destination before the first mutation.
        for path in files:
            data=io.BytesIO()
            try: ftp.retrbinary('RETR '+remote(path),data.write); content=data.getvalue()
            except ftplib.error_perm as error:
                if not str(error).startswith('550'): raise
                content=None
            prior=baseline(path)
            if content is None and prior is not None: raise RuntimeError('Expected production file is missing: '+path)
            if content is not None and not equal(content,prior) and not equal(content,(ROOT/path).read_bytes()): raise RuntimeError('Production drift detected: '+path)
            previous[path]=content
            if content is not None:
                saved=backup/path; saved.parent.mkdir(parents=True,exist_ok=True); saved.write_bytes(content)
        manifest={path:{'remote':remote(path),'existed':previous[path] is not None,'sha256':hashlib.sha256((ROOT/path).read_bytes()).hexdigest()} for path in files}
        (backup/'manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
        try:
            for path in files:
                content=(ROOT/path).read_bytes(); ftp_tools.mkdirs(ftp,remote(path).rsplit('/',1)[0])
                uploaded.append(path); ftp.storbinary('STOR '+remote(path),io.BytesIO(content))
                received=io.BytesIO(); ftp.retrbinary('RETR '+remote(path),received.write)
                if received.getvalue()!=content: raise RuntimeError('Upload verification failed: '+path)
                print('Verified '+path,flush=True)
        except Exception:
            # Restore the exact snapshot; remove only scoped newly created destinations.
            for path in reversed(uploaded):
                if previous[path] is None: ftp.delete(remote(path))
                else: ftp.storbinary('STOR '+remote(path),io.BytesIO(previous[path]))
            raise
        print('Backup: '+str(backup),flush=True)
    finally: ftp.close()

if __name__=='__main__': main()
