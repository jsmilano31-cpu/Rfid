package com.example.campustrack.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import org.json.JSONObject

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TeacherApp(api: ApiClient, onSessionEnded: () -> Unit, onSignOut: () -> Unit, onStudent: (() -> Unit)?) {
    val appPreferences = LocalAppPreferences.current
    var route by rememberSaveable { mutableStateOf(appPreferences.value.startPage.takeIf { it in setOf("home","schedule","messages","more") } ?: "home") }
    var history by rememberSaveable { mutableStateOf(listOf<String>()) }
    var refresh by remember { mutableIntStateOf(0) }
    var payload by remember { mutableStateOf<JSONObject?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    var assistantOpen by remember { mutableStateOf(false) }
    if (assistantOpen) AssistantScreen(loadRecords = { parsePortal(api.get("teacher/portal.php")) }, teacher = true, onSessionEnded = onSessionEnded, onClose = { assistantOpen = false })
    fun open(path: String) { if (path != route) { history = history + route; route = path } }
    fun back() { route = history.lastOrNull() ?: "home"; history = history.dropLast(1) }
    BackHandler(history.isNotEmpty()) { back() }
    LaunchedEffect(refresh) {
        loading = true
        error = null
        try { payload = api.get("teacher/portal.php") }
        catch (cancelled: CancellationException) { throw cancelled }
        catch (failure: Exception) { if (failure is ApiException && failure.status == 401) onSessionEnded() else error = failure.message ?: "Could not load your teaching workspace." }
        finally { loading = false }
    }
    val data = remember(payload) { payload?.let(::parsePortal) }
    val permissions = payload?.optJSONArray("permissions")?.let { list -> (0 until list.length()).map { list.getString(it) }.toSet() }.orEmpty()
    val tabs = listOf(
        PortalDestination("home", "Classes", Icons.Outlined.SpaceDashboard),
        PortalDestination("schedule", "Schedule", Icons.Outlined.CalendarMonth),
        PortalDestination("messages", "Inbox", Icons.Outlined.MailOutline),
        PortalDestination("more", "More", Icons.Outlined.GridView)
    )
    val selected = when {
        route == "home" || route.startsWith("class/") || route.startsWith("attendance/") || route.startsWith("grades/") || route.startsWith("live/") || route.startsWith("policy/") -> "home"
        route == "schedule" -> "schedule"
        route == "messages" || route.startsWith("message/") -> "messages"
        else -> "more"
    }
    PortalScaffold(
        topBar = { PortalTopBar(data?.student?.initials.orEmpty(), history.isNotEmpty(), ::back, { open("profile") }) {
            NotificationBell(api, onSessionEnded)
            IconButton(onClick = { open("messages") }) {
                val unread = data?.messages?.count { !it.read } ?: 0
                BadgedBox(badge = { if (unread > 0) Badge { Text(unread.toString()) } }) { Icon(Icons.Outlined.MailOutline, "Messages, $unread unread") }
            }
        } },
        bottomBar = { PortalBottomBar(tabs, selected, data != null && !loading, onNavigate = { route = it; history = emptyList() }, onAssistant = { assistantOpen = true }) }
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.TopCenter) {
            Column(Modifier.widthIn(max = 720.dp).fillMaxSize()) {
                PortalStatusRow("Teacher", loading) { refresh++ }
                if (loading && data != null) LinearProgressIndicator(Modifier.fillMaxWidth())
                Box(Modifier.weight(1f)) {
            if (error != null) Column(Modifier.padding(20.dp)) { EmptyState("Couldn't load your workspace", error!!); Button(onClick = { refresh++ }) { Text("Try again") }; TextButton(onClick = onSignOut) { Text("Sign out") } }
            else if (data == null) LoadingSkeleton()
            else {
                val id = route.substringAfter('/', "")
                when {
                    route == "home" -> LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
                        item { PortalGreeting(data.student.name, data.today(), "Your assigned classes and teaching tools.") }
                        item { Panel { Text("${data.courses.size} assigned subjects", style = MaterialTheme.typography.headlineSmall); Text(data.today().displayDate()); TextButton(onClick = { open("feed") }) { Text("Campus updates") } } }
                        if (data.courses.isEmpty()) item { EmptyState("No assigned classes", "Your subjects will appear after the school assigns them to you.") }
                        items(data.courses, key = { it.id }) { course ->
                            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) { CourseCard(course) { open("class/${course.id}") }; if (data.today().dayOfWeek in course.days) course.notice(data.today())?.let { Text(it, color = MaterialTheme.colorScheme.error) } }
                        }
                    }
                    route.startsWith("class/") -> data.courses.find { it.id == id }?.let { course ->
                        LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
                            item { PageTitle(course.title, "${course.code} · ${course.section} · ${course.semester}") }
                            items(course.meetings) { meeting -> Panel { Text("${meeting.day} · ${meeting.time}"); Text("${meeting.room} · ${meeting.building} ${meeting.floor}") } }
                            item { Button(onClick = { open("roster/$id") }, modifier = Modifier.fillMaxWidth()) { Text("Class roster and student profiles") } }
                            if ("manage_attendance" in permissions) {
                                item { TeacherMenu("Attendance", "Dated roster, corrections and finalization") { open("attendance/$id") } }
                                item { TeacherMenu("Attendance calendar", "Monthly class attendance summaries") { open("attendance-calendar/$id") } }
                                item { TeacherMenu("Live seats", "Assignments and attendance status") { open("live/$id") } }
                            }
                            if ("manage_grades" in permissions) item { TeacherMenu("Grades", "Enter evaluations and finalize grades") { open("grades/$id") } }
                            if ("manage_attendance_policies" in permissions) item { TeacherMenu("Class attendance rules", "Campus defaults or subject-specific policy") { open("policy/$id") } }
                        }
                    }
                    route.startsWith("attendance/") -> TeacherRosterScreen(api, id, data.today().toString(), false, onSessionEnded) { open("person/$it") }
                    route.startsWith("grades/") -> TeacherRosterScreen(api, id, data.today().toString(), true, onSessionEnded) { open("person/$it") }
                    route.startsWith("attendance-calendar/") -> TeacherAttendanceCalendar(api, id, data, onSessionEnded) { date -> open("attendance-date/$id/$date") }
                    route.startsWith("attendance-date/") -> TeacherRosterScreen(api, id.substringBefore('/'), id.substringAfter('/'), false, onSessionEnded) { open("person/$it") }
                    route.startsWith("roster/") -> PeopleScreen(api, id, onSessionEnded, teacher = true) { open("person/$it") }
                    route.startsWith("person/") -> DirectoryProfileScreen(api, id, onSessionEnded) { open("roster/$it") }
                    route.startsWith("policy/") -> TeacherPolicyScreen(api, id, onSessionEnded)
                    route.startsWith("live/") -> TeacherLiveSeats(api, id, data.today().toString(), onSessionEnded)
                    route == "schedule" -> ScheduleScreen(data) { open("class/$it") }
                    route == "calendar" -> StudentCalendarScreen(data, api, null, onSessionEnded) { open("activity/$it") }
                    route == "campus" -> CampusScreen(data) { open("activity/$it") }
                    route.startsWith("activity/") -> data.activities.find { it.id == id }?.let { ActivityScreen(it, data.today()) }
                    route == "feed" -> CampusFeedScreen(api, onSessionEnded) { open("person/$it") }
                    route == "people" -> PeopleScreen(api, null, onSessionEnded) { open("person/$it") }
                    route == "compliance" -> TeacherComplianceScreen(api, onSessionEnded) { open("person/$it") }
                    route == "time-off" -> TeacherTimeOffScreen(api, data.today().toString(), onSessionEnded) { refresh++ }
                    route == "seats" -> TeacherSeatPlans(api, onSessionEnded)
                    route == "compose" -> TeacherComposeScreen(api, onSessionEnded)
                    route == "sent" -> SentScreen(api, onSessionEnded)
                    route == "messages" -> MessagesScreen(data.messages, emptyList()) { messageId ->
                        open("message/$messageId"); scope.launch { try { api.post("student/message-read.php", JSONObject().put("message_id", messageId.toLong())); refresh++ } catch (cancelled: CancellationException) { throw cancelled } catch (failure: Exception) { error = failure.message } }
                    }
                    route.startsWith("message/") -> data.messages.find { it.id == id }?.let { MessageScreen(it) { sender -> open("person/$sender") } }
                    route == "profile" -> ProfileScreen(data.student, onPhoto = { bytes, mime -> api.uploadPhoto(bytes, mime); refresh++ }, onSave = { email -> api.post("student/profile-update.php", JSONObject().put("email", email)); refresh++ }, onVisibility = { value -> api.post("student/profile-visibility.php", JSONObject().put("is_profile_public", value)); refresh++ }, onPassword = { current, password, confirmation -> api.post("auth/password.php", JSONObject().put("current_password", current).put("password", password).put("password_confirmation", confirmation)) })
                    route == "settings" -> SettingsScreen(api, onSessionEnded) { open("profile") }
                    else -> LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(18.dp)) {
                        item { PageTitle("Your workspace", "Your teaching tools and campus account.") }
                        item { WorkspaceProfileCard(data.student) { open("profile") } }
                        item { TeacherMenu("Campus calendar", "Month view and agenda") { open("calendar") } }
                        if (onStudent != null) item { Button(onClick = onStudent, modifier = Modifier.fillMaxWidth()) { Text("Open student workspace") } }
                        if ("send_messages" in permissions) item { TeacherMenu("Compose message", "Individual, section, role or campus recipients") { open("compose") } }
                        item { TeacherMenu("Sent messages", "Your sent communication history") { open("sent") } }
                        if ("manage_teacher_time_off" in permissions) item { TeacherMenu("Teaching time off", "Schedule, edit or cancel time off") { open("time-off") } }
                        if ("manage_seat_plans" in permissions) item { TeacherMenu("Seat plans", "Edit room layouts and reusable presets") { open("seats") } }
                        if ("view_compliance" in permissions) item { TeacherMenu("Student compliance", "Class absences, tardies and deficiencies") { open("compliance") } }
                        item { TeacherMenu("People", "Directory and class relationships") { open("people") } }
                        item { TeacherMenu("Campus activities", "Events, recollections and retreats") { open("campus") } }
                        item { TeacherMenu("My profile", "Contact email, password and profile photo") { open("profile") } }
                        item { TeacherMenu("Settings", "Notifications, appearance, privacy and app behavior") { open("settings") } }
                        item { OutlinedButton(onClick = { scope.launch { try { api.post("auth/logout.php") } catch (cancelled: CancellationException) { throw cancelled } catch (_: java.io.IOException) { /* Clear the local session even when offline. */ } finally { onSignOut() } } }, modifier = Modifier.fillMaxWidth()) { Text("Sign out") } }
                    }
                }
            }
                }
            }
        }
    }
}

@Composable
internal fun TeacherMenu(title: String, description: String, open: () -> Unit) {
    MenuCard(title, description, when {
        title.contains("calendar", true) -> Icons.Outlined.CalendarMonth
        title.contains("grade", true) -> Icons.Outlined.School
        title.contains("message", true) -> Icons.Outlined.MailOutline
        title.contains("seat", true) -> Icons.Outlined.EventSeat
        title.contains("profile", true) -> Icons.Outlined.Badge
        title.contains("People", true) -> Icons.Outlined.People
        title.contains("attendance", true) || title.contains("compliance", true) -> Icons.Outlined.VerifiedUser
        else -> Icons.Outlined.GridView
    }, open)
}
