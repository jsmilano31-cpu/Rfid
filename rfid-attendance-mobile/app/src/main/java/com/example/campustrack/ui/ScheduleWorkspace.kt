package com.example.campustrack.ui

import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import com.example.campustrack.data.*
import org.json.JSONArray
import org.json.JSONObject
import java.time.DayOfWeek
import java.time.LocalTime
import java.time.format.DateTimeFormatter
import java.util.Locale

internal fun scheduleCourses(items:JSONArray,data:StudentData):List<Course> = items.objects().groupBy{it.text("class_offering_id")}.map{(id,rows)->
    val first=rows.first();val old=data.courses.find{it.id==id}
    val meetings=rows.map{row->Meeting(DayOfWeek.valueOf(row.text("day_of_week").uppercase(Locale.ENGLISH)),"${scheduleTime(row.text("start_time"))} – ${scheduleTime(row.text("end_time"))}",row.text("room_name"),row.text("room_building"),row.text("room_floor"),row.text("room_code"))}
    Course(id,first.text("title"),first.text("teacher_names").ifBlank{"Teacher to be assigned"},first.text("room_name"),meetings.map{it.day}.distinct(),meetings.firstOrNull()?.time.orEmpty(),old?.prelim,old?.midterm,old?.final,old?.overall,first.text("course_code")+" "+first.text("component_type"),old?.gradeStatus?:"Not entered",meetings,first.text("section_name"),first.text("semester_name"),first.text("status"),old?.teachers?:listOfNotNull(first.text("teacher_id").takeIf{it.isNotBlank()}?.let{Teacher(it,first.text("teacher_names"))}),old?.timeOff.orEmpty(),old?.daysOff.orEmpty(),old?.gradeUpdatedAt.orEmpty(),first.text("semester_id"),first.text("school_year_name"),first.text("starts_on"),first.text("ends_on"),rows.associate{it.text("availability_date") to it.text("availability_notice")})
}
internal fun scheduleTime(value:String):String=runCatching{LocalTime.parse(value).format(DateTimeFormatter.ofPattern("h:mm a",Locale.ENGLISH))}.getOrDefault(value)

@Composable internal fun ScheduleWorkspace(api:ApiClient,data:StudentData,workspace:String,onExpired:()->Unit,open:(String)->Unit){
    RemotePanel(api,"workspace/schedule.php?workspace=$workspace",onExpired){json->val courses=scheduleCourses(json.optJSONArray("items")?:JSONArray(),data)
        ScheduleScreen(data.copy(courses=courses)){open("scheduled/$it")}
    }
}
@Composable internal fun ScheduledClass(api:ApiClient,data:StudentData,workspace:String,id:String,onExpired:()->Unit,open:(String)->Unit){
    RemotePanel(api,"workspace/schedule.php?workspace=$workspace&offering_id=$id",onExpired){json->val course=scheduleCourses(json.optJSONArray("items")?:JSONArray(),data).firstOrNull()
        if(course==null)EmptyState("Schedule unavailable","This class is outside your permitted schedule.")
        else if(workspace=="student")CourseScreen(course,data.copy(courses=(data.courses.filter{it.id!=id}+course)),open,api.permissions)
        else ClassTools(api,course,open)
    }
}
