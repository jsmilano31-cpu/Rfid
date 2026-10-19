"""Recollection date ranges and native Flatpickr navigation with real Choices integration."""
from pathlib import Path
import json, os, re, subprocess, sys, time, uuid, urllib.request
WEB = Path(__file__).resolve().parent.parent
TMP = WEB.parent / '.codex-tmp'
sys.path.insert(0, str(TMP / 'audit-deps'))
import pymysql
from playwright.sync_api import sync_playwright, expect

database = 'recollection_' + uuid.uuid4().hex
username = 'recol_' + uuid.uuid4().hex[:12]
password = uuid.uuid4().hex
base = 'http://127.0.0.1:8157'
db = pymysql.connect(host='127.0.0.1', port=3340, user='root', autocommit=True)
server = log = None
try:
    with db.cursor() as c:
        c.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4'); c.execute(f'USE `{database}`')
        for source in ['schema.sql', 'seed.sql']:
            sql = re.sub(r'^\s*--.*$', '', (WEB / 'database' / source).read_text(encoding='utf-8-sig'), flags=re.M)
            for statement in sql.split(';'):
                if statement.strip(): c.execute(statement)
        c.execute('ALTER TABLE events ADD COLUMN is_announcement BOOLEAN NOT NULL DEFAULT FALSE')
        # Exercise the upgrade from the old, single-date schema.
        c.execute('ALTER TABLE recollections DROP CONSTRAINT chk_recollection_dates, DROP INDEX idx_recollection_dates, DROP COLUMN end_date')
        c.execute(f"CREATE USER '{username}'@'127.0.0.1' IDENTIFIED BY '{password}'")
        c.execute(f"GRANT ALL ON `{database}`.* TO '{username}'@'127.0.0.1'")
    env = os.environ.copy()
    env.update(DB_HOST='127.0.0.1', DB_PORT='3340', DB_DATABASE=database, DB_USERNAME=username,
               DB_PASSWORD=password, APP_ENV='testing', APP_DEBUG='true', APP_URL=base)
    probe = r'''
        require getcwd().'/src/bootstrap.php';
        use App\Core\Database;use App\Core\Auth;use App\Services\RecollectionService as R;
        use App\Services\RecollectionScheduleSchema as S;
        $pdo=Database::connection();App\Services\AnalyticsSchema::install($pdo);App\Services\EventTrackingSchema::install($pdo);
        $baseline=$pdo->query('SELECT baseline_at FROM analytics_state WHERE id=1')->fetchColumn();
        $pdo->exec('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id JOIN roles r ON r.id=rp.role_id WHERE p.code="view_analytics" AND r.name="Teacher"');
        $grants=$pdo->query('SELECT role_id,permission_id FROM role_permissions ORDER BY role_id,permission_id')->fetchAll();
        $legacy=$pdo->query('SELECT id,date FROM recollections')->fetchAll();
        S::install($pdo);S::install($pdo);
        if($baseline!==$pdo->query('SELECT baseline_at FROM analytics_state WHERE id=1')->fetchColumn())throw new RuntimeException('Baseline changed.');
        if($grants!==$pdo->query('SELECT role_id,permission_id FROM role_permissions ORDER BY role_id,permission_id')->fetchAll())throw new RuntimeException('Revoked grants restored.');
        foreach($legacy as $row){$q=$pdo->prepare('SELECT end_date FROM recollections WHERE id=?');$q->execute([$row['id']]);if($q->fetchColumn()!==$row['date'])throw new RuntimeException('Legacy range changed.');}
        $missing=(int)$pdo->query("SELECT COUNT(*) FROM analytics_history WHERE source_table='recollections' AND JSON_CONTAINS_PATH(payload,'one','$.end_date')=0")->fetchColumn();if($missing)throw new RuntimeException('Legacy history not extended.');
        Auth::attempt('ADMIN-001','Password123!');
        $today=date('Y-m-d');$yesterday=date('Y-m-d',strtotime('-1 day'));$tomorrow=date('Y-m-d',strtotime('+1 day'));
        $data=['title'=>'Range Fixture Retreat','type'=>'Retreat','section_id'=>1,'date'=>$yesterday,'end_date'=>$tomorrow,'start_time'=>'18:00','end_time'=>'08:00','venue'=>'Formation Hall','attendance_required'=>1];
        $id=R::create($data,1);$row=R::findForManagement($id);if($row['end_date']!==$tomorrow)throw new RuntimeException('Multi-day range lost.');
        $entry=App\Services\CalendarService::recollectionById($id);if($entry['to']!==$tomorrow.'T08:00:00')throw new RuntimeException('Calendar ends on wrong date.');
        $oldClient=$data;unset($oldClient['end_date']);R::update($id,$oldClient,1);if(R::findForManagement($id)['end_date']!==$tomorrow)throw new RuntimeException('Old client collapsed range.');
        $single=$data;$single['title']='Single Day Fixture';$single['date']=$today;unset($single['end_date']);$single['start_time']='09:00';$single['end_time']='10:00';$sid=R::create($single,1);if(R::findForManagement($sid)['end_date']!==$today)throw new RuntimeException('Single-day default failed.');
        foreach([['date'=>'2026-02-30'],['end_date'=>'2026-13-01'],['end_date'=>date('Y-m-d',strtotime('-2 days'))],['end_date'=>$yesterday,'end_time'=>'18:00'],['end_date'=>$yesterday,'end_time'=>'08:00'],['start_time'=>'25:30']] as $bad){try{R::create(array_replace($data,$bad),1);throw new RuntimeException('Invalid schedule accepted.');}catch(DomainException $expected){}}
        $data['end_date']=$today;$data['end_time']='19:00';R::update($id,$data,1);
        $q=$pdo->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(payload,'$.end_date')) FROM analytics_history WHERE source_table='recollections' AND record_key=? AND valid_to IS NULL");$q->execute([(string)$id]);if($q->fetchColumn()!==$today)throw new RuntimeException('Correction history missing.');
        $q=$pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE entity_type='recollections' AND entity_id=? AND operation='UPDATE' AND after_values LIKE '%end_date%'");$q->execute([$id]);if(!$q->fetchColumn())throw new RuntimeException('End-date edit lacks audit coverage.');
        $a=new App\Services\AnalyticsService(['from'=>$today,'to'=>$today,'semester_id'=>'0']);$result=$a->response('activities');
        $rows=$result['panels'][0]['rows'];if(!array_filter($rows,fn($r)=>$r['label']==='Range Fixture Retreat'&&$r['end_date']===$today))throw new RuntimeException('Overlapping activity missing from analytics.');
        $data['end_date']=$tomorrow;$data['end_time']='08:00';R::update($id,$data,1);
        echo json_encode(['id'=>$id,'today'=>$today,'tomorrow'=>$tomorrow]);
    '''
    result = subprocess.run(['php','-r',probe], cwd=WEB, env=env, capture_output=True, encoding='utf-8')
    assert result.returncode == 0 and not result.stderr, result.stdout + result.stderr
    fixture = json.loads(result.stdout)
    assets = {}
    urls = {
        'flatpickr.min.js':'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js',
        'flatpickr.min.css':'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css',
        'choices.min.js':'https://cdn.jsdelivr.net/npm/choices.js@11.1.0/public/assets/scripts/choices.min.js',
        'choices.min.css':'https://cdn.jsdelivr.net/npm/choices.js@11.1.0/public/assets/styles/choices.min.css',
    }
    for name,url in urls.items():
        cache=TMP/('recollection-'+name)
        if not cache.exists():
            with urllib.request.urlopen(url,timeout=30) as response:cache.write_bytes(response.read())
        assets[url]=cache
    log=open(TMP/'recollection-server.log','w',encoding='utf-8')
    server=subprocess.Popen(['php','-S','127.0.0.1:8157','-t','public'],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
    time.sleep(.7)
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
        context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();errors=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        def route(request):
            url=request.request.url
            if url in assets:return request.fulfill(path=str(assets[url]),content_type='text/css' if url.endswith('.css') else 'text/javascript')
            if url.startswith(base):return request.continue_()
            return request.abort()
        page.route('**/*',route)
        page.goto(base+'/auth/login.php');page.locator('[name=identifier]').fill('ADMIN-001');page.locator('[name=password]').fill('Password123!');page.locator('button[type=submit]').click();page.wait_for_url(re.compile('/admin/'))
        page.goto(base+'/admin/?page=recollections')
        form=page.locator('form').filter(has=page.locator('[name=action][value=create_recollection]'))
        page.wait_for_function("!!document.querySelector('[data-activity-start-date]')._flatpickr && !!window.Choices")
        # Ordinary eight-option selects remain searchable while library navigation stays native.
        page.evaluate("""()=>{const s=document.createElement('select');s.id='fixture-searchable';for(let i=0;i<12;i++)s.add(new Option('Item '+i,i));document.querySelector('main').append(s)}""")
        page.wait_for_function("!!document.querySelector('#fixture-searchable')._choicesInstance")
        def picker_check(input):
            input.evaluate("e=>e._flatpickr.altInput.scrollIntoView({block:'center',behavior:'instant'})")
            input.evaluate("e=>{e._flatpickr.setDate('2026-10-07',false,'Y-m-d');e._flatpickr.open()}")
            widget=input.locator('xpath=..').locator('.flatpickr-calendar')
            expect(widget).to_be_visible()
            assert widget.locator('.choices').count()==0
            month=widget.locator('.flatpickr-monthDropdown-months');year=widget.locator('.cur-year')
            expect(month).to_have_value('9');expect(year).to_have_value('2026')
            widget.locator('.flatpickr-next-month').click();expect(month).to_have_value('10')
            widget.locator('.flatpickr-next-month').click();expect(month).to_have_value('11')
            widget.locator('.flatpickr-next-month').click();expect(month).to_have_value('0');expect(year).to_have_value('2027')
            widget.locator('.flatpickr-prev-month').click();expect(month).to_have_value('11');expect(year).to_have_value('2026')
            month.select_option('2');expect(month).to_have_value('2')
            year.fill('2028');year.press('Enter');expect(year).to_have_value('2028')
            year.hover();widget.locator('.numInputWrapper .arrowUp').click();expect(year).to_have_value('2029')
            assert year.evaluate('e=>e.clientHeight>=24 && e.clientWidth>=50')
            page.wait_for_function("(e)=>{const b=e.getBoundingClientRect();return b.left>=0 && b.right<=innerWidth+1}",arg=widget.element_handle())
            box=widget.bounding_box();width=page.evaluate('innerWidth');assert box['x']>=0 and box['x']+box['width']<=width+1,box
            input.evaluate('e=>e._flatpickr.close()')
        for theme in ['light','dark']:
            page.evaluate('(v)=>document.documentElement.dataset.theme=v',theme)
            for width in [1440,390]:
                page.set_viewport_size({'width':width,'height':1000});page.wait_for_function('(w)=>innerWidth===w',arg=width)
                page.evaluate('()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)))')
                form.evaluate("e=>{e.elements.date._flatpickr.clear(false);e.elements.end_date._flatpickr.clear(false);window.syncActivityDateRanges(e)}")
                picker_check(form.locator('[name=date]'))
                form.locator('[name=date]').evaluate("e=>e._flatpickr.setDate('2026-10-07',true,'Y-m-d')")
                expect(form.locator('[name=end_date]')).to_have_value('2026-10-07')
                form.locator('[name=end_date]').evaluate("e=>{e._flatpickr.setDate('2026-10-09',true,'Y-m-d');e._flatpickr.open()}")
                expect(form.locator('[name=end_date]').locator('xpath=..').locator('.flatpickr-monthDropdown-months')).to_have_value('9')
                form.locator('[name=end_date]').evaluate('e=>e._flatpickr.close()')
                form.locator('[name=date]').evaluate("e=>e._flatpickr.setDate('2026-10-08',true,'Y-m-d')")
                expect(form.locator('[name=end_date]')).to_have_value('2026-10-09')
                page.screenshot(path=str(TMP/f'recollection-{theme}-{width}.png'),full_page=True)
        page.set_viewport_size({'width':1440,'height':1000})
        page.locator(f'[data-manage-recollection][data-recollection-id="{fixture["id"]}"]').click()
        dialog=page.locator('#manage-recollection-dialog');expect(dialog.locator('form')).to_be_visible()
        expect(dialog.locator('[name=end_date]')).to_have_value(fixture['tomorrow'])
        expect(dialog.locator('[name=start_time]')).to_have_value('18:00')
        picker_check(dialog.locator('[name=date]'))
        dialog.locator('[name=date]').evaluate("(e,d)=>e._flatpickr.setDate(d,true,'Y-m-d')",fixture['today'])
        dialog.locator('[name=end_date]').evaluate("(e,d)=>e._flatpickr.setDate(d,true,'Y-m-d')",fixture['tomorrow'])
        dialog.locator('button[type=submit]').click();expect(dialog).not_to_be_visible()
        with db.cursor() as c:
            c.execute('SELECT date,end_date FROM recollections WHERE id=%s',(fixture['id'],));saved=c.fetchone()
            assert str(saved[0])==fixture['today'] and str(saved[1])==fixture['tomorrow'],saved
        assert not errors,errors
        browser.close()
    print('PASS: month/year navigation, Choices isolation, light/dark/mobile, editor persistence, overnight ranges, historical migration, revoked grants, audit and overlapping analytics.')
finally:
    if server:server.terminate();server.wait(timeout=10)
    if log:log.close()
    with db.cursor() as c:
        c.execute(f'DROP DATABASE IF EXISTS `{database}`');c.execute(f"DROP USER IF EXISTS '{username}'@'127.0.0.1'")
    db.close()
