package com.example.campustrack.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject

@Composable internal fun BulkDeleteControl(api:ApiClient,type:String,selected:Set<Long>,selection:(Set<Long>)->Unit,onExpired:()->Unit,open:((String)->Unit)?=null,changed:()->Unit){
    var review by remember{mutableStateOf<JSONObject?>(null)};var busy by remember{mutableStateOf(false)};var message by remember{mutableStateOf<String?>(null)};val scope=rememberCoroutineScope()
    fun request(action:String){scope.launch{busy=true;try{val result=api.post("management/bulk.php",JSONObject().put("action",action).put("type",type).put("ids",JSONArray(selected.toList())));review=result;if(action=="delete"){selection((result.optJSONArray("targets")?:JSONArray()).objects().mapNotNull{it.optJSONObject("target")?.optLong("id")}.toSet());message=result.text("message");changed();if(selected.isEmpty())review=null}}catch(c:CancellationException){throw c}catch(f:Exception){if(f is ApiException&&f.status==401)onExpired()else message=f.message}finally{busy=false}}}
    Column{
        if(selected.isNotEmpty())OutlinedButton(enabled=!busy,onClick={request("inspect")}){Text("Review deletion (${selected.size})")}
        message?.let{Text(it,style=MaterialTheme.typography.bodySmall)}
    }
    review?.let{data->AlertDialog(onDismissRequest={if(!busy)review=null},title={Text("Review selected records")},text={LazyColumn(verticalArrangement=Arrangement.spacedBy(12.dp)){
        item{Text("${data.optInt("ready_count")} ready · ${data.optInt("blocked_count")} blocked. Protected history is retained.")}
        items((data.optJSONArray("targets")?:JSONArray()).objects()){row->Text(row.optJSONObject("target")?.text("label").orEmpty(),style=MaterialTheme.typography.titleSmall);Text(if(row.optBoolean("blocked"))row.text("restriction").ifBlank{"Related records prevent deletion."}else"Ready to delete");if(open!=null)TextButton(onClick={review=null;open("dependencies/$type/${row.optJSONObject("target")?.text("id")}")}){Text("Inspect related records")};(row.optJSONArray("groups")?:JSONArray()).objects().forEach{group->Text("${group.text("label")}: ${group.optInt("total")}");Text(group.text("resolution"),style=MaterialTheme.typography.bodySmall)}}
    }},dismissButton={TextButton(enabled=!busy,onClick={review=null}){Text("Close")}},confirmButton={Button(enabled=!busy&&data.optInt("ready_count")>0,onClick={request("delete")}){Text(if(busy)"Processing…"else"Delete eligible records")}})}
}
