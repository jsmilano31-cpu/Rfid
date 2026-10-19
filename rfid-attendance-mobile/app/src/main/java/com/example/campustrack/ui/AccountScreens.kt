package com.example.campustrack.ui

import android.util.Patterns
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.Student
import kotlinx.coroutines.launch
import kotlinx.coroutines.CancellationException
import coil.compose.AsyncImage
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale

@Composable
fun MoreScreen(student: Student, unread: Int, open: (String) -> Unit, onTeacher: (() -> Unit)? = null, onSignOut: () -> Unit = {}) {
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(18.dp)) {
        if (onTeacher != null) item { Button(onClick = onTeacher, modifier = Modifier.fillMaxWidth()) { Text("Open teacher workspace") } }
        item { PageTitle("Your workspace", "Everything else you need for campus life.") }
        item { WorkspaceProfileCard(student) { open("profile") } }
        item { MenuCard("Campus calendar", "Month view, agenda and class suspensions", Icons.Outlined.CalendarMonth) { open("calendar") } }
        item { MenuCard("Campus activities", "Events, recollections and retreats", Icons.Outlined.Explore) { open("campus") } }
        item { MenuCard("People", "Campus directory and shared classes", Icons.Outlined.People) { open("people") } }
        item { MenuCard("Sent messages", "Your previously sent messages", Icons.Outlined.Outbox) { open("sent") } }
        item { MenuCard("My grades", "Your academic progress by subject", Icons.Outlined.School) { open("grades") } }
        item { MenuCard("Compliance", "Attendance requirements and deficiencies", Icons.Outlined.VerifiedUser) { open("compliance") } }
        item { MenuCard("Messages", "$unread unread campus updates", Icons.Outlined.MailOutline) { open("messages") } }
        item { MenuCard("My profile", "School details and RFID registration", Icons.Outlined.Badge) { open("profile") } }
        item { MenuCard("Settings", "Notifications, appearance, privacy and app behavior", Icons.Outlined.Settings) { open("settings") } }
        item { Panel {
            Text("A connected campus, in your pocket.", style = MaterialTheme.typography.titleMedium)
            Text("CampusTrack · Student portal", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.primary)
            Text("Your records are connected to your school account. Contact the school office if academic or RFID details need correcting.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        } }
        item { OutlinedButton(onClick = onSignOut, modifier = Modifier.fillMaxWidth()) { Text("Sign out") } }
    }
}

@Composable
internal fun MenuCard(title: String, subtitle: String, icon: ImageVector, onClick: () -> Unit) {
    Card(onClick = onClick, colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface), shape = RoundedCornerShape(18.dp)) {
        Row(Modifier.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(14.dp), verticalAlignment = Alignment.CenterVertically) {
            IconTile(icon)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) { Text(title, style = MaterialTheme.typography.titleMedium); Text(subtitle, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            Icon(Icons.Outlined.ChevronRight, null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
fun ProfileScreen(student: Student, onPhoto: suspend (ByteArray, String) -> Unit = { _, _ -> }, onSave: suspend (String) -> Unit, onVisibility: suspend (Boolean) -> Unit = {}, onPassword: suspend (String, String, String) -> Unit) {
    val email = student.email
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var passwordDialog by remember { mutableStateOf(false) }
    var currentPassword by remember { mutableStateOf("") }
    var newPassword by remember { mutableStateOf("") }
    var confirmation by remember { mutableStateOf("") }
    val context = androidx.compose.ui.platform.LocalContext.current
    var photoBusy by remember { mutableStateOf(false) }
    var photoError by remember { mutableStateOf<String?>(null) }
    val photoPicker = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) scope.launch {
            photoBusy = true; photoError = null
            try {
                val mime = context.contentResolver.getType(uri).orEmpty()
                require(mime in listOf("image/jpeg", "image/png", "image/webp")) { "Choose a JPG, PNG or WebP image." }
                val bytes = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) { context.contentResolver.openInputStream(uri)?.use { it.readBytesAtMost(2_097_153) } ?: error("Could not read this image.") }
                require(bytes.size in 1..2_097_152) { "Choose an image up to 2 MB." }
                onPhoto(bytes, mime)
            } catch (cancelled: CancellationException) { throw cancelled } catch (failure: Exception) { photoError = failure.message ?: "Photo upload failed." } finally { photoBusy = false }
        }
    }
    var passwordSaved by remember { mutableStateOf(false) }
    var editing by rememberSaveable { mutableStateOf(false) }
    var draft by rememberSaveable(email) { mutableStateOf(email) }
    var saved by rememberSaveable { mutableStateOf(false) }
    var showValidation by rememberSaveable { mutableStateOf(false) }
    var profilePublic by rememberSaveable(student.isProfilePublic) { mutableStateOf(student.isProfilePublic) }
    var visibilityBusy by remember { mutableStateOf(false) }
    var visibilityError by remember { mutableStateOf<String?>(null) }
    val valid = Patterns.EMAIL_ADDRESS.matcher(draft.trim()).matches()
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(20.dp)) {
        item { PageTitle("My profile", "Your place in the campus community.") }
        item { Panel {
            Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
                StudentAvatar(student, 80)
                TextButton(enabled = !photoBusy, onClick = { photoPicker.launch("image/*") }) { Text(if (photoBusy) "Uploading..." else "Change photo") }
                if (photoError != null) Text(photoError!!, color = MaterialTheme.colorScheme.error)
                Text(student.name, style = MaterialTheme.typography.titleLarge)
                Text(student.schoolId, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Text(student.roles.joinToString(" · "), style = MaterialTheme.typography.labelLarge)
            }
        } }
        item { Panel {
            Text("Academic details", style = MaterialTheme.typography.titleMedium)
            ProfileField("Program", student.program)
            ProfileField("Section", student.section)
            ProfileField("Department", student.department)
        } }
        item { Panel {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) { Text("Contact email", Modifier.weight(1f), style = MaterialTheme.typography.titleMedium); TextButton(onClick = { draft = email; editing = true; showValidation = false; saved = false }) { Text("Edit") } }
            Text(email, style = MaterialTheme.typography.bodyMedium)
            if (saved) Text("Email updated.", color = MaterialTheme.colorScheme.secondary, style = MaterialTheme.typography.bodySmall)
        } }
        item { Panel {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                    Text("Public profile", style = MaterialTheme.typography.titleMedium)
                    Text(if (profilePublic) "Campus users can open your profile." else "Other users see only that your profile is private.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                Switch(checked = profilePublic, enabled = !visibilityBusy, onCheckedChange = { requested ->
                    visibilityBusy = true; visibilityError = null
                    scope.launch {
                        try { onVisibility(requested); profilePublic = requested }
                        catch (cancelled: CancellationException) { throw cancelled }
                        catch (failure: Exception) { visibilityError = failure.message ?: "Could not update profile visibility." }
                        finally { visibilityBusy = false }
                    }
                })
            }
            if (visibilityError != null) Text(visibilityError!!, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            Text("This does not remove you from class rosters, attendance, grades, messaging, or other school functions.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        } }
        item { Panel {
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) { IconTile(Icons.Outlined.Sensors); Text("RFID registration", style = MaterialTheme.typography.titleMedium) }
            StatusPill(student.rfidStatus ?: "Not registered")
            if (student.rfidUid != null) ProfileField("Card UID", student.rfidUid)
            Text("Use your physical school ID at campus readers. Contact the school office to register or replace a card.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        } }
        item { OutlinedButton(onClick = { error = null; passwordDialog = true }, modifier = Modifier.fillMaxWidth()) { Text("Change password") } }
        if (passwordSaved) item { Text("Password updated successfully.", color = MaterialTheme.colorScheme.secondary) }
    }
    if (editing) AlertDialog(
        onDismissRequest = { if (!busy) editing = false }, title = { Text("Edit contact email") },
        text = { Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("Update the contact email on your school account.")
            OutlinedTextField(draft, { draft = it; showValidation = false }, label = { Text("Email address") }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), isError = showValidation && !valid, supportingText = { if (showValidation && !valid) Text("Enter a valid email address.") })
            if (error != null) Text(error!!, color = MaterialTheme.colorScheme.error)
        } },
        confirmButton = { TextButton(enabled = !busy, onClick = { if (valid) { busy = true; error = null; scope.launch { try { onSave(draft.trim()); saved = true; editing = false } catch (cancelled: CancellationException) { throw cancelled } catch (failure: Exception) { error = failure.message } finally { busy = false } } } else showValidation = true }) { Text(if (busy) "Saving…" else "Save") } },
        dismissButton = { TextButton(enabled = !busy, onClick = { editing = false }) { Text("Cancel") } }
    )
    if (passwordDialog) AlertDialog(onDismissRequest = { if (!busy) { passwordDialog = false; currentPassword = ""; newPassword = ""; confirmation = "" } }, title = { Text("Change password") }, text = {
        Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
            PasswordField("Current password", currentPassword, { currentPassword = it }, !busy)
            PasswordField("New password", newPassword, { newPassword = it }, !busy)
            PasswordField("Confirm new password", confirmation, { confirmation = it }, !busy)
            if (error != null) Text(error!!, color = MaterialTheme.colorScheme.error)
        }
    }, confirmButton = { TextButton(enabled = !busy, onClick = {
        if (newPassword.toByteArray().size !in 8..72 || newPassword != confirmation) { error = "Use an 8–72 byte password and match the confirmation." } else {
            busy = true; error = null
            scope.launch { try { onPassword(currentPassword, newPassword, confirmation); currentPassword = ""; newPassword = ""; confirmation = ""; passwordDialog = false; passwordSaved = true } catch (cancelled: CancellationException) { throw cancelled } catch (failure: Exception) { error = failure.message } finally { busy = false } }
        }
    }) { Text(if (busy) "Updating…" else "Update password") } }, dismissButton = { TextButton(enabled = !busy, onClick = { passwordDialog = false; currentPassword = ""; newPassword = ""; confirmation = "" }) { Text("Cancel") } })
}

@Composable
private fun StudentAvatar(student: Student, size: Int) {
    Surface(Modifier.size(size.dp), shape = CircleShape, color = MaterialTheme.colorScheme.primaryContainer) {
        Box(contentAlignment = Alignment.Center) {
            Text(student.initials, style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary)
            if (student.photoUrl != null && !student.photoUrl.endsWith(".svg")) AsyncImage(student.photoUrl, "Profile picture", Modifier.fillMaxSize().clip(CircleShape), contentScale = ContentScale.Crop)
        }
    }
}

@Composable
private fun ProfileField(label: String, value: String) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) { Text(label, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant); Text(value, style = MaterialTheme.typography.bodyMedium) }
}

private fun java.io.InputStream.readBytesAtMost(limit: Int): ByteArray {
    val output = java.io.ByteArrayOutputStream()
    val buffer = ByteArray(8192)
    while (output.size() < limit) {
        val count = read(buffer, 0, minOf(buffer.size, limit - output.size()))
        if (count < 0) break
        output.write(buffer, 0, count)
    }
    return output.toByteArray()
}

@Composable
internal fun WorkspaceProfileCard(student: Student, onClick: () -> Unit) {
            Card(onClick = onClick, colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.primaryContainer), shape = RoundedCornerShape(20.dp)) {
                Row(Modifier.fillMaxWidth().padding(20.dp), horizontalArrangement = Arrangement.spacedBy(14.dp), verticalAlignment = Alignment.CenterVertically) {
                    StudentAvatar(student, 52)
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) { Text(student.name, style = MaterialTheme.typography.titleMedium); Text("${student.schoolId} · ${student.section}", style = MaterialTheme.typography.bodySmall) }
                    Icon(Icons.Outlined.ChevronRight, null)
                }
            }
}
