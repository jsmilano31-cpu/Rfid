"""Deploy the teacher portal theme-completion stylesheet."""
from pathlib import Path
import argparse
import datetime
import importlib.util
import io

ROOT = Path(__file__).resolve().parent.parent
TARGET = 'domains/jsmkj.space/public_html/assets/css/app.css'


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


ftp_tools = load('teacher_dark_theme_ftp', ROOT / 'scripts/deploy-mobile-api.py')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    args = parser.parse_args()
    source = ROOT / 'public/assets/css/app.css'
    if not args.deploy:
        print(f'{source.relative_to(ROOT)} -> {TARGET}')
        return

    backup = ROOT / 'storage/teacher-dark-theme-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = ftp_tools.connect()
    try:
        previous = io.BytesIO()
        ftp.retrbinary('RETR ' + TARGET, previous.write)
        (backup / 'app.css').write_bytes(previous.getvalue())
        body = source.read_bytes()
        ftp.storbinary('STOR ' + TARGET, io.BytesIO(body))
        check = io.BytesIO()
        ftp.retrbinary('RETR ' + TARGET, check.write)
        if check.getvalue() != body:
            raise RuntimeError('Upload verification failed: ' + TARGET)
        print('Verified ' + TARGET)
    finally:
        ftp.close()


if __name__ == '__main__':
    main()
