"""Scoped Teacher portal rollout; private backups, drift checks and reversible role migration."""
from pathlib import Path
import argparse, datetime, ftplib, hashlib, importlib.util, io, json, secrets, subprocess, urllib.request, urllib.error
ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('permission_deploy',ROOT/'scripts/deploy-permission-coverage.py')
shared=importlib.util.module_from_spec(spec);spec.loader.exec_module(shared)
FILES=['database/migrations/20261005_teacher_portal.sql','src/Core/PermissionRegistry.php','src/Core/Auth.php','src/Services/AttendanceService.php','src/Services/LiveSeatPlanService.php','src/Services/SeatPlanService.php','src/Services/SearchService.php','src/Services/EventService.php','src/Controllers/AdminController.php','src/Controllers/PortalController.php','public/attendance.php','public/seat-plans.php','public/lookups/users.php','public/assets/js/attendance.js','public/assets/js/live-seat-plan.js','templates/admin/users.php','templates/layouts/app.php','templates/partials/attendance_management.php','templates/partials/live_seat_plan.php','templates/portal/attendance.php','templates/portal/dashboard.php','templates/portal/seat-plans.php']
TEACHER={'view_dashboard','view_schedule','view_attendance','view_grades','view_class_rosters','view_events','view_recollections','view_people','view_messages','view_compliance','view_analytics','use_search','manage_own_profile','manage_own_settings','manage_own_notifications','send_messages','manage_grades','correct_class_attendance','assign_class_seats'}
RUNNER=r'''<?php
declare(strict_types=1);
if(!hash_equals('DEPLOY_TOKEN',$_SERVER['HTTP_X_DEPLOY_KEY']??'')){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');require dirname(__DIR__).'/src/bootstrap.php';
use App\Core\Database;use App\Core\Auth;use App\Core\PermissionRegistry as P;
try {
 $pdo=Database::connection();$phase=$_GET['phase']??'';
 $snapshot=static function()use($pdo){$out=['ok'=>true,'baseline'=>$pdo->query('SELECT baseline_at FROM analytics_state WHERE id=1')->fetchColumn(),'sources'=>[]];foreach(['roles','permissions','role_permissions']as$table)$out['sources'][$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();return $out;};
 $grants=static function($out){$rows=array_map(static fn($r)=>[(int)$r['role_id'],(int)$r['permission_id']],$out['sources']['role_permissions']);sort($rows);return $rows;};
 if($phase==='migrate'||$phase==='rollback') {
  $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
  $pdo->beginTransaction();$pdo->query('SELECT role_id,permission_id FROM role_permissions FOR UPDATE')->fetchAll();
  if($grants($snapshot())!==$grants($input['expected']))throw new RuntimeException('Concurrent permission edits.');
  if($phase==='migrate'){
   $sql=preg_replace('/^\s*--.*$/m','',file_get_contents(BASE_PATH.'/database/migrations/20261005_teacher_portal.sql'));
   foreach(explode(';',$sql)as$s)if(trim($s))$pdo->exec($s);
  }else{
   $pdo->exec('DELETE FROM role_permissions');$insert=$pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)');
   foreach($input['before']['sources']['role_permissions']as$r)$insert->execute([(int)$r['role_id'],(int)$r['permission_id']]);
   $prior=array_map('intval',array_column($input['before']['sources']['permissions'],'id'));
   foreach($snapshot()['sources']['permissions']as$p)if(!in_array((int)$p['id'],$prior,true)&&in_array($p['code'],['correct_class_attendance','assign_class_seats'],true))$pdo->prepare('DELETE FROM permissions WHERE id=?')->execute([(int)$p['id']]);
  }
  $pdo->commit();
 }
 $out=$snapshot();
 if($phase==='verify'){
  $admin=(int)$pdo->query('SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name="Administrator" AND u.status="active" ORDER BY u.id LIMIT 1')->fetchColumn();
  $teacher=(int)$pdo->query('SELECT id FROM roles WHERE name="Teacher"')->fetchColumn();
  if(!$admin||!$teacher)throw new RuntimeException('Verification role missing.');
  $_SESSION['user_id']=$admin;Auth::setPreviewRole($teacher);
  $out['teacher_preview']=['admin_area'=>Auth::usesAdminArea(),'correct'=>P::canCorrectAttendance(),'assign'=>P::canAssignSeats(),'grades'=>Auth::can('manage_grades'),'policy'=>P::canManageCampusCalendar(),'layout'=>Auth::can('manage_seat_plans'),'global_attendance'=>\App\Services\AttendanceService::hasGlobalAccess($admin),'global_grades'=>\App\Services\GradeService::hasGlobalAccess($admin),'profile'=>P::showOwnProfile()];
  foreach(['admin_area','policy','layout','global_attendance','global_grades']as$k)if($out['teacher_preview'][$k])throw new RuntimeException('Teacher restriction failed.');
  foreach(['correct','assign','grades','profile']as$k)if(!$out['teacher_preview'][$k])throw new RuntimeException('Teacher classroom permission missing.');
  Auth::setPreviewRole(null);if(P::showOwnProfile())throw new RuntimeException('Administrator personal Profile visible.');unset($_SESSION['user_id']);
 }
 echo json_encode($out,JSON_THROW_ON_ERROR);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Teacher portal rollout verification failed.']);}
'''

def grant_codes(data):
 codes={int(p['id']):p['code'] for p in data['sources']['permissions']}
 return {(int(r['role_id']),codes[int(r['permission_id'])]) for r in data['sources']['role_permissions']}

def main():
 parser=argparse.ArgumentParser();parser.add_argument('--apply',action='store_true');args=parser.parse_args()
 if not args.apply:print(f'Scoped Teacher portal deployment: {len(FILES)} files and one permission migration.');return
 backup=ROOT/'storage/teacher-portal-backups'/datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d-%H%M%S');backup.mkdir(parents=True)
 token=secrets.token_hex(32);runner='teacher-portal-'+secrets.token_hex(12)+'.php';runner_remote=shared.DOMAIN+'/public_html/'+runner
 ftp=shared.ftp_tools.connect();previous={};uploaded=[];installed=False;before=None;migrated=None
 def reconnect():
  nonlocal ftp
  try:ftp.close()
  except Exception:pass
  ftp=shared.ftp_tools.connect()
 def request(phase,data=None):
  req=urllib.request.Request('https://jsmkj.space/'+runner+'?phase='+phase,data=json.dumps(data).encode() if data is not None else None,headers={'X-Deploy-Key':token,'Content-Type':'application/json'})
  with urllib.request.urlopen(req,timeout=60) as response:result=json.load(response)
  if not result.get('ok'):raise RuntimeError('Rollout verification failed.')
  return result
 try:
  known={}
  for folder in ['holiday-backups','shared-grade-backups','permission-coverage-backups','teacher-portal-backups']:
   for manifest_path in (ROOT/'storage'/folder).glob('*/manifest.json'):
    for path,entry in json.loads(manifest_path.read_text(encoding='utf-8')).items():
     if path in FILES and entry.get('remote')==shared.remote(path):known.setdefault(path,set()).add(entry['sha256'])
  equal=lambda a,b:a is not None and b is not None and a.replace(b'\r\n',b'\n')==b.replace(b'\r\n',b'\n')
  for path in FILES:
   stream=io.BytesIO()
   try:ftp.retrbinary('RETR '+shared.remote(path),stream.write);old=stream.getvalue()
   except ftplib.error_perm as e:
    if not str(e).startswith('550'):raise
    old=None
   source=subprocess.run(['git','show','HEAD:'+path],cwd=ROOT,capture_output=True);prior=source.stdout if source.returncode==0 else None
   if old is None and prior is not None:raise RuntimeError('Expected production file missing: '+path)
   if old is not None and not equal(old,prior) and not equal(old,(ROOT/path).read_bytes()) and hashlib.sha256(old).hexdigest() not in known.get(path,set()):raise RuntimeError('Production drift: '+path)
   previous[path]=old
   if old is not None:saved=backup/path;saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(old)
  (backup/'manifest.json').write_text(json.dumps({p:{'remote':shared.remote(p),'existed':previous[p] is not None,'sha256':hashlib.sha256((ROOT/p).read_bytes()).hexdigest()} for p in FILES},indent=2),encoding='utf-8')
  ftp.storbinary('STOR '+runner_remote,io.BytesIO(RUNNER.replace('DEPLOY_TOKEN',token).encode()));installed=True
  before=request('backup');(backup/'database-before.json').write_text(json.dumps(before),encoding='utf-8');reconnect()
  for path in FILES:
   content=(ROOT/path).read_bytes();remote=shared.remote(path);shared.ftp_tools.mkdirs(ftp,remote.rsplit('/',1)[0]);temporary=remote+'.'+secrets.token_hex(5)+'.tmp'
   ftp.storbinary('STOR '+temporary,io.BytesIO(content));ftp.rename(temporary,remote);uploaded.append(path)
   check=io.BytesIO();ftp.retrbinary('RETR '+remote,check.write)
   if check.getvalue()!=content:raise RuntimeError('Uploaded bytes differ: '+path)
   print('Verified '+path,flush=True)
  migrated=request('migrate',{'expected':before});(backup/'database-migrated.json').write_text(json.dumps(migrated),encoding='utf-8');reconnect()
  result=request('verify');teacher=next(int(r['id']) for r in before['sources']['roles'] if r['name']=='Teacher')
  if result['baseline']!=before['baseline']:raise RuntimeError('Analytics baseline changed.')
  old=grant_codes(before);new=grant_codes(result)
  if {code for role,code in new if role==teacher}!=TEACHER:raise RuntimeError('Teacher baseline differs.')
  expected_other={(role,code) for role,code in old if role!=teacher}
  for role,code in list(expected_other):
   if code in ['manage_attendance','manage_seat_plans']:expected_other.add((role,{'manage_attendance':'correct_class_attendance','manage_seat_plans':'assign_class_seats'}[code]))
  if {(role,code) for role,code in new if role!=teacher}!=expected_other:raise RuntimeError('Unrelated role grants changed.')
  (backup/'verified.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
  print('Teacher baseline, preserved other roles, effective scope, Profile policy and runtime files verified. Private backup: '+str(backup),flush=True)
 except Exception:
  if migrated is not None:
   request('rollback',{'expected':migrated,'before':before});print('Previous role grants restored.',flush=True)
  reconnect()
  for path in reversed(uploaded):
   if previous[path] is not None:ftp.storbinary('STOR '+shared.remote(path),io.BytesIO(previous[path]))
  print('Rollout stopped; previous runtime files restored. Private backup: '+str(backup),flush=True);raise
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
