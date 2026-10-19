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

@Composable fun NativeGradesScreen(api:ApiClient,workspace:String,onExpired:()->Unit,openRoster:(String)->Unit){var query by rememberSaveable{mutableStateOf("")};var mode by rememberSaveable{mutableStateOf(if(workspace=="student")"own"else"subjects")};var page by rememberSaveable(query,mode){mutableIntStateOf(1)};var student by rememberSaveable{mutableStateOf("")};var filters by remember{mutableStateOf(JSONObject())};var refine by remember{mutableStateOf(false)};RemotePanel(api,"workspace/grades.php?workspace=$workspace&mode=${if(student.isNotBlank())"transcript"else mode}&q=${encoded(query)}&page=$page&student_id=$student&"+filters.keys().asSequence().joinToString("&"){"${encoded(it)}=${encoded(filters.text(it))}"},onExpired){json->LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
    item{PageTitle(if(workspace=="student")"My grades"else"Class grades","One shared subject result covers lecture and laboratory.")}
    if(workspace=="student")item{SearchSelect("Semester",filters.text("semester_id").ifBlank{"0"},listOf("0" to "All enrolled semesters")+json.options("semesters")){filters=JSONObject(filters.toString()).put("semester_id",it);page=1};OutlinedTextField(query,{query=it},label={Text("Search subject or teacher")},singleLine=true,modifier=Modifier.fillMaxWidth())}
    if(workspace!="student")item{if(student.isNotBlank())TextButton(onClick={student=""}){Text("Back to student search")}else{SearchSelect("Browse",mode,listOf("subjects" to "Subjects","students" to "Student lookup")){mode=it};if(mode=="subjects")TextButton(onClick={refine=true}){Text("Refine subjects")};OutlinedTextField(query,{query=it},label={Text(if(mode=="students")"Search student name or ID"else"Search subject, teacher or section")},singleLine=true,modifier=Modifier.fillMaxWidth())}}
    if(json.has("gpa"))item{Panel{Text("Finalized weighted average",style=MaterialTheme.typography.labelLarge);Text(json.text("gpa").ifBlank{if(json.optInt("graded_count")>0)"Average unavailable"else"No finalized results"},style=MaterialTheme.typography.headlineMedium);Text("${json.optInt("graded_count")} finalized shared subjects");if(json.text("gpa").isBlank()&&json.optInt("graded_count")>0)Text("An average needs configured units for every finalized subject.",style=MaterialTheme.typography.bodySmall)}}
    items((json.optJSONArray("grades")?:JSONArray()).objects()){row->Panel{Text(row.text("course_code"),style=MaterialTheme.typography.titleLarge);Text(row.text("course_title"),style=MaterialTheme.typography.titleMedium);Text("Teacher: ${row.text("teacher_names").ifBlank{"Not assigned"}}");Text("${listOf(row.text("component_type"),row.text("section_name")).filter{it.isNotBlank()}.joinToString(" \u00b7 ")}\n${row.text("semester_name")} · ${row.text("school_year_name")}",style=MaterialTheme.typography.bodySmall);HorizontalDivider();val settings=json.optJSONObject("settings")?:JSONObject();listOf("prelim" to "Prelims","midterm" to "Midterms","final" to "Endterms").forEach{(key,label)->if(settings.optInt("has_$key")==1)Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text(label);Text(row.text(key).ifBlank{"—"})}};Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text("Overall",style=MaterialTheme.typography.titleSmall);Text(row.text("overall").ifBlank{"—"},style=MaterialTheme.typography.titleLarge)};StatusPill(row.text("status"))}}
    items((json.optJSONArray("subjects")?:JSONArray()).objects()){row->Panel{Text("${row.text("course_code")} · ${row.text("component_type")}",style=MaterialTheme.typography.titleMedium);Text(row.text("title").ifBlank{row.text("course_title")});Text("${row.text("section_name")} · ${row.text("semester_name")}");Text("Teacher: ${row.text("teacher_names")}");Text("${row.text("student_count")} students · ${row.text("finalized_count")} finalized");Button(onClick={openRoster(row.text("id"))}){Text("Open grade sheet")}}}
    items((json.optJSONArray("students")?:json.optJSONArray("items")?:JSONArray()).objects()){row->Panel{Text(row.text("name").ifBlank{row.text("student_name").ifBlank{"${row.text("first_name")} ${row.text("last_name")}"}},style=MaterialTheme.typography.titleMedium);Text(row.text("school_id"));Button(onClick={student=row.text("id").ifBlank{row.text("student_id")}}){Text("View transcript")}}}
    if(json.has("pagination"))item{val p=json.optJSONObject("pagination")?:JSONObject();PageControls(page,p.optInt("pages",1),p.optInt("total")){page=it}}
    if((json.optJSONArray("grades")?:json.optJSONArray("subjects")?:json.optJSONArray("students")?:JSONArray()).length()==0)item{EmptyState("No matching records","Search by name, ID, subject or section. Grades remain missing until entered.")}
}};if(refine)GradeFilters(api,workspace,filters,onExpired,{refine=false}){filters=it;page=1}}

@Composable fun NativeSearchScreen(api:ApiClient,onExpired:()->Unit,open:(String)->Unit){
    var query by rememberSaveable{mutableStateOf("")};var category by rememberSaveable{mutableStateOf("")};var page by rememberSaveable(query,category){mutableIntStateOf(1)}
    Column{OutlinedTextField(query,{query=it},label={Text("Search pages, people, subjects and events")},modifier=Modifier.fillMaxWidth().padding(20.dp),singleLine=true);SearchSelect("Search in",category,listOf("" to "Everything","people" to "People","academics" to "Subjects and classes","activities" to "Activities","facilities" to "Rooms and scanners","pages" to "Pages")){category=it};RemotePanel(api,"workspace/search.php?q=${encoded(query)}&category=${encoded(category)}&page=$page",onExpired){json->LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(10.dp)){
        items((json.optJSONArray("groups")?:JSONArray()).objects()){group->Text(group.text("label"),style=MaterialTheme.typography.titleMedium);(group.optJSONArray("items")?:JSONArray()).objects().forEach{row->TeacherMenu(row.text("label").ifBlank{row.text("title")},row.text("subtitle")){open(nativeSearchDestination(row))}}}
        item{val p=json.optJSONObject("pagination")?:json;PageControls(page,p.optInt("pages",1),p.optInt("total")){page=it}}
    }}}
}

internal fun nativeSearchDestination(row:JSONObject):String {
    val uri=android.net.Uri.parse(row.text("url"));val page=uri.getQueryParameter("page");val entity=uri.getQueryParameter("manage");val record=uri.getQueryParameter("entity_id");val offering=uri.getQueryParameter("offering_id");val id=uri.getQueryParameter("id")?:uri.getQueryParameter("user_id")?:row.text("key").substringAfter(':',row.text("id"))
    return when {
        uri.path?.endsWith("deletion-record.php")==true->"linked/${uri.getQueryParameter("type")}/$id"
        uri.getQueryParameter("manage_user")!=null->"record/user/${uri.getQueryParameter("manage_user")}"
        entity!=null&&record!=null->"record/$entity/$record"
        row.text("type")=="person"->"person/$id"
        row.text("type")=="event"->"event/${uri.getQueryParameter("event_id")?:id}"
        page=="class-roster"&&offering!=null->"roster/$offering"
        page in listOf("grades","attendance")&&offering!=null->"$page/$offering"
        page=="seat-plans"&&offering!=null->"live/$offering"
        page=="seat-plans"->"seat-subjects"
        page=="profile"&&id.isNotBlank()->"person/$id"
        page=="dashboard"||page==null->"home"
        page in listOf("users","academics","rooms","audit-trail","roles")->"manage/${if(page=="roles")"role"else page}"
        page=="attendance-policies"->"manage/policies"
        page=="rfid"->"manage/rfid"
        else->page
    }
}
