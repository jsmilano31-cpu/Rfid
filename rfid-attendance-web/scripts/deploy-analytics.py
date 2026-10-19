"""Scoped Analytics rollout: private source backups, drift checks and verified additive installation."""
from pathlib import Path
import argparse, datetime, ftplib, hashlib, importlib.util, io, json, secrets, ssl, subprocess, urllib.error, urllib.request
ROOT=Path(__file__).resolve().parent.parent;DOMAIN='domains/jsmkj.space'
FOUNDATION=['database/migrations/20261003_analytics.sql','src/Services/AnalyticsSchema.php','src/Services/AnalyticsHistory.php','src/Services/AnalyticsWorker.php','src/Services/AnalyticsMetrics.php','src/Services/AuditSchema.php']
FILES=FOUNDATION+[
 'src/Services/AnalyticsService.php','public/api/analytics.php','scripts/install-analytics.php','scripts/analytics-worker.php','scripts/notification-worker.php',
 'public/assets/vendor/echarts/campustrack-charts.min.js','public/assets/vendor/echarts/campustrack-charts.min.js.LEGAL.txt','public/assets/vendor/echarts/LICENSE',
 'public/assets/css/analytics.css','public/assets/js/analytics.js','templates/partials/analytics_workspace.php','templates/admin/analytics.php','templates/portal/analytics.php',
 'src/Exceptions/ScanRejection.php','src/Services/RfidDeviceService.php','src/Services/RfidService.php','src/Services/AttendanceService.php','public/iot/scan.php','src/Services/MessageService.php',
 'src/Services/SearchService.php','src/Controllers/AdminController.php','src/Controllers/PortalController.php','templates/layouts/app.php','templates/admin/users.php',
]
spec=importlib.util.spec_from_file_location('ftp_tools',ROOT/'scripts/deploy-mobile-api.py');ftp_tools=importlib.util.module_from_spec(spec);spec.loader.exec_module(ftp_tools)
def remote(path):return DOMAIN+('/public_html/'+path.removeprefix('public/') if path.startswith('public/') else '/'+path)
RUNNER=r'''<?php
declare(strict_types=1);
if(!hash_equals('DEPLOY_TOKEN',$_SERVER['HTTP_X_DEPLOY_KEY']??'')){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');require dirname(__DIR__).'/src/bootstrap.php';
use App\Core\Database;use App\Services\AnalyticsSchema;use App\Services\AnalyticsWorker;use App\Services\AuditSchema;
try{$pdo=Database::connection();$phase=$_GET['phase']??'inspect';
 if($phase==='inspect'){
  $tables=array_keys(AuditSchema::tables($pdo));$schema=[];foreach($tables as $table)$schema[$table]=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
  echo json_encode(['ok'=>true,'schema'=>$schema,'triggers'=>$pdo->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(),'permissions'=>$pdo->query('SELECT * FROM permissions')->fetchAll(),'role_permissions'=>$pdo->query('SELECT * FROM role_permissions')->fetchAll(),'baseline_exists'=>(bool)$pdo->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="analytics_state"')->fetchColumn()],JSON_THROW_ON_ERROR);
 }elseif($phase==='backup'){
  $table=$_GET['table']??'';$allowed=array_merge(array_keys(AnalyticsSchema::SOURCES),['attendance_records','attendance_sessions','message_recipients','device_scan_logs','audit_logs']);if(!in_array($table,$allowed,true))throw new RuntimeException('Unknown backup table.');$offset=max(0,(int)($_GET['offset']??0));$keys=array_column(array_filter($pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(),static fn($r)=>$r['Key']==='PRI'),'Field');$order=implode(',',array_map(static fn($k)=>'`'.$k.'`',$keys));$rows=$pdo->query('SELECT * FROM `'.$table.'` ORDER BY '.$order.' LIMIT 500 OFFSET '.$offset)->fetchAll();echo json_encode(['ok'=>true,'rows'=>$rows],JSON_THROW_ON_ERROR);
 }elseif($phase==='install' && $_SERVER['REQUEST_METHOD']==='POST'){
  $pdo->exec("SET @ct_actor_id=NULL,@ct_actor_name='Analytics installation',@ct_actor_role='System'");$schema=AnalyticsSchema::install($pdo);$audit=AuditSchema::install($pdo);$build=AnalyticsWorker::run($pdo,true);echo json_encode(['ok'=>true,'schema'=>$schema,'audit'=>$audit,'build'=>$build],JSON_THROW_ON_ERROR);
 }elseif($phase==='verify'){
  $state=$pdo->query('SELECT * FROM analytics_state WHERE id=1')->fetch();if(!$state || !$state['built_at'])throw new RuntimeException('Analytics build did not complete.');$triggers=$pdo->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE "ct_an_%"')->fetchAll(PDO::FETCH_COLUMN);if(count($triggers)<80)throw new RuntimeException('History trigger coverage is incomplete.');$coverage=AuditSchema::install($pdo);$marker=BASE_PATH.'/storage/private/notification-worker-status.json';$worker=is_file($marker)?json_decode(file_get_contents($marker),true):null;echo json_encode(['ok'=>true,'state'=>$state,'history_triggers'=>count($triggers),'audit'=>$coverage,'worker'=>$worker],JSON_THROW_ON_ERROR);
 }else{http_response_code(405);echo json_encode(['ok'=>false]);}
}catch(Throwable $e){http_response_code(500);error_log('Analytics deployment: '.$e->getMessage());echo json_encode(['ok'=>false,'reason'=>$e->getMessage()]);}
'''
def main():
 parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--deploy',action='store_true');args=parser.parse_args()
 if not args.deploy:
  print('\n'.join(FILES));print('Dry run: '+str(len(FILES))+' scoped runtime files.');return
 backup=ROOT/'storage/analytics-backups'/datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S');backup.mkdir(parents=True)
 ftp=ftp_tools.connect();previous={};uploaded=[];installed=False;runner_installed=False;key=secrets.token_hex(32);runner='analytics-deploy-'+secrets.token_hex(10)+'.php';runner_remote=DOMAIN+'/public_html/'+runner
 def request(phase,method='GET',**query):
  from urllib.parse import urlencode
  req=urllib.request.Request('https://jsmkj.space/'+runner+'?'+urlencode({'phase':phase,**query}),data=b'' if method=='POST' else None,headers={'X-Deploy-Key':key,'Accept':'application/json'},method=method)
  try:
   with urllib.request.urlopen(req,timeout=180,context=ssl.create_default_context()) as response:payload=json.load(response)
  except urllib.error.HTTPError as e:raise RuntimeError(json.loads(e.read()).get('reason','Analytics rollout failed.')) from None
  if not payload.get('ok'):raise RuntimeError('Analytics rollout verification failed.')
  return payload
 def upload(path):
  content=(ROOT/path).read_bytes();ftp_tools.mkdirs(ftp,remote(path).rsplit('/',1)[0]);ftp.storbinary('STOR '+remote(path),io.BytesIO(content));uploaded.append(path);received=io.BytesIO();ftp.retrbinary('RETR '+remote(path),received.write)
  if received.getvalue()!=content:raise RuntimeError('File verification failed: '+path)
  print('Verified '+path,flush=True)
 try:
  # A retry may find byte-verified files from this rollout's earlier attempt.
  known_versions={}
  for saved_manifest in (ROOT/'storage/analytics-backups').glob('*/manifest.json'):
   saved=json.loads(saved_manifest.read_text(encoding='utf-8'))
   for path,entry in saved.items():
    if path in FILES and entry.get('remote')==remote(path):known_versions.setdefault(path,set()).add(entry['sha256'])
  # Compare with the pre-change commit or our recorded rollout, before uploads.
  for path in FILES:
   content=io.BytesIO()
   try:ftp.retrbinary('RETR '+remote(path),content.write);old=content.getvalue()
   except ftplib.error_perm as e:
    if not str(e).startswith('550'):raise
    old=None
   base=subprocess.run(['git','show','HEAD:'+path],cwd=ROOT,capture_output=True);prior=base.stdout if base.returncode==0 else None
   equal=lambda a,b:a is not None and b is not None and a.replace(b'\r\n',b'\n')==b.replace(b'\r\n',b'\n')
   if old is None and prior is not None:raise RuntimeError('Expected production file missing: '+path)
   if old is not None and not equal(old,prior) and not equal(old,(ROOT/path).read_bytes()) and hashlib.sha256(old).hexdigest() not in known_versions.get(path,set()):raise RuntimeError('Production drift: '+path)
   previous[path]=old
   if old is not None:saved=backup/path;saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(old)
  (backup/'manifest.json').write_text(json.dumps({p:{'remote':remote(p),'existed':previous[p] is not None,'sha256':hashlib.sha256((ROOT/p).read_bytes()).hexdigest()} for p in FILES},indent=2),encoding='utf-8')
  # The schema reader is uploaded before the protected inspector; no UI is exposed yet.
  upload('src/Services/AnalyticsSchema.php');ftp.storbinary('STOR '+runner_remote,io.BytesIO(RUNNER.replace('DEPLOY_TOKEN',key).encode()));runner_installed=True
  before=request('inspect');(backup/'database-before.json').write_text(json.dumps(before,indent=2),encoding='utf-8')
  for table in list(dict.fromkeys(list(AnalyticsSchema_sources())+['attendance_records','attendance_sessions','message_recipients','device_scan_logs','audit_logs'])):
   offset=0
   with (backup/(table+'.jsonl')).open('w',encoding='utf-8') as saved:
    while True:
     rows=request('backup',table=table,offset=offset)['rows']
     for row in rows:saved.write(json.dumps(row,ensure_ascii=False)+'\n')
     offset+=len(rows)
     if len(rows)<500:break
   print('Backed up '+table+': '+str(offset)+' rows.',flush=True)
  ftp.close();ftp=ftp_tools.connect()
  for path in FOUNDATION:
   if path!='src/Services/AnalyticsSchema.php':upload(path)
  installed=True;result=request('install','POST');(backup/'database-installed.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
  ftp.close();ftp=ftp_tools.connect()
  for path in FILES:
   if path not in FOUNDATION:upload(path)
  verified=request('verify');(backup/'database-verified.json').write_text(json.dumps(verified,indent=2),encoding='utf-8');print('Verified history, audit tracking, initial facts and runtime files. Backup: '+str(backup),flush=True)
  # Existing cron must run the new worker marker; waiting does not trigger external sends.
  import time
  for attempt in range(6):
   result=request('verify');marker=result.get('worker') or {}
   if 'analytics_built_at' in marker:print('Existing cron verified Analytics scheduling.',flush=True);break
   print('Waiting for the existing scheduled worker marker…',flush=True);time.sleep(20)
  else:raise RuntimeError('Existing cron did not expose the Analytics completion marker; inspect the private backup before enabling.')
 except Exception:
  # Additive history is retained if installation started; never discard captured data.
  ftp.close();ftp=ftp_tools.connect()
  for path in reversed(uploaded):
   try:
    if previous[path] is None:
     if not installed:ftp.delete(remote(path))
    else:ftp.storbinary('STOR '+remote(path),io.BytesIO(previous[path]))
   except Exception:print('Restore requires review: '+path,flush=True)
  print('Rollout stopped. Private backup: '+str(backup),flush=True);raise
 finally:
  if runner_installed:
   ftp.close();ftp=ftp_tools.connect()
   ftp.delete(runner_remote)
   try:urllib.request.urlopen('https://jsmkj.space/'+runner,timeout=30)
   except urllib.error.HTTPError as e:
    if e.code!=404:raise RuntimeError('Temporary runner cleanup failed.')
   else:raise RuntimeError('Temporary runner remains public.')
   print('Temporary inspector removed; 404 verified.',flush=True)
  ftp.close()

def AnalyticsSchema_sources():
 # Parse only the explicit source registry; no connection secrets are read here.
 import re
 text=(ROOT/'src/Services/AnalyticsSchema.php').read_text(encoding='utf-8').split('public const SOURCES = [',1)[1].split('public const DERIVED',1)[0]
 return re.findall(r"'([a-z_]+)'\s*=>\s*\[",text)
if __name__=='__main__':main()
