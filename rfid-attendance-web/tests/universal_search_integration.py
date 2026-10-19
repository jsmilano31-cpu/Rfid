"""Exercise universal search against an isolated seeded MariaDB database."""

from pathlib import Path
import re
import os
import shutil
import subprocess
import sys
import time
import uuid
from urllib.parse import urljoin

WEB = Path(__file__).resolve().parent.parent
WORKSPACE = WEB.parent
sys.path.insert(0, str(WORKSPACE / '.codex-tmp' / 'audit-deps'))
import pymysql
from playwright.sync_api import expect, sync_playwright

name = 'search_' + uuid.uuid4().hex
root = WORKSPACE / '.codex-tmp' / name
port = 8123
server = None
db_port = int(os.environ.get('TEST_DB_PORT', '3340'))
database = pymysql.connect(host='127.0.0.1', port=db_port, user='root', autocommit=True)

try:
    for folder in ['src', 'config', 'public', 'templates']:
        shutil.copytree(WEB / folder, root / folder)
    (root / 'storage/logs').mkdir(parents=True)
    (root / '.env').write_text(
        f'DB_HOST=127.0.0.1\nDB_PORT={db_port}\nDB_DATABASE={name}\nDB_USERNAME=root\nDB_PASSWORD=\n'
        f'APP_ENV=testing\nAPP_DEBUG=true\nAPP_URL=http://127.0.0.1:{port}\nPUBLIC_DIRECTORY=public\n',
        encoding='utf-8',
    )
    with database.cursor() as cursor:
        cursor.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4')
        cursor.execute(f'USE `{name}`')
        for source in ['schema.sql', 'seed.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB / 'database' / source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip():
                    cursor.execute(statement)
        cursor.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
        cursor.execute('INSERT INTO seat_plan_presets (id,name,visibility,created_by) VALUES (1,"Standard Grid","campus",1)')
        cursor.execute('INSERT INTO user_preferences (user_id,theme_mode) VALUES (2,"dark")')
        cursor.execute('UPDATE users SET profile_visibility="private" WHERE id=5')

    log = (root / 'server.log').open('w', encoding='utf-8')
    server = subprocess.Popen(
        ['php', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')],
        stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW,
    )
    time.sleep(0.8)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        durations = []
        anonymous = browser.new_context()
        assert anonymous.request.get(f'http://127.0.0.1:{port}/api/search.php?q=Juan').status == 401
        anonymous.close()
        for identifier, landing, role in [('ADMIN-001', '/admin/', 'Administrator'), ('TCH-001', '/index/', 'Teacher'), ('2026-0002', '/index/', 'Student')]:
            context = browser.new_context(viewport={'width': 1280, 'height': 900})
            page = context.new_page()
            errors = []
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.goto(f'http://127.0.0.1:{port}/auth/login.php')
            page.locator('[name=identifier]').fill(identifier)
            page.locator('[name=password]').fill('Password123!')
            page.locator('button[type=submit]').click()
            page.wait_for_url('**' + landing)
            expect(page.locator('[data-global-search]')).to_be_visible()
            expect(page.locator('[data-account-trigger]')).to_be_visible()
            if role == 'Teacher':
                expect(page.locator('html')).to_have_attribute('data-theme', 'dark')
            assert not any(item.is_visible() for item in page.locator('.topbar').get_by_text(role, exact=True).all())

            def lookup(query, category=None, page_number=1):
                args = {'q': query, 'context': 'schedule', 'page': page_number}
                if category:
                    args['category'] = category
                started = time.perf_counter()
                response = context.request.get(f'http://127.0.0.1:{port}/api/search.php', params=args)
                durations.append((time.perf_counter() - started) * 1000)
                assert response.status == 200, (role, query, response.status, response.text())
                return response.json()

            defaults = lookup('')
            assert defaults['groups'][0]['key'] == 'pages'
            assert defaults['groups'][0]['items']
            assert any(item['key'] == 'page:schedule' for item in lookup('sched')['groups'][0]['items']) == (role != 'Administrator')
            people = lookup('Juan', 'people')['groups'][0]['items']
            assert any(item['key'] == 'person:5' for item in people)
            if role == 'Administrator':
                first_people = lookup('2026', 'people')['groups'][0]
                second_people = lookup('2026', 'people', 2)['groups'][0]
                assert first_people['has_more']
                assert set(item['key'] for item in first_people['items']).isdisjoint(item['key'] for item in second_people['items'])
            private = next(item for item in people if item['key'] == 'person:5')
            assert ('2026-0001' in private['subtitle']) == (role == 'Administrator')
            direct = context.new_page()
            direct.goto(urljoin(f'http://127.0.0.1:{port}/', private['url']))
            expect(direct.get_by_text('Juan Dela Cruz').first).to_be_visible()
            direct.close()
            if role != 'Administrator':
                assert lookup('2026-0001', 'people')['groups'][0]['items'] == []
            rooms = lookup('Room', 'facilities')
            assert rooms['ok']
            room_matches = lookup('IT-LAB-01', 'facilities')['groups'][0]['items']
            assert any(item['key'] == 'room:1' for item in room_matches)
            room = next(item for item in room_matches if item['key'] == 'room:1')
            room_page = context.new_page()
            room_page.goto(urljoin(f'http://127.0.0.1:{port}/', room['url']))
            if role == 'Administrator':
                expect(room_page.locator('#manage-room-dialog')).to_be_visible()
            else:
                expect(room_page.locator('[data-schedule-entity-dialog]').first).to_be_visible()
            room_page.close()
            academics = lookup('BSIT', 'academics')
            assert academics['ok']
            assert lookup('Juan', 'people')['ok']
            tampered = context.request.get(f'http://127.0.0.1:{port}/api/search.php', params={'q':'Juan','context':'../../admin','category':'people'})
            assert tampered.status == 200 and tampered.json()['ok']
            presets = lookup('Standard', 'seat_plans')['groups'][0]['items']
            if role == 'Student':
                assert presets == []
            else:
                assert any(item['key'] == 'preset:1' for item in presets)
                preset = next(item for item in presets if item['key'] == 'preset:1')
                preset_page = context.new_page()
                preset_page.goto(urljoin(f'http://127.0.0.1:{port}/', preset['url']))
                expect(preset_page.locator('[data-seat-plan-preset]').first).to_have_value('1')
                preset_page.close()
            if role != 'Administrator':
                assert lookup('BA 201', 'academics')['groups'][0]['items'] == []
            activities = lookup('Foundation', 'activities')['groups'][0]['items']
            assert any(item['key'] == 'event:1' for item in activities)
            event = next(item for item in activities if item['key'] == 'event:1')
            event_page = context.new_page()
            event_page.goto(urljoin(f'http://127.0.0.1:{port}/', event['url']))
            if role == 'Administrator':
                expect(event_page.locator('#event-details-dialog')).to_be_visible()
            else:
                expect(event_page.locator('[data-universal-record="event:1"]')).to_be_visible()
            event_page.close()
            page.locator('[data-search-input]').fill('Juan')
            expect(page.locator('[data-search-panel]')).to_be_visible()
            expect(page.locator('.global-search-group').filter(has_text='People')).to_be_visible()
            if role == 'Administrator':
                page.screenshot(path=str(WORKSPACE / '.codex-tmp/universal-search-admin.png'))
            page.keyboard.press('Escape')
            expect(page.locator('[data-search-panel]')).to_be_hidden()
            page.keyboard.press('Control+k')
            expect(page.locator('[data-search-input]')).to_be_focused()
            page.locator('[data-account-trigger]').click()
            expect(page.locator('[data-account-panel]')).to_be_visible()
            assert not errors, (role, errors)
            if role == 'Administrator':
                page.locator('[data-account-trigger]').click()
                page.locator('.view-as-trigger').click()
                page.locator('#view-as-dialog form').filter(has=page.locator('input[name=role_id][value="3"]')).locator('button').click()
                page.wait_for_url('**/index/')
                assert lookup('2026-0001', 'people')['groups'][0]['items'] == []
                assert lookup('BA 201', 'academics')['groups'][0]['items'] == []
                assert not lookup('SCANNER-CBA01', 'facilities')['groups'][0]['items']
            if role == 'Student':
                page.set_viewport_size({'width': 390, 'height': 844})
                page.reload()
                page.locator('[data-search-input]').fill('Ana')
                expect(page.locator('[data-search-panel]')).to_be_visible()
                expect(page.locator('.global-search-option').filter(has_text='Ana Mae Garcia')).to_be_visible()
                page.screenshot(path=str(WORKSPACE / '.codex-tmp/universal-search-mobile.png'))
            context.close()
        browser.close()
    print(f'Universal search integration passed for Administrator, Teacher, and Student; fixture API median {sorted(durations)[len(durations)//2]:.0f} ms, max {max(durations):.0f} ms.')
finally:
    if server:
        server.terminate()
        server.wait(timeout=10)
    with database.cursor() as cursor:
        cursor.execute(f'DROP DATABASE IF EXISTS `{name}`')
    database.close()
    shutil.rmtree(root, ignore_errors=True)
