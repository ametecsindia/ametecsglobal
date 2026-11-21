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

// ---------- navigation ----------
@Composable
fun AgHome(vm: AppViewModel) {
    val apps by vm.agApps.collectAsState()
    var page by rememberSaveable { mutableStateOf("home") }
    if (apps == null) { Dashboard(vm); return }
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

/** Loads one API answer; shows a spinner, the error with Retry, or the content (with a reload function). */
@Composable
private fun Load(key: Any?, load: suspend () -> JsonObject, content: @Composable (JsonObject, () -> Unit) -> Unit) {
    var tick by remember { mutableIntStateOf(0) }
    var data by remember(key) { mutableStateOf<JsonObject?>(null) }
    var err by remember(key) { mutableStateOf<String?>(null) }
    LaunchedEffect(key, tick) {
        err = null
        runCatching { load() }
            .onSuccess { if (it.str("ok") == "false") err = it.str("message") ?: "Not available." else data = it }
            .onFailure { err = it.message ?: "Could not load this just now." }
    }
    val d = data
    when {
        d != null -> content(d) { tick++ }
        err != null -> Column(Modifier.fillMaxWidth().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(Icons.Outlined.ErrorOutline, null, tint = Bad, modifier = Modifier.size(36.dp))
            Spacer(Modifier.height(8.dp))
            Text(err!!, color = Text1, textAlign = TextAlign.Center)
            TextButton(onClick = { tick++ }) { Text("Try again", color = Warn) }
        }
        else -> Box(Modifier.fillMaxWidth().padding(48.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Gold) }
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

// ---------- home ----------
private data class Tile(val key: String, val label: String, val sub: String, val icon: ImageVector, val badge: Int = 0)

@Composable
private fun Hub(vm: AppViewModel, go: (String) -> Unit) {
    var tick by remember { mutableIntStateOf(0) }
    val punch = rememberPunch(vm) { tick++; vm.refresh() }
    Load(tick, { vm.ag.get("home") }) { h, reload ->
        val me = h.o("me") ?: JsonObject(emptyMap())
        val today = h.o("today") ?: JsonObject(emptyMap())
        val can = h.o("can") ?: JsonObject(emptyMap())
        val hour = LocalTime.now().hour
        val hello = if (hour < 12) "Good morning" else if (hour < 17) "Good afternoon" else "Good evening"
        val isIn = today.s("last") == "IN"
        val tiles = buildList {
            if (can.b("attendance")) add(Tile("attendance", "Attendance history", "Days, in / out, hours", Icons.Outlined.History))
            if (can.b("leave")) add(Tile("leave", "Leave", "Balance, apply, status", Icons.Outlined.BeachAccess, h.n("pending_leave")))
            if (can.b("payslips")) add(Tile("payslips", "Payslips", "Monthly salary slips", Icons.Outlined.Payments))
            if (can.b("corrections")) add(Tile("corrections", "Corrections", "Missed or wrong punch", Icons.Outlined.EditCalendar))
            if (can.b("productivity")) add(Tile("productivity", "Productivity", "PC time and score", Icons.Outlined.Insights))
            if (can.b("field")) add(Tile("field", "Field work", "Calls, visits, follow-ups", Icons.Outlined.Route))
            if (can.b("letters")) add(Tile("letters", "My letters", "Letters issued to you", Icons.Outlined.Description))
            if (can.b("assets")) add(Tile("assets", "My assets", "Items issued to you", Icons.Outlined.Inventory2))
            add(Tile("alerts", "Notifications", "Approvals and updates", Icons.Outlined.Notifications, h.n("unread")))
            add(Tile("profile", "My profile", "Details and sign-out", Icons.Outlined.Person))
        }
        LazyColumn(Modifier.fillMaxSize().background(Cream), contentPadding = PaddingValues(bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 28.dp, bottomEnd = 28.dp))
                    .background(Brush.linearGradient(listOf(Color(0xFF0B0906), Ink, Ink2))).statusBarsPadding().padding(20.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(Gold), contentAlignment = Alignment.Center) {
                            Text("AG", color = Ink, fontWeight = FontWeight.ExtraBold, fontSize = 12.sp)
                        }
                        Spacer(Modifier.width(10.dp))
                        Text("Ametecs ", color = Color.White, fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
                        Text("Global", color = GoldHi, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
                        Spacer(Modifier.weight(1f))
                        Icon(Icons.Outlined.Refresh, "Refresh", tint = GoldHi, modifier = Modifier.size(24.dp).clickable { reload(); vm.refresh() })
                    }
                    Spacer(Modifier.height(18.dp))
                    Text("$hello,", color = Color(0xFFD9C9A3), fontSize = 15.sp)
                    Text(me.s("name").ifBlank { "there" }, color = Color.White, fontSize = 26.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    listOf(me.s("designation"), me.s("code")).filter { it.isNotBlank() }.joinToString(" · ").takeIf { it.isNotBlank() }?.let {
                        Text(it, color = Color(0xFFBFAE86), fontSize = 13.sp)
                    }
                    Spacer(Modifier.height(16.dp))
                    // today
                    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Color(0x1FFFFFFF)).padding(14.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.size(10.dp).clip(CircleShape).background(if (isIn) Color(0xFF4ADE80) else if (today.str("out") != null) Color(0xFFF59E0B) else Color(0xFFEF4444)))
                            Spacer(Modifier.width(8.dp))
                            Text(if (isIn) "You're in" else if (today.str("out") != null) "Punched out" else "Not punched in yet", color = Color.White, fontWeight = FontWeight.SemiBold)
                            Spacer(Modifier.weight(1f))
                            Text(LocalDate.now().format(DateTimeFormatter.ofPattern("EEE, d MMM")), color = Color(0xFFD9C9A3), fontSize = 13.sp)
                        }
                        Spacer(Modifier.height(12.dp))
                        Row {
                            listOf("Check in" to today.s("in"), "Check out" to today.s("out"), "Worked" to today.s("worked")).forEach { (k, v) ->
                                Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                                    Text(k, color = Color(0xFFBFAE86), fontSize = 11.sp)
                                    Text(v.ifBlank { "—" }, color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.Bold)
                                }
                            }
                        }
                    }
                    if (can.b("scan")) {
                        Spacer(Modifier.height(14.dp))
                        GoldButton(if (isIn) "Scan QR to punch out" else "Scan QR to punch in", Icons.Outlined.QrCodeScanner, onClick = punch)
                    }
                    if (can.b("attendance")) {
                        Text("Attendance history  ›", color = GoldHi, fontWeight = FontWeight.SemiBold, fontSize = 14.sp,
                            modifier = Modifier.align(Alignment.CenterHorizontally).padding(top = 12.dp).clickable { go("attendance") })
                    }
                }
            }
            val punches = today.a("punches")
            if (punches.isNotEmpty()) item {
                Card(Modifier.padding(horizontal = 16.dp)) {
                    Text("Today's punches", color = Text2, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.height(8.dp))
                    punches.forEach { p ->
                        Row(Modifier.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                            Pill(p.s("dir"), if (p.s("dir") == "IN") Ok else Color(0xFF2563EB))
                            Spacer(Modifier.width(10.dp))
                            Text(p.s("at"), color = Text1, fontWeight = FontWeight.SemiBold)
                            Spacer(Modifier.weight(1f))
                            Text(p.s("src"), color = Text2, fontSize = 12.sp)
                        }
                    }
                }
            }
            items(tiles.chunked(2)) { pair ->
                Row(Modifier.padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    pair.forEach { t ->
                        Column(Modifier.weight(1f).clip(RoundedCornerShape(18.dp)).background(Color.White).border(1.dp, Line, RoundedCornerShape(18.dp))
                            .clickable { go(t.key) }.padding(16.dp)) {
                            Row(verticalAlignment = Alignment.Top) {
                                Box(Modifier.size(42.dp).clip(RoundedCornerShape(12.dp)).background(GoldTint), contentAlignment = Alignment.Center) {
                                    Icon(t.icon, null, tint = Color(0xFFB08433), modifier = Modifier.size(22.dp))
                                }
                                Spacer(Modifier.weight(1f))
                                if (t.badge > 0) Text("${t.badge}", color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                                    modifier = Modifier.clip(CircleShape).background(Bad).padding(horizontal = 7.dp, vertical = 2.dp))
                            }
                            Spacer(Modifier.height(12.dp))
                            Text(t.label, color = Text1, fontWeight = FontWeight.SemiBold, fontSize = 15.sp)
                            Text(t.sub, color = Text2, fontSize = 12.sp, lineHeight = 15.sp, modifier = Modifier.padding(top = 2.dp))
                        }
                    }
                    if (pair.size == 1) Spacer(Modifier.weight(1f))
                }
            }
        }
    }
}

// ---------- attendance history ----------
@Composable
private fun AttendancePage(vm: AppViewModel, back: () -> Unit, go: (String) -> Unit) {
    var month by rememberSaveable { mutableStateOf(YearMonth.now().toString()) }
    val ym = YearMonth.parse(month)
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Attendance history", back)
        Row(Modifier.fillMaxWidth().background(Color.White).padding(horizontal = 8.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Outlined.ChevronLeft, "Previous month", tint = Text1, modifier = Modifier.size(40.dp).clip(CircleShape).clickable { month = ym.minusMonths(1).toString() }.padding(8.dp))
            Text(ym.format(DateTimeFormatter.ofPattern("MMMM yyyy")), color = Text1, fontWeight = FontWeight.Bold, fontSize = 16.sp, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
            val canNext = ym < YearMonth.now()
            Icon(Icons.Outlined.ChevronRight, "Next month", tint = if (canNext) Text1 else Line,
                modifier = Modifier.size(40.dp).clip(CircleShape).clickable(enabled = canNext) { month = ym.plusMonths(1).toString() }.padding(8.dp))
        }
        Load(month, { vm.ag.get("attendance", mapOf("m" to month)) }) { d, _ ->
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item {
                    val sum = (d["summary"] as? JsonArray).orEmpty().mapNotNull { it as? JsonArray }
                    Card {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text("Worked this month", color = Text2, fontSize = 12.sp, modifier = Modifier.weight(1f))
                            Text(d.s("worked"), color = Ink, fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
                        }
                        Spacer(Modifier.height(10.dp))
                        sum.chunked(3).forEach { row ->
                            Row(Modifier.padding(vertical = 4.dp)) {
                                row.forEach { p ->
                                    Column(Modifier.weight(1f)) {
                                        Text(p[1].jsonPrimitive.content, color = Text1, fontWeight = FontWeight.Bold, fontSize = 20.sp)
                                        Text(p[0].jsonPrimitive.content, color = Text2, fontSize = 12.sp)
                                    }
                                }
                            }
                        }
                    }
                }
                item { TextButton(onClick = { go("corrections") }) { Icon(Icons.Outlined.EditCalendar, null, tint = Warn); Spacer(Modifier.width(6.dp)); Text("Missed a punch? Request a correction", color = Warn) } }
                val days = d.a("days")
                if (days.isEmpty()) item { Text("Nothing recorded for this month.", color = Text2, modifier = Modifier.padding(16.dp)) }
                items(days) { day ->
                    Card {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(day.s("label"), color = Text1, fontWeight = FontWeight.Bold)
                                Text(day.s("day") + if (day.b("planned")) " · planned" else "", color = Text2, fontSize = 12.sp)
                            }
                            Pill(day.s("status_label"), stateColor(day.s("status_label")))
                        }
                        Spacer(Modifier.height(10.dp))
                        Row {
                            listOf("In" to day.s("in"), "Out" to day.s("out"), "Worked" to day.s("worked"), "Late" to day.s("late")).forEach { (k, v) ->
                                Column(Modifier.weight(1f)) {
                                    Text(k, color = Text2, fontSize = 11.sp)
                                    Text(v.ifBlank { "—" }, color = if (k == "Late" && v.isNotBlank()) Warn else Text1, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                                }
                            }
                        }
                        val ps = day.a("punches")
                        if (ps.isNotEmpty()) {
                            Spacer(Modifier.height(8.dp))
                            LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                items(ps) { p ->
                                    Text("${p.s("dir")} ${p.s("at")} · ${p.s("src")}", fontSize = 11.sp, color = Text1,
                                        modifier = Modifier.clip(RoundedCornerShape(8.dp)).background(GoldTint).padding(horizontal = 8.dp, vertical = 4.dp))
                                }
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
        Load(Unit, { vm.ag.get("leave") }) { d, _ ->
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
        Load(Unit, { vm.ag.get("payslips") }) { d, _ ->
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
        Load(period, { vm.ag.get("payslip", mapOf("period" to period)) }) { d, _ ->
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
        Load(Unit, { vm.ag.get("corrections") }) { d, _ ->
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
@Composable
private fun PersonPage(vm: AppViewModel, app: String, title: String, back: () -> Unit) {
    var range by rememberSaveable { mutableStateOf("month") }
    val now = LocalDate.now()
    val from = when (range) { "today" -> now; "week" -> now.minusDays(6); else -> now.withDayOfMonth(1) }
    Page(title, back) {
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Chip("Today", range == "today") { range = "today" }
                Chip("Last 7 days", range == "week") { range = "week" }
                Chip("This month", range == "month") { range = "month" }
            }
        }
        item {
            Load(range, { vm.ag.get("person", mapOf("app" to app, "from" to from.toString(), "to" to now.toString())) }) { d, _ ->
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    d.str("note")?.let { Text(it, color = Text2) }
                    (d["tiles"] as? JsonArray).orEmpty().mapNotNull { it as? JsonArray }.chunked(2).forEach { row ->
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

// ---------- notifications, profile ----------
@Composable
private fun AlertsPage(vm: AppViewModel, back: () -> Unit) {
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("Notifications", back)
        Load(Unit, { vm.ag.get("notifications") }) { d, _ ->
            val list = d.a("items")
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (list.isEmpty()) item { Text("You're all caught up.", color = Text2) }
                items(list) { n ->
                    Card {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            if (!n.b("read")) { Box(Modifier.size(8.dp).clip(CircleShape).background(Gold)); Spacer(Modifier.width(8.dp)) }
                            Text(n.s("title"), color = Text1, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        }
                        Text(n.s("body"), color = Text1, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
                        Text(n.s("at").take(16).let { a -> runCatching { dmy(a.take(10)) + a.drop(10) }.getOrDefault(a) }, color = Text2, fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp))
                    }
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
        Load(Unit, { vm.ag.get("home") }) { h, _ ->
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

// ---------- my letters, my assets ----------
@Composable
private fun LettersPage(vm: AppViewModel, back: () -> Unit) {
    var open by rememberSaveable { mutableStateOf("") }
    Column(Modifier.fillMaxSize().background(Cream)) {
        TopBar("My letters", back)
        Load(Unit, { vm.ag.get("letters") }) { d, _ ->
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
        Load(Unit, { vm.ag.get("assets") }) { d, _ ->
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
