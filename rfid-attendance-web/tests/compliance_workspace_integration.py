"""Unified Compliance routing, permissions, exports, and UI using disposable fixtures."""
from pathlib import Path
import csv, io, os, re, subprocess, sys, time, uuid

WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect

database = 'compliance_' + uuid.uuid4().hex
username = 'comp_' + uuid.uuid4().hex[:12]
password = uuid.uuid4().hex
base = 'http://127.0.0.1:8133'
server = None
db = pymysql.connect(host='127.0.0.1', port=3340, user='root', autocommit=True)

try:
    with db.cursor() as c:
        c.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4')
        c.execute(f'USE `{database}`')
        for source in ['schema.sql', 'seed.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB/'database'/source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): c.execute(statement)
        c.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
        for i in range(100, 165):
            c.execute("INSERT INTO users(id,school_id,first_name,last_name,email,password_hash,role_id,department_id,program_id,section_id,status) SELECT %s,%s,'Compliance',%s,%s,password_hash,3,1,1,1,'active' FROM users WHERE id=5", (i, f'COMP-{i}', f'Student {i}', f'comp{i}@example.test'))
            c.execute('INSERT INTO user_roles(user_id,role_id) VALUES(%s,3)', (i,))
        for i in range(63):
            c.execute("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details) VALUES(1,'fixture_change','fixture',%s,'Compliance test action')", (i,))
        roles = {}
        for label, permissions in [('REPORTS', ['manage_reports']), ('AUDIT', ['view_audit_logs']), ('COMPLIANCE', ['view_compliance']), ('MANAGER', ['manage_compliance']), ('NONE', [])]:
            c.execute('INSERT INTO roles(name) VALUES(%s)', ('Fixture ' + label,))
            role_id = c.lastrowid
            roles[label] = role_id
            for permission in permissions:
                c.execute('INSERT INTO role_permissions(role_id,permission_id) SELECT %s,id FROM permissions WHERE code=%s', (role_id, permission))
            c.execute("INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) SELECT %s,'Fixture',%s,%s,password_hash,%s FROM users WHERE id=5", (label, label, label.lower()+'@example.test', role_id))
            c.execute('INSERT INTO user_roles(user_id,role_id) VALUES(%s,%s)', (c.lastrowid, role_id))
            if label in ['COMPLIANCE', 'MANAGER']:
                c.execute('INSERT INTO user_preferences(user_id,start_page) SELECT id,%s FROM users WHERE school_id=%s', ('attendance' if label == 'COMPLIANCE' else 'messages', label))
        # Teaching plus a separately assigned compliance role must use its union of grants.
        c.execute("INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) SELECT 'MULTI','Fixture','Multiple Roles','multi@example.test',password_hash,2 FROM users WHERE id=5")
        multi_user = c.lastrowid
        c.execute('INSERT INTO user_roles(user_id,role_id) VALUES(%s,2),(%s,%s)', (multi_user, multi_user, roles['COMPLIANCE']))
        c.execute('SELECT COUNT(*) FROM users WHERE role_id=3')
        student_count = c.fetchone()[0]
        c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'")
        c.execute(f"GRANT ALL ON `{database}`.* TO '{username}'@'127.0.0.1'")
    env = os.environ.copy()
    env.update(DB_HOST='127.0.0.1', DB_PORT='3340', DB_DATABASE=database, DB_USERNAME=username, DB_PASSWORD=password, APP_ENV='testing', APP_DEBUG='true', APP_URL=base)
    subprocess.run(['php','scripts/install-audit-trail.php'],cwd=WEB,env=env,check=True,capture_output=True)
    server = subprocess.Popen(['php', '-S', '127.0.0.1:8133', '-t', 'public'], cwd=WEB, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.8)
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        context = browser.new_context(viewport={'width':1440, 'height':1000}, color_scheme='dark')
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))

        def login(identifier):
            context.clear_cookies()
            page.goto(base + '/auth/login.php')
            page.locator('[name=identifier]').fill(identifier)
            page.locator('[name=password]').fill('Password123!')
            page.locator('button[type=submit]').click()
            page.wait_for_url(re.compile(r'/(admin|index)/$'))

        def get(path, status=200):
            response = page.request.get(base + path)
            assert response.status == status, (path, response.status, response.text()[:300])
            assert not re.search(r'(Fatal error|Warning:|Deprecated:|SQLSTATE)', response.text()), path
            return response

        def csv_rows(path):
            response = get(path)
            assert 'text/csv' in response.headers['content-type']
            return list(csv.reader(io.StringIO(response.body().decode('utf-8-sig'))))

        login('ADMIN-001')
        page.goto(base + '/admin/?page=compliance')
        expect(page.locator('.compliance-tabs a')).to_have_count(3)
        expect(page.locator('#compliance-results h2')).to_have_text('Student compliance')
        assert page.locator('table').count() == 1
        assert page.locator('#compliance-results tbody tr').count() == 50
        assert not page.get_by_role('link', name='Reports', exact=True).count()
        assert page.get_by_role('link', name='Analytics', exact=True).count()
        token = page.locator('#issue-violation-dialog [name=_token]').input_value()
        page.locator('[data-issue-violation]').first.click()
        dialog = page.locator('#issue-violation-dialog')
        expect(dialog).to_be_visible()
        dialog.locator('[data-violation-group]').evaluate("el=>{el.selectedIndex=1;el.dispatchEvent(new Event('change',{bubbles:true}))}")
        expect(dialog.locator('[data-specific-violation]')).to_be_enabled()
        assert dialog.locator('[data-specific-violation] option').count() > 1
        dialog.locator('.violation-progressive-toggle').click()
        expect(dialog.locator('[name=current_occurrence]')).to_be_visible()
        page.keyboard.press('Escape')
        expect(dialog).not_to_be_visible()
        print('Unified student table and existing violation controls passed.', flush=True)

        rows = csv_rows('/admin/?page=compliance&export=compliance_csv')
        assert len(rows) == student_count + 1, (len(rows), student_count)
        assert len(csv_rows('/admin/?page=compliance&export=compliance_csv&q=COMP-100')) == 2
        assert len(csv_rows('/admin/?page=compliance&export=compliance_csv&program_id=999')) == 1
        expect(page.locator('[name=q]')).to_be_visible()
        page.locator('[name=q]').fill('COMP-100')
        expect(page.locator('#compliance-results tbody tr')).to_have_count(1)
        with page.expect_download() as downloaded:
            page.locator('[data-compliance-export]').click()
        assert 'q=COMP-100' in page.locator('[data-compliance-export]').get_attribute('href')
        assert len(list(csv.reader(io.StringIO(Path(downloaded.value.path()).read_text(encoding='utf-8-sig'))))) == 2
        get('/admin/?page=compliance&partial=compliance&q=COMP-100')
        print('CSV covers all pages and follows live filters.', flush=True)

        for tab, heading in [('classes', 'Class attendance summary'), ('events', 'Event attendance summary')]:
            page.goto(base + '/admin/?page=compliance&tab=' + tab)
            expect(page.get_by_role('heading', name=heading, exact=True)).to_be_visible()
            expect(page.locator('.compliance-tabs [aria-current=page]')).to_have_attribute('data-compliance-tab', tab)
            assert page.locator('table').count() == 1
            assert page.locator('tbody tr').count() > 0
            get('/admin/?page=compliance&tab=' + tab)
        page.goto(base + '/admin/?page=compliance&tab=audit&audit_entity=fixture&audit_page=2')
        expect(page.get_by_role('heading',name='Audit Trail',exact=True)).to_be_visible()
        assert 'entity=fixture' in page.url
        page.goto(base + '/admin/?page=compliance&tab=audit&audit_q=fixture&audit_entity=fixture')
        expect(page.locator('[data-audit-rows] tr')).to_have_count(50)
        page.goto(base + '/admin/?page=reports#reports-event-attendance')
        page.wait_for_url(re.compile(r'page=compliance.*tab=events'))
        expect(page.get_by_role('heading', name='Event attendance summary')).to_be_visible()
        legacy = get('/admin/?page=reports&report_page=2&q=Compliance')
        assert 'page=compliance' in legacy.url and 'compliance_page=2' in legacy.url
        assert len(csv_rows('/admin/?page=reports&export=compliance_csv&q=COMP-100')) == 2
        page.goto(base + '/admin/?page=analytics')
        expect(page.get_by_role('heading', name='Analytics', exact=True)).to_be_visible()
        expect(page.get_by_role('heading', name='Analytics is coming later')).to_be_visible()
        assert not page.locator('table').count()
        search = get('/api/search.php?q=reports').json()
        assert 'page=compliance' in str(search) and 'page=reports' not in str(search)
        print('Summaries, audit filtering/pagination, search, and legacy links passed.', flush=True)

        # Native links work with keyboard; both themes and narrow layout preserve access.
        page.goto(base + '/admin/?page=compliance')
        page.locator('[data-compliance-tab=events]').focus()
        page.keyboard.press('Enter')
        page.wait_for_url(re.compile('tab=events'))
        for theme in ['light', 'dark']:
            for width in [1440, 390]:
                page.set_viewport_size({'width':width, 'height':844})
                page.goto(base + '/admin/?page=compliance&tab=students')
                page.evaluate('(theme)=>document.documentElement.dataset.theme=theme', theme)
                page.wait_for_timeout(450)
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), (theme, width, 'overflow')
                expect(page.locator('.compliance-tabs a[aria-current=page]')).to_be_visible()
                if width == 1440:
                    page.screenshot(path=str(WEB.parent/'.codex-tmp'/f'compliance-{theme}.png'), full_page=True)
        page.emulate_media(media='print')
        expect(page.locator('.compliance-tabs')).not_to_be_visible()
        assert page.locator('.compliance-page').evaluate('el=>getComputedStyle(el).color') == 'rgb(23, 32, 51)'
        expect(page.locator('.compliance-row-actions').first).not_to_be_visible()
        page.emulate_media(media='screen')
        print('Keyboard links, mobile sizing, themes, and print checks passed.', flush=True)

        # All old grants retain equivalent data access; forbidden panes and forged partials fail.
        for label, permitted, denied in [
            ('REPORTS', ['students', 'classes', 'events'], ['audit']),
            ('AUDIT', ['audit'], ['students', 'classes', 'events']),
            ('COMPLIANCE', ['students'], ['classes', 'events', 'audit']),
            ('MANAGER', ['students'], ['classes', 'events', 'audit']),
            ('MULTI', ['students'], ['classes', 'events', 'audit']),
        ]:
            login(label)
            assert get('/admin/').status == 200
            if label == 'AUDIT':
                page.goto(base+'/admin/?page=compliance')
                expect(page.get_by_role('heading',name='Audit Trail',exact=True)).to_be_visible()
                for tab in ['students','classes','events']: get('/admin/?page=compliance&tab='+tab,403)
                get('/admin/?page=compliance&partial=compliance',403)
                get('/admin/?page=compliance&export=compliance_csv',403)
                get('/admin/?page=analytics')
                get('/admin/audit-trail.php')
                assert 'page=audit-trail' in str(get('/api/search.php?q=audit&category=pages').json())
                assert 'page=compliance' not in str(get('/api/search.php?q=compliance&category=pages').json())
                response=page.request.post(base+'/admin/?page=compliance',form={'action':'issue_violation','_token':token,'student_id':'5'})
                assert response.status==403
                continue
            page.goto(base + '/admin/?page=compliance')
            assert page.locator('.compliance-tabs a').count() == len(permitted)
            assert bool(page.locator('[data-issue-violation]').count()) == (label == 'MANAGER')
            search = get('/api/search.php?q=compliance&category=pages').json()
            assert 'page=compliance' in str(search) and '/admin/' in str(search)
            # Stored portal bookmarks preserve filters and cannot expose an older workspace.
            legacy = get('/index/?page=compliance&q=COMP-100&section_id=1&compliance_page=2')
            assert '/admin/' in legacy.url and 'q=COMP-100' in legacy.url
            assert 'section_id=1' in legacy.url and 'compliance_page=2' in legacy.url
            for tab in permitted: get('/admin/?page=compliance&tab=' + tab)
            for tab in denied: get('/admin/?page=compliance&tab=' + tab, 403)
            get('/admin/?page=compliance&partial=compliance', 403 if label == 'AUDIT' else 200)
            get('/admin/?page=compliance&export=compliance_csv', 200 if label == 'REPORTS' else 403)
            get('/admin/?page=analytics', 200 if label in ['REPORTS', 'AUDIT'] else 403)
            if label in ['COMPLIANCE', 'MANAGER']:
                for unrelated in ['users', 'rooms', 'academics', 'events', 'grades']:
                    get('/admin/?page=' + unrelated, 403)
            if label in ['COMPLIANCE', 'REPORTS', 'MULTI']:
                page.goto(base + '/admin/?page=compliance')
                view_token = page.locator('input[name=_token]').first.input_value()
                response = page.request.post(base + '/admin/?page=compliance', headers={'X-Requested-With':'fetch'}, form={'action':'issue_violation', '_token':view_token, 'student_id':'5'})
                assert response.status == 422 and not response.json()['ok']
                assert 'permission' in response.json()['message']
            if label == 'AUDIT':
                response = page.request.post(base + '/admin/?page=compliance', form={'action':'issue_violation', '_token':token, 'student_id':'5'})
                assert response.status == 403
                assert '#compliance-results' not in get('/admin/?page=reports').text()
        # A manage-only role can submit through both canonical and legacy routes.
        login('MANAGER')
        page.goto(base + '/admin/?page=compliance')
        manager_token = page.locator('#issue-violation-dialog [name=_token]').input_value()
        payload = {'action':'issue_violation', '_token':manager_token, 'student_id':'5', 'violation_group':'Minor offenses', 'specific_violation':'Littering', 'classification':'Category C', 'incident_date':time.strftime('%Y-%m-%d'), 'incident_time':'09:00', 'location':'Isolated fixture', 'incident_description':'Documented fixture incident for compliance authorization testing.'}
        for route in ['/admin/?page=compliance', '/index/?page=compliance']:
            response = page.request.post(base + route, headers={'X-Requested-With':'fetch'}, form=payload)
            assert response.status == 200 and response.json()['ok'], response.text()
            assert '/admin/?page=compliance' in response.json()['redirect']
        with db.cursor() as c:
            c.execute('SELECT COUNT(*),MAX(id) FROM disciplinary_violations')
            issued_count, violation_id = c.fetchone()
            assert issued_count == 2, issued_count
            c.execute("SELECT COUNT(*) FROM audit_logs WHERE action='issue_violation'")
            assert c.fetchone()[0] == 2
        get('/admin/deletion-record.php?type=violation&id=' + str(violation_id))
        print('View-only and manage-only roles, mixed roles, canonical routing, and protected actions passed.', flush=True)
        login('NONE')
        get('/admin/?page=compliance', 403)
        get('/index/?page=compliance', 403)
        assert 'page=compliance' not in str(get('/api/search.php?q=compliance&category=pages').json())
        for identifier in ['TCH-001', '2026-0001']:
            login(identifier)
            assert '/index/' in page.url
            response = get('/index/?page=compliance')
            assert '/index/' in response.url and 'data-compliance-workspace' not in response.text()
            get('/admin/?page=compliance', 403)
            search = get('/api/search.php?q=compliance&category=pages').json()
            assert '/index/?page=compliance' in str(search) and '/admin/' not in str(search)
        # Explicit campus-wide grants also work when assigned directly to a built-in role.
        for permission in ['manage_compliance', 'manage_reports', 'view_audit_logs']:
            with db.cursor() as c:
                c.execute('INSERT INTO role_permissions(role_id,permission_id) SELECT roles.id,permissions.id FROM roles CROSS JOIN permissions WHERE roles.id IN (2,3) AND permissions.code=%s', (permission,))
            for identifier in ['TCH-001', '2026-0001']:
                login(identifier)
                assert '/admin/' in page.url
                response = get('/index/?page=compliance')
                assert '/admin/' in response.url
                assert 'data-compliance-workspace' in response.text()
                assert ('data-issue-violation' in response.text()) == (permission == 'manage_compliance')
                get('/admin/?page=compliance&tab=audit', 200 if permission == 'view_audit_logs' else 403)
                get('/admin/?page=compliance&tab=classes', 200 if permission == 'manage_reports' else 403)
            login('ADMIN-001')
            page.goto(base + '/admin/?page=compliance')
            token = page.locator('#issue-violation-dialog [name=_token]').input_value()
            for role_id in [2, 3]:
                page.request.post(base + '/auth/view-as.php', form={'_token':token, 'role_id':str(role_id)})
                assert '/admin/' in get('/index/?page=compliance').url
            with db.cursor() as c:
                c.execute('DELETE role_permissions FROM role_permissions JOIN permissions ON permissions.id=permission_id WHERE role_id IN (2,3) AND permissions.code=%s', (permission,))
        print('Direct Teacher/Student management grants and equivalent View As scope passed.', flush=True)
        login('ADMIN-001')
        page.goto(base + '/admin/?page=compliance')
        token = page.locator('#issue-violation-dialog [name=_token]').input_value()
        page.request.post(base + '/auth/view-as.php', form={'_token':token, 'role_id':str(roles['AUDIT'])})
        get('/admin/?page=compliance&tab=students', 403)
        get('/admin/?page=compliance&tab=audit')
        for label in ['COMPLIANCE', 'MANAGER', 'REPORTS']:
            page.request.post(base + '/auth/view-as.php', form={'_token':token, 'role_id':str(roles[label])})
            response = get('/index/?page=compliance')
            assert '/admin/?page=compliance' in response.url
            assert ('data-issue-violation' in response.text()) == (label == 'MANAGER')
            get('/admin/?page=compliance&tab=audit', 403)
        # Built-in staff/coordinator roles share the same workspace; View As uses their grants.
        for role_id in [4, 5]:
            page.request.post(base + '/auth/view-as.php', form={'_token':token, 'role_id':str(role_id)})
            response = get('/index/?page=compliance')
            assert '/admin/' in response.url
            get('/admin/?page=compliance&tab=classes')
            get('/admin/?page=compliance&tab=events')
            get('/admin/?page=compliance&tab=audit')
        for role_id in [2, 3]:
            page.request.post(base + '/auth/view-as.php', form={'_token':token, 'role_id':str(role_id)})
            get('/admin/?page=compliance&tab=audit', 403)
            response = get('/index/?page=compliance')
            assert '/index/' in response.url
            assert '/admin/' not in str(get('/api/search.php?q=compliance&category=pages').json())
        context.clear_cookies()
        response = page.request.get(base + '/admin/?page=compliance&export=compliance_csv')
        assert '/auth/login.php' in response.url and 'text/csv' not in response.headers.get('content-type', '')
        assert not errors, errors
        browser.close()
        print('Restricted roles, View As, portal scope, and expired-session checks passed.', flush=True)
finally:
    if server:
        server.terminate()
        server.wait(timeout=5)
    with db.cursor() as c:
        c.execute(f'DROP DATABASE IF EXISTS `{database}`')
        c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
    db.close()
