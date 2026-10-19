"""Deploy event layouts for records without photos using verified FTPS."""
from pathlib import Path
import argparse
import datetime
import importlib.util
import io
import json

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'

spec = importlib.util.spec_from_file_location('event_empty_image_ftp', ROOT / 'scripts/deploy-mobile-api.py')
ftp_tools = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ftp_tools)

PRIVATE_FILES = ['templates/admin/events.php', 'templates/partials/events_social_feed.php']
PUBLIC_FILES = ['public/assets/css/app.css', 'public/assets/js/app.js']


def mappings():
    result = [(ROOT / path, DOMAIN + '/' + path) for path in PRIVATE_FILES]
    result += [(ROOT / path, DOMAIN + '/public_html/' + path.removeprefix('public/')) for path in PUBLIC_FILES]
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    args = parser.parse_args()
    pairs = mappings()
    if not args.deploy:
        for source, target in pairs:
            print(f'{source.relative_to(ROOT)} -> {target}')
        return

    backup = ROOT / 'storage/event-empty-image-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = ftp_tools.connect()
    try:
        for source, target in pairs:
            previous = io.BytesIO()
            try:
                ftp.retrbinary('RETR ' + target, previous.write)
            except Exception as error:
                if not str(error).startswith('550'):
                    raise
            else:
                saved = backup / target
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(previous.getvalue())

        for source, target in pairs:
            ftp_tools.mkdirs(ftp, target.rsplit('/', 1)[0])
            body = source.read_bytes()
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
