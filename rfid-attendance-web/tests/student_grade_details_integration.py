"""Personal grade teaching teams stay within the exact subject, section and semester."""
from pathlib import Path
import json, os, re, subprocess, sys, time, uuid, urllib.request

WEB = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(WEB.parent / '.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect

database = 'student_grade_' + uuid.uuid4().hex
username = 'grades_' + uuid.uuid4().hex[:12]
password = uuid.uuid4().hex
base = 'http://127.0.0.1:8156'
db = pymysql.connect(host='127.0.0.1', port=3340, user='root', autocommit=True)
server = log = None
try:
    with db.cursor() as c:
        c.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4')
        c.execute(f'USE `{database}`')
        for source in ['schema.sql', 'seed.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB / 'database' / source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): c.execute(statement)
        c.execute('ALTER TABLE events ADD COLUMN is_announcement BOOLEAN NOT NULL DEFAULT FALSE')
        c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'")
        c.execute(f"GRANT ALL ON `{database}`.* TO '{username}'@'127.0.0.1'")
    env = os.environ.copy()
    env.update(DB_HOST='127.0.0.1', DB_PORT='3340', DB_DATABASE=database, DB_USERNAME=username,
               DB_PASSWORD=password, APP_ENV='testing', APP_DEBUG='true', APP_URL=base)
    probe = r'''
        require getcwd().'/src/bootstrap.php';
        use App\Core\Database;use App\Core\Auth;
        $pdo=Database::connection();
        App\Services\AnalyticsSchema::install($pdo);App\Services\EventTrackingSchema::install($pdo);
        $title='Advanced Systems Administration and Maintenance with Integrated Laboratory Applications and Collaborative Research Methodologies';
        $pdo->prepare('UPDATE courses SET title=? WHERE id=1')->execute([$title]);
        $pdo->exec('UPDATE users SET first_name="Alexandria Cassandra",middle_name="Isabella Marielle",last_name="Del Rosario" WHERE id=2');
        $pdo->exec('INSERT INTO class_offerings(course_id,section_id,semester_id,component_type,room_id) VALUES(1,1,1,"LAB",2)');$lab=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO teacher_assignments(class_offering_id,teacher_id) VALUES(?,2),(?,3)')->execute([$lab,$lab]);
        $pdo->prepare('INSERT INTO class_enrollments(class_offering_id,student_id) VALUES(?,5)')->execute([$lab]);
        foreach([[1,2,1],[1,1,2]] as $cohort) {
            $pdo->prepare('INSERT INTO class_offerings(course_id,section_id,semester_id,component_type,room_id) VALUES(?,?,?,"LEC",1)')->execute($cohort);
            $other=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO teacher_assignments(class_offering_id,teacher_id) VALUES(?,4)')->execute([$other]);
        }
        $pdo->exec('DELETE FROM teacher_assignments WHERE class_offering_id=2');
        $pdo->exec('UPDATE grading_settings SET has_prelim=0,has_midterm=1,has_final=1 WHERE id=1');
        Auth::attempt('2026-0001','Password123!');
        $method=new ReflectionMethod(App\Controllers\PortalController::class,'grades');
        $rows=$method->invoke(null,$pdo,Auth::user())['gradeRows'];
        $row=array_values(array_filter($rows,fn($r)=>(int)$r['class_offering_id']===1))[0];
        $expected='Alexandria Cassandra Isabella Marielle Del Rosario, Daniel Reyes';
        if ($row['teacher_names']!==$expected) throw new RuntimeException('Teaching team duplicated or mixed with another cohort: '.$row['teacher_names']);
        if (count(array_filter($rows,fn($r)=>(int)$r['class_offering_id']===1))!==1) throw new RuntimeException('Shared lecture/lab subject duplicated.');
        $missing=array_values(array_filter($rows,fn($r)=>(int)$r['class_offering_id']===2))[0];
        if ($missing['teacher_names']!==null) throw new RuntimeException('Missing teacher was invented.');
        $stored=$pdo->query('SELECT overall FROM grades WHERE student_id=5 AND class_offering_id=1')->fetchColumn();
        if ($row['overall']!==$stored) throw new RuntimeException('Teacher join changed the grade.');
        echo json_encode(['title'=>$title,'teachers'=>$expected,'overall'=>$stored,'lab'=>$lab,'code'=>$row['course_code']]);
    '''
    result = subprocess.run(['php','-r',probe], cwd=WEB, env=env, capture_output=True, encoding='utf-8')
    assert result.returncode == 0 and not result.stderr, result.stdout + result.stderr
    fixture = json.loads(result.stdout)
    # Use the application's real table widget rather than a stub.
    cache = WEB.parent / '.codex-tmp/student-grade-datatable.js'
    if not cache.exists():
        with urllib.request.urlopen('https://cdn.jsdelivr.net/npm/simple-datatables@10', timeout=30) as response:
            cache.write_bytes(response.read())
    log = open(WEB.parent / '.codex-tmp/student-grade-server.log', 'w', encoding='utf-8')
    server = subprocess.Popen(['php','-S','127.0.0.1:8156','-t','public'], cwd=WEB, env=env,
                              stdout=subprocess.DEVNULL, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.7)
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        context = browser.new_context(viewport={'width':1440,'height':1000})
        page = context.new_page(); errors=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        def route(request):
            url=request.request.url
            if url.startswith(base):return request.continue_()
            if 'simple-datatables@10' in url and not url.endswith('.css'):return request.fulfill(path=str(cache),content_type='text/javascript')
            return request.abort()
        page.route('**/*',route)
        page.goto(base+'/auth/login.php')
        page.locator('[name=identifier]').fill('2026-0001');page.locator('[name=password]').fill('Password123!')
        page.locator('button[type=submit]').click();page.wait_for_url(re.compile('/index/'))
        page.goto(base+'/index/?page=grades&view=personal')
        records=page.locator('#student-grade-records')
        page.wait_for_function("!!document.querySelector('#student-grade-records table')._dataTableInstance")
        row=records.locator('tbody tr').filter(has=page.locator('.student-grade-subject-link',has_text=fixture['code']))
        expect(row.locator('.student-grade-title')).to_have_text(fixture['title'])
        expect(row.locator('.student-grade-teacher-name')).to_have_text(fixture['teachers'])
        expect(row.locator('.student-grade-component')).to_have_text('LEC + LAB')
        expect(records).to_contain_text('No teacher assigned')
        for theme in ['light','dark']:
            for view in ['cards','list']:
                page.evaluate('(v)=>document.documentElement.dataset.theme=v',theme)
                page.locator(f'[data-record-view={view}]').click()
                expect(row.locator('.student-grade-teacher-name')).to_be_visible()
                expect(row.locator('.student-grade-title')).to_be_visible()
                assert row.locator('.student-grade-title').evaluate('(e)=>getComputedStyle(e).whiteSpace==="normal" && e.scrollWidth<=e.clientWidth+1')
                assert row.locator('.student-grade-teacher-name').evaluate('(e)=>getComputedStyle(e).whiteSpace==="normal" && e.scrollWidth<=e.clientWidth+1')
            page.locator('[data-record-view=cards]').click()
            page.set_viewport_size({'width':390,'height':844})
            page.wait_for_function("innerWidth===390 && matchMedia('(max-width:700px)').matches")
            assert row.locator('.student-grade-title').evaluate('(e)=>e.scrollHeight<=e.clientHeight+1')
            page.evaluate('new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))')
            page.screenshot(path=str(WEB.parent/'.codex-tmp/student-grade-mobile.png'))
            assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'), page.evaluate('Array.from(document.querySelectorAll("body *")).filter(e=>e.getBoundingClientRect().right>innerWidth+1&&e.getBoundingClientRect().width).map(e=>({tag:e.tagName,classes:e.className,width:e.getBoundingClientRect().width})).slice(0,14)')
            page.set_viewport_size({'width':1440,'height':1000})
        search=records.locator('.datatable-input');search.fill('Daniel')
        expect(records.locator('tbody tr')).to_have_count(1)
        expect(records).to_contain_text(fixture['code'])
        search.fill('')
        page.screenshot(path=str(WEB.parent/'.codex-tmp/student-grade-cards.png'))
        page.locator('[data-record-view=list]').click()
        page.screenshot(path=str(WEB.parent/'.codex-tmp/student-grade-list.png'))
        page.goto(base+f'/index/?page=grades&view=personal&offering_id={fixture["lab"]}')
        expect(page.locator('.student-grade-teacher-name')).to_have_text(fixture['teachers'])
        assert not errors,errors
        browser.close()
    print('Exact teaching-team scope, lecture/lab deduplication, unchanged grades, searchable teacher names, long details and light/dark card/list layouts passed.')
finally:
    if server:server.terminate();server.wait(timeout=10)
    if log:log.close()
    with db.cursor() as c:
        c.execute(f'DROP DATABASE IF EXISTS `{database}`')
        c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
    db.close()
