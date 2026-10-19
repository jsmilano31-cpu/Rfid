"""Deploy title-case normalization and run its idempotent data cleanup."""
from pathlib import Path
import argparse
import datetime
import importlib.util
import io
import json
import secrets
import ssl
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'
PUBLIC = DOMAIN + '/public_html'


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


ftp_tools = load('title_case_ftp', ROOT / 'scripts/deploy-mobile-api.py')

PRIVATE_FILES = [
    'src/helpers.php',
    'src/Controllers/AdminController.php',
    'src/Services/UserService.php',
    'src/Services/AcademicManagementService.php',
    'src/Services/RoomService.php',
    'src/Services/EventService.php',
    'src/Services/RecollectionService.php',
    'src/Services/SeatPlanService.php',
    'database/migrations/20260926_normalize_entity_title_case.php',
]
PUBLIC_FILES = ['public/assets/js/app.js']


def mappings():
    result = [(ROOT / path, DOMAIN + '/' + path) for path in PRIVATE_FILES]
    result += [(ROOT / path, PUBLIC + '/' + path.removeprefix('public/')) for path in PUBLIC_FILES]
    return result


def run_migration(ftp, backup):
    secret = secrets.token_hex(32)
    runner_name = '_rfid_title_case_' + secrets.token_hex(12) + '.php'
    database_backup_name = 'title-case-' + datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '.json'
    target = PUBLIC + '/' + runner_name
    runner = r'''<?php
if (!hash_equals('MIGRATION_SECRET', $_SERVER['HTTP_X_MIGRATION_KEY'] ?? '')) { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
$tables = ['users','departments','programs','courses','roles','rooms','devices','events','recollections','room_seat_plans','seat_plan_presets'];
$backup = [];
foreach ($tables as $table) $backup[$table] = App\Core\Database::connection()->query('SELECT * FROM ' . $table)->fetchAll();
$backupDirectory = dirname(__DIR__) . '/storage/title-case-backups';
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0775, true) && !is_dir($backupDirectory)) throw new RuntimeException('Unable to create the database backup directory.');
$backupPath = $backupDirectory . '/DATABASE_BACKUP';
if (file_put_contents($backupPath, json_encode($backup, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Unable to write the database backup.');
ob_start();
require dirname(__DIR__) . '/database/migrations/20260926_normalize_entity_title_case.php';
$output = ob_get_clean();
header('Content-Type: application/json');
echo json_encode(['ok'=>true,'backup'=>'DATABASE_BACKUP','output'=>$output], JSON_THROW_ON_ERROR);
'''.replace('MIGRATION_SECRET', secret).replace('DATABASE_BACKUP', database_backup_name)
    try:
        ftp.storbinary('STOR ' + target, io.BytesIO(runner.encode('utf-8')))
        request = urllib.request.Request(
            'https://jsmkj.space/' + runner_name,
            headers={'X-Migration-Key': secret, 'User-Agent': 'RFID-title-case-deployer/1.0'},
        )
        with urllib.request.urlopen(request, timeout=120, context=ssl.create_default_context()) as response:
            payload = json.load(response)
        if not payload.get('ok') or payload.get('backup') != database_backup_name:
            raise RuntimeError('Title-case migration verification failed.')

        remote_backup = DOMAIN + '/storage/title-case-backups/' + database_backup_name
        local_backup = backup / database_backup_name
        with local_backup.open('wb') as output:
            ftp.retrbinary('RETR ' + remote_backup, output.write)
        if local_backup.stat().st_size == 0:
            raise RuntimeError('The database backup was empty.')
        print(payload.get('output', '').strip(), flush=True)
        print('Verified private database backup ' + database_backup_name, flush=True)
    finally:
        try:
            ftp.delete(target)
        except Exception:
            pass
        try:
            urllib.request.urlopen('https://jsmkj.space/' + runner_name, timeout=20)
            raise RuntimeError('The migration runner is still public.')
        except urllib.error.HTTPError as error:
            if error.code != 404:
                raise


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

    backup = ROOT / 'storage/title-case-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
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

        if not args.skip_migration:
            run_migration(ftp, backup)
        (backup / 'manifest.json').write_text(json.dumps([target for _, target in pairs], indent=2), encoding='utf-8')
    finally:
        ftp.close()


if __name__ == '__main__':
    main()
