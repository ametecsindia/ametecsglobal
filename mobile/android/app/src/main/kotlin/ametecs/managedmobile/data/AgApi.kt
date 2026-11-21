package ametecs.managedmobile.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.jsonObject
import okhttp3.Cookie
import okhttp3.CookieJar
import okhttp3.FormBody
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.concurrent.TimeUnit

/**
 * AMETECS GLOBAL NATIVE SCREENS' API (owner, 20 Nov 2026: "no WebViews ... native application UI ... integrate existing
 * APIs and business logic"). The phone's own screens read and write through SmartPRS's own engines
 * (plugins/smartprs3/surface/partials/ag-api.php): this client holds the employee's Ametecs Global sign-in as a cookie,
 * opened with the one-time link ROVEXY hands this approved phone ([link]). Signed out on the server (timeout, password
 * change) = the next call signs in again once. QR punches go to QRONEX with a one-time ticket from the same API.
 */
class AgApi(private val link: suspend () -> String) {
    private val json = Json { ignoreUnknownKeys = true }
    private val cookies = mutableListOf<Cookie>()
    private val jar = object : CookieJar {
        override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) = synchronized(this@AgApi.cookies) {
            this@AgApi.cookies.removeAll { c -> cookies.any { it.name == c.name } }
            this@AgApi.cookies.addAll(cookies)
            Unit
        }
        override fun loadForRequest(url: HttpUrl) = synchronized(cookies) { cookies.filter { it.matches(url) } }
    }
    private val http = OkHttpClient.Builder().cookieJar(jar).followRedirects(false)
        .connectTimeout(6, TimeUnit.SECONDS).readTimeout(25, TimeUnit.SECONDS).build()   // unreachable server: say so in seconds, not a minute
    private val lock = Mutex()
    @Volatile private var root: String? = null      // http://host/AmetecsGlobal
    @Volatile private var csrf: String? = null

    fun reset() { synchronized(cookies) { cookies.clear() }; root = null; csrf = null }

    @Volatile private var gen = 0   // +1 per sign-in
    /** [seen] = the sign-in the caller used; one another call finished meanwhile is reused (no double sign-in). */
    private suspend fun signIn(seen: Int) = lock.withLock {
        if (root != null && gen != seen) return@withLock
        val url = link()
        withContext(Dispatchers.IO) { http.newCall(Request.Builder().url(url).build()).execute().close() }
        root = url.substringBefore("/plugins/smartprs3/")
        csrf = null
        gen++
    }

    private suspend fun send(build: (String) -> Request): JsonObject {
        if (root == null) signIn(gen)
        repeat(2) { attempt ->
            val seen = gen
            val used = root!!
            val text = withContext(Dispatchers.IO) { http.newCall(build(used)).execute().use { if (it.isRedirect) "" else it.body?.string().orEmpty() } }
            val o = runCatching { json.parseToJsonElement(text).jsonObject }.getOrNull()
            if (o != null) { o.str("csrf")?.let { csrf = it }; return o }
            if (attempt == 0) signIn(seen)   // got the sign-in page: the server session ended
        }
        throw ApiException(0, "ag_signin", "Could not reach Ametecs Global. Check the connection and try again.")
    }

    private fun app(root: String, params: Map<String, String>): HttpUrl =
        "$root/plugins/smartprs3/public/app.php".toHttpUrl().newBuilder().apply { params.forEach { (k, v) -> addQueryParameter(k, v) } }.build()

    suspend fun get(op: String, params: Map<String, String> = emptyMap()): JsonObject =
        send { r -> Request.Builder().url(app(r, params + ("ag_api" to op))).build() }

    /** A SmartPRS form action (leave-request, correction-raise...), decided by the web's own handler. Returns ok + message. */
    suspend fun action(action: String, fields: Map<String, String>): JsonObject {
        repeat(2) {
            if (csrf == null) get("home")
            val token = csrf.orEmpty()
            val r = send { root ->
                val body = FormBody.Builder().add("action", action).add("csrf", token).apply { fields.forEach { (k, v) -> add(k, v) } }.build()
                Request.Builder().url(app(root, mapOf("ag_api" to "result"))).post(body).build()
            }
            if (r.str("message")?.startsWith("That form was refused") != true) return r
            csrf = null   // signed in again since the token was read
        }
        throw ApiException(0, "csrf", "Please try again.")
    }

    /** POST to one API op with this sign-in's token (e.g. notifications = mark all read). */
    suspend fun post(op: String, fields: Map<String, String> = emptyMap()): JsonObject {
        if (csrf == null) get("home")
        val token = csrf.orEmpty()
        return send { root ->
            val body = FormBody.Builder().add("csrf", token).apply { fields.forEach { (k, v) -> add(k, v) } }.build()
            Request.Builder().url(app(root, mapOf("ag_api" to op))).post(body).build()
        }
    }

    /** QRONEX punch: a one-time ticket for this employee, then QRONEX's own verifier. Returns QRONEX's answer. */
    suspend fun punch(qr: String, lat: Double, lng: Double, accuracy: Float, mocked: Boolean, agent: String): JsonObject {
        val t = get("scan-ticket")
        if (t.str("ok") != "true") return t
        val body = buildJsonObject {
            put("t", t.str("t").orEmpty()); put("qr", qr); put("lat", lat); put("lng", lng); put("accuracy", accuracy); put("mocked", mocked); put("agent", agent)
        }.toString()
        return withContext(Dispatchers.IO) {
            http.newCall(Request.Builder().url("${root!!}/${t.str("url")}").header("Accept", "application/json")
                .post(body.toRequestBody(ApiClient.JSON)).build()).execute().use { resp ->
                val text = resp.body?.string().orEmpty()
                runCatching { json.parseToJsonElement(text).jsonObject }.getOrElse {
                    JsonObject(mapOf("ok" to JsonPrimitive(false),
                        "message" to JsonPrimitive(if (resp.code == 429) "Too many tries. Wait a minute and try again." else "QRONEX could not be reached (${resp.code}). Try again.")))
                }
            }
        }
    }
}
