"""Shared lecture/lab grades and component controls; disposable MariaDB/browser fixtures only."""
from pathlib import Path
import json, os, re, subprocess, sys, time, uuid
WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect
expect.set_options(timeout=15000)
database = 'shared_' + uuid.uuid4().hex
username = 'shared_' + uuid.uuid4().hex[:12]
password = uuid.uuid4().hex
base = 'http://127.0.0.1:8144'
server = None
PROBE = r'''
require getcwd().'/src/bootstrap.php'; $_ENV['DB_PASSWORD']='';
use App\Core\Auth;use App\Core\Database;use App\Services\GradeService as G;
use App\Services\SharedGradeService as S;use App\Services\AcademicManagementService as A;
use App\Services\AnalyticsService;use App\Services\AnalyticsSchema;use App\Services\AuditSchema;
$pdo=Database::connection();function check($v,$m){if(!$v)throw new RuntimeException($m);}
function denied($callback,$message){try{$callback();throw new RuntimeException($message);}catch(DomainException $e){}}
$pdo->prepare('UPDATE users SET password_hash=?')->execute([password_hash('Password123!',PASSWORD_DEFAULT)]);
AnalyticsSchema::install($pdo);AuditSchema::install($pdo);
Auth::attempt('ADMIN-001','Password123!');
$pdo->exec('INSERT INTO class_offerings(course_id,section_id,semester_id,component_type,room_id) VALUES(1,1,1,"LAB",2)');$lab=(int)$pdo->lastInsertId();
$pdo->exec('INSERT INTO teacher_assignments(class_offering_id,teacher_id) VALUES('.$lab.',3)');
$pdo->exec('INSERT INTO class_schedules(class_offering_id,room_id,day_of_week,start_time,end_time) VALUES('.$lab.',2,"Thursday","15:00:00","17:00:00")');
$pdo->exec('INSERT INTO class_enrollments(class_offering_id,student_id) SELECT '.$lab.',student_id FROM class_enrollments WHERE class_offering_id=1');
$pdo->exec('INSERT INTO class_enrollments(class_offering_id,student_id) VALUES('.$lab.',15)');
$pdo->exec('UPDATE users SET first_name="Cyrine",middle_name="Dean",last_name="Aguarte" WHERE id=15');
$pdo->exec('UPDATE users SET first_name="Cyrine",middle_name=NULL,last_name="Outside" WHERE id=21');
$subject=array_values(array_filter(G::subjects(1)['subjects'],fn($r)=>$r['id']===1))[0];
check(G::subjects(1)['pagination']['total']===6,'Lecture/lab creates duplicate subject');
check($subject['component_type']==='LEC + LAB' && count($subject['schedule'])===2,'Components or meetings not merged');
$lec=G::roster(1,1);$paired=G::roster($lab,1);
check($paired['subject']['id']==1 && $lec['pagination']['total']===$paired['pagination']['total'],'Component direct links disagree');
check(count(array_filter($paired['students'],fn($r)=>$r['student_id']===15))===1,'Lab-only student missing');
$source=G::studentRecord($lab,5,1);$gradeId=$source['grade_id'];
check($source['status']==='finalized','Existing finalized grade lost');
denied(fn()=>G::save(['class_offering_id'=>$lab,'student_id'=>5,'midterm'=>1,'final'=>1],1),'Finalized shared grade edited');
G::reopen($gradeId,1);
Auth::attempt('TCH-002','Password123!');
$teacher=(int)Auth::user()['id'];check($teacher===3,'Unexpected fixture teacher');
$teacherSearch=G::searchStudents($teacher,'Cyrine');
check($teacherSearch['scope']==='assigned_classes' && $teacherSearch['pagination']['total']===1 && $teacherSearch['students'][0]['id']===15,'Teacher transcript search does not match assigned-class scope');
check(G::searchStudents($teacher,'Dean Aguarte')['students'][0]['id']===15,'Multi-part student name search failed');
check(G::searchStudents($teacher,'2026-0011')['students'][0]['id']===15,'Student school ID search failed');
check(G::roster(1,$teacher)['subject']['id']==1,'Lab teacher cannot open lecture representative');
$saved=G::save(['class_offering_id'=>$lab,'student_id'=>5,'midterm'=>80,'final'=>90],$teacher);
check($saved['overall']==86 && G::studentRecord(1,5,$teacher)['overall']==86,'Component save not shared');
check((int)$pdo->query('SELECT COUNT(*) FROM grades WHERE student_id=5 AND class_offering_id IN (1,'.$lab.')')->fetchColumn()===1,'New physical duplicate grade');
denied(fn()=>G::studentRecord(4,15,$teacher),'Unrelated class grade exposed');
$transcript=G::studentGradesOverview(5,$teacher);
check(count(array_filter($transcript['grades'],fn($r)=>(int)$r['class_offering_id']===1))===1,'Transcript component duplicate');
check(!array_filter($transcript['grades'],fn($r)=>(int)$r['class_offering_id']===3),'Transcript exposed unrelated teacher class');
denied(fn()=>G::reopen($gradeId,$teacher),'Teacher granted academic records authority');
Auth::attempt('ADMIN-001','Password123!');
$campusSearch=G::searchStudents(1,'Cyrine');
check($campusSearch['scope']==='campus' && $campusSearch['pagination']['total']===2,'Global transcript search lost campus students');
$availability=A::componentAvailability(1,1,1);check(count($availability['components'])===2,'Occupied component missing');
check(count(A::componentAvailability(1,1,1,$lab)['components'])===1,'Editor does not exclude itself');
check(!A::componentAvailability(1,2,1)['components'],'Different section disabled');
check(!A::componentAvailability(1,1,2)['components'],'Different term disabled');
$data=['course_id'=>1,'offering_program_id'=>1,'section_id'=>1,'offering_school_year_id'=>1,'semester_id'=>1,'teacher_id'=>2,'component_type'=>'LEC','schedules'=>[['day_of_week'=>'Thursday','start_time'=>'19:00','end_time'=>'20:00','room_id'=>1]]];
denied(fn()=>A::createOffering($data,1),'Existing component reused on creation');
$edit=['id'=>1,'course_id'=>2,'program_id'=>1,'section_id'=>1,'school_year_id'=>1,'semester_id'=>1,'teacher_id'=>2,'component_type'=>'LEC','status'=>'active','schedules'=>[['day_of_week'=>'Monday','start_time'=>'08:00','end_time'=>'10:00','room_id'=>1]]];
denied(fn()=>A::save('offering',$edit,1),'Graded subject moved to unrelated course');
check((int)$pdo->query('SELECT course_id FROM class_offerings WHERE id=1')->fetchColumn()===1,'Failed offering edit changed grade identity');
$before=(int)$pdo->query('SELECT COUNT(*) FROM class_schedules WHERE class_offering_id=1')->fetchColumn();
check($before===1,'Duplicate creation mutated schedules');
# Laboratory first must still produce one grade when its lecture is added later.
$data['course_id']=3;$data['section_id']=2;$data['offering_program_id']=2;$data['component_type']='LAB';$data['teacher_id']=4;
$new=A::createOffering($data,1);$first=(int)$new['record']['id'];
$saved=G::save(['class_offering_id'=>$first,'student_id'=>15,'midterm'=>70,'final'=>80],1);
$data['component_type']='LEC';$data['schedules'][0]['day_of_week']='Tuesday';$data['schedules'][0]['start_time']='19:00';$data['schedules'][0]['end_time']='20:00';
$next=A::createOffering($data,1);$later=(int)$next['record']['id'];
check(G::studentRecord($later,15,1)['grade_id']===$saved['grade_id'],'Lecture added after lab lost grade identity');
# Conflicting legacy grades are null in read models until explicit authorized reconciliation.
$pdo->exec('INSERT INTO grades(class_offering_id,student_id,midterm,final,overall,status,entered_by) VALUES('.$lab.',5,20,30,26,"draft",3)');$conflictId=(int)$pdo->lastInsertId();
$conflict=G::studentRecord($lab,5,1);check($conflict['status']==='conflict' && $conflict['overall']===null && count($conflict['conflict_grades'])===2,'Disagreeing grades chosen silently');
denied(fn()=>G::save(['class_offering_id'=>1,'student_id'=>5,'midterm'=>99,'final'=>99],1),'Conflict bypassed');
Auth::attempt('TCH-002','Password123!');denied(fn()=>G::resolveShared(1,5,$conflictId,3),'Teacher reconciled protected records');
Auth::attempt('ADMIN-001','Password123!');denied(fn()=>G::resolveShared(1,5,999999,1),'Unrelated source grade accepted');
$resolved=G::resolveShared($lab,5,$gradeId,1);check($resolved['status']==='draft' && $resolved['overall']==86,'Chosen grade did not become shared draft');
check(count(S::originals(1,5))===2,'Original grade IDs removed');
$final=G::save(['class_offering_id'=>$lab,'student_id'=>5,'midterm'=>80,'final'=>90,'finalize'=>1],1);
check($final['status']==='finalized' && !S::conflicting(S::originals(1,5)),'Original rows not synchronized');
check((int)$pdo->query('SELECT COUNT(*) FROM audit_logs WHERE entity_type="grades" AND source="database"')->fetchColumn()>0,'Grade changes lack audit trail');
$analytics=new AnalyticsService(['from'=>date('Y-m-d'),'to'=>date('Y-m-d'),'semester_id'=>1,'offering_id'=>$lab]);$result=$analytics->response('grades');
$rows=$result['panels'][0]['rows'];check(count($rows)===1 && $rows[0]['offering_id']==1 && $rows[0]['sample']==(int)$pdo->query('SELECT COUNT(DISTINCT student_id) FROM grades WHERE class_offering_id IN (1,'.$lab.') AND status="finalized"')->fetchColumn(),'Analytics double counted shared grade or lost component filter');
check($rows[0]['known']===1,'Original finalized policy snapshot not found');
G::reopen($gradeId,1);check(!S::conflicting(S::originals(1,5)),'Reopen did not cover both source rows');
# Leave disagreement to exercise the review dialog in the browser.
$pdo->exec('UPDATE grades SET midterm=40,final=50,overall=46 WHERE id='.$conflictId);
echo json_encode(['lab'=>$lab,'grade'=>$gradeId,'first'=>$first,'later'=>$later]);
'''
db = pymysql.connect(host='127.0.0.1', port=3340, user='root', autocommit=True)
try:
    with db.cursor() as c:
        c.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4'); c.execute(f'USE `{database}`')
        for source in ['schema.sql', 'seed.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB/'database'/source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): c.execute(statement)
        c.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
        c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'")
        c.execute(f"GRANT ALL ON `{database}`.* TO '{username}'@'127.0.0.1'")
    env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='3340',DB_DATABASE=database,DB_USERNAME='root',DB_PASSWORD='',APP_ENV='testing',APP_DEBUG='true')
    result=subprocess.run(['php','-r',PROBE],cwd=WEB,env=env,capture_output=True,text=True)
    assert result.returncode==0,result.stdout+result.stderr
    ids=json.loads(result.stdout); print('Shared subject identity, rosters, access, grades, conflicts, snapshots, and analytics passed.',flush=True)
    env.update(DB_USERNAME=username,DB_PASSWORD=password,APP_URL=base)
    server=subprocess.Popen(['php','-S','127.0.0.1:8144','-t','public'],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.8)
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
        context=browser.new_context(viewport={'width':1440,'height':1000});context.route(re.compile(r'https://fonts\.(googleapis|gstatic)\.com/'),lambda route:route.abort());page=context.new_page();errors=[]
        page.on('pageerror',lambda e:errors.append(e.stack))
        def login(identifier):
            context.clear_cookies()
            page.goto(base+'/auth/login.php');page.locator('[name=identifier]').fill(identifier);page.locator('[name=password]').fill('Password123!');page.locator('button[type=submit]').click();page.wait_for_url(re.compile(r'/(admin|index)/$'))
        def get(path): return page.request.get(base+path).json()
        login('TCH-002')
        scoped_search=get('/grades.php?mode=student_search&q=Cyrine')
        assert scoped_search['scope']=='assigned_classes' and scoped_search['pagination']['total']==1 and scoped_search['students'][0]['id']==15,scoped_search
        page.goto(base+f'/index/?page=grades&offering_id={ids["lab"]}&student_id=5')
        teacher_review=page.locator('[data-grade-edit-form]');expect(teacher_review).to_be_visible();expect(teacher_review.locator('[data-shared-grade-review]')).to_be_visible();expect(teacher_review.locator('[data-shared-grade-sources]')).to_contain_text('Prelims (stored)');expect(teacher_review.locator('[data-shared-grade-resolve]')).to_be_hidden();expect(teacher_review.locator('[name=source_grade_id]').first).to_be_disabled()
        login('ADMIN-001');page.goto(base+f'/admin/?page=grades&offering_id={ids["lab"]}&student_id=5')
        dialog=page.locator('[data-admin-grade-edit-form]');expect(dialog).to_be_visible();expect(dialog.locator('[data-shared-grade-review]')).to_be_visible()
        expect(dialog.locator('[name=midterm]')).to_be_disabled();expect(dialog.locator('[type=submit]')).to_be_disabled()
        dialog.locator('[name=source_grade_id]').first.check();
        with page.expect_response(lambda r: r.url.endswith('/grades.php') and r.request.method=='POST') as submission:
            dialog.locator('[data-shared-grade-resolve]').click()
        assert submission.value.status==200,submission.value.json()
        expect(dialog.locator('[data-shared-grade-review]')).to_be_hidden();expect(dialog.locator('[name=midterm]')).to_have_value('80.00')
        expect(dialog.locator('[name=midterm]')).to_be_enabled();dialog.locator('[data-dialog-close]').click()
        page.goto(base+'/admin/?page=academics')
        create=page.locator('[data-create-offering-form]')
        def select(form,name,value):
            form.locator(f'[name="{name}"]').evaluate('(el,value)=>{el.value=value;el._choicesInstance?.setChoiceByValue(value);el.dispatchEvent(new Event("change",{bubbles:true}));}',str(value))
        select(create,'course_id',1);select(create,'offering_program_id',1);select(create,'section_id',1);select(create,'offering_school_year_id',1);select(create,'semester_id',1)
        expect(create.locator('[data-component-availability]')).to_contain_text('Both components exist')
        expect(create.locator('[value=LEC][name=component_type]')).to_be_disabled();expect(create.locator('[value=LAB][name=component_type]')).to_be_disabled()
        assert create.locator('[data-component-availability] a').count()==2
        assert create.locator('[data-component-availability] a').first.get_attribute('target')=='_blank'
        select(create,'course_id',2)
        expect(create.locator('[value=LEC][name=component_type]')).to_be_disabled();expect(create.locator('[value=LAB][name=component_type]')).to_be_enabled()
        expect(create.locator('[value=LAB][name=component_type]')).to_be_checked()
        select(create,'offering_program_id',2);select(create,'section_id',2)
        expect(create.locator('[value=LEC][name=component_type]')).to_be_enabled();expect(create.locator('[value=LAB][name=component_type]')).to_be_enabled()
        create.locator('[value=LEC][name=component_type]').check();create.locator('[value=LEC][name=component_type]').focus();page.keyboard.press('ArrowRight')
        expect(create.locator('[value=LAB][name=component_type]')).to_be_checked()
        # Context churn and theme/mobile controls must not leave a stale occupied state.
        select(create,'course_id',1);select(create,'offering_program_id',1);select(create,'section_id',1);select(create,'semester_id',1);select(create,'course_id',2)
        expect(create.locator('[value=LAB][name=component_type]')).to_be_enabled()
        for theme in ['dark','light']:
            page.evaluate('(theme)=>document.documentElement.dataset.theme=theme',theme)
            page.set_viewport_size({'width':390,'height':844});expect(create.locator('[data-offering-components]')).to_be_visible()
            page.wait_for_timeout(600)
            width=page.evaluate('({viewport:innerWidth,document:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll("body *")].filter(e=>e.getBoundingClientRect().right>innerWidth+1 && e.getBoundingClientRect().width>0).slice(0,8).map(e=>[e.tagName,e.className,Math.round(e.getBoundingClientRect().right)])})')
            assert width['document']<=width['viewport']+1,width
        page.set_viewport_size({'width':1440,'height':1000})
        # Reopening the same dynamic editor must not accumulate stale submit handlers.
        for entity in [1,ids['lab'],2]:
            page.goto(base+f'/admin/?page=academics&manage=offering&entity_id={entity}')
            editor=page.locator('[data-academic-form]');expect(editor).to_be_visible()
            expected='LAB' if entity==ids['lab'] else 'LEC'
            expect(editor.locator(f'[name=component_type][value={expected}]')).to_be_enabled()
            expect(editor.locator(f'[name=component_type][value={expected}]')).to_be_checked()
            editor.locator('[data-dialog-close]').click() if editor.locator('[data-dialog-close]').count() else page.locator('[data-academic-management] [data-dialog-close]').first.click()
        login('TCH-002');page.goto(base+f'/index/?page=grades&offering_id={ids["lab"]}&student_id=5')
        expect(page.locator('[data-grade-edit-form]')).to_be_visible();expect(page.locator('[data-grade-edit-subject]')).to_contain_text('IT 301')
        assert get('/admin/academic-entity.php?mode=components&course_id=1&section_id=1&semester_id=1')['ok'] is False
        login('2026-0001');page.goto(base+f'/index/?page=grades&view=personal&offering_id={ids["lab"]}')
        rows=page.locator('#student-grade-records tbody tr');assert rows.count()==1
        expect(rows.first).to_contain_text('LEC + LAB');expect(rows.first).to_contain_text('86.00')
        assert not errors,errors
        browser.close()
    print('Admin/teacher/student navigation, conflict review, component switches, keyboard, themes, and mobile passed.',flush=True)
finally:
    if server: server.terminate();server.wait(timeout=10)
    with db.cursor() as c:
        c.execute(f'DROP DATABASE IF EXISTS `{database}`');c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
    db.close()
