"""Deploy universal search files with verified FTPS backups and production drift checks."""

from pathlib import Path
import argparse
import datetime
import ftplib
import hashlib
import importlib.util
import io
import json
import subprocess

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'
FILES = [
    'src/Services/SearchService.php',
    'src/Services/GradeService.php',
    'src/Services/AttendanceService.php',
    'src/Services/RecollectionService.php',
    'src/Controllers/PortalController.php',
    'templates/layouts/app.php',
    'templates/partials/portal_schedule_views.php',
    'templates/portal/events.php',
    'templates/portal/recollections.php',
    'public/api/search.php',
    'public/admin/calendar-events.php',
    'public/assets/css/app.css',
    'public/assets/js/app.js',
    'public/assets/js/global-search.js',
    'public/assets/js/seat-plans.js',
]

spec = importlib.util.spec_from_file_location('ftp_tools', ROOT / 'scripts/deploy-mobile-api.py')
ftp_tools = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ftp_tools)


def mapping(path):
    remote = DOMAIN + ('/public_html/' + path.removeprefix('public/') if path.startswith('public/') else '/' + path)
    return ROOT / path, remote


def baseline(path):
    result = subprocess.run(['git', 'show', 'HEAD:' + path], cwd=ROOT, capture_output=True)
    return result.stdout if result.returncode == 0 else None


def same_text(left, right):
    return left == right or left.replace(b'\r\n', b'\n') == right.replace(b'\r\n', b'\n')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    parser.add_argument('--only', action='append', choices=FILES, help='Deploy one selected file; may be repeated')
    parser.add_argument('--expected-sha256', action='append', default=[], metavar='PATH=HASH', help='Expected remote hash for a previously deployed new file')
    args = parser.parse_args()
    pairs = [(path, *mapping(path)) for path in (args.only or FILES)]
    expected_hashes = dict(value.split('=', 1) for value in args.expected_sha256)
    if any(path not in FILES or len(digest) != 64 for path, digest in expected_hashes.items()):
        parser.error('Expected hashes must use a deployment path and a 64-character SHA-256 digest.')
    if not args.deploy:
        for path, _, remote in pairs:
            print(f'{path} -> {remote}')
        return

    backup = ROOT / 'storage/universal-search-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
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
                if baseline(path) is not None:
                    raise RuntimeError('Expected production file is missing: ' + remote) from error
                previous_files[path] = None
                continue

            body = previous.getvalue()
            previous_files[path] = body
            expected = baseline(path)
            if expected is None:
                verified_prior = expected_hashes.get(path) == hashlib.sha256(body).hexdigest()
                if not same_text(body, source.read_bytes()) and not verified_prior:
                    saved = backup / remote
                    saved.parent.mkdir(parents=True, exist_ok=True)
                    saved.write_bytes(body)
                    raise RuntimeError('A new search file already exists with different content: ' + remote)
            elif not same_text(body, expected) and not same_text(body, source.read_bytes()):
                saved = backup / remote
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(body)
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
