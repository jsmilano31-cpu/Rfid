"""Recipient mailbox integration and browser checks against disposable fixtures (port 3339)."""
from pathlib import Path
import os, re, subprocess, sys, time, uuid
WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp' / 'audit-deps'))
import pymysql
from playwright.sync_api import expect, sync_playwright
name = 'mailbox_' + uuid.uuid4().hex
web_user = 'mail_' + uuid.uuid4().hex[:12]
password = uuid.uuid4().hex
server = None
PHP = r'''
require getcwd().'/src/bootstrap.php'; $_ENV['DB_PASSWORD']='';
use App\Services\MessageService;
use App\Core\Database;
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$pdo=Database::connection();
$id=MessageService::send(['subject'=>'Mailbox test','body'=>"First line\nSecond line <script>alert('unsafe')</script>",'recipient_type'=>'section','target_id'=>1],1);
check(MessageService::message(5,$id)['read_at']===null,'Listing marked messages read');
MessageService::change(5,[$id],'star'); MessageService::change(5,[$id],'read');
check(MessageService::mailbox(5,'starred','Mailbox',1)['total']===1,'Star or search failed');
MessageService::change(5,[$id],'trash');
check(MessageService::mailbox(5,'inbox','Mailbox',1)['total']===0,'Trashed copy in inbox');
check(MessageService::mailbox(5,'trash','Mailbox',1)['total']===1,'Trash missing');
check(MessageService::message(6,$id)['deleted_at']===null,'Deletion affected another recipient');
check(MessageService::message(1,$id)['body']!==null,'Deletion affected sender');
MessageService::change(5,[$id],'restore'); MessageService::change(5,[$id],'unread');
try { MessageService::change(5,[$id,999999],'trash'); throw new RuntimeException('Unauthorized batch accepted'); } catch (DomainException $e) {}
check(MessageService::message(5,$id)['deleted_at']===null,'Unauthorized batch partially changed messages');
try { MessageService::message(4,$id); throw new RuntimeException('Unrelated user read message'); } catch (DomainException $e) {}
try { MessageService::change(1,[$id],'trash'); throw new RuntimeException('Sender modified recipient copies'); } catch (DomainException $e) {}
for ($i=0;$i<22;$i++) MessageService::send(['subject'=>'Pagination '.$i,'body'=>'A message','recipient_type'=>'user','target_id'=>5],1);
check(count(MessageService::mailbox(5,'inbox','Pagination',1)['items'])===20,'Wrong page size');
check(count(MessageService::mailbox(5,'inbox','Pagination',2)['items'])===2,'Second page missing');
check(MessageService::mailbox(5,'inbox','%',1)['total']===0,'LIKE wildcard not escaped');
check(MessageService::mailbox(5,'inbox','sYsTeM aDmInIsTrAtOr',1)['total']>=23,'Name matching failed');
$pdo->exec("UPDATE users SET status='inactive' WHERE id=7");
$before=(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
try { MessageService::send(['subject'=>'Inactive','body'=>'Must fail','recipient_type'=>'user','target_id'=>7],1); throw new RuntimeException('Inactive recipient accepted'); } catch (DomainException $e) {}
check((int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$before,'Failed send left message');
MessageService::send(['subject'=>'Teacher reply test','body'=>'Please reply','recipient_type'=>'user','target_id'=>2],1);
echo $id;
'''
database = pymysql.connect(host='127.0.0.1', port=3339, user='root', autocommit=True)
try:
    with database.cursor() as cursor:
        cursor.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4')
        cursor.execute(f'USE `{name}`')
        for source in ['schema.sql','seed.sql']:
            sql=re.sub(r'^\s*--.*$', '', (WEB/'database'/source).read_text(encoding='utf-8-sig'),flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): cursor.execute(statement)
        cursor.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
        cursor.execute(f"CREATE USER '{web_user}'@'127.0.0.1' IDENTIFIED BY '{password}'")
        cursor.execute(f"GRANT ALL ON `{name}`.* TO '{web_user}'@'127.0.0.1'")
    env=os.environ.copy(); env.update({'DB_HOST':'127.0.0.1','DB_PORT':'3339','DB_DATABASE':name,'DB_USERNAME':'root','DB_PASSWORD':'','APP_ENV':'testing','APP_DEBUG':'true'})
    result=subprocess.run(['php','-r',PHP],cwd=WEB,env=env,capture_output=True,text=True)
    assert result.returncode==0,result.stdout+result.stderr
    message_id=int(result.stdout.strip()); print('Mailbox ownership, atomic bulk actions, search, pagination, and history retention passed.')
    env.update({'DB_USERNAME':web_user,'DB_PASSWORD':password,'APP_URL':'http://127.0.0.1:8126'})
    server=subprocess.Popen(['php','-S','127.0.0.1:8126','-t',str(WEB/'public')],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.8)
    with sync_playwright() as playwright:
        browser=playwright.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
        context=browser.new_context(viewport={'width':1440,'height':950})
        page=context.new_page(); errors=[]; page.on('pageerror',lambda error:errors.append(str(error)))
        base='http://127.0.0.1:8126'
        def login(identifier):
            page.goto(base+'/auth/login.php'); page.locator('[name=identifier]').fill(identifier); page.locator('[name=password]').fill('Password123!'); page.locator('button[type=submit]').click(); page.wait_for_url(re.compile(r'/(admin|index)/$'))
        login('2026-0001'); page.goto(base+'/index/?page=messages')
        expect(page.locator('.mail-row')).to_have_count(20)
        page.locator('[data-mail-search]').fill('Mailbox test'); expect(page.locator('.mail-row')).to_have_count(1)
        row=page.locator('[data-mail-open]').first; row.focus(); page.keyboard.press('Enter')
        expect(page.locator('.mail-message-body')).to_contain_text("Second line <script>alert('unsafe')</script>")
        assert not page.locator('.mail-message-body script').count()
        assert page.locator('.mail-detail-subject').evaluate('el=>el===document.activeElement')
        expect(page.locator('[data-mail-detail]')).to_be_visible()
        page.locator('[data-mail-detail] [data-mail-action=trash]').click(); expect(page.locator('[data-mail-detail]')).to_be_hidden()
        page.get_by_role('button',name=re.compile('^Trash')).click(); expect(page.locator('.mail-row')).to_have_count(1)
        page.locator('[data-mail-action=restore]').click(); expect(page.locator('.mail-row')).to_have_count(0)
        page.get_by_role('button',name=re.compile('^Inbox')).click(); expect(page.locator('.mail-row')).to_have_count(20)
        page.locator('[data-mail-select-all]').check(); expect(page.locator('[data-mail-selected]')).to_have_text('20 selected')
        page.locator('[data-mail-bulk] [data-mail-action=read]').click(); expect(page.locator('[data-mail-selected]')).to_have_text('Select')
        page.locator('[data-mail-search]').fill('Mailbox test'); expect(page.locator('.mail-row')).to_have_count(1)
        for theme in ['light','dark']:
            page.evaluate('theme=>document.documentElement.dataset.theme=theme',theme)
            page.locator('[data-mailbox]').screenshot(path=str(WEB.parent/'.codex-tmp'/f'mailbox-{theme}.png'))
            page.locator('[data-mail-open]').click(); page.locator('[data-mailbox]').screenshot(path=str(WEB.parent/'.codex-tmp'/f'mailbox-detail-{theme}.png')); page.locator('[data-mail-action=back]').click()
        page.set_viewport_size({'width':390,'height':844}); page.locator('[data-mail-open]').click(); expect(page.locator('.mail-message-body')).to_be_visible()
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'), 'Mobile overflow'
        page.locator('[data-mailbox]').screenshot(path=str(WEB.parent/'.codex-tmp'/'mailbox-mobile.png'))
        page.keyboard.press('Escape'); expect(page.locator('[data-mail-detail]')).to_be_hidden()
        token=page.locator('[data-mailbox]').get_attribute('data-token')
        assert context.request.get(base+'/api/messages.php?message_id=999999').status==422
        assert context.request.post(base+'/api/messages.php',form={'action':'trash','ids':f'[{message_id}]'}).status==419
        assert context.request.post(base+'/api/messages.php',form={'_token':token,'action':'send_message','subject':'Denied','body':'Denied','recipient_type':'user','target_id':'1'}).status==403
        context.clear_cookies(); assert context.request.get(base+'/api/messages.php').status==401
        login('ADMIN-001'); page.set_viewport_size({'width':1440,'height':950}); page.goto(base+'/admin/?page=messages')
        page.get_by_role('button',name='Compose message').click()
        form=page.locator('[data-mail-compose]'); form.locator('[name=subject]').fill('Browser sent'); form.locator('.ql-editor').fill('Browser body')
        form.locator('[data-remote-user-input]').fill('2026-0001'); expect(form.locator('.remote-user-option')).to_have_count(1); form.locator('.remote-user-option').click()
        with page.expect_response(lambda response:'/api/messages.php' in response.url and response.request.method=='POST') as saved: form.get_by_role('button',name='Send message').click()
        assert saved.value.status==200,saved.value.text()
        expect(page.locator('.mail-compose')).to_be_hidden(); page.get_by_role('button',name='Sent',exact=True).click(); expect(page.locator('.mail-row').first).to_contain_text('Browser sent')
        page.locator('[data-mail-open]').first.click(); expect(page.locator('.mail-message-body')).to_have_text('Browser body')
        assert not errors,errors
        context.clear_cookies(); login('TCH-001'); page.goto(base+'/index/?page=messages')
        page.locator('[data-mail-search]').fill('Teacher reply test'); expect(page.locator('.mail-row')).to_have_count(1); page.locator('[data-mail-open]').click()
        page.locator('[data-mail-action=reply]').click(); expect(page.locator('[data-mail-compose]')).to_be_visible()
        expect(page.locator('[name=subject]')).to_have_value('Re: Teacher reply test')
        assert '"id":1' in page.locator('[name=recipients_json]').input_value()
        page.locator('[data-mail-compose] .ql-editor').fill('Teacher reply body')
        page.locator('[data-mail-compose] [type=submit]').click(); expect(page.locator('.mail-compose')).to_be_hidden()
        page.locator('[data-mail-action=forward]').click(); expect(page.locator('[name=subject]')).to_have_value('Fwd: Teacher reply test')
        expect(page.locator('[name=target_id]')).to_have_value('')
        assert not errors,errors
        browser.close()
    print('Inbox/detail, keyboard, bulk actions, trash/restore, mobile/themes, sessions, and compose passed.')
finally:
    if server: server.terminate(); server.wait(timeout=10)
    with database.cursor() as cursor:
        cursor.execute(f'DROP DATABASE IF EXISTS `{name}`'); cursor.execute(f"DROP USER IF EXISTS '{web_user}'@'127.0.0.1'")
    database.close()
