"""Verify the deployed attendance edge-case schema without exposing credentials."""
from pathlib import Path
import importlib.util, io, json, secrets, ssl, urllib.error, urllib.request

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('mobile_deploy', ROOT / 'scripts' / 'deploy-mobile-api.py')
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
secret = secrets.token_hex(32)
name = '_edge_verify_' + secrets.token_hex(10) + '.php'
target = 'domains/jsmkj.space/public_html/' + name
runner = '''<?php
if (!hash_equals('SECRET', $_SERVER['HTTP_X_VERIFY_KEY'] ?? '')) { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
$pdo = App\\Core\\Database::connection();
$required = [
 'attendance_settings'=>['early_check_in_minutes'],
 'class_attendance_policies'=>['early_check_in_minutes'],
 'class_offerings'=>['component_type'],
 'events'=>['suspends_classes'],
 'event_scanners'=>['event_id','device_id'],
];
$missing=[];
foreach($required as $table=>$columns){foreach($columns as $column){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name');$q->execute(['table_name'=>$table,'column_name'=>$column]);if(!(int)$q->fetchColumn())$missing[]=$table.'.'.$column;}}
$indexes=[];
foreach([['devices','idx_devices_room_status'],['class_offerings','uq_offering_component']] as [$table,$index]){$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND INDEX_NAME=:index_name');$q->execute(['table_name'=>$table,'index_name'=>$index]);$indexes[$index]=(bool)$q->fetchColumn();}
header('Content-Type: application/json');echo json_encode(['ok'=>!$missing&&!in_array(false,$indexes,true),'missing'=>$missing,'indexes'=>$indexes]);
'''.replace('SECRET', secret)

ftp = module.connect()
try:
    ftp.storbinary('STOR ' + target, io.BytesIO(runner.encode()))
    request = urllib.request.Request('https://jsmkj.space/' + name, headers={'X-Verify-Key': secret})
    with urllib.request.urlopen(request, timeout=30, context=ssl.create_default_context()) as response:
        result = json.load(response)
    if not result.get('ok'):
        raise RuntimeError('Schema verification failed: ' + json.dumps(result))
    print('PASS: deployed attendance edge-case schema and indexes are present')
finally:
    try: ftp.delete(target)
    finally: ftp.close()
    try:
        urllib.request.urlopen('https://jsmkj.space/' + name, timeout=15)
        raise RuntimeError('Verification runner is still public')
    except urllib.error.HTTPError as error:
        if error.code != 404: raise
