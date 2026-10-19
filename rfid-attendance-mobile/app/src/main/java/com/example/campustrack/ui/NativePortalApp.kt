package com.example.campustrack.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject

/** One capability-driven shell; personal, assigned-class and management contexts stay explicit. */
@Composable
fun NativePortalApp(api: ApiClient, onSessionEnded: () -> Unit, onSignedOut: () -> Unit) {
    ThemeSystemBars()
    var workspace by rememberSaveable { mutableStateOf(api.capabilities.optString("default_workspace", "student")) }
    val preferences=LocalAppPreferences.current
    var route by rememberSaveable { mutableStateOf(preferences.value.startPage.takeIf{it in listOf("home","schedule","attendance","messages","more")&&nativeRouteAllowed(it,api.permissions,api.roles,api.capabilities.optBoolean("can_manage_sms"))} ?: "more") }
    var history by rememberSaveable { mutableStateOf(listOf<String>()) }
    var revision by remember { mutableIntStateOf(0) }
    var data by remember { mutableStateOf<StudentData?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    var assistant by remember{mutableStateOf(false)}
    if(assistant && workspace!="management")AssistantScreen(loadRecords={parsePortal(api.get(if(workspace=="teacher")"teacher/portal.php"else"student/portal.php"))},teacher=workspace=="teacher",onSessionEnded=onSessionEnded,onClose={assistant=false})
    fun open(path: String) { if (path != route) { history = history + route; route = path } }
    fun back() { route = history.lastOrNull() ?: "home"; history = history.dropLast(1) }
    BackHandler(history.isNotEmpty()) { back() }
    val currentPermissions=api.permissions
    val notificationRefresh by CampusPush.refresh.collectAsState()
    LaunchedEffect(workspace, revision,notificationRefresh,currentPermissions) {
        loading = true; error = null
        try {
            api.session()
            val choices = api.capabilities.optJSONArray("workspaces") ?: JSONArray()
            if ((0 until choices.length()).none { choices.getString(it) == workspace }) { workspace = api.capabilities.optString("default_workspace"); route="home"; history=emptyList(); data=null }
            data = parsePortal(api.get(when(workspace){"management"->"workspace/portal.php";"teacher"->"teacher/portal.php";else->"student/portal.php"}))
        } catch (cancelled: CancellationException) { throw cancelled }
        catch (failure: Exception) { if(failure is ApiException && failure.status == 401) onSessionEnded() else error = failure.message }
        finally { loading=false }
    }
    LaunchedEffect(api.roles,api.capabilities.optString("workspaces")) {val choices=api.capabilities.optJSONArray("workspaces")?:JSONArray();if((0 until choices.length()).none{choices.getString(it)==workspace}){workspace=api.capabilities.optString("default_workspace");route="home";history=emptyList();data=null;revision++}}
    val tabs = buildList {
        if(api.can("view_dashboard")) add(PortalDestination("home", "Home", Icons.Outlined.SpaceDashboard))
        if(api.can("view_schedule")) add(PortalDestination("schedule", "Schedule", Icons.Outlined.CalendarMonth))
        if(api.can("view_attendance","correct_class_attendance","manage_attendance") ) add(PortalDestination("attendance", "Attendance", Icons.Outlined.FactCheck))
        add(PortalDestination("more", "More", Icons.Outlined.GridView))
    }
    PortalScaffold(topBar = { PortalTopBar(data?.student?.initials.orEmpty(), history.isNotEmpty(), ::back, { open("settings") }) {
        if(api.can("use_search")) IconButton(onClick={open("search")}){Icon(Icons.Outlined.Search,"Search campus")}
        if(api.can("manage_own_notifications")) NotificationBell(api,onSessionEnded)
        if(api.can("view_messages")) IconButton(onClick={open("messages")}){Icon(Icons.Outlined.MailOutline,"Mailbox")}
    } }, bottomBar = { PortalBottomBar(tabs, route.takeIf { path -> tabs.any { it.route==path } } ?: "more", true, { route=it;history=emptyList() }, {if(workspace!="management")assistant=true else open("search")},assistantLabel=if(workspace=="management")"Search campus"else"Open Campus Assistant",assistantIsSearch=workspace=="management",assistantEnabled=workspace!="management"||api.can("use_search")) }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            PortalStatusRow(if(workspace=="teacher") "My classes" else if(workspace=="student") "My progress" else "Campus workspace",loading){revision++}
            if(api.capabilities.optJSONObject("preview")!=null) Surface(color=MaterialTheme.colorScheme.tertiaryContainer) { Row(Modifier.fillMaxWidth().padding(horizontal=16.dp), horizontalArrangement=Arrangement.SpaceBetween) {
                Text("Preview: ${api.capabilities.optJSONObject("preview")?.text("name")}",Modifier.padding(vertical=12.dp))
                TextButton(onClick={scope.launch {try{api.post("workspace/preview.php",JSONObject().put("role_id",0));api.session();workspace=api.capabilities.optString("default_workspace");revision++}catch(c:CancellationException){throw c}catch(f:Exception){if(f is ApiException&&f.status==401)onSessionEnded()else error=f.message}}}){Text("Exit preview")}
            } }
            if(loading && data==null && workspace!="management") LoadingSkeleton()
            else if(error!=null) Column(Modifier.padding(20.dp)){EmptyState("Couldn't load this workspace",error!!);Button(onClick={revision++}){Text("Try again")}}
            else {
                val current=data
                val id=route.substringAfter('/',"")
                when {
                    !nativeRouteAllowed(route,currentPermissions,api.roles,api.capabilities.optBoolean("can_manage_sms"))->Column(Modifier.padding(20.dp)){EmptyState("This tool is unavailable","Your current roles and permissions no longer allow this workflow.");Button(onClick={route="more";history=emptyList()}){Text("Open permitted tools")}}
                    route.startsWith("dependencies/")->DeletionDependencies(api,id.substringBefore('/'),id.substringAfter('/'),onSessionEnded,::open)
                    route.startsWith("linked/" )->LinkedHistoryRecord(api,id.substringBefore('/'),id.substringAfter('/'),onSessionEnded)
                    route.startsWith("record/")->ManagementRecord(api,id.substringBefore('/'),id.substringAfter('/'),onSessionEnded,::open)
                    route=="more" -> LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(12.dp)) {
                        item{PageTitle("Your workspace","Choose a tool. Access follows your current roles and permissions.")}
                        item { val choices=api.capabilities.optJSONArray("workspaces")?:JSONArray(); if(choices.length()>1) SearchSelect("Workspace",workspace,(0 until choices.length()).map{choices.getString(it) to when(choices.getString(it)){"teacher"->"My classes";"student"->"My progress";else->"Management"}}){workspace=it;data=null;route="home";history=emptyList()} }
                        if(api.can("view_grades","manage_grades")) item{TeacherMenu("Grades","Subject results and evaluations"){open("grades")}}
                        if(api.can("view_analytics")) item{TeacherMenu("Analytics","Attendance, outcomes and progress"){open("analytics")}}
                        if(workspace=="student"&&api.can("manage_own_learning_goals")&&api.can("view_analytics")) item{TeacherMenu("My goals","Private targets and measured progress"){open("goals")}}
                        if(workspace!="student"&&api.can("send_attendance_sms","correct_class_attendance","manage_attendance","view_class_rosters")) item{TeacherMenu("Attendance alerts","Subject totals and reviewed SMS notices"){open("alerts")}}
                        if(api.can("assign_class_seats","manage_seat_plans")) item{TeacherMenu("Seat plans","Assign enrolled students to prepared layouts"){open("seat-subjects")}}
                        if(api.can("view_events","manage_events")) item{TeacherMenu("Events","Details, activity history and tracking"){open("events")}}
                        if(api.can("view_recollections","manage_recollections")) item{TeacherMenu("Recollections","Formation activities and retreats"){open("recollections")}}
                        if(api.can("view_schedule","view_events","manage_events","view_recollections","manage_recollections","manage_academic_calendar")) item{TeacherMenu("Campus calendar","Schedules, activities and holiday policy"){open("calendar")}}
                        if(api.can("view_messages","send_messages")) item{TeacherMenu("Messages","Mailbox and campus communication"){open("messages")}}
                        if(api.can("view_people","manage_users")) item{TeacherMenu("People","Directory and permitted student profiles"){open("people")}}
                        if(api.can("view_compliance","manage_compliance","manage_reports")) item{TeacherMenu("Compliance","Attendance deficiencies and violations"){open("compliance")}}
                        if(workspace=="teacher"&&api.can("manage_teacher_time_off")) item{TeacherMenu("Teaching time off","Manage your class suspension dates"){open("time-off")}}
                        if(workspace=="management") item{ManagementDirectory(api,::open)}
                        if(api.can("manage_own_profile") && "Administrator" !in api.roles) item{TeacherMenu("Profile","Photo, email, privacy and password"){open("profile")}}
                        if(api.can("manage_own_settings")) item{TeacherMenu("Settings","Appearance, notifications and accessibility"){open("settings")}}
                        if(workspace=="management" && api.capabilities.optBoolean("can_manage_sms")) item{TeacherMenu("SMS settings","Administrator-managed delivery configuration"){open("sms-settings")}}
                        if((api.capabilities.optJSONArray("preview_roles")?.length()?:0)>0) item{PreviewSelector(api){revision++;workspace=api.capabilities.optString("default_workspace");route="home"}}
                        item{OutlinedButton(onClick={scope.launch{try{api.post("auth/logout.php")}catch(c:CancellationException){throw c}catch(_:Exception){/* Local logout remains available offline. */}finally{api.clearSession();onSignedOut()}}},modifier=Modifier.fillMaxWidth()){Text("Sign out")}}
                    }
                    route=="analytics" -> NativeAnalyticsScreen(api,if(workspace=="management"){val choices=api.capabilities.optJSONObject("analytics_workspaces");if(choices?.has("campus")==true)"management"else if(choices?.has("teacher")==true)"teacher"else"student"}else workspace,onSessionEnded,::open)
                    route=="goals" -> NativeGoalsScreen(api,onSessionEnded)
                    route=="alerts" -> AttendanceAlertsScreen(api,workspace,onSessionEnded)
                    route=="messages" -> NativeMailboxScreen(api,onSessionEnded)
                    route=="events" -> NativeEventsScreen(api,onSessionEnded,::open)
                    route.startsWith("event/") -> NativeEventDetail(api,id,onSessionEnded){back()}
                    route=="grades" -> NativeGradesScreen(api,workspace,onSessionEnded){open("grades/$it")}
                    route=="search" -> NativeSearchScreen(api,onSessionEnded,::open)
                    route=="sms-settings" -> SmsSettingsScreen(api,onSessionEnded)
                    route=="recollections" -> RecollectionsWorkspace(api,current,onSessionEnded,::open)
                    route=="compliance" && workspace!="student" -> ComplianceManagement(api,onSessionEnded,workspace)
                    route=="seat-subjects" -> PreparedSeatSubjects(api,onSessionEnded,workspace){open("live/$it")}
                    route=="calendar" -> if(current!=null) CalendarWorkspace(api,current,onSessionEnded){open(if(it.startsWith("event-"))"event/${it.removePrefix("event-")}" else "activity/$it")}
                    route=="people" -> PeopleScreen(api,null,onSessionEnded){open("person/$it")}
                    route.startsWith("person/") -> DirectoryProfileScreen(api,id,onSessionEnded){open("roster/$it")}
                    route.startsWith("roster/") -> PeopleScreen(api,id,onSessionEnded,workspace!="student"){open("person/$it")}
                    route=="settings" -> SettingsScreen(api,onSessionEnded,onSms=if(workspace=="management")({open("sms-settings")})else null){open("profile")}
                    route.startsWith("manage/") -> ManagementScreen(api,id,onSessionEnded,::open)
                    route=="home" -> if(workspace=="management") ManagementHome(api,onSessionEnded,::open) else if(current!=null) PersonalHome(api,current,workspace,onSessionEnded,::open)
                    current!=null -> when {
                        route=="schedule" -> ScheduleWorkspace(api,current,workspace,onSessionEnded,::open)
                        route.startsWith("scheduled/") -> ScheduledClass(api,current,workspace,id,onSessionEnded,::open)
                        route=="attendance" && workspace=="student" -> PersonalAttendance(api,onSessionEnded)
                        route=="attendance" -> AttendanceClasses(api,workspace,onSessionEnded){open("attendance/$it")}
                        route=="seat-subjects" -> AssignedClassList(current,"Seat plans"){open("live/$it")}
                        route.startsWith("class/") -> ClassTools(api,current.course(id),::open)
                        route.startsWith("course/") -> CourseScreen(current.course(id),current,::open,api.permissions)
                        route.startsWith("attendance-calendar/") -> if(workspace=="student")PersonalAttendanceCalendar(api,id,current,onSessionEnded)else TeacherAttendanceCalendar(api,id,current,onSessionEnded){open("attendance-date/$id/$it")}
                        route.startsWith("attendance-date/") -> TeacherRosterScreen(api,id.substringBefore('/'),id.substringAfter('/'),false,onSessionEnded,workspace){open("person/$it")}
                        route.startsWith("attendance/") -> TeacherRosterScreen(api,id,current.today().toString(),false,onSessionEnded,workspace){open("person/$it")}
                        route.startsWith("grades/") -> TeacherRosterScreen(api,id,current.today().toString(),true,onSessionEnded,workspace){open("person/$it")}
                        route.startsWith("live/") -> TeacherLiveSeats(api,id,current.today().toString(),onSessionEnded)
                        route.startsWith("policy/") -> TeacherPolicyScreen(api,id,onSessionEnded)
                        route=="time-off" -> TeacherTimeOffScreen(api,current.today().toString(),onSessionEnded){revision++}
                        route=="compliance" -> if(workspace=="teacher") TeacherComplianceScreen(api,onSessionEnded){open("person/$it")} else ComplianceScreen(current)
                        route=="profile" -> NativeOwnerProfile(api,onSessionEnded){revision++}
                        route.startsWith("activity/") -> current.activities.find{it.id==id}?.let{ActivityScreen(it,current.today())}
                        else -> EmptyState("Choose a tool","Open More to find your permitted tools.")
                    }
                    else -> EmptyState("Choose a tool","Open More to find your permitted tools.")
                }
            }
        }
    }
}

@Composable internal fun AssignedClassList(data:StudentData,title:String,open:(String)->Unit) { LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(12.dp)){item{PageTitle(title,"Select an assigned class.")};if(data.courses.isEmpty())item{EmptyState("No assigned classes","Classes appear after the school assigns them.")};items(data.courses,key={it.id}){CourseCard(it){open(it.id)}}} }
@Composable internal fun ClassTools(api:ApiClient,course:Course,open:(String)->Unit) { LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(12.dp)){item{PageTitle(course.title,"${course.code} · ${course.section} · ${course.semester}")};items(course.meetings){Panel{Text("${it.day} · ${it.time}");Text("${it.room} · ${it.building} ${it.floor}")}};if(api.can("view_class_rosters"))item{TeacherMenu("Class roster","Enrolled students and profiles"){open("roster/${course.id}")}};if(api.can("view_attendance","correct_class_attendance","manage_attendance"))item{TeacherMenu("Attendance","Review dated records"){open("attendance/${course.id}")}};if(api.can("assign_class_seats","manage_seat_plans"))item{TeacherMenu("Live seats","Assign students to prepared layouts"){open("live/${course.id}")}};if(api.can("manage_grades"))item{TeacherMenu("Grades","Assigned-subject evaluation"){open("grades/${course.id}")}};if(api.can("manage_attendance_policies"))item{TeacherMenu("Attendance rules","Class policy"){open("policy/${course.id}")}}} }
