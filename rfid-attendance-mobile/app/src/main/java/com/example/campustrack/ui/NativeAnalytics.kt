package com.example.campustrack.ui

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import org.json.JSONArray
import org.json.JSONObject
import java.time.LocalDate

@Composable fun NativeAnalyticsScreen(api:ApiClient,workspace:String,onExpired:()->Unit,open:(String)->Unit={}) {
    val context=if(workspace=="management")"campus"else workspace
    var section by rememberSaveable(context){mutableStateOf("overview")}
    var period by rememberSaveable{mutableStateOf("This semester")}
    var filters by remember{mutableStateOf(JSONObject())}
    var refine by remember{mutableStateOf(false)}
    var inspect by remember{mutableStateOf<JSONObject?>(null)}
    var records by rememberSaveable{mutableStateOf(false)}
    var page by rememberSaveable{mutableIntStateOf(1)}
    var question by rememberSaveable{mutableStateOf("weekday")}
    var matching by rememberSaveable{mutableStateOf("date")};var weekday by rememberSaveable{mutableStateOf("all")};var outcome by rememberSaveable{mutableStateOf("lecture_only")}
    var panelKey by rememberSaveable{mutableStateOf("")}
    val query=filters.keys().asSequence().joinToString("&"){"${encoded(it)}=${encoded(filters.text(it))}"}
    val base="workspace/analytics.php?workspace=$context&section=$section&question=$question&matching=$matching&weekday=$weekday&outcome=$outcome&$query"
    if(section=="goals"){Column{TextButton(onClick={section="overview"}){Text("Back to overview")};NativeGoalsScreen(api,onExpired)};return};RemotePanel(api,base+(if(records)"&mode=records&page=$page&panel=$panelKey"else""),onExpired){json->
        val options=json.optJSONObject("options")?:JSONObject()
        val navigation=json.optJSONObject("navigation")?:JSONObject()
        LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(16.dp)) {
            item{PageTitle(when(context){"student"->"My progress";"teacher"->"My classes";else->"Campus insights"},json.optJSONObject("metadata")?.text("scope").orEmpty())}
            if(!records){
                item{SearchSelect("Analytics view",section,navigation.keys().asSequence().map{it to navigation.text(it)}.toList().ifEmpty{listOf("overview" to "Overview")}){section=it;panelKey=""}}
                item{SearchSelect("Semester",filters.text("semester_id").ifBlank{json.optJSONObject("filters")?.text("semester_id").orEmpty()},listOf("0" to "All permitted semesters")+options.options("semester_id")){filters=JSONObject(filters.toString()).put("semester_id",it)}}
                item{FilterChips(listOf("This semester","Last 30 days","Custom"),period){period=it;filters=JSONObject(filters.toString()).apply{if(it=="Last 30 days"){put("from",LocalDate.parse(json.text("today")).minusDays(30).toString());put("to",LocalDate.parse(json.text("today")).minusDays(1).toString())}else if(it=="This semester"){remove("from");remove("to")}}}}
                if(period=="Custom") item{Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){Column(Modifier.weight(1f)){DateInput("From",filters.text("from")){filters=JSONObject(filters.toString()).put("from",it)}};Column(Modifier.weight(1f)){DateInput("Through",filters.text("to")){filters=JSONObject(filters.toString()).put("to",it)}}}}
                item{TextButton(onClick={refine=true}){Text("Refine view${if(filters.length()>0)" (${filters.length()})"else""}")}}
                if(filters.length()>0)item{Column{filters.keys().asSequence().filter{key->key !in listOf("from","to")&&filters.text(key)!="0"}.forEach{key->Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text(options.options(key).find{it.first==filters.text(key)}?.second?:filters.text(key),Modifier.weight(1f),style=MaterialTheme.typography.bodySmall);TextButton(onClick={filters=JSONObject(filters.toString()).apply{remove(key)}}){Text("Remove")}}};TextButton(onClick={filters=JSONObject();period="This semester"}){Text("Clear filters")}}}
                if(section=="explore")item{SearchSelect("Question",question,listOf("weekday" to "Attendance by weekday","components" to "Lecture versus laboratory","classes" to "Changes across classes","grades" to "Midterms versus Endterms")){question=it};if(question in listOf("components","weekday"))SearchSelect("Weekday",weekday,listOf("all" to "All weekdays")+listOf("Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday").map{it to it}){weekday=it};if(question=="components")SearchSelect("Pattern",outcome,listOf("lecture_only" to "Attended lecture, absent from lab","lab_only" to "Attended lab, absent from lecture","both_attended" to "Attended both components","both_absent" to "Absent from both components")){outcome=it};if(question=="components")SearchSelect("Match observations",matching,listOf("date" to "Same date","week" to "Same calendar week")){matching=it}}
                if(json.text("answer").isNotEmpty())item{Text(json.text("answer"),style=MaterialTheme.typography.titleLarge)}
                item{Column(verticalArrangement=Arrangement.spacedBy(10.dp)){(json.optJSONArray("cards")?:JSONArray()).objects().take(4).chunked(2).forEach{pair->Row(horizontalArrangement=Arrangement.spacedBy(10.dp)){pair.forEach{card->Surface(Modifier.weight(1f),shape=MaterialTheme.shapes.medium,color=MaterialTheme.colorScheme.surfaceContainer){Column(Modifier.padding(14.dp),verticalArrangement=Arrangement.spacedBy(6.dp)){Text(card.text("label"),style=MaterialTheme.typography.labelLarge);Text(card.text("value").ifBlank{"\u2014"}+card.text("unit"),style=MaterialTheme.typography.headlineSmall);Text(card.text("detail"),style=MaterialTheme.typography.bodySmall)}}};if(pair.size==1)Spacer(Modifier.weight(1f))}}}}
                items((json.optJSONArray("panels")?:JSONArray()).objects()){panel->Panel{Text(panel.text("title"),style=MaterialTheme.typography.titleLarge);Text(panel.text("description"),style=MaterialTheme.typography.bodySmall);NativeMetricChart(panel){inspect=it};Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){TextButton(onClick={inspect=panel}){Text("View data")};TextButton(onClick={section=panel.text("section").ifBlank{section};panelKey=panel.text("key");records=true;page=1}){Text("Supporting records")}};TextButton(onClick={inspect=JSONObject().put("columns",JSONObject().put("formula","Calculation").put("coverage","Coverage").put("history","Historical context")).put("rows",JSONArray().put(JSONObject().put("formula",panel.text("formula")).put("coverage",panel.text("coverage")).put("history",json.optJSONObject("metadata")?.text("history_note"))))}){Text("How calculated")};}}
                items((json.optJSONArray("insights")?:JSONArray()).objects().take(3)){item->Text("${item.text("title")}\n${item.text("detail").ifBlank{item.text("description")}}",style=MaterialTheme.typography.bodyMedium)}
                json.optJSONObject("supporting")?.let{support->item{Text(support.text("title"),style=MaterialTheme.typography.titleLarge)};items((support.optJSONArray("rows")?:JSONArray()).objects().take(5)){row->Panel{Text("${row.text("course_code")} · ${row.text("section")}");Text(row.text("title"));Text("${row.text("teacher")} · ${row.text("semester")}");Text("Attendance ${row.text("attendance").ifBlank{"—"}}%")}}}
                json.optJSONObject("supporting")?.optJSONObject("followups")?.let{alerts->item{Panel{Text("Attendance follow-ups",style=MaterialTheme.typography.titleMedium);(alerts.optJSONArray("items")?:JSONArray()).objects().take(5).forEach{row->Text("${row.text("student_name")} · ${row.text("course_code")}")};TextButton(onClick={open("alerts")}){Text("Review attendance alerts")}}}}
                json.optJSONObject("reports")?.let{reports->items(reports.keys().asSequence().toList()){key->TeacherMenu(reports.text(key),"Open this permitted report"){section=key}}}
            }else{
                item{TextButton(onClick={records=false}){Text("Back to chart")}}
                items((json.optJSONArray("rows")?:JSONArray()).objects()){row->Panel{RecordDetails(row,json.optJSONObject("columns")?:JSONObject())}}
                item{PageControls(page,json.optInt("pages",if(json.optBoolean("has_more"))page+1 else page),json.optInt("total")){page=it}}
            }
            item{Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){PrintRecords(api,"$base&mode=export&panel=$panelKey",onExpired);DownloadRecords(api,"$base&mode=export&panel=$panelKey","campustrack-analytics.csv",onExpired)}}
            if((json.optJSONArray(if(records)"rows"else"panels")?.length()?:0)==0)item{EmptyState("No recorded results","Try another period. Missing records are never counted as zero.")}
        }
        if(refine)AnalyticsFilters(options,filters,context,onExpired,{refine=false}){input->filters=JSONObject(filters.toString()).apply{input.keys().forEach{key->if(input.text(key).isNotBlank()&&input.text(key)!="0")put(key,input.text(key))else remove(key)}}}

    }
    inspect?.let{panel->AlertDialog(onDismissRequest={inspect=null},title={Text(panel.text("title").ifBlank{"Chart data"})},text={LazyColumn(verticalArrangement=Arrangement.spacedBy(14.dp)){items((panel.optJSONArray("rows")?:JSONArray()).objects()){row->RecordDetails(row,panel.optJSONObject("columns")?:JSONObject());HorizontalDivider()}}},confirmButton={TextButton(onClick={inspect=null}){Text("Close")}})}
}

/** Values retain an honest zero-to-100 scale for rates, nulls create gaps, and all points have a data view. */
@Composable internal fun NativeMetricChart(panel:JSONObject,inspect:(JSONObject)->Unit) {
    val rows=(panel.optJSONArray("rows")?:JSONArray()).objects();val chart=panel.optJSONObject("chart")?:JSONObject();val series=chart.optJSONObject("series")?:JSONObject()
    val keys=series.keys().asSequence().toList();val colors=listOf(MaterialTheme.colorScheme.secondary,latenessColor(),MaterialTheme.colorScheme.error,MaterialTheme.colorScheme.primary)
    val rateKeys=setOf("participation","lateness","attendance","on_time","punctuality","midterm","final","prelim","overall","mean","average","completion")
    val rateScale=keys.all{it in rateKeys||it in setOf("earlier","recent","coverage","rejection")||panel.optJSONObject("columns")?.text(it)?.contains("%")==true}
    val maxValue=if(rateScale)100.0 else maxOf(1.0,rows.flatMap{row->keys.mapNotNull{key->if(row.isNull(key))null else row.optDouble(key).takeIf{it.isFinite()}}}.maxOrNull()?:1.0)
    if(keys.isEmpty()){Text("Open View data to inspect these records.",style=MaterialTheme.typography.bodyMedium);return}
    if(rows.isEmpty()){Text("No comparable records",style=MaterialTheme.typography.bodyMedium);return}
    if(chart.text("kind")=="heatmap"){
        Text("Rate scale: 0%–100%. Missing cells have no recorded result.",style=MaterialTheme.typography.labelSmall)
        rows.forEach{row->Column(Modifier.fillMaxWidth().clickable{inspect(JSONObject().put("rows",JSONArray().put(row)).put("columns",panel.optJSONObject("columns")))}.padding(vertical=8.dp)){
            Text(row.text("label"),style=MaterialTheme.typography.titleSmall)
            Row(horizontalArrangement=Arrangement.spacedBy(8.dp)){keys.forEachIndexed{index,key->val value=if(row.isNull(key))null else row.optDouble(key);Surface(Modifier.weight(1f),shape=MaterialTheme.shapes.small,color=if(value==null)MaterialTheme.colorScheme.surfaceContainer else colors[index%colors.size].copy(alpha=.08f+.18f*(value/100).toFloat().coerceIn(0f,1f))){Column(Modifier.padding(12.dp)){Text(series.text(key),style=MaterialTheme.typography.labelSmall);Text(value?.let{"%.1f%%".format(it)}?:"No result",style=MaterialTheme.typography.titleMedium)}}}}
            Text("${row.text("sample")} supporting observations",style=MaterialTheme.typography.bodySmall)
        }};return
    }
    if(chart.text("kind")=="paired"){
        rows.forEach{row->Column(Modifier.fillMaxWidth().clickable{inspect(JSONObject().put("rows",JSONArray().put(row)).put("columns",panel.optJSONObject("columns")))}.padding(vertical=8.dp)){
            Text(row.text("label"),style=MaterialTheme.typography.titleSmall)
            val values=keys.map{key->if(row.isNull(key))null else row.optDouble(key).takeIf{it.isFinite()}}
            val track=MaterialTheme.colorScheme.outlineVariant
            Canvas(Modifier.fillMaxWidth().height(36.dp).semantics{contentDescription=keys.mapIndexed{index,key->"${series.text(key)} ${values[index]?:"Missing"}"}.joinToString(", ")}){
                val inset=10.dp.toPx();val usable=size.width-2*inset;val points=values.map{it?.let{value->Offset(inset+usable*(value/maxValue).toFloat().coerceIn(0f,1f),size.height/2)}}
                drawLine(track,Offset(inset,size.height/2),Offset(size.width-inset,size.height/2),2f)
                if(points.size>=2&&points[0]!=null&&points[1]!=null)drawLine(colors[0].copy(alpha=.45f),points[0]!!,points[1]!!,5f)
                points.forEachIndexed{index,point->if(point!=null){if(index==0)drawCircle(colors[index%colors.size],7.dp.toPx(),point)else drawRect(colors[index%colors.size],point-Offset(6.dp.toPx(),6.dp.toPx()),androidx.compose.ui.geometry.Size(12.dp.toPx(),12.dp.toPx()))}}
            }
            Text(keys.mapIndexed{index,key->"${series.text(key)}: ${values[index]?.let{"%.1f".format(it)}?:"No result"}"}.joinToString(" ? "),style=MaterialTheme.typography.bodySmall)
            Text("Axis 0–${"%.0f".format(maxValue)} ? ${row.text("sample")} matched observations",style=MaterialTheme.typography.labelSmall)
        }};return
    }
    if(chart.text("kind") in listOf("horizontal","paired","heatmap","dot","bar")){
        rows.take(12).forEach{row->Column(Modifier.fillMaxWidth().clickable{inspect(JSONObject().put("rows",JSONArray().put(row)).put("columns",panel.optJSONObject("columns"))) }.padding(vertical=8.dp)){
            Text(row.text("label").ifBlank{row.text("date").ifBlank{row.text("day")}},style=MaterialTheme.typography.labelLarge)
            keys.forEachIndexed{index,key->val value=if(row.isNull(key))null else row.optDouble(key);Text("${series.text(key)}: ${value?.let{"%.1f".format(it)}?:"No result"}",style=MaterialTheme.typography.bodySmall);if(value!=null)LinearProgressIndicator(progress={(value/maxValue).toFloat().coerceIn(0f,1f)},color=colors[index%colors.size],modifier=Modifier.fillMaxWidth().height(5.dp))}
            Text("${row.text("sample").ifBlank{row.text("recorded")}} recorded observations",style=MaterialTheme.typography.bodySmall)
        }}
    }else{
        val grid=MaterialTheme.colorScheme.outlineVariant
        Canvas(Modifier.fillMaxWidth().height(190.dp).pointerInput(rows){detectTapGestures{point->val index=if(rows.size==1)0 else ((point.x/size.width)*(rows.size-1)).toInt().coerceIn(0,rows.lastIndex);inspect(JSONObject().put("title",rows[index].text("label")).put("rows",JSONArray().put(rows[index])).put("columns",panel.optJSONObject("columns")))}}.semantics{contentDescription="${panel.text("title")}. Axis zero to $maxValue. Use View data for exact values."}){
            for(i in 0..4)drawLine(grid,Offset(0f,size.height*i/4),Offset(size.width,size.height*i/4),1f)
            keys.forEachIndexed{index,key->val path=Path();var connected=false;rows.forEachIndexed{i,row->if(row.isNull(key)){connected=false}else{val value=row.optDouble(key,Double.NaN);if(value.isFinite()){val x=if(rows.size==1)size.width/2 else size.width*i/(rows.size-1);val y=size.height*(1-(value/maxValue).toFloat());if(connected)path.lineTo(x,y)else path.moveTo(x,y);connected=true;drawCircle(colors[index%colors.size],4f+index,Offset(x,y))}}};drawPath(path,colors[index%colors.size],style=Stroke(width=3f))}
        }
        Row(Modifier.fillMaxWidth(),horizontalArrangement=Arrangement.SpaceBetween){Text(rows.first().text("label").ifBlank{rows.first().text("date")},style=MaterialTheme.typography.labelSmall);Text(rows.last().text("label").ifBlank{rows.last().text("date")},style=MaterialTheme.typography.labelSmall)}
        Text("Axis 0–${"%.0f".format(maxValue)} · ${panel.text("sample_size")} recorded observations",style=MaterialTheme.typography.bodySmall)
        Text(keys.joinToString(" · "){series.text(it)},style=MaterialTheme.typography.labelSmall)
    }
}

@Composable fun NativeGoalsScreen(api:ApiClient,onExpired:()->Unit) {
    var revision by remember{mutableIntStateOf(0)};var edit by remember{mutableStateOf<JSONObject?>(null)}
    RemotePanel(api,"workspace/goals.php?revision=$revision",onExpired){json->
        val subjects=(json.optJSONArray("subjects")?:JSONArray()).objects();val terms=(json.optJSONArray("semesters")?:JSONArray()).objects()
        LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
            item{PageTitle("My goals","Private targets. Progress comes from your recorded results.")}
            if(json.optBoolean("can_write"))item{Button(onClick={edit=JSONObject()},enabled=terms.isNotEmpty()&&(json.optJSONArray("goals")?:JSONArray()).objects().count{it.isNull("archived_at")}<3){Text("Set a goal")}}
            items((json.optJSONArray("goals")?:JSONArray()).objects()){goal->Panel{Text(goal.text("label"),style=MaterialTheme.typography.titleMedium);Text("${goal.text("course_code")} ${goal.text("title")} · ${goal.text("semester")}");Text("${goal.text("starts_on")} – ${goal.text("ends_on")}");Text("Actual ${goal.text("actual").ifBlank{"No results"}} / target ${goal.text("target")}");if(!goal.isNull("progress"))LinearProgressIndicator(progress={goal.optDouble("progress").toFloat()/100f},modifier=Modifier.fillMaxWidth());Text("${goal.text("sample")} supporting records · ${goal.text("excluded")} exclusions",style=MaterialTheme.typography.bodySmall);Text(goal.text("formula"),style=MaterialTheme.typography.bodySmall);StatusPill(goal.text("state"));if(goal.isNull("archived_at")&&json.optBoolean("can_write")){TextButton(onClick={edit=goal}){Text("Edit or archive")}}}}
            if((json.optJSONArray("goals")?.length()?:0)==0)item{EmptyState("No goals yet",if(terms.isEmpty())"Goals become available when the school enrolls you in a subject."else"Set an attendance, on-time attendance or finalized subject-grade target.")}
        }
        edit?.let{goal->
            val initialTerm=terms.find{it.text("id")==goal.text("semester_id")}?:terms.firstOrNull()
            var chosenTerm by remember(goal){mutableStateOf(initialTerm?.text("id").orEmpty())}
            var metric by remember(goal){mutableStateOf(goal.text("metric").ifBlank{"attendance"})}
            var resets by remember(goal){mutableStateOf(emptyMap<String,String>())}
            val term=terms.find{it.text("id")==chosenTerm}
            val metricOptions=(json.optJSONArray("metrics")?:JSONArray()).let{a->(0 until a.length()).map{a.getString(it) to when(a.getString(it)){"grade"->"Subject grade";"punctuality"->"On-time attendance";else->"Attendance"}}}
            val subjectOptions=subjects.filter{it.text("semester_id")==chosenTerm}.map{"${it.text("course_id")}:${it.text("section_id")}:${it.text("semester_id")}" to "${it.text("course_code")} \u00b7 ${it.text("title")} \u00b7 ${it.text("section")}"}
            NativeEditor("Learning goal",listOf(
                NativeField("metric","Measure",metric,metricOptions,required=true),
                NativeField("semester_id","Semester",chosenTerm,terms.map{it.text("id") to listOf(it.text("name"),it.text("school_year")).filter{part->part.isNotBlank()}.joinToString(" \u00b7 ")},required=true),
                NativeField("subject","Subject",if(goal.text("course_id").isBlank())""else"${goal.text("course_id")}:${goal.text("section_id")}:${goal.text("semester_id")}",(if(metric=="grade")emptyList()else listOf("" to "All enrolled subjects"))+subjectOptions,type="select",required=metric=="grade"),
                NativeField("target","Target (0.01\u2013100)",goal.text("target"),required=true),
                NativeField("starts_on","From",goal.text("starts_on").ifBlank{minOf(term?.text("ends_on").orEmpty(),maxOf(json.text("today"),term?.text("starts_on").orEmpty()))},type="date",required=true),
                NativeField("ends_on","Through",goal.text("ends_on").ifBlank{term?.text("ends_on").orEmpty()},type="date",required=true),
                NativeField("action","Action","save",if(goal.optLong("id")>0)listOf("save" to "Save goal","archive" to "Archive goal")else listOf("save" to "Save goal"))
            ),onExpired,{edit=null},onChange={key,value->
                if(key=="metric")metric=value
                if(key=="semester_id"&&value!=chosenTerm){chosenTerm=value;val selected=terms.find{it.text("id")==value};resets=mapOf("subject" to "","starts_on" to minOf(selected?.text("ends_on").orEmpty(),maxOf(json.text("today"),selected?.text("starts_on").orEmpty())),"ends_on" to selected?.text("ends_on").orEmpty())}
            },resetValues=resets){input->
                input.put("id",goal.optLong("id"));val parts=input.text("subject").split(':')
                if(parts.size==3&&parts[0].isNotBlank()){require(parts[2]==input.text("semester_id")){"Choose a subject in the selected semester."};input.put("course_id",parts[0]);input.put("section_id",parts[1])}
                api.post("workspace/goals.php",input);revision++
            }
        }
    }
}
