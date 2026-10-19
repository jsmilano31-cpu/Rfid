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
import java.time.LocalDate
import java.time.YearMonth

@Composable fun PersonalAttendance(api:ApiClient,onExpired:()->Unit){var query by rememberSaveable{mutableStateOf("")};var status by rememberSaveable{mutableStateOf("")};var page by rememberSaveable(query,status){mutableIntStateOf(1)};var refine by remember{mutableStateOf(false)};var dates by remember{mutableStateOf(JSONObject())};var detail by remember{mutableStateOf<JSONObject?>(null)};RemotePanel(api,"workspace/attendance.php?q=${encoded(query)}&status=$status&page=$page&from=${dates.text("from")}&through=${dates.text("through")}",onExpired){json->LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
    item{PageTitle("My attendance","Recorded class sessions, arrival times and sources.")}
    item{Panel{Text((json.optJSONArray("summary")?:JSONArray()).objects().joinToString(" · "){"${it.text("status")} ${it.optInt("total")}"},style=MaterialTheme.typography.bodyMedium);Text("Unrecorded sessions are not counted as absences.",style=MaterialTheme.typography.bodySmall)}}
    item{OutlinedTextField(query,{query=it},label={Text("Search subject")},modifier=Modifier.fillMaxWidth(),singleLine=true);SearchSelect("Attendance status",status,listOf("" to "All statuses")+listOf("Present","Late","Absent","Excused").map{it to it}){status=it};TextButton(onClick={refine=true}){Text("Refine dates")}}
    items((json.optJSONArray("items")?:JSONArray()).objects(),key={it.text("id")}){row->Card(onClick={detail=row},modifier=Modifier.fillMaxWidth()){Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){Text("${row.text("course_code")} · ${row.text("component_type")}",style=MaterialTheme.typography.titleMedium);Text(row.text("course_title"));Text("${row.text("section_name")} · ${row.text("semester_name")}",style=MaterialTheme.typography.bodySmall);StatusPill(row.text("status"));Text("${row.text("attendance_date")} · ${row.text("time_in").ifBlank{"No arrival recorded"}}");Text("Source: ${row.text("source")} · ${if(row.text("finalized_at").isBlank())"Open session"else"Finalized session"}",style=MaterialTheme.typography.bodySmall)}}}
    if(json.optInt("total")==0)item{EmptyState("No matching records","Try another subject, period or status.")}
    item{PageControls(page,json.optInt("pages",1),json.optInt("total")){page=it}}
}}
    if(refine)NativeEditor("Attendance period",listOf(NativeField("from","From",dates.text("from"),type="date"),NativeField("through","Through",dates.text("through"),type="date")),onExpired,{refine=false},"Apply",extra={TextButton(onClick={dates=JSONObject();refine=false}){Text("Clear dates")}}){dates=it;page=1}
    detail?.let{row->AlertDialog(onDismissRequest={detail=null},title={Text(row.text("course_code"))},text={RecordDetails(row,JSONObject().put("course_title","Subject").put("section_name","Section").put("semester_name","Semester").put("attendance_date","Date").put("status","Status").put("time_in","Time in").put("time_out","Time out").put("source","Source").put("finalized_at","Finalized"))},confirmButton={TextButton(onClick={detail=null}){Text("Close")}})}
}

@Composable internal fun PersonalAttendanceCalendar(api:ApiClient,offering:String,data:StudentData,onExpired:()->Unit){var month by rememberSaveable{mutableStateOf(YearMonth.from(data.today()).toString())};var selected by rememberSaveable{mutableStateOf(data.today().toString())};RemotePanel(api,"workspace/attendance.php?mode=calendar&offering_id=$offering&month=$month",onExpired){json->val rows=(json.optJSONArray("records")?:JSONArray()).objects();LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){item{PageTitle("Subject attendance calendar","Select a recorded date to inspect arrival times and sources.")};item{MonthPicker(YearMonth.parse(month),LocalDate.parse(selected),{month=it.toString();selected=it.atDay(1).toString()},{selected=it.toString()}){day->rows.any{it.text("attendance_date")==day.toString()}}};items(rows.filter{it.text("attendance_date")==selected}){row->Panel{StatusPill(row.text("status"));Text("Time in: ${row.text("time_in").ifBlank{"Not recorded"}}\nTime out: ${row.text("time_out").ifBlank{"Not recorded"}}");Text("Source: ${row.text("source")}\n${if(row.text("finalized_at").isBlank())"Open session"else"Finalized session"}")}};if(rows.none{it.text("attendance_date")==selected})item{EmptyState("No recorded attendance","This date has no recorded session for you. It is not counted as an absence.")}}}}
