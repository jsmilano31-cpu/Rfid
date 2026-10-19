"""Analytics calculations, prospective history, scoped API and browser UX in disposable fixtures."""
from pathlib import Path
import json, os, re, subprocess, sys, time, uuid
WEB=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(WEB.parent/'.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect
expect.set_options(timeout=15000)

database='analytics_'+uuid.uuid4().hex;username='analytics_'+uuid.uuid4().hex[:10];password=uuid.uuid4().hex
db=pymysql.connect(host='127.0.0.1',port=3340,user='root',autocommit=True);server=None;base='http://127.0.0.1:8138'
PROBE=r'''
require getcwd().'/src/bootstrap.php';
use App\Core\Database;use App\Services\AnalyticsSchema;use App\Services\AnalyticsWorker;use App\Services\AnalyticsHistory;use App\Services\AuditSchema;
$pdo=Database::connection();function check($x,$m){if(!$x)throw new RuntimeException($m);}
$schema=AnalyticsSchema::install($pdo);$again=AnalyticsSchema::install($pdo);check($schema['baseline_at']===$again['baseline_at'],'Baseline changed on reinstall');
$pdo->exec("UPDATE analytics_state SET baseline_at='2026-09-01 00:00:00'");$pdo->exec("UPDATE analytics_history SET valid_from='2026-09-01 00:00:00' WHERE operation='BASELINE'");
AuditSchema::install($pdo);
$before=(int)$pdo->query('SELECT COUNT(*) FROM analytics_history')->fetchColumn();$pdo->beginTransaction();$pdo->exec('UPDATE class_enrollments SET status="dropped" WHERE class_offering_id=1 AND student_id=5');$pdo->rollBack();check((int)$pdo->query('SELECT COUNT(*) FROM analytics_history')->fetchColumn()===$before,'Rolled back change retained history');
$pdo->exec('UPDATE class_enrollments SET status="dropped" WHERE class_offering_id=1 AND student_id=6');
$pdo->exec("UPDATE analytics_history SET valid_to='2026-09-25 00:00:00' WHERE source_table='class_enrollments' AND record_key=(SELECT id FROM class_enrollments WHERE class_offering_id=1 AND student_id=6) AND operation='BASELINE'");
$pdo->exec("UPDATE analytics_history SET valid_from='2026-09-25 00:00:00' WHERE source_table='class_enrollments' AND record_key=(SELECT id FROM class_enrollments WHERE class_offering_id=1 AND student_id=6) AND operation='UPDATE'");
$pdo->exec('UPDATE grading_settings SET has_prelim=0,has_midterm=1,has_final=1,prelim_weight=0,midterm_weight=40,final_weight=60,passing_grade=75 WHERE id=1');
$pdo->exec("UPDATE grades SET prelim=99,midterm=70,final=80,overall=76,status='finalized',finalized_at='2026-09-24 09:00:00' WHERE class_offering_id=1 AND student_id=5");
$pdo->exec('UPDATE grading_settings SET passing_grade=90 WHERE id=1');
$pdo->exec('UPDATE grades SET midterm=71 WHERE class_offering_id=1 AND student_id=5');
$pdo->exec("INSERT INTO attendance_sessions(class_offering_id,attendance_date,finalized_at,finalized_by) VALUES(1,'2026-09-21','2026-09-21 11:00:00',2) ON DUPLICATE KEY UPDATE finalized_at=VALUES(finalized_at)");
$pdo->exec("DELETE FROM attendance_records WHERE class_offering_id=1 AND attendance_date='2026-09-21'");
$pdo->exec("INSERT INTO attendance_records(class_offering_id,student_id,attendance_date,time_in,status,source,recorded_by) VALUES(1,5,'2026-09-21','08:22:00','Late','manual',2),(1,6,'2026-09-21',NULL,'Excused','manual',2),(1,7,'2026-09-21',NULL,'Absent','manual',2)");
$meeting=(int)$pdo->query("SELECT id FROM class_schedules WHERE class_offering_id=1 AND day_of_week='Monday'")->fetchColumn();
$pdo->exec("UPDATE analytics_history SET valid_to='2026-09-21 10:00:00' WHERE source_table='class_schedules' AND record_key={$meeting} AND operation='BASELINE'");
$pdo->exec("INSERT INTO analytics_history(source_table,record_key,valid_from,operation,payload) SELECT source_table,record_key,'2026-09-21 10:00:00','UPDATE',JSON_SET(payload,'$.start_time','12:00:00','$.end_time','13:00:00') FROM analytics_history WHERE source_table='class_schedules' AND record_key={$meeting} AND operation='BASELINE'");
$build=AnalyticsWorker::run($pdo,true);
$pdo->exec("UPDATE class_schedules SET start_time='09:00:00' WHERE class_offering_id=1");
AnalyticsWorker::run($pdo,true);
check((float)$pdo->query("SELECT late_minutes FROM analytics_attendance WHERE class_offering_id=1 AND student_id=5 AND attendance_date='2026-09-21'")->fetchColumn()===22.0,'Later timetable change rewrote past lateness');
$factCount=(int)$pdo->query('SELECT COUNT(*) FROM analytics_attendance')->fetchColumn();$revision=$pdo->query('SELECT revision FROM analytics_state WHERE id=1')->fetchColumn();
$pdo->exec("CREATE TRIGGER fixture_analytics_failure BEFORE INSERT ON analytics_daily FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated refresh failure'");
try{AnalyticsWorker::run($pdo,true);throw new RuntimeException('Expected isolated refresh failure');}catch(PDOException $expected){}
check((int)$pdo->query('SELECT COUNT(*) FROM analytics_attendance')->fetchColumn()===$factCount && $pdo->query('SELECT revision FROM analytics_state WHERE id=1')->fetchColumn()===$revision,'Failed refresh changed committed facts or revision');
$pdo->exec('DROP TRIGGER fixture_analytics_failure');AnalyticsWorker::run($pdo,true);
$pdo->exec("INSERT INTO events(title,event_date,end_date,start_time,end_time,venue,attendance_required,check_out_required,status,created_by) VALUES('Analytics audience fixture','2026-09-24','2026-09-25','09:00:00','15:00:00','Fixture venue',1,1,'closed',1)");$event=(int)$pdo->lastInsertId();
$pdo->exec('INSERT INTO event_participants(event_id,user_id,is_required) VALUES('.$event.',5,1),('.$event.',6,1),('.$event.',7,1),('.$event.',8,0)');
$pdo->exec("UPDATE analytics_history SET valid_from='2026-09-24 00:00:00' WHERE source_table='events' AND record_key=".$event);
$pdo->exec("UPDATE analytics_history SET valid_from='2026-09-24 00:00:00' WHERE source_table='event_participants' AND JSON_EXTRACT(payload,'$.event_id')=".$event);
$pdo->exec("INSERT INTO event_attendance(event_id,user_id,status,source,check_in_at,check_out_at) VALUES({$event},5,'Attended','manual','2026-09-24 08:50:00','2026-09-25 15:00:00'),({$event},6,'Incomplete','manual','2026-09-24 09:15:00',NULL),({$event},7,'Excused','manual',NULL,NULL),({$event},8,'Attended','manual','2026-09-24 08:55:00','2026-09-24 15:00:00')");
$pdo->exec('DELETE FROM event_participants WHERE event_id='.$event.' AND user_id=6');
$pdo->exec("INSERT INTO events(title,event_date,start_time,end_time,venue,status,created_by) VALUES('Cascade fixture','2026-11-01','09:00:00','10:00:00','Fixture','draft',1)");$cascade=(int)$pdo->lastInsertId();$pdo->exec('INSERT INTO event_participants(event_id,user_id,is_required) VALUES('.$cascade.',5,1)');$cascadeParticipant=(int)$pdo->lastInsertId();$pdo->exec('DELETE FROM events WHERE id='.$cascade);
$cascadeHistory=$pdo->query("SELECT operation FROM analytics_history WHERE source_table='event_participants' AND record_key='{$cascadeParticipant}' ORDER BY id DESC LIMIT 1")->fetchColumn();check($cascadeHistory==='DELETE','Cascaded audience history remained active');
$history=new AnalyticsHistory($pdo);$enrollment=$pdo->query('SELECT id FROM class_enrollments WHERE class_offering_id=1 AND student_id=6')->fetchColumn();check($history->at('class_enrollments',$enrollment,'2026-09-21 08:00:00')['status']==='enrolled','Historical enrollment lost');
$snap=$history->finalizedGrade(1,5,'2026-09-24 09:00:00');check((float)$snap['_policy']['passing_grade']===75.0,'Finalized policy changed with current settings');
$fact=$pdo->query("SELECT * FROM analytics_attendance WHERE class_offering_id=1 AND student_id=5 AND attendance_date='2026-09-21'")->fetch();check($fact['history_known']==1 && $fact['expected']==1 && (float)$fact['late_minutes']===22.0,'Attendance fact or lateness wrong');
$pdo->exec('INSERT INTO messages(sender_id,subject,body) VALUES(1,"Analytics delivery fixture","Private analytics message content")');$message=(int)$pdo->lastInsertId();$pdo->exec('INSERT INTO message_recipients(message_id,user_id) VALUES('.$message.',5)');
\App\Services\MessageService::change(5,[$message],'read');check($pdo->query('SELECT first_opened_at FROM message_recipients WHERE message_id='.$message)->fetchColumn()===null,'Bulk read fabricated an open');
\App\Services\MessageService::message(5,$message);$first=$pdo->query('SELECT first_opened_at FROM message_recipients WHERE message_id='.$message)->fetchColumn();check($first!==null,'Authorized full message open not tracked');\App\Services\MessageService::change(5,[$message],'unread');check($pdo->query('SELECT first_opened_at FROM message_recipients WHERE message_id='.$message)->fetchColumn()===$first,'Unread erased first open');
echo json_encode(['schema'=>$schema,'build'=>$build,'message'=>$message,'event'=>$event],JSON_THROW_ON_ERROR);
'''
try:
 with db.cursor() as c:
  c.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4');c.execute(f'USE `{database}`')
  for source in ['schema.sql','seed.sql']:
   sql=re.sub(r'^\s*--.*$','',(WEB/'database'/source).read_text(encoding='utf-8-sig'),flags=re.M)
   for statement in sql.split(';'):
    if statement.strip():c.execute(statement)
  c.execute("UPDATE class_enrollments SET enrolled_at='2026-08-01 00:00:00'")
  c.execute("INSERT INTO roles(name) VALUES('Audit only')");role=c.lastrowid
  c.execute('INSERT INTO role_permissions(role_id,permission_id) VALUES(%s,22)',(role,))
  c.execute("INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) SELECT 'AUDITONLY','Audit','Only','only@example.test',password_hash,%s FROM users WHERE id=5",(role,));audit_user=c.lastrowid;c.execute('INSERT INTO user_roles(user_id,role_id) VALUES(%s,%s)',(audit_user,role))
  c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'");c.execute(f'GRANT ALL ON `{database}`.* TO \'{username}\'@\'127.0.0.1\'')
 env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='3340',DB_DATABASE=database,DB_USERNAME=username,DB_PASSWORD=password,APP_ENV='testing',APP_DEBUG='true',APP_URL=base)
 probe=subprocess.run(['php','-r',PROBE],cwd=WEB,env=env,capture_output=True,text=True,encoding='utf-8')
 assert probe.returncode==0,probe.stdout+probe.stderr
 fixture=json.loads(probe.stdout);print('Prospective history, immutable grade policies, attendance facts, rollback and first opens passed.',flush=True)
 server=subprocess.Popen(['php','-S','127.0.0.1:8138','-t','public'],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=open(WEB.parent/'.codex-tmp/analytics-server.log','w'),creationflags=subprocess.CREATE_NO_WINDOW);time.sleep(.7)
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True);context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
  def login(identifier):
   context.clear_cookies();page.goto(base+'/auth/login.php');page.locator('[name=identifier]').fill(identifier);page.locator('[name=password]').fill('Password123!');page.locator('button[type=submit]').click();page.wait_for_url(re.compile('/(admin|index)/(\\?|$)'))
   if identifier in ['2026-0001','TCH-001']:assert page.locator('h1').count()>0,'Portal dashboard failed to render'
  def api(section='overview',extra=''):
   response=page.request.get(base+'/api/analytics.php?from=2026-09-01&to=2026-10-02&semester_id=1&section='+section+'&refresh=1&'+extra);assert response.status==200,response.text();payload=response.json();assert payload['ok'];return payload
  login('ADMIN-001')
  with db.cursor() as c:
   c.execute("UPDATE messages SET created_at='2026-08-25 09:00:00' WHERE id=%s",(fixture['message'],))
   c.execute("INSERT INTO messages(sender_id,subject,body,created_at) VALUES(1,'Analytics prospective delivery','Fixture body','2026-09-25 09:00:00')");delivery=c.lastrowid
   c.execute("INSERT INTO message_recipients(message_id,user_id,first_opened_at) VALUES(%s,5,'2026-09-26 09:00:00')",(delivery,))
  for section in ['overview','attendance','grades','activities','compliance','resources','scanners','communications','quality']:api(section)
  activity=next(r for r in api('activities')['panels'][0]['rows'] if r['label']=='Analytics audience fixture');assert activity['completion']==50 and activity['eligible_required']==2 and activity['optional']==1 and activity['incomplete']==1,activity
  assert not api('activities','section_id=2')['panels'][0]['rows'] or all(r['label']!='Analytics audience fixture' for r in api('activities','section_id=2')['panels'][0]['rows'])
  assert page.request.get(base+'/api/analytics.php?from[]=bad').status==422
  a=api('attendance','from=2026-09-21&to=2026-09-21&offering_id=1');assert a['cards'][0]['value']==50,a
  assert any('provisional' in i['title'] for i in api('attendance')['insights'])
  communication=api('communications','from=2026-08-01');legacy=next(r for r in communication['panels'][0]['rows'] if r['label']=='Analytics delivery fixture');assert legacy['delay_minutes'] is None and legacy['history']=='Legacy first-open history unknown' and int(legacy['opened'])==0 and legacy['reach'] is None,legacy
  tracked=next(r for r in communication['panels'][0]['rows'] if r['label']=='Analytics prospective delivery');assert tracked['reach']==100 and tracked['delay_minutes']==1440 and communication['cards'][0]['value']==100,tracked
  g=api('grades');row=next(r for r in g['panels'][0]['rows'] if r['offering_id']==1);assert row['passing']==100 and row['paired_change']==9,row;assert row['prelim'] is None # Disabled Prelims excluded from newly captured grading policy.
  records=api('attendance','mode=records&limit=2');assert len(records['rows'])==2 and records['has_more'];next_records=api('attendance','mode=records&limit=2&record_page=2');assert records['rows']!=next_records['rows']
  export=page.request.get(base+'/api/analytics.php?section=attendance&mode=export&from=2026-09-01&to=2026-10-02');assert export.status==200 and 'text/csv' in export.headers['content-type']
  assert page.request.post(base+'/api/analytics.php').status==405;assert page.request.get(base+'/api/analytics.php?from=bad').status==422
  page.goto(base+'/admin/?page=analytics');expect(page.locator('[data-analytics-results]')).to_have_attribute('aria-busy','false');expect(page.locator('[data-analytics-panels] .analytics-panel').first).to_be_visible();assert not page.locator('.analytics-error').count(),page.locator('[data-analytics-status]').inner_text()
  page.locator('[data-analytics-tab=attendance]').click();expect(page.locator('[data-analytics-tab=attendance]')).to_have_attribute('aria-current','page');page.locator('.analytics-inspect').first.click();expect(page.locator('[data-analytics-drawer]')).to_be_visible();expect(page.locator('[data-analytics-records] tbody tr').first).to_be_visible();page.keyboard.press('Escape');expect(page.locator('[data-analytics-drawer]')).not_to_be_visible()
  for theme in ['dark','light']:
   for accent in ['blue','green','purple','orange']:
    page.evaluate('([theme,accent])=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.accent=accent}',[theme,accent]);page.wait_for_timeout(350);assert page.locator('.analytics-chart svg').count()>0, {'errors':errors,'charts':page.locator('.analytics-chart').all_text_contents(),'engine':page.evaluate('typeof window.CampusCharts'),'status':page.locator('[data-analytics-status]').inner_text()}
   page.set_viewport_size({'width':390,'height':844});page.wait_for_function('document.documentElement.scrollWidth<=innerWidth+1',timeout=3000);assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1');page.locator('.analytics-inspect').first.click();assert page.locator('[data-analytics-drawer]').evaluate('e=>e.scrollWidth<=e.clientWidth+1');page.keyboard.press('Escape')
  page.set_viewport_size({'width':1440,'height':1000});page.screenshot(path=str(WEB.parent/'.codex-tmp/analytics-desktop.png'),full_page=True)
  page.goto(base+'/admin/?page=analytics&section=attendance&from=2026-08-01&to=2026-08-02');expect(page.locator('[data-analytics-results]')).to_have_attribute('aria-busy','false');expect(page.locator('.analytics-empty').first).to_be_visible()
  token=page.locator('input[name=_token]').first.input_value();page.request.post(base+'/auth/view-as.php',form={'_token':token,'role_id':'3'});preview=api();assert 'scanners' not in preview['sections'];assert page.request.get(base+'/api/analytics.php?section=scanners').status==403
  login('TCH-001');teacher=api();assert 'scanners' not in teacher['sections'];assert page.request.get(base+'/api/analytics.php?section=grades&offering_id=2').status==403
  teacher_records=api('attendance','mode=records&limit=100');assert all(r['class_offering_id'] in [1,3] for r in teacher_records['rows'])
  with db.cursor() as c:c.execute('DELETE FROM teacher_assignments WHERE teacher_id=2 AND class_offering_id=1')
  revoked=api('attendance','mode=records&limit=100');assert all(r['class_offering_id']!=1 for r in revoked['rows']);assert page.request.get(base+'/api/analytics.php?section=attendance&offering_id=1').status==403
  with db.cursor() as c:c.execute('INSERT INTO teacher_assignments(class_offering_id,teacher_id) VALUES(1,2)')
  with db.cursor() as c:
   c.execute('INSERT INTO user_roles(user_id,role_id) VALUES(2,3)');c.execute('INSERT INTO class_enrollments(class_offering_id,student_id) VALUES(4,2)');c.execute("INSERT INTO grades(class_offering_id,student_id,midterm,final,overall,status,entered_by,finalized_at) VALUES(4,2,80,85,83,'finalized',4,'2026-09-24 09:00:00')")
  login('TCH-001');mixed=api('grades','mode=records&limit=100');assert all(r['class_offering_id'] in [1,3] or r['student_id']==2 for r in mixed['rows']);assert any(r['class_offering_id']==4 and r['student_id']==2 for r in mixed['rows'])
  login('2026-0001');student=api();assert 'communications' not in student['sections'];student_records=api('attendance','mode=records&limit=100');assert all(r['student_id']==5 for r in student_records['rows']);assert page.request.get(base+'/api/analytics.php?section=scanners').status==403
  assert all(r['enrolled']<=1 and r['seated']<=1 for r in api('resources')['panels'][1]['rows'])
  login('AUDITONLY');assert page.request.get(base+'/api/analytics.php').status==403;assert page.request.get(base+'/admin/?page=analytics').status==403
  login('STF-001');staff=api();assert 'attendance' in staff['sections'] and 'grades' not in staff['sections'] and 'scanners' not in staff['sections']
  login('EVT-001');coordinator=api();assert 'activities' in coordinator['sections'] and 'grades' not in coordinator['sections'];assert any(r['label']=='Analytics audience fixture' for r in api('activities')['panels'][0]['rows'])
  login('ADMIN-001')
  fixture_entries=100000
  with db.cursor() as c:
   c.execute('START TRANSACTION')
   for offset in range(0,fixture_entries,1000):c.executemany("INSERT INTO analytics_attendance(class_offering_id,student_id,attendance_date,semester_id,department_id,program_id,section_id,course_id,status,finalized,expected,history_known) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",[(1,100000+i,'2026-09-22',1,1,1,1,1,'Present',1,1,1) for i in range(offset,offset+1000)])
   c.execute('COMMIT')
   c.execute('EXPLAIN SELECT COUNT(*) FROM analytics_attendance WHERE student_id=5 AND attendance_date BETWEEN \'2026-09-01\' AND \'2026-10-02\'');plan=c.fetchall();assert any('student' in str(row[5]) for row in plan),plan
  build=subprocess.run(['php','-r',"require getcwd().'/src/bootstrap.php';\App\Services\AnalyticsWorker::summarize(\App\Core\Database::connection(),'2026-09-22');"],cwd=WEB,env=env,capture_output=True,text=True);assert build.returncode==0,build.stdout+build.stderr
  started=time.perf_counter();api('attendance');cold=round((time.perf_counter()-started)*1000,1);assert cold<2000,cold
  started=time.perf_counter();response=page.request.get(base+'/api/analytics.php?section=attendance&from=2026-09-01&to=2026-10-02&semester_id=1');warm=round((time.perf_counter()-started)*1000,1);assert response.status==200 and warm<500,(response.text(),warm)
  started=time.perf_counter();api('attendance','from=2024-01-01&semester_id=0');multi_year=round((time.perf_counter()-started)*1000,1);assert multi_year<2000,multi_year
  print(f'Multi-year filtered summary: {multi_year} ms.',flush=True)
  print(f'{fixture_entries:,}-observation benchmark: uncached {cold} ms; cached {warm} ms. Indexed student/date lookup verified.',flush=True)
  context.clear_cookies();assert page.request.get(base+'/api/analytics.php').status==401;assert not errors,errors;browser.close();print('All domains, calculations, API pagination/export, role boundaries, View As, keyboard, mobile themes and expiry passed.',flush=True)
finally:
 if server:server.terminate();server.wait(timeout=5)
 with db.cursor() as c:c.execute(f'DROP DATABASE IF EXISTS `{database}`');c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
 db.close()
