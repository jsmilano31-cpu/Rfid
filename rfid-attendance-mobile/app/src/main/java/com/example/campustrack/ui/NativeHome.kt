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

@Composable internal fun NativeStudentHome(api:ApiClient,data:StudentData,onExpired:()->Unit,open:(String)->Unit){
    val today=data.today()
    LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(16.dp)){
        item{PageTitle("Hello, ${data.student.name.substringBefore(' ')}",today.displayDate())}
        if(api.can("view_analytics"))item{RemotePanel(api,"workspace/analytics.php?workspace=student&section=overview",onExpired){json->Panel{Text("Your progress",style=MaterialTheme.typography.titleLarge);(json.optJSONArray("cards")?:JSONArray()).objects().take(4).forEach{card->Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text(card.text("label"),Modifier.weight(1f));Text(if(card.text("value").isBlank())"No results"else card.text("value")+card.text("unit"),style=MaterialTheme.typography.titleMedium)}};TextButton(onClick={open("analytics")}){Text("Explore your progress")}}}}
        if(api.can("view_schedule")){
            item{SectionTitle("Today's classes","Weekly schedule"){open("schedule")}}
            val classes=data.courses.filter{today.dayOfWeek in it.days}
            if(classes.isEmpty())item{EmptyState("No classes today","Open your schedule to see the week ahead.")}
            items(classes,key={it.id}){course->CourseCard(course){open("course/${course.id}")}}
        }
        if(api.can("view_schedule","view_events","manage_events","view_recollections","manage_recollections","manage_academic_calendar"))item{TeacherMenu("Campus calendar","Classes, activities and holidays"){open("calendar")}}
        if(api.can("view_events","manage_events","view_recollections","manage_recollections")){
            val activities=data.activities.filter{!(it.endDate?:it.date).isBefore(today)&&it.status !in listOf("closed","cancelled")}.sortedBy{it.date}.take(3)
            if(activities.isNotEmpty())item{SectionTitle("Upcoming activities")}
            items(activities,key={it.id}){activity->ActivityCard(activity){open(if(activity.id.startsWith("event-"))"event/${activity.id.removePrefix("event-")}"else"recollections")}}
        }
        if(api.can("view_messages"))item{TeacherMenu("Messages","Open your campus mailbox"){open("messages")}}
    }
}

@Composable internal fun AttendanceClasses(api:ApiClient,workspace:String,onExpired:()->Unit,open:(String)->Unit){
    var query by rememberSaveable{mutableStateOf("")};var page by rememberSaveable(query){mutableIntStateOf(1)}
    RemotePanel(api,"teacher/attendance.php?mode=subjects&workspace=$workspace&q=${encoded(query)}&page=$page",onExpired){json->LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
        item{PageTitle("Class attendance","Choose a permitted class to inspect its dated records.");OutlinedTextField(query,{query=it},label={Text("Search subject or section")},singleLine=true,modifier=Modifier.fillMaxWidth())}
        items((json.optJSONArray("subjects")?:JSONArray()).objects()){row->Panel{Text("${row.text("course_code")} · ${row.text("component_type")}",style=MaterialTheme.typography.titleMedium);Text(row.text("course_title"));Text("${row.text("section_name")} · ${row.text("semester_name")} · ${row.text("school_year_name")}");Text("Teacher: ${row.text("teacher_names")}");Button(onClick={open(row.text("id"))}){Text("Open attendance")}}}
        item{val p=json.optJSONObject("pagination")?:json;PageControls(page,p.optInt("pages",1),p.optInt("total")){page=it}}
    }}
}
