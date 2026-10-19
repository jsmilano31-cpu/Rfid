"""Deploy the CampusTrack web branding and login screen with verified FTPS backups."""

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
    'config/app.php',
    'templates/admin/users.php',
    'templates/auth/login.php',
    'templates/errors/403.php',
    'templates/layouts/app.php',
    'templates/layouts/auth.php',
    'public/assets/css/app.css',
    'public/assets/js/auth-login.js',
    'public/assets/logos/backgrounds/bg.png',
    'public/assets/logos/backgrounds/bg2.webp',
    'public/assets/logos/favicons/favicon.ico',
    'public/assets/logos/favicons/apple-touch-icon.png',
    'public/assets/logos/favicons/android-chrome-192x192.png',
    'public/assets/logos/favicons/android-chrome-512x512.png',
    'public/assets/logos/logos/png/campustrack-icon-light.png',
    'public/assets/logos/logos/png/campustrack-icon-dark.png',
    'public/assets/logos/logos/png/campustrack-logo-dark.png',
    'public/assets/logos/web/site.webmanifest',
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
    parser.add_argument('--assets-only', action='store_true', help='Upload only the login CSS and JavaScript')
    args = parser.parse_args()
    paths = ['public/assets/css/app.css', 'public/assets/js/auth-login.js'] if args.assets_only else FILES
    pairs = [mapping(path) for path in paths]
    if not args.deploy:
        for source, target in pairs:
            print(f'{source.relative_to(ROOT)} -> {target}')
        return

    backup = ROOT / 'storage/branding-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
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
