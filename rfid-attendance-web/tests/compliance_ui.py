"""End-to-end compliance and administrator role-preview checks on an isolated database."""
from pathlib import Path
import http.cookiejar, re, shutil, subprocess, sys, time, uuid
WEB=Path(__file__).resolve().parent.parent
WORKSPACE=WEB.parent
sys.path.insert(0,str(WORKSPACE/'.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect

name='compliance_ui_'+uuid.uuid4().hex
root=WORKSPACE/'.codex-tmp'/name
for folder in ['src','config','public','templates']:
    shutil.copytree(WEB/folder,root/folder)
(root/'storage/logs').mkdir(parents=True)
(root/'storage/violation-evidence').mkdir(parents=True)
(root/'.env').write_text(f'DB_HOST=127.0.0.1\nDB_PORT=3338\nDB_DATABASE={name}\nDB_USERNAME=root\nDB_PASSWORD=\nAPP_ENV=testing\nAPP_DEBUG=true\nAPP_URL=http://127.0.0.1:8101\nPUBLIC_DIRECTORY=public\n')
db=pymysql.connect(host='127.0.0.1',port=3338,user='root',autocommit=True)
server=None
try:
    with db.cursor() as cursor:
        cursor.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4')
        cursor.execute(f'USE `{name}`')
        for source in ['schema.sql','seed.sql']:
            sql=re.sub(r'^\s*--.*$','',(WEB/'database'/source).read_text(encoding='utf-8-sig'),flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): cursor.execute(statement)
    log=(root/'server.log').open('w')
    php=[r'C:\xampp\php\php.exe','-n','-d',r'extension_dir=C:\xampp\php\ext','-d','extension=mbstring','-d','extension=pdo_mysql','-d','extension=fileinfo','-S','127.0.0.1:8101','-t',str(root/'public')]
    server=subprocess.Popen(php,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(1)
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
        page=browser.new_page(viewport={'width':1280,'height':900})
        page.goto('http://127.0.0.1:8101/auth/login.php')
        page.locator('[name=identifier]').fill('ADMIN-001')
        page.locator('[name=password]').fill('Password123!')
        page.locator('button[type=submit]').click()
        page.goto('http://127.0.0.1:8101/admin/?page=compliance')
        expect(page.get_by_role('heading',name='Compliance & deficiencies')).to_be_visible()
        expect(page.locator('tbody .compliance-total').first).to_be_visible()
        assert page.locator('tbody .compliance-total.badge').count()==0
        page.locator('[data-issue-violation]').first.click()
        expect(page.get_by_role('heading',name='Issue Violation')).to_be_visible()
        page.locator('[name=violation_group]').select_option(label='Minor offenses')
        page.locator('[name=specific_violation]').select_option(label='Littering')
        assert page.locator('[name=classification]').input_value()=='Category C'
        page.locator('.violation-progressive-toggle').click()
        page.locator('[name=previous_infractions]').select_option('2')
        assert page.locator('[name=current_occurrence]').input_value()=='3'
        page.locator('[name=location]').fill('Main hallway')
        page.locator('[name=incident_description]').fill('Campus staff observed and documented the incident clearly.')
        page.locator('[name=evidence]').set_input_files(str(WORKSPACE/'.codex-tmp/compliance-mobile.png'))
        invalid=page.locator('[data-violation-form]').evaluate("form=>[...form.elements].filter(field=>!field.checkValidity()).map(field=>({name:field.name,value:field.value,message:field.validationMessage}))")
        assert not invalid, invalid
        page.get_by_role('button',name='Issue Violation',exact=True).click()
        expect(page.locator('.app-toast').filter(has_text='Violation issued and added')).to_be_visible()
        expect(page.locator('#issue-violation-dialog')).not_to_have_attribute('open','')
        page.locator('[data-dialog-open="#view-as-dialog"]').click()
        page.locator('#view-as-dialog').get_by_role('button',name=re.compile('Staff')).click()
        page.wait_for_url('**/admin/**')
        expect(page.locator('.view-as-trigger')).to_contain_text('Staff')
        expect(page.locator('.sidebar-nav')).to_contain_text('Compliance')
        expect(page.locator('.sidebar-nav')).not_to_contain_text('Users & RFID')
        page.locator('.view-as-trigger').click()
        page.locator('#view-as-dialog').get_by_role('button',name=re.compile('Student')).click()
        page.wait_for_url('**/index/**')
        expect(page.locator('.view-as-trigger')).to_contain_text('Student')
        expect(page.locator('.topbar .eyebrow').first).to_contain_text('Student preview')
        page.locator('.view-as-trigger').click()
        page.locator('#view-as-dialog').get_by_role('button',name=re.compile('Admin')).click()
        page.wait_for_url('**/admin/**')
        expect(page.locator('.view-as-trigger')).to_contain_text('Admin')
        page.set_viewport_size({'width':390,'height':844})
        page.goto('http://127.0.0.1:8101/admin/?page=compliance')
        page.locator('[data-issue-violation]').first.click()
        box=page.locator('#issue-violation-dialog').bounding_box()
        assert box and box['x']>=0 and box['x']+box['width']<=390
        expect(page.locator('#issue-violation-dialog .app-dialog-body')).to_be_visible()
        page.screenshot(path=str(WORKSPACE/'.codex-tmp/compliance-mobile.png'),full_page=False)
        browser.close()
    with db.cursor() as cursor:
        cursor.execute(f'USE `{name}`')
        cursor.execute('SELECT evidence_path,evidence_mime,evidence_size FROM disciplinary_violations')
        evidence=cursor.fetchone()
        assert evidence and evidence[1]=='image/png' and evidence[2]>0
        assert (root/evidence[0]).is_file()
    print('Compliance modal, persistence, and role preview UI checks passed.')
finally:
    if server: server.terminate(); server.wait(timeout=10)
    with db.cursor() as cursor: cursor.execute(f'DROP DATABASE IF EXISTS `{name}`')
    db.close()
    shutil.rmtree(root,ignore_errors=True)
