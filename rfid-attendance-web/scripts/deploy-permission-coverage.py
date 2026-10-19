"""Scoped web permission rollout with additive migration, backups, drift checks and file verification."""
from pathlib import Path
import argparse, datetime, ftplib, hashlib, importlib.util, io, json, secrets, subprocess, urllib.request, urllib.error
ROOT=Path(__file__).resolve().parent.parent
DOMAIN='domains/jsmkj.space'
FILES=["database/migrations/20261004_permission_coverage.sql","src/Core/PermissionRegistry.php","public/admin/calendar-events.php","public/admin/user.php","public/api/holidays.php","public/api/message-attachment.php","public/api/messages.php","public/api/notifications.php","public/api/search.php","public/assets/js/admin-control.js","public/assets/js/messages.js","public/attendance.php","public/class-roster.php","public/lookups/users.php","src/Controllers/AdminController.php","src/Controllers/PortalController.php","src/Core/Auth.php","src/Services/AcademicCalendarService.php","src/Services/AnalyticsService.php","src/Services/AttendanceService.php","src/Services/CalendarService.php","src/Services/DashboardService.php","src/Services/DirectoryProfileService.php","src/Services/GradeService.php","src/Services/LiveSeatPlanService.php","src/Services/PhilippineHolidayService.php","src/Services/SearchService.php","src/Services/UserPreferenceService.php","templates/admin/attendance.php","templates/admin/dashboard.php","templates/admin/grades.php","templates/admin/profile.php","templates/admin/rooms.php","templates/admin/users.php","templates/layouts/app.php","templates/partials/dashboard_calendar.php","templates/partials/events_social_feed.php","templates/partials/live_seat_plan.php","templates/partials/mailbox.php","templates/portal/dashboard.php"]
FILES.append('src/Services/MessageService.php')
FILES.append('public/assets/css/app.css')
spec=importlib.util.spec_from_file_location('ftp_tools',ROOT/'scripts/deploy-mobile-api.py')
ftp_tools=importlib.util.module_from_spec(spec);spec.loader.exec_module(ftp_tools)
def remote(path):return DOMAIN+('/public_html/'+path.removeprefix('public/') if path.startswith('public/') else '/'+path)
RUNNER=r'''<?php
declare(strict_types=1);
if(!hash_equals('DEPLOY_TOKEN',$_SERVER['HTTP_X_DEPLOY_KEY']??'')){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');require dirname(__DIR__).'/src/bootstrap.php';
use App\Core\Database;use App\Core\Auth;use App\Core\PermissionRegistry;
try {
 $pdo=Database::connection();$phase=$_GET['phase']??'';
 if($phase==='migrate') {
  $pdo->beginTransaction();
  $sql=preg_replace('/^\\s*--.*$/m','',file_get_contents(BASE_PATH.'/database/migrations/20261004_permission_coverage.sql'));
  foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
  $pdo->commit();
 }
 $out=['ok'=>true,'baseline'=>$pdo->query('SELECT baseline_at FROM analytics_state WHERE id=1')->fetchColumn(),'sources'=>[]];
 foreach(['roles','permissions','role_permissions'] as $table)$out['sources'][$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
 if($phase==='verify') {
  $codes=$pdo->query('SELECT code FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
  foreach(array_keys(PermissionRegistry::SELF_SERVICE) as $code)if(!in_array($code,$codes,true))throw new RuntimeException('Permission catalog incomplete.');
  $admin=(int)$pdo->query('SELECT id FROM users WHERE role_id=1 AND status="active" ORDER BY id LIMIT 1')->fetchColumn();
  $teacher=(int)$pdo->query('SELECT id FROM roles WHERE name="Teacher" LIMIT 1')->fetchColumn();
  if($admin>0 && $teacher>0) {
   $_SESSION['user_id']=$admin;Auth::setPreviewRole($teacher);
   $out['teacher_preview']=['can_edit_calendar'=>PermissionRegistry::canManageCampusCalendar(),'policy_grant'=>Auth::can('manage_attendance_policies'),'calendar_grant'=>Auth::can('manage_academic_calendar'),'global_attendance'=>\App\Services\AttendanceService::hasGlobalAccess($admin),'global_grades'=>\App\Services\GradeService::hasGlobalAccess($admin)];
   if($out['teacher_preview']['global_attendance'] || $out['teacher_preview']['global_grades'])throw new RuntimeException('Teacher preview inherited global access.');
   if($out['teacher_preview']['can_edit_calendar'] !== ($out['teacher_preview']['policy_grant'] && $out['teacher_preview']['calendar_grant']))throw new RuntimeException('Calendar permission gate disagrees.');
   Auth::setPreviewRole(null);unset($_SESSION['user_id']);
  }
 }
 echo json_encode($out,JSON_THROW_ON_ERROR);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Permission coverage verification failed.']);}
'''
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--apply',action='store_true');parser.add_argument('--files',nargs='+',choices=FILES);args=parser.parse_args();selected=args.files or FILES
 if not args.apply:print('Scoped deployment files: '+str(len(selected)));return
 backup=ROOT/'storage/permission-coverage-backups'/datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S');backup.mkdir(parents=True)
 token=secrets.token_hex(32);runner='permissions-'+secrets.token_hex(12)+'.php';runner_remote=DOMAIN+'/public_html/'+runner
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
  for folder in ['holiday-backups','shared-grade-backups','permission-coverage-backups']:
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
   if path=='database/migrations/20261004_permission_coverage.sql':
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

  (backup/'verified.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
  print('Permission catalog, unchanged existing grants, View As scope and files verified. Private backup: '+str(backup),flush=True)
 except Exception:
  reconnect()
  for path in reversed(uploaded):
   if previous[path] is not None:ftp.storbinary('STOR '+remote(path),io.BytesIO(previous[path]))
  print('Rollout stopped; previous runtime files restored. Additive permission catalog may remain safely installed. Private backup: '+str(backup),flush=True);raise
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
