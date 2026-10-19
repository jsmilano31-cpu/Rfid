"""Deploy the physical RFID web/API changes through verified FTPS."""
from pathlib import Path
import argparse, datetime, importlib.util, io, json, secrets, ssl, urllib.error, urllib.request

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('mobile_deploy', ROOT / 'scripts' / 'deploy-mobile-api.py')
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
DOMAIN = 'domains/jsmkj.space'
PUBLIC = DOMAIN + '/public_html'

PRIVATE = [
    'src/Controllers/AdminController.php', 'src/Controllers/PortalController.php',
    'src/Services/AttendanceService.php', 'src/Services/EventService.php',
    'src/Services/RecollectionService.php', 'src/Services/RoomService.php',
    'src/Services/CalendarService.php', 'src/Services/DashboardService.php',
    'src/Services/AcademicManagementService.php',
    'src/Services/RfidService.php', 'src/Services/RfidDeviceService.php',
    'src/Services/RfidRegistrationService.php',
    'templates/admin/users.php', 'templates/admin/rooms.php', 'templates/admin/events.php',
    'templates/admin/recollections.php', 'templates/admin/attendance.php',
    'templates/admin/academics.php', 'templates/admin/partials/manage_user_dialog.php', 'templates/partials/attendance_management.php',
    'templates/partials/attendance_policy_dialog.php', 'templates/partials/dashboard_calendar.php',
]
PUBLIC_FILES = [
    'public/admin/rfid.php', 'public/admin/user.php', 'public/admin/facility.php',
    'public/iot/scan.php', 'public/iot/status.php', 'public/attendance.php', 'public/admin/calendar-events.php', 'public/assets/js/app.js',
    'public/assets/js/admin-control.js', 'public/assets/js/rooms.js', 'public/assets/css/app.css',
    'public/assets/js/attendance-policy.js', 'public/assets/js/academic-management.js',
]
MOBILE = ['mobile-api/src/TeacherApi.php', 'mobile-api/src/StudentApi.php', 'mobile-api/public/iot/scan.php', 'mobile-api/public/iot/status.php']

def mappings():
    result = [(ROOT / path, DOMAIN + '/' + path) for path in PRIVATE]
    result += [(ROOT / path, PUBLIC + '/' + path.removeprefix('public/')) for path in PUBLIC_FILES]
    result += [(ROOT / path, DOMAIN + '/mobile-api/src/' + Path(path).name) if path.startswith('mobile-api/src/')
               else (ROOT / path, DOMAIN + '/public_html/rfid-attendance-web/public/' + path.removeprefix('mobile-api/public/'))
               for path in MOBILE]
    return result

def migrate(ftp, statements, label):
    secret = secrets.token_hex(32); name = '_rfid_migrate_' + secrets.token_hex(12) + '.php'
    runner = '''<?php
if (!hash_equals('SECRET', $_SERVER['HTTP_X_MIGRATION_KEY'] ?? '')) { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
$statements = STATEMENTS;
foreach ($statements as $statement) {
    try { App\\Core\\Database::connection()->exec($statement); }
    catch (PDOException $error) { if (!in_array((int)($error->errorInfo[1] ?? 0), [1060,1061,1091], true)) throw $error; }
}
header('Content-Type: application/json'); echo json_encode(['ok'=>true,'phase'=>'LABEL']);
'''.replace('SECRET', secret).replace('STATEMENTS', json.dumps(statements)).replace('LABEL', label)
    target = PUBLIC + '/' + name
    try:
        ftp.storbinary('STOR ' + target, io.BytesIO(runner.encode()))
        request = urllib.request.Request('https://jsmkj.space/' + name, headers={'X-Migration-Key': secret})
        with urllib.request.urlopen(request, timeout=60, context=ssl.create_default_context()) as response:
            body = json.load(response)
        if not body.get('ok') or body.get('phase') != label: raise RuntimeError('Migration verification failed')
        print('Verified database migration phase ' + label, flush=True)
    finally:
        try: ftp.delete(target)
        except Exception: pass
        try:
            urllib.request.urlopen('https://jsmkj.space/' + name, timeout=20)
            raise RuntimeError('Migration runner is still public')
        except urllib.error.HTTPError as error:
            if error.code != 404: raise

def main():
    parser = argparse.ArgumentParser(description=__doc__); parser.add_argument('--deploy', action='store_true'); parser.add_argument('--skip-migration', action='store_true'); args = parser.parse_args()
    files = mappings()
    if not args.deploy:
        for source, target in files: print(f'{source.relative_to(ROOT)} -> {target}')
        return
    backup = ROOT / 'storage' / 'physical-rfid-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = module.connect()
    try:
        expand = [
            "ALTER TABLE attendance_records MODIFY source ENUM('rfid','mock_rfid','manual','system') NOT NULL",
            "ALTER TABLE event_attendance MODIFY source ENUM('rfid','mock_rfid','manual','system') NOT NULL",
            "ALTER TABLE recollection_attendance MODIFY source ENUM('rfid','mock_rfid','manual','system') NOT NULL",
            '''CREATE TABLE IF NOT EXISTS rfid_registration_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_token CHAR(64) NOT NULL UNIQUE, user_id INT UNSIGNED NOT NULL, device_id INT UNSIGNED NOT NULL, requested_by INT UNSIGNED NOT NULL, uid VARCHAR(32) NULL, status ENUM('pending','captured','completed','cancelled','expired') NOT NULL DEFAULT 'pending', expires_at DATETIME NOT NULL, captured_at DATETIME NULL, completed_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_rfid_registration_device (device_id,status,expires_at), CONSTRAINT fk_rfid_registration_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_rfid_registration_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE, CONSTRAINT fk_rfid_registration_actor FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB''',
            '''CREATE TABLE IF NOT EXISTS device_scan_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id INT UNSIGNED NOT NULL, uid VARCHAR(32) NULL, outcome VARCHAR(40) NOT NULL, message VARCHAR(255) NULL, class_offering_id INT UNSIGNED NULL, student_id INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_device_scan_created (device_id,created_at), CONSTRAINT fk_device_scan_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE, CONSTRAINT fk_device_scan_offering FOREIGN KEY (class_offering_id) REFERENCES class_offerings(id) ON DELETE SET NULL, CONSTRAINT fk_device_scan_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB''',
            "ALTER TABLE attendance_settings ADD COLUMN early_check_in_minutes TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER id",
            "ALTER TABLE class_attendance_policies ADD COLUMN early_check_in_minutes TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER class_offering_id",
            "ALTER TABLE devices ADD INDEX idx_devices_room_status (room_id,status)",
            "ALTER TABLE devices DROP INDEX uq_devices_room",
            "ALTER TABLE class_offerings ADD COLUMN component_type ENUM('LEC','LAB') NOT NULL DEFAULT 'LEC' AFTER semester_id",
            "ALTER TABLE class_offerings ADD UNIQUE KEY uq_offering_component (course_id,section_id,semester_id,component_type)",
            "ALTER TABLE class_offerings DROP INDEX uq_offering",
            "ALTER TABLE events ADD COLUMN suspends_classes BOOLEAN NOT NULL DEFAULT FALSE AFTER attendance_required",
            '''CREATE TABLE IF NOT EXISTS event_scanners (event_id INT UNSIGNED NOT NULL, device_id INT UNSIGNED NOT NULL, assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(event_id,device_id), INDEX idx_event_scanners_device(device_id,event_id), CONSTRAINT fk_event_scanners_event FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE, CONSTRAINT fk_event_scanners_device FOREIGN KEY(device_id) REFERENCES devices(id) ON DELETE CASCADE) ENGINE=InnoDB''',
        ]
        if not args.skip_migration: migrate(ftp, expand, 'expand')
        for source, target in files:
            previous = io.BytesIO()
            try:
                ftp.retrbinary('RETR ' + target, previous.write)
                saved = backup / target; saved.parent.mkdir(parents=True, exist_ok=True); saved.write_bytes(previous.getvalue())
                if previous.getvalue() == source.read_bytes():
                    print('Unchanged ' + target, flush=True); continue
            except Exception:
                module.mkdirs(ftp, target.rsplit('/', 1)[0])
            body = source.read_bytes(); ftp.storbinary('STOR ' + target, io.BytesIO(body))
            check = io.BytesIO(); ftp.retrbinary('RETR ' + target, check.write)
            if check.getvalue() != body: raise RuntimeError('Upload verification failed: ' + target)
            print('Verified ' + target, flush=True)
        old = DOMAIN + '/src/Services/MockRfidService.php'
        previous = io.BytesIO()
        try:
            ftp.retrbinary('RETR ' + old, previous.write)
            saved = backup / old; saved.parent.mkdir(parents=True, exist_ok=True); saved.write_bytes(previous.getvalue())
            ftp.delete(old); print('Removed legacy ' + old, flush=True)
        except Exception: pass
        contract = [
            "UPDATE attendance_records SET source='rfid' WHERE source='mock_rfid'",
            "ALTER TABLE attendance_records MODIFY source ENUM('rfid','manual','system') NOT NULL",
            "UPDATE event_attendance SET source='rfid' WHERE source='mock_rfid'",
            "ALTER TABLE event_attendance MODIFY source ENUM('rfid','manual','system') NOT NULL",
            "UPDATE recollection_attendance SET source='rfid' WHERE source='mock_rfid'",
            "ALTER TABLE recollection_attendance MODIFY source ENUM('rfid','manual','system') NOT NULL",
        ]
        if not args.skip_migration: migrate(ftp, contract, 'contract')
        (backup / 'manifest.json').write_text(json.dumps([target for _, target in files], indent=2))
    finally:
        ftp.close()

if __name__ == '__main__': main()
