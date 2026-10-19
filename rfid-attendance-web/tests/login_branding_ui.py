"""Check CampusTrack sign-in layout, theme toggle, assets, and reduced motion."""

from pathlib import Path
import os
import re
import shutil
import socket
import subprocess
import sys
import time
import urllib.request

WEB = Path(__file__).resolve().parent.parent
WORKSPACE = WEB.parent
sys.path.insert(0, str(WORKSPACE / '.codex-tmp/audit-deps'))
from playwright.sync_api import expect, sync_playwright

with socket.socket() as probe:
    probe.bind(('127.0.0.1', 0))
    port = probe.getsockname()[1]
base = f'http://127.0.0.1:{port}'
environment = os.environ.copy()
environment['APP_URL'] = base
server = subprocess.Popen(
    [shutil.which('php') or r'C:\xampp\php\php.exe', '-S', f'127.0.0.1:{port}', '-t', str(WEB / 'public')],
    cwd=WEB,
    env=environment,
    stdout=subprocess.DEVNULL,
    stderr=subprocess.DEVNULL,
    creationflags=subprocess.CREATE_NO_WINDOW if sys.platform == 'win32' else 0,
)
try:
    for _ in range(30):
        try:
            urllib.request.urlopen(base + '/auth/login.php', timeout=1).close()
            break
        except Exception:
            time.sleep(.2)
    else:
        raise RuntimeError('The local PHP server did not start.')

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        desktop = browser.new_context(viewport={'width': 1440, 'height': 900}, color_scheme='light')
        page = desktop.new_page()
        errors = []
        missing = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.on('response', lambda response: missing.append(response.url) if response.status >= 400 else None)
        page.goto(base + '/auth/login.php', wait_until='networkidle')
        expect(page).to_have_title('Sign in · CampusTrack')
        expect(page.get_by_role('heading', name='CampusTrack')).to_be_visible()
        expect(page.locator('.auth-visual')).to_be_visible()
        expect(page.locator('.auth-visual')).to_have_class(re.compile('has-motion-library'))
        expect(page.locator('.auth-form-panel')).to_be_visible()
        expect(page.locator('input[name="password"]')).to_have_attribute('type', 'password')
        assert page.locator('.auth-visual').bounding_box()['x'] == 0
        assert page.locator('.auth-form-panel').bounding_box()['x'] > page.locator('.auth-visual').bounding_box()['x']
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        page.screenshot(path=str(WORKSPACE / '.codex-tmp/login-brand-desktop.png'), full_page=True)
        photo_opacity = page.locator('.auth-photo-second').evaluate('''(el) => {
          el.getAnimations()[0].currentTime = 19000;
          return Number(getComputedStyle(el).opacity);
        }''')
        assert photo_opacity > .9, photo_opacity
        page.screenshot(path=str(WORKSPACE / '.codex-tmp/login-brand-second-photo.png'), full_page=True)

        page.get_by_role('button', name='Switch to dark mode').click()
        expect(page.locator('html')).to_have_attribute('data-theme', 'dark')
        expect(page.get_by_role('button', name='Switch to light mode')).to_be_visible()
        page.emulate_media(reduced_motion='reduce')
        expect(page.locator('.auth-visual')).not_to_have_class(re.compile('has-motion-library'))
        page.emulate_media(reduced_motion='no-preference')
        expect(page.locator('.auth-visual')).to_have_class(re.compile('has-motion-library'))
        page.reload(wait_until='networkidle')
        expect(page.locator('html')).to_have_attribute('data-theme', 'dark')
        page.screenshot(path=str(WORKSPACE / '.codex-tmp/login-brand-dark.png'), full_page=True)
        assert not errors, errors
        assert not missing, missing

        mobile = browser.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, device_scale_factor=1)
        mobile_page = mobile.new_page()
        mobile_page.goto(base + '/auth/login.php', wait_until='networkidle')
        expect(mobile_page.get_by_role('heading', name='CampusTrack')).to_be_visible()
        assert mobile_page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        assert mobile_page.locator('.auth-form-panel').bounding_box()['y'] > mobile_page.locator('.auth-visual').bounding_box()['y']
        assert mobile_page.locator('[data-auth-theme-toggle]').bounding_box()['y'] < mobile_page.locator('.auth-visual').bounding_box()['height']
        mobile_page.screenshot(path=str(WORKSPACE / '.codex-tmp/login-brand-mobile.png'), full_page=True)

        reduced = browser.new_context(viewport={'width': 1200, 'height': 800}, reduced_motion='reduce')
        reduced_page = reduced.new_page()
        reduced_page.goto(base + '/auth/login.php', wait_until='networkidle')
        assert reduced_page.locator('.auth-photo-second').evaluate('(el) => getComputedStyle(el).animationName') == 'none'
        assert 'has-motion-library' not in reduced_page.locator('.auth-visual').get_attribute('class')
        browser.close()
    print('CampusTrack sign-in UI checks passed')
finally:
    server.terminate()
    server.wait(timeout=10)
