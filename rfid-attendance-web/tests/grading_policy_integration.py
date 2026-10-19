"""Grading periods and policy UI tests against a disposable local database."""
from pathlib import Path
import json, os, re, subprocess, sys, time, uuid
WEB=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(WEB.parent/'.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect

name='grading_'+uuid.uuid4().hex
username='grade_'+uuid.uuid4().hex[:12]
password=uuid.uuid4().hex
server=None
PHP=r'''
require getcwd().'/src/bootstrap.php'; $_ENV['DB_PASSWORD']=''; $_SESSION['user_id']=1;
use App\Core\Database; use App\Services\GradeService as Grade;
$pdo=Database::connection();
function check($value,$message){if(!$value)throw new RuntimeException($message);}
function policy($extra=[]){return array_merge(['has_midterm'=>1,'has_final'=>1,'midterm_weight'=>40,'final_weight'=>60,'passing_grade'=>75],$extra);}
check(!Grade::settings()['has_prelim'],'Default unexpectedly enables Prelims');
$pdo->exec('UPDATE grades SET status="draft",finalized_at=NULL WHERE class_offering_id=1 AND student_id=5');
$old=$pdo->query('SELECT prelim FROM grades WHERE class_offering_id=1 AND student_id=5')->fetchColumn();
$row=Grade::save(['class_offering_id'=>1,'student_id'=>5,'midterm'=>80,'final'=>90,'prelim'=>1],1);
check($row['overall']==86,'Midterms/Endterms weight mismatch');
check($pdo->query('SELECT prelim FROM grades WHERE class_offering_id=1 AND student_id=5')->fetchColumn()==$old,'Disabled score overwritten');
foreach ([policy(['has_midterm'=>0,'has_final'=>0]),policy(['midterm_weight'=>-1,'final_weight'=>101]),policy(['midterm_weight'=>'bad']),policy(['passing_grade'=>101]),policy(['midterm_weight'=>30])] as $invalid){
    try {Grade::updateSettings($invalid,1);throw new RuntimeException('Invalid policy accepted');}catch(DomainException $expected){}
}
Grade::updateSettings(policy(['has_prelim'=>1,'prelim_weight'=>20,'midterm_weight'=>30,'final_weight'=>50]),1);
check(Grade::settings()['has_prelim']==1,'Settings cache not refreshed');
try {Grade::save(['class_offering_id'=>1,'student_id'=>5,'midterm'=>80,'final'=>90],1);throw new RuntimeException('Prelims not required');}catch(DomainException $expected){}
$row=Grade::save(['class_offering_id'=>1,'student_id'=>5,'prelim'=>70,'midterm'=>80,'final'=>90],1);
check($row['overall']==83,'Three period calculation mismatch');
Grade::updateSettings(policy(['has_midterm'=>0,'final_weight'=>100]),1);
$row=Grade::save(['class_offering_id'=>1,'student_id'=>5,'final'=>95],1);
check($row['overall']==95 && $row['prelim']==70 && $row['midterm']==80,'Single-period calculation or history preservation failed');
Grade::updateSettings(policy(),1);
try {Grade::save(['class_offering_id'=>1,'student_id'=>5,'midterm'=>80],1);throw new RuntimeException('Endterms not required');}catch(DomainException $expected){check(str_contains($expected->getMessage(),'Endterms'),'Legacy label in validation');}
$historical=$pdo->query('SELECT overall FROM grades WHERE status="finalized" LIMIT 1')->fetchColumn();
Grade::updateSettings(policy(['midterm_weight'=>50,'final_weight'=>50]),1);
check($pdo->query('SELECT overall FROM grades WHERE status="finalized" LIMIT 1')->fetchColumn()==$historical,'Policy changed finalized history');
$pdo->exec("CREATE TRIGGER reject_policy_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture audit failure'");
try {Grade::updateSettings(policy(),1);throw new RuntimeException('Failed audit accepted');}catch(PDOException $expected){}
check($pdo->query('SELECT midterm_weight FROM grading_settings WHERE id=1')->fetchColumn()==50,'Audit rollback failed');
$pdo->exec('DROP TRIGGER reject_policy_audit'); Grade::updateSettings(policy(),1);
$pdo->exec('INSERT INTO roles(name) VALUES("Policy manager")');$role=$pdo->lastInsertId();
$pdo->exec('INSERT INTO role_permissions(role_id,permission_id) SELECT '.$role.',id FROM permissions WHERE code IN ("manage_attendance","manage_grading_settings")');
$stmt=$pdo->prepare('INSERT INTO users(school_id,first_name,last_name,email,password_hash,role_id) VALUES("POLICY","Policy","Manager","policy@example.test",?,?)');
$stmt->execute([password_hash('Password123!',PASSWORD_DEFAULT),$role]);$policyUser=$pdo->lastInsertId();
$pdo->exec('INSERT INTO user_roles(user_id,role_id) VALUES('.$policyUser.','.$role.')');
echo json_encode(['policy_user'=>$policyUser]);
'''
db=pymysql.connect(host='127.0.0.1',port=3340,user='root',autocommit=True)
try:
    with db.cursor() as c:
        c.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4');c.execute(f'USE `{name}`')
        for source in ['schema.sql','seed.sql']:
            sql=re.sub(r'^\s*--.*$','',(WEB/'database'/source).read_text(encoding='utf-8-sig'),flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): c.execute(statement)
        c.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
        c.execute('DELETE role_permissions FROM role_permissions JOIN permissions ON permissions.id=permission_id WHERE role_id=1 AND permissions.code="manage_grading_settings"')
        migration=re.sub(r'^\s*--.*$','',(WEB/'database/migrations/20261002_restore_grading_policy_permission.sql').read_text(encoding='utf-8'),flags=re.M)
        for _ in range(2):
            for statement in migration.split(';'):
                if statement.strip():c.execute(statement)
        c.execute('SELECT COUNT(*) FROM role_permissions JOIN permissions ON permissions.id=permission_id WHERE permissions.code="manage_grading_settings"')
        assert c.fetchone()[0]==1,'Permission restoration duplicated or granted another role'
        c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'")
        c.execute(f"GRANT ALL ON `{name}`.* TO '{username}'@'127.0.0.1'")
    env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='3340',DB_DATABASE=name,DB_USERNAME='root',DB_PASSWORD='',APP_ENV='testing',APP_DEBUG='true')
    result=subprocess.run(['php','-r',PHP],cwd=WEB,env=env,capture_output=True,text=True)
    assert result.returncode==0,result.stdout+result.stderr
    json.loads(result.stdout);print('Period defaults, validation, weighted calculations, history preservation, and audit rollback passed.',flush=True)
    env.update(DB_USERNAME=username,DB_PASSWORD=password,APP_URL='http://127.0.0.1:8129')
    server=subprocess.Popen(['php','-S','127.0.0.1:8129','-t','public'],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.8)
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
        context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();errors=[]
        page.on('pageerror',lambda e:errors.append(e.stack));base='http://127.0.0.1:8129'
        def login(identifier):
            page.goto(base+'/auth/login.php');page.locator('[name=identifier]').fill(identifier);page.locator('[name=password]').fill('Password123!');page.locator('button[type=submit]').click();page.wait_for_url(re.compile(r'/(admin|index)/$'))
        def post(data):
            return page.evaluate('''async data => { const response = await fetch('/grades.php',{method:'POST',body:new URLSearchParams(data)}); return {status:response.status,body:await response.json()}; }''',data)
        login('ADMIN-001');page.goto(base+'/admin/?page=attendance');page.locator('[data-dialog-open="#admin-attendance-rules-dialog"]').click()
        dialog=page.locator('#admin-attendance-rules-dialog');expect(dialog).to_be_visible();expect(dialog).to_contain_text('Attendance rules');expect(dialog).to_contain_text('Grading periods')
        form=dialog.locator('[data-grading-policy-form]');expect(form.locator('[name=has_prelim]')).not_to_be_checked()
        # Toggle periods and adjust weights without navigation or losing the modal.
        form.locator('[name=has_prelim]').check();form.locator('[name=prelim_weight]').fill('20');form.locator('[name=midterm_weight]').fill('30');form.locator('[name=final_weight]').fill('50')
        before=page.url;form.locator('[type=submit]').click();expect(form.locator('[data-grading-policy-feedback]')).to_contain_text('Saved.');assert page.url==before;expect(dialog).to_be_visible()
        form.locator('[name=has_prelim]').uncheck();form.locator('[name=midterm_weight]').fill('40');form.locator('[name=final_weight]').fill('60');form.locator('[type=submit]').click();expect(form.locator('[data-grading-policy-feedback]')).to_contain_text('Saved.')
        token=form.locator('[name=_token]').input_value()
        assert post({'action':'update_grading_settings','_token':'invalid'})['status']==419
        assert post({'action':'update_grading_settings','_token':token,'has_midterm':'1','midterm_weight':'-100','passing_grade':'75'})['status']==422
        # Independent attendance save retains grading controls and their input.
        attendance=dialog.locator('[data-attendance-rules-form]');attendance.locator('button').click();expect(attendance.locator('[data-attendance-feedback]')).to_contain_text('updated')
        for theme in ['dark','light']:
            page.evaluate('(theme)=>document.documentElement.dataset.theme=theme',theme);page.set_viewport_size({'width':390,'height':844})
            assert dialog.evaluate('el=>el.scrollWidth<=el.clientWidth+1'),'Modal overflows narrow screen'
            assert form.locator('[name=final_weight]').is_visible()
        page.set_viewport_size({'width':1440,'height':1000});page.evaluate('document.documentElement.dataset.theme="dark"');dialog.locator('.app-dialog-body').evaluate('el=>el.scrollTop=el.scrollHeight');dialog.screenshot(path=str(WEB.parent/'.codex-tmp/grading-policy-desktop.png'))
        page.keyboard.press('Escape');expect(dialog).not_to_be_visible()
        # View As uses the preview role, not the administrator's underlying rights.
        page.request.post(base+'/auth/view-as.php',form={'_token':token,'role_id':'3'})
        assert page.request.get(base+'/grades.php?mode=settings').status==403
        page.request.post(base+'/auth/view-as.php',form={'_token':token,'role_id':'1'})
        # Management tables and previews use exactly the institution periods.
        page.goto(base+'/admin/?page=grades');page.locator('[data-admin-grade-subject-results] .grade-subject-card').filter(has_text='IT 301').first.click();page.locator('[data-admin-grade-roster-results] tr').filter(has_text='2026-0001').locator('[data-edit-student-grade]').click();edit=page.locator('#admin-grade-edit-dialog');expect(edit).to_be_visible()
        expect(edit.locator('[name=prelim]')).not_to_be_visible();expect(edit.locator('[name=prelim]')).to_be_disabled()
        edit.locator('[name=midterm]').fill('80');edit.locator('[name=final]').fill('90');expect(edit.locator('[data-admin-grade-edit-overall]')).to_have_text('86.00')
        assert not page.locator('th[data-grade-term=prelim]:visible').count()
        page.keyboard.press('Escape');page.goto(base+'/admin/?page=grades&student_q=2026-0001');lookup=page.locator('[data-lookup-student-grades-tbody]')
        expect(lookup.locator('tr').first).to_be_visible();assert not lookup.locator('[data-grade-term=prelim]:visible').count()
        # Students see the same selected periods in personal records and profiles.
        context.clear_cookies();login('2026-0001');page.goto(base+'/index/?page=grades&view=personal')
        assert not page.locator('[data-grade-term=prelim]:visible').count();expect(page.locator('[data-card-label=Endterms]').first).to_be_visible();page.get_by_role('button',name='List view',exact=True).click();expect(page.get_by_role('columnheader',name='Endterms')).to_be_visible()
        assert page.request.get(base+'/grades.php?mode=settings').status==403
        token=page.locator('body').get_attribute('data-bulk-token');assert post({'action':'update_grading_settings','_token':token,'has_final':'1','final_weight':'100','passing_grade':'75'})['status']==403
        page.goto(base+'/index/?page=profile');assert not page.locator('[data-grade-term=prelim]:visible').count()
        context.clear_cookies();login('TCH-001');page.goto(base+'/index/?page=grades&offering_id=1&student_id=5')
        teacherEdit=page.locator('[data-grade-edit-dialog]');expect(teacherEdit).to_be_visible();expect(teacherEdit.locator('[name=prelim]')).to_be_disabled()
        teacherEdit.locator('[name=midterm]').fill('80');teacherEdit.locator('[name=final]').fill('90');expect(teacherEdit.locator('[data-grade-overall-preview]')).to_have_text('86')
        teacherEdit.locator('[data-grade-save-draft]').click();expect(teacherEdit).not_to_be_visible()
        page.locator('[data-grade-edit-student="5"]').click();expect(teacherEdit).to_be_visible();teacherEdit.locator('[data-grade-finalize]').click();expect(teacherEdit).not_to_be_visible()
        page.locator('[data-grade-edit-student="5"]').click();expect(teacherEdit.locator('[name=midterm]')).to_be_disabled();page.keyboard.press('Escape')
        # Advanced entry excludes Prelims as well.
        page.locator('[data-dialog-open="#grade-manager-portal-advanced"]').click();advanced=page.locator('[data-grade-advanced-form]');expect(advanced.locator('[name=prelim]')).to_be_disabled();page.keyboard.press('Escape')
        context.clear_cookies();login('ADMIN-001');page.goto(base+'/admin/?page=grades');page.locator('[data-admin-grade-subject-results] .grade-subject-card').filter(has_text='IT 301').first.click();page.locator('[data-admin-grade-roster-results] tr').filter(has_text='2026-0001').locator('[data-edit-student-grade]').click()
        edit=page.locator('#admin-grade-edit-dialog');expect(edit.locator('[name=midterm]')).to_be_disabled();page.once('dialog',lambda d:d.accept());edit.locator('[data-admin-grade-reopen-btn]').click();expect(edit).not_to_be_visible()
        # A policy administrator does not need access to private grade records.
        context.clear_cookies();login('POLICY');page.goto(base+'/admin/?page=attendance');page.locator('[data-dialog-open="#admin-attendance-rules-dialog"]').click()
        form=page.locator('[data-grading-policy-form]');expect(form).to_be_visible();assert not page.locator('[data-attendance-rules-form]').count()
        form.locator('[type=submit]').click();expect(form.locator('[data-grading-policy-feedback]')).to_contain_text('Saved.');assert page.request.get(base+'/grades.php?mode=roster&offering_id=1').status==403
        context.clear_cookies();assert page.request.get(base+'/grades.php?mode=settings').status==401
        assert not errors,errors
        browser.close();print('Admin, teacher, student, custom permissions, settings saves, previews, disabled inputs, mobile themes, keyboard, and JSON failures passed.',flush=True)
finally:
    if server: server.terminate();server.wait(timeout=5)
    with db.cursor() as c:
        c.execute(f'DROP DATABASE IF EXISTS `{name}`');c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
    db.close()
