package com.example.campustrack.ui

import androidx.compose.material3.*
import androidx.compose.runtime.*
import com.example.campustrack.data.*
import org.json.JSONArray
import org.json.JSONObject

@Composable internal fun AnalyticsFilters(options:JSONObject,filters:JSONObject,workspace:String,onExpired:()->Unit,close:()->Unit,apply:(JSONObject)->Unit){
    var draft by remember{mutableStateOf(JSONObject(filters.toString()))}
    val relationships=(options.optJSONArray("relationships")?:JSONArray()).objects()
    val hierarchy=listOf("semester_id","department_id","program_id","section_id","teacher_id","course_id","offering_id")
    fun choices(key:String):List<Pair<String,String>>{
        val parents=hierarchy.takeWhile{it!=key}
        val ids=relationships.filter{row->parents.all{parent->draft.text(parent).ifBlank{"0"}=="0"||row.text(if(parent=="offering_id")"id"else parent)==draft.text(parent)}}.map{it.text(if(key=="offering_id")"id"else key)}.toSet()
        return listOf("0" to "All permitted ${when(key){"course_id"->"subjects";"offering_id"->"classes";else->key.removeSuffix("_id")+"s"}}")+options.options(key).filter{relationships.isEmpty()||it.first in ids}
    }
    NativeEditor("Refine analytics",listOfNotNull(
        if(workspace=="campus")NativeField("department_id","Department",draft.text("department_id"),choices("department_id"))else null,
        if(workspace=="campus")NativeField("program_id","Program",draft.text("program_id"),choices("program_id"))else null,
        if(workspace=="campus")NativeField("section_id","Section",draft.text("section_id"),choices("section_id"))else null,
        if(workspace=="campus")NativeField("teacher_id","Teacher",draft.text("teacher_id"),choices("teacher_id"))else null,
        NativeField("course_id","Subject",draft.text("course_id"),choices("course_id")),
        if(workspace!="student")NativeField("offering_id","Assigned class",draft.text("offering_id"),choices("offering_id"))else null
    ),onExpired,close,"Apply",onChange={key,value->draft=JSONObject(draft.toString()).put(key,value)},extra={Text("Options narrow as you select an academic group.");TextButton(onClick={apply(JSONObject());close()}){Text("Clear filters")}}){apply(it)}
}
