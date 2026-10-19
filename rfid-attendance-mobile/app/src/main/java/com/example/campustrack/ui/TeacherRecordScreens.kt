package com.example.campustrack.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
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
import java.net.URLEncoder
import java.time.LocalDate
import java.time.YearMonth

internal fun JSONObject.text(key: String) = optString(key, "").takeUnless { it == "null" }.orEmpty()
internal fun encoded(value: String) = URLEncoder.encode(value, "UTF-8")
internal data class TeacherField(val key: String, val label: String, val initial: String = "", val options: List<Pair<String,String>> = emptyList(), val multiline: Boolean = false)

@Composable
internal fun TeacherForm(title: String, fields: List<TeacherField>, warning: String = "", confirm: String = "Save", onExpired: () -> Unit, close: () -> Unit, submit: suspend (JSONObject) -> Unit) {
    NativeEditor(title, fields.map { field -> NativeField(field.key, field.label, field.initial, field.options, if(field.multiline) "multiline" else if(field.key in listOf("starts_on", "ends_on", "attendance_date")) "date" else "text",required=field.key in listOf("reason","starts_on","ends_on","attendance_date")) }, onExpired, close, confirm, extra = { if(warning.isNotBlank()) Text(warning, style = MaterialTheme.typography.bodyMedium) }, submit = submit)
}

@Composable
fun TeacherRosterScreen(api: ApiClient, offering: String, initialDate: String, grades: Boolean, onExpired: () -> Unit,workspace:String="teacher", openProfile: (String) -> Unit) {
    var date by rememberSaveable(offering) { mutableStateOf(initialDate) }
    var query by rememberSaveable { mutableStateOf("") }
    var page by rememberSaveable(query, date) { mutableIntStateOf(1) }
    var revision by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<JSONObject?>(null) }
    var action by remember { mutableStateOf("") }
    var notice by remember { mutableStateOf("") }
    val area = if (grades) "grades" else "attendance"
    Column {
        Column(Modifier.padding(horizontal = 20.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            PageTitle(if (grades) "Class grades" else "Class attendance", "Search the roster and open a student to make changes.")
            if (!grades) DateInput("Attendance date",date){date=it}
            OutlinedTextField(query, { query = it }, label = { Text("Search student name or school ID") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
            if (notice.isNotEmpty()) Text(notice, color = MaterialTheme.colorScheme.primary)
        }
        RemotePanel(api, "teacher/$area.php?offering_id=$offering&date=${encoded(date)}&q=${encoded(query)}&page=$page&revision=$revision&workspace=$workspace", onExpired) { json ->
            val rows = (json.optJSONArray("students") ?: JSONArray()).objects()
            val settings = json.optJSONObject("settings") ?: JSONObject()
            LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(14.dp)) {
                if (!grades) {
                    item { Text(json.text("schedule_message"), color = MaterialTheme.colorScheme.error) }
                    item { Text(json.optJSONObject("summary")?.let { summary -> listOf("Present", "Late", "Absent", "Excused", "Unrecorded").joinToString(" · ") { "$it ${summary.optInt(it)}" } } ?: "") }
                    if(json.optBoolean("can_finalize")) item { Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(onClick = { action = "finalize" }, enabled = json.optBoolean("scheduled")) { Text("Finalize date") }
                    } }
                    if (!json.optJSONObject("session")?.text("finalized_at").isNullOrEmpty()) item { StatusPill("Finalized") }
                }
                if (rows.isEmpty()) item { EmptyState("No students found", "Try another search or check class enrollment.") }
                items(rows, key = { it.getString("student_id") }) { student ->
                    Panel {
                        Text(student.text("name").ifEmpty { listOf("first_name", "last_name").joinToString(" ") { student.text(it) } }, style = MaterialTheme.typography.titleMedium)
                        Text(student.text("school_id")); StatusPill(student.text("status").ifEmpty { "Not entered" })
                        if (grades) {
                            Text("Overall: ${student.text("overall").ifEmpty { "—" }}")
                            Text(listOf("prelim", "midterm", "final").filter { settings.optInt("has_$it") == 1 }.joinToString(" · ") { "$it: ${student.text(it).ifEmpty { "—" }}" })
                        } else { Text("Time in: ${student.text("time_in").ifEmpty { "Not recorded" }}"); Text("Time out: ${student.text("time_out").ifBlank { "Not recorded" }} · Source: ${student.text("source").ifBlank { "Not recorded" }}", style=MaterialTheme.typography.bodySmall) }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            TextButton(onClick = { openProfile(student.getString("student_id")) }) { Text("Profile") }
                            if ((grades && student.text("status") !in listOf("finalized","conflict")) || (!grades && json.optBoolean("can_correct"))) Button(onClick = { editing = student; action = "edit" }) { Text(if (grades) "Enter grades" else "Correct attendance") }
                            else if (json.optBoolean("can_reopen")) TextButton(onClick = { editing = student; action = "reopen" }) { Text("Reopen grade") }
                            if(grades && student.text("status")=="conflict" && json.optBoolean("can_reopen")) TextButton(onClick={editing=student;action="resolve"}){Text("Review conflicting grades")}
                        }
                    }
                }
                item { val pagination = json.optJSONObject("pagination") ?: JSONObject(); PageControls(page, pagination.optInt("pages", 1), pagination.optInt("total")) { page = it } }
            }
            val student = editing
            if(action=="resolve" && student!=null) {
                RemotePanel(api,"workspace/grades.php?mode=originals&offering_id=$offering&student_id=${student.text("student_id")}",onExpired){result->
                    val originals=(result.optJSONObject("grade")?.optJSONArray("conflict_grades")?:JSONArray()).objects()
                    NativeEditor("Resolve shared subject grades",listOf(NativeField("source_grade_id","Use component scores as the shared draft","",originals.map{it.text("id") to "${it.text("component_type")} · Prelims ${it.text("prelim")} · Midterms ${it.text("midterm")} · Endterms ${it.text("final")}"})),onExpired,{action=""},"Confirm shared draft",extra={Text("The original values remain in audit history. Review the selected scores before saving.")}){input->api.post("workspace/grades.php",input.put("workspace",workspace).put("action","resolve").put("offering_id",offering.toLong()).put("student_id",student.optLong("student_id")));revision++}
                }
            }
            if (action == "edit" && student != null) {
                val fields = if (grades) listOf("prelim", "midterm", "final").filter { settings.optInt("has_$it") == 1 }.map { TeacherField(it, "${it.replaceFirstChar(Char::uppercase)} score (0–100)", student.text(it)) } + TeacherField("finalize", "Save as", "", listOf("" to "Draft", "1" to "Finalized"))
                else listOf(TeacherField("status", "Attendance status", student.text("status").takeIf { it != "Unrecorded" } ?: "Present", listOf("Present", "Late", "Absent", "Excused").map { it to it }), TeacherField("time_in", "Time in (HH:mm, optional)", student.text("time_in")), TeacherField("reason", "Reason for this correction", ""))
                TeacherForm(if (grades) "Enter grades" else "Correct attendance", fields, if (grades) "Finalized grades are locked. Only an authorized account can reopen them." else "Changes are recorded in the school's audit history.", onExpired = onExpired, close = { action = ""; editing = null }) { input ->
                    api.post("teacher/$area.php", input.put("workspace",workspace).put("action", if (grades) "save" else "manual").put("class_offering_id", offering.toLong()).put("student_id", student.getLong("student_id")).put("attendance_date", date)); notice = "Changes saved."; revision++
                }
            }
            if (action in listOf("finalize", "reopen")) TeacherForm(
                if (action == "finalize") "Finalize attendance?" else "Reopen grade?",
                emptyList(),
                if (action == "finalize") "All missing enrolled students for $date will be marked absent. Review the roster before confirming." else "This will unlock the grade for editing.",
                confirm = "Confirm", onExpired = onExpired, close = { action = "" }
            ) { input ->
                val result = api.post("teacher/$area.php", input.put("workspace",workspace).put("action", action).put("class_offering_id", offering.toLong()).put("attendance_date", date).put("grade_id", editing?.optLong("grade_id") ?: 0))
                notice = result.text("message"); revision++
            }
        }
    }
}

@Composable
fun TeacherPolicyScreen(api: ApiClient, offering: String, onExpired: () -> Unit,workspace:String="teacher") {
    var revision by remember { mutableIntStateOf(0) }; var editing by remember { mutableStateOf(false) }
    RemotePanel(api, "teacher/attendance.php?mode=policy&offering_id=$offering&revision=$revision&workspace=$workspace", onExpired) { json ->
        val policy = json.getJSONObject("policy"); val rules = policy.getJSONObject("effective")
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            PageTitle("Class attendance rules", if (policy.optBoolean("uses_campus_default")) "Using campus defaults" else "Using subject-specific rules")
            Panel { Text("Early check-in: ${rules.optInt("early_check_in_minutes", 10)} minutes"); Text("Grace period: ${rules.optInt("grace_period_minutes")} minutes"); Text("Absent after: ${rules.optInt("absent_after_minutes")} minutes"); Text("${rules.optInt("tardies_per_absence")} tardies per absence") }
            Button(onClick = { editing = true }) { Text("Edit class rules") }
        }
        if (editing) TeacherForm("Attendance policy", listOf(TeacherField("use_campus_default", "Policy source", policy.optBoolean("uses_campus_default").toString(), listOf("true" to "Campus defaults", "false" to "Custom class rules"))) + listOf("early_check_in_minutes", "grace_period_minutes", "absent_after_minutes", "tardies_per_absence").map { TeacherField(it, it.replace('_',' '), rules.text(it)) }, onExpired = onExpired, close = { editing = false }) { input ->
            input.put("use_campus_default", input.getString("use_campus_default") == "true")
            api.post("teacher/attendance.php", input.put("action", "policy").put("class_offering_id", offering.toLong())); revision++
        }
    }
}

@Composable
fun TeacherAttendanceCalendar(api: ApiClient, offering: String, data: StudentData, onExpired: () -> Unit, openDate: (String) -> Unit) {
    var month by rememberSaveable { mutableStateOf(YearMonth.from(data.today()).toString()) }
    var selected by rememberSaveable { mutableStateOf(data.today().toString()) }
    RemotePanel(api, "teacher/attendance.php?mode=calendar&offering_id=$offering&month=$month", onExpired) { json ->
        val sessions = (json.optJSONArray("sessions") ?: JSONArray()).objects()
        LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
            item { PageTitle("Class attendance calendar", "Select a date to review its roster.") }
            item { MonthPicker(YearMonth.parse(month), LocalDate.parse(selected), { month = it.toString(); selected = it.atDay(1).toString() }, { selected = it.toString() }) { day -> sessions.any { it.text("attendance_date") == day.toString() } } }
            item { Text(selected); Button(onClick = { openDate(selected) }) { Text("Open dated roster") } }
            items(sessions.filter { it.text("attendance_date") == selected }) { session -> Panel { StatusPill(if (session.optBoolean("finalized")) "Finalized" else "Open"); listOf("present_count", "late_count", "absent_count", "excused_count").forEach { Text("${it.replace('_',' ')}: ${session.optInt(it)}") } } }
        }
    }
}

@Composable
fun TeacherComplianceScreen(api: ApiClient, onExpired: () -> Unit, profile: (String) -> Unit) {
    var query by rememberSaveable { mutableStateOf("") }; var page by rememberSaveable(query) { mutableIntStateOf(1) }
    Column {
        OutlinedTextField(query, { query = it }, label = { Text("Search students or subjects") }, modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp))
        RemotePanel(api, "teacher/compliance.php?q=${encoded(query)}&page=$page", onExpired) { json ->
            LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(14.dp)) {
                item { PageTitle("Student compliance", "Deficiencies calculated with school and class policies.") }
                items((json.optJSONArray("items") ?: JSONArray()).objects()) { student -> Panel { Text(student.text("student_name"), style = MaterialTheme.typography.titleMedium); Text("${student.text("course_code")} · ${student.text("section_name")}"); listOf("absences", "tardies", "tardy_equivalent", "missed_events", "missed_recollections", "total_deficiencies").forEach { Text("${it.replace('_',' ')}: ${student.optInt(it)}") }; TextButton(onClick = { profile(student.getString("id")) }) { Text("Student profile") } } }
                item { PageControls(page, maxOf(1, (json.optInt("total") + 49) / 50), json.optInt("total")) { page = it } }
            }
        }
    }
}

@Composable
fun TeacherTimeOffScreen(api: ApiClient, today: String, onExpired: () -> Unit, changed: () -> Unit) {
    var revision by remember { mutableIntStateOf(0) }; var editing by remember { mutableStateOf<JSONObject?>(null) }; var cancel by remember { mutableStateOf<JSONObject?>(null) }
    RemotePanel(api, "teacher/time-off.php?revision=$revision", onExpired) { json ->
        LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
            item { PageTitle("Teaching time off", "Affected students will see class suspension notices.") }
            item { Button(onClick = { editing = JSONObject() }) { Text("Schedule time off") } }
            items((json.optJSONArray("items") ?: JSONArray()).objects()) { row ->
                val tags = row.optJSONObject("customTags") ?: JSONObject()
                if (tags.optBoolean("editable")) Panel { Text("${tags.text("startsOn")} to ${tags.text("endsOn")}"); Text(tags.text("reason")); Row { TextButton(onClick = { editing = tags }) { Text("Edit") }; TextButton(onClick = { cancel = tags }) { Text("Cancel time off") } } }
            }
        }
    }
    editing?.let { entry -> TeacherForm("Teaching time off", listOf(TeacherField("starts_on", "Start date · YYYY-MM-DD", entry.text("startsOn").ifEmpty { today }), TeacherField("ends_on", "End date · YYYY-MM-DD", entry.text("endsOn").ifEmpty { today }), TeacherField("reason", "Reason", entry.text("reason"))), warning = "Past dates, overlapping time off and dates with existing attendance are checked by the school service.", onExpired = onExpired, close = { editing = null }) { input -> api.post("teacher/time-off.php", input.put("action", "save").put("time_off_id", entry.optLong("sourceId"))); revision++; changed() } }
    cancel?.let { entry -> TeacherForm("Cancel this time off?", emptyList(), "Classes in this date range will no longer be suspended by this entry.", "Confirm cancellation", onExpired, { cancel = null }) { api.post("teacher/time-off.php", JSONObject().put("action", "cancel").put("time_off_id", entry.getLong("sourceId"))); revision++; changed() } }
}
