package com.example.campustrack.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import java.time.LocalDate

@Composable
fun CampusScreen(data: StudentData, openActivity: (String) -> Unit) {
    var kind by rememberSaveable { mutableStateOf("All") }
    var query by rememberSaveable { mutableStateOf("") }
    var status by rememberSaveable { mutableStateOf("All statuses") }
    var period by rememberSaveable { mutableStateOf("Upcoming") }
    var page by rememberSaveable(kind, query, status, period) { mutableIntStateOf(1) }
    val activities = data.activities.filter {
        (kind == "All" || it.kind == kind) && (status == "All statuses" || it.status.equals(status, true)) &&
            (period == "All dates" || if (period == "Upcoming") !(it.endDate ?: it.date).isBefore(data.today()) else (it.endDate ?: it.date).isBefore(data.today())) &&
            "${it.title} ${it.venue} ${it.date} ${it.endDate} ${it.description}".contains(query.trim(), true)
    }.let { list -> if (period == "Past") list.sortedByDescending { it.date } else list.sortedBy { it.date } }
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
        item { PageTitle("Campus life", "Be part of what happens next.") }
        item { FilterChips(listOf("Upcoming", "Past", "All dates"), period) { period = it } }
        item { OutlinedTextField(query, { query = it }, Modifier.fillMaxWidth(), placeholder = { Text("Find title, venue, section or date") }, leadingIcon = { Icon(Icons.Outlined.Search, null) }, singleLine = true, shape = RoundedCornerShape(16.dp)) }
        item { FilterChips(listOf("All", "Event", "Announcement", "Recollection", "Retreat"), kind) { kind = it } }
        item { FilterChips(listOf("All statuses", "Scheduled", "Open", "Closed", "Cancelled"), status) { status = it } }
        if (activities.isEmpty()) item { EmptyState("No activities found", "Try another filter or check back for new campus activities.", Icons.Outlined.EventAvailable) }
        items(activities.drop((page - 1) * 30).take(30), key = { it.id }) { activity -> ActivityCard(activity) { openActivity(activity.id) } }
        item { PageControls(page, maxOf(1, (activities.size + 29) / 30), activities.size) { page = it } }
    }
}

@Composable
fun ActivityScreen(activity: CampusActivity, today: LocalDate = LocalDate.now()) {
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(20.dp)) {
        item { StatusPill(activity.kind) }
        item { PageTitle(activity.title, if ((activity.endDate ?: activity.date).isBefore(today)) "Past campus activity" else "Something to look forward to.") }
        item { Panel {
            DetailLine(Icons.Outlined.CalendarMonth, activity.date.displayDate() + (activity.endDate?.let { " to ${it.displayDate()}" } ?: ""))
            DetailLine(Icons.Outlined.Schedule, activity.time)
            DetailLine(Icons.Outlined.LocationOn, activity.venue)
            DetailLine(Icons.Outlined.Groups, if (activity.required) "Attendance required" else "Optional attendance")
            StatusPill(activity.status.replaceFirstChar { it.uppercase() })
        } }
        items((listOfNotNull(activity.imageUrl) + activity.images).distinct()) { url ->
            coil.compose.AsyncImage(model = url, contentDescription = activity.title, modifier = Modifier.fillMaxWidth().heightIn(max = 320.dp), contentScale = androidx.compose.ui.layout.ContentScale.Fit)
        }
        item { SectionTitle("About this activity") }
        item { Text(activity.description, style = MaterialTheme.typography.bodyLarge) }
        item { Panel {
            Text("Your attendance", style = MaterialTheme.typography.titleMedium)
            StatusPill(activity.attendance)
            Text("Attendance is recorded with your physical school ID at the venue. Contact the school office if your record needs correcting.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        } }
    }
}

@Composable
fun MessagesScreen(messages: List<StudentMessage>, readIds: List<String>, openMessage: (String) -> Unit) {
    var filter by rememberSaveable { mutableStateOf("All messages") }
    var page by rememberSaveable(filter) { mutableIntStateOf(1) }
    val filtered = messages.filter { filter == "All messages" || (!it.read && it.id !in readIds) }
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
        item { PageTitle("Messages", "Your campus conversations and updates.") }
        item { FilterChips(listOf("All messages", "Unread"), filter) { filter = it } }
        if (filtered.isEmpty()) item { EmptyState("You're all caught up", "There are no unread messages in your inbox.", Icons.Outlined.MarkEmailRead) }
        items(filtered.drop((page - 1) * 30).take(30), key = { it.id }) { message ->
            Card(onClick = { openMessage(message.id) }, colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface), shape = RoundedCornerShape(18.dp)) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        Text(message.sender, Modifier.weight(1f), style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.primary)
                        StatusPill(if (message.read || message.id in readIds) "Read" else "Unread")
                    }
                    Text(message.subject, style = MaterialTheme.typography.titleMedium)
                    Text(message.body, maxLines = 2, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Text(message.date.displayDate(), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        }
        item { PageControls(page, maxOf(1, (filtered.size + 29) / 30), filtered.size) { page = it } }
    }
}

@Composable
fun MessageScreen(message: StudentMessage, read: Boolean = message.read, openSender: (String) -> Unit = {}) {
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(22.dp)) {
        if (message.senderId != null) item { TextButton(onClick = { openSender(message.senderId) }) { Text("View sender profile") } }
        item { PageTitle(message.subject, message.date.displayDate()) }
        item { Panel { DetailLine(Icons.Outlined.PersonOutline, message.sender); Text("To: you", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) } }
        item { Text(message.body, style = MaterialTheme.typography.bodyLarge) }
        item { StatusPill(if (read) "Read" else "Unread") }
    }
}
