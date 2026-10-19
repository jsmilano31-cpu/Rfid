from pathlib import Path
import base64
import sys, subprocess, shutil, time, json, urllib.request, urllib.error, http.cookiejar, uuid
ROOT=Path(__file__).resolve().parents[3]
sys.path.insert(0,str(ROOT/'.codex-tmp/audit-deps'))
import pymysql
WEB=ROOT/'rfid-attendance-web'
database_name='mobile_api_test_'+uuid.uuid4().hex
TEST=ROOT/'.codex-tmp'/database_name
TEST.mkdir(exist_ok=True)
for name in ['src','config','mobile-api']:
    shutil.copytree(WEB/name,TEST/name,dirs_exist_ok=True)
(TEST/'.env').write_text(f'DB_HOST=127.0.0.1\nDB_PORT=3307\nDB_DATABASE={database_name}\nDB_USERNAME=root\nDB_PASSWORD=\nAPP_ENV=testing\nAPP_DEBUG=false\nAPP_URL=http://127.0.0.1:8097/\nAPP_TIMEZONE=Asia/Manila\n')
db=pymysql.connect(host='127.0.0.1',port=3307,user='root',autocommit=True)
with db.cursor() as c:
    c.execute(f'CREATE DATABASE `{database_name}` CHARACTER SET utf8mb4')
    c.execute(f'USE `{database_name}`')
    c.execute('SET FOREIGN_KEY_CHECKS=0')
    for statement in (Path(__file__).parent/'schema.sql').read_text().split(';'):
        if statement.strip(): c.execute(statement)
    c.execute('SET FOREIGN_KEY_CHECKS=1')
    c.execute('INSERT INTO roles (id,name) VALUES (3,"Student"),(2,"Teacher")')
log=(TEST/'server.log').open('w')
server=subprocess.Popen(['php','-S','127.0.0.1:8097','-t',str(TEST/'mobile-api/public')],stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW)
time.sleep(1)
count=0
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def call(opener,path,expected,payload=None,token=None):
    global count
    headers={'Content-Type':'application/json'}
    if token: headers['X-CSRF-Token']=token
    req=urllib.request.Request('http://127.0.0.1:8097/'+path,data=None if payload is None else json.dumps(payload).encode(),headers=headers)
    try:r=opener.open(req)
    except urllib.error.HTTPError as e:r=e
    raw=r.read().decode()
    assert r.status==expected,(path,r.status,expected,raw)
    count+=1
    return json.loads(raw)
try:
    a=client(); b=client()
    call(a,'health.php',200)
    call(a,'student/portal.php',401)
    call(a,'auth/register.php',419,{})
    token=call(a,'auth/session.php',200)['data']['csrf_token']
    call(a,'auth/register.php',422,{},token)
    fields=dict(school_id='TEST-STUDENT',first_name='Test',last_name='Student',email='student@example.invalid',password='testing-password',password_confirmation='testing-password',role_id=2,status='active')
    call(a,'auth/register.php',201,fields,token)
    with db.cursor() as c:
        c.execute('SELECT status,role_id,program_id FROM users WHERE school_id="TEST-STUDENT"'); assert c.fetchone()==('inactive',3,None)
    call(a,'auth/register.php',409,fields,token)
    login=dict(identifier='TEST-STUDENT',password='testing-password')
    call(a,'auth/login.php',403,login,token)
    with db.cursor() as c:c.execute('UPDATE users SET status="active" WHERE school_id="TEST-STUDENT"')
    token=call(a,'auth/login.php',200,login,token)['data']['csrf_token']
    portal=call(a,'student/portal.php',200)['data']
    assert portal['student']['school_id']=='TEST-STUDENT' and 'password_hash' not in portal['student']
    (ROOT/'.codex-tmp/api-test-portal.json').write_text(json.dumps(portal,indent=2))
    with db.cursor() as c:
        c.execute("INSERT INTO users (id,school_id,first_name,last_name,email,password_hash,role_id,status) SELECT 2,'OTHER','Other','Student','other@example.invalid',password_hash,3,'active' FROM users WHERE id=1")
        c.execute('INSERT INTO user_roles (user_id,role_id) VALUES (2,3)')
        c.execute("INSERT INTO departments (id,code,name) VALUES (1,'TEST','Test department')")
        c.execute("INSERT INTO programs (id,department_id,code,name) VALUES (1,1,'TEST','Test program')")
        c.execute("INSERT INTO sections (id,program_id,name,year_level,section_code) VALUES (1,1,'Test A',1,'A')")
        c.execute("INSERT INTO school_years (id,name,starts_on,ends_on) VALUES (1,'Test','2020-01-01','2030-12-31')")
        c.execute("INSERT INTO semesters (id,school_year_id,name,starts_on,ends_on) VALUES (1,1,'Term','2020-01-01','2030-12-31')")
        c.execute("INSERT INTO courses (id,department_id,course_code,title) VALUES (1,1,'TEST101','Test course'),(2,1,'OTHER101','Other course')")
        c.execute("INSERT INTO class_offerings (id,course_id,section_id,semester_id,status) VALUES (1,1,1,1,'completed'),(2,2,1,1,'active')")
        c.execute('INSERT INTO class_enrollments (class_offering_id,student_id) VALUES (1,1),(1,2)')
        c.execute("INSERT INTO attendance_records (class_offering_id,student_id,attendance_date,status,source) VALUES (1,1,'2020-01-01','Present','manual')")
        c.execute("INSERT INTO users (id,school_id,first_name,last_name,email,password_hash,role_id,status) SELECT 3,'TEACHER','Test','Teacher','teacher@example.invalid',password_hash,2,'active' FROM users WHERE id=1")
        c.execute('INSERT INTO user_roles (user_id,role_id) VALUES (3,2)')
        c.execute('INSERT INTO teacher_assignments (class_offering_id,teacher_id) VALUES (1,3)')
        c.execute("INSERT INTO class_schedules (class_offering_id,day_of_week,start_time,end_time) VALUES (1,'Monday','08:00:00','09:00:00')")
        c.execute("INSERT INTO teacher_time_off (teacher_id,starts_on,ends_on,reason,created_by) VALUES (3,'2026-01-01','2027-12-31','Test leave',3)")
        c.execute("INSERT INTO events (title,event_date,start_time,end_time,venue,status,created_by) VALUES ('Cancelled test','2026-09-05','08:00:00','09:00:00','Hall','cancelled',1)")
        c.execute('INSERT INTO event_participants (event_id,user_id,is_required) VALUES (1,1,1)')
        c.execute("INSERT INTO messages (sender_id,subject,body) VALUES (1,'Sent test','Body')")
        c.execute('INSERT INTO message_recipients (message_id,user_id) VALUES (1,2)')
    people=call(a,'student/people.php?q=Ot',200)['data'];assert people['total']==1 and 'email' not in people['items'][0]
    call(a,'student/people.php?q=x',200)
    other=call(a,'student/directory-profile.php?id=2',200)['data']
    assert not other['can_view_contact'] and 'email' not in other['profile'] and not other['can_view_grades'] and other['grades']==[] and other['attendance']==[]
    call(a,'student/directory-profile.php?id=99999',404)
    roster=call(a,'student/roster.php?offering_id=1',200)['data'];assert len(roster['students'])==2 and all('email' not in row for row in roster['students'])
    call(a,'student/roster.php?offering_id=2',403)
    calendar=call(a,'student/calendar.php',200)['data'];assert any(e['kind']=='teacher_time_off' for e in calendar['entries'])
    call(a,'student/feed.php',200)
    sent=call(a,'student/sent.php',200)['data'];assert sent['total']==1 and sent['items'][0]['subject']=='Sent test'
    history=call(a,'student/portal.php',200)['data'];assert history['attendance'][0]['attendance_date']=='2020-01-01' and history['courses'][0]['status']=='completed' and history['attendance_window_days']==0 and len(history['courses'][0]['time_off'])==1 and history['activities'][0]['status']=='cancelled'
    call(a,'student/photo.php',419,{})
    call(a,'student/photo.php',422,{},token)
    boundary='CampusTestBoundary'
    png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR1sAAAAASUVORK5CYII=')
    body=(f'--{boundary}\r\nContent-Disposition: form-data; name="profile_image"; filename="test.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+png+f'\r\n--{boundary}--\r\n'.encode())
    req=urllib.request.Request('http://127.0.0.1:8097/student/photo.php',data=body,headers={'Content-Type':f'multipart/form-data; boundary={boundary}','X-CSRF-Token':token})
    with a.open(req) as response: assert response.status==200 and '/uploads/profile-images/' in json.load(response)['data']['profile_image_url']
    count+=1
    from teacher_checks import run as teacher_checks
    teacher_checks(db,client,call,a,token)
    call(a,'student/message-read.php',404,{'message_id':99999},token)
    call(a,'student/profile-update.php',422,{'email':'invalid'},token)
    call(a,'student/profile-update.php',200,{'email':'changed@example.invalid'},token)
    token_b=call(b,'auth/session.php',200)['data']['csrf_token']
    call(b,'auth/login.php',200,login,token_b)
    call(a,'auth/password.php',422,dict(current_password='wrong',password='new-password',password_confirmation='new-password'),token)
    token=call(a,'auth/password.php',200,dict(current_password='testing-password',password='new-password',password_confirmation='new-password'),token)['data']['csrf_token']
    call(b,'student/portal.php',401)
    call(a,'auth/logout.php',200,{},token)
    call(a,'student/portal.php',401)
    print(f'PASS: {count} HTTP checks, registration, role boundaries, historical records, roster authorization, directory privacy, calendar/time off, cancelled events, sent messages, multipart photo upload, password session revocation and logout.')
finally:
    server.terminate();server.wait();log.close()
    with db.cursor() as c: c.execute(f'DROP DATABASE `{database_name}`')
    db.close()
