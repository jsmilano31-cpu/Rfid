"""End-to-end form submission checks against isolated application data."""
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
import uuid

WEB = Path(__file__).resolve().parent.parent
WORKSPACE = WEB.parent
sys.path.insert(0, str(WORKSPACE / '.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import expect, sync_playwright

name = 'form_submit_' + uuid.uuid4().hex
root = WORKSPACE / '.codex-tmp' / name
port = 8115
server = None
db = pymysql.connect(host='127.0.0.1', port=3338, user='root', autocommit=True)
try:
    for folder in ['src', 'config', 'public', 'templates']:
        shutil.copytree(WEB / folder, root / folder)
    (root / 'storage/logs').mkdir(parents=True)
    (root / 'storage/violation-evidence').mkdir(parents=True)
    (root / '.env').write_text(
        f'DB_HOST=127.0.0.1\nDB_PORT=3338\nDB_DATABASE={name}\nDB_USERNAME=root\nDB_PASSWORD=\n'
        f'APP_ENV=testing\nAPP_DEBUG=true\nAPP_URL=http://127.0.0.1:{port}\nPUBLIC_DIRECTORY=public\n',
        encoding='utf-8',
    )
    with db.cursor() as cursor:
        cursor.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4')
        cursor.execute(f'USE `{name}`')
        for source in ['schema.sql', 'seed.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB / 'database' / source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip():
                    cursor.execute(statement)

    log = (root / 'server.log').open('w', encoding='utf-8')
    server = subprocess.Popen(
        [r'C:\xampp\php\php.exe', '-n', '-d', r'extension_dir=C:\xampp\php\ext', '-d', 'extension=mbstring', '-d', 'extension=pdo_mysql', '-d', 'extension=fileinfo', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')],
        stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW,
    )
    time.sleep(0.8)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        page = browser.new_page(viewport={'width': 1400, 'height': 900})
        navigations = []
        post_responses = []
        page.on('framenavigated', lambda frame: navigations.append(frame.url) if frame == page.main_frame else None)
        page.on('response', lambda response: post_responses.append((response.status, response.headers.get('content-type', ''), response.url)) if response.request.method == 'POST' else None)

        page.goto(f'http://127.0.0.1:{port}/auth/login.php')
        page.locator('[name=identifier]').fill('ADMIN-001')
        page.locator('[name=password]').fill('Password123!')
        page.locator('button[type=submit]').click()
        page.wait_for_url('**/admin/')
        page.goto(f'http://127.0.0.1:{port}/admin/?page=academics')
        baseline_navigations = len(navigations)

        toggle = page.locator('#academics-course-catalog form').first
        original_status = toggle.locator('input[name=course_id]').input_value()
        toggle.locator('button[type=submit], button:not([type])').click()
        expect(page.locator('.app-toast').last).to_contain_text('Course status updated.')
        assert len(navigations) == baseline_navigations, navigations
        assert not page.locator('body').evaluate("body => body.classList.contains('is-page-loading')")
        expect(page.locator('[data-page-loading-skeleton]')).to_have_attribute('aria-hidden', 'true')
        assert post_responses[-1][0] == 200, post_responses[-1]
        assert 'application/json' in post_responses[-1][1]

        create = page.locator('form').filter(has=page.locator('input[name=action][value=create_course]')).first
        create.locator('input[name=course_code]').fill('ZZZ999')
        create.locator('input[name=title]').fill('AJAX Form Regression')
        create.locator('button[type=submit], button:not([type])').click()
        expect(page.locator('.app-toast').last).to_contain_text('Course created.')
        expect(page.locator('#academics-course-catalog')).to_contain_text('ZZZ999')
        assert len(navigations) == baseline_navigations
        assert post_responses[-1][0] == 200
        assert not page.locator('body').evaluate("body => body.classList.contains('is-page-loading')")

        create.locator('input[name=course_code]').fill('ZZZ999')
        create.locator('input[name=title]').fill('Duplicate AJAX Form Regression')
        create.locator('button[type=submit], button:not([type])').click()
        expect(page.locator('.app-toast.is-error').last).to_be_visible()
        assert post_responses[-1][0] == 422
        assert len(navigations) == baseline_navigations
        assert not page.locator('body').evaluate("body => body.classList.contains('is-page-loading')")
        expect(create.locator('button[type=submit], button:not([type])')).to_be_enabled()

        student = browser.new_page(viewport={'width': 1100, 'height': 800})
        student_navigations = []
        student_posts = []
        student.on('framenavigated', lambda frame: student_navigations.append(frame.url) if frame == student.main_frame else None)
        student.on('response', lambda response: student_posts.append((response.status, response.headers.get('content-type', ''))) if response.request.method == 'POST' else None)
        student.goto(f'http://127.0.0.1:{port}/auth/login.php')
        student.locator('[name=identifier]').fill('2026-0001')
        student.locator('[name=password]').fill('Password123!')
        student.locator('button[type=submit]').click()
        student.wait_for_url('**/index/')
        student.goto(f'http://127.0.0.1:{port}/index/?page=profile')
        student_baseline = len(student_navigations)
        profile_form = student.locator('form').filter(has=student.locator('input[name=action][value=update_profile]')).first
        profile_form.locator('input[name=email]').fill('ajax-profile@student.local')
        profile_form.locator('button[type=submit], button:not([type])').click()
        expect(student.locator('.app-toast').last).to_contain_text('Profile settings updated.')
        assert len(student_navigations) == student_baseline
        assert student_posts[-1][0] == 200 and 'application/json' in student_posts[-1][1]
        assert not student.locator('body').evaluate("body => body.classList.contains('is-page-loading')")
        expect(profile_form.locator('button[type=submit], button:not([type])')).to_be_enabled()

        with db.cursor() as cursor:
            cursor.execute(f'USE `{name}`')
            cursor.execute('SELECT status FROM courses WHERE id=%s', (original_status,))
            assert cursor.fetchone()[0] == 'inactive'
            cursor.execute('SELECT COUNT(*) FROM courses WHERE course_code="ZZZ999"')
            assert cursor.fetchone()[0] == 1
            cursor.execute('SELECT email FROM users WHERE id=5')
            assert cursor.fetchone()[0] == 'ajax-profile@student.local'
        browser.close()
    print('isolated form-submission integration checks passed')
finally:
    if server:
        server.terminate()
        try: server.wait(timeout=5)
        except subprocess.TimeoutExpired: server.kill()
    with db.cursor() as cursor:
        cursor.execute(f'DROP DATABASE IF EXISTS `{name}`')
    db.close()
    shutil.rmtree(root, ignore_errors=True)
