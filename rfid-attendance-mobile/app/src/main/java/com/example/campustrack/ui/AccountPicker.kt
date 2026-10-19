package com.example.campustrack.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import org.json.JSONArray
import org.json.JSONObject

@Composable internal fun AccountPicker(api:ApiClient,onExpired:()->Unit,close:()->Unit,studentOnly:Boolean=false,selected:(JSONObject)->Unit){
    var query by remember{mutableStateOf("")}
    AlertDialog(onDismissRequest=close,title={Text(if(studentOnly)"Select student"else"Select account")},text={
        Column{
            OutlinedTextField(query,{query=it},label={Text("Search name or school ID")},singleLine=true,modifier=Modifier.fillMaxWidth())
            if(query.length<2)Text("Type at least two characters.",style=MaterialTheme.typography.bodySmall)
            else RemotePanel(api,"management/selection.php?q=${encoded(query)}"+(if(studentOnly)"&role=Student"else""),onExpired){json->
                LazyColumn(Modifier.heightIn(max=320.dp)){
                    items((json.optJSONArray("items")?:JSONArray()).objects()){person->
                        TextButton(onClick={selected(person);close()}){Column{Text(person.text("name"));Text(person.text("school_id"),style=MaterialTheme.typography.bodySmall)}}
                    }
                }
            }
        }
    },confirmButton={TextButton(onClick=close){Text("Close")}})
}
