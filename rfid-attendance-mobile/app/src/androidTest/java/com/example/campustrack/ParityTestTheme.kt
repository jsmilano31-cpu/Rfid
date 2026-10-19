package com.example.campustrack

import androidx.compose.runtime.*
import androidx.compose.ui.platform.LocalContext
import com.example.campustrack.data.*
import com.example.campustrack.ui.theme.CampusTrackTheme

@Composable internal fun ParityTestTheme(darkTheme:Boolean?=null,content:@Composable ()->Unit){
    val context=LocalContext.current
    val prefs=remember{AppPreferenceController(context).apply{apply(AppPreferences(reducedMotion=true,pushEnabled=false))}}
    CompositionLocalProvider(LocalAppPreferences provides prefs){CampusTrackTheme(prefs.value,darkTheme,content)}
}
