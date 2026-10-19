"""Deploy natural table ordering and sortable column-header controls."""
from pathlib import Path
import argparse
import datetime
import importlib.util
import io
import json

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'
PUBLIC = DOMAIN + '/public_html'


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


ftp_tools = load('natural_table_sorting_ftp', ROOT / 'scripts/deploy-mobile-api.py')

PRIVATE_FILES = ['templates/admin/rooms.php']
PUBLIC_FILES = [
    'public/assets/css/app.css',
    'public/assets/js/app.js',
    'public/assets/js/rooms.js',
    'public/assets/js/academic-management.js',
    'public/assets/js/recollection-management.js',
]


def mappings():
    result = [(ROOT / path, DOMAIN + '/' + path) for path in PRIVATE_FILES]
    result += [(ROOT / path, PUBLIC + '/' + path.removeprefix('public/')) for path in PUBLIC_FILES]
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

    backup = ROOT / 'storage/natural-table-sorting-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = ftp_tools.connect()
    try:
        for source, target in pairs:
            previous = io.BytesIO()
            ftp.retrbinary('RETR ' + target, previous.write)
            saved = backup / target
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(previous.getvalue())
        for source, target in pairs:
            body = source.read_bytes()
            ftp.storbinary('STOR ' + target, io.BytesIO(body))
            check = io.BytesIO()
            ftp.retrbinary('RETR ' + target, check.write)
            if check.getvalue() != body:
                raise RuntimeError('Upload verification failed: ' + target)
            print('Verified ' + target, flush=True)
        (backup / 'manifest.json').write_text(json.dumps([target for _, target in pairs], indent=2))
    finally:
        ftp.close()


if __name__ == '__main__':
    main()
