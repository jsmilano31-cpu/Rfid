"""Audit capture, cascades, access controls, indexed queries, and browser UX in isolated fixtures."""
from pathlib import Path
import json, os, re, subprocess, sys, time, uuid
WEB=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(WEB.parent/'.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect
database='audit_'+uuid.uuid4().hex;username='audit_'+uuid.uuid4().hex[:12];password=uuid.uuid4().hex
fixture_entries=int(os.environ.get('AUDIT_TEST_ENTRIES','20000'))
db=pymysql.connect(host='127.0.0.1',port=3340,user='root',autocommit=True);server=None;base='http://127.0.0.1:8134'
PROBE=r'''
require getcwd().'/src/bootstrap.php';
use App\Core\Database;use App\Core\AuditContext;use App\Services\AuditSchema;use App\Services\AuditTrailService;use App\Services\AuditService;
$pdo=Database::connection();
function check($value,$message){if(!$value)throw new RuntimeException($message);}
$first=AuditSchema::install($pdo);$second=AuditSchema::install($pdo);check($second['installed_triggers']===0,'Installation is not idempotent');
$_SESSION['user_id']=1;AuditContext::configure($pdo);
$pdo->exec('UPDATE courses SET title="Audit Needle Course" WHERE id=1');
$row=$pdo->query('SELECT * FROM audit_logs WHERE entity_type="courses" AND source="database" ORDER BY id DESC LIMIT 1')->fetch();
check($row['actor_name']==='System Administrator' && $row['user_id']==1,'Actor context missing');
check(json_decode($row['after_values'],true)['title']==='Audit Needle Course','After value missing');
check(json_decode($row['before_values'],true)['title']!=='Audit Needle Course','Before value missing');
$count=$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();$pdo->exec('UPDATE courses SET title=title WHERE id=1');check($pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()==$count,'No-op generated a log');
$pdo->beginTransaction();$pdo->exec('UPDATE courses SET title="Rolled back" WHERE id=1');$pdo->rollBack();check($pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()==$count,'Rollback retained a phantom change');
$pdo->exec('UPDATE users SET password_hash="sensitive-password-fixture" WHERE id=22');
$entry=$pdo->query('SELECT * FROM audit_logs WHERE entity_type="users" ORDER BY id DESC LIMIT 1')->fetch();
check(!str_contains(json_encode($entry),'sensitive-password-fixture'),'Credential leaked');
$detail=AuditTrailService::detail((int)$entry['id']);check(in_array('password_hash',array_column($detail['entry']['changes'],'field'),true),'Redacted field change disappeared');
$pdo->exec('INSERT INTO audit_fixture_parent VALUES(1,"Parent"),(2,"Blocked")');$pdo->exec('INSERT INTO audit_fixture_child VALUES(1,1,"Child")');$pdo->exec('INSERT INTO audit_fixture_grandchild VALUES(1,1,"Grandchild")');$pdo->exec('INSERT INTO audit_fixture_unlink VALUES(1,1,"Linked")');
$pdo->exec('DELETE FROM audit_fixture_parent WHERE id=1');
foreach (['audit_fixture_parent','audit_fixture_child','audit_fixture_grandchild'] as $table) check($pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='{$table}' AND operation='DELETE'")->fetchColumn()==1,'Cascade not captured: '.$table);
check($pdo->query('SELECT parent_id FROM audit_fixture_unlink WHERE id=1')->fetchColumn()===null,'Unlink not applied');
$entry=$pdo->query('SELECT * FROM audit_logs WHERE entity_type="audit_fixture_unlink" AND operation="UPDATE" ORDER BY id DESC LIMIT 1')->fetch();check(json_decode($entry['before_values'],true)['parent_id']==1 && json_decode($entry['after_values'],true)['parent_id']===null,'Unlink snapshot wrong');
$pdo->exec('INSERT INTO audit_fixture_restrict VALUES(1,2)');$count=$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
try {$pdo->exec('DELETE FROM audit_fixture_parent WHERE id=2');throw new RuntimeException('Restrictive delete succeeded');}catch(PDOException $expected){}
check($pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn()==$count,'Failed deletion retained logs');
$oldActor=$row['actor_name'];$pdo->exec('UPDATE users SET first_name="Renamed" WHERE id=1');check($pdo->query('SELECT actor_name FROM audit_logs WHERE id='.$row['id'])->fetchColumn()===$oldActor,'Historical actor name changed');
$pdo->exec('INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) VALUES("DELETED-ACTOR","Temporary","Actor","deleted@example.test","redacted-fixture",4)');$deletedActor=(int)$pdo->lastInsertId();AuditContext::configure($pdo,$deletedActor);
$pdo->exec('UPDATE courses SET title="Deleted actor fixture" WHERE id=2');$actorLog=(int)$pdo->query('SELECT MAX(id) FROM audit_logs')->fetchColumn();$pdo->exec('DELETE FROM users WHERE id='.$deletedActor);
$saved=$pdo->query('SELECT user_id,actor_id,actor_name FROM audit_logs WHERE id='.$actorLog)->fetch();check($saved['user_id']===null && (int)$saved['actor_id']===$deletedActor && $saved['actor_name']==='Temporary Actor','Deleted actor identity lost');AuditContext::configure($pdo,1);
AuditService::log(1,'audit_fixture_action','fixture',1,'Indexed fixture summary');
$pdo->exec('INSERT INTO messages(sender_id,subject,body,body_html) VALUES(1,"PrivateSubjectFixtureSecret","PrivateBodyFixtureSecret","<p>PrivateHtmlFixtureSecret</p>")');
$private=$pdo->query('SELECT * FROM audit_logs WHERE entity_type="messages" ORDER BY id DESC LIMIT 1')->fetch();check(!str_contains(json_encode($private),'PrivateSubjectFixtureSecret') && !str_contains(json_encode($private),'PrivateBodyFixtureSecret'),'Private message content leaked');
$found=AuditTrailService::listing(['q'=>'Audit Needle']);check(count($found['items'])>0,'Full-text lookup missed changed values');
$found=AuditTrailService::listing(['q'=>'ADMIN-001']);check(count($found['items'])>0,'Exact school ID lookup failed');
check(!AuditTrailService::listing(['q'=>'PrivateSubjectFixtureSecret'])['items'],'Private content was searchable');
$device=$pdo->query('SELECT * FROM devices WHERE id=1')->fetch();$pdo->prepare('UPDATE devices SET api_key_hash=? WHERE id=1')->execute([password_hash('isolated-audit-scanner-key',PASSWORD_DEFAULT)]);
\App\Services\RfidDeviceService::authenticate($device['device_code'],'isolated-audit-scanner-key');
$scanner=$pdo->query('SELECT actor_name,actor_id,actor_role FROM audit_logs WHERE entity_type="devices" ORDER BY id DESC LIMIT 1')->fetch();check($scanner['actor_role']==='Scanner' && $scanner['actor_id']===null,'Scanner identity missing');AuditContext::configure($pdo,1);
\App\Services\RfidDeviceService::scheduleStatus(['room_id'=>1,'room_status'=>'active'],'2026-09-07','08:15:00');
echo json_encode(['coverage'=>$first,'detail_id'=>$row['id']],JSON_THROW_ON_ERROR);
'''
try:
 with db.cursor() as c:
  c.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4');c.execute(f'USE `{database}`')
  for source in ['schema.sql','seed.sql']:
   sql=re.sub(r'^\s*--.*$','',(WEB/'database'/source).read_text(encoding='utf-8-sig'),flags=re.M)
   for statement in sql.split(';'):
    if statement.strip():c.execute(statement)
  c.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
  for statement in [
   'CREATE TABLE audit_fixture_parent(id INT PRIMARY KEY,name VARCHAR(100)) ENGINE=InnoDB',
   'CREATE TABLE audit_fixture_child(id INT PRIMARY KEY,parent_id INT,name VARCHAR(100),FOREIGN KEY(parent_id) REFERENCES audit_fixture_parent(id) ON DELETE CASCADE) ENGINE=InnoDB',
   'CREATE TABLE audit_fixture_grandchild(id INT PRIMARY KEY,child_id INT,name VARCHAR(100),FOREIGN KEY(child_id) REFERENCES audit_fixture_child(id) ON DELETE CASCADE) ENGINE=InnoDB',
   'CREATE TABLE audit_fixture_unlink(id INT PRIMARY KEY,parent_id INT NULL,name VARCHAR(100),FOREIGN KEY(parent_id) REFERENCES audit_fixture_parent(id) ON DELETE SET NULL) ENGINE=InnoDB',
   'CREATE TABLE audit_fixture_restrict(id INT PRIMARY KEY,parent_id INT,FOREIGN KEY(parent_id) REFERENCES audit_fixture_parent(id)) ENGINE=InnoDB',
  ]:c.execute(statement)
  c.execute("INSERT INTO roles(name) VALUES('Audit reviewer')");reviewer=c.lastrowid
  c.execute("INSERT INTO role_permissions(role_id,permission_id) SELECT %s,id FROM permissions WHERE code='view_audit_logs'",(reviewer,))
  c.execute("INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) SELECT 'AUDITOR','Audit','Reviewer','audit@example.test',password_hash,%s FROM users WHERE id=5",(reviewer,));c.execute('INSERT INTO user_roles(user_id,role_id) VALUES(%s,%s)',(c.lastrowid,reviewer))
  c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'");c.execute(f"GRANT ALL ON `{database}`.* TO '{username}'@'127.0.0.1'")
 env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='3340',DB_DATABASE=database,DB_USERNAME=username,DB_PASSWORD=password,APP_ENV='testing',APP_DEBUG='true',APP_URL=base)
 probe=subprocess.run(['php','-r',PROBE],cwd=WEB,env=env,capture_output=True,text=True)
 assert probe.returncode==0,probe.stdout+probe.stderr
 data=json.loads(probe.stdout);print('Transactional capture, identity snapshots, redaction, no-ops, cascades, and full-text searches passed.',flush=True)
 with db.cursor() as c:
  # Scale pagination and indexed queries without touching application records.
  for offset in range(0,fixture_entries,1000):
   c.executemany("INSERT INTO audit_logs(user_id,actor_id,action,entity_type,entity_id,details,actor_name,actor_school_id,source,operation,search_text,created_at) VALUES(1,1,'db_update','fixture',%s,'Fixture edit','System Administrator','ADMIN-001','database','UPDATE',%s,'2026-09-20 08:00:00')",[(str(i),'Fixture indexed entry '+str(i)) for i in range(offset,offset+1000)])
  c.executemany("INSERT INTO audit_logs(action,entity_type,entity_id,actor_name,search_text) VALUES('legacy_fixture','nullable_fixture',%s,%s,'Nullable pagination fixture')",[(None,None)]*30+[('A','Actor')]*40)
  c.execute('EXPLAIN SELECT id FROM audit_logs WHERE entity_type="fixture" ORDER BY id DESC LIMIT 51');plan=c.fetchall();assert any(row[5] and 'audit_entity' in row[5] for row in plan),plan
  c.execute('EXPLAIN SELECT id FROM audit_logs WHERE MATCH(search_text) AGAINST("+Needle*" IN BOOLEAN MODE)');plan=c.fetchall();assert any(row[3]=='fulltext' for row in plan),plan
 server=subprocess.Popen(['php','-S','127.0.0.1:8134','-t','public'],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW);time.sleep(.8)
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
  context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
  def login(identifier):
   context.clear_cookies();page.goto(base+'/auth/login.php');page.locator('[name=identifier]').fill(identifier);page.locator('[name=password]').fill('Password123!');page.locator('button[type=submit]').click();page.wait_for_url(re.compile('/(admin|index)/$'))
  def listing(query=''):
   response=page.request.get(base+'/admin/audit-trail.php?'+query);assert response.status==200,response.text();payload=response.json();assert payload['ok'];return payload
  login('ADMIN-001');page.goto(base+'/admin/?page=audit-trail');expect(page.locator('[data-audit-results]')).to_have_attribute('aria-busy','false');expect(page.locator('[data-audit-rows] tr').first).to_be_visible()
  assert page.get_by_role('link',name='Audit Trail',exact=True).count()==1
  expect(page.locator('.compliance-tabs')).to_have_count(0)
  page.locator('[name=q]').fill('Audit Needle');expect(page.locator('[data-audit-open]').first).to_have_attribute('data-audit-open',str(data['detail_id']));expect(page.locator('[data-audit-rows]')).to_contain_text('Courses')
  page.locator('[data-audit-open]').first.click();dialog=page.locator('[data-audit-dialog]');expect(dialog).to_be_visible();expect(dialog.locator('[data-audit-detail-body]')).to_contain_text('Audit Needle Course');page.keyboard.press('Escape');expect(dialog).not_to_be_visible()
  page.goto(base+'/admin/?page=audit-trail&entry='+str(data['detail_id']));expect(dialog).to_be_visible();expect(dialog.locator('[data-audit-detail-body]')).to_contain_text('Recorded changes');page.keyboard.press('Escape')
  page.locator('[data-audit-sort=actor]').focus();page.keyboard.press('Enter');expect(page.locator('[data-audit-sort=actor]').locator('..')).to_have_attribute('aria-sort','ascending')
  first=listing('entity=fixture&source=database&limit=25');assert len(first['items'])==25 and first['next'];second=listing('entity=fixture&source=database&limit=25&cursor='+first['next']);assert not set(row['id'] for row in first['items']) & set(row['id'] for row in second['items']);back=listing('entity=fixture&source=database&limit=25&cursor='+second['previous']);assert [row['id'] for row in back['items']]==[row['id'] for row in first['items']]
  for sort in ['actor','record']:
   for direction in ['asc','desc']:
    query=f'entity=nullable_fixture&sort={sort}&direction={direction}&limit=25';chunk=listing(query);ids=[]
    while True:
     ids.extend(row['id'] for row in chunk['items'])
     if not chunk['next']:break
     previous_ids=[row['id'] for row in chunk['items']];chunk=listing(query+'&cursor='+chunk['next']);previous=listing(query+'&cursor='+chunk['previous']);assert previous_ids==[row['id'] for row in previous['items']]
    assert len(ids)==70 and len(set(ids))==70,(sort,direction,ids)
  assert not listing('entity=fixture&source=database&from=2026-10-01')['items']
  assert listing('entity=fixture&from=2026-09-20&to=2026-09-20&operation=UPDATE')['items']
  assert page.request.get(base+'/admin/audit-trail.php?from=2026-10-03&to=2026-01-01').status==422
  assert page.request.get(base+'/admin/audit-trail.php?id=999999999').status==404
  assert page.request.post(base+'/admin/audit-trail.php').status==405
  assert listing('sort=unsafeSQL&direction=invalid')['filters']['sort']=='time'
  timings=[]
  for query in ['entity=fixture&limit=50','q=Needle','actor=1&source=database','from=2026-09-20&to=2026-09-20']:
   started=time.perf_counter();listing(query);timings.append(round((time.perf_counter()-started)*1000,1))
  assert max(timings)<1500,timings
  print(f'Keyset pagination including null legacy values, sorting, dates, validation, and indexed {fixture_entries:,}-entry queries passed: '+str(timings)+' ms.',flush=True)
  for theme in ['dark','light']:
   page.set_viewport_size({'width':390,'height':844});page.goto(base+'/admin/?page=audit-trail');page.evaluate('(theme)=>document.documentElement.dataset.theme=theme',theme);expect(page.locator('[data-audit-results]')).to_have_attribute('aria-busy','false');page.wait_for_timeout(350)
   if not page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'):
    page.screenshot(path=str(WEB.parent/'.codex-tmp/audit-trail-mobile-debug.png'),full_page=True)
    print(page.evaluate('({viewport:innerWidth,scrollX,document:document.documentElement.scrollWidth,containers:Array.from(document.querySelectorAll(".app-main,.app-content,.audit-workspace,.audit-history,.audit-history .table-wrap")).map(el=>({class:el.className,width:el.clientWidth,scroll:el.scrollWidth,overflow:getComputedStyle(el).overflow,position:getComputedStyle(el).position})),elements:Array.from(document.querySelectorAll("body *")).filter(el=>el.getBoundingClientRect().right>innerWidth && !el.closest("table")).slice(0,20).map(el=>({tag:el.tagName,class:String(el.className),width:el.getBoundingClientRect().width,right:el.getBoundingClientRect().right}))})'),flush=True)
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1');page.locator('[data-audit-open]').first.click();expect(dialog).to_be_visible();assert dialog.evaluate('el=>el.scrollWidth<=el.clientWidth+1');page.keyboard.press('Escape')
  page.set_viewport_size({'width':1440,'height':1000});page.goto(base+'/admin/?page=audit-trail');expect(page.locator('[data-audit-results]')).to_have_attribute('aria-busy','false');page.screenshot(path=str(WEB.parent/'.codex-tmp/audit-trail-desktop.png'),full_page=True)
  page.goto(base+'/admin/?page=compliance&tab=audit&audit_q=Needle');page.wait_for_url(re.compile('page=audit-trail'));expect(page.get_by_role('heading',name='Audit Trail',exact=True)).to_be_visible()
  token=page.locator('input[name=_token]').first.input_value()
  page.request.post(base+'/auth/view-as.php',form={'_token':token,'role_id':'3'});assert page.request.get(base+'/admin/audit-trail.php').status==403
  assert 'audit-trail' not in str(page.request.get(base+'/api/search.php?q=audit&category=pages').json())
  for identifier in ['TCH-001','2026-0001']:
   login(identifier);assert page.request.get(base+'/admin/audit-trail.php').status==403;assert page.request.get(base+'/admin/?page=audit-trail').status==403
  login('AUDITOR');page.goto(base+'/admin/?page=audit-trail');expect(page.locator('[data-audit-results]')).to_have_attribute('aria-busy','false');assert page.request.get(base+'/admin/?page=users').status==403
  response=page.request.get(base+'/admin/?page=compliance');assert 'page=audit-trail' in response.url
  context.clear_cookies();assert page.request.get(base+'/admin/audit-trail.php').status==401
  assert not errors,errors;browser.close();print('Role access, View As, legacy links, details, keyboard, mobile themes, and session expiry passed.',flush=True)
finally:
 if server:server.terminate();server.wait(timeout=5)
 with db.cursor() as c:c.execute(f'DROP DATABASE IF EXISTS `{database}`');c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
 db.close()
