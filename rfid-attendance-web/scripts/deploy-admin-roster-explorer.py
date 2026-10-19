"""Deploy the admin class roster explorer through verified FTPS. Dry run by default."""
from pathlib import Path
import argparse
import datetime
import importlib.util
import io
import ftplib

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'
FILES = [
    ('public/assets/js/admin-roster-explorer.js', DOMAIN + '/public_html/assets/js/admin-roster-explorer.js'),
    ('public/assets/css/app.css', DOMAIN + '/public_html/assets/css/app.css'),
    ('src/Services/AttendanceService.php', DOMAIN + '/src/Services/AttendanceService.php'),
    ('templates/admin/attendance.php', DOMAIN + '/templates/admin/attendance.php'),
    ('templates/layouts/app.php', DOMAIN + '/templates/layouts/app.php'),
]

spec = importlib.util.spec_from_file_location('ftp_tools', ROOT / 'scripts/deploy-mobile-api.py')
ftp_tools = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ftp_tools)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    args = parser.parse_args()
    if not args.deploy:
        for source, target in FILES:
            print(source + ' -> ' + target)
        return

    backup = ROOT / 'storage/admin-roster-explorer-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = ftp_tools.connect()
    try:
        for source, target in FILES:
            body = (ROOT / source).read_bytes()
            previous = io.BytesIO()
            try:
                ftp.retrbinary('RETR ' + target, previous.write)
            except ftplib.error_perm as error:
                if not str(error).startswith('550'):
                    raise
                ftp_tools.mkdirs(ftp, target.rsplit('/', 1)[0])
            else:
                saved = backup / target
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(previous.getvalue())
                if previous.getvalue() == body:
                    print('Unchanged ' + target, flush=True)
                    continue
            ftp.storbinary('STOR ' + target, io.BytesIO(body))
            uploaded = io.BytesIO()
            ftp.retrbinary('RETR ' + target, uploaded.write)
            if uploaded.getvalue() != body:
                raise RuntimeError('Upload verification failed: ' + target)
            print('Verified ' + target, flush=True)
    finally:
        ftp.close()


if __name__ == '__main__':
    main()
