package com.example.campustrack.data

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.example.campustrack.MainActivity
import com.example.campustrack.R
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.MutableStateFlow
import org.json.JSONArray
import org.json.JSONObject
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import androidx.work.*

data class CampusNotification(val id: String, val revision: Int, val title: String, val body: String, val kind: String, val read: Boolean, val time: String)
data class NotificationPage(val items: List<CampusNotification>, val unread: Int, val next: String?)
class NotificationRepository(private val api: ApiClient) {
    suspend fun load(before: String? = null): NotificationPage {
        val json = api.get("notifications/index.php" + (before?.let { "?before=${it.toLong()}" } ?: ""))
        return NotificationPage(json.getJSONArray("items").objects().map { row ->
            CampusNotification(row.getString("id"), row.getInt("revision"), row.getString("title"), row.getString("body"), row.getString("kind"), !row.isNull("read_at"), row.getString("updated_at"))
        }, json.getInt("unread"), if (json.isNull("next_before")) null else json.getString("next_before"))
    }
    suspend fun read(items: List<CampusNotification>) {
        items.chunked(50).forEach { batch -> api.post("notifications/read.php", JSONObject().put("items", JSONArray(batch.map { JSONObject().put("id", it.id).put("revision", it.revision) }))) }
    }
}

object CampusPush {
    val refresh = MutableStateFlow(0L)
    val openInbox = MutableStateFlow(false)
    private const val CHANNEL = "campus_alerts"
    fun configured(context: Context) = FirebaseApp.getApps(context).isNotEmpty()
    fun allowed(context: Context) = NotificationManagerCompat.from(context).areNotificationsEnabled() &&
        (Build.VERSION.SDK_INT < 33 || ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED)
    private fun preferences(context: Context) = context.getSharedPreferences("campus_push", Context.MODE_PRIVATE)
    fun clear(context: Context) {
        val prefs = preferences(context)
        prefs.edit().remove("account").putLong("generation", prefs.getLong("generation", 0) + 1).apply()
        WorkManager.getInstance(context).cancelUniqueWork("campus-push-token")
        NotificationManagerCompat.from(context).cancelAll()
        openInbox.value = false
    }
    suspend fun register(context: Context, api: ApiClient): Boolean {
        if (!configured(context)) return false
        val generation = preferences(context).getLong("generation", 0)
        val token = suspendCancellableCoroutine<String> { continuation ->
            FirebaseMessaging.getInstance().token.addOnSuccessListener { if (continuation.isActive) continuation.resume(it) }
                .addOnFailureListener { if (continuation.isActive) continuation.resumeWithException(it) }
        }
        val enabled = allowed(context)
        val result = api.post("notifications/device.php", JSONObject().put("token", token).put("enabled", enabled))
        if (preferences(context).getLong("generation", 0) != generation) return false
        preferences(context).edit().putString("token", token).apply()
        if (enabled) preferences(context).edit().putString("account", result.getString("account_id")).apply()
        else clear(context)
        return enabled
    }
    fun channel(context: Context) {
        context.getSystemService(NotificationManager::class.java).createNotificationChannel(NotificationChannel(CHANNEL, "Campus alerts", NotificationManager.IMPORTANCE_HIGH).apply { description = "Attendance warnings and upcoming campus activities" })
    }
    fun receive(context: Context, data: Map<String, String>) {
        val prefs = preferences(context)
        val account = prefs.getString("account", null)
        val envelope = acceptedPush(account, data) ?: return
        val (id, revision) = envelope
        refresh.value = System.currentTimeMillis()
        if (!allowed(context)) return
        val key = "seen:$account:$id"
        if (prefs.getInt(key, 0) >= revision) return
        channel(context)
        val intent = Intent(context, MainActivity::class.java).putExtra("open_notifications", true).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        val pending = PendingIntent.getActivity(context, 7001, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val notification = NotificationCompat.Builder(context, CHANNEL).setSmallIcon(R.drawable.ic_notification)
            .setContentTitle("CampusTrack notification").setContentText("You have a new campus alert. Tap to view it securely.")
            .setContentIntent(pending).setAutoCancel(true).setPriority(NotificationCompat.PRIORITY_HIGH)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE).build()
        if (Build.VERSION.SDK_INT >= 33 && ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return
        try { NotificationManagerCompat.from(context).notify("campus:$id", 1, notification) }
        catch (_: SecurityException) { return } // Permission may be revoked between the check and posting.
        prefs.edit().putInt(key, revision).apply()
    }
}

class CampusMessagingService : FirebaseMessagingService() {
    override fun onMessageReceived(message: RemoteMessage) { CampusPush.receive(this, message.data) }
    override fun onNewToken(token: String) {
        getSharedPreferences("campus_push", MODE_PRIVATE).edit().putString("token", token).apply()
        // Retry token rotation with the existing authenticated session, never a model/user-supplied id.
        WorkManager.getInstance(this).enqueueUniqueWork("campus-push-token", ExistingWorkPolicy.REPLACE,
            OneTimeWorkRequestBuilder<PushTokenWorker>().setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()).build())
        CampusPush.refresh.value = System.currentTimeMillis()
    }
}

fun acceptedPush(account: String?, data: Map<String, String>): Pair<Long, Int>? {
    if (account.isNullOrBlank() || account != data["account_id"]) return null
    val id = data["notification_id"]?.toLongOrNull()?.takeIf { it > 0 } ?: return null
    val revision = data["revision"]?.toIntOrNull()?.takeIf { it > 0 } ?: return null
    return id to revision
}

class PushTokenWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val prefs = applicationContext.getSharedPreferences("campus_push", Context.MODE_PRIVATE)
        if (prefs.getString("account", null) == null) return Result.success()
        return try {
            val api = ApiClient(applicationContext)
            if (api.session()) CampusPush.register(applicationContext, api) else CampusPush.clear(applicationContext)
            Result.success()
        } catch (cancelled: CancellationException) { throw cancelled }
        catch (failure: Exception) {
            if (failure is ApiException && failure.status == 401) { CampusPush.clear(applicationContext); Result.success() }
            else if (runAttemptCount < 6) Result.retry() else Result.failure()
        }
    }
}
