package com.example.campustrack.ui

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.*
import kotlinx.coroutines.CancellationException

@Composable
fun CampusApp() {
    ThemeSystemBars()
    val context = LocalContext.current.applicationContext
    val api = remember { ApiClient(context) }
    val repository = remember { RemoteStudentRepository(api) }
    val preferenceController = LocalAppPreferences.current
    var state by remember { mutableStateOf("checking") }
    var error by remember { mutableStateOf<String?>(null) }
    var retry by remember { mutableIntStateOf(0) }
    LaunchedEffect(retry) {
        state = "checking"
        try {
            state = if (api.session()) {
                runCatching { preferenceController.apply(AppPreferences.from(api.get("student/settings.php"), preferenceController.value)) }
                "portal"
            } else "signed_out"
        }
        catch (cancelled: CancellationException) { throw cancelled }
        catch (failure: Exception) { error = failure.message; state = "error" }
    }
    when (state) {
        "checking" -> Surface(Modifier.fillMaxSize().safeDrawingPadding()) { LoadingSkeleton() }
        "error" -> Surface(Modifier.fillMaxSize()) { Column(Modifier.safeDrawingPadding().padding(24.dp), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
            EmptyState("Let's reconnect", error ?: "The campus service is unavailable.")
            Button(onClick = { retry++ }, Modifier.padding(top = 16.dp)) { Text("Try again") }
            TextButton(onClick = { state = "signed_out" }) { Text("Go to sign in") }
        } }
        "signed_out" -> AuthScreen(api, onAuthenticated = { error = null; retry++ }, sessionMessage = error)
        else -> NativePortalApp(api, onSessionEnded = { api.clearSession(); error = "Please sign in again."; state = "signed_out" }, onSignedOut = { api.clearSession(); error = null; state = "signed_out" })
    }
}
