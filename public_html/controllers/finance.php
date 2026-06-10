<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';
require_once __DIR__ . '/../vendor/shuchkin/simplexlsxgen/src/SimpleXLSXGen.php';

auth_guard();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

use Shuchkin\SimpleXLSXGen;

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = (int) ($_SESSION['user_id'] ?? 0);

/* =========================
   JSON HELPERS
========================= */
function sync_participants(PDO $pdo, int $txId, array $memberIds): void
{
    $memberIds = array_values(array_unique(array_map('intval', $memberIds)));

    // clear old
    $pdo->prepare("DELETE FROM finance_transaction_participants WHERE transaction_id = ?")
        ->execute([$txId]);

    if (!$memberIds)
        return;

    // fetch snapshots (members.class_id -> classes.name)
    $in = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            m.id,
            COALESCE(NULLIF(m.fullname,''), m.mssv) AS fullname,
            m.mssv,
            COALESCE(c.name,'') AS class_text
        FROM members m
        LEFT JOIN classes c ON c.id = m.class_id
        WHERE m.id IN ($in)
    ");
    $stmt->execute($memberIds);
    $ms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $chk = $pdo->prepare("SELECT 1 FROM finance_transactions WHERE id = ? LIMIT 1");
    $chk->execute([$txId]);
    if (!$chk->fetchColumn()) {
        throw new Exception("transaction_id=$txId không tồn tại trong finance_transactions");
    }

    $ins = $pdo->prepare("
        INSERT INTO finance_transaction_participants
          (transaction_id, member_id, member_name, mssv, class_text)
        VALUES (?,?,?,?,?)
    ");

    foreach ($ms as $m) {
        $ins->execute([
            $txId,
            (int) $m['id'],
            (string) $m['fullname'],
            (string) ($m['mssv'] ?? ''),
            (string) ($m['class_text'] ?? '')
        ]);
    }
}

function next_voucher_code(PDO $pdo, int $year, string $type): string
{
    if (!in_array($type, ['income', 'expense'], true)) {
        throw new Exception("Invalid type for voucher counter");
    }

    // lock counter row theo (year,type)
    $st = $pdo->prepare("
        SELECT last_no
        FROM finance_voucher_counters
        WHERE year = ? AND type = ?
        FOR UPDATE
    ");
    $st->execute([$year, $type]);
    $last = $st->fetchColumn();

    // lấy max existing theo (year,type) để sync
    $stMax = $pdo->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(code,'-',-1) AS UNSIGNED)), 0)
        FROM finance_transactions
        WHERE type = ? AND code LIKE ?
    ");
    $stMax->execute([$type, $year . '-%']);
    $maxExisting = (int) $stMax->fetchColumn();

    if ($last === false) {
        // chưa có row counter => tạo theo maxExisting
        $pdo->prepare("
            INSERT INTO finance_voucher_counters(year, type, last_no)
            VALUES(?, ?, ?)
        ")->execute([$year, $type, $maxExisting]);
        $last = $maxExisting;
    } else {
        // đã có row nhưng last_no thấp hơn dữ liệu thật => nâng lên
        if ((int) $last < $maxExisting) {
            $pdo->prepare("
                UPDATE finance_voucher_counters
                SET last_no = ?
                WHERE year = ? AND type = ?
            ")->execute([$maxExisting, $year, $type]);
            $last = $maxExisting;
        }
    }

    $next = ((int) $last) + 1;

    $pdo->prepare("
        UPDATE finance_voucher_counters
        SET last_no = ?
        WHERE year = ? AND type = ?
    ")->execute([$next, $year, $type]);

    return $year . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}




function json_ok($data = null)
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err($msg, $code = 400)
{
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_sign_name(PDO $pdo): string
{
    try {
        $st = $pdo->query("SELECT sign_line3 FROM finance_voucher_settings WHERE id=1 LIMIT 1");
        $v = (string) $st->fetchColumn();
        $v = trim($v);
        return $v; // có thể rỗng

    } catch (Throwable $e) {
        return '';
    }
}

/* =========================
   PERMISSION
========================= */
function require_can($code, $act)
{
    if (!function_exists('can'))
        return;
    if (!can($code, $act))
        json_err('Bạn không có quyền thao tác', 403);
}

/* =========================
   OUTPUT BUFFER CLEAN
========================= */
function clean_output_buffers()
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

/* =========================
   INPUT JSON
========================= */
function read_json()
{
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function get_voucher_settings(PDO $pdo): array
{
    $default = [
        'org_line1' => 'Văn phòng Đoàn trường CĐBK Nam Sài Gòn',
        'org_line2' => 'Lầu 1 - khu A - phòng 1.19 (cạnh Hội trường A)',
        'org_line3' => 'Bí thư đoàn trường: 0362007006',
    ];

    try {
        $st = $pdo->query("SELECT org_line1, org_line2, org_line3 FROM finance_voucher_settings WHERE id=1 LIMIT 1");
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r)
            return $default;

        return [
            'org_line1' => trim((string) ($r['org_line1'] ?? $default['org_line1'])) ?: $default['org_line1'],
            'org_line2' => trim((string) ($r['org_line2'] ?? $default['org_line2'])) ?: $default['org_line2'],
            'org_line3' => trim((string) ($r['org_line3'] ?? $default['org_line3'])) ?: $default['org_line3'],
        ];
    } catch (Throwable $e) {
        return $default;
    }
}

/* =========================
   UTILS
========================= */
function to_money($v)
{
    // nhận cả "100.000", "100,000", "100 000" => 100000
    $s = preg_replace('/[^\d\-]/', '', (string) $v);
    return ($s !== '' && is_numeric($s)) ? (float) $s : 0;
}


function my_name(PDO $pdo, $userId)
{
    if (!$userId)
        return '';
    $stmt = $pdo->prepare("SELECT COALESCE(fullname, username) FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return (string) $stmt->fetchColumn();
}

/* =========================
   BUILD POSITION FOR INCOME (Lớp - Khoa - Khóa)
========================= */
function build_income_position_full($t)
{
    $parts = [];

    $cls = trim((string) ($t['class_text'] ?? ''));
    $dept = trim((string) ($t['department_name'] ?? ''));

    if ($cls !== '') {
        $parts[] = $cls;
    }

    if ($dept !== '') {
        $parts[] = 'Khoa ' . $dept;
    }

    return implode(' - ', $parts);
}


/* =========================
   MONEY WORDS (VI) - không dùng NumberFormatter
   -> đảm bảo 100.000.000 => "Một trăm triệu đồng"
========================= */
function vn_read_three($n, $full = false)
{
    $nums = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

    $n = (int) $n;
    $hundreds = intdiv($n, 100);
    $tens = intdiv($n % 100, 10);
    $units = $n % 10;

    $out = [];

    if ($hundreds > 0 || $full) {
        $out[] = $nums[$hundreds] . ' trăm';
    }

    if ($tens > 1) {
        $out[] = $nums[$tens] . ' mươi';

        if ($units === 1)
            $out[] = 'mốt';
        else if ($units === 4)
            $out[] = 'tư';
        else if ($units === 5)
            $out[] = 'lăm';
        else if ($units > 0)
            $out[] = $nums[$units];

    } elseif ($tens === 1) {
        $out[] = 'mười';
        if ($units === 5)
            $out[] = 'lăm';
        else if ($units > 0)
            $out[] = $nums[$units];

    } else { // tens = 0
        if ($units > 0) {
            if ($hundreds > 0 || $full)
                $out[] = 'lẻ';
            $out[] = ($units === 5 ? 'năm' : $nums[$units]);
        }
    }

    return trim(implode(' ', $out));
}
function sheet_replace_text(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $map): void
{
    foreach ($sheet->getRowIterator() as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(true);

        foreach ($cellIterator as $cell) {
            $v = $cell->getValue();
            if (!is_string($v) || $v === '')
                continue;

            $key = trim($v);
            if (isset($map[$key])) {
                $cell->setValueExplicit((string) $map[$key], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
    }
}

function vn_ucfirst($s)
{
    $s = (string) $s;
    if ($s === '')
        return $s;

    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $first = mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8');
        $rest = mb_substr($s, 1, null, 'UTF-8');
        return $first . $rest;
    }

    return ucfirst($s);
}

function vn_number_to_words($n)
{
    $n = (int) round((float) $n);
    if ($n <= 0)
        return 'Không đồng';

    $scales = ['', 'nghìn', 'triệu', 'tỷ', 'nghìn tỷ', 'triệu tỷ', 'tỷ tỷ'];
    $groups = [];

    while ($n > 0) {
        $groups[] = $n % 1000;
        $n = intdiv($n, 1000);
    }

    $parts = [];
    for ($i = count($groups) - 1; $i >= 0; $i--) {
        $g = $groups[$i];
        if ($g == 0)
            continue;

        $full = ($i < count($groups) - 1);
        $chunk = vn_read_three($g, $full);

        if ($chunk !== '') {
            $parts[] = $chunk . ($scales[$i] ? ' ' . $scales[$i] : '');
        }
    }

    $s = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    $s = vn_ucfirst($s);

    return $s . ' đồng';
}

function money_words_vi($n)
{
    return vn_number_to_words($n);
}




/* =========================
   EXPORT PDF (giữ như bạn đang dùng, đã fix nội dung)
========================= */
function export_voucher_pdf($t, $exportDateText, $makerName, $inline = false, array $cfg = [])
{
    require_once __DIR__ . '/../vendor/autoload.php';

    if (!class_exists('Dompdf\\Dompdf')) {
        json_err('Chưa cài dompdf (class Dompdf\\Dompdf không tồn tại)');
    }

    // ======================
    // BUILD DATA
    // ======================
    $type = $t['type'] ?? '';
    $isIncome = ($type === 'income');
    $title = $isIncome ? 'PHIẾU THU' : 'PHIẾU CHI';

    $personMain = $isIncome ? ($t['payer_name'] ?? '') : ($t['payee_name'] ?? '');

    if ($isIncome) {
        $positionLabel = "Lớp:";
        $position = build_income_position_full($t); // lớp + khoa + khóa
    } else {
        $positionLabel = "Chức vụ:";
        $position = (string) ($t['class_text'] ?? '');
    }

    // ✅ Lý do nộp/chi = cột nội dung (khoản thu/chi)
    $reason = trim((string) ($t['item_name'] ?? ''));
    if ($reason === '') {
        $reason = trim((string) ($t['description'] ?? '')); // fallback
    }

    $amount = (float) ($t['amount'] ?? 0);
    $amountText = number_format($amount, 0, ',', '.') . " đ";
    $amountWords = money_words_vi($amount);

    $attach = trim((string) ($t['note'] ?? ''));
    if ($attach === '')
        $attach = "....................";

    // ======================
    // FONT EMBED (Times New Roman)
    // ======================
    $fontDir = realpath(__DIR__ . '/../assets/fonts');

    $fReg = $fontDir ? realpath($fontDir . '/times.ttf') : false;
    $fBold = $fontDir ? realpath($fontDir . '/timesbd.ttf') : false;
    $fIta = $fontDir ? realpath($fontDir . '/timesi.ttf') : false;
    $fBI = $fontDir ? realpath($fontDir . '/timesbi.ttf') : false;

    $toFileUrl = function ($path) {
        $path = str_replace('\\', '/', $path);
        // Windows: C:/...
        if (preg_match('~^[A-Za-z]:/~', $path))
            return 'file:///' . $path;
        return 'file://' . $path;
    };

    $fontCss = "";
    // ✅ fallback serif (có dấu ngon)
    $fontFamily = "DejaVu Serif";

    if ($fReg && $fBold && $fIta && $fBI) {
        $fontFamily = "TimesVN";
        $fontCss = "
@font-face{
  font-family:'TimesVN';
  src:url('" . $toFileUrl($fReg) . "') format('truetype');
  font-weight:normal; font-style:normal;
}
@font-face{
  font-family:'TimesVN';
  src:url('" . $toFileUrl($fBold) . "') format('truetype');
  font-weight:bold; font-style:normal;
}
@font-face{
  font-family:'TimesVN';
  src:url('" . $toFileUrl($fIta) . "') format('truetype');
  font-weight:normal; font-style:italic;
}
@font-face{
  font-family:'TimesVN';
  src:url('" . $toFileUrl($fBI) . "') format('truetype');
  font-weight:bold; font-style:italic;
}";
    }

    // ======================
    // RENDER 1 VOUCHER BLOCK
    // ======================
    $renderBlock = function () use ($t, $title, $exportDateText, $isIncome, $personMain, $positionLabel, $position, $reason, $amountText, $amountWords, $attach, $makerName, $cfg) {
        // ✅ dùng sign_line3 thống nhất
        $signName = trim((string) ($cfg['sign_line3'] ?? ''));
        if ($signName === '')
            $signName = $makerName;

        return "
<div class='voucher'>
  <table class='top'>
    <tr>
      <td style='width:60%'>
        <div class='left'>
<p>" . htmlspecialchars($cfg['org_line1'] ?? '') . "</p>
<p>" . htmlspecialchars($cfg['org_line2'] ?? '') . "</p>
<p>" . htmlspecialchars($cfg['org_line3'] ?? '') . "</p>
        </div>
      </td>
<td style='width:40%' class='right'>
  <div class='ms'><b>Mẫu số: 01-TT</b></div>

  <div class='tt'>
    (Ban hành theo Thông tư số 200/2014/TT-BTC ngày 22/12/2014 của Bộ Tài chính)
  </div>

<table class='meta-table'>
  <tr>
    <td class='meta-label'>Quyển số:</td>
<td class='meta-dots'>
  <div class='dots'></div>
</td>
  </tr>
<tr>
  <td class='meta-label'>Số:</td>
<td class='meta-dots'>
  <div class='dots'>
    <span class='line'></span>
    <span class='code'>" . htmlspecialchars((string) ($t['code'] ?? '')) . "</span>
  </div>
</td>

</tr>

  <tr>
    <td class='meta-label'>Nợ:</td>
<td class='meta-dots'>
  <div class='dots'></div>
</td>
  </tr>
  <tr>
    <td class='meta-label'>Có:</td>
<td class='meta-dots'>
  <div class='dots'></div>
</td>
  </tr>
</table>



</td>

    </tr>
  </table>

  <div class='center'>
    <h1>{$title}</h1>
    <div class='date'>{$exportDateText}</div>
  </div>

  <table class='form'>
  <tr>
    <td class='lbl'>Họ và tên người nộp tiền:</td>
    <td class='val no-dots'><span class='hoten'>" . htmlspecialchars($personMain) . "</span></td>
  </tr>

  <tr>
    <td class='lbl'>{$positionLabel}</td>
    <td class='val no-dots'>
      <span class='shift'>" . htmlspecialchars($position) . "</span>
    </td>
  </tr>

  <tr>
    <td class='lbl'>Lý do nộp:</td>
    <td class='val no-dots'>
      <span class='shift'>" . htmlspecialchars($reason) . "</span>
    </td>
  </tr>

  <tr>
    <td class='lbl'>Số tiền:</td>
    <td class='val no-dots'>
      <span class='shift'>" . htmlspecialchars($amountText) . "</span>
    </td>
  </tr>

  <tr>
    <td class='lbl'>Bằng chữ:</td>
    <td class='val no-dots'>
      <span class='shift'>" . htmlspecialchars($amountWords) . "./</span>
    </td>
  </tr>

  <tr>
    <td class='lbl'>Kèm theo:</td>
    <td class='val no-dots'>
      <span class='shift'>" . htmlspecialchars($attach) . " chứng từ gốc</span>
    </td>
  </tr>
</table>


  <table class='sign'>
    <tr>
      <td>
        <div class='cap'>Bí thư đoàn trường</div>
        <div class='note'>(Ký, họ tên, đóng dấu)</div>
        <div class='name'>" . htmlspecialchars($signName) . "</div>
      </td>
      <td>
        <div class='cap'>" . ($isIncome ? "Người nộp tiền" : "Người nhận tiền") . "</div>
        <div class='note'>(Ký, họ tên)</div>
        <div class='name'>" . htmlspecialchars($personMain) . "</div>
      </td>
      <td>
        <div class='cap'>Người lập phiếu</div>
        <div class='note'>(Ký, họ tên)</div>
        <div class='name'>" . htmlspecialchars($makerName) . "</div>
      </td>
    </tr>
  </table>

<div class='foot'>
  <div class='foot-main'>
    Đã nhận đủ số tiền (viết bằng chữ): <b>" . htmlspecialchars($amountWords) . "./</b>
  </div>

  <div class='foot-line'>
    + Tỷ giá ngoại tệ (vàng bạc, đá quý): .................................................................................
  </div>
  <div class='foot-line'>
    + Số tiền quy đổi: ...............................................................................................................
  </div>
</div>

</div>
";
    };

    // ======================
    // HTML: 2 LIÊN / 1 TRANG (TABLE 50%)
    // ======================
    $html = "
<!doctype html>
<html>
<head>
<meta charset='utf-8'>
<style>
  {$fontCss}


  /* ✅ nhẹ lề để nhét chắc 2 liên */
  @page { margin: 6mm 10mm; }

  body{
    margin:0; padding:0;
    font-size: 12px;
    color:#000;
    font-family: {$fontFamily}, 'Times New Roman', 'DejaVu Serif', serif;
  }

  /* ✅ ép TẤT CẢ về 1 font, không cho fallback linh tinh */
  body, table, tr, td, div, p, span, b, i, u, h1, h2, h3{
    font-family: {$fontFamily}, 'Times New Roman', 'DejaVu Serif', serif !important;
  }

  /* ✅ TABLE full trang, chia 2 nửa */
  table.page{
    width:100%;
    height: 100%;
    border-collapse: collapse;
    table-layout: fixed;
  }
  td.half{
    vertical-align: top;
    padding: 0;
  }

  .cutline{
    height: 2mm;
    border-top: 1px dashed #000;
  }

  .voucher{ width:100%; }

  .top{ width:100%; border-collapse:collapse; }
  .top td{ vertical-align:top; }

  /* ✅ QUAN TRỌNG: KHÔNG XÀI 600 -> fallback font */
/* ✅ 13pt in đậm (Văn phòng - địa chỉ - số điện thoại) */
.left p{
  margin:0;
  line-height:1.1;
  font-weight:bold;
  font-size:13pt;
}

/* ✅ cột phải */
.right{
  text-align:center;
}

/* ✅ 9.5pt in đậm: Mẫu số */
.right .ms{
  font-size:9.5pt;
  font-weight:700;
}

/* ✅ 9.5pt in nghiêng: thông tư */
.right .tt{
  font-size:9.5pt;
  font-style:italic;
  line-height:1.2;
  margin-top:1px;
}

/* ✅ 12pt: Quyển số / Số / Nợ / Có */
.right .meta{
  font-size:12pt;
  font-style:normal;
  font-weight:400;
  line-height:1.2;

}

.right .meta.mt{
  margin-top:4px;
}

.right .meta{
  white-space: nowrap;
}
.meta-table{
  width:100%;
  border-collapse:collapse;
  margin-left:4px;
}

.meta-table td{
  font-size: 12pt;
  line-height:1.2;
  vertical-align:middle;   /* QUAN TRỌNG: middle, không bottom/baseline */
  padding:0;
}

/* cột chữ */
.meta-label{
  width:50px;
  white-space:nowrap;
  text-align:center;
  padding-left:10px;
}

/* cột dấu chấm */
.meta-dots{
  width:70px;
}

/* 🔥 DẤU CHẤM THẬT SỰ NẰM Ở ĐÂY */
.meta-dots .dots{
  position: relative;          /* ✅ BẮT BUỘC */
  height:1em;                         /* = chiều cao chữ */
  border-bottom:1px dotted #000;      /* vẽ ở đáy div */
}

/* chữ */
.meta-dots .dots .code{
  position:absolute;
  left:0;
  bottom:0;
  font-weight:700;
  font-size:11pt;
  padding:0 4px;
  background:#fff;  /* chỉ che đúng vùng chữ */
}
td.val.no-dots{
  border-bottom: none !important;
  padding-bottom: 3.2px;   /* 🔥 chỉnh 1–3px là đẹp */
}

/* dịch nội dung của 5 dòng dưới */
table.form td.val span.shift{
  display: inline-block;   /* BẮT BUỘC */
  margin-left: -75px;      /* chỉnh 20–32px tùy mắt */
}
table.form td.val span.hoten{
  display: inline-block;   /* BẮT BUỘC */
  margin-left: 5px;      /* chỉnh 20–32px tùy mắt */
}
table.form td{
  line-height: 0.7;
}
  .center{ text-align:center; margin-top: -70px; }
  .center h1{ margin:2px 0; font-size:16pt; letter-spacing:0.5px; }
  .center .date{ margin-top: 0px; font-style:italic; font-size:11pt; margin-bottom: 20px;}

  table.form{ width:100%; border-collapse:collapse; margin-top:6px; }
  table.form td{ padding:4px 0; vertical-align:bottom; }
  td.lbl{ width:170px; white-space:nowrap; font-size:12pt;}
  td.val{
    border-bottom: 1px dotted #111;   /* ✅ cái “gạch chân” nằm ở đây */
    padding-left:8px;
    font-size:12pt;
  }
  td.val span{ display:inline-block; width:100%; }

  .sign{ width:100%; margin-top:4px; }
  .sign td{ width:33.33%; text-align:center; vertical-align:top; }
  .cap{ font-weight:700; font-size: 16px;}
  .note{ font-size:10px; font-style:italic; margin-top:3px; font-size: 11pt;}
  .name{ margin-top:70px; font-weight:700; font-size: 11pt;}

  .foot{ margin-top:0px; font-size:16px; line-height:1; }

  body, table, td, span, div {
  font-family: TimesVN, 'DejaVu Serif', serif !important;
}
.foot-main{
    margin-bottom: 10px;
}

.foot-line{
  margin-bottom: 6px;
  line-height: 0.8;
}
  
</style>
</head>
<body>

<table class='page'>
  <tr>
    <td class='half'>
      " . $renderBlock() . "
    </td>
  </tr>

  <tr>
    <td style='padding: 2mm 0;'>
      <div class='cutline'></div>
    </td>
  </tr>

  <tr>
    <td class='half'>
      " . $renderBlock() . "
    </td>
  </tr>
</table>

</body>
</html>
";

    // ✅ dọn output để PDF không dính whitespace/JSON
    clean_output_buffers();

    // ======================
    // DOMPDF OPTIONS
    // ======================
    $options = new Dompdf\Options();
    $options->setIsHtml5ParserEnabled(true);
    $options->setIsRemoteEnabled(true);              // ✅ cho phép load font file://
    $options->setIsFontSubsettingEnabled(true);

    // ✅ chroot là 1 string, root project
    $options->setChroot(realpath(__DIR__ . '/..'));

    // ✅ default font
    $options->setDefaultFont($fontFamily);

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $fname = ($isIncome ? "phieu_thu_" : "phieu_chi_") . ($t['id'] ?? '0') . ".pdf";

    // ✅ dompdf stream sẽ tự set header chuẩn inline/download
// ✅ bỏ stream + exit, đổi sang return bytes
    $pdfBytes = $dompdf->output();
    return $pdfBytes;

}



/* =========================
   EXPORT XLSX - LOAD TEMPLATE rptPhieuThu200.xlsx (GIỐNG FILE BẠN GỬI)
========================= */
function export_voucher_xlsx($t, $exportDateText, $makerName, array $cfg = [])
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $type = $t['type'] ?? '';
    $isIncome = ($type === 'income');

    $title = $isIncome ? 'PHIẾU THU' : 'PHIẾU CHI';

    $personMain = $isIncome ? ($t['payer_name'] ?? '') : ($t['payee_name'] ?? '');
    $signName = trim((string) ($cfg['sign_line3'] ?? ''));
    if ($signName === '')
        $signName = $makerName;

    // ✅ label + value "Lớp / Chức vụ"
    if ($isIncome) {
        $positionLabel = "Lớp:";
        $position = build_income_position_full($t);
        $reasonLabel = "Lý do nộp:";
        $whoLabel = "Họ và tên người nộp tiền:";
    } else {
        $positionLabel = "Chức vụ:";
        $position = (string) ($t['class_text'] ?? '');
        $reasonLabel = "Lý do chi:";
        $whoLabel = "Họ và tên người nhận tiền:";
    }

    // ✅ Lý do nộp/chi = cột nội dung (khoản thu/chi)
    $reason = trim((string) ($t['item_name'] ?? ''));
    if ($reason === '') {
        $reason = trim((string) ($t['description'] ?? '')); // fallback
    }
    $amount = (float) ($t['amount'] ?? 0);

    // ✅ số tiền có đ
    $amountText = number_format($amount, 0, ',', '.') . " đ";

    // ✅ bằng chữ: Một trăm triệu đồng
    $amountWords = money_words_vi($amount) . "./";

    $attach = trim((string) ($t['note'] ?? ''));
    $attachText = ($attach !== '' ? $attach : "....................") . "chứng từ gốc";

    // ✅ TEMPLATE PATH
    $tpl1 = __DIR__ . '/../assets/templates/rptPhieuThu200.xlsx';
    $tpl2 = __DIR__ . '/../rptPhieuThu200.xlsx';
    $tplPath = file_exists($tpl1) ? $tpl1 : (file_exists($tpl2) ? $tpl2 : '');

    if ($tplPath === '') {
        json_err("Không tìm thấy template XLSX: rptPhieuThu200.xlsx (hãy đặt vào assets/templates/)");
    }
    $old1 = 'Văn phòng Đoàn trường CĐBK Nam Sài Gòn';
    $old2 = 'Lầu 1 - khu A - phòng 1.19 (cạnh Hội trường A)';
    $old3 = 'Bí thư đoàn trường: 0362007006';

    $spreadsheet = IOFactory::load($tplPath);
    $sheet = $spreadsheet->getActiveSheet();

    sheet_replace_text($sheet, [
        $old1 => (string) ($cfg['org_line1'] ?? $old1),
        $old2 => (string) ($cfg['org_line2'] ?? $old2),
        $old3 => (string) ($cfg['org_line3'] ?? $old3),
    ]);


    // helper set cell as string (không bị scientific)
    $setS = function ($addr, $val) use ($sheet) {
        $sheet->setCellValueExplicit($addr, (string) $val, DataType::TYPE_STRING);
    };
    $code = (string) ($t['code'] ?? '');

    // Fill 2 phiếu trên 1 trang (offset = 0 và 34)
    $code = (string) ($t['code'] ?? '');

    $fillOne = function ($off) use ($sheet, $setS, $title, $exportDateText, $whoLabel, $personMain, $positionLabel, $position, $reasonLabel, $reason, $amountText, $amountWords, $attachText, $makerName, $isIncome, $signName, $code) {
        // Title + date
        $setS("G" . (7 + $off), $title);
        $setS("G" . (12 + $off), $exportDateText);

        // Labels (đổi được Thu/Chi)
        $setS("A" . (17 + $off), $whoLabel);
        $setS("A" . (18 + $off), $positionLabel);
        $setS("A" . (19 + $off), $reasonLabel);

        // Values
        $setS("F" . (17 + $off), $personMain);
        $setS("D" . (18 + $off), $position);
        $setS("D" . (19 + $off), $reason);
        $setS("D" . (20 + $off), $amountText);
        $setS("D" . (21 + $off), $amountWords);
        $setS("D" . (22 + $off), $attachText);


        // Names sign
        $setS("B" . (28 + $off), $signName);
        $setS("J" . (28 + $off), $makerName);
        $setS("H" . (28 + $off), $personMain);

        // Nếu muốn đổi caption "Người nộp tiền / Người nhận tiền" trên template:
        // template đang có label ở H25:Q25 và H59:Q59
        $setS("H" . (25 + $off), $isIncome ? "Người nộp tiền" : "Người nhận tiền");

        // ✅ SET "Số:" (vì vùng này đang merge, phải ghi vào ô top-left)
        if ($off === 0) {
            // phiếu 1: ô chứa “Số:” thường là L8 (theo template bạn gửi)
            $setS("Q8", $code);
        } else {
            // phiếu 2: ô chứa “Số:” thường là M42 (offset 34)
            $setS("Q42", $code);
        }
    };

    // phiếu 1 + phiếu 2
    $fillOne(0);
    $fillOne(34);

    // Output
    clean_output_buffers();
    header_remove('Content-Type');

    $fname = ($isIncome ? "phieu_thu_" : "phieu_chi_") . ($t['id'] ?? '0') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$fname\"");

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}


function get_active_semesters(PDO $pdo): array
{
    // bảng semesters của bạn: code, label, sort_order, is_active
    $stmt = $pdo->query("
        SELECT code, label, sort_order
        FROM semesters
        WHERE is_active = 1
        ORDER BY sort_order ASC, code ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function validate_semester_or_null(PDO $pdo, ?string $semester): ?string
{
    $semester = trim((string) $semester);
    if ($semester === '')
        return null;

    $rows = get_active_semesters($pdo);
    $allowed = array_map(fn($r) => (string) $r['code'], $rows);

    if (!in_array($semester, $allowed, true)) {
        json_err('Học kỳ không hợp lệ', 400);
    }
    return $semester;
}


/* =========================
   MAIN
========================= */
try {

    switch ($action) {
        case 'voucher_sign_get':
            require_can('finance', 'view');
            json_ok(['sign_line3' => get_sign_name($pdo)]);
            break;

        case 'voucher_sign_save':
            require_can('finance', 'update');
            $input = read_json();
            $name = trim((string) ($input['sign_line3'] ?? ''));

            $uid = (int) ($_SESSION['user_id'] ?? 0);

            $st = $pdo->prepare("
        INSERT INTO finance_voucher_settings
          (id, org_line1, org_line2, org_line3, sign_line3, updated_by, updated_at)
        VALUES
          (1, '', '', '', ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          sign_line3 = VALUES(sign_line3),
          updated_by = VALUES(updated_by),
          updated_at = NOW()
    ");
            $st->execute([$name, $uid]);

            log_activity('update', 'finance', 'Cấu hình phiếu', 1, 'Cập nhật tên người ký (Bí thư đoàn trường)');
            json_ok(true);
            break;


        case 'voucher_settings_get':
            require_can('finance', 'view'); // hoặc update nếu bạn muốn chặn chặt
            $cfg = get_voucher_settings($pdo);
            json_ok($cfg);
            break;
        case 'voucher_settings_save':
            require_can('finance', 'update'); // hoặc chỉ admin nếu muốn
            $input = read_json();

            $l1 = trim((string) ($input['org_line1'] ?? $input['line1'] ?? $input['header_line1'] ?? ''));
            $l2 = trim((string) ($input['org_line2'] ?? $input['line2'] ?? $input['header_line2'] ?? ''));
            $l3 = trim((string) ($input['org_line3'] ?? $input['line3'] ?? $input['header_line3'] ?? ''));


            if ($l1 === '' || $l2 === '' || $l3 === '') {
                json_err('Vui lòng nhập đủ 3 dòng thông tin phiếu', 400);
            }

            $st = $pdo->prepare("
    INSERT INTO finance_voucher_settings
      (id, org_line1, org_line2, org_line3, sign_line3, updated_by, updated_at)
    VALUES
      (1, ?, ?, ?, '', ?, NOW())
    ON DUPLICATE KEY UPDATE
      org_line1 = VALUES(org_line1),
      org_line2 = VALUES(org_line2),
      org_line3 = VALUES(org_line3),
      updated_by = VALUES(updated_by),
      updated_at = NOW()
");
            $st->execute([$l1, $l2, $l3, (int) ($_SESSION['user_id'] ?? 0)]);


            log_activity('update', 'finance', 'Cấu hình phiếu', 1, 'Cập nhật thông tin đầu phiếu (phiếu thu/chi)');
            json_ok(true);
            break;

        /* =========================================================
           META: departments + courses + me
        ========================================================= */
        case 'meta':
            require_can('finance', 'view');

            $depts = $pdo->query("
        SELECT id, name, COALESCE(type,'khoa') AS type
        FROM departments
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

            $courses = [];
            try {
                $courses = $pdo->query("
            SELECT id, name
            FROM courses
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $courses = [];
            }

            $schoolYears = [];
            try {
                $schoolYears = $pdo->query("
            SELECT id, year_label
            FROM school_years
            ORDER BY id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $schoolYears = [];
            }

            $semesters = [];
            try {
                $semesters = get_active_semesters($pdo);
            } catch (Exception $e) {
                $semesters = [];
            }

            $me = null;
            if ($userId) {
                $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.username,
                COALESCE(m.fullname, u.fullname, u.username) AS name,
                u.role_id,
                COALESCE(r.name, '') AS role_name,
                CASE
                    WHEN LOWER(COALESCE(r.name,'')) = 'admin' THEN 1
                    ELSE 0
                END AS is_admin
            FROM users u
            LEFT JOIN members m ON m.user_id = u.id
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
            LIMIT 1
        ");
                $stmt->execute([$userId]);
                $me = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            json_ok([
                'departments' => $depts,
                'courses' => $courses,
                'school_years' => $schoolYears,
                'semesters' => $semesters,
                'me' => $me
            ]);

            break;



        /* =========================================================
           ITEMS (Khoản thu/chi) CRUD
        ========================================================= */
        case 'items_list':
            require_can('finance', 'view');
            $input = read_json();
            $type = $input['type'] ?? 'income';
            if (!in_array($type, ['income', 'expense']))
                $type = 'income';

            // Tự nâng cấp CSDL nếu chưa có cột target_type
            try {
                $pdo->query("SELECT target_type FROM finance_items LIMIT 1");
            } catch (Throwable $e) {
                $pdo->exec("ALTER TABLE finance_items ADD COLUMN target_type VARCHAR(20) DEFAULT 'tat_ca'");
            }

            $stmt = $pdo->prepare("
                SELECT id, name, COALESCE(target_type, 'tat_ca') AS target_type
                FROM finance_items
                WHERE type = ? AND is_active = 1
                ORDER BY name ASC
                LIMIT 500
            ");
            $stmt->execute([$type]);
            json_ok(['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'items_create':
            require_can('finance', 'create');
            $input = read_json();
            $type = $input['type'] ?? 'income';
            if (!in_array($type, ['income', 'expense']))
                json_err('Type không hợp lệ');

            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '')
                json_err('Chưa nhập tên khoản');

            $targetType = trim((string) ($input['target_type'] ?? 'tat_ca'));
            if (!in_array($targetType, ['tat_ca', 'doan_vien', 'thanh_nien'])) {
                $targetType = 'tat_ca';
            }

            $stmt = $pdo->prepare("
                INSERT INTO finance_items(type, name, target_type, is_active)
                VALUES(?, ?, ?, 1)
            ");
            $stmt->execute([$type, $name, $targetType]);

            $newId = (int) $pdo->lastInsertId();

            log_activity(
                'create',
                'finance',
                'Thu-Chi',
                $newId,
                'Thêm ' . ($type === 'income' ? 'khoản thu: ' : 'khoản chi: ') . $name . ' (' . $targetType . ')'
            );

            json_ok(['id' => $newId]);
            break;

        case 'items_update':
            require_can('finance', 'update');
            $input = read_json();
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0)
                json_err('Thiếu ID');

            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '')
                json_err('Chưa nhập tên khoản');

            $targetType = trim((string) ($input['target_type'] ?? 'tat_ca'));
            if (!in_array($targetType, ['tat_ca', 'doan_vien', 'thanh_nien'])) {
                $targetType = 'tat_ca';
            }

            $stmt = $pdo->prepare("UPDATE finance_items SET name = ?, target_type = ? WHERE id = ?");
            $stmt->execute([$name, $targetType, $id]);

            log_activity('update', 'finance', 'Thu-Chi', $id, 'Cập nhật khoản: ' . $name . ' (' . $targetType . ')');

            json_ok(true);
            break;

        case 'items_delete':
            require_can('finance', 'delete');
            $input = read_json();
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0)
                json_err('Thiếu ID');

            $stmt = $pdo->prepare("DELETE FROM finance_items WHERE id = ?");
            $stmt->execute([$id]);

            log_activity('delete', 'finance', 'Thu-Chi', $id, 'Xoá khoản thu/chi ID: ' . $id);

            json_ok(true);
            break;

        /* =========================================================
           POSITIONS (Chức vụ) CRUD
        ========================================================= */
        case 'positions_list':
            require_can('finance', 'view');

            $stmt = $pdo->query("
                SELECT id, name
                FROM finance_positions
                WHERE is_active = 1
                ORDER BY name ASC
                LIMIT 500
            ");
            json_ok(['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'positions_create':
            require_can('finance', 'create');
            $input = read_json();
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '')
                json_err('Chưa nhập chức vụ');

            $stmt = $pdo->prepare("INSERT INTO finance_positions(name, is_active) VALUES(?,1)");
            $stmt->execute([$name]);
            $newId = (int) $pdo->lastInsertId();

            log_activity('create', 'finance', 'Thu-Chi', $newId, 'Thêm chức vụ: ' . $name);

            json_ok(['id' => $newId]);
            break;

        case 'positions_update':
            require_can('finance', 'update');
            $input = read_json();
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0)
                json_err('Thiếu ID');

            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '')
                json_err('Chưa nhập chức vụ');

            $stmt = $pdo->prepare("UPDATE finance_positions SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);

            log_activity('update', 'finance', 'Thu-Chi', $id, 'Cập nhật chức vụ: ' . $name);

            json_ok(true);
            break;

        case 'positions_delete':
            require_can('finance', 'delete');
            $input = read_json();
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0)
                json_err('Thiếu ID');

            $stmt = $pdo->prepare("DELETE FROM finance_positions WHERE id = ?");
            $stmt->execute([$id]);

            log_activity('delete', 'finance', 'Thu-Chi', $id, 'Xoá chức vụ ID: ' . $id);

            json_ok(true);
            break;

        /* =========================================================
           CLASSES BY KHOA + KHÓA
        ========================================================= */
        case 'classes_by_dept_course':
            require_can('finance', 'view');
            $input = read_json();

            $deptId = (int) ($input['department_id'] ?? 0);
            $courseId = (int) ($input['course_id'] ?? 0);

            if ($deptId <= 0) {
                json_ok(['rows' => []]);
            }

            if ($courseId > 0) {
                $stmt = $pdo->prepare("
                    SELECT id, name
                    FROM classes
                    WHERE department_id = ? AND course_id = ?
                    ORDER BY name ASC
                    LIMIT 300
                ");
                $stmt->execute([$deptId, $courseId]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT id, name
                    FROM classes
                    WHERE department_id = ?
                    ORDER BY name ASC
                    LIMIT 300
                ");
                $stmt->execute([$deptId]);
            }

            json_ok(['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        /* =========================================================
           MEMBERS SUGGEST (fullname + mssv)
        ========================================================= */
        case 'members_suggest':
            require_can('finance', 'view');
            $input = read_json();

            $q = trim((string) ($input['q'] ?? ''));
            $limit = (int) ($input['limit'] ?? 10);
            if ($limit < 5)
                $limit = 5;
            if ($limit > 20)
                $limit = 20;

            if ($q === '') {
                $stmt = $pdo->prepare("
                    SELECT
                        m.id,
                        m.mssv,
                        COALESCE(NULLIF(m.fullname,''), m.mssv) AS fullname,
                        c.name AS class_text
                    FROM members m
                    LEFT JOIN classes c ON c.id = m.class_id
                    ORDER BY fullname ASC
                    LIMIT $limit
                ");
                $stmt->execute();
            } else {
                $like = '%' . $q . '%';

                $stmt = $pdo->prepare("
                    SELECT
                        m.id,
                        m.mssv,
                        COALESCE(NULLIF(m.fullname,''), m.mssv) AS fullname,
                        c.name AS class_text
                    FROM members m
                    LEFT JOIN classes c ON c.id = m.class_id
                    WHERE (m.fullname LIKE ? OR m.mssv LIKE ?)
                    ORDER BY fullname ASC
                    LIMIT $limit
                ");
                $stmt->execute([$like, $like]);
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $out = array_map(function ($r) {
                return [
                    'id' => $r['id'],
                    'name' => $r['fullname'],
                    'mssv' => $r['mssv'],
                    'class_text' => $r['class_text'] ?? ''
                ];
            }, $rows);

            json_ok(['rows' => $out]);
            break;

        /* =========================================================
   LIST + STATS (có năm học + học kỳ)
========================================================= */
        case 'list':
            require_can('finance', 'view');
            $input = read_json();

            $page = max(1, (int) ($input['page'] ?? 1));
            $pageSize = max(5, min(50, (int) ($input['page_size'] ?? 10)));

            $type = $input['type'] ?? 'all'; // all|income|expense
            $deptId = trim((string) ($input['department_id'] ?? ''));
            $courseId = trim((string) ($input['course_id'] ?? ''));
            $classText = trim((string) ($input['class_text'] ?? ''));
            $q = trim((string) ($input['q'] ?? ''));

            $from = trim((string) ($input['from'] ?? ''));
            $to = trim((string) ($input['to'] ?? ''));

            // ✅ thêm filter optional
            $schoolYearId = trim((string) ($input['school_year_id'] ?? ''));
            $semester = trim((string) ($input['semester'] ?? ''));

            $where = " WHERE 1=1 ";
            $params = [];

            if (in_array($type, ['income', 'expense'])) {
                $where .= " AND t.type = ? ";
                $params[] = $type;
            }

            if ($deptId !== '') {
                $where .= " AND t.department_id = ? ";
                $params[] = (int) $deptId;
            }

            if ($courseId !== '') {
                $where .= " AND t.course_id = ? ";
                $params[] = (int) $courseId;
            }

            if ($classText !== '') {
                $where .= " AND t.class_text LIKE ? ";
                $params[] = '%' . $classText . '%';
            }

            if ($from !== '') {
                $where .= " AND t.trans_date >= ? ";
                $params[] = $from;
            }

            if ($to !== '') {
                $where .= " AND t.trans_date <= ? ";
                $params[] = $to;
            }

            // ✅ filter optional: năm học / học kỳ
            if ($schoolYearId !== '') {
                $where .= " AND t.school_year_id = ? ";
                $params[] = (int) $schoolYearId;
            }

            if ($semester !== '') {
                $where .= " AND t.semester = ? ";
                $params[] = $semester;
            }

            if ($q !== '') {
                $where .= " AND (
            t.item_name LIKE ?
            OR t.payer_name LIKE ?
            OR t.receiver_name LIKE ?
            OR t.payee_name LIKE ?
            OR t.note LIKE ?
            OR t.description LIKE ?
            OR t.code LIKE ?
        ) ";
                $like = '%' . $q . '%';
                array_push($params, $like, $like, $like, $like, $like, $like, $like);
            }

            // COUNT
            $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM finance_transactions t
        $where
    ");
            $stmt->execute($params);
            $total = (int) $stmt->fetchColumn();

            $totalPages = max(1, (int) ceil($total / $pageSize));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $pageSize;

            // ✅ LIST ROWS: JOIN school_years lấy year_label
            $stmt = $pdo->prepare("
    SELECT
        t.id,
        t.code,
        t.type,
        t.item_name,
        t.quantity,
        t.source_item_id,
        t.unit_price,
        t.amount,
        t.trans_date,
        t.school_year_id,
        t.semester,
        sy.year_label AS school_year_label,
        sem.label AS semester_label,
        t.department_id,
        t.course_id,
        t.class_text,
        t.payer_name,
        t.receiver_name,
        t.payee_name,
        t.description,
        t.note,
        COALESCE(t.status,'active') AS status,
        d.name AS department_name,
        COALESCE(d.type,'khoa') AS department_type,
        cr.name AS course_name,
        COALESCE(u.fullname, u.username) AS created_by_name,
        t.created_at,
        -- ==================== FIX CHO MYSQL CŨ ====================
        (SELECT GROUP_CONCAT(member_id ORDER BY member_id SEPARATOR ',')
         FROM finance_transaction_participants 
         WHERE transaction_id = t.id) AS participant_ids_raw
        -- ======================================================
    FROM finance_transactions t
    LEFT JOIN departments d ON d.id = t.department_id
    LEFT JOIN courses cr ON cr.id = t.course_id
    LEFT JOIN users u ON u.id = t.created_by
    LEFT JOIN school_years sy ON sy.id = t.school_year_id
    LEFT JOIN semesters sem ON sem.code = t.semester
    $where
    ORDER BY t.trans_date DESC, t.id DESC
    LIMIT $pageSize OFFSET $offset
");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ✅ FIX: Chuyển GROUP_CONCAT thành mảng số cho JS (tương thích MySQL cũ)
            foreach ($rows as &$r) {
                if (!empty($r['participant_ids_raw'])) {
                    $ids = explode(',', $r['participant_ids_raw']);
                    $r['participant_ids'] = array_map('intval', $ids);
                } else {
                    $r['participant_ids'] = [];
                }
                unset($r['participant_ids_raw']);
            }
            // STATS
            $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) AS total_expense
        FROM finance_transactions t
        $where
    ");
            $stmt->execute($params);
            $st = $stmt->fetch(PDO::FETCH_ASSOC);

            $income = (float) ($st['total_income'] ?? 0);
            $expense = (float) ($st['total_expense'] ?? 0);

            json_ok([
                'rows' => $rows,
                'paging' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'total_pages' => $totalPages
                ],
                'stats' => [
                    'income' => $income,
                    'expense' => $expense,
                    'balance' => $income - $expense
                ]
            ]);
            break;


        /* =========================================================
   CREATE
========================================================= */
        /* =========================================================
           CREATE
        ========================================================= */
        case 'create':
            require_can('finance', 'create');
            $input = read_json();

            $type = $input['type'] ?? '';
            if (!in_array($type, ['income', 'expense']))
                json_err('Type không hợp lệ');

            $item = trim((string) ($input['item_name'] ?? ''));
            if ($item === '')
                json_err('Chưa nhập Khoản thu/chi');

            $date = trim((string) ($input['trans_date'] ?? ''));
            if ($date === '')
                json_err('Chưa chọn ngày');

            $year = (int) date('Y'); // fallback
            if ($date !== '') {
                $dtTmp = DateTime::createFromFormat('Y-m-d', $date);
                if ($dtTmp)
                    $year = (int) $dtTmp->format('Y');
            }

            $schoolYearId = isset($input['school_year_id']) && $input['school_year_id'] !== ''
                ? (int) $input['school_year_id']
                : null;

            $semester = validate_semester_or_null($pdo, $input['semester'] ?? null);


            $desc = trim((string) ($input['description'] ?? ''));
            $note = trim((string) ($input['note'] ?? ''));

            $qty = (int) ($input['quantity'] ?? 1);
            if ($qty <= 0)
                $qty = 1;

            $unitPrice = to_money($input['unit_price'] ?? 0);
            $amount = to_money($input['amount'] ?? 0);
            if ($amount <= 0 && $unitPrice > 0)
                $amount = $unitPrice * $qty;
            if ($amount <= 0)
                json_err('Số tiền phải > 0');

            $code = trim((string) ($input['code'] ?? ''));
            if ($code === '')
                $code = null;


            $makerName = my_name($pdo, $userId);

            $departmentId = null;
            $courseId = null;
            $classText = null;
            $payerName = null;
            $receiverName = null;
            $payeeName = null;

            $sourceItemId = null;
            if ($type === 'expense') {
                $raw = $input['source_item_id'] ?? $input['source_id'] ?? $input['sourceItemId'] ?? '';
                $raw = trim((string) $raw);
                $sourceItemId = ($raw !== '' && ctype_digit($raw) && (int) $raw > 0) ? (int) $raw : null;
            }

            if ($type === 'income') {
                $departmentId = isset($input['department_id']) && $input['department_id'] !== '' ? (int) $input['department_id'] : null;
                $courseId = isset($input['course_id']) && $input['course_id'] !== '' ? (int) $input['course_id'] : null;

                $classText = trim((string) ($input['class_text'] ?? ''));
                if ($classText === '')
                    $classText = null;

                $payerName = trim((string) ($input['payer_name'] ?? ''));
                if ($payerName === '')
                    $payerName = null;

                $receiverName = $makerName;
            } else {
                $payeeName = trim((string) ($input['payee_name'] ?? ''));
                if ($payeeName === '')
                    $payeeName = null;

                $classText = trim((string) ($input['class_text'] ?? ''));
                if ($classText === '')
                    $classText = null;

                $payerName = $makerName;

                $receiverName = trim((string) ($input['receiver_name'] ?? ''));
                if ($receiverName === '')
                    $receiverName = null;
            }

            try {
                $pdo->beginTransaction();
                if ($code === null) {
                    $code = next_voucher_code($pdo, $year, $type); // ✅ tách thu/chi
                }


                $stmt = $pdo->prepare("
            INSERT INTO finance_transactions(
                code, type, item_name, source_item_id,
                quantity, unit_price, amount,
                trans_date,
                school_year_id, semester,
                department_id, course_id, class_text,
                payer_name, receiver_name, payee_name,
                description, note,
                status, created_by, created_at, updated_at
            )
            VALUES(
                ?, ?, ?, ?,
                ?, ?, ?,
                ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                'active', ?, NOW(), NOW()
            )
        ");

                $stmt->execute([
                    $code,
                    $type,
                    $item,
                    $sourceItemId,
                    $qty,
                    $unitPrice,
                    $amount,
                    $date,
                    $schoolYearId,
                    $semester,
                    $departmentId,
                    $courseId,
                    $classText,
                    $payerName,
                    $receiverName,
                    $payeeName,
                    $desc,
                    $note,
                    $userId
                ]);

                $newId = (int) $pdo->lastInsertId();
                if ($newId <= 0) {
                    // nếu hệ thống có trigger làm LAST_INSERT_ID lệch thì rơi vào đây
                    throw new Exception('Không lấy được ID giao dịch vừa tạo (lastInsertId = 0)');
                }

                $participantIds = $input['participant_ids'] ?? [];
                sync_participants($pdo, $newId, is_array($participantIds) ? $participantIds : []);

                log_activity(
                    'create',
                    'finance',
                    'Thu-Chi',
                    $newId,
                    ($type === 'income' ? 'Tạo khoản thu: ' : 'Tạo khoản chi: ') . $item . ' - ' . number_format($amount, 0, ',', '.')
                );

                $pdo->commit();

                json_ok(['id' => $newId]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction())
                    $pdo->rollBack();
                json_err('Lỗi: ' . $e->getMessage(), 500);
            }
            break;


        /* =========================================================
           UPDATE
        ========================================================= */
        case 'update':
            require_can('finance', 'update');
            $input = read_json();

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0)
                json_err('Thiếu ID');

            // lấy record cũ
            $stmt = $pdo->prepare("SELECT * FROM finance_transactions WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $old = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$old)
                json_err('Không tìm thấy dữ liệu', 404);

            $type = $old['type'] ?? '';
            if (!in_array($type, ['income', 'expense']))
                json_err('Type dữ liệu không hợp lệ');

            $item = trim((string) ($input['item_name'] ?? ''));
            if ($item === '')
                json_err('Chưa nhập Khoản thu/chi');

            $date = trim((string) ($input['trans_date'] ?? ''));
            if ($date === '')
                json_err('Chưa chọn ngày');

            $schoolYearId = isset($input['school_year_id']) && $input['school_year_id'] !== ''
                ? (int) $input['school_year_id']
                : null;

            $semester = validate_semester_or_null($pdo, $input['semester'] ?? null);


            $desc = trim((string) ($input['description'] ?? ''));
            $note = trim((string) ($input['note'] ?? ''));

            $qty = (int) ($input['quantity'] ?? 1);
            if ($qty <= 0)
                $qty = 1;

            $unitPrice = to_money($input['unit_price'] ?? 0);
            $amount = to_money($input['amount'] ?? 0);

            if ($amount <= 0 && $unitPrice > 0) {
                $amount = $unitPrice * $qty;
            }
            if ($amount <= 0)
                json_err('Số tiền phải > 0');

            $makerName = my_name($pdo, $userId);

            // fields nghiệp vụ
            $departmentId = null;
            $courseId = null;
            $classText = null;
            $payerName = null;
            $receiverName = null;
            $payeeName = null;

            if ($type === 'income') {
                $departmentId = isset($input['department_id']) && $input['department_id'] !== ''
                    ? (int) $input['department_id']
                    : null;

                $courseId = isset($input['course_id']) && $input['course_id'] !== ''
                    ? (int) $input['course_id']
                    : null;

                $classText = trim((string) ($input['class_text'] ?? ''));
                if ($classText === '')
                    $classText = null;

                $payerName = trim((string) ($input['payer_name'] ?? ''));
                if ($payerName === '')
                    $payerName = null;

                $receiverName = $makerName;
            } else {
                $payeeName = trim((string) ($input['payee_name'] ?? ''));
                if ($payeeName === '')
                    $payeeName = null;

                $classText = trim((string) ($input['class_text'] ?? ''));
                if ($classText === '')
                    $classText = null;

                $payerName = $makerName;

                $receiverName = trim((string) ($input['receiver_name'] ?? ''));
                if ($receiverName === '')
                    $receiverName = null;
            }

            $stmt = $pdo->prepare("
                UPDATE finance_transactions
                SET
                    item_name = ?,
                    source_item_id = ?,     -- ✅ NEW
                    quantity = ?,
                    unit_price = ?,
                    amount = ?,
                    trans_date = ?,
                    school_year_id = ?,
                    semester = ?,
                    department_id = ?,
                    course_id = ?,
                    class_text = ?,
                    payer_name = ?,
                    receiver_name = ?,
                    payee_name = ?,
                    description = ?,
                    note = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $sourceItemId = null;
            if ($type === 'expense') {
                $sourceItemId = isset($input['source_item_id']) && $input['source_item_id'] !== ''
                    ? (int) $input['source_item_id']
                    : null;
            }
            $stmt->execute([
                $item,
                $sourceItemId,     // ✅ NEW
                $qty,
                $unitPrice,
                $amount,
                $date,
                $schoolYearId,
                $semester,
                $departmentId,
                $courseId,
                $classText,
                $payerName,
                $receiverName,
                $payeeName,
                $desc,
                $note,
                $id
            ]);
            $participantIds = $input['participant_ids'] ?? [];
            sync_participants($pdo, (int) $id, is_array($participantIds) ? $participantIds : []);



            log_activity(
                'update',
                'finance',
                'Thu-Chi',
                $id,
                ($type === 'income' ? 'Cập nhật khoản thu: ' : 'Cập nhật khoản chi: ') . $item . ' - ' . number_format($amount, 0, ',', '.')
            );

            json_ok(true);
            break;

        /* =========================================================
           DELETE
        ========================================================= */
        case 'delete':
            require_can('finance', 'delete');
            $input = read_json();

            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0)
                json_err('Thiếu ID');

            $stmt = $pdo->prepare("SELECT type, item_name FROM finance_transactions WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("DELETE FROM finance_transactions WHERE id = ?");
            $stmt->execute([$id]);

            log_activity(
                'delete',
                'finance',
                'Thu-Chi',
                $id,
                'Xóa khoản ' . (($row['type'] ?? '') === 'income' ? 'thu: ' : 'chi: ') . ($row['item_name'] ?? '')
            );

            json_ok(true);
            break;

        /* =========================================================
           VOUCHER EXPORT: PDF / XLSX
           GET: ?action=voucher_export&id=123&format=pdf|xlsx&inline=1
        ========================================================= */
        case 'voucher_export':
            require_can('finance', 'view');
            $voucherCfg = get_voucher_settings($pdo);
            $voucherCfg['sign_line3'] = get_sign_name($pdo);

            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0)
                json_err('Thiếu ID');

            $format = strtolower((string) ($_GET['format'] ?? 'pdf'));
            if (!in_array($format, ['pdf', 'xlsx']))
                $format = 'pdf';

            $inline = !empty($_GET['inline']); // inline=1 => mở tab để in
            $download = !empty($_GET['download']); // download=1 => tải

            // lấy data đầy đủ để build phiếu (có khoa/khóa/năm học)
            $stmt = $pdo->prepare("
    SELECT
        t.*,
        d.name AS department_name,
        COALESCE(d.type,'khoa') AS department_type,

        -- ✅ lấy đúng người tạo phiếu
        COALESCE(uc.fullname, uc.username, '') AS created_by_name
    FROM finance_transactions t
    LEFT JOIN departments d ON d.id = t.department_id
    LEFT JOIN users uc ON uc.id = t.created_by
    WHERE t.id = ?
    LIMIT 1
");
            $stmt->execute([$id]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t)
                json_err('Không tìm thấy phiếu', 404);

            // ngày ghi trên phiếu = ngày thu/chi
            $dt = null;
            if (!empty($t['trans_date'])) {
                $dt = DateTime::createFromFormat('Y-m-d', (string) $t['trans_date']);
            }
            if (!$dt)
                $dt = new DateTime();

            $exportDateText = "Ngày " . $dt->format('d') . " tháng " . $dt->format('m') . " năm " . $dt->format('Y');

            // ✅ người lập phiếu = người tạo phiếu (created_by)
            $makerName = trim((string) ($t['created_by_name'] ?? ''));

            // fallback nếu user bị xóa / null
            if ($makerName === '') {
                // nếu bạn muốn fallback theo dữ liệu phiếu:
                // - với income: receiver_name thường chính là người lập lúc tạo
                // - với expense: payer_name thường chính là người lập lúc tạo
                $makerName = trim((string) ($t['receiver_name'] ?? ''));
                if ($makerName === '')
                    $makerName = trim((string) ($t['payer_name'] ?? ''));
                if ($makerName === '')
                    $makerName = my_name($pdo, $userId); // cuối cùng mới fallback người đang đăng nhập
            }

            // ✅ thống nhất: sign_line3 là "tên người ký"
            $signLine3 = trim(get_sign_name($pdo));
            if ($signLine3 === '')
                $signLine3 = $makerName;

            // gắn vào cfg để PDF/XLSX dùng chung
            $voucherCfg['sign_line3'] = $signLine3;


            if ($format === 'xlsx') {
                // hàm này tự header + output + exit
                export_voucher_xlsx($t, $exportDateText, $makerName, $voucherCfg);
            }

            // PDF bytes (hàm đã return bytes)
            $pdfBytes = export_voucher_pdf($t, $exportDateText, $makerName, $inline, $voucherCfg);

            clean_output_buffers();

            $fname = (($t['type'] ?? '') === 'income' ? "phieu_thu_" : "phieu_chi_") . $id . ".pdf";

            header('Content-Type: application/pdf');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');

            // inline ưu tiên hơn download
            if ($inline) {
                header("Content-Disposition: inline; filename=\"$fname\"");
            } else {
                header("Content-Disposition: attachment; filename=\"$fname\"");
            }

            echo $pdfBytes;
            exit;

        case 'unpaid_classes': {
            require_can('finance', 'view');
            $input = read_json();

            $itemName = trim((string) ($input['item_name'] ?? ''));
            if ($itemName === '') {
                json_err('Vui lòng chọn Khoản thu để đối chiếu');
            }

            $schoolYearId = isset($input['school_year_id']) && $input['school_year_id'] !== '' ? (int) $input['school_year_id'] : null;
            $semester = validate_semester_or_null($pdo, $input['semester'] ?? null);
            $deptId = isset($input['department_id']) && $input['department_id'] !== '' ? (int) $input['department_id'] : null;
            $courseId = isset($input['course_id']) && $input['course_id'] !== '' ? (int) $input['course_id'] : null;

            // Tìm các lớp chưa đóng tiền
            $unpaidSql = "
                SELECT 
                    c.id AS class_id,
                    c.name AS class_name,
                    d.name AS department_name,
                    co.name AS course_name
                FROM classes c
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN courses co ON co.id = c.course_id
                WHERE 1
                  AND (? IS NULL OR c.department_id = ?)
                  AND (? IS NULL OR c.course_id = ?)
                  AND c.name NOT IN (
                      SELECT DISTINCT t.class_text
                      FROM finance_transactions t
                      WHERE t.type = 'income'
                        AND t.item_name = ?
                        AND (? IS NULL OR t.school_year_id = ?)
                        AND (? IS NULL OR t.semester = ?)
                        AND t.class_text IS NOT NULL
                  )
                ORDER BY d.name ASC, co.name DESC, c.name ASC
            ";
            $stUnpaid = $pdo->prepare($unpaidSql);
            $stUnpaid->execute([
                $deptId, $deptId,
                $courseId, $courseId,
                $itemName,
                $schoolYearId, $schoolYearId,
                $semester, $semester
            ]);
            $unpaid = $stUnpaid->fetchAll(PDO::FETCH_ASSOC);

            // Tìm các lớp đã đóng tiền
            $paidSql = "
                SELECT 
                    c.id AS class_id,
                    c.name AS class_name,
                    d.name AS department_name,
                    co.name AS course_name,
                    t.code AS voucher_code,
                    t.amount,
                    t.trans_date,
                    t.payer_name
                FROM classes c
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN courses co ON co.id = c.course_id
                JOIN finance_transactions t ON t.class_text = c.name
                WHERE t.type = 'income'
                  AND t.item_name = ?
                  AND (? IS NULL OR t.school_year_id = ?)
                  AND (? IS NULL OR t.semester = ?)
                  AND (? IS NULL OR c.department_id = ?)
                  AND (? IS NULL OR c.course_id = ?)
                ORDER BY t.trans_date DESC, c.name ASC
            ";
            $stPaid = $pdo->prepare($paidSql);
            $stPaid->execute([
                $itemName,
                $schoolYearId, $schoolYearId,
                $semester, $semester,
                $deptId, $deptId,
                $courseId, $courseId
            ]);
            $paid = $stPaid->fetchAll(PDO::FETCH_ASSOC);

            json_ok([
                'unpaid' => $unpaid,
                'paid' => $paid
            ]);
            break;
        }

        case 'export_transactions': {
            require_can('finance', 'view');

            $idsRaw = trim((string) ($_GET['ids'] ?? ''));
            $where = " WHERE 1=1 ";
            $params = [];

            if ($idsRaw !== '') {
                $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
                if (!empty($ids)) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $where .= " AND t.id IN ($in) ";
                    $params = array_values($ids);
                }
            } else {
                // Lọc theo filter
                $type = $_GET['type'] ?? 'all';
                $deptId = trim((string) ($_GET['department_id'] ?? ''));
                $courseId = trim((string) ($_GET['course_id'] ?? ''));
                $classText = trim((string) ($_GET['class_text'] ?? ''));
                $from = trim((string) ($_GET['from'] ?? ''));
                $to = trim((string) ($_GET['to'] ?? ''));
                $q = trim((string) ($_GET['q'] ?? ''));

                if (in_array($type, ['income', 'expense'])) {
                    $where .= " AND t.type = ? ";
                    $params[] = $type;
                }
                if ($deptId !== '') {
                    $where .= " AND t.department_id = ? ";
                    $params[] = (int) $deptId;
                }
                if ($courseId !== '') {
                    $where .= " AND t.course_id = ? ";
                    $params[] = (int) $courseId;
                }
                if ($classText !== '') {
                    $where .= " AND t.class_text LIKE ? ";
                    $params[] = '%' . $classText . '%';
                }
                if ($from !== '') {
                    $where .= " AND t.trans_date >= ? ";
                    $params[] = $from;
                }
                if ($to !== '') {
                    $where .= " AND t.trans_date <= ? ";
                    $params[] = $to;
                }
                if ($q !== '') {
                    $where .= " AND (
                        t.item_name LIKE ?
                        OR t.payer_name LIKE ?
                        OR t.receiver_name LIKE ?
                        OR t.payee_name LIKE ?
                        OR t.note LIKE ?
                        OR t.description LIKE ?
                        OR t.code LIKE ?
                    ) ";
                    $like = '%' . $q . '%';
                    array_push($params, $like, $like, $like, $like, $like, $like, $like);
                }
            }

            // Lấy dữ liệu
            $sql = "
                SELECT
                    t.id,
                    t.code,
                    t.type,
                    t.item_name,
                    t.amount,
                    t.trans_date,
                    sy.year_label AS school_year_label,
                    sem.label AS semester_label,
                    d.name AS department_name,
                    t.class_text,
                    t.payer_name,
                    t.receiver_name,
                    t.payee_name,
                    t.description,
                    t.note
                FROM finance_transactions t
                LEFT JOIN departments d ON d.id = t.department_id
                LEFT JOIN school_years sy ON sy.id = t.school_year_id
                LEFT JOIN semesters sem ON sem.code = t.semester
                $where
                ORDER BY t.trans_date DESC, t.id DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Tạo cấu trúc dữ liệu cho Excel
            $data = [
                [
                    '<b>STT</b>',
                    '<b>Số phiếu</b>',
                    '<b>Loại</b>',
                    '<b>Học kỳ • Năm học</b>',
                    '<b>Nội dung</b>',
                    '<b>Số tiền (đ)</b>',
                    '<b>Đơn vị / Người được chi</b>',
                    '<b>Lớp / Chức vụ</b>',
                    '<b>Người nộp / Người duyệt</b>',
                    '<b>Người nhận / Chức vụ duyệt</b>',
                    '<b>Mô tả</b>',
                    '<b>Ghi chú</b>',
                    '<b>Ngày giao dịch</b>'
                ]
            ];

            foreach ($rows as $i => $r) {
                $isIncome = ($r['type'] === 'income');
                $typeText = $isIncome ? 'Thu' : 'Chi';
                
                $hkText = ($r['semester_label'] || $r['school_year_label']) 
                    ? ($r['semester_label'] . ' • ' . $r['school_year_label'])
                    : '--';
                
                if ($isIncome) {
                    $deptText = $r['department_name'] ?: '--';
                    $clsText = $r['class_text'] ?: '--';
                    $payerText = $r['payer_name'] ?: '--';
                    $receiverText = $r['receiver_name'] ?: '--';
                } else {
                    $deptText = $r['payee_name'] ?: '--';
                    $clsText = $r['class_text'] ?: '--';
                    $payerText = $r['payer_name'] ?: '--';
                    $receiverText = $r['receiver_name'] ?: '--';
                }

                $data[] = [
                    $i + 1,
                    $r['code'] ?: '--',
                    $typeText,
                    $hkText,
                    $r['item_name'] ?: '--',
                    (float) $r['amount'],
                    $deptText,
                    $clsText,
                    $payerText,
                    $receiverText,
                    $r['description'] ?: '--',
                    $r['note'] ?: '--',
                    $r['trans_date'] ? date('d/m/Y', strtotime($r['trans_date'])) : '--'
                ];
            }

            clean_output_buffers();

            $filename = "Danh_Sach_Thu_Chi_" . date('Ymd_His') . ".xlsx";

            $xlsx = SimpleXLSXGen::fromArray($data);
            $xlsx->downloadAs($filename);
            exit;
        }

        case 'export_unpaid_classes': {
            require_can('finance', 'view');

            $itemName = trim((string) ($_GET['item_name'] ?? ''));
            if ($itemName === '') {
                json_err('Thiếu tên khoản thu');
            }

            $schoolYearId = isset($_GET['school_year_id']) && $_GET['school_year_id'] !== '' ? (int) $_GET['school_year_id'] : null;
            $semester = validate_semester_or_null($pdo, $_GET['semester'] ?? null);
            $deptId = isset($_GET['department_id']) && $_GET['department_id'] !== '' ? (int) $_GET['department_id'] : null;
            $courseId = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int) $_GET['course_id'] : null;

            // Tìm các lớp chưa đóng tiền
            $unpaidSql = "
                SELECT 
                    c.name AS class_name,
                    d.name AS department_name,
                    co.name AS course_name
                FROM classes c
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN courses co ON co.id = c.course_id
                WHERE 1
                  AND (? IS NULL OR c.department_id = ?)
                  AND (? IS NULL OR c.course_id = ?)
                  AND c.name NOT IN (
                      SELECT DISTINCT t.class_text
                      FROM finance_transactions t
                      WHERE t.type = 'income'
                        AND t.item_name = ?
                        AND (? IS NULL OR t.school_year_id = ?)
                        AND (? IS NULL OR t.semester = ?)
                        AND t.class_text IS NOT NULL
                  )
                ORDER BY d.name ASC, co.name DESC, c.name ASC
            ";
            $stUnpaid = $pdo->prepare($unpaidSql);
            $stUnpaid->execute([
                $deptId, $deptId,
                $courseId, $courseId,
                $itemName,
                $schoolYearId, $schoolYearId,
                $semester, $semester
            ]);
            $rows = $stUnpaid->fetchAll(PDO::FETCH_ASSOC);

            // Tạo cấu trúc data cho Excel
            $data = [
                [
                    '<b>STT</b>', 
                    '<b>Tên lớp</b>', 
                    '<b>Khoa / Phòng</b>', 
                    '<b>Khóa</b>'
                ]
            ];

            foreach ($rows as $i => $r) {
                $data[] = [
                    $i + 1,
                    $r['class_name'],
                    $r['department_name'] ?: '--',
                    $r['course_name'] ?: '--'
                ];
            }

            clean_output_buffers();
            
            $filename = "Lop_Chua_Dong_Tien_" . preg_replace('/[^a-zA-Z0-9]/', '_', $itemName) . ".xlsx";
            
            $xlsx = SimpleXLSXGen::fromArray($data);
            $xlsx->downloadAs($filename);
            exit;
        }

        case 'export_paid_classes': {
            require_can('finance', 'view');

            $itemName = trim((string) ($_GET['item_name'] ?? ''));
            if ($itemName === '') {
                json_err('Thiếu tên khoản thu');
            }

            $schoolYearId = isset($_GET['school_year_id']) && $_GET['school_year_id'] !== '' ? (int) $_GET['school_year_id'] : null;
            $semester = validate_semester_or_null($pdo, $_GET['semester'] ?? null);
            $deptId = isset($_GET['department_id']) && $_GET['department_id'] !== '' ? (int) $_GET['department_id'] : null;
            $courseId = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int) $_GET['course_id'] : null;

            // Tìm các lớp đã đóng tiền
            $paidSql = "
                SELECT 
                    c.name AS class_name,
                    d.name AS department_name,
                    co.name AS course_name,
                    t.code AS voucher_code,
                    t.amount,
                    t.trans_date,
                    t.payer_name
                FROM classes c
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN courses co ON co.id = c.course_id
                JOIN finance_transactions t ON t.class_text = c.name
                WHERE t.type = 'income'
                  AND t.item_name = ?
                  AND (? IS NULL OR t.school_year_id = ?)
                  AND (? IS NULL OR t.semester = ?)
                  AND (? IS NULL OR c.department_id = ?)
                  AND (? IS NULL OR c.course_id = ?)
                ORDER BY t.trans_date DESC, c.name ASC
            ";
            $stPaid = $pdo->prepare($paidSql);
            $stPaid->execute([
                $itemName,
                $schoolYearId, $schoolYearId,
                $semester, $semester,
                $deptId, $deptId,
                $courseId, $courseId
            ]);
            $rows = $stPaid->fetchAll(PDO::FETCH_ASSOC);

            // Tạo cấu trúc data cho Excel
            $data = [
                [
                    '<b>STT</b>', 
                    '<b>Tên lớp</b>', 
                    '<b>Khoa / Phòng</b>', 
                    '<b>Khóa</b>',
                    '<b>Người nộp</b>',
                    '<b>Số tiền đã đóng</b>',
                    '<b>Số phiếu</b>',
                    '<b>Ngày nộp</b>'
                ]
            ];

            foreach ($rows as $i => $r) {
                $data[] = [
                    $i + 1,
                    $r['class_name'],
                    $r['department_name'] ?: '--',
                    $r['course_name'] ?: '--',
                    $r['payer_name'] ?: '--',
                    $r['amount'],
                    $r['voucher_code'] ?: '--',
                    $r['trans_date'] ? date('d/m/Y', strtotime($r['trans_date'])) : '--'
                ];
            }

            clean_output_buffers();
            
            $filename = "Lop_Da_Dong_Tien_" . preg_replace('/[^a-zA-Z0-9]/', '_', $itemName) . ".xlsx";
            
            $xlsx = SimpleXLSXGen::fromArray($data);
            $xlsx->downloadAs($filename);
            exit;
        }

        case 'export_unpaid_classes_summary': {
            require_can('finance', 'view');

            $schoolYearId = isset($_GET['school_year_id']) && $_GET['school_year_id'] !== '' ? (int) $_GET['school_year_id'] : null;
            $semester = validate_semester_or_null($pdo, $_GET['semester'] ?? null);
            $deptId = isset($_GET['department_id']) && $_GET['department_id'] !== '' ? (int) $_GET['department_id'] : null;
            $courseId = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int) $_GET['course_id'] : null;

            // Truy vấn chéo tìm các lớp chưa đóng tiền của tất cả các khoản thu
            $summarySql = "
                SELECT 
                    items.item_name,
                    items.school_year_id,
                    items.semester,
                    items.school_year_label,
                    items.semester_label,
                    c.name AS class_name,
                    d.name AS department_name,
                    co.name AS course_name
                FROM (
                    SELECT DISTINCT 
                        t.item_name, 
                        t.school_year_id,
                        t.semester,
                        sy.year_label AS school_year_label,
                        se.label AS semester_label
                    FROM finance_transactions t
                    LEFT JOIN school_years sy ON sy.id = t.school_year_id
                    LEFT JOIN semesters se ON se.code = t.semester
                    WHERE t.type = 'income'
                      AND (? IS NULL OR t.school_year_id = ?)
                      AND (? IS NULL OR t.semester = ?)
                ) items
                CROSS JOIN classes c
                LEFT JOIN departments d ON d.id = c.department_id
                LEFT JOIN courses co ON co.id = c.course_id
                WHERE 1
                  AND (? IS NULL OR c.department_id = ?)
                  AND (? IS NULL OR c.course_id = ?)
                  AND NOT EXISTS (
                      SELECT 1
                      FROM finance_transactions t2
                      WHERE t2.type = 'income'
                        AND t2.item_name = items.item_name
                        AND t2.class_text = c.name
                        AND (items.school_year_id IS NULL OR t2.school_year_id = items.school_year_id)
                        AND (items.semester IS NULL OR t2.semester = items.semester)
                  )
                ORDER BY items.item_name ASC, d.name ASC, co.name DESC, c.name ASC
            ";

            $stmt = $pdo->prepare($summarySql);
            $stmt->execute([
                $schoolYearId, $schoolYearId,
                $semester, $semester,
                $deptId, $deptId,
                $courseId, $courseId
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Tạo cấu trúc dữ liệu cho file Excel
            $data = [
                [
                    '<b>STT</b>', 
                    '<b>Khoản thu chưa đóng</b>',
                    '<b>Tên lớp</b>', 
                    '<b>Khoa / Phòng</b>', 
                    '<b>Khóa</b>',
                    '<b>Học kỳ</b>',
                    '<b>Năm học</b>'
                ]
            ];

            foreach ($rows as $i => $r) {
                $data[] = [
                    $i + 1,
                    $r['item_name'],
                    $r['class_name'],
                    $r['department_name'] ?: '--',
                    $r['course_name'] ?: '--',
                    $r['semester_label'] ?: $r['semester'] ?: '--',
                    $r['school_year_label'] ?: '--'
                ];
            }

            clean_output_buffers();

            $filename = "Tong_Hop_Lop_Chua_Dong_Tien_" . date('Ymd_His') . ".xlsx";

            $xlsx = SimpleXLSXGen::fromArray($data);
            $xlsx->downloadAs($filename);
            exit;
        }

        case 'members_by_class': {
            require_can('finance', 'view');

            $input = read_json();

            $deptId = (int) ($input['department_id'] ?? 0);
            $courseId = (int) ($input['course_id'] ?? 0);
            $classText = trim((string) ($input['class_text'] ?? ''));

            if ($classText === '')
                json_ok(['rows' => []]);

            $where = ["c.name = ?"];
            $params = [$classText];

            if ($deptId > 0) {
                $where[] = "c.department_id = ?";
                $params[] = $deptId;
            }
            if ($courseId > 0) {
                $where[] = "c.course_id = ?";
                $params[] = $courseId;
            }

            $targetType = trim((string) ($input['target_type'] ?? 'tat_ca'));
            if ($targetType === 'doan_vien') {
                $where[] = "LOWER(CAST(m.type AS CHAR)) IN ('member','doanvien','doan_vien','dv','doan-vien','doan vien','đoàn viên','doan')";
            } elseif ($targetType === 'thanh_nien') {
                $where[] = "LOWER(CAST(m.type AS CHAR)) IN ('youth','thanhnien','thanh_nien','tn','thanh-nien','thanh nien','thanh')";
            }

            $sql = "
        SELECT
          m.id,
          m.fullname AS name,
          m.mssv,
          COALESCE(c.name, '') AS class_text,
          CASE 
            WHEN LOWER(CAST(m.type AS CHAR)) IN ('member','doanvien','doan_vien','dv','doan-vien','doan vien','đoàn viên','doan') THEN 'Đoàn viên'
            WHEN LOWER(CAST(m.type AS CHAR)) IN ('youth','thanhnien','thanh_nien','tn','thanh-nien','thanh nien','thanh') THEN 'Thanh niên'
            ELSE 'Khác'
          END AS member_type
        FROM members m
        JOIN classes c ON c.id = m.class_id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY m.fullname
    ";

            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            json_ok(['rows' => $rows]);
        }



        default:
            json_err('Action không hợp lệ', 400);
    }

} catch (Throwable $e) {
    json_err('Lỗi: ' . $e->getMessage(), 500);
}
