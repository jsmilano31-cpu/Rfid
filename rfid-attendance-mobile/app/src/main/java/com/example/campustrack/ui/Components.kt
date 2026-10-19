package com.example.campustrack.ui

import androidx.compose.animation.core.*
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowForward
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.*
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import com.example.campustrack.ui.theme.*

@Composable
fun PageTitle(title: String, subtitle: String) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(title, style = MaterialTheme.typography.headlineLarge)
        Text(subtitle, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
fun SectionTitle(title: String, action: String? = null, onClick: () -> Unit = {}) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(title, Modifier.weight(1f), style = MaterialTheme.typography.titleLarge)
        if (action != null) TextButton(onClick = onClick) { Text(action); Icon(Icons.AutoMirrored.Outlined.ArrowForward, null, Modifier.padding(start = 5.dp).size(16.dp)) }
    }
}

@Composable
fun Panel(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    val compact = LocalAppPreferences.current.value.compactMode
    Surface(modifier.fillMaxWidth(), shape = RoundedCornerShape(20.dp), color = MaterialTheme.colorScheme.surface, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)) {
        Column(Modifier.padding(if (compact) 14.dp else 20.dp), verticalArrangement = Arrangement.spacedBy(if (compact) 8.dp else 12.dp), content = content)
    }
}

@Composable
fun StatusPill(label: String) {
    val display=when(label.lowercase()){"unentered","ungraded"->"Not entered";"conflict"->"Needs review";"unknown"->"Delivery unknown";"accepted"->"Provider accepted";else->label.replace('_',' ').replaceFirstChar(Char::uppercase)}
    val color = when (label.lowercase()) {
        "present","attended","active","complete","read","delivered" -> MaterialTheme.colorScheme.secondary
        "late","pending","in progress","draft","queued","unknown","attendance threshold reached","enrollment needs review" -> latenessColor()
        "absent","dropped","failed","undelivered" -> MaterialTheme.colorScheme.error
        "unentered","ungraded","not entered","closed","cancelled","inactive" -> MaterialTheme.colorScheme.onSurfaceVariant
        else -> MaterialTheme.colorScheme.primary
    }
    Surface(color = color.copy(alpha = .10f), shape = RoundedCornerShape(5.dp)) {
        Text(display, Modifier.padding(horizontal = 7.dp, vertical = 3.dp), color = color, style = MaterialTheme.typography.labelMedium,maxLines=1,overflow=androidx.compose.ui.text.style.TextOverflow.Ellipsis)
    }
}

@Composable
fun IconTile(icon: ImageVector, modifier: Modifier = Modifier, color: Color = MaterialTheme.colorScheme.primary) {
    Box(modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = .09f)), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = color, modifier = Modifier.size(22.dp))
    }
}

@Composable
fun DetailLine(icon: ImageVector, text: String) {
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, Modifier.size(18.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(text, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
fun CourseCard(course: Course, onClick: () -> Unit) {
    Card(onClick = onClick, colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface), border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant), shape = RoundedCornerShape(18.dp)) {
        Row(Modifier.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            Box(Modifier.width(4.dp).height(82.dp).clip(CircleShape).background(MaterialTheme.colorScheme.primary))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                Text(course.time, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.primary)
                Text(course.title, style = MaterialTheme.typography.titleMedium)
                Text("${course.code} · ${course.room}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Text(course.teacher, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Icon(Icons.Outlined.ChevronRight, null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(20.dp))
        }
    }
}

@Composable
fun EmptyState(title: String, description: String, icon: ImageVector = Icons.Outlined.SearchOff) {
    Panel {
        Column(Modifier.fillMaxWidth().padding(vertical = 22.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
            IconTile(icon)
            Text(title, style = MaterialTheme.typography.titleMedium)
            Text(description, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        }
    }
}

@Composable
fun AttendanceRow(record: AttendanceRecord, course: Course, onClick: () -> Unit) {
    Card(onClick = onClick, colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface), border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(record.date.displayDate(), Modifier.weight(1f), style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                StatusPill(record.status.name)
            }
            Text(course.title, style = MaterialTheme.typography.titleMedium)
            Text("${course.code}  ·  ${record.timeIn ?: "No check-in"}  ·  ${if (record.timeIn == null) "Class record" else record.source}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
fun LoadingSkeleton() {
    val position=if(LocalAppPreferences.current.value.reducedMotion)0f else { val transition=rememberInfiniteTransition(label="Skeleton shimmer");val animated by transition.animateFloat(0f,1500f,infiniteRepeatable(tween(1400,easing=LinearEasing)),label="Shimmer position");animated }
    val base = MaterialTheme.colorScheme.surfaceVariant
    val brush = Brush.linearGradient(listOf(base, MaterialTheme.colorScheme.surface, base), start = Offset(position - 500f, 0f), end = Offset(position, 500f))
    LazyColumn(Modifier.fillMaxSize().semantics { contentDescription = "Loading portal"; liveRegion = LiveRegionMode.Polite }, contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(20.dp)) {
        item { Box(Modifier.fillMaxWidth(.55f).height(34.dp).clip(RoundedCornerShape(8.dp)).background(brush)) }
        item { Box(Modifier.fillMaxWidth(.8f).height(18.dp).clip(RoundedCornerShape(6.dp)).background(brush)) }
        item { Box(Modifier.fillMaxWidth().height(195.dp).clip(RoundedCornerShape(24.dp)).background(brush)) }
        item { Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) { repeat(3) { Box(Modifier.weight(1f).height(92.dp).clip(RoundedCornerShape(18.dp)).background(brush)) } } }
        items(3) { Box(Modifier.fillMaxWidth().height(110.dp).clip(RoundedCornerShape(18.dp)).background(brush)) }
    }
}

@Composable
internal fun PortalGreeting(name: String, today: java.time.LocalDate, subtitle: String) {
    Column(verticalArrangement = Arrangement.spacedBy(5.dp)) {
        Text(today.format(java.time.format.DateTimeFormatter.ofPattern("EEEE, MMMM d", java.util.Locale.ENGLISH)), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text("Hello, ${name.substringBefore(' ')}.", style = MaterialTheme.typography.headlineLarge)
        Text(subtitle, color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodyMedium)
    }
}

@Composable internal fun latenessColor()=if(MaterialTheme.colorScheme.background.red<.3f)Color(0xFFFFC078)else CampusAmber
