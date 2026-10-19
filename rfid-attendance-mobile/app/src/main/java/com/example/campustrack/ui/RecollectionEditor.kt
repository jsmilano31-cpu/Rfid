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

@Composable internal fun RecollectionEditor(api:ApiClient,selected:JSONObject,onExpired:()->Unit,close:()->Unit,saved:()->Unit){
    RemotePanel(api,"workspace/recollections.php?mode=options&id=${selected.optLong("id")}",onExpired){json->
        val row=json.optJSONObject("record")?:selected
        var roles by remember(row){mutableStateOf((row.optJSONArray("target_role_ids")?:JSONArray()).let{a->(0 until a.length()).map{a.getLong(it)}.toSet()})}
        var people by remember(row){mutableStateOf((row.optJSONArray("target_users")?:JSONArray()).objects())}
        var targets by remember{mutableStateOf(false)};var picker by remember{mutableStateOf(false)}
        NativeEditor(if(row.optLong("id")>0)"Edit activity"else"Create activity",listOf(
            NativeField("title","Title",row.text("title"),required=true),NativeField("type","Activity type",row.text("type").ifBlank{"Recollection"},listOf("Recollection" to "Recollection","Retreat" to "Retreat")),NativeField("section_id","Section",row.text("section_id"),json.options("sections"),required=true),NativeField("venue","Venue",row.text("venue"),required=true),
            NativeField("date","Date start",row.text("date").ifBlank{json.text("today")},type="date",required=true),NativeField("end_date","Date end",row.text("end_date").ifBlank{row.text("date").ifBlank{json.text("today")}},type="date",required=true),NativeField("start_time","Time start",row.text("start_time"),type="time",required=true),NativeField("end_time","Time end",row.text("end_time"),type="time",required=true),NativeField("facilitator","Facilitator",row.text("facilitator")),NativeField("attendance_required","Attendance",row.text("attendance_required").ifBlank{"1"},listOf("1" to "Required","0" to "Optional"))
        ),onExpired,close,extra={OutlinedButton(onClick={targets=true}){Text("Additional involvement (${roles.size} roles, ${people.size} people)")};Text("Enrolled students and teachers in the selected section are included. Add other roles or people when needed.",style=MaterialTheme.typography.bodySmall)}){input->api.post("workspace/recollections.php",input.put("id",row.optLong("id")).put("action",if(row.optLong("id")>0)"update"else"create").put("targets_submitted",1).put("other_role_ids",JSONArray(roles.toList())).put("other_user_ids",JSONArray(people.map{it.optLong("id")})));saved()}
        if(targets)AlertDialog(onDismissRequest={targets=false},title={Text("Additional involvement")},text={LazyColumn(verticalArrangement=Arrangement.spacedBy(8.dp)){
            items((json.optJSONArray("roles")?:JSONArray()).objects()){role->Row{Checkbox(role.optLong("id") in roles,{yes->roles=if(yes)roles+role.optLong("id")else roles-role.optLong("id")});Text(role.text("name"),Modifier.padding(vertical=12.dp))}}
            item{OutlinedButton(onClick={picker=true}){Text("Add person")}}
            items(people){person->Row{Text(person.text("name").ifBlank{person.text("label")},Modifier.weight(1f));TextButton(onClick={people=people-person}){Text("Remove")}}}
        }},confirmButton={TextButton(onClick={targets=false}){Text("Done")}})
        if(picker)AccountPicker(api,onExpired,{picker=false}){people=(people+it).distinctBy{p->p.optLong("id")}}
    }
}
