"""Browser regression checks for transcript links and table sort controls."""

from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import shutil
import sys
import threading
import uuid

WEB = Path(__file__).resolve().parent.parent
WORKSPACE = WEB.parent
sys.path.insert(0, str(WORKSPACE / '.codex-tmp/audit-deps'))
from playwright.sync_api import expect, sync_playwright

root = WORKSPACE / '.codex-tmp' / ('transcript-sort-' + uuid.uuid4().hex)
(root / 'assets/js').mkdir(parents=True)
(root / 'assets/css').mkdir(parents=True)
for name in ['app.js', 'admin-control.js', 'recollection-management.js']:
    shutil.copy2(WEB / 'public/assets/js' / name, root / 'assets/js' / name)
shutil.copy2(WEB / 'public/assets/css/app.css', root / 'assets/css/app.css')
(root / 'index.html').write_text('''<!doctype html><html data-theme="dark"><head><meta charset="utf-8">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simple-datatables@10/dist/style.css">
<link rel="stylesheet" href="/assets/css/app.css"></head><body>
<div class="table-wrap"><table id="sort-table"><thead><tr><th>Activity</th><th>Section</th></tr></thead>
<tbody><tr><td>Activity 10</td><td>BSIT 4C</td></tr><tr><td>Activity 2</td><td>BSIT 2A</td></tr></tbody></table></div>
<section data-admin-grade-manager data-endpoint="/grades.php"><div data-admin-grade-subject-results></div><div data-admin-grade-subject-pagination></div>
<div data-admin-student-grade-search><input data-admin-student-grade-lookup type="search" aria-expanded="false"><span data-admin-student-grade-summary></span>
<div data-admin-student-grade-matches hidden></div></div>
<div data-admin-student-grade-results hidden><div class="student-grade-lookup-card">
<img data-lookup-student-avatar><h3><a data-lookup-student-name data-entity-user href="#"></a></h3>
<a data-lookup-student-id href="#"></a><div><span data-lookup-student-program></span> · <span data-lookup-student-section></span> · <span data-lookup-student-department></span></div>
<span data-lookup-student-gpa></span><span data-lookup-student-progress></span></div>
<table><tbody data-lookup-student-grades-tbody></tbody></table></div></section>
<script>
window.fetch = async (input) => {
  const url = new URL(String(input), location.href);
  const mode = url.searchParams.get('mode');
  const id = Number(url.searchParams.get('student_id') || 42);
  const query = url.searchParams.get('q') || '';
  const payload = mode === 'student_search'
    ? (query === 'zz'
      ? {ok:true,scope:'assigned_classes',students:[],pagination:{total:0,page:1,pages:1}}
      : query === 'cy'
      ? {ok:true,scope:'assigned_classes',students:[
          {id:42,name:'Cyrine Dean Aguarte',school_id:'2302623',section_name:'BSIT 4E',program_code:'BSIT'},
          {id:43,name:'Cynthia Go',school_id:'STF-002',section_name:'BSIT 4D',program_code:'BSIT'}
        ],pagination:{total:2,page:1,pages:1}}
      : {ok:true,scope:'assigned_classes',students:[{id:42,name:'Asher John Catamin',school_id:'2302143',section_name:'BSIT 4D',program_code:'BSIT'}],pagination:{total:1,page:1,pages:1}})
    : mode === 'student_grades'
      ? {ok:true,student:{id,school_id:id === 43 ? 'STF-002' : '2302143',first_name:id === 43 ? 'Cynthia' : 'Asher John',last_name:id === 43 ? 'Go' : 'Catamin',role_name:'Student',section_id:17,section_name:'BSIT 4D',program_code:'BSIT',department_code:'CCS'},grades:[{class_offering_id:8,section_id:17,section_name:'BSIT 4D',course_code:'ITP217',course_title:'Capstone',teacher_names:'Maria Santos',units:3,prelim:90,midterm:91,final:92,overall:91,status:'finalized'}],gpa:91,graded_count:1,total_subjects:1}
      : {ok:true,subjects:[],pagination:{total:0,page:1,pages:1}};
  return new Response(JSON.stringify(payload), {status:200,headers:{'Content-Type':'application/json'}});
};
window.CampusGrading = {sync() {}};
</script>
<script src="https://cdn.jsdelivr.net/npm/simple-datatables@10" defer></script>
<script src="/assets/js/app.js" defer></script><script src="/assets/js/admin-control.js" defer></script>
</body></html>''', encoding='utf-8')
(root / 'recollection.html').write_text('''<!doctype html><html><head><meta charset="utf-8"></head><body>
<section id="recollections-directory"><table><tbody><tr><td><span class="badge">closed</span></td><td><button data-manage-recollection data-recollection-id="7">Edit</button></td></tr></tbody></table></section>
<dialog data-recollection-management data-endpoint="/admin/recollection.php"><div data-recollection-loading hidden></div><div data-recollection-error hidden></div>
<form data-recollection-manage-form hidden><input name="_token" value="token"><input name="recollection_id"><input name="title"><select name="type"><option>Recollection</option></select>
<select name="section_id"><option value="17">BSIT 4D</option></select><input name="venue"><input name="facilitator"><input name="attendance_required" type="checkbox">
<input name="date" type="date"><input name="start_time" type="time"><input name="end_time" type="time"><select name="other_role_ids[]" multiple></select>
<div data-selected-people><p data-selected-people-empty></p></div><span data-recollection-manage-feedback></span>
<div class="form-actions"><button type="button" data-dialog-close>Cancel</button><button type="submit">Save changes</button></div></form></dialog>
<script>
window.submittedAction = '';
window.fetch = async (input, options = {}) => {
  const url = new URL(String(input), location.href);
  if (url.pathname.endsWith('recollection.html')) return new Response('<section id="recollections-directory"><table><tbody><tr><td><span class="badge">cancelled</span></td><td></td></tr></tbody></table></section>', {headers:{'Content-Type':'text/html'}});
  if (options.method === 'POST') {
    window.submittedAction = options.body.get('action');
    return new Response(JSON.stringify({ok:true,message:'Activity cancelled.'}), {headers:{'Content-Type':'application/json'}});
  }
  return new Response(JSON.stringify({ok:true,recollection:{id:7,title:'BSIT 4D Recollection',type:'Recollection',section_id:17,venue:'Auditorium',facilitator:'',attendance_required:1,date:'2026-09-27',start_time:'09:00:00',end_time:'10:00:00',target_role_ids:[],target_users:[],status:'closed'}}), {headers:{'Content-Type':'application/json'}});
};
</script><script src="/assets/js/recollection-management.js" defer></script></body></html>''', encoding='utf-8')


class Quiet(SimpleHTTPRequestHandler):
    def log_message(self, *_):
        pass


server = ThreadingHTTPServer(('127.0.0.1', 0), lambda *args, **kwargs: Quiet(*args, directory=str(root), **kwargs))
threading.Thread(target=server.serve_forever, daemon=True).start()
try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', headless=True)
        page = browser.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.goto(f'http://127.0.0.1:{server.server_port}/', wait_until='networkidle')
        expect(page.locator('#sort-table .datatable-sorter .table-sort-icon')).to_have_count(2)
        page.locator('#sort-table th').first.locator('.datatable-sorter').click()
        expect(page.locator('#sort-table tbody tr').first).to_contain_text('Activity 2')
        page.locator('#sort-table th').first.locator('.datatable-sorter').click()
        expect(page.locator('#sort-table tbody tr').first).to_contain_text('Activity 10')
        expect(page.locator('#sort-table .datatable-sorter .table-sort-icon')).to_have_count(2)
        page.evaluate('''() => {
          window.simpleDatatables = undefined;
          const wrap = document.createElement('div');
          wrap.className = 'table-wrap';
          wrap.innerHTML = '<table id="fallback-table"><thead><tr><th>Room</th></tr></thead><tbody><tr><td>CSL 10</td></tr><tr><td>CSL 2</td></tr></tbody></table>';
          document.body.append(wrap);
          window.initializeDataTable(wrap.querySelector('table'));
        }''')
        expect(page.locator('#fallback-table .datatable-sorter .table-sort-icon')).to_have_count(1)
        page.locator('#fallback-table .datatable-sorter').click()
        expect(page.locator('#fallback-table tbody tr').first).to_contain_text('CSL 2')
        page.locator('#fallback-table .datatable-sorter').click()
        expect(page.locator('#fallback-table tbody tr').first).to_contain_text('CSL 10')
        page.locator('[data-admin-student-grade-lookup]').fill('as')
        expect(page.locator('[data-lookup-student-name]')).to_have_attribute('href', '/admin/?page=profile&id=42')
        expect(page.locator('[data-lookup-student-id]')).to_have_attribute('href', '/admin/?page=profile&id=42')
        expect(page.locator('[data-lookup-student-section] a')).to_have_attribute('href', '/admin/?page=academics&manage=section&entity_id=17')
        expect(page.locator('[data-lookup-student-grades-tbody] td').nth(1).locator('a')).to_have_attribute('href', '/admin/?page=academics&manage=section&entity_id=17')
        page.locator('[data-admin-student-grade-lookup]').fill('cy')
        expect(page.locator('.student-grade-search-option')).to_have_count(2)
        expect(page.locator('[data-admin-student-grade-summary]')).to_contain_text('2 matching students in your classes')
        page.locator('[data-admin-student-grade-lookup]').press('ArrowDown')
        expect(page.locator('.student-grade-search-option').first).to_be_focused()
        page.locator('.student-grade-search-option').nth(1).click()
        expect(page.locator('[data-lookup-student-name]')).to_have_attribute('href', '/admin/?page=profile&id=43')
        page.locator('[data-admin-student-grade-lookup]').fill('zz')
        expect(page.locator('.student-grade-search-empty')).to_contain_text('No active students in your assigned classes match “zz”')
        expect(page.locator('[data-admin-student-grade-results]')).to_be_hidden()
        assert not errors, errors

        page.goto(f'http://127.0.0.1:{server.server_port}/recollection.html', wait_until='networkidle')
        page.locator('[data-manage-recollection]').click()
        expect(page.locator('[data-recollection-manage-form]')).to_be_visible()
        expect(page.get_by_role('button', name='Cancel activity')).to_be_visible()
        expect(page.get_by_role('button', name='Save changes')).to_be_hidden()
        expect(page.get_by_role('button', name='Close')).to_be_visible()
        page.on('dialog', lambda dialog: dialog.accept())
        page.get_by_role('button', name='Cancel activity').click()
        expect(page.locator('#recollections-directory .badge')).to_have_text('cancelled')
        assert page.evaluate('window.submittedAction') == 'cancel_recollection'
        assert not errors, errors
        browser.close()
    print('Transcript links, sort controls, and recollection actions passed')
finally:
    server.shutdown()
    server.server_close()
    shutil.rmtree(root, ignore_errors=True)
