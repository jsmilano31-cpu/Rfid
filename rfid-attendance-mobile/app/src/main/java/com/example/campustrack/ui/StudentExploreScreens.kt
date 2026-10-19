package com.example.campustrack.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import org.json.JSONArray
import org.json.JSONObject
import java.net.URLEncoder
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.DateTimeFormatter

private fun encode(value: String) = URLEncoder.encode(value, "UTF-8")
private fun JSONObject.label(key: String) = optString(key, "").takeUnless { it == "null" }.orEmpty()

@Composable
fun CampusFeedScreen(api: ApiClient?, onExpired: () -> Unit, openProfile: (String) -> Unit) {
    var category by rememberSaveable { mutableStateOf("All") }
    RemotePanel(api, "student/feed.php", onExpired) { json ->
        val items = (json.optJSONArray("items") ?: JSONArray()).objects().filter { category == "All" || it.label("category").equals(category, true) }
        LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(18.dp)) {
            item { PageTitle("Campus feed", "Updates from your campus community.") }
            item { FilterChips(listOf("All", "Announcements", "Active", "Inactive"), category) { category = it } }
            if (items.isEmpty()) item { EmptyState("No updates", "There are no posts in this category.") }
            items(items, key = { it.getString("id") }) { post ->
                Panel {
                    val poster = post.optJSONObject("poster") ?: JSONObject()
                    TextButton(onClick = { openProfile(poster.getString("id")) }) { Text(poster.label("name")) }
                    Text(post.label("audience_label"), style = MaterialTheme.typography.labelSmall)
                    Text(post.label("title"), style = MaterialTheme.typography.titleLarge)
                    StatusPill(post.label("status"))
                    Text(post.label("description"))
                    val images = post.optJSONArray("images") ?: JSONArray()
                    for (index in 0 until images.length()) coil.compose.AsyncImage(images.getString(index), post.label("title"), Modifier.fillMaxWidth().heightIn(max = 300.dp))
                    Text("${post.label("event_date")} ${post.label("start_time")} to ${post.label("end_date").ifEmpty { post.label("event_date") }} ${post.label("end_time")}")
                    Text(post.label("venue"))
                    Text("${post.optInt("attended_count")} attended · ${post.optInt("participant_count")} participants", style = MaterialTheme.typography.bodySmall)
                    if (post.optBoolean("attendance_required")) Text("Attendance required")
                    if (post.optBoolean("check_out_required")) Text("Check-out required")
                    if (post.label("check_in_start").isNotEmpty()) Text("Check-in: ${post.label("check_in_start")} to ${post.label("check_in_end")}")
                    if (post.label("my_attendance_status").isNotEmpty()) StatusPill(post.label("my_attendance_status"))
                }
            }
        }
    }
}

@Composable
fun RemotePanel(api: ApiClient?, path: String, onExpired: () -> Unit, content: @Composable (JSONObject) -> Unit) {
    var value by remember(api, path.substringBefore('?'),api?.roles,api?.permissions,api?.capabilities?.optJSONObject("preview")?.toString()) { mutableStateOf<JSONObject?>(null) }
    var error by remember(api, path.substringBefore('?'),api?.roles,api?.permissions,api?.capabilities?.optJSONObject("preview")?.toString()) { mutableStateOf<String?>(null) }
    var loading by remember { mutableStateOf(true) }
    var retry by remember { mutableIntStateOf(0) }
    val notificationRefresh by CampusPush.refresh.collectAsState()
    LaunchedEffect(path, retry,notificationRefresh,api?.permissions) {
        error = null; loading = true
        try { delay(250); value = api?.get(path) ?: JSONObject() }
        catch (cancelled: CancellationException) { throw cancelled }
        catch (failure: Exception) { if (failure is ApiException && failure.status == 401) onExpired() else error = failure.message ?: "Could not load this page." }
        finally { loading = false }
    }
    when {
        error != null -> Column(Modifier.padding(20.dp)) { EmptyState("Couldn't load this page", error!!); Button(onClick = { retry++ }) { Text("Try again") } }
        value == null -> Column(Modifier.fillMaxWidth().padding(20.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){LinearProgressIndicator(Modifier.fillMaxWidth());Text("Loading records…",style=MaterialTheme.typography.bodySmall)}
        else -> Column { if(loading) LinearProgressIndicator(Modifier.fillMaxWidth()); content(value!!) }
    }
}

@Composable
fun PeopleScreen(api: ApiClient?, offering: String?, onExpired: () -> Unit, teacher: Boolean = false, openProfile: (String) -> Unit) {
    var query by rememberSaveable { mutableStateOf("") }
    var role by rememberSaveable { mutableStateOf("All roles") }
    var page by rememberSaveable { mutableIntStateOf(1) }
    Column {
        Column(Modifier.padding(horizontal = 20.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            PageTitle(if (offering == null) "People" else "Class roster", if (offering == null) "Find people and your shared campus connections." else "Students enrolled in this subject.")
            OutlinedTextField(query, { query = it; page = 1 }, Modifier.fillMaxWidth(), label = { Text("Search by name or school ID") }, singleLine = true)
            if (offering == null) SearchSelect("Role",role,listOf("All roles" to "All roles")+(api?.capabilities?.optJSONArray("role_choices")?:JSONArray()).objects().map{it.text("name") to it.text("name")}){role=it;page=1}
        }
        if (offering == null && query.trim().length < 2) { Column(Modifier.padding(20.dp)) { EmptyState("Find someone", "Enter at least two characters. You can also search by email.") }; return }
        val path = if (offering == null) "student/people.php?q=${encode(query)}&role=${encode(if (role == "All roles") "" else role)}&page=$page" else "${if (teacher) "teacher" else "student"}/roster.php?offering_id=$offering&q=${encode(query)}&page=$page"
        RemotePanel(api, path, onExpired) { json ->
            val rows = (json.optJSONArray(if (offering == null) "items" else "students") ?: JSONArray()).objects()
            val pagination = json.optJSONObject("pagination") ?: json
            LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(12.dp)) {
                json.optJSONObject("subject")?.let { subject -> item { Text("${subject.label("course_code")} · ${subject.label("section_name")}", style = MaterialTheme.typography.titleMedium) } }
                if (rows.isEmpty()) item { EmptyState("No people found", "Try another search.") }
                items(rows, key = { it.getString("id") }) { person ->
                    Card(onClick = { openProfile(person.getString("id")) }, modifier = Modifier.fillMaxWidth()) {
                        Row(Modifier.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(14.dp), verticalAlignment = Alignment.CenterVertically) {
                            coil.compose.AsyncImage(person.label("photo_url"), null, Modifier.size(48.dp))
                            Column(Modifier.weight(1f)) { Text(person.label("name"), style = MaterialTheme.typography.titleMedium); Text(listOf(person.label("school_id"), person.label("role")).filter { it.isNotEmpty() }.joinToString(" · ")) }
                            Icon(Icons.Outlined.ChevronRight, "Open profile")
                        }
                    }
                }
                item { PageControls(page, pagination.optInt("pages", 1), pagination.optInt("total", rows.size)) { page = it } }
            }
        }
    }
}

@Composable
fun PageControls(page: Int, pages: Int, total: Int, change: (Int) -> Unit) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
        TextButton(onClick = { change(page - 1) }, enabled = page > 1) { Text("Previous") }
        Text("$page / $pages · $total records", style = MaterialTheme.typography.labelSmall)
        TextButton(onClick = { change(page + 1) }, enabled = page < pages) { Text("Next") }
    }
}

@Composable
fun DirectoryProfileScreen(api: ApiClient?, id: String, onExpired: () -> Unit, openRoster: (String) -> Unit) {
    var semester by rememberSaveable(id) { mutableStateOf("") }
    RemotePanel(api, "student/directory-profile.php?id=$id" + (if(semester.isBlank())""else"&schedule_semester_id=$semester"), onExpired) { json ->
        val person = json.optJSONObject("profile") ?: JSONObject()
        val name = listOf("first_name", "middle_name", "last_name").map { person.label(it) }.filter { it.isNotEmpty() }.joinToString(" ")
        if (json.optBoolean("is_private")) {
            LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
                item { PageTitle(name, "Private profile") }
                item { EmptyState("$name has set their profile to private", "Their account remains available for classes, attendance, messaging, and other required campus functions.", Icons.Outlined.Lock) }
            }
            return@RemotePanel
        }
        LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
            item { coil.compose.AsyncImage(person.label("photo_url"), "Profile picture", Modifier.fillMaxWidth().height(140.dp)) }
            item { PageTitle(name, person.label("role_names_label")) }
            item { Panel { listOf("school_id", "department_name", "program_name", "section_name", "email").forEach { key -> if (person.label(key).isNotEmpty()) Text("${key.replace('_', ' ')}: ${person.label(key)}") } } }
            if(json.optBoolean("can_view_schedule")) {
                val schedule=json.optJSONObject("schedule")?:JSONObject()
                item { SectionTitle("Class schedule"); SearchSelect("Semester",semester.ifBlank{schedule.text("semester_id")},(schedule.optJSONArray("terms")?:JSONArray()).objects().map{it.text("id") to "${it.text("name")} ${it.text("school_year_name")}"}){semester=it} }
                items((schedule.optJSONArray("rows")?:JSONArray()).objects()){meeting->Panel{Text("${meeting.text("course_code")} · ${meeting.text("section_name")}",style=MaterialTheme.typography.titleMedium);Text(meeting.text("title").ifBlank{meeting.text("course_title")});Text("${meeting.text("day_of_week")} · ${meeting.text("start_time")} – ${meeting.text("end_time")}");Text("${meeting.text("room_code")} · ${meeting.text("teacher_names")}")}}
                if((schedule.optJSONArray("rows")?.length()?:0)==0)item{EmptyState("No scheduled classes","No meetings are available for this semester.")}
            }
            item { SectionTitle(json.label("relationship_heading").ifEmpty { "Shared classes" }); Text(json.label("relationship_copy")) }
            items((json.optJSONArray("classes") ?: JSONArray()).objects()) { course ->
                Card(onClick = { openRoster(course.getString("class_offering_id")) }, modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp)) { Text(course.label("course_code"), style = MaterialTheme.typography.titleMedium); Text(course.label("course_title").ifEmpty { course.label("title") }); Text(course.label("section_name")); Text("Open class roster", color = MaterialTheme.colorScheme.primary) }
                }
            }
            if (json.optBoolean("can_view_grades")) {
                item { SectionTitle("Grades") }
                items((json.optJSONArray("grades") ?: JSONArray()).objects()) { grade -> Panel { Text("${grade.label("course_code")} · ${grade.label("course_title")}");Text("${grade.text("section_name")} · ${grade.text("semester_name")} · ${grade.text("school_year_name")}",style=MaterialTheme.typography.bodySmall);val settings=json.optJSONObject("grade_settings")?:JSONObject();listOf("prelim" to "Prelims","midterm" to "Midterms","final" to "Endterms").forEach{(key,label)->if(settings.optInt("has_$key")==1)Text("$label: ${grade.text(key).ifBlank{"—"}}")}; Text("Overall: ${grade.label("overall").ifEmpty { "—" }} · ${grade.label("status")}") } }
            }
            if (json.optBoolean("can_view_attendance")) {
                item { SectionTitle("Attendance");val summary=json.optJSONObject("attendance_summary")?:JSONObject();summary.keys().forEach{key->Text("$key: ${summary.text(key)}",style=MaterialTheme.typography.labelLarge)} }
                items((json.optJSONArray("attendance") ?: JSONArray()).objects()) { record -> Panel { Text("${record.label("attendance_date")} · ${record.label("course_code")}"); StatusPill(record.label("status")) } }
            }
        }
    }
}

@Composable
fun SentScreen(api: ApiClient?, onExpired: () -> Unit) {
    var page by rememberSaveable { mutableIntStateOf(1) }
    var selected by remember { mutableStateOf<JSONObject?>(null) }
    RemotePanel(api, "student/sent.php?page=$page", onExpired) { json ->
        val rows = (json.optJSONArray("items") ?: JSONArray()).objects()
        LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
            item { PageTitle("Sent messages", "Messages previously sent from your account.") }
            if (rows.isEmpty()) item { EmptyState("No sent messages", "Messages sent through an authorized web role will appear here.") }
            items(rows) { row -> Card(onClick = { selected = row }, modifier = Modifier.fillMaxWidth()) { Column(Modifier.padding(16.dp)) { Text(row.label("subject"), style = MaterialTheme.typography.titleMedium); Text("${row.label("recipient_count")} recipients · ${row.label("created_at")}") } } }
            item { PageControls(page, json.optInt("pages", 1), json.optInt("total")) { page = it } }
        }
    }
    selected?.let { row -> AlertDialog(onDismissRequest = { selected = null }, title = { Text(row.label("subject")) }, text = { androidx.compose.foundation.lazy.LazyColumn { item { Text(row.label("body")) } } }, confirmButton = { TextButton(onClick = { selected = null }) { Text("Close") } }) }
}

@Composable
fun MonthPicker(month: YearMonth, selected: LocalDate, changeMonth: (YearMonth) -> Unit, select: (LocalDate) -> Unit, hasEntry: (LocalDate) -> Boolean) {
    Panel {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
            IconButton(onClick = { changeMonth(month.minusMonths(1)) }) { Icon(Icons.Outlined.ChevronLeft, "Previous month") }
            Text(month.format(DateTimeFormatter.ofPattern("MMMM yyyy")), style = MaterialTheme.typography.titleMedium)
            IconButton(onClick = { changeMonth(month.plusMonths(1)) }) { Icon(Icons.Outlined.ChevronRight, "Next month") }
        }
        Row { listOf("M", "T", "W", "T", "F", "S", "S").forEach { Text(it, Modifier.weight(1f), textAlign = androidx.compose.ui.text.style.TextAlign.Center) } }
        val offset = month.atDay(1).dayOfWeek.value - 1
        repeat((offset + month.lengthOfMonth() + 6) / 7) { week ->
            Row {
                repeat(7) { day ->
                    val number = week * 7 + day - offset + 1
                    if (number !in 1..month.lengthOfMonth()) Spacer(Modifier.weight(1f).height(48.dp))
                    else {
                        val date = month.atDay(number)
                        Surface(onClick = { select(date) }, modifier = Modifier.weight(1f).height(48.dp), shape = RoundedCornerShape(10.dp), color = if (date == selected) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface) {
                            Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) { Text(number.toString()); if (hasEntry(date)) Box(Modifier.size(4.dp).background(MaterialTheme.colorScheme.primary, RoundedCornerShape(4.dp))) }
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun StudentCalendarScreen(data: StudentData, api: ApiClient?, offering: String?, onExpired: () -> Unit, openActivity: (String) -> Unit) {
    var monthText by rememberSaveable { mutableStateOf(YearMonth.from(data.today()).toString()) }
    var dateText by rememberSaveable { mutableStateOf(data.today().toString()) }
    val month = YearMonth.parse(monthText); val date = LocalDate.parse(dateText)
    val records = data.attendance.filter { offering == null || it.courseId == offering }
    @Composable fun CalendarBody(entries: List<JSONObject>) {
        fun onDate(entry: JSONObject, target: LocalDate): Boolean = target.toString() >= entry.label("from").take(10) && target.toString() <= entry.label("to").take(10)
        LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
            item { PageTitle(if (offering == null) "Campus calendar" else "Attendance calendar", if (offering == null) "Events, formation and teaching time off." else data.course(offering).title) }
            item { TextButton(onClick = { monthText = YearMonth.from(data.today()).toString(); dateText = data.today().toString() }) { Text("Today") } }
            item { MonthPicker(month, date, { monthText = it.toString(); dateText = it.atDay(1).toString() }, { dateText = it.toString() }) { target -> if (offering != null) records.any { it.date == target } else entries.any { onDate(it, target) } } }
            item { SectionTitle(date.displayDate()) }
            if (offering != null) {
                val selectedRecords = records.filter { it.date == date }
                if (selectedRecords.isEmpty()) item { EmptyState("No attendance record", "No record was recorded for this date.") }
                items(selectedRecords) { record -> Panel { StatusPill(record.status.name); Text("Time in: ${record.timeIn ?: "Not recorded"}"); Text("Time out: ${record.timeOut ?: "Not recorded"}"); Text("Source: ${record.source.replace('_', ' ')}") } }
            } else {
                val agenda = entries.filter { onDate(it, date) }.distinctBy { it.label("id") }
                if (agenda.isEmpty()) item { EmptyState("No campus entries", "There are no events or time-off entries for this date.") }
                items(agenda) { entry ->
                    Panel {
                        Text(entry.label("title"), style = MaterialTheme.typography.titleMedium)
                        Text(entry.label("description")); Text(entry.label("location"))
                        Text("${entry.label("from").replace('T', ' ')} to ${entry.label("to").replace('T', ' ')}", style = MaterialTheme.typography.bodySmall)
                        val entryId = entry.label("id").replace("recollection-", "formation-")
                        if (data.activities.any { it.id == entryId }) TextButton(onClick = { openActivity(entryId) }) { Text("View activity") }
                    }
                }
                val notices = data.courses.filter { date.dayOfWeek in it.days }.mapNotNull { course -> course.notice(date)?.let { "${course.code}: $it" } }
                items(notices) { notice -> Panel { DetailLine(Icons.Outlined.Info, notice) } }
            }
        }
    }
    if (offering != null) CalendarBody(emptyList()) else RemotePanel(api, "student/calendar.php", onExpired) { json -> CalendarBody((json.optJSONArray("entries") ?: JSONArray()).objects()) }
}
