package com.example.campustrack.ui

import androidx.compose.animation.*
import androidx.compose.animation.core.tween
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.testTag
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.FactCheck
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.*
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

private val destinations = listOf(
    PortalDestination("home", "Home", Icons.Outlined.SpaceDashboard),
    PortalDestination("attendance", "Attendance", Icons.AutoMirrored.Outlined.FactCheck),
    PortalDestination("schedule", "Schedule", Icons.Outlined.CalendarMonth),
    PortalDestination("more", "More", Icons.Outlined.GridView)
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun StudentApp(repository: StudentRepository, onTeacher: (() -> Unit)? = null, onSessionEnded: () -> Unit = {}, onSignedOut: () -> Unit = {}) {
    val nav = rememberNavController()
    val appPreferences = LocalAppPreferences.current
    val scope = rememberCoroutineScope()
    val snackbar = remember { SnackbarHostState() }
    val remote = repository as? RemoteStudentRepository
    var assistantOpen by remember { mutableStateOf(false) }
    if (assistantOpen) AssistantScreen(loadRecords = { repository.load() }, onSessionEnded = onSessionEnded, onClose = { assistantOpen = false })
    val entry by nav.currentBackStackEntryAsState()
    val route = entry?.destination?.route ?: "home"
    var refresh by rememberSaveable { mutableIntStateOf(0) }
    var data by remember { mutableStateOf<StudentData?>(null) }
    var failed by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(true) }
    var readIds by rememberSaveable { mutableStateOf(listOf<String>()) }
    var failureMessage by remember { mutableStateOf("Please try loading your student information again.") }
    LaunchedEffect(repository, refresh) {
        loading = true
        failed = false
        try { data = repository.load() } catch (cancelled: CancellationException) { throw cancelled } catch (failure: Exception) { if (failure is ApiException && failure.status == 401) onSessionEnded() else { failureMessage = failure.message ?: "Could not load your records."; failed = true } } finally { loading = false }
    }
    val unread = data?.messages?.count { !it.read && it.id !in readIds } ?: 0
    val isRoot = destinations.any { it.route == route }
    val selected = when {
        route in listOf("grades", "compliance", "messages", "profile", "settings") || route.startsWith("message/") -> "more"
        route.startsWith("course/") -> "attendance"
        route in listOf("home", "attendance", "schedule") -> route
        else -> "more"
    }
    suspend fun accountAction(block: suspend () -> Unit) {
        try { block() } catch (failure: ApiException) { if (failure.status == 401) onSessionEnded(); throw failure }
    }
    fun open(path: String) { if (data != null && !loading) nav.navigate(path) { launchSingleTop = true } }
    PortalScaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            PortalTopBar(initials = data?.student?.initials.orEmpty(), showBack = !isRoot, onBack = { nav.popBackStack() }, onProfile = { open("profile") },
                actions = {
                    NotificationBell(remote?.api, onSessionEnded)
                    IconButton(onClick = { open("messages") }) {
                        BadgedBox(badge = { if (unread > 0) Badge { Text(unread.toString()) } }) { Icon(Icons.Outlined.MailOutline, "Messages, $unread unread") }
                    }
                }
            )
        },
        bottomBar = {
            PortalBottomBar(destinations, selected, data != null && !loading, onNavigate = { path ->
                nav.navigate(path) {
                    popUpTo(nav.graph.findStartDestination().id) { saveState = true }
                    launchSingleTop = true
                    restoreState = true
                }
            }, onAssistant = { assistantOpen = true })
        }
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.TopCenter) {
            Column(Modifier.widthIn(max = 720.dp).fillMaxSize()) {
                PortalStatusRow("Student", loading) { refresh++ }
                // Keep the NavHost mounted through reloads so the destination and back stack survive.
                Box(Modifier.weight(1f)) {
                    val loaded = data
                    if (loaded != null) {
                        NavHost(nav, startDestination = appPreferences.value.startPage.takeIf { it in setOf("home","attendance","schedule","messages","more") } ?: "home", enterTransition = { fadeIn(tween(if(appPreferences.value.reducedMotion) 0 else 220)) + slideInHorizontally(tween(if(appPreferences.value.reducedMotion) 0 else 260)) { it / 12 } }, exitTransition = { fadeOut(tween(if(appPreferences.value.reducedMotion) 0 else 140)) }, popEnterTransition = { fadeIn(tween(if(appPreferences.value.reducedMotion) 0 else 200)) }, popExitTransition = { fadeOut(tween(if(appPreferences.value.reducedMotion) 0 else 140)) }) {
                            composable("home") { HomeScreen(loaded, ::open) }
                            composable("attendance") { AttendanceScreen(loaded) { open("course/$it") } }
                            composable("schedule") { ScheduleScreen(loaded) { open("course/$it") } }
                            composable("campus") { CampusScreen(loaded) { open("activity/$it") } }
                            composable("more") { MoreScreen(loaded.student, unread, ::open, onTeacher = onTeacher) { scope.launch { try { remote?.api?.post("auth/logout.php") } catch (_: java.io.IOException) { /* Local credentials are cleared even when offline. */ } finally { onSignedOut() } } } }
                            composable("people") { PeopleScreen(remote?.api, null, onSessionEnded) { open("person/$it") } }
                            composable("person/{id}") { detail -> DirectoryProfileScreen(remote?.api, detail.arguments?.getString("id").orEmpty(), onSessionEnded) { open("roster/$it") } }
                            composable("roster/{id}") { detail -> PeopleScreen(remote?.api, detail.arguments?.getString("id"), onSessionEnded) { open("person/$it") } }
                            composable("calendar") { StudentCalendarScreen(loaded, remote?.api, null, onSessionEnded) { open("activity/$it") } }
                            composable("attendance-calendar/{id}") { detail -> StudentCalendarScreen(loaded, remote?.api, detail.arguments?.getString("id"), onSessionEnded) { open("activity/$it") } }
                            composable("feed") { CampusFeedScreen(remote?.api, onSessionEnded) { open("person/$it") } }
                            composable("sent") { SentScreen(remote?.api, onSessionEnded) }
                            composable("grades") { GradesScreen(loaded) }
                            composable("compliance") { ComplianceScreen(loaded) }
                            composable("messages") { MessagesScreen(loaded.messages, readIds) { id ->
                                open("message/$id")
                                scope.launch { try { remote?.markRead(id); readIds = (readIds + id).distinct() } catch (cancelled: CancellationException) { throw cancelled } catch (failure: Exception) { if (failure is ApiException && failure.status == 401) onSessionEnded() else snackbar.showSnackbar(failure.message ?: "Could not mark message read.") } }
                            } }
                            composable("profile") { ProfileScreen(loaded.student, onPhoto = { bytes, mime -> accountAction { remote?.api?.uploadPhoto(bytes, mime); refresh++ } }, onSave = { value -> accountAction { remote?.updateEmail(value); refresh++ } }, onVisibility = { value -> accountAction { remote?.updateProfileVisibility(value); refresh++ } }, onPassword = { current, password, confirmation -> accountAction { remote?.changePassword(current, password, confirmation) } }) }
                            composable("settings") { remote?.api?.let { SettingsScreen(it, onSessionEnded) { open("profile") } } }
                            composable("course/{id}") { detail -> detail.arguments?.getString("id")?.let { CourseScreen(loaded.course(it), loaded, ::open) } }
                            composable("activity/{id}") { detail -> loaded.activities.find { it.id == detail.arguments?.getString("id") }?.let { ActivityScreen(it, loaded.today()) } }
                            composable("message/{id}") { detail -> loaded.messages.find { it.id == detail.arguments?.getString("id") }?.let { MessageScreen(it, it.read || it.id in readIds) { id -> open("person/$id") } } }
                        }
                    }
                    androidx.compose.animation.AnimatedVisibility(visible = loading || failed, enter = fadeIn(tween(120)), exit = fadeOut(tween(260))) {
                        Surface(Modifier.fillMaxSize().pointerInput(Unit) { detectTapGestures {} }, color = MaterialTheme.colorScheme.background) {
                        if (failed) Column(Modifier.padding(20.dp)) {
                            EmptyState("Couldn't load your portal", failureMessage, Icons.Outlined.CloudOff)
                            Button(onClick = { refresh++ }, modifier = Modifier.padding(top = 16.dp).fillMaxWidth()) { Text("Try again") }
                        } else LoadingSkeleton()
                        }
                    }
                }
            }
        }
    }
}
