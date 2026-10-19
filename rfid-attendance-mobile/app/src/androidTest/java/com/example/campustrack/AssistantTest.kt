package com.example.campustrack

import android.content.Intent
import android.os.Build
import android.os.SystemClock
import android.view.accessibility.AccessibilityNodeInfo
import androidx.activity.compose.setContent
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.example.campustrack.data.nativeAiSupported
import com.example.campustrack.ui.AssistantScreen
import com.example.campustrack.ui.theme.CampusTrackTheme
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

/** Uses platform accessibility, avoiding Espresso's Android 17 InputManager issue. */
@RunWith(AndroidJUnit4::class)
class AssistantTest {
    @Test fun unsupportedEmulatorCannotChatOrUseNonAiFallback() {
        org.junit.Assume.assumeFalse(nativeAiSupported(Build.SUPPORTED_ABIS.firstOrNull()))
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val activity = instrumentation.startActivitySync(Intent(instrumentation.targetContext, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
        try {
            instrumentation.runOnMainSync {
                activity.setContent {
                    ParityTestTheme { AssistantScreen(loadRecords = { error("Must not fetch records while AI unavailable") }, onSessionEnded = {}, onClose = {}) }
                }
            }
            val deadline = SystemClock.uptimeMillis() + 10_000
            var nodes = emptyList<AccessibilityNodeInfo>()
            while (SystemClock.uptimeMillis() < deadline) {
                val root = instrumentation.uiAutomation.rootInActiveWindow
                fun flatten(node: AccessibilityNodeInfo): List<AccessibilityNodeInfo> = listOf(node) + (0 until node.childCount).flatMap { i -> node.getChild(i)?.let { flatten(it) }.orEmpty() }
                nodes = root?.let { flatten(it) }.orEmpty()
                if (nodes.any { it.contentDescription?.toString() == "Send question" }) break
                SystemClock.sleep(100)
            }
            assertTrue(nodes.any { it.text?.toString() == "AI unavailable on this emulator" })
            val send = nodes.first { it.contentDescription?.toString() == "Send question" }
            // Compose exposes the decorative icon separately from its disabled button.
            assertTrue("Send button must be disabled", generateSequence(send) { it.parent }.any { !it.isEnabled })
            val input = nodes.first { it.className?.toString() == "android.widget.EditText" }
            assertTrue("Composer must be disabled", generateSequence(input) { it.parent }.any { !it.isEnabled })
            assertFalse(nodes.any { it.text?.toString() in listOf("My records", "School records", "Web search") })
            instrumentation.uiAutomation.takeScreenshot()?.let { bitmap ->
                java.io.File(instrumentation.targetContext.cacheDir, "assistant-unavailable.png").outputStream().use { bitmap.compress(android.graphics.Bitmap.CompressFormat.PNG, 100, it) }
            }
        } finally { instrumentation.runOnMainSync { activity.finish() } }
    }
}
