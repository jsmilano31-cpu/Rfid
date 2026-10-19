package com.example.campustrack

import android.app.NotificationManager
import android.content.Context
import androidx.test.platform.app.InstrumentationRegistry
import androidx.work.WorkManager
import com.google.android.gms.tasks.Tasks
import com.google.firebase.messaging.FirebaseMessaging
import org.json.JSONObject
import org.junit.Assert.assertTrue
import org.junit.Assume.assumeTrue
import org.junit.Test
import java.io.File
import java.util.concurrent.TimeUnit

/** Opt-in delivery check: isolated local binding, no production account or attendance writes. */
class LivePushTest {
    @Test fun receivesRealFcmMessage() {
        assumeTrue(InstrumentationRegistry.getArguments().getString("livePushTest") == "true")
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val preferences = context.getSharedPreferences("campus_push", Context.MODE_PRIVATE)
        val original = preferences.all.toMap()
        val tokenFile = File(context.filesDir, "push-verification.json")
        val manager = context.getSystemService(NotificationManager::class.java)
        val id = System.currentTimeMillis().toString()
        try {
            val token = Tasks.await(FirebaseMessaging.getInstance().token, 90, TimeUnit.SECONDS)
            // Let initial token rotation finish before installing a synthetic binding.
            // Its normal signed-out cleanup must not race the transport-only fixture.
            val work = WorkManager.getInstance(context)
            work.cancelUniqueWork("campus-push-token").result.get(30, TimeUnit.SECONDS)
            val rotationDeadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(30)
            while (work.getWorkInfosForUniqueWork("campus-push-token").get(5, TimeUnit.SECONDS).any { !it.state.isFinished }) {
                check(System.nanoTime() < rotationDeadline) { "Token rotation did not settle" }
                Thread.sleep(250)
            }
            preferences.edit().putString("account", "push-verification-only").commit()
            tokenFile.writeText(JSONObject().put("token", token).put("notification_id", id).toString())
            val deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(150)
            var received = false
            while (System.nanoTime() < deadline) {
                received = manager.activeNotifications.any { it.tag == "campus:$id" }
                if (received) break
                Thread.sleep(500)
            }
            assertTrue("Real FCM delivery did not produce the expected device notification", received)
        } finally {
            tokenFile.delete()
            manager.cancel("campus:$id", 1)
            val editor = preferences.edit().clear()
            original.forEach { (key, value) ->
                when (value) {
                    is String -> editor.putString(key, value)
                    is Int -> editor.putInt(key, value)
                    is Long -> editor.putLong(key, value)
                    is Boolean -> editor.putBoolean(key, value)
                    is Float -> editor.putFloat(key, value)
                    is Set<*> -> editor.putStringSet(key, value.filterIsInstance<String>().toSet())
                }
            }
            editor.commit()
        }
    }
}
