package com.example.campustrack.ui

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset
import java.time.LocalTime

@Composable internal fun SearchSelect(label:String,value:String,options:List<Pair<String,String>>,select:(String)->Unit) {
    val choices=options.distinctBy{it.first}
    var expanded by remember { mutableStateOf(false) };var query by remember { mutableStateOf("") }
    OutlinedButton(onClick={expanded=true;query=""},modifier=Modifier.fillMaxWidth(),shape=MaterialTheme.shapes.small,contentPadding=PaddingValues(16.dp,12.dp),colors=ButtonDefaults.outlinedButtonColors(contentColor=MaterialTheme.colorScheme.onSurface)){Column(Modifier.weight(1f),verticalArrangement=Arrangement.spacedBy(4.dp)){Text(label,style=MaterialTheme.typography.labelSmall,color=MaterialTheme.colorScheme.onSurfaceVariant);Text(choices.find{it.first==value}?.second?:"Choose",style=MaterialTheme.typography.bodyMedium,maxLines=2,overflow=androidx.compose.ui.text.style.TextOverflow.Ellipsis)};Icon(androidx.compose.material.icons.Icons.Default.ArrowDropDown,"Open options")}
    if(expanded) AlertDialog(onDismissRequest={expanded=false},title={Text(label)},text={Column{
        OutlinedTextField(query,{query=it},placeholder={Text("Search options")},singleLine=true,modifier=Modifier.fillMaxWidth())
        LazyColumn(Modifier.heightIn(max=340.dp)){items(choices.filter{it.second.contains(query,true)},key={it.first}){option->ListItem(headlineContent={Text(option.second)},modifier=Modifier.clickable{select(option.first);expanded=false})}}
    }},confirmButton={TextButton(onClick={expanded=false}){Text("Close")}})
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable internal fun DateInput(label:String,value:String,change:(String)->Unit) {
    var show by remember{mutableStateOf(false)}
    OutlinedButton(onClick={show=true},modifier=Modifier.fillMaxWidth(),shape=MaterialTheme.shapes.small,contentPadding=PaddingValues(16.dp,12.dp)){Column(Modifier.fillMaxWidth()){Text(label,style=MaterialTheme.typography.labelSmall);Text(runCatching{LocalDate.parse(value).displayDate()}.getOrDefault("Choose date"))}}
    if(show){val state=rememberDatePickerState(initialSelectedDateMillis=runCatching{LocalDate.parse(value).atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli()}.getOrNull());DatePickerDialog(onDismissRequest={show=false},confirmButton={TextButton(onClick={state.selectedDateMillis?.let{change(Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate().toString())};show=false},enabled=state.selectedDateMillis!=null){Text("Apply")}},dismissButton={TextButton(onClick={show=false}){Text("Cancel")}}){DatePicker(state)}}
}

internal data class NativeField(val key:String,val label:String,val initial:String="",val options:List<Pair<String,String>> = emptyList(),val type:String="text",val required:Boolean=false)
@OptIn(ExperimentalMaterial3Api::class)
@Composable internal fun TimeInputField(label:String,value:String,change:(String)->Unit){var show by remember{mutableStateOf(false)};OutlinedButton(onClick={show=true},modifier=Modifier.fillMaxWidth(),shape=MaterialTheme.shapes.small,contentPadding=PaddingValues(16.dp,12.dp)){Column(Modifier.fillMaxWidth()){Text(label.substringBefore(" ("),style=MaterialTheme.typography.labelSmall);Text(runCatching{LocalTime.parse(value.take(5)).format(java.time.format.DateTimeFormatter.ofPattern("h:mm a"))}.getOrDefault("Choose time"))}};if(show){val time=runCatching{LocalTime.parse(value.take(5))}.getOrDefault(LocalTime.NOON);val state=rememberTimePickerState(time.hour,time.minute,false);AlertDialog(onDismissRequest={show=false},title={Text(label.substringBefore(" ("))},text={TimeInput(state)},confirmButton={TextButton(onClick={change("%02d:%02d".format(state.hour,state.minute));show=false}){Text("Apply")}},dismissButton={TextButton(onClick={change("");show=false}){Text("Clear")}})}}
@OptIn(ExperimentalMaterial3Api::class)
@Composable internal fun NativeEditor(title:String,fields:List<NativeField>,onExpired:()->Unit,close:()->Unit,confirm:String="Save",onChange:(String,String)->Unit={_,_->},resetValues:Map<String,String> = emptyMap(),extra:@Composable ()->Unit = {},submit:suspend(JSONObject)->Unit) {
    val values=remember(title){mutableStateMapOf<String,String>().apply{fields.forEach{put(it.key,it.initial)}}}
    LaunchedEffect(resetValues){resetValues.forEach{(key,value)->values[key]=value}}
    SideEffect { fields.forEach { field -> if(!values.containsKey(field.key))values[field.key]=field.initial; if(field.options.isNotEmpty()&&values[field.key].orEmpty().isNotBlank()&&field.options.none{it.first==values[field.key]}){values[field.key]="";onChange(field.key,"")} } }
    fun change(key:String,value:String){values[key]=value;onChange(key,value)}
    var busy by remember{mutableStateOf(false)};var error by remember{mutableStateOf<String?>(null)};val scope=rememberCoroutineScope()
    Dialog(onDismissRequest={if(!busy)close()},properties=DialogProperties(usePlatformDefaultWidth=false)){Surface(Modifier.fillMaxSize(),color=MaterialTheme.colorScheme.background){Scaffold(topBar={TopAppBar(title={Text(title)},navigationIcon={TextButton(onClick=close,enabled=!busy){Text("Cancel")}})},bottomBar={Column(Modifier.padding(16.dp)){error?.let{Text(it,color=MaterialTheme.colorScheme.error)};Button(onClick={busy=true;error=null;scope.launch{try{submit(JSONObject().apply{values.forEach{(k,v)->put(k,v)}});close()}catch(c:CancellationException){throw c}catch(f:Exception){if(f is ApiException&&f.status==401)onExpired()else error=f.message}finally{busy=false}}},enabled=!busy&&fields.filter{it.required}.all{!values[it.key].isNullOrBlank()}&&fields.filter{it.type=="phone"}.all{PhilippineMobile.valid(values[it.key].orEmpty())},modifier=Modifier.fillMaxWidth()){if(busy)CircularProgressIndicator(Modifier.size(18.dp),strokeWidth=2.dp)else Text(confirm)}}}){padding->LazyColumn(Modifier.padding(padding),contentPadding=PaddingValues(16.dp),verticalArrangement=Arrangement.spacedBy(14.dp)){item{extra()};items(fields,key={it.key}){field->
        when{field.type=="date"->DateInput(field.label,values[field.key].orEmpty()){change(field.key,it)}
            field.type=="time"||field.label.contains("HH:mm")->TimeInputField(field.label,values[field.key].orEmpty()){change(field.key,it)}
            field.options.isNotEmpty()||field.type=="select"->SearchSelect(field.label+(if(field.required)" *"else""),values[field.key].orEmpty(),field.options){change(field.key,it)}
            field.type=="phone"->PhoneInput(values[field.key].orEmpty()){change(field.key,it)}
            else->OutlinedTextField(values[field.key].orEmpty(),{change(field.key,it);error=null},label={Text(field.label+(if(field.required)" *" else ""))},modifier=Modifier.fillMaxWidth(),singleLine=field.type!="multiline",minLines=if(field.type=="multiline")4 else 1,enabled=!busy,visualTransformation=if(field.type=="secret")androidx.compose.ui.text.input.PasswordVisualTransformation()else androidx.compose.ui.text.input.VisualTransformation.None)
        }
    }}}}}
}

@Composable internal fun DownloadRecords(api:ApiClient,path:String,name:String,onExpired:()->Unit) {
    val context=LocalContext.current;val scope=rememberCoroutineScope();var busy by remember{mutableStateOf(false)};var error by remember{mutableStateOf<String?>(null)}
    val save=rememberLauncherForActivityResult(ActivityResultContracts.CreateDocument("text/csv")){uri->if(uri!=null)scope.launch{busy=true;error=null;try{api.saveDocument(context.contentResolver,uri,path)}catch(c:CancellationException){throw c}catch(f:Exception){if(f is ApiException&&f.status==401)onExpired()else error=f.message}finally{busy=false}}}
    Column{OutlinedButton(onClick={save.launch(name)},enabled=!busy){Text(if(busy)"Saving records…"else"Export records")};error?.let{Text(it,color=MaterialTheme.colorScheme.error,style=MaterialTheme.typography.bodySmall)}}
}

@Composable internal fun RecordDetails(row:JSONObject,columns:JSONObject) {Column(verticalArrangement=Arrangement.spacedBy(8.dp)){columns.keys().forEach{key->Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text(columns.text(key),style=MaterialTheme.typography.labelMedium,modifier=Modifier.weight(1f));Text(row.text(key).ifBlank{"—"},modifier=Modifier.weight(1f))}}}}
internal fun JSONObject.options(key:String):List<Pair<String,String>> = (optJSONArray(key)?:JSONArray()).objects().map{it.text("id") to it.text("label").ifBlank{it.text("name")}}

@Composable internal fun PreviewSelector(api:ApiClient,changed:()->Unit){val roles=(api.capabilities.optJSONArray("preview_roles")?:JSONArray()).objects();val scope=rememberCoroutineScope();var error by remember{mutableStateOf<String?>(null)};SearchSelect("View as role","",roles.map{it.text("id") to it.text("name")}){scope.launch{try{api.post("workspace/preview.php",JSONObject().put("role_id",it.toLong()));api.session();changed()}catch(c:CancellationException){throw c}catch(f:Exception){error=f.message}}};error?.let{Text(it,color=MaterialTheme.colorScheme.error)}}

@Composable internal fun PersonalHome(api:ApiClient,data:StudentData,workspace:String,onExpired:()->Unit,open:(String)->Unit){
    if(workspace=="student"){NativeStudentHome(api,data,onExpired,open);return}
    val today=data.today()
    LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
        item{PageTitle("Hello, ${data.student.name.substringBefore(' ')}","Your assigned classes for ${today.displayDate()}.")}
        if(api.can("send_attendance_sms","correct_class_attendance","manage_attendance","view_class_rosters"))item{
            RemotePanel(api,"workspace/dashboard.php?workspace=teacher",onExpired){json->json.optJSONObject("alerts")?.let{alerts->Panel{
                Text("Attendance follow-ups",style=MaterialTheme.typography.titleMedium)
                Text("${alerts.optJSONObject("summary")?.optInt("pending")?:0} notices need review")
                (alerts.optJSONArray("items")?:JSONArray()).objects().take(5).forEach{Text("${it.text("student_name")} \u00b7 ${it.text("course_code")}")}
                TextButton(onClick={open("alerts")}){Text("View attendance alerts")}
            }}}
        }
        if(api.can("view_schedule")){
            item{TeacherMenu("Today's schedule","Classes, rooms and times"){open("schedule")}}
            val classes=data.courses.filter{today.dayOfWeek in it.days&&(it.startsOn.isBlank()||!today.isBefore(LocalDate.parse(it.startsOn)))&&(it.endsOn.isBlank()||!today.isAfter(LocalDate.parse(it.endsOn)))}.take(5)
            if(classes.isEmpty())item{EmptyState("No classes today","Open your schedule to see the week ahead.")}
            items(classes,key={it.id}){CourseCard(it){open("class/${it.id}")}}
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
