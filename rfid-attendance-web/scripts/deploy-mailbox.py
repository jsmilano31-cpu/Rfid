"""Deploy recipient mailboxes with scoped backups, schema checks, and file verification."""

from pathlib import Path
import argparse
import datetime
import ftplib
import hashlib
import importlib.util
import io
import json
import secrets
import ssl
import subprocess
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = 'domains/jsmkj.space'
FILES = ['database/schema.sql', 'database/migrations/20261001_recipient_mailbox.sql', 'src/Services/MessageService.php', 'public/api/messages.php', 'public/assets/css/messages.css', 'public/assets/js/messages.js', 'templates/partials/message_compose.php', 'templates/partials/mailbox.php', 'templates/portal/messages.php', 'templates/admin/messages.php', 'src/Controllers/PortalController.php', 'templates/layouts/app.php']

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


def database_phase(ftp, phase, key, remote_name):
    request = urllib.request.Request('https://jsmkj.space/' + remote_name + '?phase=' + phase,
                                     headers={'X-Deploy-Key': key, 'Accept': 'application/json'})
    with urllib.request.urlopen(request, timeout=60, context=ssl.create_default_context()) as response:
        payload = json.load(response)
    if not payload.get('ok'):
        raise RuntimeError('Production database ' + phase + ' failed.')
    return payload


def migration_runner(key):
    return '''<?php
declare(strict_types=1);
if (!hash_equals('TOKEN', $_SERVER['HTTP_X_DEPLOY_KEY'] ?? '')) { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
try {
    $pdo = App\\Core\\Database::connection();
    $columns = array_column($pdo->query('SHOW COLUMNS FROM message_recipients')->fetchAll(PDO::FETCH_ASSOC),'Field');
    if (($_GET['phase'] ?? '') === 'inspect') {
        $definition = $pdo->query('SHOW CREATE TABLE message_recipients')->fetch(PDO::FETCH_NUM);
        echo json_encode(['ok'=>true,'create_table'=>$definition[1],
            'rows'=>$pdo->query('SELECT * FROM message_recipients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR);
    } elseif (($_GET['phase'] ?? '') === 'migrate') {
        if (!in_array('starred_at',$columns,true)) $pdo->exec('ALTER TABLE message_recipients ADD COLUMN starred_at DATETIME NULL AFTER read_at');
        if (!in_array('deleted_at',$columns,true)) $pdo->exec('ALTER TABLE message_recipients ADD COLUMN deleted_at DATETIME NULL AFTER starred_at');
        $indexes = array_column($pdo->query('SHOW INDEX FROM message_recipients')->fetchAll(PDO::FETCH_ASSOC),'Key_name');
        if (!in_array('idx_recipient_mailbox',$indexes,true)) $pdo->exec('ALTER TABLE message_recipients ADD KEY idx_recipient_mailbox (user_id,deleted_at,message_id)');
        $columns = array_column($pdo->query('SHOW COLUMNS FROM message_recipients')->fetchAll(PDO::FETCH_ASSOC),'Field');
        $verified = in_array('starred_at',$columns,true) && in_array('deleted_at',$columns,true);
        echo json_encode(['ok'=>$verified,'column_exists'=>$verified], JSON_THROW_ON_ERROR);
    } else { http_response_code(404); echo json_encode(['ok'=>false]); }
} catch (Throwable $error) { http_response_code(500); echo json_encode(['ok'=>false]); }
'''.replace('TOKEN', key).encode('utf-8')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--deploy', action='store_true')
    args = parser.parse_args()
    pairs = [(path, *mapping(path)) for path in FILES]
    if not args.deploy:
        for path, _, remote in pairs:
            print(f'{path} -> {remote}')
        return

    backup = ROOT / 'storage/mailbox-backups' / datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    backup.mkdir(parents=True, exist_ok=False)
    ftp = ftp_tools.connect()
    try:
        previous_files = {}
        for path, source, remote in pairs:
            result = io.BytesIO()
            try:
                ftp.retrbinary('RETR ' + remote, result.write)
            except ftplib.error_perm as error:
                if not str(error).startswith('550'):
                    raise
                if baseline(path) is not None:
                    raise RuntimeError('Expected production file is missing: ' + remote) from error
                previous_files[path] = None
                continue
            previous = result.getvalue()
            prior = baseline(path)
            if prior is None and not same_text(previous, source.read_bytes()):
                raise RuntimeError('An expected new file already exists with different content: ' + remote)
            if prior is not None and not same_text(previous, prior) and not same_text(previous, source.read_bytes()):
                raise RuntimeError('Production file differs from repository baseline: ' + remote)
            previous_files[path] = previous

        for path, _, remote in pairs:
            previous = previous_files[path]
            if previous is not None:
                saved = backup / remote
                saved.parent.mkdir(parents=True, exist_ok=True)
                saved.write_bytes(previous)

        key = secrets.token_hex(32)
        runner = '_campustrack_mailbox_' + secrets.token_hex(12) + '.php'
        runner_remote = DOMAIN + '/public_html/' + runner
        ftp.storbinary('STOR ' + runner_remote, io.BytesIO(migration_runner(key)))
        try:
            snapshot = database_phase(ftp, 'inspect', key, runner)
            (backup / 'message_recipients.json').write_text(json.dumps(snapshot, indent=2), encoding='utf-8')
            migrated = database_phase(ftp, 'migrate', key, runner)
            if not migrated['column_exists']:
                raise RuntimeError('The recipient mailbox migration was not verified.')
            print('Verified production recipient mailbox schema.', flush=True)
        finally:
            ftp.delete(runner_remote)
        try:
            urllib.request.urlopen('https://jsmkj.space/' + runner, timeout=20)
            raise RuntimeError('Migration runner is still public.')
        except urllib.error.HTTPError as error:
            if error.code != 404:
                raise

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
        print('Backup: ' + str(backup), flush=True)
    finally:
        ftp.close()


if __name__ == '__main__':
    main()
