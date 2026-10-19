"""Render the local application in a fresh Chrome profile using local demo data."""
import json
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright, expect
import pymysql

LOCAL = Path(__file__).resolve().parent
credentials = json.loads((LOCAL / 'private/local-db.json').read_text(encoding='utf-8'))
connection = pymysql.connect(host='127.0.0.1', port=3306, user='campustrack_local',
                             password=credentials['app_password'], database='rfid_attendance')
with connection.cursor() as cursor:
    cursor.execute('SELECT COUNT(*) FROM users')
    assert cursor.fetchone()[0] == 25
    cursor.execute('SELECT COUNT(*) FROM courses')
    assert cursor.fetchone()[0] == 537
    cursor.execute("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.name='Teacher' AND p.code IN ('manage_attendance','manage_seat_plans','manage_attendance_policies')")
    assert cursor.fetchone()[0] == 0
connection.close()

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(channel='chrome', headless=True)
    page = browser.new_page(viewport={'width': 1440, 'height': 1000})
    production_requests = []
    errors = []
    def prevent_production(route):
        if urlsplit(route.request.url).hostname in ('jsmkj.space', 'www.jsmkj.space'):
            production_requests.append(route.request.url)
            route.abort()
        else:
            route.continue_()
    page.route('**/*', prevent_production)
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.goto('http://127.0.0.1:8080/', wait_until='domcontentloaded')
    page.locator('input[name=identifier]').fill('ADMIN-001')
    page.locator('input[name=password]').fill('Password123!')
    page.get_by_role('button', name='Sign in', exact=True).click()
    page.wait_for_url('**/admin/**')
    expect(page.locator('body')).to_contain_text('CampusTrack')
    page.screenshot(path=str(LOCAL / 'state/local-dashboard.png'), full_page=True)
    assert not production_requests, 'The local page attempted a production request'
    assert not errors, 'Browser JavaScript errors: ' + '; '.join(errors)
    browser.close()
print('Chrome local login/render passed; data survived restart and Teacher permissions match the latest migration.')
