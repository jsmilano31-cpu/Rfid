package com.example.campustrack.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import org.json.JSONArray
import org.json.JSONObject

@Composable internal fun DeletionDependencies(api:ApiClient,type:String,id:String,onExpired:()->Unit,open:(String)->Unit){
    var group by rememberSaveable{mutableStateOf("")};var page by rememberSaveable(group){mutableIntStateOf(1)};var revision by remember{mutableIntStateOf(0)}
    RemotePanel(api,"management/dependencies.php?type=$type&id=$id&page=$page&revision=$revision"+(if(group.isBlank())""else"&group=${encoded(group)}"),onExpired){json->LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
        item{PageTitle(json.optJSONObject("target")?.text("label").orEmpty(),json.text("message"));TextButton(onClick={revision++}){Text("Refresh dependencies")}}
        items((json.optJSONArray("groups")?:JSONArray()).objects()){section->Panel{Text("${section.text("label")} (${section.optInt("total")})",style=MaterialTheme.typography.titleMedium);Text(section.text("resolution"));(section.optJSONArray("records")?:JSONArray()).objects().forEach{row->if(row.text("url").isBlank())Text(row.text("title"))else TeacherMenu(row.text("title"),row.text("subtitle")){open(nativeSearchDestination(row))}};PageControls(section.optInt("page",1),section.optInt("pages",1),section.optInt("total")){group=section.text("key");page=it}}}
    }}
}
@Composable internal fun LinkedHistoryRecord(api:ApiClient,type:String,id:String,onExpired:()->Unit){
    RemotePanel(api,"management/dependencies.php?mode=record&type=$type&id=$id",onExpired){json->LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
        item{PageTitle(json.text("title"),json.text("subtitle"));Text(json.text("explanation"))}
        item{Panel{val details=json.optJSONObject("details")?:JSONObject();RecordDetails(details,JSONObject().apply{details.keys().forEach{put(it,it)}})}}
    }}
}
