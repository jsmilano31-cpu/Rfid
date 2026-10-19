"""Readable, scoped CSV exports and section-switch behavior in disposable MariaDB fixtures."""
from pathlib import Path
import csv, datetime, io, os, re, subprocess, sys, time, uuid
from urllib.parse import parse_qs, urlparse

WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect

database = 'analytics_exports_' + uuid.uuid4().hex
username = 'exports_' + uuid.uuid4().hex[:10]
password = uuid.uuid4().hex
db = pymysql.connect(host='127.0.0.1', port=3340, user='root', autocommit=True)
base = 'http://127.0.0.1:8148'
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
                            "\\App\\Services\\AuditSchema::install(\\App\\Core\\Database::connection());\\App\\Services\\EventTrackingSchema::install(\\App\\Core\\Database::connection());"],
                           cwd=WEB, env=env, capture_output=True, text=True, encoding='utf-8')
    assert probe.returncode == 0, probe.stdout + probe.stderr
    with db.cursor() as c:
        c.execute('DELETE FROM analytics_attendance')
        observations = []
        for day in range(65):
            date = datetime.date(2026, 8, 1) + datetime.timedelta(days=day)
            for offering in [1, 3]:
                observations.append((offering, 5, date, 1, 1, 1, 1, 1, 'Present', 1, 1, 1))
        c.executemany('INSERT INTO analytics_attendance(class_offering_id,student_id,attendance_date,semester_id,department_id,program_id,section_id,course_id,status,finalized,expected,history_known) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)', observations)
        c.execute("UPDATE courses SET course_code='=SUM(1,2)' WHERE id=1")
        c.execute("UPDATE users SET first_name='=Dangerous',last_name='CSV, Student' WHERE id=5")
    log = open(WEB.parent / '.codex-tmp/analytics-exports-server.log', 'w')
    server = subprocess.Popen(['php', '-S', '127.0.0.1:8148', '-t', 'public'], cwd=WEB, env=env,
                              stdout=subprocess.DEVNULL, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.7)
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        context = browser.new_context(viewport={'width':1440, 'height':1000})
        page = context.new_page()
        page.route('**/*', lambda route: route.continue_() if route.request.url.startswith(base + '/') else route.abort())
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))

        def login(identifier):
            context.clear_cookies()
            page.goto(base + '/auth/login.php', wait_until='domcontentloaded')
            page.locator('[name=identifier]').fill(identifier)
            page.locator('[name=password]').fill('Password123!')
            page.locator('button[type=submit]').click()
            page.wait_for_url(re.compile('/(admin|index)/'))

        def export(section, extra=''):
            response = page.request.get(base + '/api/analytics.php?mode=export&section=' + section + '&from=2026-08-01&to=2026-10-04&semester_id=1&' + extra)
            assert response.status == 200, response.text()
            assert 'text/csv' in response.headers['content-type']
            assert response.headers['cache-control'] == 'no-store'
            raw = response.body()
            assert raw.startswith(b'\xef\xbb\xbf')
            text = raw.decode('utf-8-sig')
            assert '{"' not in text and 'attendance_records' not in text and 'semester_id' not in text
            return list(csv.reader(io.StringIO(text)))

        def records(rows, first_column):
            index = next(i for i, row in enumerate(rows) if row and row[0] == first_column)
            return rows[index], rows[index + 1:]

        login('ADMIN-001')
        rows = export('attendance', 'sort=name&direction=asc')
        header, values = records(rows, 'Student')
        assert len(values) == 130  # Every page, not just the first 100 rows.
        assert all(row[0].startswith("'=Dangerous") for row in values)
        assert next(row for row in rows if row and row[0] == 'Semester')[1].startswith('First Semester')
        assert next(row for row in rows if row and row[0] == 'From')[1] == 'Aug 1, 2026'
        rows = export('resources', 'course_id=1')
        header, values = records(rows, 'Subject · section')
        assert 'Starts' in header and 'Room' in header and len(values) > 0
        assert all(row[0].startswith("'=SUM(1,2)") for row in values)
        assert all('AM' in row[header.index('Starts')] or 'PM' in row[header.index('Starts')] for row in values)
        assert next(row for row in rows if row and row[0] == 'Subject')[1].startswith("'=SUM(1,2)")
        rows = export('resources', 'panel=booked-hours')
        assert records(rows, 'Weekday')[0] == ['Weekday', 'Hour', 'Booked minutes', 'Meetings']
        rows = export('resources', 'q=%3DNoResults')
        assert next(row for row in rows if row and row[0] == 'Record search')[1] == "'=NoResults"
        assert next(row for row in rows if row and row[0] == 'Results')[1].startswith('No matching records')
        for section in ['grades', 'activities', 'compliance', 'quality', 'communications', 'scanners']:
            export(section)

        page.goto(base + '/admin/?page=analytics&section=attendance&from=2026-08-01&to=2026-10-04&semester_id=1')
        link = page.locator('[data-analytics-export]')
        expect(link).to_have_attribute('href', re.compile('section=attendance'))
        assert '"from"' not in page.locator('[data-analytics-print-filters]').inner_text()
        def fail_resources(route):
            if parse_qs(urlparse(route.request.url).query).get('section') == ['resources']:
                route.fulfill(status=503, content_type='application/json', body='{"ok":false,"message":"Fixture loading failure"}')
            else:
                route.continue_()
        page.route('**/api/analytics.php?*', fail_resources)
        state = page.evaluate("""() => {document.querySelector('[data-analytics-tab="resources"]').click();const a=document.querySelector('[data-analytics-export]');return {href:a.getAttribute('href'),disabled:a.getAttribute('aria-disabled')};}""")
        assert state == {'href':None, 'disabled':'true'}
        expect(page.locator('[data-analytics-status]')).to_contain_text('Fixture loading failure')
        assert link.get_attribute('href') is None
        page.unroute('**/api/analytics.php?*', fail_resources)
        page.locator('[data-analytics-status] button').click()
        expect(link).to_have_attribute('href', re.compile('section=resources'))
        expect(link).to_contain_text('Scheduling & resources')
        with page.expect_download() as download:
            link.click()
        assert download.value.suggested_filename == 'campustrack-analytics-resources.csv'
        page.locator('.analytics-panel').nth(1).get_by_role('button', name='Inspect records').click()
        drawer_link = page.locator('[data-analytics-record-export]')
        expect(drawer_link).to_have_attribute('href', re.compile('panel=schedule-detail'))
        page.locator('[data-analytics-record-search]').fill('CSV search with no matches')
        expect(page.locator('[data-analytics-record-status]')).to_contain_text('0 accessible records')
        expect(drawer_link).to_have_attribute('href', re.compile('q=CSV'))

        login('2026-0001')
        rows = export('attendance')
        assert {r[1] for r in records(rows, 'Student')[1]} == {'2026-0001'}
        rows = export('resources', 'course_id=4')
        assert next(row for row in rows if row and row[0] == 'Subject')[1] == 'Unavailable in your permitted scope'
        assert page.request.get(base + '/api/analytics.php?section=resources&mode=export&offering_id=4').status == 403
        assert page.request.get(base + '/api/analytics.php?section=scanners&mode=export').status == 403
        login('TCH-001')
        rows = export('resources')
        assert all('BSIT' in row[0] for row in records(rows, 'Subject · section')[1])
        login('ADMIN-001')
        token = page.locator('body').get_attribute('data-bulk-token')
        page.request.post(base + '/auth/view-as.php', form={'_token':token, 'role_id':'3'})
        assert page.request.get(base + '/api/analytics.php?section=scanners&mode=export').status == 403
        context.clear_cookies()
        assert page.request.get(base + '/api/analytics.php?mode=export').status == 401
        assert not errors, errors
        browser.close()
        print('Readable CSVs, pagination, formula escaping, filtered panel exports, loading/failure links, download section, student/teacher scope, View As and expired sessions passed.')
finally:
    if server:
        server.terminate()
        server.wait(timeout=5)
    with db.cursor() as c:
        c.execute(f'DROP DATABASE IF EXISTS `{database}`')
        c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
    db.close()
