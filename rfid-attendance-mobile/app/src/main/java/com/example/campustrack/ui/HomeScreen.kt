package com.example.campustrack.ui

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowForward
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.campustrack.data.*
import com.example.campustrack.ui.theme.*
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

@Composable
fun HomeScreen(data: StudentData, open: (String) -> Unit) {
    val today = data.today()
    val classes = data.courses.filter { today.dayOfWeek in it.days }
    val nextActivity = data.activities.filter { !(it.endDate ?: it.date).isBefore(today) && it.status !in listOf("cancelled", "closed") }.minByOrNull { it.date }
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(20.dp)) {
        item { PortalGreeting(data.student.name, today, "A little more connected to your campus.") }
        item { AttendanceHero(data) { open("attendance") } }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                listOf(AttendanceStatus.Present, AttendanceStatus.Late, AttendanceStatus.Absent).forEach { status ->
                    Surface(Modifier.weight(1f), shape = RoundedCornerShape(18.dp), color = MaterialTheme.colorScheme.surface) {
                        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                            val icon = when (status) { AttendanceStatus.Present -> Icons.Outlined.CheckCircle; AttendanceStatus.Late -> Icons.Outlined.Schedule; else -> Icons.Outlined.HighlightOff }
                            val color = when (status) { AttendanceStatus.Present -> MaterialTheme.colorScheme.secondary; AttendanceStatus.Late -> CampusAmber; else -> MaterialTheme.colorScheme.error }
                            Icon(icon, null, Modifier.size(20.dp), tint = color)
                            Text(data.count(status).toString().padStart(2, '0'), style = MaterialTheme.typography.headlineMedium)
                            Text(status.name, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                }
            }
        }
        item { OutlinedButton(onClick = { open("feed") }, modifier = Modifier.fillMaxWidth()) { Text("Campus feed and announcements") } }
        item { SectionTitle("Your day", "Schedule") { open("schedule") } }
        if (classes.isEmpty()) item { EmptyState("A little room to breathe", "You have no classes scheduled today. Take a look at your week ahead.", Icons.Outlined.WbSunny) }
        items(classes, key = { it.id }) { course ->
            val meetings = course.meetings.filter { it.day == today.dayOfWeek }
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                course.notice(today)?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                if (course.notice(today) != null) { Text(course.title, style = MaterialTheme.typography.titleSmall) }
                else if (meetings.isEmpty()) CourseCard(course) { open("course/${course.id}") }
                else meetings.forEach { meeting -> CourseCard(course.copy(time = meeting.time, room = meeting.room)) { open("course/${course.id}") } }
            }
        }
        item { OutlinedButton(onClick = { open("calendar") }, modifier = Modifier.fillMaxWidth()) { Text("Open campus calendar") } }
        item { Panel { Text("Compliance summary", style = MaterialTheme.typography.titleMedium); Text("${data.compliance["total_deficiencies"] ?: 0} total deficiencies"); TextButton(onClick = { open("compliance") }) { Text("View requirements") } } }
        item { SectionTitle("Grade overview") }
        items(data.courses.filter { it.overall != null }.sortedByDescending { it.gradeUpdatedAt }.take(5)) { course ->
            Panel { Text(course.title); Text("Overall: ${formatGrade(course.overall)} / ${course.gradeStatus}"); TextButton(onClick = { open("course/${course.id}") }) { Text("View grades") } }
        }
        item { SectionTitle("Quick access") }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                listOf(Triple("Grades", Icons.Outlined.School, "grades"), Triple("Compliance", Icons.Outlined.VerifiedUser, "compliance"), Triple("Messages", Icons.Outlined.MailOutline, "messages")).forEach { (label, icon, path) ->
                    Card(onClick = { open(path) }, modifier = Modifier.weight(1f), colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface)) {
                        Column(Modifier.fillMaxWidth().padding(vertical = 18.dp, horizontal = 4.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
                            IconTile(icon)
                            Text(label, style = MaterialTheme.typography.labelMedium)
                        }
                    }
                }
            }
        }
        if (nextActivity != null) {
            item { SectionTitle("Around campus", "Explore") { open("campus") } }
            item { ActivityCard(nextActivity) { open("activity/${nextActivity.id}") } }
        }
        item {
            Surface(color = MaterialTheme.colorScheme.primaryContainer.copy(alpha = .65f), shape = RoundedCornerShape(16.dp)) {
                Row(Modifier.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Icon(Icons.Outlined.Sensors, null, tint = MaterialTheme.colorScheme.primary)
                    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Your school ID. Your check-in.", style = MaterialTheme.typography.titleSmall)
                        Text("Tap your physical RFID card at the classroom reader to record attendance.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
    }
}

@Composable
private fun AttendanceHero(data: StudentData, onClick: () -> Unit) {
    var entered by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { entered = true }
    val progress by animateFloatAsState(if (entered) data.attendancePercent / 100f else 0f, tween(if(LocalAppPreferences.current.value.reducedMotion)0 else 950), label = "Attendance ring")
    Card(onClick = onClick, shape = RoundedCornerShape(24.dp)) {
        Box(Modifier.fillMaxWidth().background(Brush.linearGradient(listOf(CampusNavy, Color(0xFF194789))))) {
            Canvas(Modifier.matchParentSize()) {
                drawCircle(Color.White.copy(alpha = .04f), radius = size.width * .42f, center = Offset(size.width, 0f))
                drawCircle(Color.White.copy(alpha = .04f), radius = size.width * .32f, center = Offset(size.width, 0f))
            }
            Column(Modifier.padding(22.dp), verticalArrangement = Arrangement.spacedBy(20.dp)) {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text("YOUR ATTENDANCE", color = Color(0xFFBCD2F4), style = MaterialTheme.typography.labelSmall, letterSpacing = 1.sp)
                        Text("Every day\ncounts.", color = Color.White, style = MaterialTheme.typography.headlineMedium)
                        Text("${data.historyLabel}", color = Color(0xFFBCD2F4), style = MaterialTheme.typography.bodySmall)
                    }
                    Box(Modifier.size(108.dp).semantics { contentDescription = "Attendance rate ${data.attendancePercent} percent" }, contentAlignment = Alignment.Center) {
                        Canvas(Modifier.fillMaxSize().padding(5.dp)) {
                            drawArc(Color.White.copy(alpha = .13f), -90f, 360f, false, style = Stroke(8.dp.toPx(), cap = StrokeCap.Round))
                            drawArc(Color(0xFF8BDDC6), -90f, progress * 360, false, style = Stroke(8.dp.toPx(), cap = StrokeCap.Round))
                        }
                        Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            Text("${data.attendancePercent}%", color = Color.White, fontSize = 27.sp, fontWeight = FontWeight.Bold)
                            Text("attended", color = Color(0xFFBCD2F4), style = MaterialTheme.typography.labelSmall)
                        }
                    }
                }
                HorizontalDivider(color = Color.White.copy(alpha = .13f))
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("View attendance history", Modifier.weight(1f), color = Color.White, style = MaterialTheme.typography.labelLarge)
                    Icon(Icons.AutoMirrored.Outlined.ArrowForward, null, tint = Color.White, modifier = Modifier.size(19.dp))
                }
            }
        }
    }
}

@Composable
fun ActivityCard(activity: CampusActivity, onClick: () -> Unit) {
    Card(onClick = onClick, colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface), shape = RoundedCornerShape(20.dp)) {
        Box(Modifier.fillMaxWidth().height(142.dp).background(if (activity.kind == "Event") Color(0xFFE5ECFF) else Color(0xFFE5F2EB))) {
            Canvas(Modifier.matchParentSize()) {
                val tint = if (activity.kind == "Event") CampusBlue else CampusGreen
                drawCircle(tint.copy(alpha = .08f), size.width * .30f, Offset(size.width * .84f, size.height * .6f))
                drawCircle(tint.copy(alpha = .10f), size.width * .20f, Offset(size.width * .84f, size.height * .6f), style = Stroke(1.5.dp.toPx()))
                drawCircle(tint.copy(alpha = .15f), size.width * .13f, Offset(size.width * .84f, size.height * .6f), style = Stroke(1.5.dp.toPx()))
            }
            Column(Modifier.padding(20.dp).align(Alignment.CenterStart), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(if (activity.kind == "Event") "IDEAS MEET\nPOSSIBILITY." else "MAKE SPACE\nFOR YOURSELF.", color = CampusNavy, style = MaterialTheme.typography.titleLarge)
                Text(activity.kind.uppercase(), color = CampusBlue, style = MaterialTheme.typography.labelSmall, letterSpacing = 1.sp)
            }
            Icon(if (activity.kind == "Event") Icons.Outlined.AutoAwesome else Icons.Outlined.Spa, null, tint = if (activity.kind == "Event") CampusBlue else CampusGreen, modifier = Modifier.align(Alignment.CenterEnd).padding(end = 26.dp).size(38.dp))
            activity.imageUrl?.let { coil.compose.AsyncImage(model = it, contentDescription = activity.title, contentScale = androidx.compose.ui.layout.ContentScale.Crop, modifier = Modifier.matchParentSize()) }
        }
        Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
            Text(activity.date.displayDate().uppercase(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.primary)
            Text(activity.title, style = MaterialTheme.typography.titleMedium)
            DetailLine(Icons.Outlined.LocationOn, activity.venue)
            DetailLine(Icons.Outlined.Schedule, activity.time)
        }
    }
}
