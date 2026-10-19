package com.example.campustrack.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

@Composable
fun SettingsScreen(api: ApiClient, onSessionEnded: () -> Unit,onSms:(()->Unit)?=null, onAccount: () -> Unit) {
    val controller = LocalAppPreferences.current
    var settings by remember { mutableStateOf(controller.value) }
    var busy by remember { mutableStateOf(false) }
    var message by remember { mutableStateOf<String?>(null) }
    var passwordDialog by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(api) {
        try { settings = AppPreferences.from(api.get("student/settings.php"), settings); controller.apply(settings) }
        catch (failure: ApiException) { if (failure.status == 401) onSessionEnded() else message = failure.message }
    }
    fun update(next: AppPreferences) { settings = next; controller.apply(next); message = null }
    fun save() { scope.launch { busy = true; try { settings = AppPreferences.from(api.post("student/settings.php", settings.json()), settings); controller.apply(settings); message = "Settings saved across web and mobile." } catch (cancelled: CancellationException) { throw cancelled } catch (failure: ApiException) { if (failure.status == 401) onSessionEnded() else message = failure.message } finally { busy = false } } }
    LazyColumn(contentPadding = pagePadding, verticalArrangement = Arrangement.spacedBy(18.dp)) {
        item { PageTitle("Settings", "Choose how CampusTrack opens, looks, and keeps you informed.") }
        item { SettingsPanel("General", Icons.Outlined.Tune) {
            Text("Default start page", style = MaterialTheme.typography.titleMedium)
            SingleChoiceSegment(listOf("home" to "Home", "attendance" to "Attendance", "schedule" to "Schedule", "messages" to "Messages", "more" to "More").filter{nativeRouteAllowed(it.first,api.permissions,api.roles,api.capabilities.optBoolean("can_manage_sms"))}, settings.startPage) { update(settings.copy(startPage=it)) }
            SettingSwitch("Compact layout", "Fit more information on each screen.", settings.compactMode) { update(settings.copy(compactMode=it)) }
        } }
        item { SettingsPanel("Notifications", Icons.Outlined.NotificationsNone) {
            SettingSwitch("Push notifications", "Receive alerts while the app is closed.", settings.pushEnabled) { update(settings.copy(pushEnabled=it)) }
            SettingSwitch("Attendance alerts", "Late, absent, and corrected records.", settings.notifyAttendance) { update(settings.copy(notifyAttendance=it)) }
            SettingSwitch("Events", "Upcoming events and schedule changes.", settings.notifyEvents) { update(settings.copy(notifyEvents=it)) }
            SettingSwitch("Recollections", "Assignments and reminders.", settings.notifyRecollections) { update(settings.copy(notifyRecollections=it)) }
            SettingSwitch("Messages", "New direct and campus messages.", settings.notifyMessages) { update(settings.copy(notifyMessages=it)) }
        } }
        item { SettingsPanel("Appearance & customization", Icons.Outlined.Palette) {
            Text("Display mode", style = MaterialTheme.typography.titleMedium)
            SingleChoiceSegment(listOf("system" to "System", "light" to "Light", "dark" to "Dark"), settings.themeMode) { update(settings.copy(themeMode=it)) }
            Text("Accent color", style = MaterialTheme.typography.titleMedium)
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                listOf("blue" to Color(0xFF175CD3),"green" to Color(0xFF087A55),"purple" to Color(0xFF6941C6),"orange" to Color(0xFFC45118)).forEach { (name,color) ->
                    FilterChip(selected=settings.accentTheme==name,onClick={update(settings.copy(accentTheme=name))},label={Box(Modifier.size(20.dp).background(color,CircleShape))})
                }
            }
            SettingSwitch("Reduce motion", "Minimize transitions and animated effects.", settings.reducedMotion) { update(settings.copy(reducedMotion=it)) }
        } }
        item { SettingsPanel("Privacy & account", Icons.Outlined.Shield) {
            if("Administrator" !in api.roles&&api.can("manage_own_profile"))SettingSwitch("Public profile", "Allow campus users to open your profile. School functions remain available.", settings.profilePublic) { update(settings.copy(profilePublic=it)) }
            if(api.can("manage_own_profile"))OutlinedButton(onClick={if("Administrator" in api.roles)passwordDialog=true else onAccount()}, modifier=Modifier.fillMaxWidth()) { Icon(Icons.Outlined.ManageAccounts,null); Spacer(Modifier.width(8.dp)); Text(if("Administrator" in api.roles)"Change password"else"Manage account and password") }
        } }
        if(onSms!=null&&api.capabilities.optBoolean("can_manage_sms"))item{SettingsPanel("SMS",Icons.Outlined.Sms){Text("Manage campus delivery, sender, notice threshold and templates.");OutlinedButton(onClick=onSms,modifier=Modifier.fillMaxWidth()){Text("Open SMS settings")}}}
        item { message?.let { Text(it, color=if(it.startsWith("Settings saved")) MaterialTheme.colorScheme.secondary else MaterialTheme.colorScheme.error) }; Button(onClick=::save, enabled=!busy, modifier=Modifier.fillMaxWidth()) { if(busy) CircularProgressIndicator(Modifier.size(20.dp),strokeWidth=2.dp) else Text("Save settings") } }
    }
    if(passwordDialog)NativeEditor("Change password",listOf(NativeField("current_password","Current password",type="secret",required=true),NativeField("password","New password",type="secret",required=true),NativeField("password_confirmation","Confirm new password",type="secret",required=true)),onSessionEnded,{passwordDialog=false}){input->api.post("auth/password.php",input)}
}

@Composable private fun SettingsPanel(title:String, icon:androidx.compose.ui.graphics.vector.ImageVector, content:@Composable ColumnScope.()->Unit)=Panel { Row(verticalAlignment=Alignment.CenterVertically,horizontalArrangement=Arrangement.spacedBy(10.dp)){IconTile(icon);Text(title,style=MaterialTheme.typography.titleLarge)}; content() }
@Composable private fun SettingSwitch(title:String, subtitle:String, checked:Boolean, change:(Boolean)->Unit){ Row(Modifier.fillMaxWidth(),verticalAlignment=Alignment.CenterVertically){Column(Modifier.weight(1f)){Text(title,style=MaterialTheme.typography.titleSmall);Text(subtitle,style=MaterialTheme.typography.bodySmall,color=MaterialTheme.colorScheme.onSurfaceVariant)};Switch(checked=checked,onCheckedChange=change)} }
@Composable private fun SingleChoiceSegment(values:List<Pair<String,String>>,selected:String,change:(String)->Unit){FlowRow(horizontalArrangement=Arrangement.spacedBy(8.dp),verticalArrangement=Arrangement.spacedBy(8.dp)){values.forEach{(value,label)->FilterChip(selected=selected==value,onClick={change(value)},label={Text(label)})}}}
