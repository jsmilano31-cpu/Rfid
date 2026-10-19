"""Validate mixed event recipients against an isolated MariaDB schema."""

from pathlib import Path
import os
import re
import subprocess
import sys
import time
import uuid

WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp' / 'audit-deps'))
import pymysql
from playwright.sync_api import expect, sync_playwright

name = 'event_audience_' + uuid.uuid4().hex
database = pymysql.connect(host='127.0.0.1', port=3339, user='root', autocommit=True)
web_user = 'event_' + uuid.uuid4().hex[:12]
web_password = uuid.uuid4().hex
server = None

PHP = r'''
require getcwd() . '/src/bootstrap.php';
$_ENV['DB_PASSWORD'] = '';
use App\Core\Database;
use App\Services\CalendarService;
use App\Services\EventAudienceService;
use App\Services\EventService;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$pdo = Database::connection();
$targets = [
    ['type'=>'section', 'id'=>1, 'roleFilter'=>'teacher'],
    ['type'=>'user', 'id'=>5, 'roleFilter'=>'any'],
    ['type'=>'user', 'id'=>5, 'roleFilter'=>'any'],
];
$data = [
    'title'=>'Mixed Audience Test', 'description'=>'', 'event_date'=>'2026-10-10',
    'start_time'=>'09:00', 'end_time'=>'11:00', 'venue'=>'Test Hall',
    'targets_json'=>json_encode($targets), 'attendance_required'=>'on'
];
$eventId = EventService::create($data, 1);
$users = array_map('intval', $pdo->query('SELECT user_id FROM event_participants WHERE event_id=' . $eventId . ' ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN));
check($users === [2,3,5], 'Mixed audience did not select only section teachers and the chosen student: ' . json_encode($users));
$groups = EventAudienceService::targetsByEventIds([$eventId]);
check(count($groups[$eventId]) === 2, 'Duplicate targets were not removed.');
check(count(CalendarService::entries()) > 0, 'Calendar entries unavailable.');
$calendar = CalendarService::eventById($eventId);
check(count($calendar['customTags']['targets']) === 2, 'Calendar editor lost recipients.');
check(!array_column(EventService::feed(6), 'id') || !in_array($eventId, array_column(EventService::feed(6), 'id'), true), 'Uninvited student can see event.');
check(in_array($eventId, array_column(EventService::feed(5), 'id'), true), 'Invited student cannot see event.');
$data['targets_json'] = json_encode([
    ['type'=>'section', 'id'=>1, 'roleFilter'=>'teacher'],
    ['type'=>'user', 'id'=>6, 'roleFilter'=>'any'],
]);
EventService::update($eventId, $data, 1);
$users = array_map('intval', $pdo->query('SELECT user_id FROM event_participants WHERE event_id=' . $eventId . ' ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN));
check($users === [2,3,6], 'Updating recipients did not replace the participant union: ' . json_encode($users));
$pdo->prepare('INSERT INTO event_attendance(event_id,user_id,status,source) VALUES(:event,:user,"Attended","manual")')->execute(['event'=>$eventId,'user'=>6]);
$data['targets_json'] = json_encode([['type'=>'all_teachers','id'=>null,'roleFilter'=>'any']]);
EventService::update($eventId, $data, 1);
$users = array_map('intval', $pdo->query('SELECT user_id FROM event_participants WHERE event_id=' . $eventId . ' ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN));
check($users === [2,3,4,6], 'Historical attendance participant was not retained: ' . json_encode($users));
try { EventAudienceService::normalize(['targets_json'=>json_encode([['type'=>'user','id'=>999999]])]); throw new RuntimeException('Invalid person accepted.'); }
catch (DomainException $e) { /* expected */ }
echo "Mixed audience, updates, history, and calendar passed.\n";
'''

try:
    with database.cursor() as cursor:
        cursor.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4')
        cursor.execute(f'USE `{name}`')
        for source in ['schema.sql', 'seed.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB / 'database' / source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip():
                    cursor.execute(statement)
        cursor.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
        cursor.execute(f"CREATE USER '{web_user}'@'127.0.0.1' IDENTIFIED BY '{web_password}'")
        cursor.execute(f"GRANT ALL ON `{name}`.* TO '{web_user}'@'127.0.0.1'")
    env = os.environ.copy()
    env.update({'DB_HOST':'127.0.0.1', 'DB_PORT':'3339', 'DB_DATABASE':name, 'DB_USERNAME':'root', 'DB_PASSWORD':'', 'APP_ENV':'testing', 'APP_DEBUG':'true'})
    result = subprocess.run(['php', '-r', PHP], cwd=WEB, env=env, capture_output=True, text=True)
    assert result.returncode == 0, result.stdout + result.stderr
    print(result.stdout.strip())
    env.update({'DB_USERNAME': web_user, 'DB_PASSWORD': web_password, 'APP_URL': 'http://127.0.0.1:8124'})
    server = subprocess.Popen(['php', '-S', '127.0.0.1:8124', '-t', str(WEB / 'public')], cwd=WEB, env=env,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(0.8)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        context = browser.new_context(viewport={'width': 1360, 'height': 900})
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.goto('http://127.0.0.1:8124/auth/login.php')
        page.locator('[name=identifier]').fill('ADMIN-001')
        page.locator('[name=password]').fill('Password123!')
        page.locator('button[type=submit]').click()
        page.wait_for_url('**/admin/')
        page.goto('http://127.0.0.1:8124/admin/?page=events')
        form = page.locator('form').filter(has=page.locator('[name="action"][value="create_event"]')).first
        builder = form.locator('[data-event-target-builder]')
        expect(builder.locator('.event-target-chip')).to_have_count(1)
        builder.locator('.event-target-chip-remove').click()
        builder.locator('[data-event-target-type]').select_option('section')
        builder.locator('[data-event-target-entity]').evaluate('node => node._choicesInstance.setChoiceByValue("1")')
        builder.locator('[data-event-target-role]').select_option('teacher')
        builder.locator('[data-event-target-add]').click()
        expect(builder.locator('.event-target-chip')).to_have_count(1)
        builder.locator('[data-event-target-type]').select_option('user')
        builder.locator('[data-event-target-person-search]').fill('2026-0001')
        expect(builder.locator('.event-target-person-option')).to_have_count(1)
        builder.locator('.event-target-person-option').click()
        expect(builder.locator('.event-target-chip')).to_have_count(2)
        targets = builder.locator('[name=targets_json]').input_value()
        assert '"roleFilter":"teacher"' in targets and '"type":"user"' in targets
        builder.scroll_into_view_if_needed()
        builder.screenshot(path=str(WEB.parent / '.codex-tmp' / 'event-recipient-builder.png'))
        page.set_viewport_size({'width': 390, 'height': 844})
        builder.scroll_into_view_if_needed()
        assert builder.bounding_box()['width'] < 390
        builder.screenshot(path=str(WEB.parent / '.codex-tmp' / 'event-recipient-builder-mobile.png'))
        page.screenshot(path=str(WEB.parent / '.codex-tmp' / 'event-recipient-page-mobile.png'))
        page.set_viewport_size({'width': 1360, 'height': 900})
        form.locator('[name=title]').fill('Browser Audience Test')
        form.locator('[name=venue]').fill('Test Hall')
        for field, value in [('event_date', '2026-10-21'), ('start_time', '09:00'), ('end_time', '11:00')]:
            form.locator(f'[name="{field}"]').evaluate('(node, value) => node._flatpickr.setDate(value, true)', value)
        invalid = form.evaluate('form => [...form.elements].filter(field => !field.checkValidity()).map(field => field.outerHTML)')
        assert not invalid, invalid
        with page.expect_response(lambda response: response.request.method == 'POST' and '/admin/' in response.url, timeout=20000) as saved:
            form.get_by_role('button', name='Create event').click()
        assert saved.value.status == 200, saved.value.status
        with database.cursor() as cursor:
            cursor.execute('SELECT id FROM events WHERE title=%s ORDER BY id DESC LIMIT 1', ('Browser Audience Test',))
            created = cursor.fetchone()
            assert created, 'Browser form did not create an event.'
            cursor.execute('SELECT user_id FROM event_participants WHERE event_id=%s ORDER BY user_id', (created[0],))
            assert [row[0] for row in cursor.fetchall()] == [2, 3, 5]
        expect(builder.locator('.event-target-chip')).to_have_count(1)
        expect(builder.locator('.event-target-chip')).to_contain_text('All students')
        assert not errors, errors
        context.close()
        browser.close()
    print('Event recipient chip and person lookup UI passed.')
finally:
    if server:
        server.terminate()
        server.wait(timeout=10)
    with database.cursor() as cursor:
        cursor.execute(f'DROP DATABASE IF EXISTS `{name}`')
        cursor.execute(f"DROP USER IF EXISTS '{web_user}'@'127.0.0.1'")
    database.close()
