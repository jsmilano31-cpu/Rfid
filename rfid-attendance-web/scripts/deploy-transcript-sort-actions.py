"""Deploy transcript links, table sort controls, and recollection actions over verified FTPS."""

from pathlib import Path
import argparse
import datetime
import ftplib
import importlib.util
import io
import json

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'
FILES = [
    'public/admin/recollection.php',
    'public/assets/css/app.css',
    'public/assets/js/admin-control.js',
    'public/assets/js/app.js',
    'public/assets/js/recollection-management.js',
    'src/Services/GradeService.php',
    'templates/admin/grades.php',
    'templates/admin/recollections.php',
]

spec = importlib.util.spec_from_file_location('ftp_tools', ROOT / 'scripts/deploy-mobile-api.py')
ftp_tools = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ftp_tools)


def mapping(path):
    remote = DOMAIN + ('/public_html/' + path.removeprefix('public/') if path.startswith('public/') else '/' + path)
    return ROOT / path, remote


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    args = parser.parse_args()
    pairs = [mapping(path) for path in FILES]
    if not args.deploy:
        for source, target in pairs:
            print(f'{source.relative_to(ROOT)} -> {target}')
        return

    backup = ROOT / 'storage/transcript-sort-actions-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = ftp_tools.connect()
    try:
        for source, target in pairs:
            previous = io.BytesIO()
            try:
                ftp.retrbinary('RETR ' + target, previous.write)
            except ftplib.error_perm as error:
                if not str(error).startswith('550'):
                    raise
            else:
                saved = backup / target
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(previous.getvalue())

        for source, target in pairs:
            body = source.read_bytes()
            ftp_tools.mkdirs(ftp, target.rsplit('/', 1)[0])
            ftp.storbinary('STOR ' + target, io.BytesIO(body))
            check = io.BytesIO()
            ftp.retrbinary('RETR ' + target, check.write)
            if check.getvalue() != body:
                raise RuntimeError('Upload verification failed: ' + target)
            print('Verified ' + target, flush=True)

        (backup / 'manifest.json').write_text(json.dumps([target for _, target in pairs], indent=2), encoding='utf-8')
    finally:
        ftp.close()


if __name__ == '__main__':
    main()
