"""Headless browser interaction checks with an isolated HTTP fixture, no real accounts."""
from pathlib import Path
import sys, re, json
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT.parent/'.codex-tmp/audit-deps'))
from playwright.sync_api import sync_playwright, expect

partial=(ROOT/'templates/partials/notification_inbox.php').read_text(encoding='utf-8')
partial=re.sub(r'<\?=.*?\?>','FIXTURE',partial)
partial=partial.replace('data-endpoint="FIXTURE"','data-endpoint="/api/notifications.php"')
html='<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/app.css"><link rel="stylesheet" href="/notifications.css"></head><body><header class="topbar">CampusTrack'+partial+'</header><script src="/notifications.js"></script></body></html>'
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
    for width,height in [(1280,900),(390,844)]:
        page=browser.new_page(viewport={'width':width,'height':height})
        items=[dict(id=1,revision=1,kind='attendance',title='Late attendance warning',body='Warning: this is your 3rd late occurrence in ITC103 LEC. <img src=x onerror=alert(1)>',read_at=None,updated_at='2026-09-07 12:00:00')]
        def route(request):
            path=request.request.url.split('notification.test')[-1]
            if path.startswith('/api/'):
                if 'push_config' in path: data={'config':{},'account_id':'5'}
                else:
                    if request.request.method=='POST': items[0]['read_at']='now'
                    data={'items':items,'unread':sum(x['read_at'] is None for x in items),'next_before':None}
                request.fulfill(content_type='application/json',body=json.dumps({'data':data})); return
            files={'/notifications.js':'public/assets/js/notifications.js','/notifications.css':'public/assets/css/notifications.css','/app.css':'public/assets/css/app.css'}
            if path in files:
                request.fulfill(content_type='text/javascript' if path.endswith('.js') else 'text/css',body=(ROOT/files[path]).read_text(encoding='utf-8')); return
            request.fulfill(content_type='text/html',body=html)
        page.route('https://notification.test/**',route)
        page.goto('https://notification.test/')
        page.locator('[data-notification-open]').click()
        expect(page.locator('[data-notification-dialog]')).to_be_visible()
        expect(page.locator('.notification-item')).to_have_count(1)
        expect(page.locator('.notification-item img')).to_have_count(0)
        expect(page.locator('[data-notification-push-status]')).to_contain_text('awaiting school setup')
        page.screenshot(path=str(ROOT.parent/f'.codex-tmp/notifications-{width}.png'), animations='disabled')
        page.locator('[data-notification-read]').click()
        expect(page.locator('.notification-item.is-unread')).to_have_count(0)
        page.locator('[data-notification-filter]').click()
        expect(page.locator('.notification-empty')).to_be_visible()
        page.keyboard.press('Escape')
        expect(page.locator('[data-notification-dialog]')).not_to_be_visible()
        page.close()
        print(f'PASS {width}px inbox, read state, unread filter, safe text, Escape close, push setup state')
    browser.close()
