"""Check the running local web/API and demo roles without contacting production."""
import http.cookiejar
import json
from pathlib import Path
import re
import urllib.error
import urllib.parse
import urllib.request

LOCAL = Path(__file__).resolve().parent
WEB = 'http://127.0.0.1:8080'
API = 'http://127.0.0.1:8097'

class LocalRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        target = urllib.parse.urlsplit(newurl)
        if target.hostname != '127.0.0.1' or target.port not in (8080, 8097):
            raise RuntimeError('External redirect blocked during local checks')
        return super().redirect_request(req, fp, code, msg, headers, newurl)

def client():
    return urllib.request.build_opener(LocalRedirects(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

results = []
def call(browser, url, expected=200, fields=None, payload=None, token=None):
    assert urllib.parse.urlsplit(url).hostname == '127.0.0.1'
    headers = {}
    body = None
    if fields is not None:
        body = urllib.parse.urlencode(fields).encode()
    if payload is not None:
        body = json.dumps(payload).encode()
        headers['Content-Type'] = 'application/json'
    if token:
        headers['X-CSRF-Token'] = token
    request = urllib.request.Request(url, body, headers)
    try:
        response = browser.open(request, timeout=60)
    except urllib.error.HTTPError as error:
        response = error
    text = response.read().decode('utf-8')
    if response.status != expected:
        raise AssertionError(f'{url}: HTTP {response.status}, expected {expected}')
    if any(marker in text for marker in ('Fatal error:', 'SQLSTATE[', '<b>Warning</b>', 'Uncaught PDOException')):
        raise AssertionError('PHP/database error rendered at ' + url)
    results.append({'url': url, 'status': response.status})
    return text, response.url

browser = client()
login, _ = call(browser, WEB + '/')
assert 'CampusTrack' in login and 'Welcome back' in login
call(browser, WEB + '/assets/css/app.css')
call(browser, WEB + '/.env', 403)
call(browser, WEB + '/config/database.php', 404)

for identifier, area in [('ADMIN-001', 'admin'), ('TCH-001', 'index'), ('2026-0001', 'index'), ('STF-001', 'admin'), ('EVT-001', 'admin')]:
    browser = client()
    login, _ = call(browser, WEB + '/auth/login.php')
    csrf = re.search(r'name="_token" value="([a-f0-9]+)"', login).group(1)
    dashboard, destination = call(browser, WEB + '/auth/login.php', fields={'_token': csrf, 'identifier': identifier, 'password': 'Password123!'})
    assert '/auth/login.php' not in destination, 'Demo login failed: ' + identifier
    assert 'CampusTrack' in dashboard
    if identifier == 'ADMIN-001':
        for page in ['users', 'academics', 'events', 'recollections', 'grades', 'compliance', 'analytics', 'audit', 'messages']:
            call(browser, WEB + '/admin/index.php?page=' + page)
    if identifier in ('TCH-001', '2026-0001'):
        for page in ['attendance', 'grades', 'schedule', 'events', 'recollections', 'compliance', 'messages', 'profile']:
            call(browser, WEB + '/index/index.php?page=' + page)
    print('Local web login/pages passed: ' + identifier, flush=True)

browser = client()
health, _ = call(browser, API + '/health.php')
assert json.loads(health)['data']['service'] == 'campustrack-student'
call(browser, API + '/student/portal.php', 401)
for identifier, endpoint in [('2026-0001', 'student/portal.php'), ('TCH-001', 'teacher/portal.php')]:
    browser = client()
    session, _ = call(browser, API + '/auth/session.php')
    csrf = json.loads(session)['data']['csrf_token']
    call(browser, API + '/auth/login.php', payload={'identifier': identifier, 'password': 'Password123!'}, token=csrf)
    portal, _ = call(browser, API + '/' + endpoint)
    assert 'data' in json.loads(portal)
    print('Local API login/portal passed: ' + identifier, flush=True)

report = {'passed': True, 'http_checks': len(results), 'results': results}
(LOCAL / 'state/http-checks.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print(f'Passed {len(results)} local HTTP checks.', flush=True)
