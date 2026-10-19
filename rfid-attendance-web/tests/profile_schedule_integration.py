"""Administrator profile schedules and effective access boundaries in disposable MariaDB fixtures."""
from pathlib import Path
import os, re, subprocess, sys, time, uuid

WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect

database = 'profile_schedule_' + uuid.uuid4().hex
username = 'profile_' + uuid.uuid4().hex[:10]
password = uuid.uuid4().hex
db = pymysql.connect(host='127.0.0.1', port=3340, user='root', autocommit=True)
base = 'http://127.0.0.1:8149'
server = None
try:
    with db.cursor() as c:
        c.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4')
        c.execute(f'USE `{database}`')
        for source in ['schema.sql', 'seed.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB / 'database' / source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip():
                    c.execute(statement)
        c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'")
        c.execute(f"GRANT ALL ON `{database}`.* TO '{username}'@'127.0.0.1'")
    env = os.environ.copy()
    env.update(DB_HOST='127.0.0.1', DB_PORT='3340', DB_DATABASE=database, DB_USERNAME=username,
               DB_PASSWORD=password, APP_ENV='testing', APP_DEBUG='true', APP_URL=base)
    probe = subprocess.run(['php', '-r', "require getcwd().'/src/bootstrap.php';"
                            "\\App\\Services\\AnalyticsSchema::install(\\App\\Core\\Database::connection());"
                            "\\App\\Services\\AuditSchema::install(\\App\\Core\\Database::connection());"],
                           cwd=WEB, env=env, capture_output=True, text=True, encoding='utf-8')
    assert probe.returncode == 0, probe.stdout + probe.stderr
    with db.cursor() as c:
        for migration in ['20261004_permission_coverage.sql','20261005_teacher_portal.sql']:
            sql=re.sub(r'^\s*--.*$','',(WEB/'database/migrations'/migration).read_text(encoding='utf-8-sig'),flags=re.M)
            for statement in sql.split(';'):
                if statement.strip():c.execute(statement)
        c.execute("UPDATE users SET profile_visibility='private' WHERE id=5")
        c.execute("INSERT INTO class_schedules(class_offering_id,room_id,day_of_week,start_time,end_time) VALUES(1,2,'Tuesday','14:00:00','15:00:00')")
        c.execute('INSERT INTO class_offerings(id,course_id,section_id,semester_id,room_id) VALUES(99,1,1,2,1)')
        c.execute("INSERT INTO class_schedules(class_offering_id,room_id,day_of_week,start_time,end_time) VALUES(99,1,'Friday','08:00:00','09:00:00')")
        c.execute('INSERT INTO class_enrollments(class_offering_id,student_id) VALUES(99,5)')
        c.execute('INSERT INTO teacher_assignments(class_offering_id,teacher_id) VALUES(99,2)')
        c.execute("INSERT INTO roles(name) VALUES('Schedule-only account manager')");custom=c.lastrowid
        c.execute("INSERT INTO role_permissions(role_id,permission_id) SELECT %s,id FROM permissions WHERE code IN ('manage_users','view_schedule','view_dashboard','manage_own_settings')",(custom,))
        c.execute("INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) SELECT 'PROFILE-MANAGER','Profile','Manager','profile-manager@example.test',password_hash,%s FROM users WHERE id=1",(custom,));custom_user=c.lastrowid
        c.execute('INSERT INTO user_roles(user_id,role_id) VALUES(%s,%s)',(custom_user,custom))
    log=open(WEB.parent/'.codex-tmp/profile-schedule-server.log','w')
    server=subprocess.Popen(['php','-S','127.0.0.1:8149','-t','public'],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.7)
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
        context=browser.new_context(viewport={'width':1440,'height':1100},reduced_motion='reduce');page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.route('**/*',lambda route:route.continue_() if route.request.url.startswith(base+'/') else route.abort())
        def login(identifier):
            context.clear_cookies();page.goto(base+'/auth/login.php',wait_until='domcontentloaded');page.locator('[name=identifier]').fill(identifier);page.locator('[name=password]').fill('Password123!');page.locator('button[type=submit]').click();page.wait_for_url(re.compile('/(admin|index)/'))
        def profile(target,term=None,path='admin',page_name='profile'):
            page.goto(base+'/'+path+'/?page='+page_name+'&user_id='+str(target)+(('&schedule_semester_id='+str(term)) if term else ''),wait_until='load')
        def ids():return page.locator('[data-timetable-event]').evaluate_all('(els)=>els.map(e=>Number(e.dataset.offeringId))')
        login('ADMIN-001')
        for target,expected in [(5,[1,1,2,3]),(2,[1,1,3])]:
            profile(target);expect(page.locator('#admin-profile-schedule')).to_be_visible();assert sorted(ids())==expected,ids()
            expect(page.locator('select[name=schedule_semester_id]')).to_have_value('1')
            assert page.locator('.section-navigation-link[href="#admin-profile-schedule"]').count()==1
            card=page.locator('[data-timetable-event]').first;card.scroll_into_view_if_needed();card.focus();expect(page.locator('.timetable-details')).to_be_visible();page.keyboard.press('Escape')
            assert '/admin/?page=grades' in card.get_attribute('data-grades-url')
            assert '/admin/?page=attendance' in card.get_attribute('data-attendance-url')
            for theme,width in [('light',1440),('dark',1440),('dark',390),('light',320)]:
                page.set_viewport_size({'width':width,'height':1100});page.evaluate('(t)=>document.documentElement.dataset.theme=t',theme);assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),(target,width)
            page.set_viewport_size({'width':1440,'height':1100})
            page.locator('select[name=schedule_semester_id]').select_option('2');page.get_by_role('button',name='View schedule',exact=True).click();page.wait_for_url(re.compile('schedule_semester_id=2'));assert ids()==[99],ids();assert 'user_id='+str(target) in page.url
            page.get_by_role('button',name='List',exact=True).click();expect(page.locator('[data-schedule-panel="list"]')).to_be_visible()
            page.get_by_role('button',name='Timetable',exact=True).click()
        profile(5)
        assert page.locator('[data-timetable-event][data-schedule-day="Tuesday"][data-offering-id="1"]').get_attribute('data-room-id')=='2'
        profile(5,path='index');assert page.locator('#directory-profile-schedule,#admin-profile-schedule').count()==1
        profile(4);assert sorted(ids())==[4,5,6]  # Teacher in a different department.
        with db.cursor() as c:c.execute("UPDATE class_offerings SET status='cancelled' WHERE id=4")
        profile(4);assert sorted(ids())==[5,6]
        profile(4,term=2);assert page.locator('[data-timetable-event]').count()==0  # Unassigned term never shows another person's classes.
        profile(5,term='oops');assert not ids() and 'Select a valid academic term' in page.locator('#admin-profile-schedule').inner_text()
        profile(999999);assert page.locator('[data-timetable-event]').count()==0
        profile(7);assert len(ids())==4
        with db.cursor() as c:c.execute('DELETE FROM class_enrollments WHERE student_id=7')
        profile(7);assert not ids() and 'No meeting schedules' in page.locator('#admin-profile-schedule').inner_text()
        token=page.locator('body').get_attribute('data-bulk-token');page.request.post(base+'/auth/view-as.php',form={'_token':token,'role_id':'2'})
        profile(2,path='index');assert page.locator('#directory-profile-schedule,#admin-profile-schedule').count()==0
        login('2026-0002');profile(5,path='index');assert page.locator('[data-timetable-event]').count()==0 and 'profile to private' in page.locator('body').inner_text()
        login('TCH-001');profile(5,path='index');assert page.locator('[data-timetable-event]').count()==0
        page.goto(base+'/index/?page=schedule',wait_until='load');assert sorted(ids())==[1,1,3,99]
        login('PROFILE-MANAGER');profile(5);assert sorted(ids())==[1,1,2,3];assert all(not e.get_attribute('data-grades-url') and not e.get_attribute('data-attendance-url') for e in page.locator('[data-timetable-event]').all())
        with db.cursor() as c:c.execute("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=%s AND p.code='view_schedule'",(custom,))
        profile(5);assert not ids() and page.locator('#admin-profile-schedule').count()==0
        login('ADMIN-001')
        with db.cursor() as c:
            c.execute('INSERT INTO user_roles(user_id,role_id) VALUES(2,3)');c.execute('INSERT INTO class_enrollments(class_offering_id,student_id) VALUES(1,2)')
        profile(2);assert sorted(ids())==[1,1,3],ids()
        # The timetable is not restricted by the existing 100-card class connection limit.
        with db.cursor() as c:
            for _ in range(101):
                c.execute('INSERT INTO courses(department_id,course_code,title,units) VALUES(1,%s,%s,3)',('FIX'+str(_),'Schedule coverage fixture '+str(_)));course=c.lastrowid
                c.execute('INSERT INTO class_offerings(course_id,section_id,semester_id,room_id) VALUES(%s,1,1,1)',(course,));offering=c.lastrowid
                c.execute('INSERT INTO class_enrollments(class_offering_id,student_id) VALUES(%s,7)',(offering,))
                c.execute("INSERT INTO class_schedules(class_offering_id,room_id,day_of_week,start_time,end_time) VALUES(%s,1,'Monday','08:00:00','09:00:00')",(offering,))
        profile(7);assert len(ids())==101,len(ids())
        assert not errors,errors
        browser.close();print('Admin Student/Teacher schedules, per-meeting rooms, term selection, empty/deleted records, private profiles, permissions, View As, multi-role deduplication, >100 offerings, portal regressions and mobile themes passed.')
finally:
    if server:server.terminate();server.wait(timeout=5)
    with db.cursor() as c:c.execute(f'DROP DATABASE IF EXISTS `{database}`');c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
    db.close()
