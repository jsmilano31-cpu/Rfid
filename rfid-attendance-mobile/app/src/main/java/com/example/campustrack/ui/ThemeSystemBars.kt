package com.example.campustrack.ui

import android.app.Activity
import android.content.ContextWrapper
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat

/** System icon contrast follows the chosen app theme, including live preference changes. */
@Composable internal fun ThemeSystemBars() {
    val view=LocalView.current
    val light=MaterialTheme.colorScheme.background.luminance()>.4f
    SideEffect {
        val activity=generateSequence(view.context){(it as? ContextWrapper)?.baseContext}.filterIsInstance<Activity>().firstOrNull()
        if(activity!=null)WindowCompat.getInsetsController(activity.window,view).apply {
            isAppearanceLightStatusBars=light
            isAppearanceLightNavigationBars=light
        }
    }
}
