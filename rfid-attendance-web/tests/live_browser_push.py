"""Opt-in live FCM test in an isolated browser profile; no application account."""
from pathlib import Path
import sys,json,importlib.util,time
ROOT=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(ROOT.parent/'.codex-tmp/audit-deps'))
from playwright.sync_api import sync_playwright
spec=importlib.util.spec_from_file_location('sender',ROOT/'scripts/verify-live-push.py')
sender=importlib.util.module_from_spec(spec);spec.loader.exec_module(sender)
config=json.loads((ROOT/'storage/private/firebase-web.json').read_text())
with sync_playwright() as p:
    browser=p.chromium.launch_persistent_context(str(ROOT.parent/'.codex-tmp/push-browser-profile'),executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True,ignore_default_args=['--disable-background-networking'],permissions=['notifications'])
    try:
        page=browser.new_page()
        page.route('https://jsmkj.space/_local_push_verification',lambda route:route.fulfill(content_type='text/html',body='<title>Isolated push verification</title>'))
        page.goto('https://jsmkj.space/_local_push_verification')
        token=page.evaluate('''async config => {
            const appSdk=await import('https://www.gstatic.com/firebasejs/12.3.0/firebase-app.js');
            const messagingSdk=await import('https://www.gstatic.com/firebasejs/12.3.0/firebase-messaging.js');
            const messaging=messagingSdk.getMessaging(appSdk.initializeApp(config));
            const registration=await navigator.serviceWorker.register('/firebase-messaging-sw.php',{scope:'/'});
            await navigator.serviceWorker.ready;
            await new Promise((resolve,reject)=>{
                const channel=new MessageChannel();
                const timeout=setTimeout(()=>reject(new Error('Worker binding timed out')),10000);
                channel.port1.onmessage=()=>{clearTimeout(timeout);resolve();};
                registration.active.postMessage({type:'campus-bind',account:'push-verification-only'},[channel.port2]);
            });
            window.received=[];
            messagingSdk.onMessage(messaging,payload=>window.received.push(payload.data));
            return await Promise.race([messagingSdk.getToken(messaging,{vapidKey:config.vapidKey,serviceWorkerRegistration:registration}),new Promise((_,reject)=>setTimeout(()=>reject(new Error('Browser push registration timed out')),45000))]);
        }''',config)
        print('Browser FCM token registered.',flush=True)
        notification_id=str(int(time.time()*1000))
        sender.send({'token':token,'notification_id':notification_id})
        page.wait_for_function('(id)=>window.received.some(data=>data.notification_id===id)',arg=notification_id,timeout=60000)
        print('Real browser foreground FCM delivery verified.',flush=True)
        page.goto('about:blank')
        background_id=str(int(time.time()*1000))
        sender.send({'token':token,'notification_id':background_id})
        worker=browser.service_workers[0]
        deadline=time.monotonic()+60
        received=False
        while time.monotonic()<deadline:
            received=worker.evaluate("async id => (await self.registration.getNotifications()).some(n=>n.tag==='campus-'+id)",background_id)
            if received: break
            time.sleep(1)
        assert received, 'Background push did not display a browser notification'
        print('Real browser background notification verified.',flush=True)
        worker.evaluate("async () => { for (const n of await self.registration.getNotifications()) n.close(); }")
    finally:
        browser.close()
