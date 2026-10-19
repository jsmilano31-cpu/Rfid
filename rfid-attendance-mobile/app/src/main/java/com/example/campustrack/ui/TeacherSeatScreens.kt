package com.example.campustrack.ui

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.repeatOnLifecycle
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import org.json.JSONArray
import org.json.JSONObject

@Composable
fun TeacherSeatPlans(api: ApiClient, onExpired: () -> Unit, open:((String)->Unit)?=null) {
    var actorId by rememberSaveable { mutableLongStateOf(0) }
    var manageCampus by rememberSaveable { mutableStateOf(false) }
    var room by rememberSaveable { mutableStateOf("") }
    var revision by remember { mutableIntStateOf(0) }
    var selectedForDeletion by remember{mutableStateOf(setOf<Long>())}
    var selectedPreset by rememberSaveable { mutableStateOf("") }
    if (room.isEmpty() && selectedPreset.isEmpty()) RemotePanel(api, "teacher/seats.php?mode=bootstrap&revision=$revision", onExpired) { json ->
        SideEffect { actorId = json.optLong("actor_id"); manageCampus = json.optBoolean("can_create_campus_presets") }
        LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(16.dp)) {
            item { PageTitle("Seat plans", "Room layouts and personal or campus presets.") }
            item { Button(onClick={selectedPreset="new"}){Text("Create layout template")} }
            item { SectionTitle("Assigned rooms") }
            items((json.optJSONArray("rooms") ?: JSONArray()).objects()) { item -> TeacherMenu(item.text("room_code"), item.text("room_name")) { room = item.getString("id") } }
            item { SectionTitle("Available presets");BulkDeleteControl(api,"preset",selectedForDeletion,{selectedForDeletion=it},onExpired,open){revision++} }
            items((json.optJSONArray("presets") ?: JSONArray()).objects()) { preset ->
                Row { if(preset.optLong("created_by")==actorId||(manageCampus&&preset.text("visibility")=="campus"))Checkbox(preset.optLong("id") in selectedForDeletion,{yes->selectedForDeletion=if(yes&&selectedForDeletion.size<100)selectedForDeletion+preset.optLong("id")else selectedForDeletion-preset.optLong("id")});Box(Modifier.weight(1f)){TeacherMenu(preset.text("name"), "${preset.text("visibility")} \u00b7 ${preset.text("creator_name")}") { selectedPreset=preset.text("id") }}}
            }
        }
    } else {
        val path = if (room.isNotEmpty()) "teacher/seats.php?mode=room&room_id=$room" else if(selectedPreset=="new")"teacher/seats.php?mode=blank"else "teacher/seats.php?mode=preset&preset_id=$selectedPreset"
        RemotePanel(api, "$path&revision=$revision", onExpired) { json ->
            val plan = json.optJSONObject("plan") ?: json.getJSONObject("preset")
            val canEditPreset = plan.optLong("created_by") == actorId || (manageCampus && plan.text("visibility") == "campus")
            var rows by remember(json) { mutableStateOf(plan.optInt("row_count", 10).toString()) }
            var columns by remember(json) { mutableStateOf(plan.optInt("column_count", 10).toString()) }
            var name by remember(json) { mutableStateOf(plan.text("name")) }
            var cells by remember(json) { mutableStateOf((json.optJSONArray("cells") ?: JSONArray()).objects().associateBy { "${it.optInt("grid_row")}:${it.optInt("grid_column")}" }) }
            var tool by remember { mutableStateOf("student_seat") }
            var label by remember { mutableStateOf("") }
            var save by remember { mutableStateOf("") }
            var presetName by remember { mutableStateOf("") }
            var visibility by remember(json){mutableStateOf(plan.text("visibility").ifBlank{"personal"})}
            LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(14.dp)) {
                item { TextButton(onClick = { room = ""; selectedPreset = "" }) { Text("All room layouts and presets") } }
                item { PageTitle(name, "Choose a tool, then tap cells. Room layouts are shared with other classes using that room.") }
                item { OutlinedTextField(name, { name = it.take(120) }, label = { Text("Layout name") }, modifier = Modifier.fillMaxWidth()) }
                if(manageCampus && room.isBlank())item{SearchSelect("Template visibility",visibility,listOf("personal" to "Personal","campus" to "Campus")){visibility=it}}
                item { Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) { OutlinedTextField(rows, { rows = it.take(2) }, label = { Text("Rows (1–20)") }, modifier = Modifier.weight(1f)); OutlinedTextField(columns, { columns = it.take(2) }, label = { Text("Columns (1–20)") }, modifier = Modifier.weight(1f)) } }
                item { FilterChips(listOf("Seat", "Teacher table", "Label", "Erase"), mapOf("student_seat" to "Seat", "teacher_table" to "Teacher table", "label" to "Label", "erase" to "Erase").getValue(tool)) { tool = mapOf("Seat" to "student_seat", "Teacher table" to "teacher_table", "Label" to "label", "Erase" to "erase").getValue(it) } }
                item { OutlinedTextField(label, { label = it.take(80) }, label = { Text("Cell label (optional)") }, modifier = Modifier.fillMaxWidth()) }
                item { Column(Modifier.horizontalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    repeat((rows.toIntOrNull() ?: 1).coerceIn(1,20)) { r -> Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        repeat((columns.toIntOrNull() ?: 1).coerceIn(1,20)) { c ->
                            val key = "${r+1}:${c+1}"; val cell = cells[key]
                            OutlinedButton(onClick = { cells = cells.toMutableMap().apply { if (tool == "erase") remove(key) else put(key, JSONObject().put("grid_row",r+1).put("grid_column",c+1).put("cell_type",tool).put("label",label.ifBlank { if (tool == "teacher_table") "Teacher" else if (tool == "label") "Label" else "${r+1}-${c+1}" })) } }, modifier = Modifier.width(86.dp).height(56.dp), contentPadding = PaddingValues(3.dp)) { Text(cell?.text("label")?.ifEmpty { cell.text("cell_type") } ?: "+", style = MaterialTheme.typography.labelSmall) }
                        }
                    } }
                } }
                if (room.isNotEmpty()) item { Button(onClick = { save = "save_room" }, modifier = Modifier.fillMaxWidth()) { Text("Save room layout") } }
                else if (canEditPreset) item { Button(onClick = { save = if(selectedPreset=="new")"save_preset"else"update_preset";if(selectedPreset=="new")presetName=name }, modifier = Modifier.fillMaxWidth()) { Text(if(selectedPreset=="new")"Create template"else"Update preset") } }
                item { OutlinedTextField(presetName, { presetName = it.take(120) }, label = { Text("New personal preset name") }, modifier = Modifier.fillMaxWidth()); TextButton(onClick = { save = "save_preset" }, enabled = presetName.isNotBlank()) { Text("Save a personal copy") } }
                if (room.isEmpty() && canEditPreset && selectedPreset!="new") item { TextButton(onClick = { save = "delete_preset" }) { Text("Delete this preset") } }
                if (room.isNotEmpty()) item {
                    Box(Modifier.fillMaxWidth().heightIn(max = 320.dp)) { RemotePanel(api, "teacher/seats.php?mode=bootstrap", onExpired) { presets ->
                        var load by remember { mutableStateOf<JSONObject?>(null) }
                        Column { Text("Apply a preset", style = MaterialTheme.typography.titleMedium); (presets.optJSONArray("presets") ?: JSONArray()).objects().forEach { preset -> TextButton(onClick = { load = preset }) { Text(preset.text("name")) } } }
                        load?.let { preset -> TeacherForm("Apply ${preset.text("name")}?", emptyList(), "This replaces your unsaved grid. Review it before saving the room layout.", "Apply", onExpired, { load = null }) {
                            val loaded = api.get("teacher/seats.php?mode=preset&preset_id=${preset.getString("id")}")
                            rows = loaded.getJSONObject("preset").text("row_count"); columns = loaded.getJSONObject("preset").text("column_count")
                            cells = loaded.getJSONArray("cells").objects().associateBy { "${it.optInt("grid_row")}:${it.optInt("grid_column")}" }
                        } }
                    }
                }
                }
            }
            if (save.isNotEmpty()) TeacherForm(if (save == "delete_preset") "Delete preset?" else "Save layout?", emptyList(), if (save == "save_room") "This changes the shared room layout. Review the grid and any existing class seat assignments before saving." else "The server verifies your permission to modify this layout or preset.", "Confirm", onExpired, { save = "" }) {
                val output = JSONArray(cells.values.map { cell -> JSONObject().put("row",cell.optInt("grid_row")).put("column",cell.optInt("grid_column")).put("type",cell.text("cell_type")).put("label",cell.text("label")) })
                val result=api.post("teacher/seats.php", JSONObject().put("action",save).put("room_id",room.toLongOrNull() ?: 0).put("preset_id",selectedPreset.toLongOrNull() ?: 0).put("visibility",visibility).put("name",if (save == "save_preset") presetName else name).put("row_count",rows.toIntOrNull() ?: 0).put("column_count",columns.toIntOrNull() ?: 0).put("cells",output))
                if(selectedPreset=="new")selectedPreset=result.optJSONObject("preset")?.text("id").orEmpty()
                if (save == "delete_preset") selectedPreset = ""
                revision++
            }
        }
    }
}

@Composable
fun TeacherLiveSeats(api: ApiClient, offering: String, initialDate: String, onExpired: () -> Unit) {
    var date by rememberSaveable { mutableStateOf(initialDate) }; var json by remember { mutableStateOf<JSONObject?>(null) }
    var error by remember { mutableStateOf<String?>(null) }; var revision by remember { mutableIntStateOf(0) }
    var selected by remember { mutableStateOf<JSONObject?>(null) }
    var meeting by rememberSaveable(offering) { mutableStateOf("") }
    val lifecycleOwner = LocalLifecycleOwner.current
    LaunchedEffect(date, revision, meeting, lifecycleOwner) {
        lifecycleOwner.lifecycle.repeatOnLifecycle(Lifecycle.State.STARTED) {
        while (true) {
            try { json = api.get("teacher/attendance.php?mode=live&offering_id=$offering&date=${encoded(date)}"+if(meeting.isBlank())""else"&schedule_id=$meeting"); error = null }
            catch (cancelled: CancellationException) { throw cancelled }
            catch (failure: Exception) { if (failure is ApiException && failure.status == 401) { onExpired(); return@repeatOnLifecycle }; error = failure.message }
            delay(10_000)
        }
        }
    }
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(14.dp)) {
        item { PageTitle("Live classroom seats", "Attendance status refreshes every 10 seconds while this screen is open.") }
        item { DateInput("Attendance date",date){date=it;json=null} }
        if (error != null) item { Text(error!!, color = MaterialTheme.colorScheme.error) }
        if (json == null) item { LinearProgressIndicator(Modifier.fillMaxWidth()) }
        json?.let { payload ->
            item { SearchSelect("Class meeting",meeting.ifBlank{payload.optJSONObject("class")?.text("schedule_id").orEmpty()},payload.options("meetings")){meeting=it} }
            val plan = payload.optJSONObject("plan")
            item { Text(payload.text("message").ifEmpty { payload.text("reason") }) }
            if (plan == null) item { EmptyState("Seat plan not configured", "Ask a layout manager to prepare a seat plan for this class meeting's room.") }
            else {
                val cells = (plan.optJSONArray("cells") ?: JSONArray()).objects()
                item { Text("${plan.optInt("checked_in_count")} checked in · ${plan.optInt("assigned_count")} assigned") }
                item { Column(Modifier.horizontalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                    repeat(plan.optInt("row_count")) { r -> Row(horizontalArrangement = Arrangement.spacedBy(5.dp)) {
                        repeat(plan.optInt("column_count")) { c ->
                            val cell = cells.find { it.optInt("grid_row") == r+1 && it.optInt("grid_column") == c+1 }
                            if (cell == null) Spacer(Modifier.width(100.dp).height(78.dp))
                            else Card(onClick = { if (cell.text("cell_type") == "student_seat") selected = cell }, modifier = Modifier.width(100.dp).height(78.dp), colors = CardDefaults.cardColors(containerColor = if (cell.optBoolean("checked_in")) MaterialTheme.colorScheme.secondaryContainer else MaterialTheme.colorScheme.surfaceVariant)) {
                                Column(Modifier.padding(7.dp)) { Text(cell.text("label"), style = MaterialTheme.typography.labelSmall); Text(cell.text("student_name").ifEmpty { "Unassigned" }, style = MaterialTheme.typography.labelSmall); Text(cell.text("attendance_status"), style = MaterialTheme.typography.labelSmall) }
                            }
                        }
                    } }
                } }
            }
        }
    }
    selected?.let { cell ->
        val planId = json?.optJSONObject("plan")?.optLong("id") ?: 0
        var query by remember { mutableStateOf("") }; var target by remember { mutableStateOf<JSONObject?>(null) }; var remove by remember { mutableStateOf(false) }
        if (target == null && !remove) AlertDialog(onDismissRequest = { selected = null }, title = { Text("Assign ${cell.text("label")}") }, text = {
            Column(Modifier.heightIn(max = 420.dp)) { OutlinedTextField(query, { query = it }, label = { Text("Find enrolled student") }); RemotePanel(api, "teacher/attendance.php?mode=seat_students&offering_id=$offering&plan_id=$planId&q=${encoded(query)}", onExpired) { result -> LazyColumn { items((result.optJSONArray("students") ?: JSONArray()).objects()) { student -> TextButton(onClick = { target = student }) { Text(student.text("name").ifEmpty { student.text("student_name") }) } } } } }
        }, confirmButton = { TextButton(onClick = { selected = null }) { Text("Close") } }, dismissButton = { if (!cell.isNull("student_id")) TextButton(onClick = { remove = true }) { Text("Remove assignment") } })
        if (target != null || remove) TeacherForm(if (remove) "Remove assignment?" else "Assign student?", emptyList(), "This changes the seat assignment for this class.", "Confirm", onExpired, { selected = null }) {
            api.post("teacher/attendance.php", JSONObject().put("action",if (remove) "unassign" else "assign").put("class_offering_id",offering.toLong()).put("seat_plan_id",planId).put("grid_row",cell.getInt("grid_row")).put("grid_column",cell.getInt("grid_column")).put("student_id",target?.optLong("id",target?.optLong("student_id") ?: 0) ?: 0)); revision++
        }
    }
}
