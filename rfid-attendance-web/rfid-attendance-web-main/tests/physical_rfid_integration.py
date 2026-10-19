from pathlib import Path
import datetime, http.cookiejar, json, re, shutil, subprocess, sys, time, urllib.error, urllib.parse, urllib.request, uuid

WEB = Path(__file__).resolve().parent.parent
WORKSPACE = WEB.parent
sys.path.insert(0, str(WORKSPACE / '.codex-tmp' / 'audit-deps'))
import pymysql

name = 'rfid_physical_test_' + uuid.uuid4().hex
test = WORKSPACE / '.codex-tmp' / name
for folder in ['src', 'config', 'public', 'templates']:
    shutil.copytree(WEB / folder, test / folder)
(test / 'storage' / 'logs').mkdir(parents=True)
(test / '.env').write_text(f'DB_HOST=127.0.0.1\nDB_PORT=3307\nDB_DATABASE={name}\nDB_USERNAME=root\nDB_PASSWORD=\nAPP_ENV=testing\nAPP_DEBUG=false\nAPP_URL=http://127.0.0.1:8098\nPUBLIC_DIRECTORY=public\n')

db = pymysql.connect(host='127.0.0.1', port=3307, user='root', autocommit=True)
server = None
try:
    with db.cursor() as cursor:
        cursor.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4')
        cursor.execute(f'USE `{name}`')
        cursor.execute('SET FOREIGN_KEY_CHECKS=0')
        for source in ['schema.sql', 'seed.sql']:
            for statement in (WEB / 'database' / source).read_text(encoding='utf-8-sig').split(';'):
                if statement.strip(): cursor.execute(statement)
        cursor.execute('SET FOREIGN_KEY_CHECKS=1')
        device_key = 'integration-device-key'
        hashed = subprocess.check_output(['php', '-r', f'echo password_hash("{device_key}", PASSWORD_DEFAULT);'], text=True)
        cursor.execute('UPDATE devices SET api_key_hash=%s WHERE id=1', (hashed,))

    log = (test / 'server.log').open('w')
    server = subprocess.Popen(['php', '-S', '127.0.0.1:8098', '-t', str(test / 'public')], stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(1)
    jar = http.cookiejar.CookieJar()
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, fp, code, message, headers, newurl): return None
    browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())
    page = browser.open('http://127.0.0.1:8098/auth/login.php').read().decode()
    token = re.search(r'name="_token" value="([a-f0-9]+)"', page).group(1)
    try:
        browser.open(urllib.request.Request('http://127.0.0.1:8098/auth/login.php', data=urllib.parse.urlencode({'_token': token, 'identifier': 'ADMIN-001', 'password': 'Password123!'}).encode()))
    except urllib.error.HTTPError as redirect:
        assert redirect.code == 302

    def form(path, values, expected=200):
        request = urllib.request.Request('http://127.0.0.1:8098/' + path, data=urllib.parse.urlencode({'_token': token, **values}).encode(), headers={'Accept': 'application/json'})
        try: response = browser.open(request)
        except urllib.error.HTTPError as error: response = error
        body = json.loads(response.read())
        assert response.status == expected, (path, response.status, expected, body)
        return body

    def scan(uid, key=device_key, expected=200):
        request = urllib.request.Request('http://127.0.0.1:8098/iot/scan.php', data=json.dumps({'uid': uid}).encode(), headers={'Content-Type': 'application/json', 'X-Device-Code': 'SCANNER-IT01', 'X-Device-Key': key})
        try: response = urllib.request.urlopen(request)
        except urllib.error.HTTPError as error: response = error
        body = json.loads(response.read())
        assert response.status == expected, (response.status, expected, body)
        return body

    def scanner_status():
        request = urllib.request.Request('http://127.0.0.1:8098/iot/status.php', headers={
            'Accept': 'application/json', 'X-Device-Code': 'SCANNER-IT01', 'X-Device-Key': device_key,
        })
        return json.loads(urllib.request.urlopen(request).read())

    created = form('admin/facility.php', {
        'action': 'create_device', 'device_code': 'SCANNER-NEW', 'name': 'New scanner', 'room_id': '',
    }, 201)
    assert created['device']['device_code'] == 'SCANNER-NEW'
    assert len(created['credentials']['api_key']) == 64
    duplicate_code = form('admin/facility.php', {
        'action': 'create_device', 'device_code': 'SCANNER-NEW', 'name': 'Duplicate scanner', 'room_id': '',
    }, 422)
    assert 'already exists' in duplicate_code['message']
    shared_room = form('admin/facility.php', {
        'action': 'create_device', 'device_code': 'SCANNER-ROOM', 'name': 'Occupied room scanner', 'room_id': 1,
    }, 201)
    assert shared_room['device']['room_id'] == 1

    fallback_request = urllib.request.Request(
        'http://127.0.0.1:8098/admin/?page=rooms',
        data=urllib.parse.urlencode({
            '_token': token, 'action': 'create_device', 'device_code': 'SCANNER-FALLBACK',
            'name': 'Fallback scanner', 'room_id': '',
        }).encode(),
    )
    try: browser.open(fallback_request)
    except urllib.error.HTTPError as redirect: assert redirect.code == 302
    fallback_page = browser.open('http://127.0.0.1:8098/admin/?page=rooms').read().decode()
    assert 'data-scanner-credentials-dialog data-pending="1"' in fallback_page
    assert 'SCANNER-FALLBACK' in fallback_page

    scan('ABCDEF12', 'wrong-key', 401)
    started = form('admin/rfid.php', {'action': 'start', 'user_id': 6, 'device_id': 1})['registration']
    assert scan('ABCDEF12')['mode'] == 'registration'
    captured = json.loads(browser.open('http://127.0.0.1:8098/admin/rfid.php?token=' + started['request_token']).read())['registration']
    assert captured['status'] == 'captured' and captured['uid'] == 'ABCDEF12' and not captured['requires_reassignment']
    form('admin/rfid.php', {'action': 'complete', 'request_token': started['request_token'], 'allow_reassignment': 0})

    started = form('admin/rfid.php', {'action': 'start', 'user_id': 7, 'device_id': 1})['registration']
    assert scan('ABCDEF12')['mode'] == 'registration'
    captured = json.loads(browser.open('http://127.0.0.1:8098/admin/rfid.php?token=' + started['request_token']).read())['registration']
    assert captured['requires_reassignment'] and captured['current_owner']['id'] == 6
    form('admin/rfid.php', {'action': 'complete', 'request_token': started['request_token'], 'allow_reassignment': 0}, 422)
    form('admin/rfid.php', {'action': 'complete', 'request_token': started['request_token'], 'allow_reassignment': 1})

    now = time.localtime()
    day = time.strftime('%A', now)
    upcoming = datetime.datetime.now() + datetime.timedelta(minutes=15)
    upcoming_end = upcoming + datetime.timedelta(minutes=30)
    if upcoming.date() == upcoming_end.date():
        with db.cursor() as cursor:
            cursor.execute(f'USE `{name}`')
            cursor.execute('DELETE FROM department_days_off WHERE day_of_week=%s', (day,))
            cursor.execute('UPDATE class_schedules SET day_of_week=%s,start_time=%s,end_time=%s,room_id=1 WHERE class_offering_id=1', (
                day, upcoming.strftime('%H:%M:%S'), upcoming_end.strftime('%H:%M:%S'),
            ))
        early = scan('ABCDEF12', expected=422)
        assert 'Attendance is not open yet' in early['message'] and 'starts at' in early['message']
        opens_soon = datetime.datetime.now() + datetime.timedelta(minutes=5)
        opens_soon_end = opens_soon + datetime.timedelta(minutes=30)
        with db.cursor() as cursor:
            cursor.execute(f'USE `{name}`')
            cursor.execute('UPDATE class_schedules SET start_time=%s,end_time=%s WHERE class_offering_id=1', (
                opens_soon.strftime('%H:%M:%S'), opens_soon_end.strftime('%H:%M:%S'),
            ))
        early_status = scanner_status()
        assert early_status['current_class']['attendance_phase'] == 'early'
        assert scan('ABCDEF12')['mode'] == 'attendance'

    with db.cursor() as cursor:
        cursor.execute(f'USE `{name}`')
        cursor.execute('DELETE FROM department_days_off WHERE day_of_week=%s', (day,))
        cursor.execute('UPDATE class_schedules SET day_of_week=%s,start_time="00:00:00",end_time="23:59:59",room_id=1 WHERE class_offering_id=1', (day,))
    status = scanner_status()
    assert status['current_class']['class_offering_id'] == 1
    assert status['current_class']['day'] == day
    assert status['current_class']['start_time'] == '00:00'
    assert 'next_class' in status
    attendance = scan('ABCDEF12')
    assert attendance['mode'] == 'attendance' and attendance['student']['school_id']
    with db.cursor() as cursor:
        cursor.execute(f'USE `{name}`')
        cursor.execute('SELECT source,student_id FROM attendance_records WHERE class_offering_id=1 AND attendance_date=CURRENT_DATE ORDER BY id DESC LIMIT 1')
        record = cursor.fetchone()
        assert record == ('rfid', 7), record
        cursor.execute('SELECT COUNT(*) FROM device_scan_logs WHERE device_id=1')
        assert cursor.fetchone()[0] >= 3
    print('PASS: physical registration, parallel room scanners, early check-in routing, attendance and device auditing')
finally:
    if server:
        server.terminate()
        server.wait(timeout=10)
    with db.cursor() as cursor: cursor.execute(f'DROP DATABASE IF EXISTS `{name}`')
    db.close()
