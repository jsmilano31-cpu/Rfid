"""Scoped recollection date-range rollout with protected history, grants, backups and drift checks."""
from pathlib import Path
import argparse, datetime, ftplib, hashlib, importlib.util, io, json, secrets, subprocess, urllib.request, urllib.error
ROOT=Path(__file__).resolve().parent.parent
DOMAIN='domains/jsmkj.space'
FILES=['src/Services/AnalyticsSchema.php', 'src/Services/AuditSchema.php', 'src/Services/RecollectionScheduleSchema.php', 'database/migrations/20261007_recollection_range.php', 'src/Services/RecollectionService.php', 'src/Services/CalendarService.php', 'src/Services/AnalyticsMetrics.php', 'src/Services/AnalyticsService.php', 'src/Services/DashboardService.php', 'src/Services/SearchService.php', 'templates/partials/recollection_schedule_fields.php', 'templates/partials/recollection_schedule.php', 'templates/admin/recollections.php', 'templates/partials/dashboard_calendar.php', 'templates/portal/dashboard.php', 'templates/portal/recollections.php', 'public/assets/js/recollection-management.js', 'public/assets/js/app.js', 'public/assets/css/app.css']
spec=importlib.util.spec_from_file_location('ftp_tools',ROOT/'scripts/deploy-mobile-api.py')
ftp_tools=importlib.util.module_from_spec(spec);spec.loader.exec_module(ftp_tools)
def remote(path):return DOMAIN+('/public_html/'+path.removeprefix('public/') if path.startswith('public/') else '/'+path)
RUNNER=r'''<?php
declare(strict_types=1);
if(!hash_equals('DEPLOY_TOKEN',$_SERVER['HTTP_X_DEPLOY_KEY']??'')){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');require dirname(__DIR__).'/src/bootstrap.php';
use App\Core\Database;use App\Services\RecollectionScheduleSchema;
try {
 $pdo=Database::connection();$phase=$_GET['phase']??'';
 $out=['ok'=>true,'baseline'=>$pdo->query('SELECT baseline_at FROM analytics_state WHERE id=1')->fetchColumn(),'sources'=>[]];
 foreach(['roles','permissions','role_permissions'] as $table)$out['sources'][$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
 if($phase==='backup') {
  $out['database']=[];
  foreach(['recollections','recollection_participants','recollection_attendance','recollection_target_users','recollection_target_roles','audit_tracking_tables'] as $table)$out['database'][$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
  $out['history']=$pdo->query('SELECT * FROM analytics_history WHERE source_table="recollections"')->fetchAll();
  $out['triggers']=$pdo->query('SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll();
 }
 if($phase==='migrate') {
  $pdo->exec("SET @ct_actor_id=NULL,@ct_actor_name='Recollection date-range rollout',@ct_actor_role='System'");
  $out['migration']=RecollectionScheduleSchema::install($pdo);
 }
 if($phase==='verify') {
  $columns=array_column($pdo->query('SHOW COLUMNS FROM recollections')->fetchAll(),null,'Field');
  if(($columns['end_date']['Type']??'')!=='date')throw new RuntimeException('End-date schema mismatch.');
  if($pdo->query('SELECT COUNT(*) FROM recollections WHERE end_date IS NULL OR end_date<date')->fetchColumn())throw new RuntimeException('Legacy ranges not preserved.');
  $out['database']=[];
  foreach(['recollections','recollection_participants','recollection_attendance','recollection_target_users','recollection_target_roles'] as $table)$out['database'][$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
  if($pdo->query("SELECT COUNT(*) FROM analytics_history WHERE source_table='recollections' AND JSON_CONTAINS_PATH(payload,'one','$.end_date')=0")->fetchColumn())throw new RuntimeException('Historical range coverage missing.');
  $out['range_index']=$pdo->query('SHOW INDEX FROM recollections WHERE Key_name="idx_recollection_dates"')->fetchAll();
  if(count($out['range_index'])!==2)throw new RuntimeException('Date-range index missing.');
  $out['audit']=$pdo->query('SELECT * FROM audit_tracking_tables WHERE table_name="recollections"')->fetch();
  if(!$out['audit'])throw new RuntimeException('Recollection audit coverage missing.');
  $out['triggers']=$pdo->query('SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE="recollections"')->fetchAll();
  $history=0;$audit=0;
  foreach($out['triggers'] as $trigger){if(str_contains($trigger['ACTION_STATEMENT'],'end_date')){if(str_starts_with($trigger['TRIGGER_NAME'],'ct_an_'))$history++;if(str_starts_with($trigger['TRIGGER_NAME'],'ct_audit_'))$audit++;}}
  if($history<3 || $audit<3)throw new RuntimeException('Date ranges lack transactional history or audit.');
 }
 echo json_encode($out,JSON_THROW_ON_ERROR);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('Recollection rollout: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Recollection rollout verification failed.']);}
'''

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--apply',action='store_true');parser.add_argument('--files',nargs='+',choices=FILES);args=parser.parse_args();selected=args.files or FILES
 if not args.apply:print('Scoped deployment files: '+str(len(selected)));return
 backup=ROOT/'storage/recollection-schedule-backups'/datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S');backup.mkdir(parents=True)
 token=secrets.token_hex(32);runner='recollection-schedule-'+secrets.token_hex(12)+'.php';runner_remote=DOMAIN+'/public_html/'+runner
 ftp=ftp_tools.connect();previous={};uploaded=[];installed=False
 def reconnect():
  nonlocal ftp
  try:ftp.close()
  except Exception:pass
  ftp=ftp_tools.connect()
 def request(phase):
  req=urllib.request.Request('https://jsmkj.space/'+runner+'?phase='+phase,headers={'X-Deploy-Key':token})
  with urllib.request.urlopen(req,timeout=60) as response:data=json.load(response)
  if not data.get('ok'):raise RuntimeError('Read-only verification failed.')
  return data
 try:
  known={}
  for folder in ['holiday-backups','shared-grade-backups','permission-coverage-backups','event-workspace-backups','recollection-schedule-backups']:
   for manifest_path in (ROOT/'storage'/folder).glob('*/manifest.json'):
    for path,entry in json.loads(manifest_path.read_text(encoding='utf-8')).items():
     if path in selected and entry.get('remote')==remote(path):known.setdefault(path,set()).add(entry['sha256'])
  equal=lambda a,b:a is not None and b is not None and a.replace(b'\r\n',b'\n')==b.replace(b'\r\n',b'\n')
  for path in selected:
   stream=io.BytesIO()
   try:ftp.retrbinary('RETR '+remote(path),stream.write);old=stream.getvalue()
   except ftplib.error_perm as e:
    if not str(e).startswith('550'):raise
    old=None
   source=subprocess.run(['git','show','HEAD:'+path],cwd=ROOT,capture_output=True);prior=source.stdout if source.returncode==0 else None
   if old is None and prior is not None:raise RuntimeError('Expected production file missing: '+path)
   if old is not None and not equal(old,prior) and not equal(old,(ROOT/path).read_bytes()) and hashlib.sha256(old).hexdigest() not in known.get(path,set()):raise RuntimeError('Production drift: '+path)
   previous[path]=old
   if old is not None:saved=backup/path;saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(old)
  (backup/'manifest.json').write_text(json.dumps({p:{'remote':remote(p),'existed':previous[p] is not None,'sha256':hashlib.sha256((ROOT/p).read_bytes()).hexdigest()} for p in selected},indent=2),encoding='utf-8')
  ftp.storbinary('STOR '+runner_remote,io.BytesIO(RUNNER.replace('DEPLOY_TOKEN',token).encode()));installed=True
  before=request('backup');(backup/'database-before.json').write_text(json.dumps(before),encoding='utf-8');reconnect()
  for path in selected:
   content=(ROOT/path).read_bytes();ftp_tools.mkdirs(ftp,remote(path).rsplit('/',1)[0]);temporary=remote(path)+'.'+secrets.token_hex(5)+'.tmp'
   ftp.storbinary('STOR '+temporary,io.BytesIO(content));ftp.rename(temporary,remote(path));uploaded.append(path)
   check=io.BytesIO();ftp.retrbinary('RETR '+remote(path),check.write)
   if check.getvalue()!=content:raise RuntimeError('Uploaded bytes differ: '+path)
   print('Verified '+path,flush=True)
   if path=='src/Services/RecollectionScheduleSchema.php':
    migrated=request('migrate');(backup/'database-migrated.json').write_text(json.dumps(migrated),encoding='utf-8');reconnect()
  result=request('verify')
  if result['baseline']!=before['baseline']:raise RuntimeError('Analytics baseline changed.')
  prior_ids={int(p['id']) for p in before['sources']['permissions']}
  grants=lambda data:{(int(r['role_id']),int(r['permission_id'])) for r in data['sources']['role_permissions'] if int(r['permission_id']) in prior_ids}
  if grants(result)!=grants(before):raise RuntimeError('Existing role grants changed.')
  role_ids={int(r['id']) for r in before['sources']['roles']}
  new_ids={int(p['id']) for p in result['sources']['permissions']} - prior_ids
  added={(int(r['role_id']),int(r['permission_id'])) for r in result['sources']['role_permissions']}
  if not {(r,p) for r in role_ids for p in new_ids}.issubset(added):raise RuntimeError('Former login-only access was not preserved.')

  for table, rows in before['database'].items():
   if table=='audit_tracking_tables':continue
   after={int(r['id']):r for r in result['database'][table]}
   for row in rows:
    if int(row['id']) not in after or any(after[int(row['id'])].get(k)!=v for k,v in row.items()):raise RuntimeError('Existing business records changed: '+table)
  old_activities={int(r['id']):r for r in before['database']['recollections']}
  for row in result['database']['recollections']:
   old=old_activities.get(int(row['id']))
   if old and row['end_date']!=(old.get('end_date') or old['date']):raise RuntimeError('Existing activity date range changed.')

  (backup/'verified.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
  print('Recollection ranges, history, audit coverage, unchanged grants and files verified. Private backup: '+str(backup),flush=True)
 except Exception:
  reconnect()
  for path in reversed(uploaded):
   if previous[path] is not None:ftp.storbinary('STOR '+remote(path),io.BytesIO(previous[path]))
   else:ftp.delete(remote(path))
  print('Rollout stopped; previous runtime files restored. Additive recollection dates and history remain installed. Private backup: '+str(backup),flush=True);raise
 finally:
  if installed:
   reconnect();ftp.delete(runner_remote)
   try:urllib.request.urlopen('https://jsmkj.space/'+runner,timeout=30)
   except urllib.error.HTTPError as e:
    if e.code!=404:raise RuntimeError('Inspector cleanup failed.')
   else:raise RuntimeError('Inspector remains reachable.')
   print('Temporary inspector removed; 404 verified.',flush=True)
  ftp.close()
if __name__=='__main__':main()
