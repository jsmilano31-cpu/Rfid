"""Regression checks for AJAX form submissions and redirect responses."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import json
import shutil
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
import uuid

WEB = Path(__file__).resolve().parent.parent
WORKSPACE = WEB.parent
TMP = WORKSPACE / '.codex-tmp' / ('form-submission-' + uuid.uuid4().hex)
TMP.mkdir(parents=True)
sys.path.insert(0, str(WORKSPACE / '.codex-tmp/audit-deps'))
from playwright.sync_api import expect, sync_playwright

php_server = None
web_server = None
try:
    # Verify that shared PHP redirects become JSON for fetch POSTs and 303 for native POSTs.
    probe = TMP / 'redirect-probe.php'
    bootstrap = (WEB / 'src/bootstrap.php').as_posix().replace("'", "\\'")
    probe.write_text(
        "<?php require '" + bootstrap + "'; flash('success', 'Probe saved.'); redirect('/done');",
        encoding='utf-8',
    )
    php_port = 8137
    php_log = (TMP / 'php.log').open('w', encoding='utf-8')
    php_server = subprocess.Popen(
        [r'C:\xampp\php\php.exe', '-S', f'127.0.0.1:{php_port}', '-t', str(TMP)],
        stdout=php_log,
        stderr=php_log,
        creationflags=subprocess.CREATE_NO_WINDOW,
    )
    time.sleep(0.6)
    fetch_request = urllib.request.Request(
        f'http://127.0.0.1:{php_port}/redirect-probe.php',
        data=b'action=probe',
        headers={'X-Requested-With': 'fetch', 'Accept': 'application/json'},
        method='POST',
    )
    with urllib.request.urlopen(fetch_request, timeout=10) as response:
        payload = json.loads(response.read())
        assert response.status == 200
        assert payload['ok'] is True
        assert payload['message'] == 'Probe saved.'
        assert payload['redirect'].endswith('/done')
        assert response.headers.get('Location') is None

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    native_request = urllib.request.Request(
        f'http://127.0.0.1:{php_port}/redirect-probe.php', data=b'action=probe', method='POST'
    )
    try:
        urllib.request.build_opener(NoRedirect).open(native_request, timeout=10)
        raise AssertionError('Native POST did not redirect')
    except urllib.error.HTTPError as error:
        assert error.code == 303
        assert error.headers.get('Location', '').endswith('/done')

    app_js = (WEB / 'public/assets/js/app.js').read_bytes()
    app_css = (WEB / 'public/assets/css/app.css').read_bytes()
    state = {'posts': [], 'refreshes': 0}

    def page(status='Active'):
        return f'''<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/app.css"></head>
<body class="app-shell" data-admin-area="1"><aside id="sidebar"></aside><div data-toast-region></div>
<main class="page-content" data-page-content><div class="page-loading-skeleton" data-page-loading-skeleton aria-hidden="true">Loading</div>
<div class="page-content-inner"><form id="save-form" method="post" action="/submit"><input type="hidden" name="action" value="toggle_course"><button id="save" type="submit">Save changes</button></form>
<form id="error-form" method="post" action="/error"><input type="hidden" name="action" value="toggle_course"><button id="error" type="submit">Delete record</button></form>
<div class="table-wrap"><table><thead><tr><th>Status</th></tr></thead><tbody><tr><td>{status}</td></tr></tbody></table></div></div></main>
<script src="/app.js"></script></body></html>'''.encode()

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_):
            pass
        def send_body(self, body, content_type='text/html; charset=utf-8', status=200):
            self.send_response(status)
            self.send_header('Content-Type', content_type)
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        def do_GET(self):
            if self.path == '/app.js': return self.send_body(app_js, 'text/javascript; charset=utf-8')
            if self.path == '/app.css': return self.send_body(app_css, 'text/css; charset=utf-8')
            if self.path == '/success':
                state['refreshes'] += 1
                return self.send_body(page('Inactive'))
            return self.send_body(page())
        def do_POST(self):
            length = int(self.headers.get('Content-Length', 0))
            self.rfile.read(length)
            state['posts'].append({'path': self.path, 'requested_with': self.headers.get('X-Requested-With')})
            if self.path == '/error':
                body = json.dumps({'ok': False, 'message': 'Linked records still exist.', 'redirect': '/'}).encode()
                return self.send_body(body, 'application/json; charset=utf-8', 422)
            body = json.dumps({'ok': True, 'message': 'Course status updated.', 'redirect': '/success'}).encode()
            return self.send_body(body, 'application/json; charset=utf-8')

    web_server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    threading.Thread(target=web_server.serve_forever, daemon=True).start()
    origin = f'http://127.0.0.1:{web_server.server_port}'

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        page_obj = browser.new_page()
        page_obj.goto(origin, wait_until='networkidle')
        page_obj.locator('#save').click()
        expect(page_obj.locator('.app-toast')).to_contain_text('Course status updated.')
        expect(page_obj.locator('.table-wrap tbody')).to_contain_text('Inactive')
        assert page_obj.url.rstrip('/') == origin
        assert not page_obj.locator('body').evaluate("body => body.classList.contains('is-page-loading')")
        expect(page_obj.locator('[data-page-loading-skeleton]')).to_have_attribute('aria-hidden', 'true')
        expect(page_obj.locator('#save')).to_be_enabled()
        assert state['posts'][0] == {'path': '/submit', 'requested_with': 'fetch'}
        assert state['refreshes'] == 1

        page_obj.locator('#error').click()
        expect(page_obj.locator('.app-toast.is-error')).to_contain_text('Linked records still exist.')
        assert page_obj.url.rstrip('/') == origin
        assert not page_obj.locator('body').evaluate("body => body.classList.contains('is-page-loading')")
        expect(page_obj.locator('#error')).to_be_enabled()
        browser.close()

    print('form-submission regression checks passed')
finally:
    if web_server:
        web_server.shutdown()
        web_server.server_close()
    if php_server:
        php_server.terminate()
        try:
            php_server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            php_server.kill()
    shutil.rmtree(TMP, ignore_errors=True)
