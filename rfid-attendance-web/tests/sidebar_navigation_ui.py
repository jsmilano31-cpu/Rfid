"""Render the shared PHP layout for each role and exercise sidebar navigation."""

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import re
import subprocess
import sys
import threading

WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp' / 'audit-deps'))
from playwright.sync_api import expect, sync_playwright

PHP = r'''
namespace App\Core {
    class Auth {
        static function user() { return ['id' => 1, 'role_id' => 1, 'role_name' => $GLOBALS['role'], 'first_name' => 'Test', 'last_name' => 'User']; }
        static function viewableRoles() { return []; }
        static function previewRole() { return null; }
        static function hasRole($role) { return $GLOBALS['role'] === $role; }
        static function canAny($permissions) {
            if ($GLOBALS['role'] === 'Administrator') return true;
            $allowed = $GLOBALS['role'] === 'Teacher'
                ? ['view_attendance', 'view_grades', 'manage_seat_plans', 'view_compliance']
                : ['view_attendance', 'view_grades', 'view_compliance'];
            return array_intersect($permissions, $allowed) !== [];
        }
        static function can($permission) { return self::canAny([$permission]); }
    }
    class Csrf {
        static function field() { return '<input type="hidden" name="csrf_token" value="test">'; }
        static function token() { return 'test'; }
    }
}
namespace App\Services {
    class UserPreferenceService {
        static function get($id) { return ['theme_mode' => 'dark', 'accent_theme' => 'blue', 'reduced_motion' => false, 'compact_mode' => false]; }
    }
}
namespace {
    function e($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    function url($path) { return $path; }
    function config($key) { return ['name' => 'CampusTrack']; }
    function pull_flashes() { return []; }
    define('BASE_PATH', getcwd());
    define('PUBLIC_PATH', getcwd() . '/public');
    require BASE_PATH . '/src/Services/SearchService.php';
    $GLOBALS['role'] = $argv[1];
    $_SERVER['REQUEST_URI'] = $GLOBALS['role'] === 'Administrator' ? '/admin/' : '/index/';
    $active = $argv[2];
    $title = 'Sidebar test';
    $content = '<h1>Sidebar test</h1>';
    require BASE_PATH . '/templates/layouts/app.php';
}
'''


def render(role, active):
    result = subprocess.run(['php', '-r', PHP, role, active], cwd=WEB, capture_output=True, text=True, check=True)
    html = result.stdout
    html = re.sub(r'<link rel="stylesheet"[^>]*>', '', html)
    html = re.sub(r'<script src="[^"]+"[^>]*></script>', '', html)
    html = html.replace('</head>', '<link rel="stylesheet" href="/assets/css/app.css"></head>')
    return html.replace('</body>', '<script src="/assets/js/sidebar-navigation.js"></script></body>').encode()


PAGES = {
    '/admin.html': render('Administrator', 'grades'),
    '/teacher.html': render('Teacher', 'attendance'),
    '/student.html': render('Student', 'schedule'),
}


def labels(locator):
    return [text.strip() for text in locator.all_text_contents()]


def wait_sidebar_width(page, expected):
    page.wait_for_function('(width) => Math.abs(document.querySelector("#sidebar").getBoundingClientRect().width - width) < 1', arg=expected)


def wait_main_x(page, expected):
    page.wait_for_function('(x) => Math.abs(document.querySelector(".app-main").getBoundingClientRect().x - x) < 1', arg=expected)


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        path = self.path.split('?', 1)[0]
        if path in PAGES:
            body, mime = PAGES[path], 'text/html; charset=utf-8'
        elif path.startswith('/assets/') and (WEB / 'public' / path.lstrip('/')).is_file():
            body = (WEB / 'public' / path.lstrip('/')).read_bytes()
            mime = 'text/css' if path.endswith('.css') else 'text/javascript' if path.endswith('.js') else 'image/png'
        else:
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header('Content-Type', mime)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_):
        pass


server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
base = f'http://127.0.0.1:{server.server_port}'
try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        page = browser.new_page(viewport={'width': 1280, 'height': 900})
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))

        page.goto(base + '/admin.html')
        page.screenshot(path=str(WEB.parent / '.codex-tmp' / 'sidebar-admin-expanded.png'))
        expect(page.locator('[data-sidebar-group="academics"]')).to_have_attribute('open', '')
        expect(page.locator('a[aria-current="page"]')).to_have_attribute('aria-label', 'Grades')
        assert labels(page.locator('[data-sidebar-group="academics"] .nav-link-label')) == ['Academics', 'Attendance Policies', 'Grades']
        assert labels(page.locator('[data-sidebar-group="campus"] .nav-link-label')) == ['Rooms & Devices', 'Events', 'Recollections']
        page.locator('[data-sidebar-group="campus"] summary').click()
        expect(page.locator('[data-sidebar-group="campus"]')).to_have_attribute('open', '')
        page.reload()
        expect(page.locator('[data-sidebar-group="campus"]')).to_have_attribute('open', '')

        handle = page.locator('[data-sidebar-resize]')
        box = handle.bounding_box()
        page.mouse.move(box['x'] + box['width'] / 2, box['y'] + 100)
        page.mouse.down()
        page.mouse.move(65, box['y'] + 100, steps=7)
        page.mouse.up()
        expect(page.locator('html')).to_have_attribute('data-sidebar-compact', 'true')
        wait_sidebar_width(page, 72)
        page.screenshot(path=str(WEB.parent / '.codex-tmp' / 'sidebar-admin-compact.png'))
        assert round(page.locator('#sidebar').bounding_box()['width']) == 72
        expect(page.locator('.sidebar-group-label').first).to_have_css('visibility', 'hidden')
        assert round(page.locator('.app-main').bounding_box()['x']) == 72
        page.reload()
        wait_sidebar_width(page, 72)
        page.mouse.move(500, 500)
        page.locator('.sidebar-brand').hover()
        wait_sidebar_width(page, 250)
        page.screenshot(path=str(WEB.parent / '.codex-tmp' / 'sidebar-admin-hover.png'))
        assert round(page.locator('.app-main').bounding_box()['x']) == 72, 'Hover should overlay the page without moving it.'
        assert page.locator('.sidebar-group-label').first.evaluate('node => getComputedStyle(node).opacity') == '1'
        page.mouse.move(500, 500)
        page.wait_for_timeout(40)
        page.locator('.sidebar-brand').hover()
        page.wait_for_timeout(150)
        wait_sidebar_width(page, 250)
        page.mouse.move(500, 500)
        wait_sidebar_width(page, 72)
        page.locator('[data-sidebar-collapse]').click()
        page.mouse.move(500, 500)
        wait_sidebar_width(page, 250)
        wait_main_x(page, 250)
        page.locator('[data-sidebar-collapse]').click()
        page.mouse.move(500, 500)
        wait_sidebar_width(page, 72)
        handle.focus()
        page.keyboard.press('ArrowRight')
        wait_sidebar_width(page, 210)
        page.keyboard.press('End')
        wait_sidebar_width(page, 360)
        page.keyboard.press('Home')
        wait_sidebar_width(page, 72)
        page.emulate_media(reduced_motion='reduce')
        assert page.locator('#sidebar').evaluate('node => getComputedStyle(node).transitionDuration') == '0s'
        page.emulate_media(reduced_motion='no-preference')

        page.goto(base + '/teacher.html')
        expect(page.locator('[data-sidebar-group="learning"] .sidebar-group-label')).to_have_text('Teaching')
        assert labels(page.locator('[data-sidebar-group="learning"] .nav-link-label')) == ['Schedule', 'Attendance', 'Grades', 'Seat Plans']
        expect(page.locator('a[aria-current="page"]')).to_have_attribute('aria-label', 'Attendance')
        assert page.locator('.sidebar-nav').get_by_role('link', name='Users & RFID').count() == 0

        page.goto(base + '/student.html')
        expect(page.locator('[data-sidebar-group="learning"] .sidebar-group-label')).to_have_text('Academics')
        assert labels(page.locator('[data-sidebar-group="learning"] .nav-link-label')) == ['Schedule', 'Attendance', 'Grades']
        page.set_viewport_size({'width': 390, 'height': 844})
        page.locator('#sidebar').evaluate("node => node.classList.add('is-open')")
        page.screenshot(path=str(WEB.parent / '.codex-tmp' / 'sidebar-student-mobile.png'))
        expect(page.locator('#sidebar')).to_have_class(re.compile(r'is-open'))
        assert page.locator('.sidebar-group-label').first.evaluate('node => getComputedStyle(node).display') != 'none'
        assert page.locator('#sidebar').bounding_box()['width'] <= 301
        assert not errors, errors
        browser.close()
    print('Sidebar role groups, active states, resizing, persistence, and mobile behavior passed.')
finally:
    server.shutdown()
    server.server_close()
