package com.example.campustrack.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import org.json.JSONArray

@Composable internal fun RfidHistory(api:ApiClient,onExpired:()->Unit,back:()->Unit){
    var query by rememberSaveable{mutableStateOf("")};var page by rememberSaveable(query){mutableIntStateOf(1)}
    RemotePanel(api,"management/rfid.php?mode=history&q=${encoded(query)}&page=$page",onExpired){json->LazyColumn(contentPadding=pagePadding,verticalArrangement=Arrangement.spacedBy(14.dp)){
        item{TextButton(onClick=back){Text("Back to registration")};PageTitle("Card assignment history","Every recorded assignment, reassignment and deactivation.");OutlinedTextField(query,{query=it},label={Text("Search account, ID or card UID")},modifier=Modifier.fillMaxWidth(),singleLine=true)}
        items((json.optJSONArray("items")?:JSONArray()).objects()){row->Panel{Text(row.text("name"),style=MaterialTheme.typography.titleMedium);Text("${row.text("school_id")} · ${row.text("uid")}");StatusPill(row.text("action"));Text(row.text("created_at"));Text(row.text("notes"))}}
        item{PageControls(page,json.optInt("pages",1),json.optInt("total")){page=it}}
    }}
}
