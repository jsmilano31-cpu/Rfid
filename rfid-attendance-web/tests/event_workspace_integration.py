"""Disposable fixtures: scoped event CRUD, scanner/manual history and real themed browser UI."""
from pathlib import Path
import json, os, re, subprocess, sys, time, uuid

WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect

name = 'event_workspace_' + uuid.uuid4().hex
username = 'event_' + uuid.uuid4().hex[:12]
password = uuid.uuid4().hex
base = 'http://127.0.0.1:8152'
db = pymysql.connect(host='127.0.0.1', port=3340, user='root', autocommit=True)
server = None
log = None
try:
    with db.cursor() as c:
        c.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4')
        c.execute(f'USE `{name}`')
        for source in ['schema.sql', 'seed.sql', 'migrations/20261004_permission_coverage.sql', 'migrations/20261005_teacher_portal.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB / 'database' / source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): c.execute(statement)
        c.execute('ALTER TABLE events ADD COLUMN is_announcement BOOLEAN NOT NULL DEFAULT FALSE')
        c.execute('INSERT IGNORE INTO role_permissions SELECT 2,id FROM permissions WHERE code="manage_events"')
        c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'")
        c.execute(f"GRANT ALL ON `{name}`.* TO '{username}'@'127.0.0.1'")
    env = os.environ.copy()
    env.update(DB_HOST='127.0.0.1', DB_PORT='3340', DB_DATABASE=name, DB_USERNAME=username,
               DB_PASSWORD=password, APP_ENV='testing', APP_DEBUG='true', APP_URL=base)
    def php(code):
        result = subprocess.run(['php', '-r', "require getcwd().'/src/bootstrap.php';" + code], cwd=WEB, env=env, capture_output=True, encoding='utf-8')
        assert result.returncode == 0 and not result.stderr, result.stdout + result.stderr
        return result.stdout
    php('App\\Services\\AnalyticsSchema::install(App\\Core\\Database::connection());App\\Services\\EventTrackingSchema::install(App\\Core\\Database::connection());')
    baseline_count = int(php('echo App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM event_activity_logs")->fetchColumn();'))
    assert baseline_count > 0
    php('App\\Services\\EventTrackingSchema::install(App\\Core\\Database::connection());')
    assert baseline_count == int(php('echo App\\Core\\Database::connection()->query("SELECT COUNT(*) FROM event_activity_logs")->fetchColumn();'))
    fixture = json.loads(php(r'''
        use App\Core\Auth; use App\Core\Database; use App\Services\EventService;
        Auth::attempt('TCH-001','Password123!');
        $data=['title'=>'Teacher event fixture','description'=>'Details for participants','event_date'=>date('Y-m-d'),'start_time'=>'00:00','end_time'=>'23:59','venue'=>'Test Hall','attendance_required'=>'1','check_out_required'=>'1','targets_json'=>'[{"type":"user","id":5},{"type":"user","id":6},{"type":"user","id":2}]','scanner_ids'=>[1]];
        $own=EventService::create($data,2);
        $data['title']='Editable teacher fixture';unset($data['scanner_ids']);$editable=EventService::create($data,2);
        Auth::attempt('TCH-002','Password123!');$data['title']='Other teacher private fixture';$other=EventService::create($data,3);
        Auth::attempt('ADMIN-001','Password123!');$data['title']='Administrator private fixture';$admin=EventService::create($data,1);
        $device=App\Services\RfidDeviceService::rotateKey(1,1);
        echo json_encode(['own'=>$own,'editable'=>$editable,'other'=>$other,'admin'=>$admin,'device_code'=>$device['device']['device_code'],'device_key'=>$device['api_key']]);
    '''))
    log = open(WEB.parent / '.codex-tmp/event-workspace-server.log', 'w', encoding='utf-8')
    server = subprocess.Popen(['php','-S','127.0.0.1:8152','-t','public'], cwd=WEB, env=env, stdout=subprocess.DEVNULL, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.7)
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        context = browser.new_context(viewport={'width':1440,'height':1000})
        errors = []
        page = context.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        def route_external(route):
            url = route.request.url
            if url.startswith(base): return route.continue_()
            if 'choices.js@11.1.0' in url:
                asset = WEB.parent / '.codex-tmp' / ('choices-filter-fixture.css' if url.endswith('.css') else 'choices-filter-fixture.js')
                if asset.exists(): return route.fulfill(path=str(asset), content_type='text/css' if url.endswith('.css') else 'text/javascript')
            return route.abort()
        page.route('**/*', route_external)
        def login(identifier):
            context.clear_cookies()
            page.goto(base+'/auth/login.php',wait_until='domcontentloaded')
            page.locator('[name=identifier]').fill(identifier)
            page.locator('[name=password]').fill('Password123!')
            page.locator('button[type=submit]').click()
            page.wait_for_url(re.compile('/(admin|index)/'))
        def get(mode,event,extra=''):
            return page.request.get(f'{base}/api/events.php?mode={mode}&event_id={event}&{extra}')
        def token(): return page.locator('input[name=_token]').first.input_value()
        def post(url,fields): return page.request.post(base+url, multipart={'_token':token(),**fields})
        login('TCH-001')
        page.goto(base+'/index/?page=events')
        expect(page.locator('[data-event-create]')).to_be_visible()
        assert page.locator('a',has_text='Manage events').count()==0
        assert 'other teacher private fixture' in page.locator('#event-collection').inner_text().lower()  # invited to view, but not manage
        own_card=page.locator(f'[data-universal-record="event:{fixture["other"]}"]')
        assert own_card.locator('[data-event-edit],[data-event-delete]').count()==0
        own=fixture['own']; editable=fixture['editable']
        assert get('details',own).json()['event']['can_manage']
        assert not get('details',fixture['other']).json()['event']['can_manage']
        assert get('live',fixture['other']).status==422
        assert post('/admin/calendar-events.php',{'action':'update_event','event_id':str(fixture['other'])}).status==422
        assert post('/api/events.php',{'action':'set_status','event_id':str(fixture['other']),'status':'cancelled'}).status==422
        assert page.request.get(base+f'/admin/deletion-conflicts.php?type=event&id={fixture["other"]}').status==422
        denial=post('/admin/calendar-events.php',{'action':'delete_event','event_id':str(fixture['other'])})
        assert denial.status==422
        assert post('/admin/calendar-events.php',{'action':'create_event','title':'Unauthorized suspension','venue':'Hall','event_date':'2026-10-20','start_time':'09:00','end_time':'10:00','suspends_classes':'1','targets_json':'[{"type":"all_students"}]'}).status==422
        # Creation and editing through actual portal modal, including search dropdowns.
        page.locator('[data-event-create]').click()
        expect(page.locator('#event-workspace-editor')).to_be_visible()
        form=page.locator('[data-event-workspace-editor]')
        form.locator('[name=title]').fill('Created in teacher portal')
        form.locator('[name=venue]').fill('Prepared Hall')
        form.locator('[name=event_date]').fill('2026-12-01')
        form.locator('[name=start_time]').fill('09:00')
        form.locator('[name=end_time]').fill('10:00')
        form.locator('[data-event-editor-submit]').click()
        page.wait_for_url(re.compile('/event.php\?id='))
        created=int(page.url.split('id=')[1])
        expect(page.locator('h1')).to_have_text('Created in Teacher Portal')
        page.locator('[data-event-edit]').click()
        form=page.locator('[data-event-workspace-editor]')
        form.locator('[name=title]').fill('Updated in teacher portal')
        form.locator('[data-event-editor-submit]').click()
        expect(page.locator('h1')).to_have_text('Updated in Teacher Portal')
        page.locator('[data-event-delete]').click()
        page.locator('.event-confirm-dialog').get_by_role('button',name='Delete event',exact=True).click()
        page.wait_for_url(re.compile('/index/\?page=events'))
        assert get('details',created).status==422
        # Live scanner event, manual ID fallback, duplicate protection and durable records.
        page.goto(base+f'/event.php?id={own}')
        page.wait_for_function("!document.querySelector('[data-event-activity-feedback]').textContent.includes('Loading')")
        start=get('live',own).json()['cursor']
        page.locator('[data-event-tracking-start]').click()
        expect(page.locator('[data-event-tracking]')).to_be_visible()
        page.wait_for_function("document.querySelector('[data-event-live-status]').textContent.startsWith('Live')")
        with db.cursor() as c:
            c.execute('SELECT uid FROM rfid_cards WHERE user_id=5 AND status="active" LIMIT 1');uid=c.fetchone()[0]
        scan=page.request.post(base+'/iot/scan.php',data={'uid':uid},headers={'X-Device-Code':fixture['device_code'],'X-Device-Key':fixture['device_key'],'Content-Type':'application/json'})
        assert scan.status==200,scan.text()
        expect(page.locator('[data-tracking-school-id]')).to_have_text('2026-0001',timeout=10000)
        assert get('live',own,f'after={start}').json()['records'][-1]['action']=='check_in'
        duplicate=page.request.post(base+'/iot/scan.php',data={'uid':uid},headers={'X-Device-Code':fixture['device_code'],'X-Device-Key':fixture['device_key'],'Content-Type':'application/json'})
        assert duplicate.status==422,duplicate.text()
        page.locator('.event-tracking-manual summary').click()
        manual=page.locator('[data-event-manual-form]')
        manual.locator('[name=school_id]').fill('2026-0002')
        manual.locator('button').click()
        expect(page.locator('[data-event-manual-status]')).to_contain_text('recorded for Ana')
        expect(page.locator('[data-tracking-school-id]')).to_have_text('2026-0002')
        # Fast checkout is rejected; simulated elapsed server time permits a valid checkout.
        with db.cursor() as c:
            c.execute('SET @ct_actor_id=NULL')
            c.execute('UPDATE event_attendance SET check_in_at=DATE_SUB(check_in_at,INTERVAL 60 SECOND) WHERE event_id=%s AND user_id=6',(own,))
        manual.locator('[name=school_id]').fill('2026-0002')
        manual.locator('[name=attendance_action]').select_option('check_out')
        manual.locator('button').click()
        expect(page.locator('[data-event-manual-status]')).to_contain_text('Checkout recorded')
        expect(page.locator('[data-tracking-outcome]')).to_have_attribute('data-action','check_out')
        page.screenshot(path=str(WEB.parent/'.codex-tmp/event-tracking-participant.png'))
        page.locator('[data-event-tracking-close]').click()
        expect(page.locator('[data-event-tracking]')).not_to_be_visible()
        activity=get('activity',own).json()
        assert activity['total']>=3 and any(r['action']=='check_out' for r in activity['records'])
        assert all('uid' not in r for r in activity['records'])
        assert get('activity',own,'q=Ana&action=check_out&source=manual').json()['total']==1
        assert get('activity',own,'sort=invalid').status==422
        assert get('activity',own,'from=2026-99-99').status==422
        # Closing retains past logs, refuses live recording and protected deletion.
        closed=post('/api/events.php',{'action':'set_status','event_id':str(own),'status':'closed'})
        assert closed.status==200,closed.text()
        assert get('activity',own).json()['total']>=activity['total']
        assert not get('live',own).json()['accepting']
        assert post('/api/events.php',{'action':'manual_id','event_id':str(own),'school_id':'2026-0001','attendance_action':'check_in'}).status==422
        assert post('/admin/calendar-events.php',{'action':'delete_event','event_id':str(own)}).status==409
        # UI theme/mobile geometry and keyboard dialog closing.
        page.goto(base+f'/event.php?id={editable}')
        for theme in ['light','dark']:
            for accent in ['blue','green','purple','orange']:
                page.evaluate('([theme,accent])=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.accent=accent}',[theme,accent])
                page.locator('[data-event-edit]').click()
                expect(page.locator('#event-workspace-editor')).to_be_visible()
                page.keyboard.press('Escape')
                expect(page.locator('#event-workspace-editor')).not_to_be_visible()
                assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth+1'), page.evaluate('Array.from(document.querySelectorAll("body *")).filter(e=>e.getBoundingClientRect().right>innerWidth+1&&e.getBoundingClientRect().width).map(e=>({tag:e.tagName,classes:e.className,width:e.getBoundingClientRect().width})).slice(0,12).concat([{innerWidth,scrollWidth:document.documentElement.scrollWidth,grid:getComputedStyle(document.querySelector(".event-overview")).gridTemplateColumns,main:getComputedStyle(document.querySelector(".app-main")).width,margin:getComputedStyle(document.querySelector(".app-main")).marginLeft}])')
        page.set_viewport_size({'width':390,'height':844})
        page.wait_for_function("innerWidth===390 && matchMedia('(max-width:800px)').matches && !document.fullscreenElement")
        page.evaluate('new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))')
        page.screenshot(path=str(WEB.parent/'.codex-tmp/event-mobile-layout-debug.png'))
        assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth+1'), page.evaluate('Array.from(document.querySelectorAll("body *")).filter(e=>e.getBoundingClientRect().right>innerWidth+1&&e.getBoundingClientRect().width).map(e=>({tag:e.tagName,classes:e.className,width:e.getBoundingClientRect().width})).slice(0,12).concat([{innerWidth,scrollWidth:document.documentElement.scrollWidth,grid:getComputedStyle(document.querySelector(".event-overview")).gridTemplateColumns,main:getComputedStyle(document.querySelector(".app-main")).width,margin:getComputedStyle(document.querySelector(".app-main")).marginLeft}])')
        page.locator('[data-event-tracking-start]').click()
        expect(page.locator('[data-event-tracking]')).to_be_visible()
        assert page.locator('[data-event-tracking]').evaluate('(e)=>e.scrollWidth <= innerWidth+1')
        page.screenshot(path=str(WEB.parent/'.codex-tmp/event-tracking-mobile.png'))
        page.locator('[data-event-tracking-close]').click()
        page.set_viewport_size({'width':1440,'height':1000})
        page.locator('[data-event-tracking-start]').click()
        page.screenshot(path=str(WEB.parent/'.codex-tmp/event-tracking-desktop.png'))
        page.locator('[data-event-tracking-close]').click()
        # Students see their own history; uninvited people and revoked teachers cannot track.
        login('2026-0001')
        own_records=get('activity',own).json()['records']
        assert own_records and all(r['school_id']=='2026-0001' for r in own_records)
        assert get('live',own).status==422
        assert get('details',fixture['admin']).status==200  # invited fixture audience
        assert get('details',1).status in [200,422]
        login('ADMIN-001')
        assert get('details',fixture['other']).json()['event']['can_manage']
        # Preview cannot inherit the administrator's ownership scope.
        response=post('/auth/view-as.php',{'role_id':'2'})
        preview=get('details',fixture['admin'])
        assert preview.status==422 or not preview.json().get('event',{}).get('can_manage',False)
        login('TCH-001')
        with db.cursor() as c:c.execute('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=2 AND p.code="manage_events"')
        assert get('details',editable).json()['event']['can_manage'] is False
        assert get('live',editable).status==422
        assert post('/api/events.php',{'action':'manual_id','event_id':str(editable),'school_id':'2026-0001','attendance_action':'check_in'}).status==422
        assert not errors,errors
        login('ADMIN-001')
        with db.cursor() as c:
            c.executemany('INSERT INTO event_activity_logs(event_id,user_id,action,occurred_at,source,status) VALUES(%s,5,"check_in","2026-10-06 09:00:00","rfid","Attended")',[(own,)]*5000)
        started=time.perf_counter(); benchmark=get('activity',own); elapsed=time.perf_counter()-started
        assert benchmark.status==200 and benchmark.json()['total']>=5000,benchmark.text()
        assert len(benchmark.json()['records'])==25
        assert get('activity',own,'page=2').json()['page']==2
        assert get('activity',own,'q=Garcia&source=manual&action=check_out').json()['total']==1
        print(f'Indexed 5,000-row activity page: {elapsed*1000:.0f} ms on the local fixture environment.')
        assert elapsed<2
        browser.close()
    with db.cursor() as c:
        c.execute('SELECT table_name FROM audit_tracking_tables WHERE table_name="event_activity_logs"');assert c.fetchone()
        c.execute('SELECT COUNT(*) FROM audit_logs WHERE entity_type="event_activity_logs" AND source="database"');assert c.fetchone()[0]>0
        c.execute('SELECT COUNT(*) FROM analytics_changes WHERE source_table="event_attendance"');assert c.fetchone()[0]>0
        c.execute('EXPLAIN SELECT id FROM event_activity_logs WHERE event_id=%s AND id>0 ORDER BY id LIMIT 50',(fixture['own'],));plan=c.fetchall();assert any('idx_event_activity' in str(row) for row in plan)
    print('Event CRUD scope, scanner/manual logs, legacy migration, history protection, permissions, themes, mobile and live UI passed.')
finally:
    if server:server.terminate();server.wait(timeout=10)
    if log:log.close()
    with db.cursor() as c:
        c.execute(f'DROP DATABASE IF EXISTS `{name}`')
        c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
    db.close()
