"""Scoped Philippine holiday rollout with drift checks, private backups and additive history installation."""
from pathlib import Path
import argparse, datetime, ftplib, hashlib, importlib.util, io, json, secrets, subprocess, urllib.request, urllib.error
ROOT=Path(__file__).resolve().parent.parent;DOMAIN='domains/jsmkj.space'
FILES=['database/migrations/20261003_campus_holidays.sql','database/data/philippine-holidays-2026.json',
 'src/Services/PhilippineHolidayService.php','src/Services/AcademicCalendarService.php','src/Services/RfidDeviceService.php',
 'src/Services/AnalyticsSchema.php','src/Services/AnalyticsWorker.php','templates/partials/holiday_dialog.php',
 'templates/partials/dashboard_calendar.php','templates/layouts/app.php','public/assets/js/holidays.js','public/assets/js/app.js',
 'public/assets/js/attendance.js','public/assets/css/app.css','public/api/holidays.php']
spec=importlib.util.spec_from_file_location('ftp_tools',ROOT/'scripts/deploy-mobile-api.py');ftp_tools=importlib.util.module_from_spec(spec);spec.loader.exec_module(ftp_tools)
def remote(path):return DOMAIN+('/public_html/'+path.removeprefix('public/') if path.startswith('public/') else '/'+path)
RUNNER=r'''<?php
declare(strict_types=1);
if(!hash_equals('DEPLOY_TOKEN',$_SERVER['HTTP_X_DEPLOY_KEY']??'')){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');require dirname(__DIR__).'/src/bootstrap.php';
use App\Core\Database;use App\Services\AnalyticsSchema;use App\Services\AuditSchema;
try{$pdo=Database::connection();$phase=$_GET['phase']??'inspect';
if($phase==='inspect'){
 $schema=[];foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$schema[$table]=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
 $exists=isset($schema['campus_holidays']);
 echo json_encode(['ok'=>true,'schema'=>$schema,'triggers'=>$pdo->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(),'permissions'=>$pdo->query('SELECT * FROM permissions')->fetchAll(),'role_permissions'=>$pdo->query('SELECT * FROM role_permissions')->fetchAll(),'state'=>$pdo->query('SELECT * FROM analytics_state')->fetch(),'holidays'=>$exists?$pdo->query('SELECT * FROM campus_holidays')->fetchAll():[]],JSON_THROW_ON_ERROR);
}elseif($phase==='prepare' && $_SERVER['REQUEST_METHOD']==='POST'){
 foreach(explode(';',file_get_contents(BASE_PATH.'/database/migrations/20261003_campus_holidays.sql')) as $sql)if(trim($sql)!=='')$pdo->exec($sql);echo json_encode(['ok'=>true]);
}elseif($phase==='install' && $_SERVER['REQUEST_METHOD']==='POST'){
 $before=$pdo->query('SELECT baseline_at FROM analytics_state WHERE id=1')->fetchColumn();AnalyticsSchema::install($pdo);AuditSchema::install($pdo);
 if($before!==$pdo->query('SELECT baseline_at FROM analytics_state WHERE id=1')->fetchColumn())throw new RuntimeException('Historical baseline changed.');echo json_encode(['ok'=>true,'baseline_preserved'=>true]);
}elseif($phase==='verify'){
 $triggers=$pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='campus_holidays'")->fetchAll(PDO::FETCH_COLUMN);
 if(count($triggers)!==6)throw new RuntimeException('Holiday audit/history trigger coverage incomplete.');
 echo json_encode(['ok'=>true,'trigger_count'=>count($triggers),'holiday_rows'=>$pdo->query('SELECT COUNT(*) FROM campus_holidays')->fetchColumn(),'baseline_at'=>$pdo->query('SELECT baseline_at FROM analytics_state WHERE id=1')->fetchColumn()]);
}else{http_response_code(405);echo json_encode(['ok'=>false]);}
}catch(Throwable $e){error_log('Holiday rollout failure: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Holiday rollout verification failed.']);}
'''
def main():
 parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--deploy',action='store_true');args=parser.parse_args()
 if not args.deploy:print('\n'.join(FILES));return
 backup=ROOT/'storage/holiday-backups'/datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S');backup.mkdir(parents=True)
 ftp=ftp_tools.connect();previous={};uploaded=[];runner_installed=False;token=secrets.token_hex(32);runner='holiday-deploy-'+secrets.token_hex(10)+'.php';runner_remote=DOMAIN+'/public_html/'+runner
 def reconnect():
  nonlocal ftp
  ftp.close();ftp=ftp_tools.connect()
 def request(phase,method='GET'):
  req=urllib.request.Request('https://jsmkj.space/'+runner+'?phase='+phase,data=b'' if method=='POST' else None,headers={'X-Deploy-Key':token},method=method)
  try:
   with urllib.request.urlopen(req,timeout=180) as r:data=json.load(r)
  except urllib.error.HTTPError as e:raise RuntimeError('Holiday rollout rejected the '+phase+' step (HTTP '+str(e.code)+').') from None
  if not data.get('ok'):raise RuntimeError('Holiday rollout verification failed.')
  return data
 def upload(path):
  content=(ROOT/path).read_bytes();ftp_tools.mkdirs(ftp,remote(path).rsplit('/',1)[0]);temporary=remote(path)+'.'+secrets.token_hex(5)+'.tmp'
  ftp.storbinary('STOR '+temporary,io.BytesIO(content));ftp.rename(temporary,remote(path));uploaded.append(path)
  received=io.BytesIO();ftp.retrbinary('RETR '+remote(path),received.write)
  if received.getvalue()!=content:raise RuntimeError('File verification failed: '+path)
  print('Verified '+path,flush=True)
 try:
  known_versions={}
  for manifest_path in (ROOT/'storage/holiday-backups').glob('*/manifest.json'):
   for path,entry in json.loads(manifest_path.read_text(encoding='utf-8')).items():
    if path in FILES and entry.get('remote')==remote(path):known_versions.setdefault(path,set()).add(entry['sha256'])
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
  ftp.storbinary('STOR '+runner_remote,io.BytesIO(RUNNER.replace('DEPLOY_TOKEN',token).encode()));runner_installed=True
  before=request('inspect');(backup/'database-before.json').write_text(json.dumps(before,indent=2),encoding='utf-8');reconnect()
  upload('database/migrations/20261003_campus_holidays.sql');request('prepare','POST');reconnect()
  history_installed='campus_holidays' in before['schema'] and len([t for t in before['triggers'] if t['EVENT_OBJECT_TABLE']=='campus_holidays'])==6 and previous['src/Services/AnalyticsSchema.php']==(ROOT/'src/Services/AnalyticsSchema.php').read_bytes()
  upload('src/Services/AnalyticsSchema.php')
  if not history_installed:
   (backup/'database-installed.json').write_text(json.dumps(request('install','POST'),indent=2));reconnect()
  publish_last=['public/assets/js/app.js','templates/partials/dashboard_calendar.php','templates/layouts/app.php']
  for path in [p for p in FILES if p not in publish_last]+publish_last:
   if path not in ['src/Services/AnalyticsSchema.php','database/migrations/20261003_campus_holidays.sql']:upload(path)
  result=request('verify')
  if result['baseline_at']!=before['state']['baseline_at']:raise RuntimeError('Historical baseline changed.')
  (backup/'database-verified.json').write_text(json.dumps(result,indent=2));print('Verified holiday table, audit/history coverage and original baseline. Private backup: '+str(backup),flush=True)
 except Exception:
  reconnect()
  for path in reversed(uploaded):
   if previous[path] is not None:ftp.storbinary('STOR '+remote(path),io.BytesIO(previous[path]))
  # Additive schema/history and newly introduced files are retained for safe retry.
  print('Rollout stopped; original runtime files restored. Private backup: '+str(backup),flush=True);raise
 finally:
  if runner_installed:
   reconnect();ftp.delete(runner_remote)
   try:urllib.request.urlopen('https://jsmkj.space/'+runner,timeout=30)
   except urllib.error.HTTPError as e:
    if e.code!=404:raise RuntimeError('Inspector cleanup failed.')
   else:raise RuntimeError('Inspector remains publicly reachable.')
   print('Temporary inspector removed; 404 verified.',flush=True)
  ftp.close()
if __name__=='__main__':main()
