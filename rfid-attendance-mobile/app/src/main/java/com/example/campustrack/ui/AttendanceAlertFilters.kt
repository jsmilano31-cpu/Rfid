package com.example.campustrack.ui

import androidx.compose.material3.*
import androidx.compose.runtime.*
import com.example.campustrack.data.*
import org.json.JSONArray
import org.json.JSONObject

@Composable internal fun AttendanceAlertFilters(api:ApiClient,workspace:String,filters:JSONObject,onExpired:()->Unit,close:()->Unit,apply:(JSONObject)->Unit){
    var semester by remember{mutableStateOf(filters.text("semester"))};var section by remember{mutableStateOf(filters.text("section"))}
    RemotePanel(api,"workspace/alerts.php?mode=options&workspace=$workspace",onExpired){json->val rows=(json.optJSONArray("options")?:JSONArray()).objects();val terms=rows.distinctBy{it.text("semester_id")};val sections=rows.filter{semester.isBlank()||semester=="0"||it.text("semester_id")==semester}.distinctBy{it.text("section_id")};val subjects=rows.filter{(semester.isBlank()||semester=="0"||it.text("semester_id")==semester)&&(section.isBlank()||section=="0"||it.text("section_id")==section)}
        NativeEditor("Refine attendance alerts",listOf(
            NativeField("semester","Semester",semester,listOf("0" to "All permitted semesters")+terms.map{it.text("semester_id") to "${it.text("semester_name")} · ${it.text("school_year_name")}"}),
            NativeField("section","Section",section,listOf("0" to "All permitted sections")+sections.map{it.text("section_id") to it.text("section_name")}),
            NativeField("subject","Subject",filters.text("subject"),listOf("0" to "All permitted subjects")+subjects.map{it.text("offering_id") to "${it.text("course_code")} · ${it.text("section_name")}"}),
            NativeField("sms_status","Delivery status",filters.text("sms_status"),listOf("" to "Any delivery status","not_sent" to "Not sent","queued" to "Queued","accepted" to "Provider accepted","delivered" to "Delivered","failed" to "Failed","unknown" to "Delivery unknown"))
        ),onExpired,close,"Apply",onChange={key,value->if(key=="semester")semester=value;if(key=="section")section=value},extra={TextButton(onClick={apply(JSONObject());close()}){Text("Clear filters")}}){apply(it)}
    }
}
