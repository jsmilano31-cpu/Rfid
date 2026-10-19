package com.example.campustrack.ui

import androidx.compose.runtime.*
import androidx.compose.material3.*
import com.example.campustrack.data.*
import org.json.JSONArray
import org.json.JSONObject

@Composable internal fun GradeFilters(api:ApiClient,workspace:String,filters:JSONObject,onExpired:()->Unit,close:()->Unit,apply:(JSONObject)->Unit){
    var program by remember{mutableStateOf(filters.text("program_id"))}
    RemotePanel(api,"workspace/grades.php?mode=options&workspace=$workspace",onExpired){json->val rows=(json.optJSONArray("items")?:JSONArray()).objects()
        NativeEditor("Refine subject grades",listOf(
            NativeField("program_id","Program",program,listOf("0" to "All permitted programs")+rows.distinctBy{it.text("program_id")}.map{it.text("program_id") to "${it.text("program_code")} · ${it.text("program_name")}"}),
            NativeField("section_id","Section",filters.text("section_id"),listOf("0" to "All permitted sections")+rows.filter{program.isBlank()||program=="0"||it.text("program_id")==program}.map{it.text("section_id") to it.text("section_name")}),
            NativeField("status","Grade status",filters.text("status"),listOf("" to "All statuses","ungraded" to "Not entered","draft" to "Draft","finalized" to "Finalized")),
            NativeField("sort","Sort by",filters.text("sort").ifBlank{"course"},listOf("course" to "Subject code","section" to "Section","students_desc" to "Most students","average_desc" to "Highest finalized average"))
        ),onExpired,close,"Apply",onChange={key,value->if(key=="program_id")program=value},extra={TextButton(onClick={apply(JSONObject());close()}){Text("Clear filters")}}){apply(it)}
    }
}
