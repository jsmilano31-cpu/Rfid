"""Teacher actions are exercised only in the isolated integration database."""
from datetime import date,timedelta

def run(db,client,call,student,student_token):
    call(student,'teacher/portal.php',403)
    call(student,'teacher/grades.php',403,{'action':'save','class_offering_id':1,'student_id':1,'midterm':100,'final':100},student_token)
    with db.cursor() as c:
        for code in ['manage_attendance','view_attendance','manage_grades','view_grades','view_compliance','send_messages','manage_seat_plans','manage_teacher_time_off','manage_attendance_policies']:
            c.execute('INSERT INTO permissions (code,description) VALUES (%s,%s)',(code,code));permission_id=c.lastrowid
            c.execute('INSERT INTO role_permissions (role_id,permission_id) VALUES (2,%s)',(permission_id,))
        c.execute('UPDATE class_offerings SET status="active" WHERE id=1')
        c.execute('UPDATE teacher_time_off SET status="cancelled" WHERE teacher_id=3')
        c.execute('INSERT INTO attendance_settings (id,grace_period_minutes,absent_after_minutes,tardies_per_absence) VALUES (1,15,60,3)')
        c.execute("INSERT INTO rooms (id,room_code,room_name,building,floor) VALUES (1,'TEST','Test classroom','A','1')")
        c.execute('UPDATE class_offerings SET room_id=1 WHERE id=1')
    teacher=client();token=call(teacher,'auth/session.php',200)['data']['csrf_token']
    token=call(teacher,'auth/login.php',200,{'identifier':'TEACHER','password':'testing-password'},token)['data']['csrf_token']
    session=call(teacher,'auth/session.php',200)['data'];assert session['roles']==['Teacher']
    portal=call(teacher,'teacher/portal.php',200)['data'];assert [c['id'] for c in portal['courses']]==[1] and 'manage_grades' in portal['permissions']
    call(teacher,'student/portal.php',403)
    call(teacher,'teacher/roster.php?offering_id=1',200)
    call(teacher,'teacher/roster.php?offering_id=2',403)
    call(teacher,'teacher/attendance.php?offering_id=2',403)
    call(teacher,'teacher/attendance.php',419,{},None)
    monday=date.today()+timedelta(days=(7-date.today().weekday())%7)
    day=monday.isoformat()
    payload={'action':'manual','class_offering_id':1,'student_id':1,'attendance_date':day,'status':'Present','time_in':'08:00','reason':'Integration test correction'}
    call(teacher,'teacher/attendance.php',403,{**payload,'class_offering_id':2},token)
    call(teacher,'teacher/attendance.php',422,{**payload,'reason':''},token)
    call(teacher,'teacher/attendance.php',422,{**payload,'student_id':3},token)
    call(teacher,'teacher/attendance.php',200,payload,token)
    result=call(teacher,'teacher/attendance.php?offering_id=1&date='+day,200)['data'];assert result['summary']['Present']==1
    result=call(teacher,'teacher/attendance.php',200,{'action':'finalize','class_offering_id':1,'attendance_date':day},token)['data'];assert result['created']==1
    result=call(teacher,'teacher/attendance.php',200,{'action':'finalize','class_offering_id':1,'attendance_date':day},token)['data'];assert result['created']==0
    call(teacher,'teacher/attendance.php?mode=calendar&offering_id=1&month='+day[:7],200)
    call(teacher,'teacher/attendance.php?mode=policy&offering_id=1',200)
    call(teacher,'teacher/attendance.php',200,{'action':'policy','class_offering_id':1,'use_campus_default':False,'grace_period_minutes':10,'absent_after_minutes':45,'tardies_per_absence':4},token)
    grade={'action':'save','class_offering_id':1,'student_id':1,'midterm':'80.25','final':'90.75'}
    call(teacher,'teacher/grades.php',403,{**grade,'class_offering_id':2},token)
    call(teacher,'teacher/grades.php',422,{**grade,'final':'101'},token)
    call(teacher,'teacher/grades.php',200,grade,token)
    call(teacher,'teacher/grades.php',200,{**grade,'finalize':'1'},token)
    call(teacher,'teacher/grades.php',422,grade,token)
    roster=call(teacher,'teacher/grades.php?offering_id=1',200)['data'];assert not roster['can_reopen']
    with db.cursor() as c:c.execute('SELECT id FROM grades WHERE student_id=1');grade_id=c.fetchone()[0]
    call(teacher,'teacher/grades.php',422,{'action':'reopen','grade_id':grade_id},token)
    call(teacher,'teacher/compliance.php',200)
    timeoff={'action':'save','starts_on':day,'ends_on':day,'reason':'Test leave'}
    call(teacher,'teacher/time-off.php',422,timeoff,token)
    future=(monday+timedelta(days=7)).isoformat()
    leave=call(teacher,'teacher/time-off.php',200,{**timeoff,'starts_on':future,'ends_on':future},token)['data']['id']
    call(teacher,'teacher/attendance.php',422,{**payload,'attendance_date':future},token)
    call(teacher,'teacher/time-off.php',200,{'action':'cancel','time_off_id':leave},token)
    call(teacher,'teacher/time-off.php',422,{'action':'cancel','time_off_id':999999},token)
    call(teacher,'teacher/recipients.php',200)
    call(teacher,'teacher/messages.php',200,{'action':'send','subject':'Teacher test','body':'Isolated message','recipient_type':'user','target_id':1,'is_announcement':False},token)
    with db.cursor() as c:c.execute('SELECT is_announcement FROM messages WHERE subject="Teacher test"');assert c.fetchone()[0]==0
    call(teacher,'teacher/seats.php?mode=bootstrap',200)
    call(teacher,'teacher/seats.php?mode=room&room_id=99999',403)
    call(teacher,'teacher/seats.php',403,{'action':'save_room','room_id':99999},token)
    layout={'action':'save_room','room_id':1,'name':'Test grid','row_count':2,'column_count':2,'cells':[{'row':1,'column':1,'type':'student_seat','label':'A1'}]}
    plan=call(teacher,'teacher/seats.php',200,layout,token)['data']['plan']['id']
    call(teacher,'teacher/attendance.php',200,{'action':'assign','class_offering_id':1,'seat_plan_id':plan,'grid_row':1,'grid_column':1,'student_id':1},token)
    state=call(teacher,'teacher/attendance.php?mode=live&offering_id=1&date='+day,200)['data'];assert state['plan']['cells'][0]['student_id']==1
    call(teacher,'teacher/attendance.php',200,{'action':'unassign','class_offering_id':1,'seat_plan_id':plan,'grid_row':1,'grid_column':1},token)
    preset=call(teacher,'teacher/seats.php',200,{**layout,'action':'save_preset','name':'Test preset'},token)['data']['preset']['id']
    call(teacher,'teacher/seats.php',200,{**layout,'action':'update_preset','preset_id':preset,'name':'Updated preset'},token)
    call(teacher,'teacher/seats.php',200,{'action':'delete_preset','preset_id':preset},token)
    with db.cursor() as c:c.execute('DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE role_id=2 AND p.code="manage_grades"')
    call(teacher,'teacher/grades.php?offering_id=1',403)
    call(teacher,'teacher/grades.php',403,grade,token)
