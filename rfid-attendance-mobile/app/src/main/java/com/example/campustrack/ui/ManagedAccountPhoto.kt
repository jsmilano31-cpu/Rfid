package com.example.campustrack.ui

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.platform.LocalContext
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject

@Composable internal fun ManagedAccountPhoto(api: ApiClient, id: String, onExpired: () -> Unit) {
    val context=LocalContext.current;val scope=rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) };var message by remember { mutableStateOf<String?>(null) }
    val pick=rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if(uri!=null)scope.launch {
            busy=true;message=null
            try {
                val bytes=withContext(Dispatchers.IO){context.contentResolver.openInputStream(uri)?.use{it.readLimited(2_097_152)}?:error("Could not read the image.")}
                api.upload("management/user-photo.php",JSONObject().put("user_id",id.toLong()),listOf(UploadFile("profile-image",context.contentResolver.getType(uri)?:"image/jpeg",bytes)),"profile_image")
                message="Profile picture updated."
            }catch(c:CancellationException){throw c}catch(f:Exception){if(f is ApiException&&f.status==401)onExpired()else message=f.message}finally{busy=false}
        }
    }
    TextButton(enabled=!busy,onClick={pick.launch(arrayOf("image/jpeg","image/png","image/webp"))}){Text(if(busy)"Updating photo…"else"Change account photo")}
    message?.let{Text(it,style=MaterialTheme.typography.bodySmall)}
}
