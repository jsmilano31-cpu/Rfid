"""Deploy the grouped sidebar with verified FTPS backups and drift checks."""

from pathlib import Path
import argparse
import datetime
import ftplib
import importlib.util
import io
import json
import subprocess

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'
FILES = [
    'templates/layouts/app.php',
    'public/assets/css/app.css',
    'public/assets/js/sidebar-navigation.js',
]

spec = importlib.util.spec_from_file_location('ftp_tools', ROOT / 'scripts/deploy-mobile-api.py')
ftp_tools = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ftp_tools)


def mapping(path):
    remote = DOMAIN + ('/public_html/' + path.removeprefix('public/') if path.startswith('public/') else '/' + path)
    return ROOT / path, remote


def same_text(left, right):
    return left == right or left.replace(b'\r\n', b'\n') == right.replace(b'\r\n', b'\n')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    args = parser.parse_args()
    pairs = [(path, *mapping(path)) for path in FILES]
    if not args.deploy:
        for path, _, remote in pairs:
            print(f'{path} -> {remote}')
        return

    backup = ROOT / 'storage/sidebar-navigation-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = ftp_tools.connect()
    try:
        previous_files = {}
        for path, source, remote in pairs:
            previous = io.BytesIO()
            try:
                ftp.retrbinary('RETR ' + remote, previous.write)
            except ftplib.error_perm as error:
                if not str(error).startswith('550'):
                    raise
                raise RuntimeError('Expected production file is missing: ' + remote) from error

            body = previous.getvalue()
            previous_files[path] = body
            baseline = subprocess.run(['git', 'show', 'HEAD:' + path], cwd=ROOT, capture_output=True, check=True).stdout
            if not same_text(body, baseline) and not same_text(body, source.read_bytes()):
                raise RuntimeError('Production file differs from the repository baseline: ' + remote)

        for path, _, remote in pairs:
            previous = previous_files[path]
            if previous is not None:
                saved = backup / remote
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(previous)

        for _, source, remote in pairs:
            body = source.read_bytes()
            ftp_tools.mkdirs(ftp, remote.rsplit('/', 1)[0])
            ftp.storbinary('STOR ' + remote, io.BytesIO(body))
            verified = io.BytesIO()
            ftp.retrbinary('RETR ' + remote, verified.write)
            if verified.getvalue() != body:
                raise RuntimeError('Upload verification failed: ' + remote)
            print('Verified ' + remote, flush=True)

        (backup / 'manifest.json').write_text(json.dumps([remote for _, _, remote in pairs], indent=2), encoding='utf-8')
        print('Backup: ' + str(backup))
    finally:
        ftp.close()


if __name__ == '__main__':
    main()
