"""Deploy dependent department, program, and section user controls."""
from pathlib import Path
import argparse
import datetime
import importlib.util
import io
import json

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


ftp_tools = load('academic_cascade_ftp', ROOT / 'scripts/deploy-mobile-api.py')

PRIVATE = [
    'src/Controllers/AdminController.php',
    'src/Services/UserService.php',
    'templates/admin/users.php',
    'templates/admin/partials/manage_user_dialog.php',
]
PUBLIC = ['assets/js/app.js']


def mappings():
    pairs = [(ROOT / path, DOMAIN + '/' + path) for path in PRIVATE]
    pairs += [(ROOT / 'public' / path, DOMAIN + '/public_html/' + path) for path in PUBLIC]
    return pairs


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    args = parser.parse_args()
    pairs = mappings()
    if not args.deploy:
        for source, target in pairs:
            print(f'{source.relative_to(ROOT)} -> {target}')
        return

    backup = ROOT / 'storage/user-academic-cascade-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = ftp_tools.connect()
    try:
        for source, target in pairs:
            old = io.BytesIO()
            ftp.retrbinary('RETR ' + target, old.write)
            saved = backup / target
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(old.getvalue())
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
