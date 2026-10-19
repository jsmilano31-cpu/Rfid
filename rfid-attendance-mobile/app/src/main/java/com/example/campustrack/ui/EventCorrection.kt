package com.example.campustrack.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import org.json.JSONArray
import org.json.JSONObject

@Composable internal fun EventCorrection(api:ApiClient,id:String,onExpired:()->Unit,close:()->Unit,saved:()->Unit){
    var person by remember{mutableStateOf<JSONObject?>(null)};var picker by remember{mutableStateOf(false)};var query by remember{mutableStateOf("")}
    NativeEditor("Correct event attendance",listOf(NativeField("status","Attendance status","Attended",listOf("Attended","Late","Absent","Excused","Incomplete").map{it to it}),NativeField("reason","Correction reason",type="multiline",required=true)),onExpired,close,extra={OutlinedButton(onClick={picker=true}){Text(person?.let{"${it.text("name")} · ${it.text("school_id")}"}?:"Select participant")}}){input->require(person!=null){"Select an event participant."};api.post("workspace/events.php",input.put("action","attendance").put("id",id.toLong()).put("user_id",person?.optLong("id")));saved()}
    if(picker)AlertDialog(onDismissRequest={picker=false},title={Text("Event participants")},text={Column{OutlinedTextField(query,{query=it},label={Text("Search name or school ID")},singleLine=true);RemotePanel(api,"workspace/events.php?mode=participants&id=$id&q=${encoded(query)}",onExpired){json->LazyColumn(Modifier.heightIn(max=320.dp)){items((json.optJSONArray("items")?:JSONArray()).objects()){row->TextButton(onClick={person=row;picker=false}){Column{Text(row.text("name"));Text(row.text("school_id"),style=MaterialTheme.typography.bodySmall)}}}}}}},confirmButton={TextButton(onClick={picker=false}){Text("Close")}})
}
