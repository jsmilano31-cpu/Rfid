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
import org.json.JSONArray
import org.json.JSONObject

@Composable
fun TeacherComposeScreen(api: ApiClient, onExpired: () -> Unit) {
    var type by rememberSaveable { mutableStateOf("user") }
    var target by rememberSaveable { mutableStateOf("") }
    var recipient by rememberSaveable { mutableStateOf("") }
    var query by rememberSaveable { mutableStateOf("") }
    var page by rememberSaveable(query) { mutableIntStateOf(1) }
    var subject by rememberSaveable { mutableStateOf("") }
    var body by rememberSaveable { mutableStateOf("") }
    var announcement by rememberSaveable { mutableStateOf(false) }
    var review by remember { mutableStateOf(false) }
    var sent by remember { mutableStateOf(false) }
    RemotePanel(api, "teacher/recipients.php", onExpired) { options ->
        LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(14.dp)) {
            item { PageTitle("Compose message", "Choose recipients, write your message and review before sending.") }
            if (sent) item { Text("Message sent successfully.", color = MaterialTheme.colorScheme.primary) }
            item { FilterChips(listOf("Individual", "Section", "Role", "All users"), mapOf("user" to "Individual", "section" to "Section", "role" to "Role", "all" to "All users").getValue(type)) { label -> type = mapOf("Individual" to "user", "Section" to "section", "Role" to "role", "All users" to "all").getValue(label); target = ""; recipient = if (type == "all") "All active users" else "" } }
            if (type in listOf("section", "role")) item {
                var expanded by remember { mutableStateOf(false) }
                Box { OutlinedButton(onClick = { expanded = true }) { Text(recipient.ifEmpty { "Select recipient group" }) }; DropdownMenu(expanded, { expanded = false }) { (options.optJSONArray(if (type == "section") "sections" else "roles") ?: JSONArray()).objects().forEach { row -> DropdownMenuItem(text = { Text(row.text("name")) }, onClick = { target = row.getString("id"); recipient = row.text("name"); expanded = false }) } } }
            }
            if (type == "user") {
                item { OutlinedTextField(query, { query = it }, label = { Text("Find a recipient by name, ID or email") }, modifier = Modifier.fillMaxWidth(), singleLine = true) }
                if (query.trim().length >= 2) item {
                    RemotePanel(api, "student/people.php?q=${encoded(query)}&page=$page", onExpired) { found ->
                        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            (found.optJSONArray("items") ?: JSONArray()).objects().forEach { person -> TextButton(onClick = { target = person.getString("id"); recipient = person.text("name"); query = "" }) { Text("${person.text("name")} · ${person.text("school_id")}") } }
                            PageControls(page, found.optInt("pages", 1), found.optInt("total")) { page = it }
                        }
                    }
                }
            }
            if (recipient.isNotEmpty()) item { Text("To: $recipient", style = MaterialTheme.typography.titleMedium) }
            item { OutlinedTextField(subject, { subject = it.take(180); sent = false }, label = { Text("Subject") }, modifier = Modifier.fillMaxWidth(), singleLine = true) }
            item { OutlinedTextField(body, { body = it.take(12000); sent = false }, label = { Text("Message") }, modifier = Modifier.fillMaxWidth(), minLines = 6) }
            item { Row { Checkbox(announcement, { announcement = it }); Text("Send as an announcement", Modifier.padding(top = 12.dp)) } }
            item { Button(onClick = { review = true }, enabled = recipient.isNotBlank() && subject.isNotBlank() && body.isNotBlank(), modifier = Modifier.fillMaxWidth()) { Text("Review and send") } }
        }
    }
    if (review) TeacherForm("Send message?", emptyList(), "To: $recipient\nSubject: $subject\n\n$body", "Send message", onExpired, { review = false }) {
        api.post("teacher/messages.php", JSONObject().put("action", "send").put("subject", subject).put("body", body).put("recipient_type", type).put("target_id", target.toLongOrNull() ?: 0).put("is_announcement", announcement))
        sent = true; subject = ""; body = ""
    }
}
