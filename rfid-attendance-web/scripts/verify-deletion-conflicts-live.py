"""Run read-only deletion-conflict queries on production through a disposable probe."""
from pathlib import Path
import argparse
import importlib.util
import io
import json
import secrets
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
REMOTE = 'domains/jsmkj.space/public_html/admin'


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


ftp_tools = load('deletion_conflict_verify_ftp', ROOT / 'scripts/deploy-mobile-api.py')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run', action='store_true', help='Upload, execute, and remove the read-only probe.')
    args = parser.parse_args()
    if not args.run:
        print('Read-only checks: department, program, course, section, offering, room, device, user, role, event, recollection, and preset when present.')
        print('Use --run to create a random-token probe, execute it once, and remove it in a finally block.')
        return

    token = secrets.token_urlsafe(32)
    filename = 'deletion-conflict-health-' + secrets.token_hex(8) + '.php'
    target = REMOTE + '/' + filename
    source = '''<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
use App\\Core\\Database;
use App\\Services\\DeletionConflictService;
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if(!hash_equals(%s,(string)($_GET['token']??''))){http_response_code(404);echo '{}';exit;}
$pdo=Database::connection();
$admin=$pdo->query('SELECT users.id FROM users JOIN roles ON roles.id=users.role_id WHERE roles.name="Administrator" AND users.status="active" ORDER BY users.id LIMIT 1')->fetchColumn();
if(!$admin){throw new RuntimeException('No active administrator is available for the permission check.');}
$_SESSION['user_id']=(int)$admin;
$queries=[
'department'=>'SELECT id FROM departments ORDER BY id LIMIT 1','program'=>'SELECT id FROM programs ORDER BY id LIMIT 1',
'course'=>'SELECT id FROM courses ORDER BY id LIMIT 1','section'=>'SELECT id FROM sections ORDER BY id LIMIT 1',
'offering'=>'SELECT id FROM class_offerings ORDER BY id LIMIT 1','room'=>'SELECT id FROM rooms ORDER BY id LIMIT 1',
'device'=>'SELECT id FROM devices ORDER BY id LIMIT 1','user'=>'SELECT id FROM users WHERE id<>(SELECT MIN(id) FROM users) ORDER BY id LIMIT 1',
'role'=>'SELECT id FROM roles WHERE name<>"Administrator" ORDER BY id LIMIT 1','event'=>'SELECT id FROM events ORDER BY id LIMIT 1',
'recollection'=>'SELECT id FROM recollections ORDER BY id LIMIT 1','preset'=>'SELECT id FROM seat_plan_presets ORDER BY id LIMIT 1'];
$result=[];
foreach($queries as $type=>$sql){$id=$pdo->query($sql)->fetchColumn();if(!$id){$result[$type]=['present'=>false];continue;}$conflict=DeletionConflictService::inspect($type,(int)$id);$result[$type]=['present'=>true,'total'=>$conflict['total'],'groups'=>array_map(static fn($group)=>['key'=>$group['key'],'total'=>$group['total'],'records'=>count($group['records']),'pages'=>$group['pages']],$conflict['groups'])];}
echo json_encode(['ok'=>true,'types'=>$result],JSON_THROW_ON_ERROR);
''' % json.dumps(token)

    ftp = ftp_tools.connect()
    try:
        ftp_tools.mkdirs(ftp, REMOTE)
        ftp.storbinary('STOR ' + target, io.BytesIO(source.encode('utf-8')))
        url = 'https://jsmkj.space/admin/' + filename + '?' + urllib.parse.urlencode({'token': token})
        with urllib.request.urlopen(url, timeout=45) as response:
            payload = json.loads(response.read().decode('utf-8'))
        if not payload.get('ok'):
            raise RuntimeError('The production conflict probe did not complete.')
        expected = {'department', 'program', 'course', 'section', 'offering', 'room', 'device', 'user', 'role', 'event', 'recollection', 'preset'}
        if set(payload.get('types', {})) != expected:
            raise RuntimeError('The production conflict probe returned an incomplete type set.')
        print(json.dumps(payload, indent=2))
    finally:
        try:
            ftp.delete(target)
        except Exception as error:
            raise RuntimeError('The disposable production probe could not be removed.') from error
        ftp.close()


if __name__ == '__main__':
    main()
