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

@Composable internal fun AcademicTerms(api:ApiClient,onExpired:()->Unit){var year by rememberSaveable{mutableStateOf("")};var revision by remember{mutableIntStateOf(0)};var edit by remember{mutableStateOf(false)};RemotePanel(api,"management/terms.php?school_year_id=$year&revision=$revision",onExpired){json->val row=json.optJSONObject("year")?:JSONObject();val terms=(json.optJSONArray("terms")?:JSONArray()).objects();LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
    item{PageTitle("School year & academic terms","Changes apply to the campus calendar and validation rules.");SearchSelect("School year",year.ifBlank{row.text("id")},json.options("years")){year=it}}
    item{Panel{Text(row.text("name"),style=MaterialTheme.typography.titleLarge);Text("${row.text("starts_on")} – ${row.text("ends_on")}");Button(onClick={edit=true}){Text("Edit dates and statuses")}}}
    items(terms){term->Panel{Text(term.text("name"),style=MaterialTheme.typography.titleMedium);Text("${term.text("starts_on")} – ${term.text("ends_on")}");StatusPill(term.text("status"))}}
};if(edit){val names=listOf("First Semester","Second Semester","Summer Class");NativeEditor("Academic calendar",listOf(NativeField("starts_on","School year start",row.text("starts_on"),type="date"),NativeField("ends_on","School year end",row.text("ends_on"),type="date"))+names.flatMapIndexed{index,name->val term=terms.find{it.text("name")==name}?:JSONObject();listOf(NativeField("start_$index","$name start",term.text("starts_on"),type="date"),NativeField("end_$index","$name end",term.text("ends_on"),type="date"),NativeField("status_$index","$name status",term.text("status").ifBlank{"upcoming"},listOf("upcoming" to "Upcoming","active" to "Active","closed" to "Closed")))},onExpired,{edit=false}){input->val save=JSONObject().put("school_year_id",row.optLong("id")).put("starts_on",input.text("starts_on")).put("ends_on",input.text("ends_on")).put("terms",JSONArray(names.mapIndexed{index,_->JSONObject().put("starts_on",input.text("start_$index")).put("ends_on",input.text("end_$index")).put("status",input.text("status_$index"))}));api.post("management/terms.php",save);revision++}}}}

@Composable internal fun DepartmentWeek(api:ApiClient,onExpired:()->Unit){var revision by remember{mutableIntStateOf(0)};var department by rememberSaveable{mutableStateOf("")};var days by remember{mutableStateOf(setOf<String>())};var confirmation by remember{mutableStateOf(false)};RemotePanel(api,"management/policies.php?mode=days-off&revision=$revision",onExpired){json->LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
    item{PageTitle("Department weekly schedules","Select regular days off. Existing classes must be moved before a weekday can be marked off.")}
    item{SearchSelect("Department",department,json.options("departments")){department=it;val policy=(json.optJSONArray("days_off")?:JSONArray()).objects().find{row->row.text("id")==it};days=(policy?.optJSONArray("days_off")?:JSONArray()).let{a->(0 until a.length()).map{a.getString(it)}.toSet()}}}
    items(listOf("Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday")){day->Row{Checkbox(day in days,{off->days=if(off)days+day else days-day});Text(day,Modifier.padding(vertical=12.dp))}}
    item{Button(onClick={confirmation=true},enabled=department.isNotBlank(),modifier=Modifier.fillMaxWidth()){Text("Save weekly schedule")}}
};if(confirmation)NativeEditor("Save regular days off?",emptyList(),onExpired,{confirmation=false},"Confirm",extra={Text(days.joinToString(", ").ifBlank{"No regular days off"})}){api.post("management/policies.php",JSONObject().put("mode","days-off").put("department_id",department.toLong()).put("days_off",JSONArray(days.toList())));revision++}}}

@Composable internal fun EnrollmentWorkspace(api:ApiClient,offering:String,onExpired:()->Unit){var query by rememberSaveable{mutableStateOf("")};var page by rememberSaveable(query){mutableIntStateOf(1)};var revision by remember{mutableIntStateOf(0)};var edit by remember{mutableStateOf<JSONObject?>(null)};var adding by remember{mutableStateOf(false)};if(adding)AccountPicker(api,onExpired,{adding=false},true){edit=JSONObject(it.toString()).put("student_id",it.optLong("id")).put("student_name",it.text("name")).put("status","enrolled")};RemotePanel(api,"management/enrollments.php?offering_id=$offering&q=${encoded(query)}&page=$page&revision=$revision",onExpired){json->LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
    item{val subject=json.optJSONObject("subject")?:JSONObject();PageTitle("${subject.text("course_code")} enrollments","${subject.text("section_name")} · ${subject.text("component_type")}\nDropping a student updates every enrolled lecture and laboratory component of this subject.");Button(onClick={adding=true}){Text("Add student")};OutlinedTextField(query,{query=it},label={Text("Search student name or ID")},singleLine=true,modifier=Modifier.fillMaxWidth())}
    items((json.optJSONArray("students")?:JSONArray()).objects()){row->Panel{Text(row.text("student_name"),style=MaterialTheme.typography.titleMedium);Text(row.text("school_id"));StatusPill(row.text("status"));TextButton(onClick={edit=row}){Text("Change enrollment")}}}
    item{val p=json.optJSONObject("pagination")?:JSONObject();PageControls(page,p.optInt("pages",1),p.optInt("total")){page=it}}
};edit?.let{student->NativeEditor("Enrollment status",listOf(NativeField("status","Status",student.text("status"),listOf("enrolled" to "Enrolled","completed" to "Completed","dropped" to "Dropped from the whole subject"))),onExpired,{edit=null},"Confirm status",extra={Text("${student.text("student_name")} · ${student.text("school_id")}")}){input->api.post("management/enrollments.php",input.put("offering_id",offering.toLong()).put("student_id",student.optLong("student_id")));revision++}}}}
