package com.example.campustrack.ui

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.animation.core.tween
import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.FactCheck
import androidx.compose.material.icons.automirrored.outlined.MenuBook
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.format.TextStyle
import java.util.Locale

internal val pagePadding: PaddingValues
    @Composable get() = PaddingValues(start = 20.dp, end = 20.dp, top = 8.dp, bottom = 28.dp + LocalPortalBottomInset.current)

@Composable
fun FilterChips(options: List<String>, selected: String, onSelect: (String) -> Unit) {
    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        options.forEach { option -> FilterChip(modifier = Modifier.testTag("filter/$option"), selected = selected == option, onClick = { onSelect(option) }, label = { Text(option) }, leadingIcon = if (selected == option) { { Icon(Icons.Outlined.Check, null, Modifier.size(16.dp)) } } else null) }
    }
}

@Composable
fun AttendanceScreen(data: StudentData, openCourse: (String) -> Unit) {
    var status by rememberSaveable { mutableStateOf("All") }
    var query by rememberSaveable { mutableStateOf("") }
    var page by rememberSaveable(status, query) { mutableIntStateOf(1) }
    val records = data.attendance.filter { (status == "All" || it.status.name == status) && "${data.course(it.courseId).code} ${data.course(it.courseId).title}".contains(query.trim(), true) }
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
        item { PageTitle("Attendance", "Your check-ins, all in one place.") }
        item {
            Panel {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                    IconTile(Icons.AutoMirrored.Outlined.FactCheck)
                    Column { Text("${data.attendancePercent}% attendance", style = MaterialTheme.typography.titleLarge); Text("${data.historyLabel} · Late arrivals included", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                }
                LinearProgressIndicator(progress = { data.attendancePercent / 100f }, modifier = Modifier.fillMaxWidth().height(6.dp), color = MaterialTheme.colorScheme.secondary, trackColor = MaterialTheme.colorScheme.surfaceVariant)
            }
        }
        item { OutlinedTextField(query, { query = it }, modifier = Modifier.fillMaxWidth(), placeholder = { Text("Search subjects") }, leadingIcon = { Icon(Icons.Outlined.Search, null) }, singleLine = true, shape = RoundedCornerShape(16.dp), trailingIcon = { if (query.isNotEmpty()) IconButton(onClick = { query = "" }) { Icon(Icons.Outlined.Close, "Clear search") } }) }
        item { FilterChips(listOf("All", "Present", "Late", "Absent", "Excused"), status) { status = it } }
        item { Text("${records.size} records", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        if (records.isEmpty()) item { EmptyState("No matching attendance", "Try another subject or attendance status.") }
        items(records.drop((page - 1) * 30).take(30), key = { it.id }) { record -> AttendanceRow(record, data.course(record.courseId)) { openCourse(record.courseId) } }
        item { PageControls(page, maxOf(1, (records.size + 29) / 30), records.size) { page = it } }
    }
}

@Composable
fun ScheduleScreen(data: StudentData, openCourse: (String) -> Unit) {
    val reducedMotion=LocalAppPreferences.current.value.reducedMotion
    var semester by rememberSaveable { mutableStateOf("") }
    val scheduleData=if(semester.isBlank())data else data.copy(courses=data.courses.filter{it.semesterId==semester})
    var mode by rememberSaveable { mutableStateOf("Day list") }
    var dayIndex by rememberSaveable { mutableIntStateOf(data.today().dayOfWeek.value - 1) }
    val day = DayOfWeek.of(dayIndex + 1)
    val courses = scheduleData.courses.filter { day in it.days }
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(18.dp)) {
        item { PageTitle("Your schedule", "Make room for a great week.") }
        item { SearchSelect("Semester",semester,listOf("" to "All enrolled semesters")+data.courses.distinctBy{it.semesterId}.filter{it.semesterId.isNotBlank()}.map{it.semesterId to "${it.semester} · ${it.schoolYear}"}){semester=it} }
        item {
            Panel {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("Weekly timetable", Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
                    TextButton(onClick = { dayIndex = data.today().dayOfWeek.value - 1 }) { Text("Today") }
                }
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    DayOfWeek.entries.forEachIndexed { index, value ->
                        val active = index == dayIndex
                        Surface(onClick = { dayIndex = index }, color = if (active) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.background, shape = RoundedCornerShape(14.dp), modifier = Modifier.width(48.dp).height(58.dp)) {
                            Box(contentAlignment = Alignment.Center) { Text(value.getDisplayName(TextStyle.SHORT, Locale.ENGLISH), style = MaterialTheme.typography.labelMedium) }
                        }
                    }
                }
            }
        }
        item { FilterChips(listOf("Day list", "Timetable"), mode) { mode = it } }
        if (mode == "Timetable") item { WeeklyGrid(scheduleData, openCourse) }
        item { Text(day.getDisplayName(TextStyle.FULL, Locale.ENGLISH), style = MaterialTheme.typography.titleLarge) }
        item {
            AnimatedContent(targetState = dayIndex, transitionSpec = {fadeIn(tween(if(reducedMotion)0 else 180)) togetherWith fadeOut(tween(if(reducedMotion)0 else 180))}, label = "Schedule day") { index ->
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    val selectedCourses = scheduleData.courses.filter { DayOfWeek.of(index + 1) in it.days }
                    if (selectedCourses.isEmpty()) EmptyState("No classes scheduled", "Your ${DayOfWeek.of(index + 1).getDisplayName(TextStyle.FULL, Locale.ENGLISH)} is free of scheduled classes.", Icons.Outlined.WbSunny)
                    selectedCourses.forEach { course ->
                        val date = data.today().with(java.time.temporal.TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY)).plusDays(index.toLong())
                        course.notice(date)?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                        val meetings = course.meetings.filter { it.day == DayOfWeek.of(index + 1) }
                        if (meetings.isEmpty()) CourseCard(course) { openCourse(course.id) } else meetings.forEach { meeting -> CourseCard(course.copy(time = meeting.time, room = meeting.room)) { openCourse(course.id) } }
                    }
                }
            }
        }
        item { Text("${courses.size} classes · ${data.student.section}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        item { SectionTitle("Enrolled subjects") }
        items(scheduleData.courses, key = { it.id }) { course ->
            ListItem(headlineContent = { Text(course.title) }, supportingContent = { Text("${course.code} · ${course.days.joinToString(" / ") { it.getDisplayName(TextStyle.SHORT, Locale.ENGLISH) }}") }, leadingContent = { IconTile(Icons.AutoMirrored.Outlined.MenuBook) }, colors = ListItemDefaults.colors(containerColor = MaterialTheme.colorScheme.background))
        }
    }
}

@Composable
fun CourseScreen(course: Course, data: StudentData, open: (String) -> Unit = {},permissions:Set<String>?=null) {
    val records = data.attendance.filter { it.courseId == course.id }
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(18.dp)) {
        item { PageTitle(course.title, "${course.code} · ${course.section}") }
        item { Panel {
            DetailLine(Icons.Outlined.PersonOutline, course.teacher)
            DetailLine(Icons.Outlined.LocationOn, course.room)
            DetailLine(Icons.Outlined.CalendarMonth, course.days.joinToString(" / ") { it.getDisplayName(TextStyle.SHORT, Locale.ENGLISH) })
            course.meetings.forEach { meeting -> DetailLine(Icons.Outlined.Schedule, "${meeting.day.getDisplayName(TextStyle.SHORT, Locale.ENGLISH)} / ${meeting.time} / ${meeting.room}") }
            if (course.meetings.isEmpty()) DetailLine(Icons.Outlined.Schedule, course.time)
            if (course.semester.isNotBlank()) DetailLine(Icons.Outlined.School, "${course.semester} / ${course.section}")
        } }
        item { Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if(permissions==null||"view_class_rosters" in permissions)OutlinedButton(onClick = { open("roster/${course.id}") }) { Text("Class roster") }
            if(permissions==null||"view_attendance" in permissions)OutlinedButton(onClick = { open("attendance-calendar/${course.id}") }) { Text("Calendar") }
        } }
        if(permissions==null||"view_people" in permissions)items(course.teachers) { teacher -> TextButton(onClick = { open("person/${teacher.id}") }) { Text("View ${teacher.name}") } }
        items(course.meetings) { meeting -> Panel { Text("${meeting.roomCode} ${meeting.room}", style = MaterialTheme.typography.titleMedium); Text("Building: ${meeting.building.ifEmpty { "Not assigned" }} / Floor: ${meeting.floor.ifEmpty { "Not assigned" }}"); Text("${meeting.day} / ${meeting.time}") } }
        items(course.timeOff) { off -> Panel { Text("Teaching time off", style = MaterialTheme.typography.titleMedium); Text("${off.teacher} / ${off.start} to ${off.end}"); Text(off.reason) } }
        if (course.daysOff.isNotEmpty()) item { Text("Department days off: ${course.daysOff.joinToString()}") }
        if(permissions==null||"view_grades" in permissions){item { SectionTitle("Your grades") };item { GradeCard(course, data.enabledTerms) }}
        item { SectionTitle("Attendance history") }
        if (records.isEmpty()) item { EmptyState("No attendance yet", "Your check-ins for this subject will appear here.") }
        items(records, key = { it.id }) { record -> Panel {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) { Text(record.date.displayDate(), Modifier.weight(1f), style = MaterialTheme.typography.titleSmall); StatusPill(record.status.name) }
            Text("Time in: ${record.timeIn ?: "Not recorded"}\nTime out: ${record.timeOut ?: "Not recorded"}\nSource: ${record.source.replace('_',' ')}", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        } }
    }
}

@Composable
fun GradesScreen(data: StudentData) {
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(18.dp)) {
        item { PageTitle("My grades", "A clearer view of your progress.") }
        item { Panel { DetailLine(Icons.Outlined.Info, "Grades and overall scores are supplied by your school. Passing grade: ${formatGrade(data.passingGrade)}. Unentered scores appear as a dash.") } }
        items(data.courses, key = { it.id }) { GradeCard(it, data.enabledTerms) }
    }
}

@Composable
private fun GradeCard(course: Course, enabledTerms: List<String>) {
    var expanded by rememberSaveable { mutableStateOf(false) }
    Panel {
        Text(course.code, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.primary)
        Text(course.title, style = MaterialTheme.typography.titleMedium)
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) { Text("Overall", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant); Text(course.overall?.toString() ?: "—", style = MaterialTheme.typography.headlineMedium) }
            StatusPill(course.gradeStatus.replaceFirstChar { it.uppercase() })
        }
        Column(Modifier.animateContentSize(animationSpec=tween(if(com.example.campustrack.data.LocalAppPreferences.current.value.reducedMotion)0 else 180))) {
            TextButton(onClick = { expanded = !expanded }, contentPadding = PaddingValues(0.dp)) { Text(if (expanded) "Hide grade breakdown" else "View grade breakdown"); Icon(if (expanded) Icons.Outlined.ExpandLess else Icons.Outlined.ExpandMore, null) }
            if (expanded) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("Prelim" to course.prelim, "Midterm" to course.midterm, "Final" to course.final).filter { it.first in enabledTerms }.forEach { (label, grade) ->
                    Column(Modifier.weight(1f).background(MaterialTheme.colorScheme.background, RoundedCornerShape(10.dp)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) { Text(label, style = MaterialTheme.typography.bodySmall); Text(grade?.toString() ?: "—", fontWeight = FontWeight.SemiBold) }
                }
            }
        }
    }
}

@Composable
fun ComplianceScreen(data: StudentData) {
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(18.dp)) {
        item { PageTitle("Compliance", "Stay on top of your attendance requirements.") }
        item { Panel {
            IconTile(Icons.Outlined.VerifiedUser)
            Text("Your attendance overview", style = MaterialTheme.typography.titleLarge)
            Text("Calculated by the school using attendance records and campus policies. Subject totals use their own class policies.", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
            HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
            ComplianceLine("Class absences", data.compliance["absences"]?.toString() ?: "—")
            ComplianceLine("Tardies", data.compliance["tardies"]?.toString() ?: "—")
            ComplianceLine("Tardy equivalent", data.compliance["tardy_equivalent"]?.toString() ?: "—")
            ComplianceLine("Missed events", data.compliance["missed_events"]?.toString() ?: "—")
            ComplianceLine("Missed recollections", data.compliance["missed_recollections"]?.toString() ?: "—")
            ComplianceLine("Total deficiencies", data.compliance["total_deficiencies"]?.toString() ?: "—")
        } }
        item { SectionTitle("By subject") }
        items(data.subjectCompliance.keys.toList(), key = { it }) { id ->
            val course = data.courses.find { it.id == id } ?: Course(id, "Subject $id", "", "", emptyList(), "", null, null, null)
            val summary = data.subjectCompliance[id].orEmpty()
            Panel {
                Text(course.title, style = MaterialTheme.typography.titleMedium)
                Text(course.code, color = MaterialTheme.colorScheme.primary, style = MaterialTheme.typography.labelMedium)
                ComplianceLine("Absences", summary["absences"]?.toString() ?: "—")
                ComplianceLine("Tardies", summary["tardies"]?.toString() ?: "—")
                ComplianceLine("Tardy equivalent", summary["tardy_equivalent"]?.toString() ?: "—")
                ComplianceLine("Class deficiencies", summary["class_deficiencies"]?.toString() ?: "—")
            }
        }
    }
}

@Composable
private fun ComplianceLine(label: String, value: String) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(label, Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(value, style = MaterialTheme.typography.labelLarge)
    }
}

internal fun formatGrade(value: Double?): String = value?.let { String.format(Locale.ENGLISH, "%.2f", it) } ?: "—"

@Composable
private fun WeeklyGrid(data: StudentData, openCourse: (String) -> Unit) {
    fun minutes(value: String): Int = runCatching {
        java.time.LocalTime.parse(value, java.time.format.DateTimeFormatter.ofPattern("h:mm a", Locale.ENGLISH)).let { it.hour * 60 + it.minute }
    }.getOrDefault(8 * 60)
    val rows = data.courses.flatMap { course -> course.meetings.map { course to it } }
    val first = minOf(8 * 60, rows.minOfOrNull { minutes(it.second.time.substringBefore(" \u2013 ")) } ?: 8 * 60) / 60 * 60
    val last = maxOf(17 * 60, rows.maxOfOrNull { minutes(it.second.time.substringAfter(" \u2013 ")) } ?: 17 * 60)
    val shortest=rows.minOfOrNull { maxOf(1,minutes(it.second.time.substringAfter(" \u2013 "))-minutes(it.second.time.substringBefore(" \u2013 "))) } ?: 60
    val scale = maxOf(1.1f,68f/shortest)
    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Column(Modifier.width(46.dp)) {
            Spacer(Modifier.height(36.dp))
            Box(Modifier.height(((last - first) * scale + 32).dp)) {
                for (time in first..last step 60) Text("${time / 60}:00", Modifier.offset(y = ((time - first) * scale).dp), style = MaterialTheme.typography.labelSmall)
            }
        }
        DayOfWeek.entries.forEach { day ->
            val dayRows=rows.filter{it.second.day==day}.sortedBy{minutes(it.second.time.substringBefore(" \u2013 "))}
            val laneEnds=mutableListOf<Int>()
            val placed=dayRows.map{pair->val start=minutes(pair.second.time.substringBefore(" \u2013 "));val end=minutes(pair.second.time.substringAfter(" \u2013 "));var lane=laneEnds.indexOfFirst{it<=start};if(lane<0){lane=laneEnds.size;laneEnds.add(end)}else laneEnds[lane]=end;Triple(pair.first,pair.second,lane)}
            Column(Modifier.width((145*maxOf(1,laneEnds.size)).dp)) {
                Text(day.getDisplayName(TextStyle.SHORT, Locale.ENGLISH), Modifier.height(36.dp), style = MaterialTheme.typography.titleMedium)
                Box(Modifier.fillMaxWidth().height(((last - first) * scale + 32).dp)) {
                    for (time in first..last step 60) HorizontalDivider(Modifier.offset(y = ((time - first) * scale).dp), color = MaterialTheme.colorScheme.outlineVariant)
                    placed.forEach { (course, meeting, lane) ->
                        val start = minutes(meeting.time.substringBefore(" \u2013 "))
                        val end = minutes(meeting.time.substringAfter(" \u2013 "))
                        Card(onClick = { openCourse(course.id) }, modifier = Modifier.width(141.dp).offset(x=(lane*145).dp,y = ((start - first) * scale).dp).height(maxOf(64f, (end - start) * scale - 4).dp), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer)) {
                            Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                Text(course.code, style = MaterialTheme.typography.titleSmall)
                                Text(meeting.time, style = MaterialTheme.typography.labelSmall)
                                Text("${meeting.roomCode.ifBlank{meeting.room}} · ${course.section}", style = MaterialTheme.typography.labelSmall)
                            }
                        }
                    }
                }
            }
        }
    }
}
