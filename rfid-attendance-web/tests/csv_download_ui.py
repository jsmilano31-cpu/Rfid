"""CSV downloads must not hide the current page behind the navigation skeleton."""
from pathlib import Path
import json, mimetypes, re, subprocess, sys, tempfile, time
from urllib.parse import parse_qs, urlparse

WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp/audit-deps'))
from playwright.sync_api import sync_playwright, expect

with tempfile.TemporaryDirectory(prefix='campustrack-csv-ui-') as temporary:
    probe = Path(temporary) / 'analytics.php'
    probe.write_text("""<?php
namespace App\Services { final class AnalyticsService { public static function sections(): array { return ['resources'=>'Scheduling & resources']; } } }
namespace {
define('BASE_PATH', %s);
function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function url(string $v): string { return $v; }
$_SERVER['REQUEST_URI']='/admin/?page=analytics';
require BASE_PATH.'/templates/partials/analytics_workspace.php';
}
""" % ("'" + WEB.as_posix().replace("'", "\\'") + "'"), encoding='utf-8')
    rendered = subprocess.run(['php', str(probe)], capture_output=True, encoding='utf-8', check=True)
    assert not rendered.stderr, rendered.stderr
    # Keep the real Compliance link's download/navigation attributes too.
    compliance = re.search(r'<a[^>]*data-compliance-export[^>]*?(?=href=)',
                           (WEB / 'templates/admin/compliance.php').read_text(encoding='utf-8')).group(0)
    compliance += 'href="/admin/?page=compliance&export=compliance_csv"><svg viewBox="0 0 24 24"><path d="M12 3v12"/></svg>Export compliance CSV</a>'
    html = ('<!doctype html><html data-theme="dark"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/analytics.css">'
            '<script defer src="/assets/js/app.js"></script><script defer src="/assets/js/analytics.js"></script></head>'
            '<body><main class="page-content" data-page-content><div class="page-loading-skeleton" data-page-loading-skeleton aria-hidden="true">Loading</div>'
            '<div class="page-content-inner">' + rendered.stdout + compliance +
            '<a href="/index/?page=attendance" id="normal-navigation">Attendance page</a></div></main></body></html>')
    columns = {'label':'Subject · section', 'day_of_week':'Day', 'start_time':'Starts', 'end_time':'Ends', 'room_code':'Room'}
    rows = [{'label':'ITL303 · BSIT 4B', 'day_of_week':'Tuesday', 'start_time':'12:30:00', 'end_time':'13:30:00', 'room_code':'PS 25'}]
    filters = dict(from_='2026-08-01', to='2026-10-05', semester_id=0, department_id=0, program_id=0, section_id=0, teacher_id=0, course_id=0, offering_id=0)
    filters['from'] = filters.pop('from_')
    panel = dict(key='schedule-detail', title='Class schedules', description='Current weekly meetings.', columns=columns,
                 rows=rows, formula='Recorded class meeting times.', chart={'kind':'table', 'series':{}}, sample_size=1, coverage='Current timetable')
    payload = dict(ok=True, section='resources', sections={'resources':'Scheduling & resources'}, filters=filters,
                   options={key:[] for key in filters if key.endswith('_id')}, panels=[panel], cards=[], insights=[],
                   metadata=dict(scope='Personal records', built_at='2026-10-06 08:00:00', calculated_at='2026-10-06T08:00:00+08:00',
                                 timezone='Asia/Manila', stale=False, history_note='Recorded eligibility only.', version='20261003.3', worker_error=None))

    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        page = browser.new_page(viewport={'width':1440, 'height':1100})
        errors, loaders = [], []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.on('console', lambda message: loaders.append(message.text) if message.text == 'navigation-loader-shown' else None)
        page.add_init_script("""document.addEventListener('DOMContentLoaded',()=>{
            new MutationObserver(()=>{if(document.body.classList.contains('is-page-loading'))console.log('navigation-loader-shown');})
                .observe(document.body,{attributes:true,attributeFilter:['class']});
        });""")
        def route(request):
            parsed = urlparse(request.request.url)
            query = parse_qs(parsed.query)
            if query.get('mode') == ['export'] or query.get('export') == ['compliance_csv']:
                time.sleep(.35)  # A slower attachment response must leave the page visible too.
                request.fulfill(body='\ufeffSubject,Room\r\nITL303,PS 25\r\n', content_type='text/csv; charset=utf-8',
                                headers={'Content-Disposition':'attachment; filename="campustrack-fixture.csv"'})
            elif parsed.path == '/api/analytics.php':
                data = dict(ok=True, columns=columns, rows=rows, total=1, page=1, has_more=False) if query.get('mode') == ['records'] else payload
                request.fulfill(body=json.dumps(data), content_type='application/json')
            elif parsed.path == '/index/':
                request.fulfill(body='<html><body><h1>Attendance page</h1></body></html>', content_type='text/html')
            elif parsed.path == '/admin/':
                request.fulfill(body=html, content_type='text/html')
            elif parsed.path.startswith('/assets/'):
                target = WEB / 'public' / parsed.path.lstrip('/')
                if target.is_file():
                    if parsed.path.endswith('.css'):
                        request.fulfill(body=re.sub(r'^@import.*$', '', target.read_text(encoding='utf-8'), flags=re.M), content_type='text/css')
                    else:
                        request.fulfill(body=target.read_bytes(), content_type=mimetypes.guess_type(parsed.path)[0] or 'application/octet-stream')
                else:
                    request.fulfill(status=404, body='Not found')
            else:
                request.abort()
        page.route('**/*', route)
        page.goto('https://fixture.test/admin/?page=analytics&section=resources', wait_until='load')
        expect(page.locator('[data-analytics-export]')).to_have_attribute('href', re.compile('mode=export'))
        original = page.url
        def download_without_navigation(link):
            with page.expect_download() as downloaded:
                link.click()
            assert downloaded.value.suggested_filename == 'campustrack-fixture.csv'
            assert page.url == original
            assert not loaders, loaders
            assert not page.locator('body').evaluate("e=>e.classList.contains('is-page-loading')")
            expect(page.locator('[data-page-loading-skeleton]')).to_have_attribute('aria-hidden', 'true')
            expect(page.locator('[data-analytics]')).to_be_visible()
            assert page.locator('[data-page-content]').get_attribute('aria-busy') is None
        download_without_navigation(page.locator('[data-analytics-export]'))
        page.get_by_role('button', name='Inspect records').click()
        expect(page.locator('[data-analytics-record-export]')).to_have_attribute('href', re.compile('mode=export'))
        page.locator('[data-analytics-record-search]').fill('ITL303')
        expect(page.locator('[data-analytics-record-export]')).to_have_attribute('href', re.compile('q=ITL303'))
        download_without_navigation(page.locator('[data-analytics-record-export]'))
        assert page.locator('[data-analytics-drawer]').evaluate('e=>e.open')
        expect(page.locator('[data-analytics-record-search]')).to_have_value('ITL303')
        page.locator('[data-analytics-close]').click()
        page.set_viewport_size({'width':390, 'height':844})
        download_without_navigation(page.locator('[data-compliance-export]'))
        page.locator('#normal-navigation').click()
        expect(page.locator('h1')).to_have_text('Attendance page')
        assert loaders  # Normal same-tab navigation still uses the loader.
        assert not errors, errors
        browser.close()
        print('Analytics, filtered drawer and Compliance downloads preserve page/filters/dialog without a skeleton; normal navigation still loads correctly.')
