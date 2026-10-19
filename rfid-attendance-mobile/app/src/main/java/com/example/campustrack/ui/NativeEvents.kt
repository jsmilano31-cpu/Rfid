package com.example.campustrack.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.Dispatchers
import org.json.JSONArray
import org.json.JSONObject
import java.time.LocalDate

@Composable fun NativeEventsScreen(api:ApiClient,onExpired:()->Unit,open:((String)->Unit)?=null){val focus=androidx.compose.ui.platform.LocalFocusManager.current;var query by rememberSaveable{mutableStateOf("")};var id by rememberSaveable{mutableStateOf("")};var create by remember{mutableStateOf(false)};var revision by remember{mutableIntStateOf(0)};var selected by remember{mutableStateOf(setOf<Long>())};var category by rememberSaveable{mutableStateOf("All")};var page by rememberSaveable(query,category){mutableIntStateOf(1)}
    BackHandler(id.isNotBlank()){id="";revision++}
    if(id.isNotBlank()){Column{TextButton(onClick={id="";revision++}){Text("Back to events")};NativeEventDetail(api,id,onExpired){id="";revision++}};return}
    RemotePanel(api,"workspace/events.php?revision=$revision&page=$page&q=${encoded(query)}&filter=${if(category=="All")""else category.lowercase()}",onExpired){json->val rows=(json.optJSONArray("items")?:JSONArray()).objects()
        LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(16.dp)){
            item{PageTitle("Events","Campus activities, details and your attendance.");if(api.can("manage_events"))Button(onClick={create=true}){Text("Create event")}}
            item{OutlinedTextField(query,{query=it},label={Text("Search events")},modifier=Modifier.fillMaxWidth(),singleLine=true,keyboardOptions=androidx.compose.foundation.text.KeyboardOptions(imeAction=androidx.compose.ui.text.input.ImeAction.Search),keyboardActions=androidx.compose.foundation.text.KeyboardActions(onSearch={focus.clearFocus()}));FilterChips(listOf("All","Active","Inactive","Announcements"),category){category=it}}
            item{BulkDeleteControl(api,"event",selected,{selected=it},onExpired,open){revision++}}
            if(rows.isEmpty())item{EmptyState("No matching events","Try another search or category.")}
            items(rows,key={it.text("id")}){event->Card(onClick={id=event.text("id")},modifier=Modifier.fillMaxWidth()){Column{coil.compose.AsyncImage(event.text("banner_url"),"Event banner",modifier=Modifier.fillMaxWidth().height(150.dp),contentScale=ContentScale.Crop);Column(Modifier.padding(16.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){if(event.optBoolean("can_manage"))Checkbox(event.optLong("id") in selected,{yes->selected=if(yes&&selected.size<100)selected+event.optLong("id")else selected-event.optLong("id")});StatusPill(event.text("status"));StatusPill(if(event.optBoolean("my_required"))"Required for you"else"Optional")};Text(event.text("title"),style=MaterialTheme.typography.titleLarge);Text(event.text("description"),maxLines=3);Text("${eventDates(event.text("event_date"),event.text("end_date"))}\n${scheduleTime(event.text("start_time"))} \u2013 ${scheduleTime(event.text("end_time"))}\n${event.text("venue")}",style=MaterialTheme.typography.bodyMedium);Text("Your attendance: ${event.text("my_attendance_status").ifBlank{"Not recorded"}}",style=MaterialTheme.typography.labelMedium)}}}}
            item{PageControls(page,json.optInt("pages",1),json.optInt("total")){page=it}}
        }
    }
    if(create)EventEditor(api,JSONObject(),onExpired,{create=false}){newId->id=newId;revision++}
}

@Composable fun NativeEventDetail(api:ApiClient,id:String,onExpired:()->Unit,onDeleted:()->Unit={}){var revision by remember{mutableIntStateOf(0)};var edit by remember{mutableStateOf(false)};var tracking by remember{mutableStateOf(false)};var log by rememberSaveable{mutableStateOf(false)};var query by rememberSaveable{mutableStateOf("")};var source by rememberSaveable{mutableStateOf("")};var page by rememberSaveable(query,source){mutableIntStateOf(1)};var control by remember{mutableStateOf("")};var refine by remember{mutableStateOf(false)};var logFilters by remember{mutableStateOf(JSONObject())};var correction by remember{mutableStateOf(false)};var error by remember{mutableStateOf<String?>(null)};val scope=rememberCoroutineScope();BackHandler(log){log=false}
    RemotePanel(api,"workspace/events.php?mode=details&id=$id&revision=$revision",onExpired){json->val event=json.getJSONObject("event")
        Column{
            if(log){TextButton(onClick={log=false}){Text("Back to event")};TextButton(onClick={refine=true}){Text("Refine activity log")};OutlinedTextField(query,{query=it},label={Text("Search participant name or ID")},modifier=Modifier.fillMaxWidth().padding(horizontal=20.dp),singleLine=true);SearchSelect("Activity source",source,listOf("" to "All sources","rfid" to "Connected scanners","manual" to "Manual entries","system" to "System")){source=it};RemotePanel(api,"workspace/events.php?mode=activity&id=$id&q=${encoded(query)}&source=$source&page=$page&"+logFilters.keys().asSequence().joinToString("&"){"${encoded(it)}=${encoded(logFilters.text(it))}"},onExpired){activity->LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(12.dp)){item{PageTitle("Activity log","History remains available after the event closes.")};items((activity.optJSONArray("records")?:JSONArray()).objects()){row->Panel{Text("${row.text("first_name")} ${row.text("last_name")}",style=MaterialTheme.typography.titleMedium);Text(row.text("school_id"));Text("${row.text("action").replace('_',' ')} · ${row.text("occurred_at")}");StatusPill(row.text("status"));Text(row.text("source"),style=MaterialTheme.typography.bodySmall)}};item{PageControls(page,activity.optInt("pages",1),activity.optInt("total")){page=it}}}}}
            else LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(16.dp)){
                item{coil.compose.AsyncImage(event.text("banner_url"),"Event banner",modifier=Modifier.fillMaxWidth().height(210.dp),contentScale=ContentScale.Crop)}
                items((event.optJSONArray("images")?:JSONArray()).let{a->(0 until a.length()).map{a.getString(it)}}){url->coil.compose.AsyncImage(url,"Event gallery image",modifier=Modifier.fillMaxWidth().height(180.dp),contentScale=ContentScale.Fit)};item{PageTitle(event.text("title"),event.text("description"));StatusPill(event.text("status"));Text("Your attendance: ${event.optJSONObject("my_attendance")?.text("status").orEmpty().ifBlank{"Not recorded"}}");Text(event.optJSONObject("my_attendance")?.text("check_in_at").orEmpty());Text(event.optJSONObject("my_attendance")?.text("check_out_at").orEmpty())}
                item{Panel{EventDetailLine("Dates","${eventDates(event.text("event_date"),event.text("end_date"))}");EventDetailLine("Time","${scheduleTime(event.text("start_time"))} \u2013 ${scheduleTime(event.text("end_time"))}");EventDetailLine("Venue",event.text("venue"));EventDetailLine("Check-in window","${scheduleTime(event.text("check_in_start"))} \u2013 ${scheduleTime(event.text("check_in_end"))}");EventDetailLine("Check-out",if(event.optInt("check_out_required")==1)"Required"else"Optional")}}
                item{Button(onClick={log=true},modifier=Modifier.fillMaxWidth()){Text("View activity log")}}
                if(event.optBoolean("can_manage")){
                    item{OutlinedButton(onClick={correction=true}){Text("Correct participant attendance")}};item{Button(onClick={tracking=true},modifier=Modifier.fillMaxWidth()){Text("Start event tracking")}}
                    item{Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){OutlinedButton(onClick={edit=true}){Text("Edit event")};TextButton(onClick={control="status"}){Text("Change status")};TextButton(onClick={control="delete"}){Text("Delete")}}}
                }
                error?.let{item{Text(it,color=MaterialTheme.colorScheme.error)}}
            }
        }
        if(refine)NativeEditor("Refine activity log",listOf(NativeField("action","Action",logFilters.text("action"),listOf("" to "All actions","check_in" to "Check in","check_out" to "Check out","status_change" to "Corrections")),NativeField("status","Attendance",logFilters.text("status"),listOf("" to "All statuses")+listOf("Attended","Late","Absent","Excused","Incomplete").map{it to it}),NativeField("from","From",logFilters.text("from"),type="date"),NativeField("through","Through",logFilters.text("through"),type="date"),NativeField("sort","Order",logFilters.text("sort").ifBlank{"newest"},listOf("newest" to "Newest first","oldest" to "Oldest first","name" to "Participant name"))),onExpired,{refine=false},"Apply",extra={TextButton(onClick={logFilters=JSONObject();source="";query="";page=1;refine=false}){Text("Clear filters")}}){logFilters=it;page=1}
        if(correction)EventCorrection(api,id,onExpired,{correction=false}){revision++}
        if(edit)EventEditor(api,event,onExpired,{edit=false}){revision++}
        if(tracking)EventTracking(api,event,onExpired){tracking=false;revision++}
        if(control.isNotBlank())NativeEditor(if(control=="delete")"Delete event?"else"Event attendance status",if(control=="delete")emptyList()else listOf(NativeField("status","Status",event.text("status"),listOf("scheduled","open","closed","cancelled").map{it to it.replaceFirstChar(Char::uppercase)})),onExpired,{control=""},if(control=="delete")"Confirm deletion"else"Apply"){input->api.post("workspace/events.php",input.put("id",id.toLong()).put("action",control));if(control=="delete")onDeleted()else revision++}
    }
}
@Composable private fun EventDetailLine(label:String,value:String){Text(label,style=MaterialTheme.typography.labelSmall,color=MaterialTheme.colorScheme.onSurfaceVariant);Text(value.ifBlank{"Not configured"},style=MaterialTheme.typography.bodyLarge)}

@Composable private fun EventEditor(api:ApiClient,event:JSONObject,onExpired:()->Unit,close:()->Unit,saved:(String)->Unit){
    var targets by remember(event){mutableStateOf((event.optJSONArray("targets")?:JSONArray().put(JSONObject().put("type","all_students").put("label","All students"))).objects())}
    var targetPicker by remember{mutableStateOf(false)}
    var scannerPicker by remember{mutableStateOf(false)}
    var scanners by remember(event){mutableStateOf((event.optJSONArray("scanners")?:JSONArray()).objects().map{it.optLong("id")}.toSet())}
    var images by remember{mutableStateOf(listOf<UploadFile>())}
    var imageError by remember{mutableStateOf<String?>(null)}
    val context=LocalContext.current
    val scope=rememberCoroutineScope()
    val pickImages=rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()){uris->scope.launch{try{require(uris.size<=20){"Choose up to twenty images."};images=withContext(Dispatchers.IO){uris.mapIndexed{index,uri->val bytes=context.contentResolver.openInputStream(uri)?.use{it.readLimited(2_097_153)}?:error("The image could not be read.");require(bytes.size<=2_097_152){"Choose images up to 2 MB."};UploadFile("event-$index.jpg",context.contentResolver.getType(uri)?:"image/jpeg",bytes)}}}catch(f:Exception){imageError=f.message}}}
    RemotePanel(api,"workspace/events.php?mode=options",onExpired){options->val audience=options.optJSONObject("audience")?:JSONObject();NativeEditor(if(event.optLong("id")>0)"Edit event"else"Create event",listOf(
    NativeField("title","Event title",event.text("title"),required=true),NativeField("description","Description",event.text("description"),type="multiline"),NativeField("venue","Venue",event.text("venue"),required=true),
    NativeField("event_date","Date start",event.text("event_date").ifBlank{options.text("today")},type="date"),NativeField("end_date","Date end",event.text("end_date").ifBlank{event.text("event_date").ifBlank{options.text("today")}},type="date"),
    NativeField("start_time","Time start (HH:mm)",event.text("start_time"),required=true),NativeField("end_time","Time end (HH:mm)",event.text("end_time"),required=true),
    NativeField("check_in_start","Check-in opens (HH:mm)",event.text("check_in_start")),NativeField("check_in_end","Check-in closes (HH:mm)",event.text("check_in_end")),
    NativeField("attendance_required","Attendance",event.text("attendance_required").ifBlank{"1"},listOf("1" to "Required","0" to "Optional")),NativeField("check_out_required","Check-out",event.text("check_out_required").ifBlank{"0"},listOf("0" to "Optional","1" to "Required")),NativeField("suspends_classes","Class suspension",event.text("suspends_classes").ifBlank{"0"},listOf("0" to "Classes continue","1" to "Suspend classes")),NativeField("is_announcement","Type",event.text("is_announcement").ifBlank{"0"},listOf("0" to "Event","1" to "Announcement"))
),onExpired,close,extra={Column(verticalArrangement=Arrangement.spacedBy(8.dp)){
    OutlinedButton(onClick={targetPicker=true}){Text("Audience (${targets.size} selections)")};Text(targets.joinToString(", "){it.text("label").ifBlank{it.text("type")}},style=MaterialTheme.typography.bodySmall)
    OutlinedButton(onClick={scannerPicker=true}){Text("Connected scanners (${scanners.size})")}
    OutlinedButton(onClick={pickImages.launch(arrayOf("image/jpeg","image/png","image/webp"))}){Text(if(images.isEmpty())"Choose banner / gallery"else"${images.size} replacement images selected")}
    Text("The first image becomes the banner. Existing images stay until replacements are selected.",style=MaterialTheme.typography.bodySmall);imageError?.let{Text(it,color=MaterialTheme.colorScheme.error)}
}}){input->input.put("id",event.optLong("id")).put("action",if(event.optLong("id")>0)"update"else"create").put("targets_json",JSONArray(targets).toString()).put("scanner_ids",JSONArray(scanners.toList()));val result=if(images.isEmpty())api.post("workspace/events.php",input)else api.upload("workspace/event-media.php",input,images,"event_images[]");saved(result.text("id").ifBlank{event.text("id")})}
    if(targetPicker)AudiencePicker(api,audience,targets,onExpired,{targetPicker=false}){targets=it}
    if(scannerPicker)ScannerPicker(options.optJSONArray("devices")?:JSONArray(),scanners,{scanners=it}){scannerPicker=false}
    }
}

@Composable private fun ScannerPicker(devices:JSONArray,selected:Set<Long>,change:(Set<Long>)->Unit,close:()->Unit){AlertDialog(onDismissRequest=close,title={Text("Connected event scanners")},text={LazyColumn{items(devices.objects()){row->Row{Checkbox(row.optLong("id") in selected,{checked->change(if(checked)selected+row.optLong("id")else selected-row.optLong("id"))});Text("${row.text("name")} · ${row.text("device_code")}")}}}},confirmButton={TextButton(onClick=close){Text("Done")}})}

@Composable private fun AudiencePicker(api:ApiClient,options:JSONObject,selected:List<JSONObject>,onExpired:()->Unit,close:()->Unit,change:(List<JSONObject>)->Unit){var type by remember{mutableStateOf("broad")};var query by remember{mutableStateOf("")};var roleFilter by remember{mutableStateOf("any")};val groups=when(type){"department"->options.optJSONArray("departments");"program"->options.optJSONArray("programs");"section"->options.optJSONArray("sections");else->null}
    AlertDialog(onDismissRequest=close,title={Text("Event audience")},text={Column{SearchSelect("Add recipients",type,listOf("broad" to "Campus groups","department" to "Department","program" to "Program","section" to "Section","user" to "Individual")){type=it};if(type in listOf("department","program","section"))SearchSelect("People in this group",roleFilter,listOf("any" to "All roles","student" to "Students","teacher" to "Teachers","staff" to "Staff")){roleFilter=it};OutlinedTextField(query,{query=it},label={Text("Search recipients")},singleLine=true);LazyColumn(Modifier.heightIn(max=220.dp)){
        if(type=="broad")items(listOf("everyone" to "Everyone","all_students" to "All students","all_teachers" to "All teachers","all_staff" to "All staff")){(key,label)->TextButton(onClick={change((selected+JSONObject().put("type",key).put("label",label)).distinctBy{it.text("type")+it.text("id")+it.text("roleFilter")}.take(40))}){Text(label)}}
        else if(type!="user")items((groups?:JSONArray()).objects().filter{it.text("name").contains(query,true)}){row->TextButton(onClick={change((selected+JSONObject().put("type",type).put("id",row.optLong("id")).put("roleFilter",roleFilter).put("label",row.text("name"))).distinctBy{it.text("type")+it.text("id")+it.text("roleFilter")}.take(40))}){Text(row.text("name"))}}
    };if(type=="user")RemotePanel(api,"workspace/events.php?mode=people&q=${encoded(query)}",onExpired){json->LazyColumn(Modifier.heightIn(max=150.dp)){items((json.optJSONArray("items")?:JSONArray()).objects()){row->TextButton(onClick={change((selected+JSONObject().put("type","user").put("id",row.optLong("id")).put("label",row.text("name"))).distinctBy{it.text("type")+it.text("id")+it.text("roleFilter")}.take(40))}){Text("${row.text("name")} · ${row.text("school_id")}")}}}}
        Text("Selected recipients",style=MaterialTheme.typography.titleSmall);LazyColumn(Modifier.heightIn(max=140.dp)){items(selected){row->Row{Text(row.text("label").ifBlank{row.text("type")},modifier=Modifier.weight(1f));TextButton(onClick={change(selected-row)}){Text("Remove")}}}}
    }},confirmButton={TextButton(onClick=close,enabled=selected.isNotEmpty()){Text("Done")}})
}

@Composable private fun EventTracking(api:ApiClient,event:JSONObject,onExpired:()->Unit,close:()->Unit){val id=event.text("id");var cursor by remember{mutableStateOf<Long?>(null)};var latest by remember{mutableStateOf<JSONObject?>(null)};var error by remember{mutableStateOf<String?>(null)};var accepting by remember{mutableStateOf<Boolean?>(null)};var schoolId by rememberSaveable{mutableStateOf("")};var action by rememberSaveable{mutableStateOf("auto")};var busy by remember{mutableStateOf(false)};val scope=rememberCoroutineScope();val owner=LocalLifecycleOwner.current
    LaunchedEffect(id,owner){owner.lifecycle.repeatOnLifecycle(Lifecycle.State.STARTED){while(true){try{val payload=api.get("workspace/events.php?mode=live&id=$id"+(cursor?.let{"&after=$it"}?:""));cursor=payload.optLong("cursor");accepting=payload.optBoolean("accepting");(payload.optJSONArray("records")?:JSONArray()).objects().lastOrNull{it.text("action") in listOf("check_in","check_out")}?.let{latest=it};error=null}catch(c:CancellationException){throw c}catch(f:Exception){if(f is ApiException&&f.status==401){onExpired();break};error=f.message};delay(2000)}}}
    val banner:@Composable ()->Unit={coil.compose.AsyncImage(event.text("banner_url"),"Event banner",modifier=Modifier.fillMaxWidth().height(260.dp),contentScale=ContentScale.Crop)}
    val participant:@Composable ()->Unit={
        val record=latest;val out=record?.text("action")=="check_out"
        Card(colors=CardDefaults.cardColors(containerColor=if(record==null)MaterialTheme.colorScheme.surfaceContainer else if(out)MaterialTheme.colorScheme.errorContainer else MaterialTheme.colorScheme.secondaryContainer),modifier=Modifier.fillMaxWidth()){
            Column(Modifier.padding(24.dp),verticalArrangement=Arrangement.spacedBy(12.dp)){
                Text("CampusTrack",style=MaterialTheme.typography.labelLarge,color=MaterialTheme.colorScheme.primary)
                if(record==null){Text("Ready for connected scanner",style=MaterialTheme.typography.headlineSmall);Text("New scans appear here. Earlier history remains in the event activity log.")}
                else{
                    coil.compose.AsyncImage(record.text("photo_url"),"Participant photo",modifier=Modifier.size(100.dp),contentScale=ContentScale.Crop)
                    Text(record.text("school_id"),style=MaterialTheme.typography.titleLarge)
                    Text(record.text("last_name"),style=MaterialTheme.typography.headlineLarge)
                    Text(record.text("first_name"),style=MaterialTheme.typography.titleMedium)
                    Text("${if(out)"CHECK OUT"else"CHECK IN"} \u00b7 ${record.text("occurred_at")}",style=MaterialTheme.typography.titleLarge)
                    Text("${record.text("status")} \u00b7 ${record.text("source")}")
                }
            }
        }
    }
    Dialog(onDismissRequest=close,properties=DialogProperties(usePlatformDefaultWidth=false)){
        Surface(Modifier.fillMaxSize(),color=MaterialTheme.colorScheme.background){LazyColumn(Modifier.safeDrawingPadding(),contentPadding=PaddingValues(20.dp),verticalArrangement=Arrangement.spacedBy(16.dp)){
            item{Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text(event.text("title"),style=MaterialTheme.typography.titleLarge,modifier=Modifier.weight(1f));TextButton(onClick=close){Text("Exit tracking")}}}
            item{BoxWithConstraints{if(maxWidth>=600.dp)Row(horizontalArrangement=Arrangement.spacedBy(20.dp)){Box(Modifier.weight(1f)){banner()};Box(Modifier.weight(1f)){participant()}}else Column(verticalArrangement=Arrangement.spacedBy(16.dp)){banner();participant()}}}
            item{
                if((event.optJSONArray("scanners")?.length()?:0)==0)Text("No scanner is linked to this event. Edit the event to assign a connected scanner, or use the manual fallback below.",style=MaterialTheme.typography.bodySmall)
                Text("Manual fallback",style=MaterialTheme.typography.titleMedium)
                Text("Enter the participant's school ID if the scanner cannot read their card.",style=MaterialTheme.typography.bodySmall)
                OutlinedTextField(schoolId,{schoolId=it},label={Text("School ID number")},singleLine=true,modifier=Modifier.fillMaxWidth())
                SearchSelect("Record",action,listOf("auto" to "Automatic check-in / check-out","check_in" to "Check in","check_out" to "Check out")){action=it}
                Button(enabled=schoolId.isNotBlank()&&!busy&&accepting==true,onClick={scope.launch{busy=true;try{api.post("workspace/events.php",JSONObject().put("action","manual_id").put("id",id.toLong()).put("school_id",schoolId).put("attendance_action",action));schoolId="";error=null}catch(c:CancellationException){throw c}catch(f:Exception){if(f is ApiException&&f.status==401)onExpired()else error=f.message}finally{busy=false}}},modifier=Modifier.fillMaxWidth()){Text(if(busy)"Recording\u2026"else"Record attendance")}
                if(accepting==null)Text("Connecting to event tracking\u2026",style=MaterialTheme.typography.bodySmall)
                else if(accepting==false)Text("This event is not accepting arrivals.",color=MaterialTheme.colorScheme.error)
            }
            error?.let{item{Text(it,color=MaterialTheme.colorScheme.error)}}
        }}
    }
}

internal fun eventDates(start:String,end:String):String {
    fun display(value:String)=runCatching{LocalDate.parse(value).displayDate()}.getOrDefault(value)
    return if(end.isBlank()||end==start)display(start)else"${display(start)} \u2013 ${display(end)}"
}
