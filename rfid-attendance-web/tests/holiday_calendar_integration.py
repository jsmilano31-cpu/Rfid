"""Philippine holidays and campus decisions in an isolated database; never production mutations."""
from pathlib import Path
import json, os, re, subprocess, sys, time, uuid
WEB=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(WEB.parent/'.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect
expect.set_options(timeout=20000)
database='holiday_'+uuid.uuid4().hex;username='holiday_'+uuid.uuid4().hex[:10];password=uuid.uuid4().hex
db=pymysql.connect(host='127.0.0.1',port=3340,user='root',autocommit=True);server=None;base='http://127.0.0.1:8142'
PROBE=r'''
require getcwd().'/src/bootstrap.php';
use App\Core\Database;use App\Services\AnalyticsSchema;use App\Services\AuditSchema;use App\Services\PhilippineHolidayService as H;use App\Services\AcademicCalendarService as C;
$pdo=Database::connection();$pdo->exec('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.code="manage_academic_calendar" AND rp.role_id=1');AnalyticsSchema::install($pdo);AuditSchema::install($pdo);$_SESSION['user_id']=1;
function check($value,$message){if(!$value)throw new RuntimeException($message);}
check(H::canManage(),'Administrator calendar permission was not restored');
$rows=H::normalize([['event'=>'One','date'=>'2026-11-01'],['event'=>'One','date'=>'2026-11-01'],['event'=>'Bad','date'=>'2026-02-30'],['event'=>'Other year','date'=>'2025-01-01']],2026);
check(count($rows)===1 && $rows[0]['type']==='unclassified','Invalid date/dedup/classification validation failed');
$feed=H::feed(2026);$working=array_filter($feed['holidays'],fn($x)=>$x['type']==='special_working');check(count($working)>0,'Working holidays missing');
$holiday=array_values(array_filter($feed['holidays'],fn($x)=>$x['date']==='2026-12-24'))[0];
$pdo->exec("INSERT INTO class_schedules(class_offering_id,day_of_week,start_time,end_time) VALUES(1,'Thursday','09:00:00','10:00:00')");
H::save(['date'=>$holiday['date'],'holiday_key'=>$holiday['key'],'no_classes'=>'1'],1);
try{C::scheduleForOfferingDate(1,'2026-12-24');throw new RuntimeException('No-class schedule accepted');}catch(DomainException $e){check(str_contains($e->getMessage(),'No classes'),'Wrong no-class explanation');}
check((int)$pdo->query("SELECT COUNT(*) FROM analytics_history WHERE source_table='campus_holidays'")->fetchColumn()===1,'Prospective holiday history missing');
check((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='campus_holidays'")->fetchColumn()>0,'Holiday row not audited');
try{App\Services\RfidDeviceService::scheduleStatus(['room_id'=>1,'room_status'=>'active'],'2026-12-24','09:15:00');throw new RuntimeException('Scanner reports active holiday classes');}catch(App\Exceptions\ScanRejection $e){check(str_contains($e->getMessage(),'No classes'),'Scanner holiday rejection missing');}
H::save(['date'=>$holiday['date'],'holiday_key'=>$holiday['key'],'no_classes'=>'0'],1);C::scheduleForOfferingDate(1,'2026-12-24');
$pdo->exec("CREATE TRIGGER holiday_fixture_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
try{H::save(['date'=>$holiday['date'],'holiday_key'=>$holiday['key'],'no_classes'=>'1'],1);throw new RuntimeException('Failed audit accepted');}catch(PDOException $expected){}
check((int)$pdo->query("SELECT no_classes FROM campus_holidays WHERE holiday_date='2026-12-24'")->fetchColumn()===0,'Failed save changed calendar');$pdo->exec('DROP TRIGGER holiday_fixture_failure');
try{H::save(['date'=>'2026-01-01','holiday_key'=>'bad','no_classes'=>'1'],1);throw new RuntimeException('Past mutation accepted');}catch(DomainException $e){}
try{H::save(['date'=>'2026-12-24','holiday_key'=>'tampered','no_classes'=>'1'],1);throw new RuntimeException('Tampered holiday accepted');}catch(DomainException $e){}
echo json_encode(['key'=>$holiday['key']]);
'''
try:
 with db.cursor() as c:
  c.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4');c.execute(f'USE `{database}`')
  for source in ['schema.sql','seed.sql']:
   sql=re.sub(r'^\s*--.*$','',(WEB/'database'/source).read_text(encoding='utf-8-sig'),flags=re.M)
   for statement in sql.split(';'):
    if statement.strip():c.execute(statement)
  c.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
  c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'");c.execute(f'GRANT ALL ON `{database}`.* TO \'{username}\'@\'127.0.0.1\'')
  c.execute("INSERT INTO roles(name) VALUES('Calendar manager')");role=c.lastrowid
  c.execute('INSERT INTO role_permissions(role_id,permission_id) VALUES(%s,20),(%s,18)',(role,role))
  c.execute("INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) SELECT 'CALENDAR','Calendar','Manager','calendar@example.test',password_hash,%s FROM users WHERE id=5",(role,));actor=c.lastrowid;c.execute('INSERT INTO user_roles(user_id,role_id) VALUES(%s,%s)',(actor,role))
 env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='3340',DB_DATABASE=database,DB_USERNAME=username,DB_PASSWORD=password,APP_ENV='testing',APP_DEBUG='true',APP_URL=base,PHILIPPINE_HOLIDAY_API_URL='disabled-in-fixture')
 result=subprocess.run(['php','-r',PROBE],cwd=WEB,env=env,capture_output=True,text=True,encoding='utf-8');assert result.returncode==0,result.stdout+result.stderr
 fixture=json.loads(result.stdout);print('Feed normalization, working holidays, campus decisions, attendance protection, audit/history and rollback passed.',flush=True)
 server=subprocess.Popen(['php','-S','127.0.0.1:8142','-t','public'],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=open(WEB.parent/'.codex-tmp/holiday-server.log','w'),creationflags=subprocess.CREATE_NO_WINDOW);time.sleep(.7)
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True);context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
  def login(identifier):
   context.clear_cookies();page.goto(base+'/auth/login.php');page.locator('[name=identifier]').fill(identifier);page.locator('[name=password]').fill('Password123!');page.locator('button[type=submit]').click();page.wait_for_url(re.compile('/(admin|index)/(\\?|$)'))
  assert page.request.get(base+'/api/holidays.php?year=2026').status==401
  login('ADMIN-001');response=page.request.get(base+'/api/holidays.php?year=2026');assert response.status==200,response.text();assert response.headers['cache-control']=='no-store';data=response.json();assert len(data['events'])==21 and data['can_manage'],data
  assert page.request.get(base+'/api/holidays.php?year=1999').status==422
  assert page.request.post(base+'/api/holidays.php',form={'date':'2026-12-24'}).status==419
  page.wait_for_function('Boolean(window.CampusHolidays)')
  # Exercise the real calendar entry, including keyboard activation, rather than only the dialog helper.
  page.locator('[data-calendar-next]').click();holiday_item=page.locator('[data-calendar-kind=holiday]').filter(has_text="All Saints").first
  expect(holiday_item).to_be_visible();holiday_item.focus();holiday_item.press('Enter');expect(page.locator('#campus-holiday-dialog')).to_be_visible();page.locator('#campus-holiday-dialog').press('Escape')
  # Opening the same feed entry used by the calendar never navigates externally.
  entry=next(x for x in data['events'] if x['customTags']['date']=='2026-12-24');original_url=page.url
  for theme,width in [('dark',1440),('light',1440),('dark',390)]:
   page.set_viewport_size({'width':width,'height':900});page.evaluate('(t)=>document.documentElement.dataset.theme=t',theme);page.evaluate('(entry)=>CampusHolidays.open(entry)',entry)
   expect(page.locator('#campus-holiday-dialog')).to_be_visible();expect(page.locator('[data-holiday-save]')).to_be_visible();assert page.url==original_url
   assert page.evaluate('document.querySelector("#campus-holiday-dialog").getBoundingClientRect().width <= innerWidth')
   page.locator('#campus-holiday-dialog').press('Escape');expect(page.locator('#campus-holiday-dialog')).not_to_be_visible()
  page.evaluate('(entry)=>CampusHolidays.open(entry)',entry);page.locator('[data-holiday-choice]').click();page.locator('[data-holiday-save]').click();expect(page.locator('#campus-holiday-dialog')).not_to_be_visible()
  after=page.request.get(base+'/api/holidays.php?year=2026').json();assert next(x for x in after['events'] if x['customTags']['date']=='2026-12-24')['customTags']['noClasses']
  assert page.url==original_url,'Form submission navigated'
  token=page.locator('input[name=_token]').first.input_value();preview=page.request.post(base+'/auth/view-as.php',form={'_token':token,'role_id':'3'});assert preview.status in (200,302,303)
  student=page.request.get(base+'/api/holidays.php?year=2026').json();assert not student['can_manage'];assert page.request.post(base+'/api/holidays.php',form={'_token':token,'date':'2026-12-24','holiday_key':fixture['key'],'no_classes':'0'}).status==403
  restricted=next(x for x in student['events'] if x['customTags']['date']=='2026-12-24');page.evaluate('(entry)=>CampusHolidays.open(entry)',restricted);expect(page.locator('[data-holiday-save]')).not_to_be_visible();expect(page.locator('[data-holiday-access]')).to_contain_text('calendar manager');page.locator('#campus-holiday-dialog').press('Escape')
  page.goto(base+'/index/?page=attendance');page.wait_for_function('Boolean(window.CampusHolidays)');assert page.request.get(base+'/api/holidays.php?year=2027').json()['notice']
  login('CALENDAR');assert page.request.get(base+'/api/holidays.php?year=2026').json()['can_manage'],'Custom academic-calendar grant not honored'
  login('2026-0001');page.goto(base+'/index/?page=attendance&offering_id=1&view=personal');expect(page.locator('[data-student-attendance-calendar]')).to_be_visible()
  page.locator('[data-student-calendar-next]').click();personal_holiday=page.locator('[data-student-attendance-calendar] .event').filter(has_text='All Saints').first;expect(personal_holiday).to_be_visible();personal_holiday.click();expect(page.locator('#campus-holiday-dialog')).to_be_visible();expect(page.locator('[data-holiday-save]')).not_to_be_visible();page.locator('#campus-holiday-dialog').press('Escape')
  assert not errors,errors
  browser.close();print('Authenticated/read-only/View As, CSRF, no redirects, both themes, mobile and dialog keyboard checks passed.',flush=True)
finally:
 if server:server.terminate();server.wait(timeout=5)
 with db.cursor() as c:c.execute(f'DROP DATABASE IF EXISTS `{database}`');c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
 db.close()
