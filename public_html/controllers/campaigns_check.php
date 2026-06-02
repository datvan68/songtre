<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/activity_log.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

/* =====================================================
   RENDER PAGE
===================================================== */
function render_page($view, $withLayout = true, $data = array())
{
    global $pdo;

    extract($data, EXTR_SKIP);

    if ($withLayout) {
        include __DIR__ . '/../components/layout_head.php';
        echo '<div class="flex w-full min-h-screen min-w-0">';
        include __DIR__ . '/../components/sidebar.php';
        echo '<div class="flex-1 flex flex-col min-w-0">';
        include __DIR__ . '/../components/navbar.php';
        echo '<main class="flex-1 bg-bg min-h-screen p-4 overflow-x-hidden min-w-0">';
    }

    require __DIR__ . '/../views/' . $view . '.php';

    if ($withLayout) {
        echo '</main></div></div>';
    }
    exit;
}

/* =====================================================
   HELPERS
===================================================== */
function distanceInMeters($lat1, $lng1, $lat2, $lng2)
{
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = pow(sin($dLat / 2), 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        pow(sin($dLng / 2), 2);

    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function detectSessionNow()
{
    $h = (int) date('H');
    if ($h >= 5 && $h < 12)
        return array('morning', 'Buổi sáng');
    if ($h < 18)
        return array('afternoon', 'Buổi chiều');
    return array('evening', 'Buổi tối');
}

/**
 * Parse JSON safely from POST (string JSON)
 */
function json_input($key, $default = null)
{
    $v = isset($_POST[$key]) ? $_POST[$key] : null;
    if ($v === null || $v === '')
        return $default;

    $d = json_decode($v, true);
    if (!is_array($d))
        return $default;

    return $d;
}

function bad($code, $extra = array())
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array('ok' => 0, 'error' => $code), $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Compute max step jitter (max distance between consecutive points)
 */
function gps_max_step_jitter($pts)
{
    $max = 0.0;
    $n = count($pts);
    for ($i = 1; $i < $n; $i++) {
        $a = $pts[$i - 1];
        $b = $pts[$i];
        $d = distanceInMeters($a['lat'], $a['lng'], $b['lat'], $b['lng']);
        if ($d > $max)
            $max = $d;
    }
    return $max;
}

/**
 * Compute max speed between consecutive points (m/s)
 */
function gps_max_speed($pts)
{
    $max = 0.0;
    $n = count($pts);
    for ($i = 1; $i < $n; $i++) {
        $a = $pts[$i - 1];
        $b = $pts[$i];

        $d = distanceInMeters($a['lat'], $a['lng'], $b['lat'], $b['lng']);
        $dt = ($b['ts'] - $a['ts']) / 1000.0; // sec
        if ($dt <= 0)
            $dt = 1.0;

        $v = $d / $dt;
        if ($v > $max)
            $max = $v;
    }
    return $max;
}

/**
 * Basic sanitize/validate points payload
 * Each: {lat,lng,acc,ts}
 */
function gps_normalize_points($raw)
{
    if (!is_array($raw))
        return array();

    $pts = array();
    foreach ($raw as $p) {
        if (!is_array($p))
            continue;

        $lat = isset($p['lat']) ? (float) $p['lat'] : null;
        $lng = isset($p['lng']) ? (float) $p['lng'] : null;
        $acc = isset($p['acc']) ? (float) $p['acc'] : null;
        $ts = isset($p['ts']) ? (float) $p['ts'] : null;

        if ($lat === null || $lng === null || $ts === null)
            continue;
        if ($lat == 0.0 && $lng == 0.0)
            continue;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)
            continue;
        if ($acc !== null && ($acc < 0 || $acc > 5000))
            $acc = null;

        $pts[] = array('lat' => $lat, 'lng' => $lng, 'acc' => $acc, 'ts' => $ts);
    }

    usort($pts, function ($a, $b) {
        if ($a['ts'] == $b['ts'])
            return 0;
        return ($a['ts'] < $b['ts']) ? -1 : 1;
    });

    if (count($pts) > 8)
        $pts = array_slice($pts, -8);

    return $pts;
}

/* =========================
   IP / GEOIP HELPERS (no-curl)
========================= */
function att_getClientIp()
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * GeoIP best-effort via ip-api.com (timeout short)
 * - ONLY for FLAG (do not hard block)
 * - Uses file_get_contents to avoid curl dependency.
 */
function att_geoipLookupViaIpApi($ip)
{
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1')
        return null;

    $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,lat,lon";

    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => 2,
            'header' => "User-Agent: attendance-geoip/1.0\r\n",
        )
    ));

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '')
        return null;

    $j = json_decode($raw, true);
    if (!is_array($j))
        return null;
    if (!isset($j['status']) || $j['status'] !== 'success')
        return null;

    if (!isset($j['lat']) || !isset($j['lon']))
        return null;
    if (!is_numeric($j['lat']) || !is_numeric($j['lon']))
        return null;

    return array(
        'lat' => (float) $j['lat'],
        'lng' => (float) $j['lon'],
        'provider' => 'ip-api'
    );
}

/* =====================================================
   LOGIN / ROLE
===================================================== */
$isLoggedIn = !empty($_SESSION['user_id']);
$user = $isLoggedIn ? auth_user() : null;
$uid = $user ? ($user['id'] ?? null) : null;

$isQRManager = $isLoggedIn && (
    can('attendance', 'update') ||
    can('attendance', 'delete')
);

/* =====================================================
   INPUT
===================================================== */
$withLayout = true;

$code = isset($_GET['code']) ? $_GET['code'] : (isset($_POST['code']) ? $_POST['code'] : '');
if (!$code) {
    render_page('campaigns_check', $withLayout, array(
        'mode' => 'error',
        'error' => array('title' => 'QR không hợp lệ', 'msg' => 'Thiếu mã QR')
    ));
}

/* =====================================================
   EVENT
===================================================== */
$stm = $pdo->prepare("SELECT * FROM attendance_events WHERE code=? LIMIT 1");
$stm->execute(array($code));
$ev = $stm->fetch(PDO::FETCH_ASSOC);

if (!$ev) {
    render_page('campaigns_check', $withLayout, array(
        'mode' => 'error',
        'error' => array('title' => 'QR không tồn tại', 'msg' => 'Không tìm thấy sự kiện')
    ));
}

$cid = (int) $ev['campaign_id'];

/* =====================================================
   CAMPAIGN
===================================================== */
$stm = $pdo->prepare("SELECT title FROM campaigns WHERE id=? LIMIT 1");
$stm->execute(array($cid));
$campTitle = $stm->fetchColumn();
if (!$campTitle)
    $campTitle = 'Phong trào';
$title = $campTitle;

/* =====================================================
   EXPIRE
===================================================== */
$isExpired = !empty($ev['expires_at']) && strtotime($ev['expires_at']) < time();

/* =====================================================
   ADMIN VIEW (NO GPS)
===================================================== */
if ($isQRManager && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $qrUrl = rtrim(BASE_URL, '/') . '/index.php?p=campaigns_check&code=' . urlencode($code);

    render_page('campaigns_check', true, array(
        'mode' => 'admin_view',
        'campTitle' => $campTitle,
        'statusText' => $isExpired ? 'Đã hết hạn' : 'Đang mở',
        'statusColor' => $isExpired ? '#dc2626' : '#16a34a',
        'qrUrl' => $qrUrl,
        'cid' => $cid
    ));
}

/* =====================================================
   USER – QR EXPIRED
===================================================== */
if (!$isQRManager && $isExpired) {
    render_page('campaigns_check', $withLayout, array(
        'mode' => 'error',
        'error' => array(
            'title' => 'QR đã hết hạn',
            'msg' => 'Thời gian điểm danh đã kết thúc'
        )
    ));
}

/* =====================================================
   USER GET – SUCCESS PAGE
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['success'])) {
    $session = $ev['session'];
    if ($session === 'morning')
        $sessionVi = 'Buổi sáng';
    elseif ($session === 'afternoon')
        $sessionVi = 'Buổi chiều';
    elseif ($session === 'evening')
        $sessionVi = 'Buổi tối';
    else
        $sessionVi = 'Không xác định';

    render_page('campaigns_check', $withLayout, array(
        'mode' => 'success',
        'campTitle' => $campTitle,
        'sessionVi' => $sessionVi,
        'timeNow' => date('H:i')
    ));
}

/* =====================================================
   USER GET – CONFIRM PAGE
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $session = $ev['session'];
    if ($session === 'morning')
        $sessionVi = 'Buổi sáng';
    elseif ($session === 'afternoon')
        $sessionVi = 'Buổi chiều';
    elseif ($session === 'evening')
        $sessionVi = 'Buổi tối';
    else
        $sessionVi = 'Không xác định';

    render_page('campaigns_check', $withLayout, array(
        'mode' => 'user_confirm',
        'campTitle' => $campTitle,
        'sessionVi' => $sessionVi,
        'code' => $code,
        'gps_policy' => array(
            'need_points' => true,
            'min_points' => 4,
            'max_points' => 6,
            'sample_ms' => 1500,
        ),
    ));
}

/* =====================================================
   USER POST – CHECKIN (AJAX)
===================================================== */
header('Content-Type: application/json; charset=utf-8');

if (!$isLoggedIn)
    bad('NEED_LOGIN');
if (!can('attendance', 'view'))
    bad('FORBIDDEN');
if ($isQRManager)
    bad('ADMIN_NO_CHECKIN');

// kiểm tra đăng ký
$stm = $pdo->prepare("SELECT 1 FROM registrations WHERE user_id=? AND campaign_id=? LIMIT 1");
$stm->execute(array($uid, $cid));
if (!$stm->fetch())
    bad('NOT_REGISTERED');

$session = $ev['session'];
if ($session === 'morning')
    $sessionVi = 'Buổi sáng';
elseif ($session === 'afternoon')
    $sessionVi = 'Buổi chiều';
elseif ($session === 'evening')
    $sessionVi = 'Buổi tối';
else
    $sessionVi = 'Không xác định';

/* =====================================================
   GPS POLICY
===================================================== */
$RADIUS_M = 100;
$MAX_ACC_M = 80;
$MIN_POINTS = 4;
$MAX_STEP_JITTER_M = 25;
$MAX_SPEED_MPS = 10;

// mismatch chỉ FLAG (không chặn)
$IP_GPS_FLAG_KM = 50;

$lat = (float) (isset($_POST['lat']) ? $_POST['lat'] : 0);
$lng = (float) (isset($_POST['lng']) ? $_POST['lng'] : 0);
$accuracy = (float) (isset($_POST['accuracy']) ? $_POST['accuracy'] : 0);

if ($lat == 0.0 && $lng == 0.0)
    bad('NO_GPS');
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)
    bad('BAD_GPS');

if ($accuracy <= 0 || $accuracy > $MAX_ACC_M) {
    bad('BAD_ACCURACY', array('accuracy' => $accuracy, 'max' => $MAX_ACC_M));
}

// points multi-sample
$rawPoints = json_input('points', array());
$points = gps_normalize_points($rawPoints);

if (count($points) < $MIN_POINTS) {
    bad('NEED_STABLE_GPS', array('min_points' => $MIN_POINTS, 'got' => count($points)));
}

// final point
$final = $points[count($points) - 1];
$lat = (float) $final['lat'];
$lng = (float) $final['lng'];
$accFinal = isset($final['acc']) && $final['acc'] ? (float) $final['acc'] : $accuracy;

if ($accFinal <= 0 || $accFinal > $MAX_ACC_M) {
    bad('BAD_ACCURACY', array('accuracy' => $accFinal, 'max' => $MAX_ACC_M));
}

$maxJitter = gps_max_step_jitter($points);
$maxSpeed = gps_max_speed($points);

if ($maxJitter > $MAX_STEP_JITTER_M) {
    bad('GPS_JUMP', array('max_jitter' => round($maxJitter), 'allow' => $MAX_STEP_JITTER_M));
}
if ($maxSpeed > $MAX_SPEED_MPS) {
    bad('IMPOSSIBLE_SPEED', array('max_speed' => round($maxSpeed, 2), 'allow' => $MAX_SPEED_MPS));
}

/* =====================================================
   DISTANCE CHECK
===================================================== */
$centerLat = (float) $ev['lat'];
$centerLng = (float) $ev['lng'];

if ($centerLat == 0.0 && $centerLng == 0.0)
    bad('EVENT_NO_LOCATION');

$dist = distanceInMeters($lat, $lng, $centerLat, $centerLng);
if ($dist > $RADIUS_M)
    bad('OUT_OF_RANGE', array('dist' => round($dist), 'allow' => $RADIUS_M));

/* =====================================================
   CHECK DUPLICATE
===================================================== */
$stm = $pdo->prepare("
    SELECT 1 FROM attendance_logs
    WHERE user_id=? AND campaign_id=? AND DATE(time)=CURDATE() AND session=?
    LIMIT 1
");
$stm->execute(array($uid, $cid, $session));
if ($stm->fetch())
    bad('ALREADY_CHECKED');

/* =====================================================
   IP ↔ GPS FLAG (best-effort)
===================================================== */
$clientIp = att_getClientIp();
$userAgent = substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 255);

$riskFlags = array();
$ipGeoLat = null;
$ipGeoLng = null;
$ipGeoProvider = null;
$ipGpsKm = null;

$ipGeo = att_geoipLookupViaIpApi($clientIp);
if ($ipGeo) {
    $ipGeoLat = $ipGeo['lat'];
    $ipGeoLng = $ipGeo['lng'];
    $ipGeoProvider = $ipGeo['provider'];

    $ipGpsMeters = distanceInMeters($lat, $lng, $ipGeoLat, $ipGeoLng);
    $ipGpsKm = round($ipGpsMeters / 1000, 2);

    if ($ipGpsMeters >= ($IP_GPS_FLAG_KM * 1000)) {
        $riskFlags[] = 'IP_GPS_MISMATCH';
    }
} else {
    $riskFlags[] = 'IP_GEO_FAIL';
}

$riskFlagsStr = count($riskFlags) ? implode(',', $riskFlags) : null;

/* =====================================================
   INSERT LOG (fallback if schema chưa migrate)
===================================================== */
try {
    $stm = $pdo->prepare("
        INSERT INTO attendance_logs
            (user_id, campaign_id, event_id, result, session,
             ip_address, user_agent,
             ip_geo_lat, ip_geo_lng, ip_geo_provider, ip_gps_km,
             risk_flags,
             lat, lng, accuracy)
        VALUES
            (?, ?, ?, 'ok', ?,
             ?, ?,
             ?, ?, ?, ?,
             ?,
             ?, ?, ?)
    ");
    $stm->execute(array(
        $uid,
        $cid,
        $ev['id'],
        $session,
        $clientIp,
        $userAgent,
        $ipGeoLat,
        $ipGeoLng,
        $ipGeoProvider,
        $ipGpsKm,
        $riskFlagsStr,
        $lat,
        $lng,
        $accFinal
    ));
} catch (Throwable $e) {
    // schema cũ
    $stm = $pdo->prepare("
        INSERT INTO attendance_logs (user_id, campaign_id, event_id, result, session)
        VALUES (?, ?, ?, 'ok', ?)
    ");
    $stm->execute(array($uid, $cid, $ev['id'], $session));
}

log_activity(
    'update',
    'Điểm danh',
    'Phong trào',
    null,
    'Điểm danh ' . $sessionVi . ' cho phong trào: ' . $campTitle
);

echo json_encode(array(
    'ok' => 1,
    'redirect' => BASE_URL . 'index.php?p=campaigns_check&code=' . urlencode($code) . '&success=1',
    'risk_flags' => $riskFlagsStr,
), JSON_UNESCAPED_UNICODE);
exit;
