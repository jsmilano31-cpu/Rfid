package com.example.campustrack.ui

import android.content.Context
import android.print.PrintManager
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.foundation.layout.Column
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.platform.LocalContext
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/** The print renderer receives escaped report cells; it never opens a remote application page. */
@Composable internal fun PrintRecords(api:ApiClient,path:String,onExpired:()->Unit){
    val context=LocalContext.current
    val scope=rememberCoroutineScope()
    var busy by remember{mutableStateOf(false)}
    var error by remember{mutableStateOf<String?>(null)}
    Column {
        OutlinedButton(enabled=!busy,onClick={scope.launch{
            busy=true;error=null
            try {
                val csv=api.download(path,2_097_152).toString(Charsets.UTF_8)
                val html="<html><head><meta charset='utf-8'><style>body{font:12px sans-serif;color:#17212f}table{border-collapse:collapse;width:100%}td{border:1px solid #d5dce5;padding:7px;vertical-align:top}tr:nth-child(even){background:#f3f5f8}h1{font-size:22px}</style></head><body><h1>CampusTrack records</h1><table>"+
                    csvRecords(csv).joinToString(""){row->"<tr>"+row.joinToString(""){"<td>"+android.text.TextUtils.htmlEncode(it)+"</td>"}+"</tr>"}+"</table></body></html>"
                val view=WebView(context)
                view.settings.javaScriptEnabled=false
                view.webViewClient=object:WebViewClient(){override fun onPageFinished(web:WebView,url:String){
                    (context.getSystemService(Context.PRINT_SERVICE) as PrintManager).print("CampusTrack records",web.createPrintDocumentAdapter("CampusTrack records"),null)
                    busy=false
                }}
                view.loadDataWithBaseURL(null,html,"text/html","utf-8",null)
            } catch(c:CancellationException){throw c}
            catch(f:Exception){if(f is ApiException&&f.status==401)onExpired()else error=if(f is IllegalArgumentException)"This report is too large to print on this device. Refine the period or export the complete CSV."else f.message;busy=false}
        }}) {Text(if(busy)"Preparing print…"else"Print view")}
        error?.let{Text(it,color=MaterialTheme.colorScheme.error)}
    }
}

internal fun csvRecords(text:String):List<List<String>> {
    val rows=mutableListOf<List<String>>()
    var row=mutableListOf<String>()
    val field=StringBuilder()
    var quoted=false
    var i=0
    val csv=text.removePrefix("\uFEFF")
    while(i<csv.length){val c=csv[i]
        when {
            c=='"'&&quoted&&i+1<csv.length&&csv[i+1]=='"'->{field.append('"');i++}
            c=='"'->quoted=!quoted
            c==','&&!quoted->{row.add(field.toString());field.clear()}
            c=='\n'&&!quoted->{row.add(field.toString().trimEnd('\r'));field.clear();rows.add(row);row=mutableListOf()}
            else->field.append(c)
        };i++
    }
    if(field.isNotEmpty()||row.isNotEmpty()){row.add(field.toString());rows.add(row)}
    return rows
}
