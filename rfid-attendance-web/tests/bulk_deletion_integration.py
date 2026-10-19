"""Bulk deletion checks use a disposable MariaDB fixture, never production records."""
from pathlib import Path
import json, os, re, subprocess, sys, time, uuid
WEB=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(WEB.parent/'.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import expect, sync_playwright

name='bulk_'+uuid.uuid4().hex
username='bulk_'+uuid.uuid4().hex[:12]
password=uuid.uuid4().hex
server=None
PHP=r'''
require getcwd().'/src/bootstrap.php'; $_ENV['DB_PASSWORD']=''; $_SESSION['user_id']=1;
use App\Core\Database;
use App\Services\BulkDeletionService as Bulk;
use App\Services\DeletionConflictService as Conflicts;
$pdo=Database::connection();
\App\Services\AuditSchema::install($pdo);
function check($condition,$message) { if (!$condition) throw new RuntimeException($message); }
function fresh($sql) { global $pdo; $pdo->exec($sql); return (int)$pdo->lastInsertId(); }
function batch($type,$clear,$blocked=null) {
    $ids=$blocked ? [$clear,$blocked,$clear] : [$clear];
    $before=Bulk::inspect($type,$ids,1); check($before['ready_count']===1,$type.' preflight failed');
    $result=Bulk::delete($type,$ids,1); check(count($result['deleted'])===1,$type.' delete failed: '.json_encode($result));
    check($result['deleted'][0]['id']===$clear,$type.' deleted wrong record');
    check($result['blocked_count']===($blocked?1:0),$type.' conflict count failed');
    if ($blocked) check($result['targets'][0]['target']['id']===$blocked,$type.' missing blocker target');
    return $result;
}
batch('department',fresh('INSERT INTO departments(code,name) VALUES("FREE-D","Free department")'),1);
batch('program',fresh('INSERT INTO programs(department_id,code,name) VALUES(1,"FREE-P","Free program")'),1);
batch('section',fresh('INSERT INTO sections(program_id,year_level,section_code,name) VALUES(1,1,"Z","Free section")'),1);
batch('course',fresh('INSERT INTO courses(course_code,title) VALUES("FREE-C","Free course")'),1);
$course=fresh('INSERT INTO courses(course_code,title) VALUES("FREE-O","Offering fixture")');
$offering=fresh('INSERT INTO class_offerings(course_id,section_id,semester_id) VALUES('.$course.',1,1)');
$pdo->exec('INSERT INTO class_enrollments(class_offering_id,student_id) VALUES('.$offering.',5)');
batch('offering',$offering,1);
check(!(bool)$pdo->query('SELECT 1 FROM class_enrollments WHERE class_offering_id='.$offering)->fetchColumn(),'Offering cascade changed');
$room=fresh('INSERT INTO rooms(room_code,room_name) VALUES("FREE-R","Free room")');
$plan=fresh('INSERT INTO room_seat_plans(room_id,name,row_count,column_count,updated_by) VALUES('.$room.',"Room plan",2,2,1)');
batch('room',$room,1); check(!(bool)$pdo->query('SELECT 1 FROM room_seat_plans WHERE id='.$plan)->fetchColumn(),'Room cascade changed');
batch('device',fresh('INSERT INTO devices(device_code,name,api_key_hash) VALUES("FREE-S","Free scanner","fixture")'));
$role=fresh('INSERT INTO roles(name) VALUES("Free role")'); batch('role',$role,3);
$user=fresh('INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) VALUES("FREE-U","Free","User","free@example.test","fixture",4)');
batch('user',$user,5);
$protected=Bulk::delete('user',[1,2],1); check(count($protected['deleted'])===0,'Protected accounts removed');
check($protected['targets'][0]['restriction']!==null,'Rule-only restriction missing');
$event=fresh('INSERT INTO events(title,event_date,start_time,end_time,venue,created_by) VALUES("Free event","2026-10-10","09:00","10:00","Room",1)');
batch('event',$event,1);
$recollection=fresh('INSERT INTO recollections(title,type,section_id,date,start_time,end_time,venue,created_by) VALUES("Free activity","Retreat",1,"2026-10-10","09:00","10:00","Room",1)');
batch('recollection',$recollection,1);
$preset=fresh('INSERT INTO seat_plan_presets(name,created_by) VALUES("Free preset",1)');
$room=fresh('INSERT INTO rooms(room_code,room_name) VALUES("PRESET-R","Preset room")');
$plan=fresh('INSERT INTO room_seat_plans(room_id,name,row_count,column_count,updated_by,based_on_preset_id) VALUES('.$room.',"Retained plan",2,2,1,'.$preset.')');
batch('preset',$preset); check((bool)$pdo->query('SELECT 1 FROM room_seat_plans WHERE id='.$plan.' AND based_on_preset_id IS NULL')->fetchColumn(),'Preset changed retained room plan');
$private=fresh('INSERT INTO seat_plan_presets(name,created_by) VALUES("Secret other personal preset",2)');
$denied=Bulk::inspect('preset',[$private],1); check($denied['blocked_count']===1 && !str_contains(json_encode($denied),'Secret other'),'Private preset identity leaked');
check(Bulk::delete('preset',[$private],1)['deleted']===[],'Other personal preset removed');
$missing=Bulk::inspect('course',[99999999],1); check($missing['blocked_count']===1,'Missing target not handled');
foreach ([[],[0],[-1],['1 OR 1=1'],array_fill(0,101,1),[[1]]] as $bad) { try { Bulk::ids($bad); throw new RuntimeException('Invalid selection accepted'); } catch (DomainException $e) {} }
// More than a page, primary + additional assignments must not duplicate people.
$assigned=fresh('INSERT INTO roles(name) VALUES("Bulk assigned")');
for($i=0;$i<24;$i++) {
    $uid=fresh('INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) VALUES("BULK-'.$i.'","Bulk","Person '.$i.'","bulk'.$i.'@example.test","fixture",'.$assigned.')');
    $pdo->exec('INSERT INTO user_roles(user_id,role_id) VALUES('.$uid.','.$assigned.')');
}
$conflict=Bulk::inspect('role',[$assigned,3],1); check($conflict['groups'][0]['total']===24,'Duplicate role assignments counted');
check(count($conflict['groups'][0]['records'])===20,'Wrong dependency page size');
check(count(Conflicts::inspect('role',$assigned,'users',2)['groups'][0]['records'])===4,'Dependency second page missing');
// An audit failure rolls back the delete too.
$rollback=fresh('INSERT INTO courses(course_code,title) VALUES("ROLLBACK","Rollback course")');
$pdo->exec("CREATE TRIGGER reject_bulk_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test audit failure'");
$failed=Bulk::delete('course',[$rollback],1); check(!$failed['deleted'],'Audit failure reported deleted');
check((bool)$pdo->query('SELECT 1 FROM courses WHERE id='.$rollback)->fetchColumn(),'Audit failure left partial deletion');
$pdo->exec('DROP TRIGGER reject_bulk_audit');
// Restrict snippets before retrieving inaccessible history.
$limited=fresh('INSERT INTO roles(name) VALUES("Limited academic manager")');
$pdo->exec('INSERT INTO role_permissions(role_id,permission_id) SELECT '.$limited.',id FROM permissions WHERE code="manage_academic_structure"');
$restricted=fresh('INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) VALUES("LIMITED","Limited","Manager","limited@example.test","fixture",'.$limited.')');
$pdo->exec('INSERT INTO user_roles(user_id,role_id) VALUES('.$restricted.','.$limited.')');
// Fixture courses for browser pagination, with some blocked and some free.
for($i=1;$i<=25;$i++) fresh('INSERT INTO courses(course_code,title) VALUES("UI-'.str_pad((string)$i,2,'0',STR_PAD_LEFT).'","Browser course '.$i.'")');
$ui=$pdo->query('SELECT id FROM courses WHERE course_code LIKE "UI-%" ORDER BY course_code')->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec('INSERT INTO class_offerings(course_id,section_id,semester_id) VALUES('.$ui[0].',1,1)');
$uiPreset1=fresh('INSERT INTO seat_plan_presets(name,created_by) VALUES("UI Preset One",1)');
$uiPreset2=fresh('INSERT INTO seat_plan_presets(name,created_by) VALUES("UI Preset Two",1)');
$uiRole=fresh('INSERT INTO roles(name) VALUES("UI Disposable Role")');
echo json_encode(['assigned_role'=>$assigned,'courses'=>$ui,'private_preset'=>$private,'limited_user'=>$restricted,'ui_presets'=>[$uiPreset1,$uiPreset2],'ui_role'=>$uiRole],JSON_THROW_ON_ERROR);
'''
db=pymysql.connect(host='127.0.0.1',port=3340,user='root',autocommit=True)
try:
    with db.cursor() as cursor:
        cursor.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4'); cursor.execute(f'USE `{name}`')
        for source in ['schema.sql','seed.sql']:
            sql=re.sub(r'^\s*--.*$','',(WEB/'database'/source).read_text(encoding='utf-8-sig'),flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): cursor.execute(statement)
        cursor.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
        cursor.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'")
        cursor.execute(f"GRANT ALL ON `{name}`.* TO '{username}'@'127.0.0.1'")
    env=os.environ.copy(); env.update(DB_HOST='127.0.0.1',DB_PORT='3340',DB_DATABASE=name,DB_USERNAME='root',DB_PASSWORD='',APP_ENV='testing',APP_DEBUG='true')
    result=subprocess.run(['php','-r',PHP],cwd=WEB,env=env,capture_output=True,text=True)
    assert result.returncode==0,result.stdout+result.stderr
    fixture=json.loads(result.stdout); print('All deletion families, protection rules, rollback, role deduplication, pagination, and private presets passed.')
    # A blocker committed while deletion waits for its parent lock is retained.
    with db.cursor() as cursor:
        cursor.execute('INSERT INTO courses(course_code,title) VALUES("RACE-C","Race fixture")'); race_course=cursor.lastrowid
        cursor.execute('INSERT INTO class_offerings(course_id,section_id,semester_id) VALUES(%s,1,1)',(race_course,)); race_offering=cursor.lastrowid
    blocker=pymysql.connect(host='127.0.0.1',port=3340,user='root',database=name,autocommit=False)
    with blocker.cursor() as cursor:
        cursor.execute('SELECT id FROM class_offerings WHERE id=%s FOR UPDATE',(race_offering,))
        cursor.execute('INSERT INTO attendance_sessions(class_offering_id,attendance_date) VALUES(%s,"2026-10-01")',(race_offering,))
    race_php=r"require getcwd().'/src/bootstrap.php'; $_ENV['DB_PASSWORD']=''; $_SESSION['user_id']=1; echo json_encode(App\Services\BulkDeletionService::delete('offering',[ID],1));".replace('ID',str(race_offering))
    race=subprocess.Popen(['php','-r',race_php],cwd=WEB,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.4); assert race.poll() is None,'Deletion did not wait for parent lock'
    blocker.commit(); blocker.close(); output,error=race.communicate(timeout=15)
    assert race.returncode==0,error
    assert json.loads(output)['blocked_count']==1,'Concurrent committed attendance was deleted'
    with db.cursor() as cursor:
        cursor.execute('SELECT COUNT(*) FROM attendance_sessions WHERE class_offering_id=%s',(race_offering,)); assert cursor.fetchone()[0]==1
    print('Concurrent attendance creation remained protected.')
    env.update(DB_USERNAME=username,DB_PASSWORD=password,APP_URL='http://127.0.0.1:8128')
    server=subprocess.Popen(['php','-S','127.0.0.1:8128','-t',str(WEB/'public')],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.8)
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
        context=browser.new_context(viewport={'width':1440,'height':1000})
        page=context.new_page(); errors=[]; page.on('pageerror',lambda error:errors.append(error.stack))
        base='http://127.0.0.1:8128'
        def login(identifier):
            page.goto(base+'/auth/login.php'); page.locator('[name=identifier]').fill(identifier); page.locator('[name=password]').fill('Password123!'); page.locator('button[type=submit]').click(); page.wait_for_url(re.compile(r'/(admin|index)/(\?|$)'))
        def actions(root, enabled):
            buttons=root.locator('.bulk-toolbar .bulk-actions button')
            expect(buttons).to_have_count(3)
            for button in buttons.all():
                (expect(button).to_be_enabled if enabled else expect(button).to_be_disabled)()
            expect(root.locator('.bulk-toolbar .bulk-action[data-needs-selection]')).to_have_count(0 if enabled else 3)
        login('ADMIN-001'); page.goto(base+'/admin/?page=academics')
        catalog=page.locator('#academics-course-catalog'); catalog.locator('.datatable-input').fill('UI-')
        expect(catalog.locator('input[data-bulk-id]')).to_have_count(10)
        for root in page.locator('.bulk-toolbar').locator('..').all(): actions(root, False)
        # Disabled actions explain themselves to mouse and keyboard users in both themes.
        requests=[]; page.on('request',lambda request: requests.append(request.url) if '/api/bulk-delete.php' in request.url else None)
        for theme in ['light','dark']:
            page.evaluate('theme=>document.documentElement.dataset.theme=theme',theme)
            for hint in catalog.locator('.bulk-action').all():
                hint.hover(); expect(hint.get_by_role('tooltip')).to_be_visible()
                hint.focus(); expect(hint.get_by_role('tooltip')).to_have_text('Select items first.')
                assert hint.get_attribute('aria-describedby')==hint.get_by_role('tooltip').get_attribute('id')
                page.keyboard.press('Escape'); expect(hint.get_by_role('tooltip')).to_be_hidden()
                page.keyboard.press('Tab')
            catalog.locator('.bulk-toolbar').screenshot(path=str(WEB.parent/'.codex-tmp'/f'bulk-disabled-{theme}.png'))
        catalog.locator('[data-bulk-review]').dispatch_event('click')
        catalog.locator('[data-bulk-delete]').dispatch_event('click')
        assert not requests,'Disabled actions made a deletion/conflict request'
        catalog.locator('input[data-bulk-id]').first.check(); actions(catalog, True)
        catalog.locator('input[data-bulk-id]').first.uncheck(); actions(catalog, False)
        catalog.locator('input[data-bulk-id]').first.check(); catalog.locator('[data-bulk-clear]').click(); actions(catalog, False)
        expect(catalog.locator('[data-bulk-count]')).to_have_text('0 selected')
        catalog.locator('[data-bulk-all]').check(); expect(catalog.locator('[data-bulk-count]')).to_have_text('10 selected')
        catalog.locator('.datatable-pagination').get_by_role('button',name='Page 2',exact=True).first.click()
        catalog.locator('input[data-bulk-id]').first.check(); expect(catalog.locator('[data-bulk-count]')).to_have_text('11 selected')
        catalog.locator('.datatable-pagination').get_by_role('button',name='Page 1',exact=True).first.click()
        expect(catalog.locator('input[data-bulk-id]:checked')).to_have_count(10)
        catalog.locator('[data-bulk-delete]').click(); confirm=page.locator('.destructive-confirm-dialog')
        if not confirm.count(): confirm=page.locator('dialog').filter(has=page.locator('[data-destructive-accept]'))
        expect(confirm).to_contain_text('11 courses'); confirm.locator('[data-destructive-accept]').click()
        blocked=page.locator('.deletion-conflict-dialog'); expect(blocked).to_be_visible()
        expect(blocked.locator('[data-conflict-message]')).to_have_text('10 records deleted. 1 retained.')
        expect(catalog.locator('[data-bulk-count]')).to_have_text('1 selected')
        expect(catalog.locator('.datatable-info')).to_contain_text('of 15 records')
        expect(catalog.locator('input[data-bulk-id]:checked')).to_have_count(1)
        link=blocked.locator('.deletion-conflict-record[href]').first
        assert link.get_attribute('target')=='_blank' and link.get_attribute('rel')=='noopener'
        with page.expect_popup() as popup_info: link.click()
        popup=popup_info.value; popup.wait_for_load_state(); expect(popup.locator('[data-academic-management]')).to_be_visible(); popup.close()
        with db.cursor() as cursor: cursor.execute('DELETE FROM class_offerings WHERE course_id=%s',(fixture['courses'][0],))
        blocked.locator('[data-conflict-refresh]').click(); expect(blocked.locator('.deletion-conflict-record')).to_have_count(0)
        with db.cursor() as cursor: cursor.execute('SELECT COUNT(*) FROM courses WHERE id=%s',(fixture['courses'][0],)); assert cursor.fetchone()[0]==1,'Refresh retried deletion'
        blocked.locator('[data-conflict-close]').last.click()
        for theme in ['light','dark']:
            page.evaluate('theme=>document.documentElement.dataset.theme=theme',theme)
            catalog.screenshot(path=str(WEB.parent/'.codex-tmp'/f'bulk-{theme}.png'))
        # Multiple target dependency pages keep distinct Load more URLs.
        payload=context.request.get(base+f'/api/bulk-delete.php?type=role&ids={fixture["assigned_role"]},3').json()
        page.evaluate('payload=>window.showDeletionConflict(payload)',payload)
        group=blocked.locator(f'[data-conflict-group="role_{fixture["assigned_role"]}_users"]')
        expect(group.locator('.deletion-conflict-record')).to_have_count(20); group.locator('[data-conflict-more]').click(); expect(group.locator('.deletion-conflict-record')).to_have_count(24)
        page.keyboard.press('Escape'); expect(blocked).to_be_hidden()
        token=page.locator('body').get_attribute('data-bulk-token')
        assert context.request.post(base+'/api/bulk-delete.php',form={'type':'course','ids':str(fixture['courses'][0])}).status==419
        assert context.request.post(base+'/api/bulk-delete.php',form={'_token':token,'type':'unknown','ids':'1'}).status==422
        assert context.request.get(base+'/api/bulk-delete.php?type=course&ids=' + ','.join(['1']*101)).status==422
        # Existing single-record dialog remains layered above the editor without losing input.
        page.locator('[data-manage-academic=program][data-entity-id="1"]').click(); editor=page.locator('[data-academic-management]')
        editor.locator('[name=name]').fill('Unsaved draft retained'); editor.locator('[data-delete-academic]').click(); page.locator('[data-destructive-accept]').click()
        expect(blocked).to_be_visible(); expect(editor.locator('[name=name]')).to_have_value('Unsaved draft retained')
        blocked.locator('[data-conflict-close]').last.click(); editor.locator('[data-dialog-close]').first.click()
        page.set_viewport_size({'width':390,'height':844}); page.goto(base+'/admin/?page=academics')
        actions(page.locator('#academics-course-catalog'), False)
        mobile_hint=page.locator('#academics-course-catalog .bulk-action').last
        mobile_hint.focus(); expect(mobile_hint.get_by_role('tooltip')).to_be_visible()
        bounds=mobile_hint.get_by_role('tooltip').bounding_box(); assert bounds['x']>=0 and bounds['x']+bounds['width']<=390,'Mobile tooltip overflow'
        page.locator('#academics-course-catalog [data-bulk-all]').check(); page.locator('#academics-course-catalog [data-bulk-review]').click()
        expect(blocked).to_be_visible(); assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'Mobile overflow'
        blocked.screenshot(path=str(WEB.parent/'.codex-tmp'/'bulk-conflicts-mobile.png')); page.keyboard.press('Escape')
        # Server-paginated accounts retain off-page selection.
        page.set_viewport_size({'width':1440,'height':1000}); page.goto(base+'/admin/?page=users')
        actions(page.locator('#user-results'), False)
        first=page.locator('#user-results input[data-bulk-id]').first; first_id=first.get_attribute('data-bulk-id'); first.check()
        page.locator('[data-live-page="2"]').click(); expect(page.locator('#user-results [data-bulk-count]')).to_have_text('1 selected')
        actions(page.locator('#user-results'), True)
        page.locator('[data-live-page="1"]').click(); expect(page.locator(f'#user-results input[data-bulk-id="{first_id}"]')).to_be_checked()
        page.locator('#user-results [data-bulk-clear]').click(); actions(page.locator('#user-results'), False)
        # Every managed list has usable selection.
        for module,types in [('rooms',['room','device']),('events',['event']),('recollections',['recollection'])]:
            page.goto(base+'/admin/?page='+module)
            for type_ in types:
                table=page.locator(f'table[data-bulk-type="{type_}"]')
                expect(table.locator('[data-bulk-all]')).to_be_visible()
                actions(page.locator('.table-wrap').filter(has=table), False)
        page.goto(base+'/admin/?page=rooms'); page.locator('.bulk-preset-directory summary').click()
        directory=page.locator('[data-seat-plan-preset-directory]')
        actions(directory, False)
        directory.locator('[data-bulk-search]').fill('UI Preset'); directory.locator('[data-bulk-all]').check()
        expect(directory.locator('[data-bulk-count]')).to_have_text('2 selected'); directory.locator('[data-bulk-search]').fill('')
        page.locator('[data-seat-plan-name]').fill('Unsaved room plan')
        directory.locator('[data-bulk-delete]').click(); page.locator('[data-destructive-accept]').click()
        expect(directory.locator('[data-bulk-count]')).to_have_text('0 selected'); expect(page.locator('[data-seat-plan-name]')).to_have_value('Unsaved room plan')
        actions(directory, False)
        for preset_id in fixture['ui_presets']: assert not page.locator(f'[data-seat-plan-preset] option[value="{preset_id}"]').count()
        # Role checkboxes delete only custom roles and keep permission navigation usable.
        page.goto(base+'/admin/?page=users')
        role_choice=page.locator('[data-bulk-collection=role] [data-bulk-all]'); expect(role_choice).to_be_visible()
        actions(page.locator('[data-bulk-collection=role]'), False)
        assert page.locator('[data-bulk-collection=role] input[data-bulk-id="3"]').count()==0
        role_id=fixture['ui_role']; page.locator(f'[data-role-tab="{role_id}"]').click()
        page.locator(f'[data-bulk-collection=role] input[data-bulk-id="{role_id}"]').check()
        page.locator('[data-bulk-collection=role] [data-bulk-delete]').click(); page.locator('[data-destructive-accept]').click()
        expect(page.locator(f'[data-role-tab="{role_id}"]')).to_have_count(0); expect(page.locator(f'#role-permissions-{role_id}')).to_have_count(0)
        expect(page.locator('[data-role-tab].is-active')).to_have_count(1)
        actions(page.locator('[data-bulk-collection=role]'), False)
        # Selection headers must update even when a filter only changes row visibility.
        page.route('**/simple-datatables@10*',lambda route:route.abort())
        page.goto(base+'/admin/?page=academics'); native=page.locator('#academics-course-catalog')
        fallback=page.locator('#academics-course-catalog .table-search-fallback')
        fallback.fill('NO MATCHING COURSE'); expect(native.locator('[data-bulk-all]')).to_be_disabled()
        fallback.fill(''); expect(native.locator('[data-bulk-all]')).to_be_enabled()
        department_search=page.locator('#academics-departments [data-collection-search]')
        department_search.fill('NO MATCHING DEPARTMENT'); expect(page.locator('#department-directory-grid [data-bulk-all]')).to_be_disabled()
        department_search.fill(''); expect(page.locator('#department-directory-grid [data-bulk-all]')).to_be_enabled()
        departments=page.locator('#department-directory-grid'); actions(departments, False)
        departments.locator('input[data-bulk-id]').first.check(); actions(departments, True)
        departments.locator('[data-bulk-clear]').click(); actions(departments, False)
        # Custom managers see restricted attendance/grade details and cannot delete accounts.
        with db.cursor() as cursor:
            cursor.execute('UPDATE users SET password_hash=(SELECT password_hash FROM (SELECT password_hash FROM users WHERE id=1) seed_password) WHERE id=%s',(fixture['limited_user'],))
        context.clear_cookies(); login('LIMITED')
        restricted=context.request.get(base+'/api/bulk-delete.php?type=offering&ids=1').json()
        assert restricted['blocked_count']==1
        for group in restricted['groups']:
            for record in group['records']: assert record['title']=='Restricted linked record' and record['url'] is None
        assert context.request.get(base+'/api/bulk-delete.php?type=user&ids=5').status==403
        context.clear_cookies(); login('TCH-001')
        page.goto(base+'/index/?page=seat-plans&offering_id=1&room_id=1'); page.locator('.bulk-preset-directory summary').click()
        directory=page.locator('[data-seat-plan-preset-directory]'); directory.locator(f'input[data-bulk-id="{fixture["private_preset"]}"]').check()
        directory.locator('[data-bulk-delete]').click(); page.locator('[data-destructive-accept]').click()
        expect(directory.locator('[data-bulk-count]')).to_have_text('0 selected')
        actions(directory, False)
        # Restricted role and expired session, including effective View As permissions.
        context.clear_cookies(); assert context.request.get(base+'/api/bulk-delete.php?type=course&ids=1').status==401
        login('2026-0001'); assert context.request.get(base+'/api/bulk-delete.php?type=course&ids=1').status==403
        context.clear_cookies(); login('ADMIN-001')
        token=page.locator('body').get_attribute('data-bulk-token')
        context.request.post(base+'/auth/view-as.php',form={'_token':token,'role_id':'3'})
        assert context.request.get(base+'/api/bulk-delete.php?type=course&ids=1').status==403
        assert not errors,errors
        browser.close()
    print('Browser selection, partial deletion, linked navigation, refresh, pagination, keyboard, mobile, themes, and access checks passed.')
finally:
    if server: server.terminate(); server.wait(timeout=10)
    with db.cursor() as cursor:
        cursor.execute(f'DROP DATABASE IF EXISTS `{name}`'); cursor.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
    db.close()
