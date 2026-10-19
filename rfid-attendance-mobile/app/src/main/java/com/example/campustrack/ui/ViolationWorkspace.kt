package com.example.campustrack.ui

import android.provider.OpenableColumns
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.platform.LocalContext
import com.example.campustrack.data.*
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.Dispatchers
import org.json.JSONArray
import org.json.JSONObject
import java.time.LocalDate

@Composable internal fun ViolationEditor(api:ApiClient,onExpired:()->Unit,close:()->Unit,saved:()->Unit){
    var person by remember{mutableStateOf<JSONObject?>(null)}
    var picker by remember{mutableStateOf(false)}
    var group by remember{mutableStateOf("")}
    var evidence by remember{mutableStateOf<UploadFile?>(null)}
    var error by remember{mutableStateOf<String?>(null)}
    val context=LocalContext.current
    val scope=rememberCoroutineScope()
    val attach=rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()){uri->if(uri!=null)scope.launch{try{var name="Evidence";context.contentResolver.query(uri,arrayOf(OpenableColumns.DISPLAY_NAME),null,null,null)?.use{if(it.moveToFirst())name=it.getString(0)};val bytes=context.contentResolver.openInputStream(uri)?.use{it.readLimited(5_242_880)}?:error("Could not read evidence.");evidence=UploadFile(name,context.contentResolver.getType(uri)?:"application/octet-stream",bytes)}catch(f:Exception){error=f.message}}}
    RemotePanel(api,"management/compliance.php?mode=catalog",onExpired){json->val catalog=json.optJSONObject("violations")?:JSONObject();val groups=catalog.keys().asSequence().toList();val actualGroup=group.ifBlank{groups.firstOrNull().orEmpty()};val items=catalog.optJSONObject(actualGroup)?.optJSONArray("items")?:JSONArray()
        NativeEditor("Record handbook violation",listOf(
            NativeField("violation_group","Violation group",actualGroup,groups.map{it to it}),
            NativeField("specific_violation","Specific violation","",(0 until items.length()).map{items.getString(it) to items.getString(it)}+listOf("Other" to "Other")),
            NativeField("other_violation","Describe other violation (if selected)",type="multiline"),
            NativeField("classification","Classification","Category A",listOf("Category A","Category B","Category C").map{it to it}),
            NativeField("is_progressive","Progressive violation","0",listOf("0" to "No","1" to "Yes")),NativeField("previous_infractions","Previous infractions (if progressive)","0"),NativeField("current_occurrence","Current occurrence (if progressive)","1"),
            NativeField("incident_date","Incident date",json.text("today"),type="date"),NativeField("incident_time","Incident time (HH:mm)",type="time"),NativeField("location","Location",required=true),NativeField("incident_description","Incident description",type="multiline",required=true),NativeField("witnesses","Witnesses (optional)",type="multiline")
        ),onExpired,close,onChange={key,value->if(key=="violation_group")group=value},extra={OutlinedButton(onClick={picker=true}){Text(person?.let{"${it.text("name")} · ${it.text("school_id")}"}?:"Select student")};OutlinedButton(onClick={attach.launch(arrayOf("application/pdf","image/jpeg","image/png","image/webp"))}){Text(evidence?.name?:"Attach evidence (optional)")};error?.let{Text(it,color=MaterialTheme.colorScheme.error)}}){input->require(person!=null){"Select a student."};api.upload("management/violation.php",input.put("student_id",person?.optLong("id")),listOfNotNull(evidence),"evidence");saved()}
    }
    if(picker)AccountPicker(api,onExpired,{picker=false},true){person=it}
}
