package com.example.campustrack.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import com.example.campustrack.BuildConfig
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import java.io.OutputStream
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.security.KeyStore
import java.util.concurrent.TimeUnit
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.runtime.mutableStateOf

class ApiException(val status: Int, val code: String, override val message: String) : IOException(message)

/** Only opaque session cookies are persisted, encrypted with an Android Keystore key. */
class SessionCookies(context: Context) : CookieJar {
    private val apiRoot=BuildConfig.API_BASE_URL.toHttpUrl().toString()
    private val rootKey=java.security.MessageDigest.getInstance("SHA-256").digest(apiRoot.toByteArray(Charsets.UTF_8)).joinToString(""){"%02x".format(it)}.take(20)
    private val preferences = context.getSharedPreferences("campus_session_$rootKey", Context.MODE_PRIVATE)
    private val keyAlias = "campustrack_session_v1"
    private var cookies: List<Cookie> = restore()
    private fun key(): SecretKey {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        return (store.getKey(keyAlias, null) as? SecretKey) ?: KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
            init(KeyGenParameterSpec.Builder(keyAlias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build())
        }.generateKey()
    }
    private fun restore(): List<Cookie> = try {
        val stored = preferences.getString("encrypted", null)
        if (stored == null) emptyList() else {
            val bytes = Base64.decode(stored, Base64.NO_WRAP)
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, bytes.copyOfRange(0, 12)))
            cipher.updateAAD(apiRoot.toByteArray(Charsets.UTF_8))
            val array = JSONArray(String(cipher.doFinal(bytes.copyOfRange(12, bytes.size)), Charsets.UTF_8))
            val url = BuildConfig.API_BASE_URL.toHttpUrl()
            (0 until array.length()).mapNotNull { Cookie.parse(url, array.getString(it)) }
        }
    } catch (_: Exception) { preferences.edit().clear().apply(); emptyList() }
    @Synchronized override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
        if (url.host != BuildConfig.API_BASE_URL.toHttpUrl().host || (!url.isHttps && !BuildConfig.DEBUG)) return
        this.cookies = (this.cookies.filter { old -> cookies.none { it.name == old.name && it.path == old.path } } + cookies).filter { it.expiresAt > System.currentTimeMillis() }
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, key()) }
        cipher.updateAAD(apiRoot.toByteArray(Charsets.UTF_8))
        val encrypted = cipher.iv + cipher.doFinal(JSONArray(this.cookies.map { it.toString() }).toString().toByteArray(Charsets.UTF_8))
        preferences.edit().putString("encrypted", Base64.encodeToString(encrypted, Base64.NO_WRAP)).apply()
    }
    @Synchronized override fun loadForRequest(url: HttpUrl) = cookies.filter { it.matches(url) && it.expiresAt > System.currentTimeMillis() }
    @Synchronized fun clear() { cookies = emptyList(); preferences.edit().clear().apply() }
}

class ApiClient(context: Context) {
    private val appContext = context.applicationContext
    private val cookies = SessionCookies(context.applicationContext)
    private val client = OkHttpClient.Builder().cookieJar(cookies).connectTimeout(15, TimeUnit.SECONDS).readTimeout(30, TimeUnit.SECONDS).callTimeout(45, TimeUnit.SECONDS).followRedirects(false).build()
    var roles: List<String> = emptyList(); private set
    var capabilities: JSONObject by mutableStateOf(JSONObject()); private set
    val permissions: Set<String> get() = capabilities.optJSONArray("permissions")?.let { list -> (0 until list.length()).map { list.getString(it) }.toSet() }.orEmpty()
    fun can(vararg grants: String) = grants.any { it in permissions }
    private var csrf: String? = null
    suspend fun get(path: String): JSONObject = request(path, null)
    suspend fun upload(path:String,payload:JSONObject,files:List<UploadFile>,field:String="attachments[]"):JSONObject {
        return try{uploadOnce(path,payload,files,field)}catch(e:ApiException){if(e.status!=419)throw e;session();uploadOnce(path,payload,files,field)}
    }
    private suspend fun uploadOnce(path:String,payload:JSONObject,files:List<UploadFile>,field:String):JSONObject {
        if(csrf==null)session()
        return withContext(Dispatchers.IO){
            val body=MultipartBody.Builder().setType(MultipartBody.FORM)
            payload.keys().forEach{key->body.addFormDataPart(key,payload.get(key).toString())}
            files.forEach{file->body.addFormDataPart(field,file.name,file.bytes.toRequestBody(file.mime.toMediaType()))}
            client.newCall(Request.Builder().url(BuildConfig.API_BASE_URL+path).header("X-CSRF-Token",csrf.orEmpty()).post(body.build()).build()).useResponse{response->
                val json=runCatching{JSONObject(response.body?.string().orEmpty())}.getOrElse{throw ApiException(response.code,"invalid_response","The upload could not be read.")}
                updateAuthorization(json)
                if(!response.isSuccessful)throw ApiException(response.code,"upload",json.optJSONObject("error")?.optString("message")?:"The upload failed.")
                json.getJSONObject("data")
            }
        }
    }
    suspend fun post(path: String, payload: JSONObject = JSONObject()): JSONObject {
        if (csrf == null) session()
        return try { request(path, payload) } catch (error: ApiException) {
            if (error.status != 419) throw error
            session(); request(path, payload) // A 419 is rejected before any server-side mutation.
        }
    }
    suspend fun session(): Boolean {
        val data = get("auth/session.php")
        if(data.getBoolean("authenticated")&&(data.optInt("api_version")<2||!data.has("workspaces")))throw ApiException(503,"server_update_required","This app needs the updated campus service. Ask your administrator to deploy the matching mobile API, then try again.")
        capabilities = data
        csrf = data.getString("csrf_token")
        val list = data.optJSONArray("roles") ?: JSONArray()
        roles = (0 until list.length()).map { list.getString(it) }
        return data.getBoolean("authenticated")
    }
    fun clearSession() { CampusPush.clear(appContext); cookies.clear(); csrf = null; roles = emptyList(); capabilities = JSONObject() }
    suspend fun download(path: String, maxBytes: Int = 10_485_760): ByteArray = withContext(Dispatchers.IO) {
        client.newCall(Request.Builder().url(BuildConfig.API_BASE_URL + path).build()).useResponse { response ->
            if (!response.isSuccessful) throw ApiException(response.code, "download", "The download could not be completed. Please retry.")
            if (response.header("Content-Type").orEmpty().contains("application/json")) throw ApiException(response.code, "download", "The service returned a message instead of a file.")
            response.body?.byteStream()?.use{it.readLimited(maxBytes)} ?: throw IOException("The file is empty.")
        }
    }
    suspend fun uploadPhoto(bytes: ByteArray, mime: String): JSONObject {
        require(bytes.size in 1..2_097_152) { "Choose an image up to 2 MB." }
        if (csrf == null) session()
        suspend fun upload(): JSONObject = withContext(Dispatchers.IO) {
            val body = MultipartBody.Builder().setType(MultipartBody.FORM)
                .addFormDataPart("profile_image", "profile", bytes.toRequestBody(mime.toMediaType())).build()
            val request = Request.Builder().url(BuildConfig.API_BASE_URL + "student/photo.php")
                .header("X-CSRF-Token", csrf.orEmpty()).post(body).build()
            client.newCall(request).useResponse { response ->
                val json = try { JSONObject(response.body?.string().orEmpty()) } catch (_: Exception) { throw ApiException(response.code, "invalid_response", "The photo service returned an unexpected response.") }
                updateAuthorization(json)
                if (!response.isSuccessful) throw ApiException(response.code, "upload", json.optJSONObject("error")?.optString("message") ?: "Photo upload failed.")
                json.getJSONObject("data")
            }
        }
        return try { upload() } catch (error: ApiException) { if (error.status != 419) throw error; session(); upload() }
    }
    private fun updateAuthorization(json:JSONObject){json.optJSONObject("authorization")?.let{authorization->
        val next=JSONObject(capabilities.toString()).apply{authorization.keys().forEach{key->put(key,authorization.get(key))}};if(next.toString()!=capabilities.toString())capabilities=next
        val list=capabilities.optJSONArray("roles")?:JSONArray();roles=(0 until list.length()).map{list.getString(it)}
    }}
    /** Stream files directly to the user-selected destination; large exports never occupy a byte array. */
    suspend fun downloadTo(path:String,output:OutputStream)=withContext(Dispatchers.IO){
        client.newCall(Request.Builder().url(BuildConfig.API_BASE_URL+path).build()).useResponse{response->
            if(!response.isSuccessful||response.header("Content-Type").orEmpty().contains("application/json"))throw ApiException(response.code,"download","The download could not be completed.")
            response.body?.byteStream()?.use{it.copyTo(output)}?:throw IOException("The file is empty.")
        }
    }
    private suspend fun request(path: String, payload: JSONObject?): JSONObject = withContext(Dispatchers.IO) {
        val request = Request.Builder().url(BuildConfig.API_BASE_URL + path).header("Accept", "application/json")
        if (payload != null) request.header("X-CSRF-Token", csrf.orEmpty()).post(payload.toString().toRequestBody("application/json; charset=utf-8".toMediaType()))
        try {
            client.newCall(request.build()).useResponse { response ->
                val text = response.body?.string().orEmpty()
                val json = try { JSONObject(text) } catch (_: Exception) { throw ApiException(response.code, "invalid_response", "The campus service returned an unexpected response. Please try again.") }
                updateAuthorization(json)
                if (!response.isSuccessful) {
                    val error = json.optJSONObject("error")
                    throw ApiException(response.code, error?.optString("code") ?: "request_failed", error?.optString("message") ?: "Could not complete the request.")
                }
                val data = json.getJSONObject("data")
                if (data.has("csrf_token")) csrf = data.getString("csrf_token")
                data
            }
        } catch (error: ApiException) { throw error } catch (_: IOException) { throw ApiException(0, "network", "Can't reach the campus service. Check your connection and try again.") }
    }
}
data class UploadFile(val name:String,val mime:String,val bytes:ByteArray)
fun java.io.InputStream.readLimited(limit:Int):ByteArray {
    val output=java.io.ByteArrayOutputStream();val buffer=ByteArray(8192)
    while(true){val count=read(buffer);if(count<0)break;require(output.size()+count<=limit){"Choose a smaller file."};output.write(buffer,0,count)}
    return output.toByteArray()
}

/** Cancellation stops the socket and response body as well as removing the stale UI request. */
private suspend fun <T> Call.useResponse(block:(Response)->T):T=suspendCancellableCoroutine{continuation->
    continuation.invokeOnCancellation{cancel()}
    enqueue(object:Callback{
        override fun onFailure(call:Call,e:IOException){if(continuation.isActive)continuation.resumeWithException(e)}
        override fun onResponse(call:Call,response:Response){try{val result=response.use(block);if(continuation.isActive)continuation.resume(result)}catch(e:Exception){if(continuation.isActive)continuation.resumeWithException(e)}}
    })
}
