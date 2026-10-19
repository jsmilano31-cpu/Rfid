package com.example.campustrack.ui

import androidx.compose.runtime.*
import com.example.campustrack.data.*
import org.json.JSONArray
import org.json.JSONObject

/** Read a fresh authoritative profile when entering; settings/privacy never reuse a portal snapshot. */
@Composable internal fun NativeOwnerProfile(api: ApiClient, onExpired: () -> Unit, changed: () -> Unit) {
    var revision by remember { mutableIntStateOf(0) }
    val preferences=LocalAppPreferences.current
    fun refresh(){revision++;changed()}
    RemotePanel(api,"student/profile.php?revision=$revision",onExpired){profile->
        val roles=profile.optJSONArray("roles")?:JSONArray()
        val card=profile.optJSONObject("rfid")
        val student=Student(
            listOf("first_name","middle_name","last_name").map{profile.text(it)}.filter{it.isNotBlank()}.joinToString(" "),
            profile.text("school_id"),profile.text("email"),profile.text("program").ifBlank{"Not assigned"},profile.text("section").ifBlank{"Not assigned"},profile.text("department").ifBlank{"Not assigned"},
            (0 until roles.length()).map{roles.getString(it)},profile.text("profile_image_url").takeIf{it.isNotBlank()},card?.text("masked_uid"),card?.text("status"),profile.optBoolean("is_profile_public",true)
        )
        SideEffect{if(preferences.value.profilePublic!=student.isProfilePublic)preferences.apply(preferences.value.copy(profilePublic=student.isProfilePublic))}
        ProfileScreen(student,
            onPhoto={bytes,mime->api.uploadPhoto(bytes,mime);refresh()},
            onSave={email->api.post("student/profile-update.php",JSONObject().put("email",email));refresh()},
            onVisibility={value->api.post("student/profile-visibility.php",JSONObject().put("is_profile_public",value));refresh()},
            onPassword={old,new,confirmation->api.post("auth/password.php",JSONObject().put("current_password",old).put("password",new).put("password_confirmation",confirmation))}
        )
    }
}
