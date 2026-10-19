package com.example.campustrack.ui

import android.Manifest
import android.content.Intent
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import com.example.campustrack.data.*
import kotlinx.coroutines.*

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NotificationBell(api: ApiClient?, onSessionEnded: () -> Unit) {
    if (api == null) return
    val context = LocalContext.current
    val owner = LocalLifecycleOwner.current
    val repository = remember(api) { NotificationRepository(api) }
    val preferenceController = LocalAppPreferences.current
    val scope = rememberCoroutineScope()
    var items by remember(api) { mutableStateOf(emptyList<CampusNotification>()) }
    var unread by remember { mutableIntStateOf(0) }
    var next by remember { mutableStateOf<String?>(null) }
    var open by remember { mutableStateOf(false) }
    var onlyUnread by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var pushStatus by remember { mutableStateOf<String?>(null) }
    var pushEnabled by remember { mutableStateOf(CampusPush.allowed(context)) }
    val requested by CampusPush.openInbox.collectAsState()
    val pulse by CampusPush.refresh.collectAsState()
    suspend fun load(older: Boolean = false) {
        if (loading) return
        loading = true
        try {
            val page = repository.load(if (older) next else null)
            items = if (older) (items + page.items).distinctBy { it.id } else (page.items + items.filter { old -> page.items.none { it.id == old.id } })
            unread = page.unread
            if (older || items.size <= 50) next = page.next
            error = null
        } catch (cancelled: CancellationException) { throw cancelled }
        catch (failure: Exception) { if (failure is ApiException && failure.status == 401) onSessionEnded() else error = failure.message ?: "Could not load notifications." }
        finally { loading = false }
    }
    suspend fun bindPush() {
        if (!preferenceController.value.pushEnabled) { pushEnabled = false; pushStatus = "Push notifications are disabled in Settings."; return }
        try {
            pushEnabled = CampusPush.register(context, api)
            pushStatus = if (!CampusPush.configured(context)) "Push delivery is awaiting school setup. Your in-app inbox still works." else if (pushEnabled) null else "Push is off. You can enable it in Android settings."
        } catch (cancelled: CancellationException) { throw cancelled }
        catch (failure: Exception) { pushStatus = failure.message ?: "Push registration failed. Tap Enable push to retry." }
    }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { scope.launch { bindPush() } }
    LaunchedEffect(api, owner, preferenceController.value.pushEnabled) {
        owner.lifecycle.repeatOnLifecycle(Lifecycle.State.STARTED) {
            bindPush()
            while (isActive) { load(); delay(15_000) }
        }
    }
    LaunchedEffect(pulse) { if (pulse > 0) { load(); bindPush() } }
    LaunchedEffect(requested) { if (requested) { open = true; CampusPush.openInbox.value = false; load() } }
    fun mark(selected: List<CampusNotification>) {
        if (saving) return
        scope.launch {
            saving = true
            try {
                repository.read(selected)
                items = items.map { item -> if (selected.any { it.id == item.id && it.revision == item.revision }) item.copy(read = true) else item }
                load()
            } catch (cancelled: CancellationException) { throw cancelled }
            catch (failure: Exception) { if (failure is ApiException && failure.status == 401) onSessionEnded() else error = failure.message }
            finally { saving = false }
        }
    }
    IconButton(onClick = { open = true; scope.launch { load() } }) {
        BadgedBox(badge = { if (unread > 0) Badge { Text(if (unread > 99) "99+" else unread.toString()) } }) {
            Icon(Icons.Outlined.NotificationsNone, "Notifications, $unread unread")
        }
    }
    if (open) Dialog(onDismissRequest = { open = false }, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Surface(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize().safeDrawingPadding()) {
                CenterAlignedTopAppBar(title = { Text("Notifications") }, navigationIcon = { IconButton(onClick = { open = false }) { Icon(Icons.Outlined.Close, "Close notifications") } }, actions = { IconButton(enabled = !loading, onClick = { scope.launch { load() } }) { Icon(Icons.Outlined.Refresh, "Refresh notifications") } })
                Row(Modifier.padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                    FilterChip(selected = onlyUnread, onClick = { onlyUnread = !onlyUnread }, label = { Text("Unread only") })
                    Spacer(Modifier.weight(1f))
                    TextButton(enabled = !saving && items.any { !it.read }, onClick = { mark(items.filter { !it.read }) }) { Text("Mark shown as read") }
                }
                if (pushStatus != null || !pushEnabled) Card(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp)) {
                    Column(Modifier.padding(14.dp)) {
                        Text(pushStatus ?: "Get attendance alerts and reminders even when the app is closed.", style = MaterialTheme.typography.bodySmall)
                        if (CampusPush.configured(context)) Row {
                            TextButton(onClick = {
                                if (Build.VERSION.SDK_INT >= 33 && !CampusPush.allowed(context)) permission.launch(Manifest.permission.POST_NOTIFICATIONS)
                                else scope.launch { bindPush() }
                            }) { Text("Enable push") }
                            TextButton(onClick = { context.startActivity(Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName)) }) { Text("Settings") }
                        }
                    }
                }
                error?.let { Text(it, Modifier.padding(16.dp), color = MaterialTheme.colorScheme.error) }
                LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    val visible = items.filter { !onlyUnread || !it.read }
                    if (visible.isEmpty()) item { EmptyState("You're all caught up", "Attendance warnings and upcoming activities will appear here.", Icons.Outlined.NotificationsNone) }
                    items(visible, key = { it.id }) { item ->
                        Card(Modifier.fillMaxWidth().animateContentSize(animationSpec=androidx.compose.animation.core.tween(if(LocalAppPreferences.current.value.reducedMotion)0 else 180)), colors = CardDefaults.cardColors(containerColor = if (item.read) MaterialTheme.colorScheme.surfaceContainer else MaterialTheme.colorScheme.primaryContainer)) {
                            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                    Icon(if (item.kind == "attendance") Icons.Outlined.WarningAmber else Icons.Outlined.Event, null)
                                    Text(item.title, Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
                                    if (!item.read) Badge()
                                }
                                Text(item.body, style = MaterialTheme.typography.bodyMedium)
                                Text("${item.time} · Campus time", style = MaterialTheme.typography.labelSmall)
                                if (!item.read) TextButton(enabled = !saving, onClick = { mark(listOf(item)) }) { Text("Mark as read") }
                            }
                        }
                    }
                    if (next != null) item { OutlinedButton(enabled = !loading, onClick = { scope.launch { load(true) } }, modifier = Modifier.fillMaxWidth()) { Text("Load older") } }
                }
            }
        }
    }
}
