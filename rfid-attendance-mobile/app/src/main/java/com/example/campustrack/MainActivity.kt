package com.example.campustrack

import android.os.Bundle
import android.content.Intent
import com.example.campustrack.data.CampusPush
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import com.example.campustrack.ui.CampusApp
import com.example.campustrack.ui.theme.CampusTrackTheme
import androidx.compose.runtime.remember
import androidx.compose.runtime.CompositionLocalProvider
import com.example.campustrack.data.AppPreferenceController
import com.example.campustrack.data.LocalAppPreferences

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        CampusPush.channel(this)
        if (intent.getBooleanExtra("open_notifications", false)) CampusPush.openInbox.value = true
        setContent {
            val preferences = remember { AppPreferenceController(applicationContext) }
            CompositionLocalProvider(LocalAppPreferences provides preferences) {
                CampusTrackTheme(preferences.value) { CampusApp() }
            }
        }
    }
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        if (intent.getBooleanExtra("open_notifications", false)) CampusPush.openInbox.value = true
    }
}
