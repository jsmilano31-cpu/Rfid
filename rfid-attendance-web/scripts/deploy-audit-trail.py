"""Scoped Audit Trail deployment with drift checks, private backups, and schema verification."""
from pathlib import Path
import argparse, datetime, ftplib, hashlib, importlib.util, io, json, secrets, ssl, subprocess, urllib.error, urllib.request

ROOT=Path(__file__).resolve().parent.parent
DOMAIN='domains/jsmkj.space'
# Reviewed live scanner fix: unique native-PDO placeholders and safe policy defaults.
# The local scanner implementation retains those behaviors before adding audit context.
REVIEWED_DRIFT={'src/Services/RfidDeviceService.php':'a936689f667fb80debd0fb49573701f4479a10e7aa60ca85e0e8acc95050405a'}
FILES=[
 'src/Core/AuditContext.php','src/Services/AuditSchema.php','src/Services/AuditTrailService.php',
 'public/admin/audit-trail.php','public/assets/css/audit-trail.css','public/assets/js/audit-trail.js','templates/admin/audit-trail.php',
 'src/Services/AuditService.php','src/Core/Database.php','src/Core/Auth.php','src/Services/RfidDeviceService.php',
 'src/Services/SearchService.php','src/Controllers/AdminController.php',
 'public/assets/js/compliance.js','templates/admin/compliance.php',
 'templates/admin/partials/compliance_reports.php','templates/admin/analytics.php','templates/layouts/app.php',
]
spec=importlib.util.spec_from_file_location('ftp_tools',ROOT/'scripts/deploy-mobile-api.py')
ftp_tools=importlib.util.module_from_spec(spec);spec.loader.exec_module(ftp_tools)

def remote(path):
 return DOMAIN+('/public_html/'+path.removeprefix('public/') if path.startswith('public/') else '/'+path)

RUNNER=r'''<?php
declare(strict_types=1);
if (!hash_equals('DEPLOY_TOKEN',$_SERVER['HTTP_X_DEPLOY_KEY']??'')) {http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');
require dirname(__DIR__).'/src/bootstrap.php';
use App\Core\Database;use App\Services\AuditSchema;
try {
 $pdo=Database::connection();$phase=$_GET['phase']??'inspect';
 if ($phase==='inspect') {
  $tables=AuditSchema::tables($pdo);$schema=[];
  foreach (array_merge(array_keys($tables),['audit_logs']) as $table) $schema[$table]=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
  $role=$pdo->query('SELECT id,name FROM roles WHERE id=1')->fetch();
  if (!$role || strcasecmp($role['name'],'Administrator')!==0) throw new RuntimeException('Unexpected Administrator role.');
  $assigned=(bool)$pdo->query('SELECT 1 FROM role_permissions JOIN permissions ON permissions.id=permission_id WHERE role_id=1 AND permissions.code="view_audit_logs"')->fetchColumn();
  $triggers=$pdo->query('SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll();
  $roles=$pdo->query('SELECT r.id,r.name,EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.code="view_audit_logs") audit_access FROM roles r ORDER BY r.id')->fetchAll();
  echo json_encode(['ok'=>true,'schema'=>$schema,'triggers'=>$triggers,'assigned'=>$assigned,'roles'=>$roles,'audit_max'=>$pdo->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetchColumn(),'tables'=>count($tables),'version'=>$pdo->query('SELECT VERSION()')->fetchColumn()],JSON_THROW_ON_ERROR);
 } elseif ($phase==='backup') {
  $after=max(0,(int)($_GET['after']??0));$through=max(0,(int)($_GET['through']??0));
  $statement=$pdo->prepare('SELECT * FROM audit_logs WHERE id>? AND id<=? ORDER BY id LIMIT 500');$statement->execute([$after,$through]);
  echo json_encode(['ok'=>true,'items'=>$statement->fetchAll()],JSON_THROW_ON_ERROR);
 } elseif ($phase==='install' && $_SERVER['REQUEST_METHOD']==='POST') {
  $pdo->exec("SET @ct_actor_id=NULL,@ct_actor_name='Audit tracking installation',@ct_actor_school_id=NULL,@ct_actor_role='System',@ct_request_id='".bin2hex(random_bytes(16))."',@ct_ip=NULL");
  $coverage=AuditSchema::install($pdo);
  $pdo->beginTransaction();
  $pdo->exec('INSERT IGNORE INTO permissions(code,description) VALUES("view_audit_logs","View administrative audit logs")');
  $pdo->exec('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT 1,id FROM permissions WHERE code="view_audit_logs"');
  $pdo->commit();
  echo json_encode(['ok'=>true,'coverage'=>$coverage],JSON_THROW_ON_ERROR);
 } elseif ($phase==='verify') {
  $tables=AuditSchema::tables($pdo);
  foreach ($tables as $table=>$fields) {
   $statement=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? AND TRIGGER_NAME LIKE "ct_audit_%"');$statement->execute([$table]);
   if ((int)$statement->fetchColumn()!==3) throw new RuntimeException('Incomplete trigger coverage.');
  }
  $assigned=(bool)$pdo->query('SELECT 1 FROM role_permissions JOIN permissions ON permissions.id=permission_id WHERE role_id=1 AND permissions.code="view_audit_logs"')->fetchColumn();
  $indexes=array_column($pdo->query('SHOW INDEX FROM audit_logs')->fetchAll(),'Key_name');
  if (!$assigned || !in_array('ft_audit_search',$indexes,true)) throw new RuntimeException('Audit verification failed.');
  echo json_encode(['ok'=>true,'tables'=>count($tables),'triggers'=>count($tables)*3,'administrator_access'=>$assigned],JSON_THROW_ON_ERROR);
 } elseif ($phase==='rollback' && $_SERVER['REQUEST_METHOD']==='POST') {
  $before=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
  $names=$pdo->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
  foreach ($names as $name) if (preg_match('/\Act_audit_[a-z0-9_]+\z/',$name)) $pdo->exec('DROP TRIGGER `'.$name.'`');
  foreach ($before['triggers'] as $trigger) {
   $name=$trigger['TRIGGER_NAME'];if (!preg_match('/\Act_audit_[a-z0-9_]+\z/',$name)) continue;
   if (!preg_match('/\A[a-zA-Z0-9_]+\z/',$trigger['EVENT_OBJECT_TABLE'])) throw new RuntimeException('Invalid backup schema.');
   $pdo->exec('CREATE TRIGGER `'.$name.'` '.$trigger['ACTION_TIMING'].' '.$trigger['EVENT_MANIPULATION'].' ON `'.$trigger['EVENT_OBJECT_TABLE'].'` FOR EACH ROW '.$trigger['ACTION_STATEMENT']);
  }
  if (!$before['assigned']) $pdo->exec('DELETE role_permissions FROM role_permissions JOIN permissions ON permissions.id=permission_id WHERE role_id=1 AND permissions.code="view_audit_logs"');
  // Additive columns/indexes and any newly recorded history remain intact.
  echo json_encode(['ok'=>true]);
 } else {http_response_code(405);echo json_encode(['ok'=>false]);}
} catch (Throwable $error) {
 if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
 error_log('Audit deployment failed: '.$error->getMessage());http_response_code(500);
 echo json_encode(['ok'=>false,'reason'=>$error instanceof PDOException?'Database trigger or schema operation was rejected.':$error->getMessage()]);
}
'''

def main():
 parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--deploy',action='store_true');args=parser.parse_args()
 if not args.deploy:
  for path in FILES:print(path+' -> '+remote(path))
  return
 backup=ROOT/'storage/audit-trail-backups'/datetime.datetime.now().strftime('%Y%m%d-%H%M%S');backup.mkdir(parents=True,exist_ok=False)
 ftp=ftp_tools.connect();previous={};uploaded=[];before=None;migration_started=False;runner_installed=False
 key=secrets.token_hex(32);runner='audit-deploy-'+secrets.token_hex(10)+'.php';runner_remote=DOMAIN+'/public_html/'+runner
 def request(phase,method='GET',payload=None,**query):
  from urllib.parse import urlencode
  data=json.dumps(payload).encode() if payload is not None else (b'' if method=='POST' else None)
  req=urllib.request.Request('https://jsmkj.space/'+runner+'?'+urlencode({'phase':phase,**query}),data=data,headers={'X-Deploy-Key':key,'Accept':'application/json','Content-Type':'application/json'},method=method)
  try:
   with urllib.request.urlopen(req,timeout=120,context=ssl.create_default_context()) as response: result=json.load(response)
  except urllib.error.HTTPError as error:
   result=json.loads(error.read());raise RuntimeError(result.get('reason','Audit deployment request failed.')) from None
  if not result.get('ok'):raise RuntimeError('Audit deployment verification failed.')
  return result
 def upload(path):
  content=(ROOT/path).read_bytes();ftp_tools.mkdirs(ftp,remote(path).rsplit('/',1)[0]);uploaded.append(path)
  ftp.storbinary('STOR '+remote(path),io.BytesIO(content));received=io.BytesIO();ftp.retrbinary('RETR '+remote(path),received.write)
  if received.getvalue()!=content:raise RuntimeError('Upload verification failed: '+path)
  print('Verified '+path,flush=True)
 try:
  # Validate all runtime destinations before changing anything.
  for path in FILES:
   received=io.BytesIO()
   try:ftp.retrbinary('RETR '+remote(path),received.write);content=received.getvalue()
   except ftplib.error_perm as error:
    if not str(error).startswith('550'):raise
    content=None
   baseline=subprocess.run(['git','show','HEAD:'+path],cwd=ROOT,capture_output=True);prior=baseline.stdout if baseline.returncode==0 else None
   if content is None and prior is not None:raise RuntimeError('Expected production file is missing: '+path)
   equal=lambda a,b:a is not None and b is not None and a.replace(b'\r\n',b'\n')==b.replace(b'\r\n',b'\n')
   if content is not None and not equal(content,prior) and not equal(content,(ROOT/path).read_bytes()) and hashlib.sha256(content).hexdigest()!=REVIEWED_DRIFT.get(path):raise RuntimeError('Production drift detected: '+path)
   previous[path]=content
   if content is not None:
    saved=backup/path;saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(content)
  (backup/'manifest.json').write_text(json.dumps({path:{'remote':remote(path),'existed':previous[path] is not None,'sha256':hashlib.sha256((ROOT/path).read_bytes()).hexdigest()} for path in FILES},indent=2),encoding='utf-8')
  upload(FILES[0]);upload(FILES[1])
  ftp.storbinary('STOR '+runner_remote,io.BytesIO(RUNNER.replace('DEPLOY_TOKEN',key).encode()));runner_installed=True
  before=request('inspect');(backup/'database-before.json').write_text(json.dumps(before,indent=2),encoding='utf-8')
  through=int(before['audit_max']);after=0;records=0
  with (backup/'audit-history-before.jsonl').open('w',encoding='utf-8') as history:
   while after<through:
    items=request('backup',after=after,through=through)['items']
    if not items:break
    for row in items:history.write(json.dumps(row,ensure_ascii=False)+'\n')
    records+=len(items);after=int(items[-1]['id'])
  print(f'Backed up schema, existing triggers, permissions, and {records} historical entries.',flush=True)
  migration_started=True;result=request('install','POST');(backup/'database-installed.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
  # Release the UI only after transactional tracking is installed.
  for path in FILES[2:]:upload(path)
  result=request('verify');(backup/'database-verified.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
  print(f"Verified {result['triggers']} triggers across {result['tables']} tables and Administrator access.",flush=True)
  print('Backup: '+str(backup),flush=True)
 except Exception as error:
  failures=[]
  if migration_started and before is not None:
   try:request('rollback','POST',before)
   except Exception:failures.append('Database trigger rollback')
  for path in reversed(uploaded):
   try:
    if previous[path] is None:ftp.delete(remote(path))
    else:ftp.storbinary('STOR '+remote(path),io.BytesIO(previous[path]))
   except Exception:failures.append('Runtime restore: '+path)
  if failures:raise RuntimeError('Rollback needs review: '+', '.join(failures)+'. Backup: '+str(backup)) from error
  raise
 finally:
  if runner_installed:
   ftp.delete(runner_remote)
   try:urllib.request.urlopen('https://jsmkj.space/'+runner,timeout=30)
   except urllib.error.HTTPError as error:
    if error.code!=404:raise RuntimeError('Temporary runner cleanup could not be verified.')
   else:raise RuntimeError('Temporary runner remains accessible.')
   print('Temporary runner removed; 404 verified.',flush=True)
  ftp.close()

if __name__=='__main__':main()
