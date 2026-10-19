package com.example.campustrack.ui

import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Send
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.example.campustrack.data.*
import kotlinx.coroutines.*

private data class ChatLine(val text: String, val user: Boolean = false, val hits: List<WebHit> = emptyList())

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AssistantScreen(loadRecords: suspend () -> StudentData, teacher: Boolean = false, onSessionEnded: () -> Unit, onClose: () -> Unit) {
    val context = LocalContext.current.applicationContext
    val engine = remember { LocalAssistant(context) }
    val scope = rememberCoroutineScope()
    val uri = LocalUriHandler.current
    var source by remember { mutableStateOf("None") }
    var ready by remember { mutableStateOf(false) }
    var issue by remember { mutableStateOf<String?>(null) }
    var input by remember { mutableStateOf("") }
    var messages by remember { mutableStateOf(emptyList<ChatLine>()) }
    var job by remember { mutableStateOf<Job?>(null) }
    var busy by remember { mutableStateOf(false) }
    var progress by remember { mutableFloatStateOf(0f) }
    var installed by remember { mutableStateOf(engine.installed) }
    var status by remember { mutableStateOf("") }
    var localHistory by remember { mutableStateOf(listOf<String>()) }
    val listState = androidx.compose.foundation.lazy.rememberLazyListState()
    LaunchedEffect(messages.size, busy) { listState.animateScrollToItem((messages.size - 1).coerceAtLeast(0)) }
    fun runTask(block: suspend () -> Unit) {
        busy = true
        issue = null
        job = scope.launch {
            try { block() }
            catch (cancelled: CancellationException) { throw cancelled }
            catch (failure: Exception) {
                if (failure is ApiException && failure.status == 401) onSessionEnded()
                else issue = failure.message ?: "Something went wrong. Please try again."
            } catch (_: LinkageError) { ready = false; issue = "The local AI runtime could not start on this device. Chat is disabled." }
            finally { busy = false; status = ""; installed = engine.installed }
        }
    }
    suspend fun prepare() {
        ready = false
        status = "Starting local AI..."
        engine.initialize()
        currentCoroutineContext().ensureActive()
        ready = true
    }
    LaunchedEffect(Unit) {
        if (engine.supported && installed) runTask { prepare() }
    }
    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
        Surface(Modifier.fillMaxSize()) {
            Column(Modifier.fillMaxSize().safeDrawingPadding().imePadding()) {
                CenterAlignedTopAppBar(title = { Column(horizontalAlignment = Alignment.CenterHorizontally) { Text("Campus Assistant"); Text("Local intelligence · Your campus", style = MaterialTheme.typography.labelSmall) } }, navigationIcon = { IconButton(onClick = onClose) { Icon(Icons.Outlined.Close, "Close assistant") } }, actions = { IconButton(enabled = !busy, onClick = { messages = emptyList(); localHistory = emptyList() }) { Icon(Icons.Outlined.DeleteSweep, "Clear conversation") } })
                Card(Modifier.fillMaxWidth().padding(horizontal = 16.dp).animateContentSize()) {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        when {
                            !engine.supported -> {
                                Text("AI unavailable on this emulator", style = MaterialTheme.typography.titleMedium)
                                Text("This build needs a native ARM64 device. Your x86 emulator's ARM translation is not supported. Connect an ARM64 Android phone to use the chatbot.")
                            }
                            !installed -> {
                                Text("Set up your private assistant", style = MaterialTheme.typography.titleMedium)
                                Text("Download Qwen3 0.6B (429 MB). Chat becomes available after the model starts successfully. Keep this screen open; Wi-Fi recommended.")
                                Button(enabled = !busy, onClick = { runTask {
                                    ready = false; progress = 0f; status = "Downloading model..."
                                    engine.download { progress = it }; installed = engine.installed; prepare()
                                } }) { Text("Download and start AI") }
                            }
                            else -> {
                                Text(if (ready) "AI ready - Runs on your device" else "AI is not ready", style = MaterialTheme.typography.titleMedium)
                                Row {
                                    if (!ready) TextButton(enabled = !busy, onClick = { runTask { prepare() } }) { Text("Retry starting AI") }
                                    TextButton(enabled = !busy, onClick = { runTask { ready = false; engine.delete(); messages = emptyList(); localHistory = emptyList() } }) { Text("Remove model") }
                                }
                            }
                        }
                        if (status.startsWith("Downloading")) LinearProgressIndicator(progress = { progress }, modifier = Modifier.fillMaxWidth())
                        issue?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                    }
                }
                if (ready) {
                    Text("Attach a source for this question", Modifier.padding(start = 20.dp, top = 12.dp), style = MaterialTheme.typography.labelMedium)
                    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        listOf("None", "School records", "Web search").forEach { name ->
                            FilterChip(selected = source == name, enabled = !busy, onClick = { source = name }, label = { Text(name) })
                        }
                    }
                    Text(when (source) {
                        "School records" -> "Fetches your authorized records for the local AI. Internet required."
                        "Web search" -> "Your next message is sent to Wikipedia as a public search query. Do not include personal information."
                        else -> "General AI conversation works offline. Attach a source for school or web questions."
                    }, Modifier.padding(horizontal = 20.dp), style = MaterialTheme.typography.bodySmall)
                }
                LazyColumn(state = listState, modifier = Modifier.weight(1f), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    items(messages) { line ->
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = if (line.user) Arrangement.End else Arrangement.Start) {
                            Surface(shape = MaterialTheme.shapes.large, color = if (line.user) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceContainer, modifier = Modifier.widthIn(max = 480.dp).animateContentSize()) {
                                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                                    androidx.compose.foundation.text.selection.SelectionContainer { Text(line.text) }
                                    line.hits.forEach { hit -> OutlinedCard(onClick = { uri.openUri(hit.url) }) { Column(Modifier.padding(12.dp)) { Text(hit.title, style = MaterialTheme.typography.titleSmall); Text(hit.snippet, style = MaterialTheme.typography.bodySmall); Text("Read on Wikipedia ↗", color = MaterialTheme.colorScheme.primary) } } }
                                }
                            }
                        }
                    }
                }
                if (busy) Row(Modifier.fillMaxWidth().padding(horizontal = 20.dp), verticalAlignment = Alignment.CenterVertically) {
                    CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                    Text(status, Modifier.weight(1f).padding(12.dp), style = MaterialTheme.typography.bodySmall)
                    TextButton(onClick = { job?.cancel(); status = "Stopping…" }) { Text("Stop") }
                }
                Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(value = input, onValueChange = { input = it.take(700) }, enabled = ready && !busy, modifier = Modifier.weight(1f), placeholder = { Text(if (ready) "Ask a question..." else "AI must be ready to chat") }, maxLines = 4, shape = MaterialTheme.shapes.extraLarge)
                    FilledIconButton(enabled = ready && !busy && input.isNotBlank(), onClick = {
                        val question = input.trim(); input = ""; messages = (messages + ChatLine(question, true)).takeLast(40)
                        runTask {
                            check(ready) { "AI is unavailable. Start the model first." }
                            var hits = emptyList<WebHit>()
                            val evidence = when (source) {
                                "School records" -> { status = "Fetching your records..."; assistantEvidence(loadRecords(), teacher) }
                                "Web search" -> {
                                    status = "Searching Wikipedia..."
                                    hits = engine.search(question)
                                    hits.joinToString("\n") { "${it.title}: ${it.snippet} (${it.url})" }.ifBlank { "No search results found. Do not invent results." }
                                }
                                else -> "No reference data attached."
                            }
                            status = "Thinking on this device..."
                            val answer = try { engine.answer(question, evidence, localHistory.takeLast(2).joinToString("\n")) }
                                catch (cancelled: CancellationException) { throw cancelled }
                                catch (failure: Exception) { ready = false; throw failure }
                            localHistory = (localHistory + "User: $question\nAssistant: $answer").takeLast(2)
                            val reply = ChatLine(answer + when(source) {
                                "School records" -> "\n\nSource: CampusTrack - fetched for this question"
                                "Web search" -> "\n\nSource: Wikipedia search results"
                                else -> ""
                            }, hits = hits)
                            currentCoroutineContext().ensureActive()
                            messages = messages + reply
                        }
                    }) { Icon(Icons.AutoMirrored.Outlined.Send, "Send question") }
                }
            }
        }
    }
}
