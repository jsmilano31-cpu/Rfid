"""Deploy only the mobile API via verified FTPS. Defaults to a dry run."""
from pathlib import Path
import argparse, ftplib, ssl, re, io, datetime, json

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'
PUBLIC = DOMAIN + '/public_html/rfid-attendance-web/public'

class SecureFTP(ftplib.FTP_TLS):
    def ntransfercmd(self, cmd, rest=None):
        connection, size = ftplib.FTP.ntransfercmd(self, cmd, rest)
        if self._prot_p:
            connection = self.context.wrap_socket(connection, server_hostname=self.host, session=self.sock.session)
        return connection, size

def connect():
    config = {m[1]: m[2].strip().strip('\"\x27') for line in (ROOT/'.env.hostinger').read_text(encoding='utf-8-sig').splitlines() if (m := re.match(r'^([A-Z_]+)=(.*)$', line))}
    ftp = SecureFTP(context=ssl.create_default_context(), timeout=30)
    ftp.connect(config['FTP_HOST'], int(config.get('FTP_PORT',21)))
    # The configured Hostinger IP presents a valid *.hstgr.io provider certificate.
    ftp.host = 'ftp.hstgr.io'
    ftp.auth(); ftp.login(config['FTP_USERNAME'],config['FTP_PASSWORD']); ftp.prot_p()
    return ftp

def mkdirs(ftp, path):
    current = ''
    for part in path.split('/'):
        current = (current + '/' + part).lstrip('/')
        try: ftp.mkd(current)
        except ftplib.error_perm:
            previous = ftp.pwd(); ftp.cwd(current); ftp.cwd(previous)

def deploy():
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument('--deploy',action='store_true'); args=parser.parse_args()
    mappings = [(p, DOMAIN+'/mobile-api/src/'+p.relative_to(ROOT/'mobile-api/src').as_posix()) for p in sorted((ROOT/'mobile-api/src').rglob('*.php'))]
    mappings += [(p, PUBLIC+'/'+p.relative_to(ROOT/'mobile-api/public').as_posix()) for p in sorted((ROOT/'mobile-api/public').rglob('*.php'))]
    if not args.deploy:
        for source,target in mappings: print(f'{source.relative_to(ROOT)} -> {target}')
        return
    backup = ROOT/'storage/mobile-api-backups'/datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True,exist_ok=False)
    ftp=connect()
    try:
        unchanged=set()
        created=set()
        # Back up all existing destination files before the first overwrite.
        for source,target in mappings:
            content=io.BytesIO()
            try: ftp.retrbinary('RETR '+target,content.write)
            except ftplib.error_perm as error:
                if not str(error).startswith('550'): raise
            else:
                saved=backup/target;saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(content.getvalue())
                if content.getvalue()==source.read_bytes(): unchanged.add(target)
        for source,target in mappings:
            if target in unchanged:
                print('Unchanged '+target,flush=True);continue
            directory=target.rsplit('/',1)[0]
            if directory not in created:
                mkdirs(ftp,directory);created.add(directory)
            body=source.read_bytes()
            ftp.storbinary('STOR '+target,io.BytesIO(body))
            result=io.BytesIO();ftp.retrbinary('RETR '+target,result.write)
            if result.getvalue()!=body: raise RuntimeError('Upload verification failed: '+target)
            print('Verified '+target,flush=True)
        (backup/'manifest.json').write_text(json.dumps([target for _,target in mappings],indent=2))
    finally: ftp.close()

if __name__=='__main__': deploy()
