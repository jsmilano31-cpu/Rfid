"""Audit real teacher/student portal surfaces and interactive states in isolated fixtures."""
from pathlib import Path
from datetime import date, timedelta
import json, os, re, subprocess, sys, time, uuid
WEB=Path(__file__).resolve().parent.parent
sys.path.insert(0,str(WEB.parent/'.codex-tmp/audit-deps'))
import pymysql
from playwright.sync_api import expect, sync_playwright
name='portal_theme_'+uuid.uuid4().hex; user='theme_'+uuid.uuid4().hex[:12]; password=uuid.uuid4().hex
db=pymysql.connect(host='127.0.0.1',port=3340,user='root',autocommit=True);server=None
SCAN=r'''() => {
 const rgba=v=>{let c=(v.match(/[\d.]+/g)||[]).map(Number);if(c.length<3)return [0,0,0,0];if(v.startsWith('color(srgb'))c=c.map((x,i)=>i<3?x*255:x);return [...c.slice(0,3),c[3]??1]};
 const background=el=>{const chain=[];while(el){chain.unshift(el);el=el.parentElement}let c=[255,255,255];for(const n of chain){const b=rgba(getComputedStyle(n).backgroundColor);c=c.map((x,i)=>x*(1-b[3])+b[i]*b[3])}return c};
 const lum=c=>c.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4}).reduce((v,x,i)=>v+x*[.2126,.7152,.0722][i],0);
 const seen=new Set(), bright=[], contrast=[];
 for(const el of document.querySelectorAll('.page-content *, dialog[open] *, .flatpickr-calendar.open *, .choices__list--dropdown.is-active *')) {
  const rect=el.getBoundingClientRect(), s=getComputedStyle(el);
  if(!rect.width||!rect.height||s.visibility==='hidden'||el.closest('svg, [hidden]')||['IMG','CANVAS','VIDEO'].includes(el.tagName))continue;
  const bg=rgba(s.backgroundColor), key=el.tagName+'.'+el.className;
  if(bg[3]>.8 && lum(bg.slice(0,3))>.7 && rect.width>50 && rect.height>20 && !seen.has(key)) {seen.add(key);bright.push({key,text:el.textContent.trim().slice(0,65),bg:s.backgroundColor,color:s.color});}
  const text=[...el.childNodes].some(n=>n.nodeType===3&&n.textContent.trim());if(!text)continue;
  const b=background(el), fg=rgba(s.color).slice(0,3);
  const ratio=(Math.max(lum(b),lum(fg))+.05)/(Math.min(lum(b),lum(fg))+.05);
  if(ratio<3 && !seen.has('c'+key)) {seen.add('c'+key);contrast.push({key,text:el.textContent.trim().slice(0,65),bg:b,color:s.color,ratio});}
 }
 return {bright,contrast};
}'''
try:
 with db.cursor() as c:
  c.execute(f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4');c.execute(f'USE `{name}`')
  for source in ['schema.sql','seed.sql']:
   sql=re.sub(r'^\s*--.*$','',(WEB/'database'/source).read_text(encoding='utf-8-sig'),flags=re.M)
   for statement in sql.split(';'):
    if statement.strip():c.execute(statement)
  c.execute('ALTER TABLE events ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out_required')
  c.execute("INSERT INTO user_preferences(user_id,theme_mode) VALUES(2,'dark'),(5,'dark')")
  # More than one page of people and enrolled classmates, with no production mutations.
  for i in range(100,133):
   c.execute("INSERT INTO users(id,school_id,first_name,last_name,email,password_hash,role_id,department_id,program_id,section_id,status) SELECT %s,%s,'Directory',%s,%s,password_hash,3,1,1,1,'active' FROM users WHERE id=5",(i,f'THEME-{i}',f'Person {i}',f'theme{i}@example.test'))
   c.execute('INSERT INTO class_enrollments(class_offering_id,student_id) VALUES(1,%s)',(i,))
  c.execute("INSERT INTO room_seat_plans(id,room_id,name,row_count,column_count,updated_by) VALUES(1,1,'Theme fixture layout',2,3,2)")
  for column,kind,label in [(1,'student_seat','Student Seat'),(2,'teacher_table','Teacher Table'),(3,'label','Board')]:
   c.execute('INSERT INTO room_seat_plan_cells(seat_plan_id,grid_row,grid_column,cell_type,label) VALUES(1,1,%s,%s,%s)',(column,kind,label))
  c.execute("INSERT INTO teacher_time_off(teacher_id,starts_on,ends_on,reason,created_by) VALUES(2,CURRENT_DATE-INTERVAL 7 DAY,CURRENT_DATE+INTERVAL 7 DAY,'Theme fixture time off',1)")
  c.execute(f"CREATE USER '{user}'@'127.0.0.1' IDENTIFIED BY '{password}'");c.execute(f"GRANT ALL ON `{name}`.* TO '{user}'@'127.0.0.1'")
 env=os.environ.copy();env.update(DB_HOST='127.0.0.1',DB_PORT='3340',DB_DATABASE=name,DB_USERNAME=user,DB_PASSWORD=password,APP_ENV='testing',APP_DEBUG='true',APP_URL='http://127.0.0.1:8132')
 server=subprocess.Popen(['php','-S','127.0.0.1:8132','-t','public'],cwd=WEB,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW);time.sleep(.8)
 report={};errors=[]
 with sync_playwright() as p:
  browser=p.chromium.launch(executable_path=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',headless=True)
  def audit(page,key,theme):
   info=page.evaluate(SCAN);report[key]=info
   assert not info['contrast'],key+': unreadable text '+json.dumps(info['contrast'])
   if theme=='dark':assert not info['bright'],key+': light surfaces '+json.dumps(info['bright'])
  for role,identifier in [('student','2026-0001'),('teacher','TCH-001')]:
   context=browser.new_context(viewport={'width':1440,'height':1000},color_scheme='dark');page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
   base='http://127.0.0.1:8132';page.goto(base+'/auth/login.php');page.locator('[name=identifier]').fill(identifier);page.locator('[name=password]').fill('Password123!');page.locator('button[type=submit]').click();page.wait_for_url(re.compile(r'/index/$'))
   routes=['dashboard','people','schedule','attendance','grades','class-roster&offering_id=1','events','recollections','compliance','messages','profile','profile&user_id=5','settings']
   if role=='teacher':routes+=['seat-plans','seat-plans&offering_id=1&room_id=1']
   for route in routes:
    response=page.goto(base+'/index/?page='+route);assert response.status==200,(role,route,response.status);page.wait_for_timeout(450)
    if route=='people':
     page.locator('[data-people-search]').fill('Directory');expect(page.locator('.people-directory-card')).to_have_count(20)
     page.get_by_role('button',name='People page 2',exact=True).click();expect(page.locator('.people-directory-card')).to_have_count(13)
     card=page.locator('.people-directory-card').first;card.hover();card.focus()
    if route.startswith('class-roster'):
     expect(page.locator('.class-roster-person').first).to_be_visible();page.locator('.class-roster-person').first.hover()
    if route.startswith('seat-plans&'):expect(page.locator('.seat-plan-cell.is-label')).to_be_visible()
    for theme in ['dark','light']:
     page.evaluate('(theme)=>document.documentElement.dataset.theme=theme',theme);page.wait_for_timeout(400)
     audit(page,role+'/'+route+'/'+theme,theme)
    page.evaluate("document.documentElement.dataset.theme='dark'");page.wait_for_timeout(400)
    if route in ['people','schedule','class-roster&offering_id=1','seat-plans&offering_id=1&room_id=1']:page.screenshot(path=str(WEB.parent/'.codex-tmp'/f'{role}-theme-{route.split("&")[0]}.png'),full_page=True)
    if route=='people':
     # Follow an actual result; ensure the profile is reachable and remains themed.
     page.locator('.people-directory-card').first.click();expect(page.locator('.directory-profile-page')).to_be_visible();audit(page,role+'/directory-link','dark')
    if route=='schedule':
     room=page.locator('[data-timetable-inline-entity="room"]').first;room.focus();room.press('Enter');expect(page.locator('[data-schedule-entity-dialog]')).to_be_visible();audit(page,role+'/room-dialog','dark');page.keyboard.press('Escape')
     page.locator('[data-schedule-view="list"]').click()
     page.locator('.table-entity-link').first.hover();audit(page,role+'/schedule-list-hover','dark')
    if role=='teacher' and route in ['attendance','grades']:
     prefix='attendance' if route=='attendance' else 'grade'
     page.locator(f'[data-{prefix}-subject-id="1"]').click()
     if prefix=='attendance':
      expect(page.locator('[data-attendance-edit]').first).to_be_visible();audit(page,role+'/attendance/time-off-note','dark')
      previous_monday=date.today()-timedelta(days=date.today().weekday()+14)
      page.locator('[data-attendance-date]').evaluate('(el,value)=>el._flatpickr.setDate(value,true)',previous_monday.isoformat())
     edit=page.locator('[data-attendance-edit]' if prefix=='attendance' else '[data-grade-edit-student]').first
     expect(edit).to_be_visible();expect(edit).to_be_enabled();edit.click();expect(page.locator(f'[data-{prefix}-edit-dialog]')).to_be_visible();audit(page,role+'/'+route+'/edit-dialog','dark');page.keyboard.press('Escape')
     if prefix=='attendance':
      page.locator('.attendance-date-field input.date-input').click();expect(page.locator('.flatpickr-calendar.open')).to_be_visible();audit(page,role+'/date-picker','dark');page.keyboard.press('Escape')
    print('Checked '+role+'/'+route+' in dark and light themes.',flush=True)
   # System preference must update existing cards without a reload.
   page.goto(base+'/index/?page=class-roster&offering_id=1');expect(page.locator('.class-roster-person').first).to_be_visible();page.evaluate("document.documentElement.dataset.theme='system'")
   audit(page,role+'/system-dark','dark');dark=page.locator('.class-roster-person').first.evaluate('el=>getComputedStyle(el).backgroundColor')
   page.emulate_media(color_scheme='light');page.wait_for_timeout(400);light=page.locator('.class-roster-person').first.evaluate('el=>getComputedStyle(el).backgroundColor');assert dark!=light
   audit(page,role+'/system-light','light')
   # Fresh mobile navigation avoids treating a mid-transition desktop drawer as overflow.
   page.set_viewport_size({'width':390,'height':844});page.emulate_media(color_scheme='dark');page.goto(base+'/index/?page=class-roster&offering_id=1');expect(page.locator('.class-roster-person').first).to_be_visible();page.wait_for_timeout(450)
   audit(page,role+'/mobile-roster','dark');assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),'Mobile page overflow'
   page.screenshot(path=str(WEB.parent/'.codex-tmp'/f'{role}-theme-mobile.png'),full_page=True)
   page.locator('[data-roster-view="list"]').click();audit(page,role+'/mobile-roster-list','dark')
   context.close()
  browser.close()
 (WEB.parent/'.codex-tmp/portal-theme-report.json').write_text(json.dumps({'pages':report,'errors':errors},indent=2),encoding='utf-8')
 assert not errors,errors
 print('Portal surfaces, populated directories/rosters, pagination, editors, system theme, and mobile checks passed.',flush=True)
finally:
 if server:server.terminate();server.wait(timeout=5)
 with db.cursor() as c:
  c.execute(f'DROP DATABASE IF EXISTS `{name}`');c.execute(f"DROP USER IF EXISTS '{user}'@'127.0.0.1'")
 db.close()
