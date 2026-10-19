"""Deploy multi-day class offering schedules and their idempotent database migration."""
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


ftp_tools = load('multi_schedule_ftp', ROOT / 'scripts/deploy-mobile-api.py')
migration_tools = load('multi_schedule_migration', ROOT / 'scripts/deploy-physical-rfid.py')

PRIVATE = [
    'src/Controllers/AdminController.php',
    'src/Services/AcademicManagementService.php',
    'templates/admin/academics.php',
    'database/schema.sql',
    'database/migrations/20260926_multiple_class_schedules.sql',
]
PUBLIC_FILES = [
    'public/assets/css/app.css',
    'public/assets/js/app.js',
    'public/assets/js/academic-management.js',
]


def mappings():
    result = [(ROOT / path, DOMAIN + '/' + path) for path in PRIVATE]
    result += [(ROOT / path, PUBLIC + '/' + path.removeprefix('public/')) for path in PUBLIC_FILES]
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    parser.add_argument('--skip-migration', action='store_true')
    args = parser.parse_args()
    pairs = mappings()
    if not args.deploy:
        for source, target in pairs:
            print(f'{source.relative_to(ROOT)} -> {target}')
        return

    backup = ROOT / 'storage/multiple-class-schedule-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
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

        if not args.skip_migration:
            statements = [
                '''DELETE duplicate_schedule FROM class_schedules AS duplicate_schedule
                   JOIN class_schedules AS keeper ON keeper.class_offering_id=duplicate_schedule.class_offering_id
                    AND keeper.day_of_week=duplicate_schedule.day_of_week AND keeper.id<duplicate_schedule.id''',
                'ALTER TABLE class_schedules ADD UNIQUE KEY uq_class_schedule_day (class_offering_id,day_of_week)',
                'ALTER TABLE class_schedules ADD INDEX idx_class_schedules_day_time_room (day_of_week,start_time,end_time,room_id)',
                '''SELECT offerings.id,
                          GROUP_CONCAT(DISTINCT CONCAT(schedules.day_of_week,' ',TIME_FORMAT(schedules.start_time,'%h:%i %p'),'–',TIME_FORMAT(schedules.end_time,'%h:%i %p'))
                            ORDER BY FIELD(schedules.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),schedules.start_time SEPARATOR '||') schedule_summary
                   FROM class_offerings offerings
                   LEFT JOIN class_schedules schedules ON schedules.class_offering_id=offerings.id
                   GROUP BY offerings.id LIMIT 1''',
            ]
            migration_tools.migrate(ftp, statements, 'multiple-class-schedules')

        for source, target in pairs:
            ftp_tools.mkdirs(ftp, target.rsplit('/', 1)[0])
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
