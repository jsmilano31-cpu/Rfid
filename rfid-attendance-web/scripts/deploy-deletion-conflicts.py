"""Deploy clickable deletion-conflict responses, dialog UI, and direct links."""
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


ftp_tools = load('deletion_conflicts_ftp', ROOT / 'scripts/deploy-mobile-api.py')

PRIVATE_FILES = [
    'src/Exceptions/DeletionConflictException.php',
    'src/Controllers/AdminController.php',
    'src/Services/DeletionConflictService.php',
    'src/Services/AcademicManagementService.php',
    'src/Services/AttendanceService.php',
    'src/Services/EventService.php',
    'src/Services/GradeService.php',
    'src/Services/RecollectionService.php',
    'src/Services/RoomService.php',
    'src/Services/SeatPlanService.php',
    'src/Services/UserService.php',
    'templates/admin/events.php',
    'templates/admin/deletion_record.php',
]

PUBLIC_FILES = [
    'public/admin/academic-entity.php',
    'public/admin/calendar-events.php',
    'public/admin/deletion-conflicts.php',
    'public/admin/deletion-record.php',
    'public/admin/facility.php',
    'public/admin/recollection.php',
    'public/admin/user.php',
    'public/assets/css/app.css',
    'public/assets/js/academic-management.js',
    'public/assets/js/app.js',
    'public/assets/js/attendance.js',
    'public/assets/js/recollection-management.js',
    'public/assets/js/rooms.js',
    'public/assets/js/seat-plans.js',
    'public/attendance.php',
    'public/grades.php',
    'public/seat-plans.php',
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

    backup = ROOT / 'storage/deletion-conflict-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
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
