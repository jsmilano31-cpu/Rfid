package com.example.campustrack.ui

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.animation.core.tween
import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.campustrack.data.*
import com.example.campustrack.ui.theme.CampusNavy
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import org.json.JSONObject

@Composable
fun AuthScreen(api: ApiClient, onAuthenticated: () -> Unit, sessionMessage: String? = null) {
    val reducedMotion=com.example.campustrack.data.LocalAppPreferences.current.value.reducedMotion
    var register by rememberSaveable { mutableStateOf(false) }
    var identifier by rememberSaveable { mutableStateOf("") }
    var firstName by rememberSaveable { mutableStateOf("") }
    var lastName by rememberSaveable { mutableStateOf("") }
    var email by rememberSaveable { mutableStateOf("") }
    // Passwords deliberately stay in memory only; they are not saved in instance state.
    var password by remember { mutableStateOf("") }
    var confirmation by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf(sessionMessage) }
    var registered by rememberSaveable { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    Surface(Modifier.fillMaxSize()) {
        Box(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background).safeDrawingPadding().imePadding(), contentAlignment = Alignment.TopCenter) {
            Column(Modifier.widthIn(max = 520.dp).fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp), verticalArrangement = Arrangement.spacedBy(24.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) { IconTile(Icons.Outlined.Sensors); Text("CampusTrack", style = MaterialTheme.typography.titleLarge) }
                Box(Modifier.fillMaxWidth().background(Brush.linearGradient(listOf(CampusNavy, Color(0xFF194789))), RoundedCornerShape(26.dp)).padding(26.dp)) {
                    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        Text("YOUR CAMPUS, CONNECTED", color = Color(0xFFBCD2F4), style = MaterialTheme.typography.labelSmall, letterSpacing = 1.sp)
                        Text("Show up.\nStay connected.", color = Color.White, style = MaterialTheme.typography.headlineLarge)
                        Text("Your classes, attendance, and campus life.\nAll in one place.", color = Color(0xFFD7E4F8), style = MaterialTheme.typography.bodyMedium)
                    }
                }
                AnimatedContent(targetState = register, transitionSpec = {fadeIn(tween(if(reducedMotion)0 else 180)) togetherWith fadeOut(tween(if(reducedMotion)0 else 180))}, label = "Authentication mode") { creating ->
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(if (creating) "Join your campus" else "Welcome back", style = MaterialTheme.typography.headlineMedium)
                        Text(if (creating) "Create a student account for school approval." else "Sign in with your school account.", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
                if (registered) Surface(color = MaterialTheme.colorScheme.secondaryContainer, shape = RoundedCornerShape(16.dp)) { Text("Registration received. Your school must activate your account and assign your academic details. You can sign in after approval.", Modifier.padding(16.dp)) }
                Column(Modifier.animateContentSize(animationSpec=tween(if(com.example.campustrack.data.LocalAppPreferences.current.value.reducedMotion)0 else 180)), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    if (register) {
                        OutlinedTextField(firstName, { firstName = it }, Modifier.fillMaxWidth(), enabled = !busy, label = { Text("First name") }, singleLine = true)
                        OutlinedTextField(lastName, { lastName = it }, Modifier.fillMaxWidth(), enabled = !busy, label = { Text("Last name") }, singleLine = true)
                    }
                    OutlinedTextField(identifier, { identifier = it }, Modifier.fillMaxWidth(), enabled = !busy, label = { Text(if (register) "School ID" else "School ID or email") }, leadingIcon = { Icon(Icons.Outlined.Badge, null) }, singleLine = true)
                    if (register) OutlinedTextField(email, { email = it }, Modifier.fillMaxWidth(), enabled = !busy, label = { Text("Email address") }, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), singleLine = true)
                    PasswordField("Password", password, { password = it }, !busy)
                    if (register) PasswordField("Confirm password", confirmation, { confirmation = it }, !busy)
                    if (error != null) Text(error!!, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
                    Button(onClick = {
                        error = null
                        if (identifier.isBlank() || password.isBlank() || register && (firstName.isBlank() || lastName.isBlank() || email.isBlank())) { error = "Please complete all fields."; return@Button }
                        if (register && (password.toByteArray().size !in 8..72 || confirmation != password)) { error = "Use an 8–72 byte password and make sure both passwords match."; return@Button }
                        busy = true
                        scope.launch {
                            try {
                                if (register) {
                                    api.post("auth/register.php", JSONObject().put("school_id", identifier.trim()).put("first_name", firstName.trim()).put("last_name", lastName.trim()).put("email", email.trim()).put("password", password).put("password_confirmation", confirmation))
                                    registered = true; register = false; password = ""; confirmation = ""
                                } else {
                                    api.post("auth/login.php", JSONObject().put("identifier", identifier.trim()).put("password", password))
                                    password = ""; onAuthenticated()
                                }
                            } catch (cancelled: CancellationException) { throw cancelled } catch (failure: Exception) { error = failure.message ?: "Could not complete your request." } finally { busy = false }
                        }
                    }, enabled = !busy, modifier = Modifier.fillMaxWidth().height(54.dp), shape = RoundedCornerShape(16.dp)) {
                        if (busy) CircularProgressIndicator(Modifier.size(22.dp), color = MaterialTheme.colorScheme.onPrimary, strokeWidth = 2.dp) else Text(if (register) "Create student account" else "Sign in")
                    }
                    TextButton(onClick = { register = !register; error = null; password = ""; confirmation = ""; registered = false }, enabled = !busy, modifier = Modifier.fillMaxWidth()) { Text(if (register) "Already registered? Sign in" else "New student? Create an account") }
                }
                Text("Need help signing in? Contact your school office to recover or activate your account.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
fun PasswordField(label: String, value: String, onChange: (String) -> Unit, enabled: Boolean = true) {
    var visible by remember { mutableStateOf(false) }
    OutlinedTextField(value, onChange, Modifier.fillMaxWidth(), enabled = enabled, label = { Text(label) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password), visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(), trailingIcon = { IconButton(onClick = { visible = !visible }) { Icon(if (visible) Icons.Outlined.VisibilityOff else Icons.Outlined.Visibility, if (visible) "Hide $label" else "Show $label") } })
}
