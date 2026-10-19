package com.example.campustrack.ui

import androidx.compose.foundation.gestures.rememberTransformableState
import androidx.compose.foundation.gestures.transformable
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import org.json.JSONObject

@Composable internal fun PrivateAttachmentPreview(api:ApiClient,file:JSONObject,onExpired:()->Unit,close:()->Unit){
    var bytes by remember{mutableStateOf<ByteArray?>(null)};var error by remember{mutableStateOf<String?>(null)};var scale by remember{mutableFloatStateOf(1f)}
    LaunchedEffect(file.text("id")){try{bytes=api.download("workspace/attachment.php?id=${file.text("id")}")}catch(c:CancellationException){throw c}catch(f:Exception){if(f is ApiException&&f.status==401)onExpired()else error=f.message}}
    val zoom=rememberTransformableState{factor,_,_->scale=(scale*factor).coerceIn(1f,4f)}
    Dialog(onDismissRequest=close,properties=DialogProperties(usePlatformDefaultWidth=false)){Surface(Modifier.fillMaxSize(),color=MaterialTheme.colorScheme.background){Column(Modifier.safeDrawingPadding().padding(16.dp)){
        Text(file.text("original_name"),style=MaterialTheme.typography.titleMedium);Row{TextButton(onClick={scale=1f}){Text("Reset zoom")};TextButton(onClick=close){Text("Close preview")}}
        error?.let{Text(it,color=MaterialTheme.colorScheme.error)}
        if(bytes==null&&error==null)LinearProgressIndicator(Modifier.fillMaxWidth())
        else bytes?.let{data->coil.compose.AsyncImage(data,"Attachment preview. Pinch to zoom.",modifier=Modifier.fillMaxWidth().weight(1f).transformable(zoom).graphicsLayer{scaleX=scale;scaleY=scale},contentScale=ContentScale.Fit)}
    }}}
}
