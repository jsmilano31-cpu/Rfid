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

@Composable fun PreparedSeatSubjects(api: ApiClient, onExpired: () -> Unit, workspace: String = "teacher", open: (String) -> Unit) {
    var query by rememberSaveable { mutableStateOf("") }
    var page by rememberSaveable(query) { mutableIntStateOf(1) }
    RemotePanel(api, "workspace/seat-subjects.php?workspace=$workspace&q=${encoded(query)}&page=$page", onExpired) { json ->
        val rows = (json.optJSONArray("items") ?: JSONArray()).objects()
        LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(14.dp)) {
            item {
                PageTitle("Seat plans", "Assign enrolled students to prepared classroom layouts.")
                OutlinedTextField(query, { query = it }, label = { Text("Search subject or section") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
            }
            if (rows.isEmpty()) item { EmptyState("No matching subjects", "Try another search or check your class assignments.") }
            items(rows, key = { it.text("id") }) { row ->
                Panel {
                    Text("${row.text("course_code")} \u00b7 ${row.text("section_name")}", style = MaterialTheme.typography.titleMedium)
                    Text(row.text("course_title"))
                    Text("Room: ${row.text("room_code")} \u00b7 ${row.text("room_name")}")
                    Text("Layout: ${row.text("seat_plan_name").ifBlank { "Not configured" }}")
                    (row.optJSONArray("schedules") ?: JSONArray()).let { times ->
                        (0 until times.length()).forEach { Text(times.getString(it), style = MaterialTheme.typography.bodySmall) }
                    }
                    if (row.isNull("seat_plan_id")) Text("Ask a layout manager to prepare this room's seat plan.", style = MaterialTheme.typography.bodySmall)
                    Button(onClick = { open(row.text("id")) }) { Text("Open seat plan") }
                }
            }
            item { PageControls(page, json.optInt("pages", 1), json.optInt("total")) { page = it } }
        }
    }
}
