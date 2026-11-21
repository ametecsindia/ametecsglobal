package ametecs.managedmobile.data

import android.content.Context
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Deferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.async
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import java.util.concurrent.ConcurrentHashMap

/**
 * THE PHONE APP OPENS ON WHAT IT LAST SAW (owner, 21 Nov 2026: "app loading very slow"). Every screen shows its last
 * answer at once and refreshes underneath; two screens asking the same thing share one call. Home is kept on disk so a
 * cold start draws immediately. Cleared on sign-out.
 * ponytail: home only on disk; persist more keys if other screens' cold opens feel slow.
 */
object AgCache {
    private val mem = ConcurrentHashMap<String, JsonObject>()
    private val inflight = ConcurrentHashMap<String, Deferred<JsonObject>>()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private const val DISK = "home"
    private var prefs: android.content.SharedPreferences? = null

    fun attach(ctx: Context) {
        if (prefs != null) return
        prefs = ctx.applicationContext.getSharedPreferences("ag_cache", Context.MODE_PRIVATE)
        prefs?.getString(DISK, null)?.let { t -> runCatching { mem[DISK] = Json.parseToJsonElement(t).jsonObject } }
    }

    fun peek(key: String): JsonObject? = mem[key]

    /** The server's answer for [key]; a call already on its way is shared, not repeated. Good answers are kept. */
    suspend fun fetch(key: String, load: suspend () -> JsonObject): JsonObject {
        val d = inflight.getOrPut(key) { scope.async { load() } }
        return try {
            d.await().also { o ->
                if (o.str("ok") != "false") {
                    mem[key] = o
                    if (key == DISK) prefs?.edit()?.putString(DISK, o.toString())?.apply()
                }
            }
        } finally {
            inflight.remove(key, d)
        }
    }

    fun warm(key: String, load: suspend () -> JsonObject) { scope.async { runCatching { fetch(key, load) } } }

    fun clear() { mem.clear(); prefs?.edit()?.clear()?.apply() }
}
