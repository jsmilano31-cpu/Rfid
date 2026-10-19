"""Permission revocation and independent grants; isolated MariaDB (3340) and browser only."""
from pathlib import Path
import json, os, re, subprocess, sys, time, uuid
WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect
expect.set_options(timeout=12000)
database = 'permissions_' + uuid.uuid4().hex
username = 'perm_' + uuid.uuid4().hex[:12]
password = uuid.uuid4().hex
base = 'http://127.0.0.1:8147'
server = None
PROBE = r'''
require getcwd().'/src/bootstrap.php'; $_ENV['DB_PASSWORD']='';
use App\Core\Auth;use App\Core\Database;use App\Core\PermissionRegistry as P;
use App\Controllers\AdminController;use App\Controllers\PortalController;
use App\Services\AttendanceService as A;use App\Services\GradeService as G;
use App\Services\AnalyticsSchema;use App\Services\AuditSchema;use App\Services\SearchService;
$pdo=Database::connection();function check($v,$m){if(!$v)throw new RuntimeException($m);}
function sqlFile($pdo,$file){$sql=preg_replace('/^\s*--.*$/m','',file_get_contents($file));foreach(explode(';',$sql)as$s)if(trim($s))$pdo->exec($s);}
AnalyticsSchema::install($pdo);AuditSchema::install($pdo);
$beforeOther=$pdo->query('SELECT role_id,permission_id FROM role_permissions WHERE role_id<>2 ORDER BY role_id,permission_id')->fetchAll();
$pdo->exec('DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code IN("correct_class_attendance","assign_class_seats"))');
$pdo->exec('DELETE FROM permissions WHERE code IN("correct_class_attendance","assign_class_seats")');
$pdo->exec('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT 2,id FROM permissions');
sqlFile($pdo,BASE_PATH.'/database/migrations/20261005_teacher_portal.sql');
$expected=['view_dashboard','view_schedule','view_attendance','view_grades','view_class_rosters','view_events','view_recollections','view_people','view_messages','view_compliance','view_analytics','use_search','manage_own_profile','manage_own_settings','manage_own_notifications','send_messages','manage_grades','correct_class_attendance','assign_class_seats'];sort($expected);
$actual=$pdo->query('SELECT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id WHERE rp.role_id=2 ORDER BY p.code')->fetchAll(PDO::FETCH_COLUMN);check($actual===$expected,'Teacher migration baseline incorrect');
$oldIds=$pdo->query('SELECT id FROM permissions WHERE code NOT IN("correct_class_attendance","assign_class_seats")')->fetchAll(PDO::FETCH_COLUMN);
$oldBefore=array_values(array_filter($beforeOther,fn($r)=>in_array($r['permission_id'],$oldIds)));
$oldAfter=$pdo->query('SELECT role_id,permission_id FROM role_permissions WHERE role_id<>2 AND permission_id IN('.implode(',',$oldIds).') ORDER BY role_id,permission_id')->fetchAll();check($oldBefore===$oldAfter,'Other role grants changed');
$pdo->exec('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=2 AND p.code="assign_class_seats"');
sqlFile($pdo,BASE_PATH.'/database/migrations/20261005_teacher_portal.sql');
check(!(int)$pdo->query('SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=2 AND p.code="assign_class_seats"')->fetchColumn(),'Rerun restored revoked teacher grant');
$pdo->exec('INSERT INTO role_permissions(role_id,permission_id) SELECT 2,id FROM permissions WHERE code="assign_class_seats"');

$pdo->prepare('UPDATE users SET password_hash=?')->execute([password_hash('Password123!',PASSWORD_DEFAULT)]);
Auth::attempt('ADMIN-001','Password123!');
$plan=\App\Services\SeatPlanService::saveRoomPlan(['room_id'=>1,'name'=>'Prepared Classroom','row_count'=>2,'column_count'=>2,'cells'=>'[{"row":1,"column":1,"type":"student_seat","label":"A1"},{"row":1,"column":2,"type":"student_seat","label":"A2"}]'],1);

// Simulate upgrade from a database without the former login-only grants.
$codes=array_keys(P::SELF_SERVICE);$in=implode(',',array_fill(0,count($codes),'?'));
$pdo->prepare('DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE code IN('.$in.'))')->execute($codes);
$pdo->prepare('DELETE FROM permissions WHERE code IN('.$in.')')->execute($codes);
$pdo->exec('DELETE FROM role_permissions WHERE role_id=2 AND permission_id=18');
sqlFile($pdo,BASE_PATH.'/database/migrations/20261004_permission_coverage.sql');
check((int)$pdo->query('SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=2 AND p.code="view_messages"')->fetchColumn()===1,'Upgrade lost former login-only behavior');
$pdo->exec('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=2 AND p.code="view_events"');
sqlFile($pdo,BASE_PATH.'/database/migrations/20261004_permission_coverage.sql');
check(!(int)$pdo->query('SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=2 AND p.code IN("view_events","manage_attendance_policies")')->fetchColumn(),'Migration restored revoked grants');
// Catalog must cover every controller mutation and permission reference.
$catalog=$pdo->query('SELECT code FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
foreach([AdminController::class,PortalController::class]as$c){$r=new ReflectionClass($c);foreach($r->getConstant('PAGE_ACTIONS')as$actions)foreach($actions as$a)check(isset(P::ACTIONS[$a]),'Unmapped native action '.$a);}
foreach(P::ACTIONS as$grants)foreach($grants as$g)check(in_array($g,$catalog,true),'Missing grant '.$g);
function account($name,$grants){global $pdo;
 $pdo->prepare('INSERT INTO roles(name,description) VALUES(?,?)')->execute([$name,'Permission fixture']);$role=(int)$pdo->lastInsertId();
 foreach($grants as$g)$pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code=?')->execute([$role,$g]);
 $pdo->prepare('INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) SELECT ?,?,"Fixture",?,password_hash,? FROM users WHERE id=1')->execute([$name,$name,$name.'@test.local',$role]);
 return ['role'=>$role,'user'=>(int)$pdo->lastInsertId()];
}
$fixtures=[];
$fixtures['CalendarOnly']=account('CalendarOnly',['manage_academic_calendar','view_dashboard','view_schedule']);
$fixtures['CalendarBoth']=account('CalendarBoth',['manage_academic_calendar','manage_attendance_policies','view_dashboard','view_schedule']);
$fixtures['RoleOnly']=account('RoleOnly',['manage_roles','view_dashboard']);
$fixtures['RfidOnly']=account('RfidOnly',['manage_rfid','view_dashboard']);
$fixtures['GradingOnly']=account('GradingOnly',['manage_grading_settings','view_dashboard']);
$fixtures['SendOnly']=account('SendOnly',['send_messages','view_dashboard']);
$fixtures['NoAccess']=account('NoAccess',[]);
$fixtures['SearchOnly']=account('SearchOnly',['use_search','view_dashboard']);
$pdo->exec('INSERT INTO role_permissions(role_id,permission_id) VALUES(2,20)');
Auth::attempt('TCH-001','Password123!');check(!P::canManageCampusCalendar(),'Teacher calendar bypass');
check(!\App\Services\UserPreferenceService::allowsKind(2,'activity','event'),'Disabled events leaked through notifications');
check(!A::hasGlobalAccess(2) && !A::hasGlobalPolicyAccess(2),'Teacher scope expanded');
$profile=A::studentAttendanceProfile(5,2);check($profile['compliance']===null && $profile['student']['rfid_uid']===null,'Inspector bypassed domain grant');
foreach($profile['classes']as$class){$s=$pdo->prepare('SELECT 1 FROM teacher_assignments WHERE teacher_id=2 AND class_offering_id=?');$s->execute([$class['class_offering_id']]);check((bool)$s->fetchColumn(),'Inspector leaked unrelated class');}
try{A::studentAttendanceProfile(14,2);throw new RuntimeException('Unrelated student exposed');}catch(DomainException){}
Auth::attempt('CalendarBoth','Password123!');check(P::canManageCampusCalendar(),'Primary role missing from authorization');
check(A::hasGlobalPolicyAccess((int)Auth::user()['id']),'Primary role missing from global scope');
$_GET['id']=5;check(!isset(SearchService::visibleNavigation(false)['profile']),'Other-profile query granted own profile navigation');unset($_GET['id']);
Auth::attempt('SendOnly','Password123!');
try{\App\Services\MessageService::send(['subject'=>'Fixture','body'=>'Fixture','recipient_type'=>'user','target_id'=>5,'forward_attachment_ids'=>'[1]'],(int)Auth::user()['id']);throw new RuntimeException('Send-only role forwarded restricted attachments');}catch(DomainException $e){check(str_contains($e->getMessage(),'permission to forward'),'Forwarding did not enforce mailbox permission');}
Auth::attempt('ADMIN-001','Password123!');
Auth::setPreviewRole($fixtures['CalendarOnly']['role']);check(!A::hasGlobalAccess(1)&&!A::hasGlobalPolicyAccess(1)&&!G::hasGlobalAccess(1),'View As used actual administrator grants');
check(!\App\Services\UserPreferenceService::allowsKind(1,'message'),'View As inherited administrator notifications');
Auth::setPreviewRole(2);check(!P::canManageCampusCalendar()&&!A::hasGlobalAccess(1),'Teacher preview inherited administrator');
Auth::setPreviewRole(null);
$pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)')->execute([$fixtures['CalendarOnly']['user'],$fixtures['CalendarBoth']['role']]);
Auth::attempt('CalendarOnly','Password123!');check(P::canManageCampusCalendar(),'Additional role not additive');
$pdo->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$fixtures['CalendarOnly']['user']]);
$pdo->exec('UPDATE users SET first_name="HiddenPerson",profile_visibility="private",school_id="HIDDEN-ID",email="hidden-email@test.local" WHERE id=5');
echo json_encode($fixtures,JSON_THROW_ON_ERROR);
'''
db = pymysql.connect(host='127.0.0.1', port=3340, user='root', autocommit=True)
try:
    with db.cursor() as c:
        c.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4'); c.execute(f'USE `{database}`')
        for source in ['schema.sql','seed.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB/'database'/source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): c.execute(statement)
        c.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
        c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'")
        c.execute(f"GRANT ALL ON `{database}`.* TO '{username}'@'127.0.0.1'")
    env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='3340',DB_DATABASE=database,DB_USERNAME='root',DB_PASSWORD='',APP_ENV='testing',APP_DEBUG='true')
    result=subprocess.run(['php','-r',PROBE],cwd=WEB,env=env,capture_output=True,text=True)
    assert result.returncode==0,result.stdout+result.stderr
    ids=json.loads(result.stdout);print('Migration revocation, catalog, effective roles, View As, and attendance scope passed.',flush=True)
    env.update(DB_USERNAME=username,DB_PASSWORD=password,APP_URL=base)
    server=subprocess.Popen(['php','-S','127.0.0.1:8147','-t','public'],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.8)
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
        context=browser.new_context(viewport={'width':1440,'height':1000});context.route(re.compile(r'https://fonts\.(googleapis|gstatic)\.com/'),lambda route:route.abort())
        page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        def login(identifier):
            context.clear_cookies();page.goto(base+'/auth/login.php')
            token=page.locator('[name=_token]').input_value()
            response=page.request.post(base+'/auth/login.php',form={'_token':token,'identifier':identifier,'password':'Password123!'},max_redirects=0)
            assert response.status in (302,303),(identifier,response.status,response.text()[:150])
            return response
        def get(path):return page.request.get(base+path,max_redirects=0)
        def token():return page.locator('body').get_attribute('data-bulk-token')
        def denied_form(path,action,extra=None):
            response=page.request.post(base+path,form={'_token':token(),'action':action,**(extra or {})},max_redirects=0)
            assert response.status==403,(path,action,response.status,response.text()[:150])
        login('NoAccess')
        endpoints=['/api/search.php?q=Juan','/api/messages.php','/api/message-attachment.php?id=1','/api/notifications.php','/api/holidays.php?year=2026','/admin/calendar-events.php','/attendance.php','/grades.php','/class-roster.php','/seat-plans.php','/admin/user.php?q=Juan','/admin/rfid.php','/admin/facility.php','/admin/academic-entity.php','/admin/recollection.php','/admin/deletion-conflicts.php?type=course&id=1','/api/analytics.php','/admin/audit-trail.php','/lookups/users.php?context=directory&q=Juan']
        for path in endpoints:
            response=get(path);assert response.status==403,(path,response.status,response.text()[:180])
        for role,path in [('TCH-001','/index/?page=attendance'),('CalendarOnly','/admin/?page=attendance')]:
            login(role);page.goto(base+path)
            assert page.locator('#admin-attendance-weekly-schedules,#admin-attendance-school-year').count()==0
            denied_form('/admin/?page=attendance','update_department_days_off',{'department_id':'1'}) if role=='CalendarOnly' else None
            if role=='CalendarOnly':denied_form('/admin/?page=attendance','update_academic_calendar')
            response=page.request.post(base+'/api/holidays.php',form={'_token':token(),'date':'2026-12-25','is_non_working':'1'})
            assert response.status==403,response.text()
        login('CalendarBoth');page.goto(base+'/admin/?page=attendance')
        expect(page.locator('#admin-attendance-weekly-schedules')).to_be_visible();expect(page.locator('#admin-attendance-school-year')).to_be_visible()
        denied_form('/admin/?page=attendance','manual_attendance')
        response=page.request.post(base+'/admin/?page=attendance',form={'_token':token(),'action':'update_department_days_off','department_id':'3'},max_redirects=0)
        assert response.status in (302,303),response.text()
        with db.cursor() as c:
            c.execute('SELECT COUNT(*) FROM department_days_off WHERE department_id=3');assert c.fetchone()[0]==0
        login('RoleOnly');page.goto(base+'/admin/?page=users')
        expect(page.locator('#users-role-permissions')).to_be_visible();assert page.locator('#users-accounts,#create-user-dialog').count()==0
        page.set_viewport_size({'width':390,'height':844})
        assert page.locator('.role-rail').bounding_box()['width']<=390
        page.set_viewport_size({'width':1440,'height':1000})
        for grant in ['view_schedule','view_class_rosters','view_events','view_recollections','view_people','view_messages','use_search','manage_own_profile','manage_own_settings','manage_own_notifications','view_dashboard']:
            assert page.locator(f'[data-permission-code="{grant}"]').count()>0 or grant in page.content(),grant
        denied_form('/admin/?page=users','create_user');assert get('/admin/?page=users&partial=accounts').status==403
        assert get('/admin/user.php?id=5').status==403
        login('RfidOnly');page.goto(base+'/admin/?page=users');expect(page.locator('[data-dialog-open="#rfid-registration-dialog"]')).to_be_visible()
        assert get('/admin/user.php?q=HiddenPerson').status==200
        assert get('/admin/user.php?id=5').status==403
        login('GradingOnly');page.goto(base+'/admin/?page=grades')
        assert page.locator('#admin-grade-sheets,#admin-grade-lookup,[data-admin-grade-console]').count()==0
        denied_form('/admin/?page=grades','save_grade')
        login('SearchOnly');page.goto(base+'/index/?page=dashboard')
        assert page.locator('[data-notification-center],#campus-calendar,#dashboard-events-feed').count()==0
        response=get('/api/search.php?q=IT&context=academics');assert response.status==200,response.text()
        data=response.json();assert all(not g.get('items',g.get('results',[])) for g in data.get('groups',[]) if g.get('key',g.get('category'))!='pages'),data
        login('SendOnly');page.goto(base+'/index/?page=messages')
        expect(page.locator('[data-mail-compose]')).to_be_visible();assert page.locator('[data-mail-list]').count()==0
        assert get('/api/messages.php').status==403
        denied_form('/index/?page=messages','mark_message_read')
        login('TCH-001');page.goto(base+'/index/?page=people')
        hidden=get('/lookups/users.php?context=directory&q=HiddenPerson').json()['items'];assert len(hidden)==1,hidden
        assert not hidden[0]['identifier'] and not hidden[0]['email'] and not hidden[0]['section_name'],hidden
        assert get('/lookups/users.php?context=directory&q=HIDDEN-ID').json()['pagination']['total']==0
        assert get('/lookups/users.php?context=directory&q=hidden-email').json()['pagination']['total']==0
        assert get('/attendance.php?mode=student_details&student_id=14').status==422
        scoped=get('/attendance.php?mode=student_details&student_id=5').json();assert scoped['compliance'] is None and scoped['student']['rfid_uid'] is None,scoped
        # A role-only View As account must not inherit administrator-only panels/actions.
        login('ADMIN-001');page.goto(base+'/admin/?page=users')
        response=page.request.post(base+'/auth/view-as.php',form={'_token':token(),'role_id':str(ids['CalendarOnly']['role'])},max_redirects=0)
        assert response.status in (302,303),response.text();page.goto(base+'/admin/?page=attendance')
        assert page.locator('#admin-attendance-weekly-schedules,#admin-attendance-correction').count()==0
        denied_form('/admin/?page=attendance','update_academic_calendar')
        assert get('/grades.php?mode=subjects').status==403

        # Restore the intentionally revoked event grant before baseline UI checks.
        with db.cursor() as c:c.execute('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT 2,id FROM permissions WHERE code="view_events"')
        # Teacher classroom capabilities must not bring campus management along.
        login('TCH-001');page.goto(base+'/index/')
        links=set(page.locator('#sidebar .nav-link').evaluate_all('(els)=>els.map(e=>e.getAttribute("aria-label"))'))
        assert {'Schedule','Attendance','Grades','Analytics','Seat Plans','Events','Recollections','People','Messages','Compliance','Profile','Settings'}<=links,links
        assert not {'Attendance Policies','Rooms & Devices','Users & RFID','Audit Trail'}&links,links
        assert get('/admin/?page=rooms').status==403
        denied_form('/admin/?page=attendance','manual_attendance')
        for path,action in [('/index/?page=events','create_event'),('/index/?page=attendance','rfid_class_scan'),('/index/?page=attendance','finalize_attendance'),('/attendance.php','quick_present'),('/attendance.php','rfid_class_scan'),('/attendance.php','finalize_attendance'),('/attendance.php','update_attendance_settings'),('/attendance.php','update_subject_attendance_policy'),('/seat-plans.php','save_room_plan'),('/seat-plans.php','save_preset'),('/seat-plans.php','update_preset'),('/seat-plans.php','delete_preset'),('/grades.php','update_grading_settings')]:
            denied_form(path,action)
        for path in ['/admin/user.php?id=5','/admin/rfid.php','/admin/facility.php','/admin/academic-entity.php','/admin/audit-trail.php','/api/holidays.php?year=2026','/grades.php?mode=settings']:
            # Holidays are permitted to read. Policy mutation is separately forbidden.
            if 'holidays' not in path:assert get(path).status==403,(path,get(path).status)
        page.goto(base+'/index/?page=attendance')
        assert page.locator('[data-attendance-finalize],input[value="rfid_class_scan"]').count()==0
        expect(page.locator('[data-attendance-subject-results] .grade-subject-card').first).to_be_visible()
        attendance={'class_offering_id':'1','student_id':'5','attendance_date':'2026-09-07','status':'Late','time_in':'08:22','reason':'Verified classroom correction'}
        def post_json(path,action,extra,expected=200):
            res=page.request.post(base+path,form={'_token':token(),'action':action,**extra});assert res.status==expected,(path,action,res.status,res.text()[:250]);return res.json()
        post_json('/attendance.php','manual_attendance',attendance)
        post_json('/attendance.php','manual_attendance',{**attendance,'reason':''},422)
        post_json('/attendance.php','manual_attendance',{**attendance,'class_offering_id':'4','student_id':'14'},422)
        post_json('/attendance.php','manual_attendance',{**attendance,'student_id':'14'},422)
        lookup=get('/lookups/users.php?context=class_enrollments&class_offering_id=1&q=HiddenPerson')
        assert lookup.status==200,lookup.text()
        assert get('/lookups/users.php?context=class_enrollments&class_offering_id=4&q=Student').status==422
        assert get('/attendance.php?mode=roster&offering_id=4&date=2026-09-07').status==422
        with db.cursor() as c:c.execute('UPDATE grades SET status="draft",finalized_at=NULL WHERE class_offering_id=1 AND student_id=5')
        grades={'class_offering_id':'1','student_id':'5','prelim':'85','midterm':'88','final':'92'}
        post_json('/grades.php','save_grade',grades)
        post_json('/grades.php','save_grade',{**grades,'finalize':'1'})
        post_json('/grades.php','save_grade',{**grades,'class_offering_id':'4','student_id':'14'},422)
        page.goto(base+'/index/?page=seat-plans&offering_id=1')
        assert page.locator('[data-seat-plan-editor]').count()==0
        expect(page.locator('[data-live-seat-configure-assignments]')).to_be_visible()
        state=get('/attendance.php?mode=live_seat_plan&offering_id=1&date=2026-09-07').json()
        plan_id=state['plan']['id']
        assignment={'class_offering_id':'1','seat_plan_id':str(plan_id),'grid_row':'1','grid_column':'1','student_id':'5'}
        post_json('/attendance.php','assign_class_seat',assignment)
        post_json('/attendance.php','assign_class_seat',{**assignment,'class_offering_id':'4'},422)
        post_json('/attendance.php','assign_class_seat',{**assignment,'student_id':'14'},422)
        post_json('/attendance.php','assign_class_seat',{**assignment,'grid_row':'20'},422)
        post_json('/attendance.php','unassign_class_seat',assignment)
        assert get('/seat-plans.php?mode=room&room_id=3').status==422
        # Exercise the actual prepared-plan assignment UI by keyboard.
        page.locator('[data-live-seat-configure-assignments]').click()
        seat=page.locator('.live-seat-cell.is-student-seat').first
        seat.focus();seat.press('Enter')
        dialog=page.locator('[data-live-seat-assignment-dialog]');expect(dialog).to_be_visible()
        expect(dialog.locator('[data-live-seat-student-results] button').first).to_be_visible()
        dialog.locator('[data-live-seat-student-results] button').first.click()
        dialog.locator('[data-live-seat-assign]').click();expect(dialog).not_to_be_visible()
        for theme,width in [('light',1440),('dark',1440),('dark',390)]:
            page.set_viewport_size({'width':width,'height':1000});page.evaluate('(t)=>document.documentElement.dataset.theme=t',theme)
            page.wait_for_timeout(350)  # Allow sidebar breakpoint/width transitions to settle.
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),('seats',theme,width,page.evaluate('({scroll:document.documentElement.scrollWidth,items:Array.from(document.querySelectorAll("main *")).filter(e=>e.getBoundingClientRect().right>innerWidth+1).slice(0,15).map(e=>({cls:e.className,right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width}))})'))
        page.set_viewport_size({'width':1440,'height':1000})
        search=get('/api/search.php?q=correct').json();assert any(i['key']=='action:correct-attendance' for g in search['groups'] for i in g['items']),search
        search=get('/api/search.php?q=assign').json();assert any(i['key']=='action:open-seat-plans' for g in search['groups'] for i in g['items']),search
        login('ADMIN-001');page.goto(base+'/admin/')
        assert page.locator('#sidebar .nav-link[aria-label="Profile"],.account-menu-panel a[href$="page=profile"]').count()==0
        assert get('/admin/?page=profile').headers['location'].endswith('/admin/?page=settings')
        assert get('/index/?page=profile').headers['location'].endswith('/admin/?page=settings')
        assert get('/admin/?page=profile&id=5').status==200
        for preview in ['2','3']:
            page.request.post(base+'/auth/view-as.php',form={'_token':token(),'role_id':preview},max_redirects=0);page.goto(base+'/index/')
            assert page.locator('#sidebar .nav-link[aria-label="Profile"]').count()==1
            denied_form('/seat-plans.php','save_room_plan')
            denied_form('/attendance.php','rfid_class_scan')
        print('Teacher portal, manual corrections, prepared seat assignments, assigned grade finalization, tampered records, denied management and admin Profile removal passed.',flush=True)
        # Management grants must not replace Teacher/Student dashboard sections.
        with db.cursor() as c:
            c.execute('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.id IN (2,3) AND p.code IN ("manage_reports","view_events")')
            c.execute('INSERT INTO user_preferences(user_id,start_page) VALUES(2,"schedule"),(5,"schedule")')
        common_sections={
            'portal-dashboard-overview':'Today at a glance',
            'dashboard-campus-calendar':'Campus calendar',
            'dashboard-events-feed':'Events & Announcements',
            'portal-dashboard-schedule':'Class schedule',
            'portal-dashboard-activities':'Upcoming activities',
        }
        for identifier,user_id,role_id in [('TCH-001',2,2),('HIDDEN-ID',5,3)]:
            response=login(identifier);assert response.headers['location'].endswith('/index/?page=schedule')
            for path in ['/admin/','/index/']:
                page.goto(base+path)
                for section,label in common_sections.items():
                    expect(page.locator(f'.section-navigation-link[href="#{section}"]')).to_have_text(label)
                    assert page.locator('#'+section).count()==1
                expect(page.locator('#portal-dashboard-schedule h2').first).to_have_text('Class schedule')
                actual_offerings=set(page.locator('#portal-dashboard-schedule [data-offering-id]').evaluate_all('(items)=>items.map(item=>Number(item.dataset.offeringId))'))
                with db.cursor() as c:
                    relation='teacher_assignments' if role_id==2 else 'class_enrollments'
                    owner='teacher_id' if role_id==2 else 'student_id'
                    enrolled=' AND relation.status="enrolled"' if role_id==3 else ''
                    c.execute(f'SELECT DISTINCT relation.class_offering_id FROM {relation} relation JOIN class_schedules s ON s.class_offering_id=relation.class_offering_id WHERE relation.{owner}=%s'+enrolled,(user_id,))
                    expected_offerings={int(row[0]) for row in c.fetchall()}
                    assert actual_offerings==expected_offerings,actual_offerings
                assert 'Operations overview' not in page.locator('.page-header').first.inner_text()
                for theme,width in [('light',1440),('dark',1440),('dark',390)]:
                    page.set_viewport_size({'width':width,'height':1000});page.evaluate('(t)=>document.documentElement.dataset.theme=t',theme)
                    link=page.locator('.section-navigation-link[href="#portal-dashboard-schedule"]')
                    link.focus();link.press('Enter')
                    expect(page).to_have_url(re.compile(r'#portal-dashboard-schedule$'))
                    expect(page.locator('#portal-dashboard-schedule')).to_be_in_viewport()
                    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),(identifier,path,width,page.evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth,items:Array.from(document.querySelectorAll("main section,main article,main .grid,main .table-wrap")).filter(e=>e.getBoundingClientRect().right>innerWidth+1).map(e=>({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width}))})'))
                page.set_viewport_size({'width':1440,'height':1000})
                sidebar_link=page.locator('#sidebar .nav-link[aria-label="Schedule"]')
                expected_url='/index/?page=schedule'
                expect(sidebar_link).to_have_attribute('href',base+expected_url)
                group=page.locator('.sidebar-group[data-sidebar-group="learning"]')
                if group.count() and group.get_attribute('open') is None:group.locator('summary').click()
                sidebar_link.click();expect(page.locator('.page-header h1')).to_have_text('Schedule')
                expect(page.locator('#sidebar .nav-link[aria-label="Schedule"]')).to_have_attribute('aria-current','page')
                scheduled=set(page.locator('#portal-weekly-schedule [data-offering-id]').evaluate_all('(items)=>items.map(item=>Number(item.dataset.offeringId))'))
                assert scheduled==expected_offerings,scheduled
                search=get('/api/search.php?q=sched').json()
                matches=[item for section in search['groups'] for item in section['items'] if item['key']=='page:schedule']
                assert matches and matches[0]['url']==base+'/index/?page=schedule',matches
            with db.cursor() as c:
                c.execute('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=%s AND p.code="view_schedule"',(role_id,))
            page.goto(base+'/admin/')
            assert page.locator('#portal-dashboard-schedule,.section-navigation-link[href="#portal-dashboard-schedule"]').count()==0
            assert page.locator('#sidebar .nav-link[aria-label="Schedule"]').count()==0
            assert get('/admin/?page=schedule').status==302
            assert get('/index/?page=schedule').status==403
            search=get('/api/search.php?q=sched').json()
            assert all(item['key']!='page:schedule' for section in search['groups'] for item in section['items'])
            response=login(identifier);assert response.headers['location'].endswith('/index/')
            with db.cursor() as c:
                c.execute('INSERT INTO role_permissions(role_id,permission_id) SELECT %s,id FROM permissions WHERE code="view_schedule"',(role_id,))
        login('ADMIN-001');page.goto(base+'/admin/')
        expect(page.locator('.page-header h1')).to_have_text('Operations overview')
        assert page.locator('#sidebar .nav-link[aria-label="Schedule"]').count()==0
        for role in ['2','3']:
            response=page.request.post(base+'/auth/view-as.php',form={'_token':token(),'role_id':role},max_redirects=0)
            assert response.status in (302,303);page.goto(base+'/admin/')
            expect(page.locator('.section-navigation-link[href="#portal-dashboard-schedule"]')).to_have_text('Class schedule')
            expect(page.locator('#portal-dashboard-schedule .schedule-empty-state')).to_be_visible()
            expect(page.locator('#sidebar .nav-link[aria-label="Schedule"]')).to_have_attribute('href',base+'/index/?page=schedule')
        print('Shared Student/Teacher dashboard sections, both entry URLs, themes/mobile, revoked schedule permission and View As passed.',flush=True)
        assert not errors,errors
        browser.close()
    print('Denied API/form requests, calendar controls, role/RFID-only management, send-only compose, private identity, and View As browser checks passed.',flush=True)
finally:
    if server:server.terminate();server.wait(timeout=10)
    with db.cursor() as c:
        if re.fullmatch(r'permissions_[a-f0-9]{32}',database):c.execute(f'DROP DATABASE `{database}`')
        c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
    db.close()
