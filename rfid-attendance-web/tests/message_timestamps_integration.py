"""Server-authoritative message clocks in an isolated database and multiple browser zones."""
from pathlib import Path
from datetime import datetime, timezone, timedelta
import json, os, re, subprocess, sys, time, uuid
WEB=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(WEB.parent/'.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import expect, sync_playwright
name='message_clock_'+uuid.uuid4().hex;user='clock_'+uuid.uuid4().hex[:12];password=uuid.uuid4().hex
server=None;old_zone=None
db=pymysql.connect(host='127.0.0.1',port=3340,user='root',autocommit=True)
PHP=r'''
require getcwd().'/src/bootstrap.php'; $_ENV['DB_PASSWORD']=''; $_SESSION['user_id']=1;
use App\Core\Database; use App\Services\MessageService as Messages; use App\Services\NotificationService;
function check($value,$text){if(!$value)throw new RuntimeException($text);}
$pdo=Database::connection();
check($pdo->query('SELECT @@session.time_zone')->fetchColumn()==='+08:00','Database did not adopt campus time on connection');
$legacy=(int)$pdo->query('SELECT id FROM messages WHERE subject="Historical clock fixture"')->fetchColumn();
$stamp=Messages::message(1,$legacy);
check($stamp['sent_at']==='2026-10-01T09:05:00+08:00','Historical instant or offset changed');
check($stamp['sent_at_display']==='Oct 1, 2026 · 9:05 AM','Wrong historical display time');
check((new DateTimeImmutable($stamp['sent_at']))->getTimestamp()===(int)$pdo->query('SELECT UNIX_TIMESTAMP(created_at) FROM messages WHERE id='.$legacy)->fetchColumn(),'Historical instant rewritten');
$data=['subject'=>'Clock before notifications','body'=>'Server clock fixture','recipient_type'=>'user','target_id'=>5,
    'created_at'=>'2099-01-01 00:00:00','sent_at'=>'2099-01-01T00:00:00Z'];
$id=Messages::send($data,1);
NotificationService::enabled(); $data['subject']='Clock after notifications';$after=Messages::send($data,1);
foreach([$id,$after] as $messageId){
    $sender=Messages::message(1,$messageId);$receiver=Messages::message(5,$messageId);
    check($sender['sent_at']===$receiver['sent_at'],'Sender and receiver disagree');
    check($sender['sent_at_display']===$receiver['sent_at_display'],'Sender and receiver labels disagree');
    check(abs((new DateTimeImmutable($sender['sent_at']))->getTimestamp()-time())<5,'Client spoof or timezone drift changed send instant');
}
Messages::change(5,[$id],'read');Messages::change(5,[$id],'star');Messages::change(5,[$id],'trash');
$state=Messages::message(5,$id);
foreach(['read_at','starred_at','deleted_at'] as $field){
    check(abs((new DateTimeImmutable($state[$field],new DateTimeZone('Asia/Taipei')))->getTimestamp()-time())<5,'Mailbox action clock inconsistent: '.$field);
}
Messages::change(5,[$id],'restore');
$sent=Messages::mailbox(1,'sent','Clock before notifications',1)['items'][0];
$inbox=Messages::mailbox(5,'inbox','Clock before notifications',1)['items'][0];
check($sent['sent_at']===$inbox['sent_at'] && $sent['sent_at']===$state['sent_at'],'List/detail time differs');
echo json_encode(['id'=>$id,'sent_at'=>$sent['sent_at'],'display'=>$sent['sent_at_display'],'legacy'=>$legacy]);
'''
try:
    with db.cursor() as c:
        c.execute('SELECT @@global.time_zone');old_zone=c.fetchone()[0]
        c.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4');c.execute(f'USE `{name}`')
        for source in ['schema.sql','seed.sql']:
            sql=re.sub(r'^\s*--.*$','',(WEB/'database'/source).read_text(encoding='utf-8-sig'),flags=re.M)
            for statement in sql.split(';'):
                if statement.strip():c.execute(statement)
        c.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
        c.execute("SET SESSION time_zone='+00:00'")
        c.execute('INSERT INTO messages(sender_id,subject,body,created_at) VALUES(1,"Historical clock fixture","History stays intact","2026-10-01 01:05:00")')
        c.execute(f"CREATE USER '{user}'@'127.0.0.1' IDENTIFIED BY '{password}'");c.execute(f"GRANT ALL ON `{name}`.* TO '{user}'@'127.0.0.1'")
        # Only this disposable local server is changed; production clocks are never modified.
        c.execute("SET GLOBAL time_zone='-07:00'")
    env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='3340',DB_DATABASE=name,DB_USERNAME='root',DB_PASSWORD='',APP_ENV='testing',APP_DEBUG='true')
    result=subprocess.run(['php','-r',PHP],cwd=WEB,env=env,capture_output=True,text=True);assert result.returncode==0,result.stdout+result.stderr
    fixture=json.loads(result.stdout);print('Database-zone mismatch, historical instants, ignored client times, sender/recipient parity, and mailbox action clocks passed.',flush=True)
    env.update(DB_USERNAME=user,DB_PASSWORD=password,APP_URL='http://127.0.0.1:8130')
    server=subprocess.Popen(['php','-S','127.0.0.1:8130','-t','public'],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW);time.sleep(.8)
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True);errors=[];base='http://127.0.0.1:8130'
        for zone,identifier in [('America/Los_Angeles','ADMIN-001'),('UTC','2026-0001'),('Pacific/Auckland','ADMIN-001'),('Asia/Taipei','2026-0001')]:
            context=browser.new_context(timezone_id=zone,locale='fr-FR',viewport={'width':1440,'height':1000});page=context.new_page();page.on('pageerror',lambda e:errors.append(e.stack));page.add_init_script('Date.now=()=>Date.UTC(2099,0,1)')
            page.goto(base+'/auth/login.php');page.locator('[name=identifier]').fill(identifier);page.locator('[name=password]').fill('Password123!');page.locator('button[type=submit]').click();page.wait_for_url(re.compile(r'/(admin|index)/$'))
            area='admin' if identifier=='ADMIN-001' else 'index';page.goto(base+'/'+area+'/?page=messages')
            expect(page.locator('.page-header')).to_contain_text('UTC+08:00')
            if identifier=='ADMIN-001':page.locator('[data-mail-folder=sent]').click()
            page.locator('[data-mail-search]').fill('Clock before notifications');row=page.locator('.mail-row').filter(has_text='Clock before notifications');stamp=row.locator('time');expect(stamp).to_have_text(fixture['display']);expect(stamp).to_have_attribute('datetime',fixture['sent_at'])
            row.locator('.mail-row-link').click();expect(page.locator('.mail-message-time')).to_have_text(fixture['display']);expect(page.locator('.mail-message-time')).to_have_attribute('datetime',fixture['sent_at'])
            # Forward quotes reuse the server-formatted original send time.
            if identifier=='ADMIN-001':
                page.get_by_role('button',name='Forward message',exact=True).click();expect(page.locator('.ql-editor')).to_contain_text(fixture['display'])
                page.locator('.mail-delivery-log summary').click();expect(page.locator('#messages-sent')).to_contain_text(fixture['display'])
            page.set_viewport_size({'width':390,'height':844});page.screenshot(path=str(WEB.parent/'.codex-tmp/message-clock-mobile.png'),full_page=True)
            size=page.locator('[data-mailbox]').evaluate('el=>({width:el.clientWidth,scroll:el.scrollWidth,overflow:[...el.querySelectorAll("*")].filter(n=>n.getBoundingClientRect().right>el.getBoundingClientRect().right+2).slice(0,12).map(n=>({tag:n.tagName,cls:n.className,width:n.clientWidth,scroll:n.scrollWidth}))})')
            assert size['scroll']<=size['width']+1,'Timestamp layout overflows mobile: '+str(size)
            payload=page.request.get(base+'/api/messages.php?message_id='+str(fixture['id'])).json();assert payload['time_zone']=='Asia/Taipei';assert payload['data']['sent_at']==fixture['sent_at']
            context.close()
        assert not errors,errors;browser.close();print('Server display text and ISO timestamps agree across four browser time zones, different locales, a wrong client clock, sent/inbox/detail/forward/log views, and mobile layouts.',flush=True)
finally:
    if server:server.terminate();server.wait(timeout=5)
    with db.cursor() as c:
        if old_zone is not None:c.execute('SET GLOBAL time_zone=%s',(old_zone,))
        c.execute(f'DROP DATABASE IF EXISTS `{name}`');c.execute(f"DROP USER IF EXISTS '{user}'@'127.0.0.1'")
    db.close()
