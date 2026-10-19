"""Browser checks for the shared searchable-select enhancement."""
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import shutil
import sys
import threading
import time
import uuid

WEB = Path(__file__).resolve().parent.parent
WORKSPACE = WEB.parent
sys.path.insert(0, str(WORKSPACE / '.codex-tmp/audit-deps'))

from playwright.sync_api import expect, sync_playwright

root = WORKSPACE / '.codex-tmp' / ('searchable-selects-' + uuid.uuid4().hex)
(root / 'assets/js').mkdir(parents=True)
(root / 'assets/css').mkdir(parents=True)
shutil.copy2(WEB / 'public/assets/js/app.js', root / 'assets/js/app.js')
shutil.copy2(WEB / 'public/assets/css/app.css', root / 'assets/css/app.css')
options = '<option value="99">AUDITORIUM &mdash; University Auditorium</option>' + ''.join(f'<option value="{index}">CSL {index} &mdash; Computer Laboratory {index}</option>' for index in range(1, 26))
(root / 'index.html').write_text(f'''<!doctype html>
<html data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js@11.1.0/public/assets/styles/choices.min.css">
<link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main style="max-width:720px;padding:40px"><label class="field"><span>Room</span>
<select id="room" name="room_id" data-searchable><option value="">TBA</option>{options}</select></label>
<div id="dynamic"></div></main>
<script src="https://cdn.jsdelivr.net/npm/choices.js@11.1.0/public/assets/scripts/choices.min.js"></script>
<script src="/assets/js/app.js"></script></body></html>''', encoding='utf-8')

class Quiet(SimpleHTTPRequestHandler):
    def log_message(self, *_):
        pass

server = ThreadingHTTPServer(('127.0.0.1', 0), lambda *args, **kwargs: Quiet(*args, directory=str(root), **kwargs))
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        page = browser.new_page(viewport={"width": 900, "height": 700})
        page.goto(f'http://127.0.0.1:{server.server_port}', wait_until='networkidle')

        room = page.locator('#room')
        expect(page.locator('.choices.searchable-select')).to_have_count(1)
        page.locator('.choices.searchable-select').click()
        search = page.locator('.choices.is-open .choices__input--cloned')
        expect(search).to_have_attribute('placeholder', 'Search room\u2026')
        expect(search).to_have_attribute('aria-label', 'Search room')
        search.fill('University Auditorium')
        visible = page.locator('.choices.is-open .choices__item--choice:visible')
        expect(visible).to_have_count(1)
        expect(visible.first).to_contain_text('University Auditorium')
        visible.first.click()
        assert room.input_value() == '99'

        page.evaluate('''() => {
          const label = document.createElement('label');
          label.className = 'field';
          label.innerHTML = '<span>Campus section</span>';
          const select = document.createElement('select');
          select.dataset.searchable = '';
          select.id = 'dynamic-section';
          select.append(new Option('Select a section', ''));
          for (let i = 1; i <= 18; i += 1) select.append(new Option(`BSCS 4${String.fromCharCode(64 + i)}`, String(i)));
          label.append(select);
          document.querySelector('#dynamic').append(label);
        }''')
        expect(page.locator('#dynamic .choices.searchable-select')).to_have_count(1)

        page.evaluate('''() => {
          const select = document.querySelector('#dynamic-section');
          select.add(new Option('BSCS 4Z \u2014 Irregular', '99'));
          window.refreshSearchableSelect(select);
        }''')
        page.locator('#dynamic .choices').click()
        dynamic_search = page.locator('#dynamic .choices.is-open .choices__input--cloned')
        dynamic_search.fill('Irregular')
        expect(page.locator('#dynamic .choices.is-open .choices__item--choice:visible')).to_contain_text('BSCS 4Z')

        dark_background = page.locator('.choices.is-open .choices__list--dropdown').evaluate('(el) => getComputedStyle(el).backgroundColor')
        assert dark_background not in {'rgb(255, 255, 255)', 'rgba(0, 0, 0, 0)'}, dark_background
        browser.close()
    print('searchable-select UI checks passed')
finally:
    server.shutdown()
    server.server_close()
    shutil.rmtree(root, ignore_errors=True)

