package com.example.campustrack.ui

import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import com.example.campustrack.data.*
import org.json.JSONArray
import org.json.JSONObject

@Composable internal fun AuditFilters(api:ApiClient,filters:JSONObject,onExpired:()->Unit,close:()->Unit,apply:(JSONObject)->Unit){
    RemotePanel(api,"management/audit.php?mode=options",onExpired){json->
        fun options(key:String)=(json.optJSONArray(key)?:JSONArray()).let{a->(0 until a.length()).map{a.getString(it) to a.getString(it).replace('_',' ')}}
        NativeEditor("Refine audit trail",listOf(
            NativeField("actor","Actor",filters.text("actor"),listOf("" to "All permitted actors","system" to "System")+json.options("auditActors")),
            NativeField("entity","Record type",filters.text("entity"),listOf("" to "All record types")+options("auditEntities")),NativeField("action","Action",filters.text("action"),listOf("" to "All actions")+options("auditActions")),
            NativeField("source","Source",filters.text("source"),listOf("" to "All sources","activity" to "Application activity","database" to "Database changes")),NativeField("operation","Operation",filters.text("operation"),listOf("" to "All operations","INSERT" to "Create","UPDATE" to "Update","DELETE" to "Delete")),
            NativeField("from","From",filters.text("from"),type="date"),NativeField("to","Through",filters.text("to"),type="date"),NativeField("sort","Sort by",filters.text("sort").ifBlank{"time"},listOf("time" to "Time","actor" to "Actor","action" to "Action","entity" to "Record type","source" to "Source")),NativeField("direction","Order",filters.text("direction").ifBlank{"desc"},listOf("desc" to "Descending","asc" to "Ascending"))
        ),onExpired,close,"Apply",extra={TextButton(onClick={apply(JSONObject());close()}){Text("Clear filters")}}){apply(it)}
    }
}
