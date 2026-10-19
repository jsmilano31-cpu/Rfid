package com.example.campustrack.data

import android.content.Context
import androidx.compose.runtime.*
import org.json.JSONObject

data class AppPreferences(
    val startPage: String = "home", val themeMode: String = "system", val accentTheme: String = "blue",
    val reducedMotion: Boolean = false, val compactMode: Boolean = false, val pushEnabled: Boolean = true,
    val notifyAttendance: Boolean = true, val notifyEvents: Boolean = true,
    val notifyRecollections: Boolean = true, val notifyMessages: Boolean = true,
    val profilePublic: Boolean = true
) {
    fun json() = JSONObject().put("start_page", startPage).put("theme_mode", themeMode).put("accent_theme", accentTheme)
        .put("reduced_motion", reducedMotion).put("compact_mode", compactMode).put("push_enabled", pushEnabled)
        .put("notify_attendance", notifyAttendance).put("notify_events", notifyEvents)
        .put("notify_recollections", notifyRecollections).put("notify_messages", notifyMessages).put("profile_public", profilePublic)

    companion object {
        fun from(data: JSONObject, fallback: AppPreferences = AppPreferences()): AppPreferences {
            val settings = data.optJSONObject("settings") ?: data
            return AppPreferences(
                settings.optString("start_page", fallback.startPage), settings.optString("theme_mode", fallback.themeMode), settings.optString("accent_theme", fallback.accentTheme),
                settings.optBoolean("reduced_motion", fallback.reducedMotion), settings.optBoolean("compact_mode", fallback.compactMode), settings.optBoolean("push_enabled", fallback.pushEnabled),
                settings.optBoolean("notify_attendance", fallback.notifyAttendance), settings.optBoolean("notify_events", fallback.notifyEvents),
                settings.optBoolean("notify_recollections", fallback.notifyRecollections), settings.optBoolean("notify_messages", fallback.notifyMessages),
                data.optBoolean("profile_public", fallback.profilePublic)
            )
        }
    }
}

@Stable
class AppPreferenceController(private val context: Context) {
    var value by mutableStateOf(load())
        private set
    fun apply(next: AppPreferences) { value = next; save(next) }
    private fun load(): AppPreferences = runCatching {
        val raw = context.getSharedPreferences("campus_preferences", Context.MODE_PRIVATE).getString("value", null) ?: return@runCatching AppPreferences()
        AppPreferences.from(JSONObject(raw))
    }.getOrDefault(AppPreferences())
    private fun save(next: AppPreferences) { context.getSharedPreferences("campus_preferences", Context.MODE_PRIVATE).edit().putString("value", next.json().toString()).apply() }
}

val LocalAppPreferences = staticCompositionLocalOf<AppPreferenceController> { error("App preferences are unavailable") }
