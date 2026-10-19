"""Deletion-conflict integration and browser checks against an isolated database."""
from pathlib import Path
import json
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


name = 'deletion_conflicts_' + uuid.uuid4().hex
root = WORKSPACE / '.codex-tmp' / name
port = 8114
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
        password_hash = '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge'
        for user_id in range(26, 31):
            cursor.execute(
                'INSERT INTO users (id,school_id,first_name,last_name,email,password_hash,role_id,status) VALUES (%s,%s,%s,%s,%s,%s,3,"active")',
                (user_id, f'PAGE-{user_id}', 'Pagination', str(user_id), f'page-{user_id}@test.local', password_hash),
            )
            cursor.execute('INSERT INTO user_roles (user_id,role_id) VALUES (%s,3)', (user_id,))
        cursor.execute('INSERT INTO seat_plan_presets (id,name,row_count,column_count,visibility,created_by) VALUES (99,"Disposable preset",2,2,"personal",1)')
        cursor.execute('INSERT INTO teacher_time_off (id,teacher_id,starts_on,ends_on,reason,status,created_by) VALUES (99,2,CURRENT_DATE + INTERVAL 30 DAY,CURRENT_DATE + INTERVAL 31 DAY,"Conference","active",2)')
        cursor.execute('INSERT INTO disciplinary_violations (id,student_id,issued_by,violation_group,specific_violation,classification,is_progressive,incident_date,incident_time,location,incident_description) VALUES (99,5,2,"Conduct","Fixture violation","Category C",0,CURRENT_DATE,"09:00:00","Test room","Fixture only")')
        cursor.execute('INSERT INTO rooms (id,room_code,room_name,status) VALUES (99,"DELETE-ME","No dependencies","active")')

    probe = root / 'conflict-probe.php'
    probe.write_text(
        '''<?php
require __DIR__ . '/src/bootstrap.php';
use App\\Services\\DeletionConflictService;
$_SESSION['user_id']=1;
$cases=['department'=>1,'program'=>1,'course'=>1,'section'=>1,'offering'=>1,'room'=>1,'user'=>2,'role'=>3,'event'=>1,'recollection'=>1,'device'=>1,'preset'=>99];
$out=[];
foreach($cases as $type=>$id){$out[$type]=DeletionConflictService::inspect($type,$id);}
echo json_encode($out,JSON_THROW_ON_ERROR);
''',
        encoding='utf-8',
    )
    probe_result = subprocess.run(
        [r'C:\xampp\php\php.exe', '-n', '-d', r'extension_dir=C:\xampp\php\ext', '-d', 'extension=mbstring', '-d', 'extension=pdo_mysql', str(probe)],
        cwd=root, capture_output=True, text=True, check=True,
    )
    conflicts = json.loads(probe_result.stdout)
    for conflict_type in ['department', 'program', 'course', 'section', 'offering', 'room', 'user', 'role', 'event', 'recollection']:
        assert conflicts[conflict_type]['total'] > 0, conflict_type
        assert conflicts[conflict_type]['groups'], conflict_type
    assert conflicts['device']['total'] == 0
    assert conflicts['preset']['total'] == 0
    user_groups = {group['key'] for group in conflicts['user']['groups']}
    assert {'violations', 'time_off'} <= user_groups
    role_group = conflicts['role']['groups'][0]
    assert role_group['total'] == 23
    assert role_group['pages'] == 2
    assert len(role_group['records']) == 20
    room_groups = {group['key']: group for group in conflicts['room']['groups']}
    assert 'focus_schedule=' in room_groups['schedules']['records'][0]['url']
    offering_groups = {group['key']: group for group in conflicts['offering']['groups']}
    assert '&student_id=' in offering_groups['attendance_records']['records'][0]['url']
    user_group_map = {group['key']: group for group in conflicts['user']['groups']}
    assert '/admin/deletion-record.php?' in user_group_map['violations']['records'][0]['url'] or user_group_map['violations']['records'][0]['url'] is None

    log = (root / 'server.log').open('w', encoding='utf-8')
    server = subprocess.Popen(
        [r'C:\xampp\php\php.exe', '-n', '-d', r'extension_dir=C:\xampp\php\ext', '-d', 'extension=mbstring', '-d', 'extension=pdo_mysql', '-d', 'extension=fileinfo', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public')],
        stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW,
    )
    time.sleep(1)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        unauthenticated = browser.new_context()
        response = unauthenticated.request.get(f'http://127.0.0.1:{port}/admin/deletion-conflicts.php?type=room&id=1')
        assert response.status == 401
        unauthenticated.close()

        page = browser.new_page(viewport={'width': 1280, 'height': 900})
        page.goto(f'http://127.0.0.1:{port}/auth/login.php')
        page.locator('[name=identifier]').fill('ADMIN-001')
        page.locator('[name=password]').fill('Password123!')
        page.locator('button[type=submit]').click()
        page.goto(f'http://127.0.0.1:{port}/admin/?page=rooms&manage=room&entity_id=1')
        room_dialog = page.locator('[data-facility-dialog=room]')
        expect(room_dialog).to_be_visible()
        room_dialog.locator('[data-delete-facility=room]').click()
        page.get_by_role('button', name='Delete permanently').click()

        blocked = page.locator('.deletion-conflict-dialog')
        expect(blocked).to_be_visible()
        expect(blocked.get_by_role('heading', name='Linked records must be resolved')).to_be_visible()
        expect(blocked).to_contain_text('IT-LAB-01')
        expect(blocked).to_contain_text('RFID scanners')
        expect(blocked).to_contain_text('Class offerings')
        expect(blocked).to_contain_text('Class schedules')
        first_link = blocked.locator('.deletion-conflict-record[href]').first
        assert first_link.get_attribute('target') == '_blank'
        assert first_link.get_attribute('rel') == 'noopener'
        assert room_dialog.get_attribute('open') is not None
        blocked.get_by_role('button', name='Back to record', exact=True).last.click()
        expect(blocked).to_be_hidden()
        expect(room_dialog).to_be_visible()

        page.evaluate('''async () => {
            const response = await fetch('/admin/deletion-conflicts.php?type=role&id=3');
            const payload = await response.json();
            payload.code = 'deletion_conflict';
            window.showDeletionConflict(payload);
        }''')
        expect(blocked).to_be_visible()
        records = blocked.locator('[data-conflict-group=users] .deletion-conflict-record')
        assert records.count() == 20
        blocked.locator('[data-conflict-group=users] [data-conflict-more]').click()
        expect(records).to_have_count(23)
        ids = records.evaluate_all('(rows) => rows.map(row => row.textContent.trim())')
        assert len(ids) == len(set(ids))

        page.locator('html').evaluate("node => node.dataset.theme = 'dark'")
        background = records.first.evaluate('node => getComputedStyle(node).backgroundColor')
        foreground = records.first.evaluate('node => getComputedStyle(node).color')
        assert background not in ('rgb(255, 255, 255)', 'rgba(0, 0, 0, 0)')
        assert foreground != background
        page.set_viewport_size({'width': 390, 'height': 760})
        box = blocked.bounding_box()
        assert box and box['x'] >= 0 and box['x'] + box['width'] <= 390

        blocked.get_by_role('button', name='Refresh conflicts').click()
        expect(blocked.locator('[data-conflict-group=users] .deletion-conflict-record')).to_have_count(20)
        detail_response = page.context.request.get(f'http://127.0.0.1:{port}/admin/deletion-record.php?type=violation&id=99')
        assert detail_response.status == 200
        assert 'Fixture violation' in detail_response.text()
        missing_response = page.context.request.get(f'http://127.0.0.1:{port}/admin/deletion-record.php?type=violation&id=999999')
        assert missing_response.status == 404
        with db.cursor() as cursor:
            cursor.execute(f'USE `{name}`')
            cursor.execute('SELECT COUNT(*) FROM rooms WHERE id=1')
            assert cursor.fetchone()[0] == 1

        csrf = page.locator('input[name=_token]').first.get_attribute('value')
        result = page.evaluate('''async ({csrf}) => {
            const body = new FormData(); body.set('_token', csrf); body.set('action', 'delete_room'); body.set('room_id', '99');
            const response = await fetch('/admin/facility.php', {method:'POST', headers:{Accept:'application/json'}, body});
            return {status:response.status, payload:await response.json()};
        }''', {'csrf': csrf})
        assert result['status'] == 200 and result['payload']['ok'] is True
        with db.cursor() as cursor:
            cursor.execute(f'USE `{name}`')
            cursor.execute('SELECT COUNT(*) FROM rooms WHERE id=99')
            assert cursor.fetchone()[0] == 0
        browser.close()

    print('Deletion conflict integration and UI checks passed.')
finally:
    if server:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
    with db.cursor() as cursor:
        cursor.execute(f'DROP DATABASE IF EXISTS `{name}`')
    db.close()
    shutil.rmtree(root, ignore_errors=True)
