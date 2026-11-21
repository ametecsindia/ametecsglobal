package ametecs.managedmobile.ui

import android.Manifest
import android.annotation.SuppressLint
import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.Logout
import androidx.compose.material.icons.outlined.BeachAccess
import androidx.compose.material.icons.outlined.Campaign
import androidx.compose.material.icons.outlined.Groups
import androidx.compose.material.icons.outlined.Bolt
import androidx.compose.material.icons.outlined.EmojiEvents
import androidx.compose.material.icons.outlined.Lightbulb
import androidx.compose.material.icons.outlined.LocalFireDepartment
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material.icons.outlined.Star
import androidx.compose.material.icons.outlined.WbSunny
import androidx.compose.animation.core.animateFloatAsState
import kotlin.math.roundToInt
import androidx.compose.material.icons.outlined.SupportAgent
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.ChevronLeft
import androidx.compose.material.icons.outlined.ChevronRight
import androidx.compose.material.icons.outlined.Description
import androidx.compose.material.icons.outlined.EditCalendar
import androidx.compose.material.icons.outlined.Inventory2
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material.icons.outlined.EventAvailable
import androidx.compose.material.icons.outlined.History
import androidx.compose.material.icons.outlined.Insights
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Payments
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.QrCodeScanner
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.Route
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.core.content.ContextCompat
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.Deferred
import kotlinx.coroutines.async
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonPrimitive
import java.time.LocalDate
import java.time.LocalTime
import java.time.YearMonth
import java.time.format.DateTimeFormatter
import kotlin.coroutines.resume
import ametecs.managedmobile.data.str
import ametecs.managedmobile.data.AgCache
import ametecs.managedmobile.R
import androidx.compose.foundation.Image
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.offset
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.drawscope.scale
import androidx.compose.ui.graphics.drawscope.translate
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.res.painterResource

/*
 * AMETECS GLOBAL - THE PHONE APP'S OWN SCREENS (owner, 20 Nov 2026: "No WebView or embedded application pages ... develop
 * the required screens directly within Ametecs Global ... fully working QR attendance scanning, punch-in/punch-out,
 * attendance history"). Every screen here is native and reads/writes through the apps' own engines:
 *   - QRONEX: live camera scanner (ZXing, as the QRONEX app) -> location -> QRONEX AttendanceVerifier (AgApi.punch)
 *   - SmartPRS: attendance month (the muster payroll reads), leave balances / apply / requests, payslips from closed
 *     payroll runs, attendance corrections, notifications, profile  (plugins/smartprs3/surface/partials/ag-api.php)
 *   - SmartEPT: my productivity (its own ag-person.php)
 *   - ROVEXY: field work is this app's native dashboard
 * What a person sees comes from the server (role, licence, App access). A workspace that is not an Ametecs Global client
 * (ag_apps null) goes straight to the ROVEXY dashboard, as before.
 */

private val Ink = Color(0xFF14100A)
private val Ink2 = Color(0xFF2A2112)
private val Gold = Color(0xFFC9A24C)
private val GoldHi = Color(0xFFE8C977)
private val GoldTint = Color(0xFFF6EDD8)
private val Cream = Color(0xFFF7F3EA)
private val Line = Color(0xFFEDE5D3)
private val Text1 = Color(0xFF17130C)
private val Text2 = Color(0xFF6B6250)
private val Ok = Color(0xFF15803D)
private val Bad = Color(0xFFB91C1C)
private val Warn = Color(0xFFB45309)

// ---------- small JSON helpers ----------
private fun JsonObject.s(k: String) = str(k).orEmpty()
private fun JsonObject.o(k: String) = this[k] as? JsonObject
private fun JsonObject.a(k: String): List<JsonObject> = (this[k] as? JsonArray).orEmpty().mapNotNull { it as? JsonObject }
private fun JsonObject.b(k: String) = str(k) == "true"
private fun JsonObject.n(k: String) = str(k)?.toIntOrNull() ?: 0
private fun fail(msg: String) = JsonObject(mapOf("ok" to JsonPrimitive(false), "message" to JsonPrimitive(msg)))

// ---------- the Ametecs Global loader (owner, 21 Nov 2026: "loading time / refresh / logo animation") ----------
/** True while a screen's data is being refreshed underneath what is shown. */
val LocalAgRefreshing = compositionLocalOf { false }

/** The transparent mark BUILDING ITSELF (owner, 21 Nov 2026: "transparent logo, animation like build complete logo"):
 *  the A is drawn, the orbit sweeps round it (behind, then in front), the satellite lands, holds, fades, and again.
 *  [spinning] = false shows the finished mark (pull to refresh before release). Same drawing as the web loader. */
@Composable
fun AgSpinner(size: Int = 56, modifier: Modifier = Modifier, spinning: Boolean = true) {
    val t = rememberInfiniteTransition(label = "ag")
    val p by t.animateFloat(0f, 1f, infiniteRepeatable(tween(2200, easing = LinearEasing)), label = "build")
    val k = if (spinning) p else 0.83f
    fun seg(a: Float, b: Float) = ((k - a) / (b - a)).coerceIn(0f, 1f)
    val aP = FastOutSlowInEasing.transform(seg(0f, 0.35f))      // the A
    val oP = FastOutSlowInEasing.transform(seg(0.30f, 0.70f))   // the orbit, all the way round
    val dP = seg(0.68f, 0.82f)                                  // the satellite lands
    val fade = 1f - seg(0.84f, 0.96f)
    val gold = Brush.linearGradient(listOf(Color(0xFFE7C77A), Color(0xFFC9A24A), Color(0xFF9C7A2E)), Offset(60f, 90f), Offset(460f, 430f))
    Canvas(modifier.size(size.dp).graphicsLayer { alpha = fade }) {
        val sc = this.size.width / 400f
        scale(sc, sc, pivot = Offset.Zero) {
            translate(-60f, -60f) {   // 400 x 340 drawing, centred in a square
                val oval = Size(336f, 104f); val tl = Offset(88f, 248f)
                fun orbit(from: Float, sweep: Float) = rotate(-16f, Offset(256f, 300f)) {
                    if (sweep > 0f) drawArc(gold, from, sweep, false, tl, oval, style = Stroke(18f, cap = StrokeCap.Round))
                }
                orbit(180f, (oP * 360f).coerceAtMost(180f))                 // back half, behind the A
                val path = Path()
                val l1 = aP * 2f
                path.moveTo(150f, 396f)
                path.lineTo(150f + 106f * l1.coerceAtMost(1f), 396f - 278f * l1.coerceAtMost(1f))
                if (l1 > 1f) path.lineTo(256f + 106f * (l1 - 1f), 118f + 278f * (l1 - 1f))
                if (aP > 0f) drawPath(path, gold, style = Stroke(46f, cap = StrokeCap.Round, join = StrokeJoin.Round))
                orbit(0f, (oP * 360f - 180f).coerceAtLeast(0f))              // front half, over the A
                if (dP > 0f) {
                    val pop = if (dP < 0.55f) dP / 0.55f * 1.5f else 1.5f - (dP - 0.55f) / 0.45f * 0.5f
                    drawCircle(Color(0xFFE7C77A), 17f * pop, Offset(417f, 254f))
                }
            }
        }
    }
}

/** Full-screen start: shown while the app checks the server and this phone, instead of a flash of the connect form. */
@Composable
fun AgSplash() {
    val t = rememberInfiniteTransition(label = "dots")
    val a by t.animateFloat(0.35f, 1f, infiniteRepeatable(tween(800), RepeatMode.Reverse), label = "a")
    Column(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color(0xFF060504), Ink, Ink2))), horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center) {
        AgSpinner(96)
        Spacer(Modifier.height(22.dp))
        Row {
            Text("Ametecs ", color = Color.White, fontSize = 28.sp, fontWeight = FontWeight.Bold)
            Text("Global", color = GoldHi, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold)
        }
        Text("ONE PLATFORM · EVERY APP", color = Color(0xFFD9C9A3).copy(alpha = 0.7f), fontSize = 11.sp, letterSpacing = 3.sp, modifier = Modifier.padding(top = 6.dp))
        Text("Getting things ready…", color = Color(0xFFD9C9A3).copy(alpha = a), fontSize = 13.sp, modifier = Modifier.padding(top = 28.dp))
    }
}

/** Thin gold bar sliding across the top while a screen refreshes what it already shows. */
@Composable
private fun RefreshBar() {
    val t = rememberInfiniteTransition(label = "bar")
    val x by t.animateFloat(-0.4f, 1f, infiniteRepeatable(tween(900, easing = FastOutSlowInEasing)), label = "x")
    BoxWithConstraints(Modifier.fillMaxWidth().height(3.dp).background(GoldTint)) {
        Box(Modifier.width(maxWidth * 0.4f).fillMaxHeight().offset(x = maxWidth * x).background(Brush.horizontalGradient(listOf(Gold, GoldHi, Gold))))
    }
}

// ---------- navigation ----------
@Composable
fun AgHome(vm: AppViewModel) {
    val apps by vm.agApps.collectAsState()
    var page by rememberSaveable { mutableStateOf("home") }
    AgCache.attach(LocalContext.current)
    if (apps == null && AgCache.peek("home") == null) { Dashboard(vm); return }   // a plain ROVEXY workspace
    val go: (String) -> Unit = { page = it }
    val back = { page = when { page.startsWith("payslip:") -> "payslips"; page == "leave-apply" -> "leave"; page == "correct" -> "corrections"; else -> "home" } }
    if (page != "home") BackHandler(onBack = back)
    when {
        page == "home" -> Hub(vm, go)
        page == "attendance" -> AttendancePage(vm, back, go)
        page == "leave" -> LeavePage(vm, back, go)
        page == "leave-apply" -> LeaveApply(vm, back)
        page == "payslips" -> PayslipsPage(vm, back, go)
        page.startsWith("payslip:") -> PayslipPage(vm, page.removePrefix("payslip:"), back)
        page == "corrections" -> CorrectionsPage(vm, back, go)
        page == "correct" -> CorrectionForm(vm, back)
        page == "productivity" -> PersonPage(vm, "smartept", "My productivity", back)
        page == "alerts" -> AlertsPage(vm, back)
        page == "support" -> SupportPage(vm, back, go)
        page == "admin" -> AdminPage(vm, back)
        page == "profile" -> ProfilePage(vm, back)
        page == "letters" -> LettersPage(vm, back)
        page == "assets" -> AssetsPage(vm, back)
        page == "field" -> Column(Modifier.fillMaxSize()) { TopBar("Field work", back); Dashboard(vm) }
        else -> Hub(vm, go)
    }
}

// ---------- shared pieces ----------
@Composable
private fun TopBar(title: String, onBack: () -> Unit, action: (@Composable () -> Unit)? = null) {
    Row(Modifier.fillMaxWidth().background(Ink).statusBarsPadding().padding(horizontal = 8.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(40.dp).clip(CircleShape).clickable(onClick = onBack), contentAlignment = Alignment.Center) {
            Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Back", tint = GoldHi, modifier = Modifier.size(22.dp))
        }
        Text(title, color = Color.White, fontWeight = FontWeight.SemiBold, fontSize = 18.sp, modifier = Modifier.weight(1f).padding(start = 4.dp))
        action?.invoke()
    }
}

@Composable
private fun Page(title: String, onBack: () -> Unit, action: (@Composable () -> Unit)? = null, body: LazyListScope.() -> Unit) {
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar(title, onBack, action)
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp), content = body)
    }
}

@Composable
private fun Card(modifier: Modifier = Modifier, onClick: (() -> Unit)? = null, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Color.White).border(1.dp, Line, RoundedCornerShape(18.dp))
        .let { if (onClick != null) it.clickable(onClick = onClick) else it }.padding(16.dp), content = content)
}

@Composable
private fun Pill(text: String, color: Color) {
    Text(text, color = color, fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
        modifier = Modifier.clip(RoundedCornerShape(50)).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp))
}

private fun stateColor(s: String) = when (s.lowercase()) {
    "approved", "present", "in" -> Ok
    "rejected", "absent", "cancelled" -> Bad
    "pending", "half day", "late" -> Warn
    else -> Text2
}

@Composable
private fun GoldButton(text: String, icon: ImageVector? = null, enabled: Boolean = true, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(if (enabled) Gold else Gold.copy(alpha = 0.4f))
        .clickable(enabled = enabled, onClick = onClick).padding(16.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center) {
        icon?.let { Icon(it, null, tint = Ink, modifier = Modifier.size(22.dp)); Spacer(Modifier.width(10.dp)) }
        Text(text, color = Ink, fontWeight = FontWeight.Bold, fontSize = 16.sp)
    }
}

@Composable
private fun Field(label: String, value: String) {
    Column(Modifier.padding(vertical = 4.dp)) {
        Text(label, color = Text2, fontSize = 11.sp)
        Text(value.ifBlank { "—" }, color = Text1, fontSize = 14.sp, fontWeight = FontWeight.Medium)
    }
}

/** Loads one API answer; shows a spinner, the error with Retry, or the content (with a reload function).
 *  [cache]: the screen opens on its last answer at once and refreshes underneath (owner, 21 Nov 2026: "loading very slow"). */
@Composable
private fun Load(key: Any?, load: suspend () -> JsonObject, cache: String? = null, content: @Composable (JsonObject, () -> Unit) -> Unit) {
    AgCache.attach(LocalContext.current)
    var tick by remember { mutableIntStateOf(0) }
    var data by remember(key) { mutableStateOf(cache?.let { AgCache.peek(it) }) }
    var err by remember(key) { mutableStateOf<String?>(null) }
    var busy by remember(key) { mutableStateOf(false) }
    LaunchedEffect(key, tick) {
        err = null
        busy = true
        runCatching { if (cache != null) AgCache.fetch(cache, load) else load() }
            .also { busy = false }
            .onSuccess { if (it.str("ok") == "false") { if (data == null) err = it.str("message") ?: "Not available." } else data = it }
            .onFailure { if (data == null) err = it.message ?: "Could not load this just now." }
    }
    val d = data
    when {
        d != null -> Box {
            CompositionLocalProvider(LocalAgRefreshing provides busy) { content(d) { tick++ } }
            if (busy) Box(Modifier.align(Alignment.TopCenter)) { RefreshBar() }
        }
        err != null -> Column(Modifier.fillMaxWidth().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(Icons.Outlined.ErrorOutline, null, tint = Bad, modifier = Modifier.size(36.dp))
            Spacer(Modifier.height(8.dp))
            Text(err!!, color = Text1, textAlign = TextAlign.Center)
            TextButton(onClick = { tick++ }) { Text("Try again", color = Warn) }
        }
        else -> Column(Modifier.fillMaxWidth().padding(56.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            AgSpinner(52)
            Text("Loading…", color = Text2, fontSize = 13.sp, modifier = Modifier.padding(top = 12.dp))
        }
    }
}

// ---------- QRONEX punch: live camera scan + location + QRONEX's own checks ----------
private fun locationOn(ctx: Context) = (ctx.getSystemService(Context.LOCATION_SERVICE) as LocationManager).isLocationEnabled

@SuppressLint("MissingPermission")
private suspend fun fix(ctx: Context): Location? = withTimeoutOrNull(20_000) {
    suspendCancellableCoroutine { c ->
        val cts = CancellationTokenSource()
        LocationServices.getFusedLocationProviderClient(ctx).getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, cts.token)
            .addOnSuccessListener { if (c.isActive) c.resume(it) }
            .addOnFailureListener { if (c.isActive) c.resume(null) }
        c.invokeOnCancellation { cts.cancel() }
    }
}

/** Returns "start a punch". Shows the progress and QRONEX's answer (punched, wrong QR, duplicate, outside the office...). */
@Composable
private fun rememberPunch(vm: AppViewModel, done: () -> Unit): () -> Unit {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<JsonObject?>(null) }
    val loc = remember { arrayOfNulls<Deferred<Location?>>(1) }
    val scan = rememberLauncherForActivityResult(ScanContract()) { r ->
        val qr = r.contents ?: return@rememberLauncherForActivityResult   // cancelled
        busy = true
        scope.launch {
            val res = runCatching {
                val l = loc[0]?.await() ?: fix(ctx)
                if (l == null) fail("We couldn't get your location. Step near a window or outside and try again.")
                else vm.ag.punch(qr, l.latitude, l.longitude, l.accuracy, if (Build.VERSION.SDK_INT >= 31) l.isMock else @Suppress("DEPRECATION") l.isFromMockProvider,
                    "${Build.MANUFACTURER} ${Build.MODEL} · Android ${Build.VERSION.RELEASE}")
            }.getOrElse { fail(it.message ?: "Could not reach the server. Try again.") }
            busy = false
            result = res
            if (res.b("ok")) done()
        }
    }
    val open = {
        loc[0] = scope.async { fix(ctx) }   // locate while the camera is up
        scan.launch(ScanOptions().setDesiredBarcodeFormats(ScanOptions.QR_CODE).setPrompt("Point at the QRONEX code on the office screen")
            .setBeepEnabled(false).setOrientationLocked(true))
    }
    val perms = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { res ->
        if (res.values.all { it }) open() else result = fail("Allow the camera (to read the QR) and location (to confirm you are at the office), then try again.")
    }
    if (busy) Dialog(onDismissRequest = {}) {
        Column(Modifier.clip(RoundedCornerShape(24.dp)).background(Ink).padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            CircularProgressIndicator(color = GoldHi)
            Spacer(Modifier.height(16.dp))
            Text("Verifying with QRONEX", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 17.sp)
            Text("Checking the QR, your phone and the office location", color = Color(0xFFD9C9A3), fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 6.dp))
        }
    }
    result?.let { r -> PunchResult(r, retry = { result = null; open() }) { result = null } }
    return {
        val need = listOf(Manifest.permission.CAMERA, Manifest.permission.ACCESS_FINE_LOCATION)
            .filter { ContextCompat.checkSelfPermission(ctx, it) != PackageManager.PERMISSION_GRANTED }
        when {
            !locationOn(ctx) -> result = fail("Turn on location. QRONEX checks you are at the office when you punch.").let {
                JsonObject(it + ("settings" to JsonPrimitive(true)))
            }
            need.isNotEmpty() -> perms.launch(need.toTypedArray())
            else -> open()
        }
    }
}

/** What to do next, per QRONEX refusal (the QRONEX app's own wording). */
private val punchHints = mapOf(
    "OUTSIDE_GEOFENCE" to "Move closer to the office entrance and scan again.",
    "LOW_GPS_ACCURACY" to "Step near a window or outside for a better GPS fix, then try again.",
    "QR_EXPIRED" to "The code changes every few seconds. Scan the screen again.",
    "QR_INVALID" to "Scan the live QRONEX code on the entrance screen, not a photo of it.",
    "DUPLICATE" to "Your earlier punch is already recorded.",
    "DEVICE_NOT_APPROVED" to "Your administrator has to approve this phone first.",
    "OFFICE_NOT_ASSIGNED" to "You can only punch at the office you are assigned to.",
    "MOCK_LOCATION" to "Turn off any location-faking app and try again.",
    "EMPLOYEE_INACTIVE" to "QR attendance is switched off for you. Ask your administrator.",
    "NO_LOCATION" to "Make sure location is on and the app is allowed to use it.",
    "signature_stale" to "Turn on automatic date & time in your phone settings.",
)

@Composable
private fun PunchResult(r: JsonObject, retry: () -> Unit, close: () -> Unit) {
    val ctx = LocalContext.current
    val ok = r.b("ok")
    Dialog(onDismissRequest = close) {
        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Color.White).padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(72.dp).clip(CircleShape).background((if (ok) Ok else Bad).copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(if (ok) Icons.Outlined.CheckCircle else Icons.Outlined.ErrorOutline, null, tint = if (ok) Ok else Bad, modifier = Modifier.size(44.dp))
            }
            Spacer(Modifier.height(14.dp))
            if (ok) {
                Text(if (r.s("type") == "OUT") "Punched out" else "Punched in", color = Text1, fontSize = 22.sp, fontWeight = FontWeight.Bold)
                Text(r.s("at"), color = Ink, fontSize = 34.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 4.dp))
                listOf(r.s("office"), r.s("gate")).filter { it.isNotBlank() }.joinToString(" · ").takeIf { it.isNotBlank() }?.let {
                    Text(it, color = Text2, fontSize = 14.sp, modifier = Modifier.padding(top = 4.dp))
                }
            } else {
                Text("Not recorded", color = Text1, fontSize = 20.sp, fontWeight = FontWeight.Bold)
                Text(punchHints[r.s("reason_code")] ?: r.str("message") ?: r.str("reason") ?: "The punch was refused.", color = Text1, fontSize = 15.sp,
                    textAlign = TextAlign.Center, modifier = Modifier.padding(top = 8.dp))
                // QRONEX's own checklist: what passed and what failed
                r.a("checks").takeIf { it.isNotEmpty() }?.let { checks ->
                    Column(Modifier.fillMaxWidth().padding(top = 14.dp).clip(RoundedCornerShape(14.dp)).background(Cream).padding(12.dp)) {
                        checks.forEach { c ->
                            val st = c.s("status")
                            Row(Modifier.padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                                Text(when (st) { "ok" -> "✓"; "bad" -> "✕"; else -> "–" }, color = when (st) { "ok" -> Ok; "bad" -> Bad; else -> Text2 }, fontWeight = FontWeight.Bold, modifier = Modifier.width(20.dp))
                                Text(c.s("label"), color = Text1, fontSize = 13.sp)
                            }
                        }
                    }
                }
                r.str("ref")?.let { Text("Ref $it", color = Text2, fontSize = 11.sp, modifier = Modifier.padding(top = 6.dp)) }
            }
            Spacer(Modifier.height(20.dp))
            when {
                ok -> GoldButton("Done", onClick = close)
                r.b("settings") -> GoldButton("Open location settings") { close(); ctx.startActivity(Intent(Settings.ACTION_LOCATION_SOURCE_SETTINGS)) }
                r.s("reason_code") in listOf("DUPLICATE", "DEVICE_NOT_APPROVED", "EMPLOYEE_INACTIVE") -> GoldButton("OK", onClick = close)
                else -> { GoldButton("Scan again", Icons.Outlined.QrCodeScanner, onClick = retry); TextButton(onClick = close) { Text("Close", color = Text2) } }
            }
        }
    }
}

// ---------- Apps & services: Bento (owner, 21 Nov 2026: option A of the redesign canvas) ----------
/** A cached API answer, shown at once and refreshed in the background; null until the first answer. No spinner. */
@Composable
private fun rememberAg(key: String, load: suspend () -> JsonObject): JsonObject? {
    var v by remember(key) { mutableStateOf(AgCache.peek(key)) }
    LaunchedEffect(key) { runCatching { AgCache.fetch(key, load) }.onSuccess { if (it.str("ok") != "false") v = it } }
    return v
}

private fun minutesOf(s: String): Int {
    val h = Regex("(\\d+)h").find(s)?.groupValues?.get(1)?.toIntOrNull() ?: 0
    val m = Regex("(\\d+)m").find(s)?.groupValues?.get(1)?.toIntOrNull() ?: 0
    return h * 60 + m
}

// ---------- home ----------
private data class Tile(val key: String, val label: String, val sub: String, val icon: ImageVector, val badge: Int = 0)

/** The Ametecs Global mark (gold "A" and orbit) at [size]. */
@Composable
private fun Mark(size: Int) = Image(painterResource(R.drawable.ag_mark), "Ametecs Global", Modifier.size(size.dp).clip(RoundedCornerShape((size * 0.23f).dp)))

@Composable
private fun Bell(unread: Int, onClick: () -> Unit) {
    Box(Modifier.size(42.dp).clip(CircleShape).background(Color(0x1FFFFFFF)).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(Icons.Outlined.Notifications, "Notifications", tint = GoldHi, modifier = Modifier.size(22.dp))
        if (unread > 0) Text(if (unread > 9) "9+" else "$unread", color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.align(Alignment.TopEnd).padding(top = 4.dp, end = 3.dp).clip(CircleShape).background(Bad).padding(horizontal = 5.dp, vertical = 1.dp))
    }
}

@Composable
private fun QuickAction(label: String, icon: ImageVector, modifier: Modifier, onClick: () -> Unit) {
    Column(modifier.clip(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(vertical = 6.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(52.dp).clip(RoundedCornerShape(16.dp)).background(Color.White).border(1.dp, Line, RoundedCornerShape(16.dp)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Color(0xFFB08433), modifier = Modifier.size(24.dp))
        }
        Text(label, color = Text1, fontSize = 12.sp, fontWeight = FontWeight.Medium, textAlign = TextAlign.Center, maxLines = 1, modifier = Modifier.padding(top = 6.dp))
    }
}

// ---------- home: H8 "Streaks" (owner, 21 Nov 2026: picked H8 of the Home redesign canvas) ----------
/** This month in numbers, worked out on the phone from the attendance month the Attendance screen also shows.
 *  ponytail: this month only (a streak resets on the 1st); read last month too if people ask for longer streaks. */
private class MonthStats(val score: Int?, val streak: Int, val best: Int, val worked: Int, val goal: Int, val present: Int, val late: Int, val absent: Int,
                         val badges: List<Triple<String, Boolean, String>>)

private fun monthStats(att: JsonObject?): MonthStats {
    val today = LocalDate.now()
    val days = att?.a("days").orEmpty().sortedBy { it.s("date") }
    val shift = att?.s("shift").orEmpty().split("–", "-").map { it.trim() }
    val start = runCatching { LocalTime.parse(shift[0]) }.getOrNull()
    val end = runCatching { LocalTime.parse(shift[1]) }.getOrNull()
    val shiftMin = if (start != null && end != null) java.time.Duration.between(start, end).toMinutes().toInt().coerceAtLeast(0) else 480
    val work = days.filter { it.s("status") in setOf("PRESENT", "HALF_DAY", "ABSENT") && it.s("date") <= today.toString() }
    val onTime = { d: JsonObject -> d.s("status") == "PRESENT" && d.str("late") == null }
    val pts = work.sumOf { d -> when { onTime(d) -> 1.0; d.s("status") == "PRESENT" -> 0.6; d.s("status") == "HALF_DAY" -> 0.5; else -> 0.0 } }
    val score = if (work.isEmpty()) null else (100 * pts / work.size).roundToInt()
    var streak = 0
    for (d in work.reversed()) { if (onTime(d)) streak++ else break }
    var best = 0; var run = 0
    for (d in work) { if (onTime(d)) { run++; best = maxOf(best, run) } else run = 0 }
    val byDate = days.associateBy { it.s("date") }
    val ym = YearMonth.now()
    val workDays = (1..ym.lengthOfMonth()).map { ym.atDay(it) }.count { d ->
        val st = byDate[d.toString()]?.s("status")
        when { st in setOf("WEEKLY_OFF", "HOLIDAY") -> false; st == null && d > today && d.dayOfWeek == java.time.DayOfWeek.SUNDAY -> false; else -> true }
    }
    val early = start != null && work.any { d -> runCatching { LocalTime.parse(d.s("in")) <= start.minusMinutes(15) }.getOrDefault(false) }
    val late = work.count { it.str("late") != null }
    val absent = work.count { it.s("status") == "ABSENT" }
    return MonthStats(score, streak, best, minutesOf(att?.s("worked").orEmpty()), workDays * shiftMin, work.count { it.s("status") == "PRESENT" }, late, absent, listOf(
        Triple("Perfect week", best >= 5, "be on time 5 working days in a row"),
        Triple("Early bird", early, "punch in 15 minutes before your shift starts"),
        Triple("Zero late", work.isNotEmpty() && late == 0, "finish the month with no late day"),
        Triple("Full month", today == ym.atEndOfMonth() && absent == 0 && work.isNotEmpty(), "go the whole month without an absent day")))
}

@Composable
private fun Badge(name: String, on: Boolean, modifier: Modifier) {
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(60.dp).clip(CircleShape).background(if (on) Brush.linearGradient(listOf(Color(0xFFF3DC95), Color(0xFFB08433))) else SolidColor(Line))
            .border(if (on) 0.dp else 2.dp, Color(0xFFD9C9A3), CircleShape), contentAlignment = Alignment.Center) {
            Icon(when { !on -> Icons.Outlined.Lock; name == "Early bird" -> Icons.Outlined.WbSunny; name == "Zero late" -> Icons.Outlined.Bolt
                name == "Full month" -> Icons.Outlined.EmojiEvents; else -> Icons.Outlined.Star }, null, tint = if (on) Ink else Color(0xFFB8AA8A), modifier = Modifier.size(26.dp))
        }
        Text(name, color = if (on) Text1 else Text2, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 6.dp))
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun Hub(vm: AppViewModel, go: (String) -> Unit) {
    var tick by remember { mutableIntStateOf(0) }
    val punch = rememberPunch(vm) { tick++; vm.refresh() }
    Load(tick, { vm.ag.get("home") }, cache = "home") { h, reload ->
        val me = h.o("me") ?: JsonObject(emptyMap())
        val today = h.o("today") ?: JsonObject(emptyMap())
        val can = h.o("can") ?: JsonObject(emptyMap())
        val isIn = today.s("last") == "IN"
        val out = today.str("out") != null && !isIn
        val month = YearMonth.now().toString()
        val att = if (can.b("attendance")) rememberAg("attendance:$month") { vm.ag.get("attendance", mapOf("m" to month)) } else null
        val st = monthStats(att)
        val refreshing = LocalAgRefreshing.current
        val pull = rememberPullToRefreshState()
        val initials = me.s("name").split(" ").filter { it.isNotBlank() }.let { w -> (w.firstOrNull()?.take(1).orEmpty() + (if (w.size > 1) w.last().take(1) else "")).uppercase() }
        PullToRefreshBox(refreshing, { reload(); vm.refresh(); AgCache.warm("attendance:$month") { vm.ag.get("attendance", mapOf("m" to month)) } },
            Modifier.fillMaxSize().background(Cream), state = pull, indicator = {
                val f = pull.distanceFraction.coerceIn(0f, 1.4f)
                if (refreshing || f > 0f) AgSpinner(34, Modifier.align(Alignment.TopCenter).statusBarsPadding()
                    .graphicsLayer { translationY = (if (refreshing) 1f else f) * 140f; alpha = if (refreshing) 1f else f.coerceAtMost(1f) }, spinning = refreshing || f >= 1f)
            }) {
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(start = 18.dp, end = 18.dp, bottom = 28.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            // header: me, refresh, bell
            item {
                Row(Modifier.statusBarsPadding().padding(top = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(46.dp).clip(CircleShape).background(Ink).clickable { go("profile") }, contentAlignment = Alignment.Center) {
                        Text(initials, color = GoldHi, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(me.s("name").ifBlank { "Welcome" }, color = Text1, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(listOf(me.s("designation"), me.s("code")).filter { it.isNotBlank() }.joinToString(" · ").ifBlank { me.s("company") }, color = Text2, fontSize = 12.sp, maxLines = 1)
                    }
                    Box(Modifier.size(42.dp).clip(CircleShape).clickable { reload(); vm.refresh() }, contentAlignment = Alignment.Center) {
                        val spin by rememberInfiniteTransition(label = "r").animateFloat(0f, 360f, infiniteRepeatable(tween(900, easing = LinearEasing)), label = "spin")
                        Icon(Icons.Outlined.Refresh, "Refresh", tint = Color(0xFF8A6A25), modifier = Modifier.size(22.dp).graphicsLayer { rotationZ = if (refreshing) spin else 0f })
                    }
                    Box(Modifier.size(42.dp).clip(CircleShape).background(Color.White).border(1.dp, Line, CircleShape).clickable { go("alerts") }, contentAlignment = Alignment.Center) {
                        Icon(Icons.Outlined.Notifications, "Notifications", tint = Text1, modifier = Modifier.size(21.dp))
                        if (h.n("unread") > 0) Text(if (h.n("unread") > 9) "9+" else "${h.n("unread")}", color = Color.White, fontSize = 9.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.align(Alignment.TopEnd).clip(CircleShape).background(Bad).padding(horizontal = 5.dp, vertical = 1.dp))
                    }
                }
            }
            // attendance score
            if (can.b("attendance")) item {
                Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(28.dp)).background(Brush.linearGradient(listOf(Color(0xFF0B0906), Ink, Ink2)))
                    .clickable { go("attendance") }.padding(20.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(112.dp), contentAlignment = Alignment.Center) {
                        val pct by animateFloatAsState((st.score ?: 0) / 100f, tween(900), label = "score")
                        Canvas(Modifier.fillMaxSize()) {
                            val w = 10.dp.toPx(); val tl = Offset(w / 2, w / 2); val sz = Size(size.width - w, size.height - w)
                            drawArc(Color(0x1AFFFFFF), 0f, 360f, false, tl, sz, style = Stroke(w))
                            drawArc(Brush.sweepGradient(listOf(Gold, GoldHi, Gold)), -90f, 360f * pct, false, tl, sz, style = Stroke(w, cap = StrokeCap.Round))
                        }
                        Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            Text(st.score?.toString() ?: "—", color = GoldHi, fontSize = 32.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 34.sp)
                            Text("SCORE", color = Color(0xFFBFAE86), fontSize = 10.sp, fontWeight = FontWeight.SemiBold, letterSpacing = 1.sp)
                        }
                    }
                    Spacer(Modifier.width(18.dp))
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("ATTENDANCE SCORE", color = Color(0xFFBFAE86), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, letterSpacing = 1.sp)
                        Text(when { st.score == null -> "Starts with your first working day"; st.score >= 90 -> "Excellent. Keep it going."; st.score >= 75 -> "Good. On time, every day, moves this up."
                            else -> "On time, every day, moves this up." }, color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold, lineHeight = 20.sp)
                        Text("${st.present} present · ${st.late} late · ${st.absent} absent", color = Color(0xFFD9C9A3), fontSize = 12.sp)
                    }
                }
            }
            // streak + hours goal
            if (can.b("attendance")) item {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Column(Modifier.weight(1f).clip(RoundedCornerShape(22.dp)).background(Color.White).border(1.dp, Line, RoundedCornerShape(22.dp)).padding(16.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Outlined.LocalFireDepartment, null, tint = Color(0xFFEA580C), modifier = Modifier.size(22.dp)); Spacer(Modifier.width(6.dp))
                            Text("On-time streak", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        }
                        Row(verticalAlignment = Alignment.Bottom, modifier = Modifier.padding(top = 6.dp)) {
                            Text("${st.streak}", color = Text1, fontSize = 30.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 32.sp)
                            Text(if (st.streak == 1) " day" else " days", color = Text2, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(bottom = 4.dp))
                        }
                        Text("Best this month: ${st.best}", color = Text2, fontSize = 12.sp)
                    }
                    Column(Modifier.weight(1f).clip(RoundedCornerShape(22.dp)).background(Color.White).border(1.dp, Line, RoundedCornerShape(22.dp)).padding(16.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Outlined.Schedule, null, tint = Color(0xFFB08433), modifier = Modifier.size(22.dp)); Spacer(Modifier.width(6.dp))
                            Text("Hours goal", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        }
                        Row(verticalAlignment = Alignment.Bottom, modifier = Modifier.padding(top = 6.dp)) {
                            Text("${st.worked / 60}", color = Text1, fontSize = 30.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 32.sp)
                            Text(" / ${st.goal / 60}h", color = Text2, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(bottom = 4.dp))
                        }
                        Box(Modifier.padding(top = 8.dp).fillMaxWidth().height(6.dp).clip(RoundedCornerShape(3.dp)).background(Line)) {
                            Box(Modifier.fillMaxWidth(if (st.goal > 0) (st.worked.toFloat() / st.goal).coerceIn(0f, 1f) else 0f).fillMaxHeight().background(Gold))
                        }
                    }
                }
            }
            // punch
            item {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    if (can.b("scan")) Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp))
                        .background(if (isIn) Brush.linearGradient(listOf(Ink2, Ink)) else Brush.linearGradient(listOf(Color(0xFFF3DC95), Gold)))
                        .clickable(onClick = punch).padding(horizontal = 18.dp, vertical = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Outlined.QrCodeScanner, null, tint = if (isIn) GoldHi else Ink, modifier = Modifier.size(30.dp))
                        Spacer(Modifier.width(14.dp))
                        Column(Modifier.weight(1f)) {
                            Text(if (isIn) "Punch out" else "Punch in", color = if (isIn) GoldHi else Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                            Text(when {
                                isIn -> "In since ${today.s("in")} · worked ${today.s("worked")}"
                                out -> "Punched out at ${today.s("out")} · worked ${today.s("worked")}"
                                st.streak > 0 -> "Scan the office QR · keep your ${st.streak}-day streak"
                                else -> "Scan the office QR to start your day"
                            }, color = if (isIn) Color(0xFFD9C9A3) else Ink.copy(alpha = 0.75f), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        }
                    }
                    val punches = today.a("punches")
                    if (punches.isNotEmpty()) Card {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text("Today's log", color = Text1, fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            if (can.b("attendance")) Text("History ›", color = Warn, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.clickable { go("attendance") })
                        }
                        Spacer(Modifier.height(8.dp))
                        PunchTimeline(punches)
                    }
                }
            }
            // badges
            if (can.b("attendance")) item {
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text("Badges this month", color = Text1, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                    Row { st.badges.forEach { (n, on, _) -> Badge(n, on, Modifier.weight(1f)) } }
                    st.badges.firstOrNull { !it.second }?.let { (n, _, how) ->
                        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Color(0xFFFFF7E6)).border(1.dp, Color(0xFFF3DC95), RoundedCornerShape(20.dp)).padding(14.dp),
                            verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Outlined.Lightbulb, null, tint = Warn, modifier = Modifier.size(22.dp)); Spacer(Modifier.width(12.dp))
                            Text("Next badge: $how to earn $n.", color = Text1, fontSize = 13.sp, lineHeight = 18.sp)
                        }
                    }
                }
            }
            // apps
            item {
                val apps = buildList {
                    if (can.b("admin")) add(Tile("admin", "Team", "", Icons.Outlined.Groups))
                    if (can.b("leave")) add(Tile("leave", "Leave", "", Icons.Outlined.BeachAccess, h.n("pending_leave")))
                    if (can.b("payslips")) add(Tile("payslips", "Payslips", "", Icons.Outlined.Payments))
                    if (can.b("productivity")) add(Tile("productivity", "Productivity", "", Icons.Outlined.Insights))
                    if (can.b("corrections")) add(Tile("corrections", "Corrections", "", Icons.Outlined.EditCalendar))
                    if (can.b("attendance")) add(Tile("attendance", "Attendance", "", Icons.Outlined.History))
                    if (can.b("field")) add(Tile("field", "Field work", "", Icons.Outlined.Route))
                    if (can.b("letters")) add(Tile("letters", "Letters", "", Icons.Outlined.Description))
                    if (can.b("assets")) add(Tile("assets", "Assets", "", Icons.Outlined.Inventory2))
                    add(Tile("support", "Support", "", Icons.Outlined.SupportAgent))
                    add(Tile("profile", "Profile", "", Icons.Outlined.Person))
                }
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("Apps", color = Text1, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                    apps.chunked(4).forEach { row ->
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            row.forEach { t ->
                                Column(Modifier.weight(1f).clip(RoundedCornerShape(18.dp)).background(Color.White).border(1.dp, Line, RoundedCornerShape(18.dp))
                                    .clickable { go(t.key) }.padding(vertical = 12.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                                    Box {
                                        Icon(t.icon, null, tint = Color(0xFFB08433), modifier = Modifier.size(24.dp))
                                        if (t.badge > 0) Box(Modifier.align(Alignment.TopEnd).offset(x = 6.dp, y = (-4).dp).size(10.dp).clip(CircleShape).background(Bad))
                                    }
                                    Text(t.label, color = Text1, fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, modifier = Modifier.padding(top = 6.dp))
                                }
                            }
                            repeat(4 - row.size) { Spacer(Modifier.weight(1f)) }
                        }
                    }
                }
            }
            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
                    Mark(16); Spacer(Modifier.width(6.dp))
                    Text("Ametecs Global · one platform, every app", color = Text2, fontSize = 11.sp)
                }
            }
        }
        }
    }
}

/** A day's punches as a timeline: each punch, and the worked / break stretch between two punches. */
@Composable
private fun PunchTimeline(punches: List<JsonObject>) {
    val mins = { t: String -> runCatching { LocalTime.parse(t).let { it.hour * 60 + it.minute } }.getOrNull() }
    punches.forEachIndexed { i, p ->
        val isIn = p.s("dir") == "IN"
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(vertical = 2.dp)) {
            Box(Modifier.size(12.dp).clip(CircleShape).background(if (isIn) Ok else Color(0xFF2563EB)))
            Spacer(Modifier.width(10.dp))
            Text(p.s("at"), color = Text1, fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.width(56.dp))
            Text(if (isIn) "In" else "Out", color = if (isIn) Ok else Color(0xFF2563EB), fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.width(36.dp))
            Spacer(Modifier.weight(1f))
            Text(p.s("src"), color = Text2, fontSize = 12.sp, modifier = Modifier.clip(RoundedCornerShape(8.dp)).background(Cream).padding(horizontal = 8.dp, vertical = 3.dp))
        }
        punches.getOrNull(i + 1)?.let { n ->
            val a = mins(p.s("at")); val b = mins(n.s("at"))
            if (a != null && b != null && b >= a) {
                val gap = b - a
                val work = isIn && n.s("dir") == "OUT"
                Row(Modifier.padding(start = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.width(2.dp).height(22.dp).background(if (work) Ok.copy(alpha = 0.35f) else Line))
                    Spacer(Modifier.width(18.dp))
                    Text((if (work) "Worked " else if (!isIn && n.s("dir") == "IN") "Break " else "Gap ") + "%dh %02dm".format(gap / 60, gap % 60),
                        color = if (work) Ok else Text2, fontSize = 12.sp)
                }
            }
        }
    }
}

// ---------- attendance history ----------
private val statusTint = mapOf("Present" to Ok, "Half day" to Warn, "Absent" to Bad, "Leave" to Color(0xFF2563EB), "Unpaid leave" to Color(0xFF7C3AED),
    "Weekly off" to Text2, "Holiday" to Color(0xFF0F766E), "Off / holiday" to Text2, "Late" to Color(0xFFEA580C))

@Composable
private fun AttendancePage(vm: AppViewModel, back: () -> Unit, go: (String) -> Unit) {
    var month by rememberSaveable { mutableStateOf(YearMonth.now().toString()) }
    var filter by rememberSaveable { mutableStateOf("All") }
    var open by rememberSaveable { mutableStateOf("") }
    val ym = YearMonth.parse(month)
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Attendance history", back)
        Row(Modifier.fillMaxWidth().background(Ink).padding(horizontal = 8.dp).padding(bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Outlined.ChevronLeft, "Previous month", tint = GoldHi, modifier = Modifier.size(40.dp).clip(CircleShape).clickable { month = ym.minusMonths(1).toString() }.padding(8.dp))
            Text(ym.format(DateTimeFormatter.ofPattern("MMMM yyyy")), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
            val canNext = ym < YearMonth.now()
            Icon(Icons.Outlined.ChevronRight, "Next month", tint = if (canNext) GoldHi else Color(0x55E8C977),
                modifier = Modifier.size(40.dp).clip(CircleShape).clickable(enabled = canNext) { month = ym.plusMonths(1).toString() }.padding(8.dp))
        }
        Load(month, { vm.ag.get("attendance", mapOf("m" to month)) }, cache = "attendance:$month") { d, _ ->
            val all = d.a("days")
            val days = when (filter) {
                "Late" -> all.filter { it.str("late") != null }
                "Needs fix" -> all.filter { it.b("fix") }
                "All" -> all
                else -> all.filter { it.s("status_label") == filter || (filter == "Leave" && it.s("status_label") == "Unpaid leave") }
            }
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item {
                    val sum = (d["summary"] as? JsonArray).orEmpty().mapNotNull { it as? JsonArray }
                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(Color(0xFF0B0906), Ink, Ink2))).padding(18.dp)) {
                        Row(verticalAlignment = Alignment.Bottom) {
                            Column(Modifier.weight(1f)) {
                                Text("Worked this month", color = Color(0xFFBFAE86), fontSize = 12.sp)
                                Text(d.s("worked"), color = GoldHi, fontWeight = FontWeight.ExtraBold, fontSize = 30.sp)
                            }
                            d.str("shift")?.let { Column(horizontalAlignment = Alignment.End) {
                                Text("Shift", color = Color(0xFFBFAE86), fontSize = 11.sp)
                                Text(it, color = Color.White, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                            } }
                        }
                        Spacer(Modifier.height(14.dp))
                        sum.chunked(3).forEach { row ->
                            Row(Modifier.padding(vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                row.forEach { p ->
                                    val label = p[0].jsonPrimitive.content
                                    val tint = statusTint[label] ?: GoldHi
                                    Column(Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(Color(0x14FFFFFF)).padding(10.dp)) {
                                        Row(verticalAlignment = Alignment.CenterVertically) {
                                            Box(Modifier.size(8.dp).clip(CircleShape).background(if (tint == Text2) Color(0xFF9CA3AF) else tint))
                                            Spacer(Modifier.width(6.dp))
                                            Text(p[1].jsonPrimitive.content, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                                        }
                                        Text(label, color = Color(0xFFD9C9A3), fontSize = 11.sp)
                                    }
                                }
                            }
                        }
                    }
                }
                item {
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(listOf("All", "Present", "Late", "Half day", "Absent", "Leave", "Weekly off", "Holiday", "Needs fix")) { f -> Chip(f, filter == f) { filter = f } }
                    }
                }
                item {
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(GoldTint).clickable { go("correct") }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Outlined.EditCalendar, null, tint = Warn); Spacer(Modifier.width(8.dp))
                        Text("Missed a punch? Request a correction", color = Warn, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.weight(1f))
                        Icon(Icons.Outlined.ChevronRight, null, tint = Warn)
                    }
                }
                if (days.isEmpty()) item { Text(if (all.isEmpty()) "Nothing recorded for this month." else "No days match \"$filter\".", color = Text2, modifier = Modifier.padding(16.dp)) }
                items(days) { day ->
                    val st = day.s("status_label")
                    val tint = statusTint[st] ?: Text2
                    val expanded = open == day.s("date")
                    val date = runCatching { LocalDate.parse(day.s("date")) }.getOrNull()
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Color.White).border(1.dp, if (expanded) Gold else Line, RoundedCornerShape(20.dp))
                        .clickable { open = if (expanded) "" else day.s("date") }) {
                        Column(Modifier.weight(1f).padding(14.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.size(52.dp).clip(RoundedCornerShape(14.dp)).background(tint.copy(alpha = 0.10f)), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                                    Text(date?.dayOfMonth?.toString() ?: "", color = if (tint == Text2) Text1 else tint, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, lineHeight = 22.sp)
                                    Text(day.s("day").uppercase(), color = Text2, fontSize = 10.sp, fontWeight = FontWeight.SemiBold)
                                }
                                Spacer(Modifier.width(12.dp))
                                Column(Modifier.weight(1f)) {
                                    Text(day.s("label"), color = Text1, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                                    Text(listOf(day.str("in")?.let { "In $it" }, day.str("out")?.let { "Out $it" }).filterNotNull().joinToString("  ·  ").ifBlank { if (day.b("planned")) "Planned" else "No punches" },
                                        color = Text2, fontSize = 12.sp)
                                }
                                Pill(st, tint)
                            }
                            if (day.a("punches").isNotEmpty() || day.str("late") != null) {
                                Spacer(Modifier.height(12.dp))
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    listOf(Triple("Worked", day.s("worked"), Text1), Triple("Late", day.str("late"), Color(0xFFEA580C)),
                                        Triple("Early out", day.str("early"), Warn), Triple("Overtime", day.str("ot"), Ok), Triple("Break", day.str("break"), Text2))
                                        .filter { !it.second.isNullOrBlank() && it.second != "—" }.take(4).forEach { (k, v, c) ->
                                            Column(Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(Cream).padding(horizontal = 8.dp, vertical = 6.dp)) {
                                                Text(k, color = Text2, fontSize = 10.sp)
                                                Text(v!!, color = c, fontWeight = FontWeight.Bold, fontSize = 13.sp, maxLines = 1)
                                            }
                                        }
                                }
                            }
                            if (day.b("fix")) Row(Modifier.padding(top = 10.dp).clip(RoundedCornerShape(10.dp)).background(Bad.copy(alpha = 0.08f)).clickable { go("correct") }.padding(horizontal = 10.dp, vertical = 6.dp),
                                verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Outlined.ErrorOutline, null, tint = Bad, modifier = Modifier.size(16.dp)); Spacer(Modifier.width(6.dp))
                                Text("Needs a correction · tap to request", color = Bad, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                            }
                            if (expanded) {
                                Spacer(Modifier.height(12.dp))
                                Box(Modifier.fillMaxWidth().height(1.dp).background(Line))
                                Spacer(Modifier.height(10.dp))
                                Text("Punch log", color = Text1, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                                Spacer(Modifier.height(6.dp))
                                val ps = day.a("punches")
                                if (ps.isEmpty()) Text("No punches recorded this day.", color = Text2, fontSize = 12.sp) else PunchTimeline(ps)
                                day.s("why").takeIf { it.isNotBlank() }?.let { Text(it, color = Text2, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp)) }
                            } else if (day.a("punches").isNotEmpty()) {
                                Text("${day.a("punches").size} punches · tap for the log", color = Warn, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp))
                            }
                        }
                    }
                }
            }
        }
    }
}

// ---------- leave ----------
@Composable
private fun LeavePage(vm: AppViewModel, back: () -> Unit, go: (String) -> Unit) {
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Leave", back)
        Load(Unit, { vm.ag.get("leave") }, cache = "leave") { d, _ ->
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                item {
                    Text("Your balance", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.height(8.dp))
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        items(d.a("types").filter { it.b("paid") }) { t ->
                            Column(Modifier.width(140.dp).clip(RoundedCornerShape(16.dp)).background(Color.White).border(1.dp, Line, RoundedCornerShape(16.dp)).padding(14.dp)) {
                                Text(t.s("available"), color = Ink, fontSize = 26.sp, fontWeight = FontWeight.ExtraBold)
                                Text(t.s("name"), color = Text1, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 2)
                                Text("balance ${t.s("balance")} · pending ${t.s("pending")}", color = Text2, fontSize = 11.sp)
                            }
                        }
                    }
                }
                if (d.b("can_apply")) item { GoldButton("Apply for leave", Icons.Outlined.EventAvailable) { go("leave-apply") } }
                item { Text("My requests", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
                val reqs = d.a("requests")
                if (reqs.isEmpty()) item { Text("No leave requests yet.", color = Text2) }
                items(reqs) { r ->
                    Card {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(r.s("type"), color = Text1, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            Pill(r.s("state").replaceFirstChar { it.uppercase() }, stateColor(r.s("state")))
                        }
                        Text(if (r.s("from") == r.s("to")) r.s("from") else "${r.s("from")} → ${r.s("to")}", color = Text1, fontSize = 14.sp, modifier = Modifier.padding(top = 4.dp))
                        Text("${r.s("days")} day(s)" + (r.str("half")?.let { " · $it" } ?: ""), color = Text2, fontSize = 12.sp)
                        if (r.s("reason").isNotBlank()) Text(r.s("reason"), color = Text2, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
                        if (r.s("by").isNotBlank()) Text("By ${r.s("by")}" + (r.s("note").takeIf { it.isNotBlank() }?.let { ": $it" } ?: ""), color = Text2, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
                    }
                }
            }
        }
    }
}

private fun pickDate(ctx: Context, current: String, set: (String) -> Unit) {
    val d = runCatching { LocalDate.parse(current) }.getOrDefault(LocalDate.now())
    DatePickerDialog(ctx, { _, y, m, day -> set(LocalDate.of(y, m + 1, day).toString()) }, d.year, d.monthValue - 1, d.dayOfMonth).show()
}

private fun pickTime(ctx: Context, set: (String) -> Unit) {
    val t = LocalTime.now()
    TimePickerDialog(ctx, { _, h, m -> set("%02d:%02d".format(h, m)) }, t.hour, t.minute, false).show()
}

private fun dmy(iso: String) = runCatching { LocalDate.parse(iso).format(DateTimeFormatter.ofPattern("dd-MM-yyyy")) }.getOrDefault(iso)

@Composable
private fun Chip(text: String, on: Boolean, click: () -> Unit) {
    Text(text, color = if (on) Ink else Text1, fontSize = 13.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Normal,
        modifier = Modifier.clip(RoundedCornerShape(50)).background(if (on) Gold else Color.White).border(1.dp, if (on) Gold else Line, RoundedCornerShape(50))
            .clickable(onClick = click).padding(horizontal = 14.dp, vertical = 8.dp))
}

@Composable
private fun LeaveApply(vm: AppViewModel, back: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var type by rememberSaveable { mutableStateOf("") }
    var from by rememberSaveable { mutableStateOf(LocalDate.now().toString()) }
    var to by rememberSaveable { mutableStateOf(LocalDate.now().toString()) }
    var half by rememberSaveable { mutableStateOf("") }
    var reason by rememberSaveable { mutableStateOf("") }
    var unpaid by rememberSaveable { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var msg by remember { mutableStateOf<Pair<Boolean, String>?>(null) }
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Apply for leave", back)
        Load(Unit, { vm.ag.get("leave") }) { d, _ ->
            val types = d.a("types")
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                Text("Leave type", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                types.chunked(2).forEach { row ->
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        row.forEach { t -> Chip("${t.s("name")}" + if (t.b("paid")) " (${t.s("available")})" else "", type == t.s("code")) { type = t.s("code") } }
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Card(Modifier.weight(1f), onClick = { pickDate(ctx, from) { from = it; if (to < it) to = it } }) { Field("From", dmy(from)) }
                    Card(Modifier.weight(1f), onClick = { pickDate(ctx, to) { to = if (it < from) from else it } }) { Field("To", dmy(to)) }
                }
                if (from == to) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Chip("Full day", half == "") { half = "" }
                    Chip("First half", half == "first") { half = "first" }
                    Chip("Second half", half == "second") { half = "second" }
                }
                OutlinedTextField(reason, { reason = it }, label = { Text("Reason") }, modifier = Modifier.fillMaxWidth(), minLines = 2)
                if (msg?.second?.contains("available") == true && msg?.first == false) Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(unpaid, { unpaid = it })
                    Text("Take the days beyond my balance as unpaid leave", color = Text1, fontSize = 13.sp)
                }
                msg?.let { (ok, m) -> Text(m, color = if (ok) Ok else Bad, fontSize = 14.sp) }
                GoldButton(if (busy) "Sending…" else "Submit request", enabled = !busy && type.isNotBlank()) {
                    busy = true
                    scope.launch {
                        val r = runCatching {
                            vm.ag.action("leave-request", mapOf("employment_code" to d.s("code"), "leave_type" to type, "from" to from, "to" to (if (half != "") from else to),
                                "half" to half, "reason" to reason, "accept_unpaid" to if (unpaid) "1" else "0"))
                        }.getOrElse { fail(it.message ?: "Could not send. Try again.") }
                        busy = false
                        msg = r.b("ok") to r.s("message")
                        if (r.b("ok")) back()
                    }
                }
            }
        }
    }
}

// ---------- payslips ----------
@Composable
private fun PayslipsPage(vm: AppViewModel, back: () -> Unit, go: (String) -> Unit) {
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Payslips", back)
        Load(Unit, { vm.ag.get("payslips") }, cache = "payslips") { d, _ ->
            val list = d.a("payslips")
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (list.isEmpty()) item { Text("No payslips yet. A payslip appears here once that month's payroll is closed.", color = Text2) }
                items(list) { p ->
                    Card(onClick = { go("payslip:" + p.s("period")) }) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(p.s("label"), color = Text1, fontWeight = FontWeight.Bold, fontSize = 16.sp)
                                Text("${p.s("payable_days")} of ${p.s("period_days")} days paid", color = Text2, fontSize = 12.sp)
                            }
                            Column(horizontalAlignment = Alignment.End) {
                                Text(p.s("net"), color = Ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                                Text("net pay", color = Text2, fontSize = 11.sp)
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun PayslipPage(vm: AppViewModel, period: String, back: () -> Unit) {
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Payslip", back)
        Load(period, { vm.ag.get("payslip", mapOf("period" to period)) }, cache = "payslip:$period") { d, _ ->
            val s = d.o("slip") ?: JsonObject(emptyMap())
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                item {
                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Brush.linearGradient(listOf(Ink, Ink2))).padding(20.dp)) {
                        Text(s.s("label"), color = Color(0xFFD9C9A3), fontSize = 14.sp)
                        Text(s.s("net"), color = GoldHi, fontSize = 32.sp, fontWeight = FontWeight.ExtraBold)
                        Text("Net pay · ${s.s("net_in_words")}", color = Color(0xFFD9C9A3), fontSize = 12.sp)
                        Spacer(Modifier.height(8.dp))
                        Text("${s.s("name")} · ${s.s("code")} · ${s.s("payable_days")} / ${s.s("period_days")} days", color = Color.White, fontSize = 13.sp)
                    }
                }
                listOf("Earnings" to "earnings", "Deductions" to "deductions").forEach { (title, key) ->
                    item {
                        Card {
                            Row { Text(title, color = Text1, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                Text(s.s(if (key == "earnings") "earnings_total" else "deductions_total"), color = Text1, fontWeight = FontWeight.Bold) }
                            Spacer(Modifier.height(6.dp))
                            (s[key] as? JsonArray).orEmpty().mapNotNull { it as? JsonArray }.forEach { l ->
                                Row(Modifier.padding(vertical = 3.dp)) {
                                    Text(l[0].jsonPrimitive.content.replace('_', ' '), color = Text2, fontSize = 13.sp, modifier = Modifier.weight(1f))
                                    Text(l[1].jsonPrimitive.content, color = Text1, fontSize = 13.sp)
                                }
                            }
                        }
                    }
                }
                if (!s.b("approved")) item { Text("This payroll run is not approved yet.", color = Warn, fontSize = 12.sp) }
            }
        }
    }
}

// ---------- attendance corrections ----------
@Composable
private fun CorrectionsPage(vm: AppViewModel, back: () -> Unit, go: (String) -> Unit) {
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Attendance corrections", back)
        Load(Unit, { vm.ag.get("corrections") }, cache = "corrections") { d, _ ->
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item { GoldButton("Request a correction", Icons.Outlined.EditCalendar) { go("correct") } }
                val rs = d.a("requests")
                if (rs.isEmpty()) item { Text("No correction requests yet.", color = Text2) }
                items(rs) { r ->
                    Card {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text("${r.s("date")} · ${r.s("dir")} ${r.s("at")}", color = Text1, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            Pill(r.s("state").replaceFirstChar { it.uppercase() }, stateColor(r.s("state")))
                        }
                        if (r.s("reason").isNotBlank()) Text(r.s("reason"), color = Text2, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
                        if (r.s("note").isNotBlank()) Text(r.s("note"), color = Text2, fontSize = 12.sp)
                    }
                }
            }
        }
    }
}

@Composable
private fun CorrectionForm(vm: AppViewModel, back: () -> Unit) {
    val ctx = LocalContext.current
    val scope = rememberCoroutineScope()
    var date by rememberSaveable { mutableStateOf(LocalDate.now().toString()) }
    var tin by rememberSaveable { mutableStateOf("") }
    var tout by rememberSaveable { mutableStateOf("") }
    var reason by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var msg by remember { mutableStateOf<Pair<Boolean, String>?>(null) }
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Request a correction", back)
        Load(Unit, { vm.ag.get("corrections") }) { d, _ ->
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                Card(onClick = { pickDate(ctx, date) { date = it } }) { Field("Date", dmy(date)) }
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Card(Modifier.weight(1f), onClick = { pickTime(ctx) { tin = it } }) { Field("Check in", tin) }
                    Card(Modifier.weight(1f), onClick = { pickTime(ctx) { tout = it } }) { Field("Check out", tout) }
                }
                Text("Give the check-in, the check-out, or both.", color = Text2, fontSize = 12.sp)
                OutlinedTextField(reason, { reason = it }, label = { Text("Reason") }, modifier = Modifier.fillMaxWidth(), minLines = 2)
                msg?.let { (ok, m) -> Text(m, color = if (ok) Ok else Bad, fontSize = 14.sp) }
                GoldButton(if (busy) "Sending…" else "Send for approval", enabled = !busy && (tin.isNotBlank() || tout.isNotBlank()) && reason.isNotBlank()) {
                    busy = true
                    scope.launch {
                        val r = runCatching {
                            vm.ag.action("correction-raise", mapOf("employment_code" to d.s("code"), "reason" to reason,
                                "in_datetime" to if (tin.isBlank()) "" else "${date}T$tin", "out_datetime" to if (tout.isBlank()) "" else "${date}T$tout"))
                        }.getOrElse { fail(it.message ?: "Could not send. Try again.") }
                        busy = false
                        msg = r.b("ok") to r.s("message")
                        if (r.b("ok")) back()
                    }
                }
            }
        }
    }
}

// ---------- another app's view of me (SmartEPT productivity) ----------
private fun hm(sec: Int) = if (sec <= 0) "0m" else if (sec < 3600) "${sec / 60}m" else "%dh %02dm".format(sec / 3600, sec / 60 % 60)

@Composable
private fun PersonPage(vm: AppViewModel, app: String, title: String, back: () -> Unit) {
    val ctx = LocalContext.current
    val now = LocalDate.now()
    var range by rememberSaveable { mutableStateOf("today") }
    var cFrom by rememberSaveable { mutableStateOf(now.withDayOfMonth(1).toString()) }
    var cTo by rememberSaveable { mutableStateOf(now.toString()) }
    var kind by rememberSaveable { mutableStateOf("all") }
    val (from, to) = when (range) {
        "today" -> now to now
        "yesterday" -> now.minusDays(1) to now.minusDays(1)
        "week" -> now.minusDays(6) to now
        "month" -> now.withDayOfMonth(1) to now
        "last" -> now.minusMonths(1).withDayOfMonth(1) to now.withDayOfMonth(1).minusDays(1)
        else -> LocalDate.parse(cFrom) to LocalDate.parse(cTo)
    }
    Page(title, back) {
        item {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(listOf("today" to "Today", "yesterday" to "Yesterday", "week" to "Last 7 days", "month" to "This month", "last" to "Last month", "custom" to "Custom")) { (k, l) ->
                    Chip(l, range == k) { range = k }
                }
            }
        }
        if (range == "custom") item {
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Card(Modifier.weight(1f), onClick = { pickDate(ctx, cFrom) { cFrom = it; if (cTo < it) cTo = it } }) { Field("From", dmy(cFrom)) }
                Card(Modifier.weight(1f), onClick = { pickDate(ctx, cTo) { cTo = if (it < cFrom) cFrom else it } }) { Field("To", dmy(cTo)) }
            }
        }
        item {
            Load("$from|$to", { vm.ag.get("person", mapOf("app" to app, "from" to from.toString(), "to" to to.toString())) }, cache = "person:$app:$from:$to") { d, _ ->
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    d.str("note")?.let { Card { Text(it, color = Text2) } }
                    val tiles = (d["tiles"] as? JsonArray).orEmpty().mapNotNull { it as? JsonArray }
                    val split = (d["split"] as? JsonArray).orEmpty().mapNotNull { it as? JsonArray }.map { it[0].jsonPrimitive.content to (it[1].jsonPrimitive.content.toIntOrNull() ?: 0) }
                    if (split.isNotEmpty()) {
                        // hero: score, active time and how the time split
                        val score = tiles.firstOrNull()?.get(1)?.jsonPrimitive?.content ?: "—"
                        val colors = listOf(Color(0xFF4ADE80), Color(0xFFF59E0B), Color(0xFF60A5FA), Color(0xFFA78BFA))
                        val total = split.sumOf { it.second }.coerceAtLeast(1)
                        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(Color(0xFF0B0906), Ink, Ink2))).padding(18.dp)) {
                            Row(verticalAlignment = Alignment.Bottom) {
                                Column(Modifier.weight(1f)) {
                                    Text("Productivity", color = Color(0xFFBFAE86), fontSize = 12.sp)
                                    Text(score, color = GoldHi, fontWeight = FontWeight.ExtraBold, fontSize = 34.sp)
                                }
                                Column(horizontalAlignment = Alignment.End) {
                                    Text("At the PC", color = Color(0xFFBFAE86), fontSize = 11.sp)
                                    Text(hm(d.n("present").takeIf { it > 0 } ?: total), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                                }
                            }
                            Spacer(Modifier.height(14.dp))
                            Row(Modifier.fillMaxWidth().height(12.dp).clip(RoundedCornerShape(6.dp)).background(Color(0x22FFFFFF))) {
                                split.forEachIndexed { i, (_, v) -> if (v > 0) Box(Modifier.weight(v.toFloat()).fillMaxSize().background(colors[i % 4])) }
                            }
                            Spacer(Modifier.height(12.dp))
                            split.chunked(2).forEach { row ->
                                Row(Modifier.padding(vertical = 3.dp)) {
                                    row.forEach { (k, v) ->
                                        val c = colors[split.indexOfFirst { it.first == k } % 4]
                                        Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically) {
                                            Box(Modifier.size(10.dp).clip(CircleShape).background(c)); Spacer(Modifier.width(8.dp))
                                            Text(k, color = Color(0xFFD9C9A3), fontSize = 12.sp, modifier = Modifier.weight(1f))
                                            Text("${hm(v)} · ${v * 100 / total}%", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(end = 12.dp))
                                        }
                                    }
                                }
                            }
                        }
                    }
                    // the four headline numbers (violations, PCs...)
                    tiles.drop(if (split.isNotEmpty()) 2 else 0).chunked(2).forEach { row ->
                        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            row.forEach { t ->
                                Card(Modifier.weight(1f)) {
                                    Text(t[1].jsonPrimitive.content, color = Ink, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text(t[0].jsonPrimitive.content, color = Text1, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                                    Text(t.getOrNull(2)?.jsonPrimitive?.content.orEmpty(), color = Text2, fontSize = 11.sp, maxLines = 2)
                                }
                            }
                            if (row.size == 1) Spacer(Modifier.weight(1f))
                        }
                    }
                    val breaks = (d["breaks"] as? JsonArray).orEmpty().mapNotNull { it as? JsonArray }
                    if (breaks.isNotEmpty()) Card {
                        Text("Breaks", color = Text1, fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(6.dp))
                        breaks.forEach { b ->
                            Row(Modifier.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                                Text(b[0].jsonPrimitive.content, color = Text1, fontSize = 14.sp, modifier = Modifier.weight(1f))
                                Text("${b[2].jsonPrimitive.content}×", color = Text2, fontSize = 12.sp, modifier = Modifier.padding(end = 12.dp))
                                Text(hm(b[1].jsonPrimitive.content.toIntOrNull() ?: 0), color = Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                            }
                        }
                    }
                    // day by day
                    val days = d.a("days")
                    if (days.size > 1) {
                        Text("Day by day", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        days.forEach { x ->
                            Card {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Column(Modifier.weight(1f)) {
                                        Text(dmy(x.s("date")), color = Text1, fontWeight = FontWeight.Bold)
                                        Text("PC ${x.s("in").ifBlank { "—" }} – ${x.s("out").ifBlank { "—" }}", color = Text2, fontSize = 12.sp)
                                    }
                                    Text("${x.s("score")}%", color = Ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                                }
                                Spacer(Modifier.height(8.dp))
                                Row {
                                    listOf("Active" to x.n("active"), "Idle" to x.n("idle"), "Break" to x.n("break"), "Away" to x.n("away")).forEach { (k, v) ->
                                        Column(Modifier.weight(1f)) { Text(k, color = Text2, fontSize = 10.sp); Text(hm(v), color = Text1, fontWeight = FontWeight.SemiBold, fontSize = 13.sp) }
                                    }
                                }
                            }
                        }
                    }
                    // the full log, filterable
                    val ev = d.a("events")
                    if (d["events"] != null) {
                        Text("Activity log", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            items(listOf("all" to "All", "session" to "PC sessions", "break" to "Breaks", "lunch" to "Lunch", "idle" to "Idle / away")) { (k, l) ->
                                Chip("$l (${if (k == "all") ev.size else ev.count { it.s("kind") == k }})", kind == k) { kind = k }
                            }
                        }
                        val shown = if (kind == "all") ev else ev.filter { it.s("kind") == kind }
                        if (shown.isEmpty()) Text("Nothing recorded for this filter.", color = Text2, modifier = Modifier.padding(8.dp))
                        shown.groupBy { it.s("from").take(10) }.forEach { (day, list) ->
                            Text(dmy(day), color = Text1, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
                            Card {
                                list.forEachIndexed { i, e ->
                                    val c = when (e.s("kind")) { "session" -> Ok; "lunch" -> Color(0xFFB08433); "break" -> Color(0xFF2563EB); else -> Warn }
                                    Row(Modifier.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                                        Box(Modifier.size(10.dp).clip(CircleShape).background(c)); Spacer(Modifier.width(10.dp))
                                        Column(Modifier.weight(1f)) {
                                            Text(e.s("label"), color = Text1, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                                            Text("${e.s("from").drop(11).take(5)} – ${e.str("to")?.drop(11)?.take(5) ?: "now"}" + (e.s("note").takeIf { it.isNotBlank() }?.let { " · $it" } ?: ""),
                                                color = Text2, fontSize = 12.sp)
                                        }
                                        Text(hm(e.n("sec")), color = c, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                                    }
                                    if (i < list.size - 1) Box(Modifier.fillMaxWidth().height(1.dp).background(Line))
                                }
                            }
                        }
                    } else {
                        (d["rows"] as? JsonArray).orEmpty().mapNotNull { it as? JsonArray }.forEach { r ->
                            Card {
                                Text(dmy(r[0].jsonPrimitive.content.take(10)), color = Text1, fontWeight = FontWeight.Bold)
                                Text(r.getOrNull(1)?.jsonPrimitive?.content.orEmpty(), color = Text1, fontSize = 13.sp)
                                Text(r.getOrNull(2)?.jsonPrimitive?.content.orEmpty(), color = Text2, fontSize = 12.sp)
                            }
                        }
                    }
                }
            }
        }
    }
}

// ---------- notifications, support, profile ----------
private val alertKinds = listOf("all" to "All", "approval" to "Approvals", "notice" to "Notices", "leave" to "Leave", "attendance" to "Attendance", "payroll" to "Payroll", "update" to "Updates")

@Composable
private fun AlertsPage(vm: AppViewModel, back: () -> Unit) {
    var kind by rememberSaveable { mutableStateOf("all") }
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Notifications", back)
        Load(Unit, { vm.ag.get("notifications") }, cache = "notifications") { d, _ ->
            val list = d.a("items")
            // seen once opened: the bell's count clears (owner, 21 Nov 2026)
            LaunchedEffect(list.any { !it.b("read") }) { if (list.any { !it.b("read") }) runCatching { vm.ag.post("notifications") } }
            val shown = if (kind == "all") list else list.filter { it.s("kind") == kind }
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item {
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(alertKinds.filter { (k, _) -> k == "all" || list.any { it.s("kind") == k } }) { (k, l) ->
                            Chip(if (k == "all") l else "$l (${list.count { it.s("kind") == k }})", kind == k) { kind = k }
                        }
                    }
                }
                if (shown.isEmpty()) item {
                    Column(Modifier.fillMaxWidth().padding(40.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(Icons.Outlined.Notifications, null, tint = Gold, modifier = Modifier.size(44.dp))
                        Text("You're all caught up.", color = Text2, modifier = Modifier.padding(top = 8.dp))
                    }
                }
                items(shown) { n ->
                    val (icon, tint) = when (n.s("kind")) {
                        "approval" -> Icons.Outlined.CheckCircle to Ok
                        "notice" -> Icons.Outlined.Campaign to Color(0xFFEA580C)
                        "leave" -> Icons.Outlined.BeachAccess to Color(0xFF2563EB)
                        "attendance" -> Icons.Outlined.History to Warn
                        "payroll" -> Icons.Outlined.Payments to Color(0xFF0F766E)
                        else -> Icons.Outlined.Notifications to Color(0xFFB08433)
                    }
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(if (n.b("read")) Color.White else Color(0xFFFFFBF0))
                        .border(1.dp, if (n.b("read")) Line else Gold.copy(alpha = 0.5f), RoundedCornerShape(18.dp)).padding(14.dp)) {
                        Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                            Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
                        }
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(n.s("title"), color = Text1, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.weight(1f))
                                if (!n.b("read")) Box(Modifier.size(8.dp).clip(CircleShape).background(Gold))
                            }
                            if (n.s("body").isNotBlank()) Text(n.s("body"), color = Text1, fontSize = 13.sp, lineHeight = 18.sp, modifier = Modifier.padding(top = 3.dp))
                            Text(n.s("at").take(16).let { a -> runCatching { dmy(a.take(10)) + a.drop(10) }.getOrDefault(a) } +
                                (n.s("until").takeIf { it.isNotBlank() }?.let { " · until $it" } ?: ""), color = Text2, fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp))
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun SupportPage(vm: AppViewModel, back: () -> Unit, go: (String) -> Unit) {
    val ctx = LocalContext.current
    var faq by rememberSaveable { mutableIntStateOf(-1) }
    val open = { uri: String -> runCatching { ctx.startActivity(Intent(Intent.ACTION_VIEW, android.net.Uri.parse(uri))) } }
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Help & support", back)
        Load(Unit, { vm.ag.get("home") }, cache = "home") { h, _ ->
            val sup = h.o("support") ?: JsonObject(emptyMap())
            val can = h.o("can") ?: JsonObject(emptyMap())
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                item {
                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(Color(0xFF0B0906), Ink, Ink2))).padding(20.dp)) {
                        Icon(Icons.Outlined.SupportAgent, null, tint = GoldHi, modifier = Modifier.size(34.dp))
                        Text("How can we help?", color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 8.dp))
                        Text("Reach your manager or HR, or fix common things yourself.", color = Color(0xFFD9C9A3), fontSize = 13.sp)
                    }
                }
                listOf("Your manager" to sup.o("manager"), "HR / company" to sup.o("company")).forEach { (title, c) ->
                    if (c != null) item {
                        Card {
                            Text(title, color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                            Text(c.s("name").ifBlank { "—" }, color = Text1, fontWeight = FontWeight.Bold, fontSize = 16.sp, modifier = Modifier.padding(top = 2.dp))
                            val phone = c.s("mobile").ifBlank { c.s("phone") }.filter { it.isDigit() || it == '+' }
                            val mail = c.s("email")
                            if (phone.isBlank() && mail.isBlank()) Text("No contact details on record.", color = Text2, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
                            Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                if (phone.isNotBlank()) {
                                    Chip("Call", false) { open("tel:$phone") }
                                    Chip("WhatsApp", false) { open("https://wa.me/" + phone.trimStart('+').let { if (it.length == 10) "91$it" else it }) }
                                }
                                if (mail.isNotBlank()) Chip("Email", false) { open("mailto:$mail") }
                            }
                        }
                    }
                }
                item { Text("Do it yourself", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
                item {
                    Card {
                        listOfNotNull(
                            ("Missed or wrong punch" to "correct").takeIf { can.b("corrections") },
                            ("Apply for leave" to "leave-apply").takeIf { can.b("leave_apply") },
                            ("See my payslips" to "payslips").takeIf { can.b("payslips") },
                            "My profile and sign-out" to "profile",
                        ).forEach { (l, to) ->
                            Row(Modifier.fillMaxWidth().clickable { go(to) }.padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                                Text(l, color = Text1, fontSize = 14.sp, modifier = Modifier.weight(1f)); Icon(Icons.Outlined.ChevronRight, null, tint = Text2)
                            }
                        }
                    }
                }
                item { Text("Common questions", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
                val qa = listOf(
                    "My QR punch was refused" to "The message under \"Not recorded\" says why. Most often: stand inside the office, turn on location, or scan the live screen again (the code changes every few seconds). If it says QR attendance is off for you, ask your administrator.",
                    "My attendance is wrong" to "Open Attendance, tap the day to see its punch log, then use \"Request a correction\". Your manager approves it.",
                    "I forgot my password" to "Ask your administrator to reset it in User Management. Then sign in with your Employee ID and the new password.",
                    "I can't see payslips" to "A payslip appears once that month's payroll is closed by HR.",
                    "The app says Locked" to "The phone has been offline too long. Connect to the internet and tap Retry.",
                )
                items(qa.size) { i ->
                    Card(onClick = { faq = if (faq == i) -1 else i }) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(qa[i].first, color = Text1, fontWeight = FontWeight.SemiBold, fontSize = 14.sp, modifier = Modifier.weight(1f))
                            Text(if (faq == i) "–" else "+", color = Gold, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                        }
                        if (faq == i) Text(qa[i].second, color = Text2, fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.padding(top = 8.dp))
                    }
                }
                item {
                    Text("Ametecs Global ${ametecs.managedmobile.BuildConfig.VERSION_NAME} · ${Build.MANUFACTURER} ${Build.MODEL}", color = Text2, fontSize = 11.sp,
                        textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(top = 8.dp))
                }
            }
        }
    }
}

@Composable
private fun ProfilePage(vm: AppViewModel, back: () -> Unit) {
    var ask by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("My profile", back)
        Load(Unit, { vm.ag.get("home") }, cache = "home") { h, _ ->
            val me = h.o("me") ?: JsonObject(emptyMap())
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        val initials = me.s("name").split(" ").filter { it.isNotBlank() }.let { w -> (w.firstOrNull()?.take(1).orEmpty() + (if (w.size > 1) w.last().take(1) else "")).uppercase() }
                        Box(Modifier.size(56.dp).clip(CircleShape).background(Gold), contentAlignment = Alignment.Center) { Text(initials, color = Ink, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp) }
                        Spacer(Modifier.width(14.dp))
                        Column {
                            Text(me.s("name"), color = Text1, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                            Text(me.s("designation"), color = Text2, fontSize = 13.sp)
                        }
                    }
                }
                Card {
                    Field("Employee ID", me.s("code"))
                    Field("Email", me.s("email"))
                    Field("Mobile", me.s("mobile"))
                    Field("Company", me.s("company"))
                    Field("Branch", me.s("branch"))
                    Field("Department", me.s("department"))
                    Field("Team", me.s("team"))
                    Field("Reports to", me.s("manager"))
                    Field("Joined", dmy(me.s("joined")))
                }
                Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).border(1.dp, Bad.copy(alpha = 0.4f), RoundedCornerShape(16.dp)).clickable { ask = true }.padding(16.dp),
                    horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.AutoMirrored.Outlined.Logout, null, tint = Bad)
                    Spacer(Modifier.width(8.dp))
                    Text("Sign out", color = Bad, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
    if (ask) AlertDialog(onDismissRequest = { ask = false }, title = { Text("Sign out?") },
        text = { Text("You'll need your employee ID and password to sign in again.") },
        confirmButton = { TextButton(onClick = { ask = false; vm.logout() }) { Text("Sign out", color = Bad) } },
        dismissButton = { TextButton(onClick = { ask = false }) { Text("Cancel") } })
}

// ---------- admin: team today and approvals (owner, 21 Nov 2026: "add also admin ... page") ----------
@Composable
private fun AdminPage(vm: AppViewModel, back: () -> Unit) {
    val scope = rememberCoroutineScope()
    var tab by rememberSaveable { mutableStateOf("today") }
    var stateF by rememberSaveable { mutableStateOf("all") }
    var ask by remember { mutableStateOf<Triple<String, String, Boolean>?>(null) }   // action, request id, approve
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var msg by remember { mutableStateOf<Pair<Boolean, String>?>(null) }
    var tick by remember { mutableIntStateOf(0) }
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Team & approvals", back)
        Load(tick, { vm.ag.get("admin") }, cache = "admin") { d, reload ->
            val c = d.o("count") ?: JsonObject(emptyMap())
            val leave = d.a("leave"); val corr = d.a("corrections"); val team = d.a("team")
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item {
                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(Color(0xFF0B0906), Ink, Ink2))).padding(18.dp)) {
                        Row(verticalAlignment = Alignment.Bottom) {
                            Column(Modifier.weight(1f)) {
                                Text("Today · ${d.s("date")}", color = Color(0xFFBFAE86), fontSize = 12.sp)
                                Text("${c.n("in") + c.n("out")} of ${d.s("team_size")} in", color = GoldHi, fontWeight = FontWeight.ExtraBold, fontSize = 28.sp)
                            }
                            Column(horizontalAlignment = Alignment.End) {
                                Text("Shift", color = Color(0xFFBFAE86), fontSize = 11.sp)
                                Text(d.s("shift"), color = Color.White, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                            }
                        }
                        Spacer(Modifier.height(12.dp))
                        listOf(listOf("In" to "in", "Out" to "out", "Not in" to "absent"), listOf("Leave" to "leave", "Off" to "off", "Late" to "late")).forEach { row ->
                            Row(Modifier.padding(vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                row.forEach { (l, k) ->
                                    Column(Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(Color(0x14FFFFFF)).clickable { tab = "today"; stateF = k }.padding(10.dp)) {
                                        Text("${c.n(k)}", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
                                        Text(l, color = Color(0xFFD9C9A3), fontSize = 11.sp)
                                    }
                                }
                            }
                        }
                    }
                }
                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Chip("Team today", tab == "today") { tab = "today" }
                        Chip("Leave (${leave.size})", tab == "leave") { tab = "leave" }
                        Chip("Corrections (${corr.size})", tab == "corr") { tab = "corr" }
                    }
                }
                msg?.let { (ok, m) -> item { Text(m, color = if (ok) Ok else Bad, fontSize = 13.sp, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp))
                    .background((if (ok) Ok else Bad).copy(alpha = 0.08f)).padding(12.dp)) } }
                when (tab) {
                    "today" -> {
                        item {
                            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                items(listOf("all" to "All", "in" to "In", "out" to "Out", "absent" to "Not in", "late" to "Late", "leave" to "Leave", "off" to "Off")) { (k, l) -> Chip(l, stateF == k) { stateF = k } }
                            }
                        }
                        val shown = when (stateF) { "all" -> team; "late" -> team.filter { it.b("late") }; else -> team.filter { it.s("state") == stateF } }
                        if (shown.isEmpty()) item { Text("Nobody here.", color = Text2, modifier = Modifier.padding(8.dp)) }
                        items(shown) { p ->
                            val (label, tint) = when (p.s("state")) { "in" -> "In" to Ok; "out" -> "Out" to Color(0xFF2563EB); "leave" -> "Leave" to Color(0xFF7C3AED); "off" -> "Off" to Text2; else -> "Not in" to Bad }
                            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color.White).border(1.dp, Line, RoundedCornerShape(16.dp)).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                                val ini = p.s("name").split(" ").filter { it.isNotBlank() }.let { w -> (w.firstOrNull()?.take(1).orEmpty() + (if (w.size > 1) w.last().take(1) else "")).uppercase() }
                                Box(Modifier.size(40.dp).clip(CircleShape).background(GoldTint), contentAlignment = Alignment.Center) { Text(ini, color = Color(0xFF8A6A25), fontWeight = FontWeight.Bold) }
                                Spacer(Modifier.width(12.dp))
                                Column(Modifier.weight(1f)) {
                                    Text(p.s("name"), color = Text1, fontWeight = FontWeight.SemiBold, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text(listOf(p.s("code"), p.s("dept")).filter { it.isNotBlank() }.joinToString(" · "), color = Text2, fontSize = 11.sp, maxLines = 1)
                                }
                                Column(horizontalAlignment = Alignment.End) {
                                    Pill(label, tint)
                                    p.str("in")?.let { Text("in $it" + if (p.b("late")) " · late" else "", color = if (p.b("late")) Color(0xFFEA580C) else Text2, fontSize = 11.sp, modifier = Modifier.padding(top = 3.dp)) }
                                }
                            }
                        }
                    }
                    "leave" -> {
                        if (leave.isEmpty()) item { Text("No leave waiting for you.", color = Text2, modifier = Modifier.padding(8.dp)) }
                        items(leave) { q ->
                            Card {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(q.s("name"), color = Text1, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                    Pill(q.s("type"), Color(0xFF2563EB))
                                }
                                Text((if (q.s("from") == q.s("to")) q.s("from") else "${q.s("from")} → ${q.s("to")}") + " · ${q.s("days")} day(s)" + (q.str("half")?.let { " · $it" } ?: ""),
                                    color = Text1, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
                                if (q.s("reason").isNotBlank()) Text(q.s("reason"), color = Text2, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
                                Decide { ok -> ask = Triple("leave-decide", q.s("id"), ok); note = "" }
                            }
                        }
                    }
                    else -> {
                        if (corr.isEmpty()) item { Text("No corrections waiting for you.", color = Text2, modifier = Modifier.padding(8.dp)) }
                        items(corr) { q ->
                            Card {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(q.s("name"), color = Text1, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                    Pill("${q.s("dir")} ${q.s("at")}", if (q.s("dir") == "IN") Ok else Color(0xFF2563EB))
                                }
                                Text(q.s("date"), color = Text1, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
                                if (q.s("reason").isNotBlank()) Text(q.s("reason"), color = Text2, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
                                Decide { ok -> ask = Triple("regularise-decide", q.s("id"), ok); note = "" }
                            }
                        }
                    }
                }
            }
            ask?.let { (action, id, ok) ->
                AlertDialog(onDismissRequest = { if (!busy) ask = null }, title = { Text(if (ok) "Approve?" else "Reject?") },
                    text = { OutlinedTextField(note, { note = it }, label = { Text(if (ok) "Note (optional)" else "Reason for rejecting") }, minLines = 2) },
                    confirmButton = { TextButton(enabled = !busy && (ok || note.isNotBlank()), onClick = {
                        busy = true
                        scope.launch {
                            val r = runCatching { vm.ag.action(action, mapOf("request_id" to id, "decision" to if (ok) "approve" else "reject", "note" to note)) }
                                .getOrElse { fail(it.message ?: "Could not send. Try again.") }
                            busy = false; ask = null
                            msg = r.b("ok") to r.s("message")
                            reload()
                        }
                    }) { Text(if (busy) "Sending…" else if (ok) "Approve" else "Reject", color = if (ok) Ok else Bad, fontWeight = FontWeight.Bold) } },
                    dismissButton = { TextButton(enabled = !busy, onClick = { ask = null }) { Text("Cancel") } })
            }
        }
    }
}

@Composable
private fun Decide(onDecide: (Boolean) -> Unit) {
    Row(Modifier.padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Box(Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).border(1.dp, Bad.copy(alpha = 0.4f), RoundedCornerShape(12.dp)).clickable { onDecide(false) }.padding(12.dp), contentAlignment = Alignment.Center) {
            Text("Reject", color = Bad, fontWeight = FontWeight.Bold)
        }
        Box(Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(Ok).clickable { onDecide(true) }.padding(12.dp), contentAlignment = Alignment.Center) {
            Text("Approve", color = Color.White, fontWeight = FontWeight.Bold)
        }
    }
}

// ---------- my letters, my assets ----------
@Composable
private fun LettersPage(vm: AppViewModel, back: () -> Unit) {
    var open by rememberSaveable { mutableStateOf("") }
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("My letters", back)
        Load(Unit, { vm.ag.get("letters") }, cache = "letters") { d, _ ->
            val list = d.a("letters")
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (list.isEmpty()) item { Text("No letters have been issued to you yet.", color = Text2) }
                items(list) { l ->
                    Card(onClick = { open = if (open == l.s("id")) "" else l.s("id") }) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(l.s("title"), color = Text1, fontWeight = FontWeight.Bold)
                                Text("Issued ${l.s("issued")}", color = Text2, fontSize = 12.sp)
                            }
                            if (l.b("needs_ack")) Pill(if (l.str("acked") != null) "Acknowledged" else "Needs acknowledgement", if (l.str("acked") != null) Ok else Bad)
                        }
                        if (open == l.s("id")) Text(l.s("body"), color = Text1, fontSize = 13.sp, lineHeight = 20.sp,
                            modifier = Modifier.padding(top = 12.dp).clip(RoundedCornerShape(12.dp)).background(Cream).padding(12.dp))
                        else Text("Tap to read", color = Warn, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
                    }
                }
            }
        }
    }
}

@Composable
private fun AssetsPage(vm: AppViewModel, back: () -> Unit) {
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("My assets", back)
        Load(Unit, { vm.ag.get("assets") }, cache = "assets") { d, _ ->
            val held = d.a("held")
            val unsettled = d.a("unsettled")
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item { Text("With you (${held.size})", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
                if (held.isEmpty()) item { Text("Nothing is issued to you right now.", color = Text2) }
                items(held) { a ->
                    Card {
                        Row { Text(a.s("model").ifBlank { a.s("type") }, color = Text1, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f)); Text(a.s("id"), color = Text2, fontSize = 12.sp) }
                        Text(listOf(a.s("type"), a.s("serial").takeIf { it.isNotBlank() }?.let { "S/N $it" }.orEmpty(), a.s("issued").takeIf { it.isNotBlank() }?.let { "issued $it" }.orEmpty())
                            .filter { it.isNotBlank() }.joinToString(" · "), color = Text2, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
                    }
                }
                if (unsettled.isNotEmpty()) {
                    item { Text("Returned damaged / lost — to settle (${unsettled.size})", color = Bad, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
                    items(unsettled) { a ->
                        Card {
                            Text(a.s("model").ifBlank { a.s("type") } + " · " + a.s("id"), color = Text1, fontWeight = FontWeight.Bold)
                            Text("Returned ${a.s("returned")} · ${a.s("condition")} · recovery ${a.s("recovery").ifBlank { "—" }} ${a.s("recovery_status")}", color = Text2, fontSize = 12.sp)
                        }
                    }
                }
            }
        }
    }
}
