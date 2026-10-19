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

@OptIn(ExperimentalLayoutApi::class)
@Composable internal fun ComplianceManagement(api: ApiClient, onExpired: () -> Unit, workspace: String = "management") {
    var query by rememberSaveable { mutableStateOf("") }
    var section by rememberSaveable { mutableStateOf("0") }
    var program by rememberSaveable { mutableStateOf("0") }
    var tab by rememberSaveable { mutableStateOf("list") }
    var page by rememberSaveable(query, section, program, tab) { mutableIntStateOf(1) }
    var revision by remember { mutableIntStateOf(0) }
    var create by remember { mutableStateOf(false) }
    var refine by remember { mutableStateOf(false) }
    if (create) ViolationEditor(api, onExpired, { create = false }) { revision++ }
    RemotePanel(api, "management/compliance.php?mode=options&workspace=$workspace", onExpired) { options ->
        val parameters = "workspace=$workspace&q=${encoded(query)}&section_id=$section&program_id=$program"
        RemotePanel(api, "management/compliance.php?mode=$tab&$parameters&page=$page&revision=$revision", onExpired) { json ->
            LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(14.dp)) {
                item {
                    PageTitle("Compliance", if (workspace == "teacher") "Attendance deficiencies for your assigned students." else "Student deficiencies and recorded attendance summaries.")
                    if (options.optBoolean("campus_reports")) SearchSelect("View", tab, listOf("list" to "Student compliance", "classes" to "Class attendance", "events" to "Event attendance")) { tab = it }
                    OutlinedTextField(query, { query = it }, label = { Text(if (tab == "list") "Search name or school ID" else "Search report records") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                    if (tab == "list") TextButton(onClick = { refine = true }) { Text("Refine view${if (section != "0" || program != "0") " · Applied" else ""}") }
                    if (api.can("manage_compliance") && tab == "list") OutlinedButton(onClick = { create = true }) { Text("Record handbook violation") }
                }
                if ((json.optJSONArray("items")?.length() ?: 0) == 0) item { EmptyState("No matching records", "Try another search or clear your filters.") }
                items((json.optJSONArray("items") ?: JSONArray()).objects()) { row ->
                    Panel {
                        Text(row.text("student_name").ifBlank { row.text("course_code").ifBlank { row.text("title") } }, style = MaterialTheme.typography.titleMedium)
                        if (tab == "list") {
                            Text(listOf("school_id", "course_code", "section_name").map { row.text(it) }.filter { it.isNotBlank() }.joinToString(" · "))
                            Text(row.text("course_title"))
                            listOf("absences" to "Absences", "tardies" to "Tardies", "tardy_equivalent" to "Converted absences", "missed_events" to "Missed required events", "missed_recollections" to "Missed recollections", "total_deficiencies" to "Total deficiencies").forEach { (key, label) ->
                                if (row.has(key)) Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) { Text(label); Text(row.text(key)) }
                            }
                        } else {
                            Text(row.text("section_name").ifBlank { row.text("event_date") })
                            StatusPill(row.text("status")); Text("${row.text("total")} recorded observations")
                        }
                    }
                }
                item { PageControls(page, json.optInt("pages", 1), json.optInt("total")) { page = it } }
                if (tab == "list" && options.optBoolean("can_export")) item {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        PrintRecords(api, "management/compliance.php?mode=export&$parameters", onExpired)
                        DownloadRecords(api, "management/compliance.php?mode=export&$parameters", "campustrack-compliance.csv", onExpired)
                    }
                }
                item { Text("These compliance totals follow the web's current compliance policies. Attendance SMS eligibility and historical analytics use their own disclosed calculations.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            }
        }
        if (refine) {
            var draftProgram by remember { mutableStateOf(program) }
            val choices = options.options("sections").filter { choice -> draftProgram == "0" || (options.optJSONArray("sections") ?: JSONArray()).objects().any { it.text("id") == choice.first && it.text("program_id") == draftProgram } }
            NativeEditor("Refine compliance", buildList {
                if ((options.optJSONArray("programs")?.length() ?: 0) > 0) add(NativeField("program_id", "Program", program, listOf("0" to "All programs") + options.options("programs")))
                add(NativeField("section_id", "Section", section, listOf("0" to "All permitted sections") + choices))
            }, onExpired, { refine = false }, "Apply", onChange = { key, value -> if (key == "program_id") draftProgram = value }, extra = { TextButton(onClick = { section = "0"; program = "0"; refine = false }) { Text("Clear filters") } }) { input ->
                section = input.text("section_id").ifBlank { "0" }; program = input.text("program_id").ifBlank { "0" }
            }
        }
    }
}
