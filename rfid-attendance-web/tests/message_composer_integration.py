"""Exercise rich compose, To/CC expansion, private uploads, forwards and receiver rendering using isolated fixtures."""
from pathlib import Path
import base64, json, os, re, subprocess, sys, time, uuid
WEB=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(WEB.parent/'.codex-tmp'/'audit-deps'))
import pymysql
from playwright.sync_api import expect,sync_playwright
name='composer_'+uuid.uuid4().hex; user='rich_'+uuid.uuid4().hex[:12]; password=uuid.uuid4().hex
server=None; database=pymysql.connect(host='127.0.0.1',port=3339,user='root',autocommit=True)
initial_files=set((WEB/'storage/message-attachments').glob('*'))
PHP=r'''
require getcwd().'/src/bootstrap.php'; $_ENV['DB_PASSWORD']='';
use App\Services\MessageService;
use App\Services\MessageContentService;
use App\Core\Database;
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$payloads=['<p onclick="bad()">Good<strong>bold</strong><script>alert(1)</script><img src=x onerror=bad()></p>',
 '<svg><foreignObject><p onclick=bad()>x</p></foreignObject></svg><a href="javascript:alert(1)">Link</a>',
 '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=bad()>">',
 '<p style="position:fixed;background-image:url(https://evil.invalid);text-align:center">Aligned</p>',
 '<a href="jav&#x09;ascript:alert(1)" onmouseover="bad()">Text</a><iframe srcdoc="bad"></iframe>'];
foreach($payloads as $payload) {
 $clean=MessageContentService::clean($payload);
 check(!preg_match('/<(script|svg|math|img|iframe|style)|onerror|onclick|onmouseover|javascript:|position:|background-image/i',$clean),'Unsafe HTML remained: '.$clean);
}
$clean=MessageContentService::clean('<h2>Heading</h2><p><strong>Bold</strong> <em>Italic</em> <u>Underline</u></p><ul><li>List</li></ul><a href="https://example.org">Link</a>');
check(str_contains($clean,'<strong>')&&str_contains($clean,'<ul>')&&str_contains($clean,'https://example.org'),'Rich formatting lost');
$pdo=Database::connection(); $pdo->exec('INSERT INTO user_roles(user_id,role_id) VALUES(6,2)');
$data=['subject'=>'Role and section overlap','body_html'=>$clean,'recipients_json'=>json_encode([
 ['kind'=>'to','type'=>'section','id'=>1], ['kind'=>'to','type'=>'user','id'=>5],
 ['kind'=>'cc','type'=>'role','id'=>2], ['kind'=>'cc','type'=>'user','id'=>6]])];
$id=MessageService::send($data,1);
$rows=$pdo->query('SELECT user_id,recipient_kind FROM message_recipients WHERE message_id='.$id)->fetchAll(PDO::FETCH_KEY_PAIR);
check($rows[5]==='to'&&$rows[6]==='to'&&$rows[2]==='cc'&&$rows[3]==='cc'&&$rows[4]==='cc','To/CC precedence or role expansion failed');
check(count($rows)===(int)$pdo->query('SELECT COUNT(DISTINCT user_id) FROM message_recipients WHERE message_id='.$id)->fetchColumn(),'Duplicate delivery');
check(!str_contains(MessageService::message(5,$id)['body'],'<strong>'),'Plain preview fallback contains markup');
try {MessageService::send(['subject'=>'Empty','body_html'=>'<p><br></p>','recipient_type'=>'user','target_id'=>5],1); throw new RuntimeException('Blank HTML accepted');} catch(DomainException) {}
try {MessageService::send(['subject'=>'No To','body'=>'Body','recipients_json'=>json_encode([['kind'=>'cc','type'=>'user','id'=>5]])],1); throw new RuntimeException('CC-only accepted');} catch(DomainException) {}
echo 'Formatting sanitizer, legacy fallback, section/role delivery, deduplication, and CC precedence passed.';
'''
try:
    with database.cursor() as cursor:
        cursor.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4'); cursor.execute(f'USE `{name}`')
        for source in ['schema.sql','seed.sql']:
            sql=re.sub(r'^\s*--.*$', '', (WEB/'database'/source).read_text(encoding='utf-8-sig'),flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): cursor.execute(statement)
        cursor.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
        cursor.execute(f"CREATE USER '{user}'@'127.0.0.1' IDENTIFIED BY '{password}'");cursor.execute(f"GRANT ALL ON `{name}`.* TO '{user}'@'127.0.0.1'")
    env=os.environ.copy();env.update({'DB_HOST':'127.0.0.1','DB_PORT':'3339','DB_DATABASE':name,'DB_USERNAME':'root','DB_PASSWORD':'','APP_ENV':'testing','APP_DEBUG':'true'})
    result=subprocess.run(['php','-r',PHP],cwd=WEB,env=env,capture_output=True,text=True)
    assert result.returncode==0,result.stdout+result.stderr;print(result.stdout)
    env.update({'DB_USERNAME':user,'DB_PASSWORD':password,'APP_URL':'http://127.0.0.1:8127'})
    server=subprocess.Popen(['php','-d','upload_max_filesize=8M','-d','post_max_size=32M','-S','127.0.0.1:8127','-t',str(WEB/'public')],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.8);base='http://127.0.0.1:8127'
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
        context=browser.new_context(viewport={'width':1440,'height':1100});page=context.new_page();errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
        def login(identifier):
            context.clear_cookies();page.goto(base+'/auth/login.php');page.locator('[name=identifier]').fill(identifier);page.locator('[name=password]').fill('Password123!');page.locator('button[type=submit]').click();page.wait_for_url(re.compile(r'/(admin|index)/$'))
        def send(fields,files=[]):
            return page.evaluate('''async ({fields,files})=>{const data=new FormData();Object.entries(fields).forEach(([key,value])=>data.set(key,value));for(const file of files){const content=file.base64?Uint8Array.from(atob(file.base64),c=>c.charCodeAt(0)):file.content;data.append('attachments[]',new File([content],file.name,{type:file.type||'application/octet-stream'}));}const r=await fetch('/api/messages.php',{method:'POST',body:data});return {status:r.status,payload:await r.json()};}''',{'fields':fields,'files':files})
        login('ADMIN-001');page.goto(base+'/admin/?page=messages');page.get_by_role('button',name='Compose message').click();form=page.locator('[data-mail-compose]')
        expect(form.locator('.mail-rich-editor .choices')).to_have_count(0)
        form.locator('[name=subject]').fill('Rich browser message');form.locator('.ql-editor').fill('Project update\nPlease review the agenda.')
        form.locator('.ql-editor').focus();page.keyboard.press('Control+a');form.get_by_role('button',name='Bold',exact=True).click();expect(form.locator('.ql-editor strong')).to_have_count(2)
        form.locator('[data-remote-user-input]').fill('2026-0001');expect(form.locator('.remote-user-option')).to_have_count(1);form.locator('.remote-user-option').click();expect(form.locator('[data-mail-recipients=to] .mail-recipient-chip')).to_have_count(1)
        form.get_by_role('button',name='Add CC',exact=True).click();form.locator('[name=recipient_type]').select_option('role')
        form.locator('[data-recipient-group=role] select').evaluate('el=>{el._choicesInstance?.setChoiceByValue("2");el.value="2";el.dispatchEvent(new Event("change"));}');form.get_by_role('button',name='Add recipient',exact=True).click();expect(form.locator('[data-mail-recipients=cc] .mail-recipient-chip')).to_have_count(1)
        image_path=WEB/'public/assets/logos/SOURCE_LOGO.png'; image_bytes=image_path.read_bytes()
        form.locator('[data-mail-files]').set_input_files([{'name':'campus.png','mimeType':'image/png','buffer':image_bytes},{'name':'agenda.txt','mimeType':'text/plain','buffer':b'Agenda: discuss project timelines.'}]);expect(form.locator('.mail-attachment-card')).to_have_count(2)
        form.locator('[data-mail-files]').set_input_files([{'name':'second-campus.png','mimeType':'image/png','buffer':image_bytes}]);expect(form.locator('.mail-attachment-card')).to_have_count(3)
        form.get_by_role('button',name='Preview campus.png',exact=True).click();expect(page.locator('[data-mail-image-dialog]')).to_be_visible();page.keyboard.press('ArrowRight');expect(page.locator('[data-mail-image-title]')).to_have_text('second-campus.png');page.keyboard.press('Escape');expect(page.locator('[data-mail-image-dialog]')).to_be_hidden()
        form.get_by_role('button',name='Remove attachment second-campus.png',exact=True).click();expect(form.locator('.mail-attachment-card')).to_have_count(2)
        for theme in ['light','dark']:
            page.evaluate('theme=>document.documentElement.dataset.theme=theme',theme);page.locator('.mail-compose').screenshot(path=str(WEB.parent/'.codex-tmp'/f'composer-{theme}.png'))
        page.set_viewport_size({'width':390,'height':844});page.wait_for_function('document.documentElement.scrollWidth<=innerWidth');page.locator('.mail-compose').screenshot(path=str(WEB.parent/'.codex-tmp'/'composer-mobile.png'));page.set_viewport_size({'width':1440,'height':1100})
        with page.expect_response(lambda r:'/api/messages.php' in r.url and r.request.method=='POST') as saved: form.get_by_role('button',name='Send message').click()
        assert saved.value.status==200,saved.value.text();message_id=saved.value.json()['data']['id'];expect(page.locator('.mail-compose')).to_be_hidden()
        token=page.locator('[data-mailbox]').get_attribute('data-token')
        fields={'_token':token,'action':'send_message','subject':'Blocked upload','body':'Body','recipient_type':'user','target_id':'5'}
        before=set((WEB/'storage/message-attachments').glob('*'))
        blocked=send(fields,[{'name':'attack.svg','type':'image/svg+xml','content':'<svg onload="alert(1)"></svg>'}]);assert blocked['status']==422,blocked
        rollback=send({**fields,'target_id':'1'},[{'name':'valid.txt','content':'Rollback test.'}]);assert rollback['status']==422,rollback
        assert set((WEB/'storage/message-attachments').glob('*'))==before,'Failed sends leaked private files'
        oversized=send(fields,[{'name':'large.txt','content':'x'*(5*1048576+1)}]);assert oversized['status']==422
        excessive=send(fields,[{'name':f'{i}.txt','content':'File contents'} for i in range(11)]);assert excessive['status']==422
        message=context.request.get(base+f'/api/messages.php?message_id={message_id}').json()['data'];assert len(message['attachments'])==2
        image=next(file for file in message['attachments'] if file['is_image']);text=next(file for file in message['attachments'] if not file['is_image'])
        assert context.request.get(image['preview_url']).body()==image_bytes
        download=context.request.get(text['url']);assert download.body()==b'Agenda: discuss project timelines.' and 'attachment;' in download.headers['content-disposition']
        login('2026-0001');page.goto(base+f'/index/?page=messages&message_id={message_id}');expect(page.locator('.mail-message-body strong')).to_have_count(2);expect(page.locator('.mail-received-attachments .mail-attachment-card')).to_have_count(2)
        page.get_by_role('button',name='Preview campus.png').click();expect(page.locator('[data-mail-image-dialog]')).to_be_visible();page.keyboard.press('Escape')
        page.locator('.mail-address-details summary').click();expect(page.locator('.mail-address-display')).to_have_count(2)
        assert context.request.get(text['url']).status==200
        page.locator('[data-mailbox]').screenshot(path=str(WEB.parent/'.codex-tmp'/'rich-receiver.png'))
        login('2026-0003');assert context.request.get(text['url']).status==404
        login('TCH-001');page.goto(base+f'/index/?page=messages&message_id={message_id}');expect(page.locator('.mail-message-identity')).to_contain_text('CC: me')
        page.locator('[data-mail-action=forward]').click();expect(page.locator('[data-mail-staged-attachments] .mail-attachment-card')).to_have_count(2)
        form=page.locator('[data-mail-compose]');form.locator('[data-remote-user-input]').fill('2026-0003');expect(form.locator('.remote-user-option')).to_have_count(1);form.locator('.remote-user-option').click()
        with page.expect_response(lambda r:'/api/messages.php' in r.url and r.request.method=='POST') as forward:form.get_by_role('button',name='Send message').click()
        assert forward.value.status==200,forward.value.text();forward_id=forward.value.json()['data']['id']
        forwarded=context.request.get(base+f'/api/messages.php?message_id={forward_id}').json()['data'];assert len(forwarded['attachments'])==2 and '<strong>' in forwarded['body_html']
        login('2026-0003');assert context.request.get(forwarded['attachments'][0]['url']).status==200
        context.clear_cookies();assert context.request.get(image['preview_url']).status==401
        assert not errors,errors;browser.close()
    print('Compose formatting, To/CC chips, private image/file uploads, lightbox, receiver display, forwards, limits, rollback, and authorization passed.')
finally:
    if server:server.terminate();server.wait(timeout=10)
    with database.cursor() as cursor:cursor.execute(f'DROP DATABASE IF EXISTS `{name}`');cursor.execute(f"DROP USER IF EXISTS '{user}'@'127.0.0.1'")
    database.close()
    # Only UUID filenames created by this isolated run are removed; production storage is never accessed.
    for file in set((WEB/'storage/message-attachments').glob('*'))-initial_files:file.unlink()
