package com.example.campustrack

import android.content.Intent
import android.graphics.Rect
import android.os.SystemClock
import android.view.accessibility.AccessibilityNodeInfo
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.ui.Modifier
import androidx.compose.runtime.mutableStateOf
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.example.campustrack.ui.*
import com.example.campustrack.ui.theme.CampusTrackTheme
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.util.concurrent.atomic.AtomicBoolean

@RunWith(AndroidJUnit4::class)
class PortalChromeTest {
    @Test fun assistantIsFullyVisibleAndClickableForBothRoles() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val activity = instrumentation.startActivitySync(Intent(instrumentation.targetContext, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
        fun flatten(node: AccessibilityNodeInfo): List<AccessibilityNodeInfo> = listOf(node) + (0 until node.childCount).flatMap { node.getChild(it)?.let { child -> flatten(child) }.orEmpty() }
        try {
            for (role in listOf("Student", "Teacher")) for (dark in listOf(false, true)) {
                val clicked = AtomicBoolean(false)
                val selected = mutableStateOf("home")
                instrumentation.runOnMainSync {
                    activity.setContent {
                        ParityTestTheme(darkTheme = dark) {
                            PortalScaffold(topBar = { PortalTopBar("JD", false, {}, {}) {} }, bottomBar = {
                                PortalBottomBar(listOf(
                                    PortalDestination("home", if (role == "Teacher") "Classes" else "Home", Icons.Outlined.SpaceDashboard),
                                    PortalDestination("schedule", "Schedule", Icons.Outlined.CalendarMonth),
                                    PortalDestination("messages", "Inbox", Icons.Outlined.MailOutline),
                                    PortalDestination("more", "More", Icons.Outlined.GridView)
                                ), selected.value, true, { selected.value = it }, { clicked.set(true) })
                            }) { padding -> Column(Modifier.fillMaxSize().padding(padding)) { PortalStatusRow(role, false) {}; LoadingSkeleton() } }
                        }
                    }
                }
                instrumentation.waitForIdleSync()
                SystemClock.sleep(400)
                val deadline = SystemClock.uptimeMillis() + 20_000
                var readyRoot: AccessibilityNodeInfo? = null
                while (SystemClock.uptimeMillis() < deadline) {
                    val candidate = instrumentation.uiAutomation.rootInActiveWindow
                    if (candidate != null && flatten(candidate).any { it.contentDescription?.toString() == "Open Campus Assistant" }) { readyRoot = candidate; break }
                    SystemClock.sleep(200)
                }
                val root = requireNotNull(readyRoot) { "Portal did not become accessible" }
                val icon = flatten(root).first { it.contentDescription?.toString() == "Open Campus Assistant" }
                val button = generateSequence(icon) { it.parent }.first { it.isClickable }
                val bounds = Rect(); button.getBoundsInScreen(bounds)
                val screen = Rect(); root.getBoundsInScreen(screen)
                val density = activity.resources.displayMetrics.density
                assertTrue("Whole circular button must be visible", bounds.width() >= (55 * density).toInt() && bounds.height() >= (55 * density).toInt())
                assertTrue("Button must stay inside window", screen.contains(bounds))
                val schedule = flatten(root).first { it.text?.toString() == "Schedule" }
                val tab = generateSequence(schedule) { it.parent }.first { it.isClickable }
                val tabBounds = Rect(); tab.getBoundsInScreen(tabBounds)
                assertFalse("AI and tab targets must not overlap", Rect.intersects(bounds, tabBounds))
                assertTrue("Schedule tab exposes a click action",tab.performAction(AccessibilityNodeInfo.ACTION_CLICK))
                val scheduleDeadline=SystemClock.uptimeMillis()+5_000
                while(selected.value!="schedule"&&SystemClock.uptimeMillis()<scheduleDeadline)SystemClock.sleep(100)
                assertEquals("schedule", selected.value)
                assertTrue("Assistant exposes a click action",button.performAction(AccessibilityNodeInfo.ACTION_CLICK))
                val assistantDeadline=SystemClock.uptimeMillis()+5_000
                while(!clicked.get()&&SystemClock.uptimeMillis()<assistantDeadline)SystemClock.sleep(100)
                assertTrue("Assistant tap must work for $role", clicked.get())
                instrumentation.uiAutomation.takeScreenshot()?.let { bitmap ->
                    java.io.File(instrumentation.targetContext.cacheDir, "portal-${role.lowercase()}-$dark.png").outputStream().use { bitmap.compress(android.graphics.Bitmap.CompressFormat.PNG, 100, it) }
                }
            }
        } finally { instrumentation.runOnMainSync { activity.finish() } }
    }
}
