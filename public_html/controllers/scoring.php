<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

auth_guard();

/**
 * Nếu export Excel -> sẽ đổi Content-Type later.
 * Còn lại luôn trả JSON.
 */
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim((string) $action);

$IS_EXPORT = ($action === 'export_scoring_summary');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
/* ======================
   JSON HELPERS + FATAL GUARD
====================== */
function json_ok($data = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $msg, int $code = 400, array $extra = []): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// Bắt cả fatal/parse để không "response rỗng"
register_shutdown_function(function () use ($IS_EXPORT) {
    $e = error_get_last();
    if (!$e)
        return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($e['type'], $fatalTypes, true))
        return;

    http_response_code(500);

    // Nếu đang export Excel mà chết, trả text cho dễ debug.
    if ($IS_EXPORT) {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "Fatal error: " . ($e['message'] ?? 'unknown') . "\n"
            . "File: " . ($e['file'] ?? '') . "\n"
            . "Line: " . ($e['line'] ?? 0) . "\n";
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Fatal error: ' . ($e['message'] ?? 'unknown'),
        'file' => $e['file'] ?? '',
        'line' => $e['line'] ?? 0,
    ], JSON_UNESCAPED_UNICODE);
});

/* ======================
   HELPERS
====================== */
function slugify(string $str): string
{
    $str = mb_strtolower($str, 'UTF-8');
    $map = [
        'à' => 'a',
        'á' => 'a',
        'ạ' => 'a',
        'ả' => 'a',
        'ã' => 'a',
        'â' => 'a',
        'ầ' => 'a',
        'ấ' => 'a',
        'ậ' => 'a',
        'ẩ' => 'a',
        'ẫ' => 'a',
        'ă' => 'a',
        'ằ' => 'a',
        'ắ' => 'a',
        'ặ' => 'a',
        'ẳ' => 'a',
        'ẵ' => 'a',
        'è' => 'e',
        'é' => 'e',
        'ẹ' => 'e',
        'ẻ' => 'e',
        'ẽ' => 'e',
        'ê' => 'e',
        'ề' => 'e',
        'ế' => 'e',
        'ệ' => 'e',
        'ể' => 'e',
        'ễ' => 'e',
        'ì' => 'i',
        'í' => 'i',
        'ị' => 'i',
        'ỉ' => 'i',
        'ĩ' => 'i',
        'ò' => 'o',
        'ó' => 'o',
        'ọ' => 'o',
        'ỏ' => 'o',
        'õ' => 'o',
        'ô' => 'o',
        'ồ' => 'o',
        'ố' => 'o',
        'ộ' => 'o',
        'ổ' => 'o',
        'ỗ' => 'o',
        'ơ' => 'o',
        'ờ' => 'o',
        'ớ' => 'o',
        'ợ' => 'o',
        'ở' => 'o',
        'ỡ' => 'o',
        'ù' => 'u',
        'ú' => 'u',
        'ụ' => 'u',
        'ủ' => 'u',
        'ũ' => 'u',
        'ư' => 'u',
        'ừ' => 'u',
        'ứ' => 'u',
        'ự' => 'u',
        'ử' => 'u',
        'ữ' => 'u',
        'ỳ' => 'y',
        'ý' => 'y',
        'ỵ' => 'y',
        'ỷ' => 'y',
        'ỹ' => 'y',
        'đ' => 'd'
    ];
    $str = strtr($str, $map);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    $str = trim((string) $str, '-');
    return $str;
}

function romanSemesterFromCode(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code === 'HK1')
        return 'HỌC KỲ I';
    if ($code === 'HK2')
        return 'HỌC KỲ II';
    if ($code === 'HK3')
        return 'HỌC KỲ III';
    return ($code !== '' ? ("HỌC KỲ " . $code) : '');
}

function getSchoolYearLabel(PDO $pdo, int $schoolYearId): string
{
    if ($schoolYearId <= 0)
        return '';
    $st = $pdo->prepare("SELECT year_label FROM school_years WHERE id = ?");
    $st->execute([$schoolYearId]);
    return (string) ($st->fetchColumn() ?? '');
}

function getSemesterLabel(PDO $pdo, string $semesterCode): string
{
    $semesterCode = trim($semesterCode);
    if ($semesterCode === '')
        return '';
    $st = $pdo->prepare("SELECT label FROM semesters WHERE code = ?");
    $st->execute([$semesterCode]);
    return (string) ($st->fetchColumn() ?? '');
}

function parseSchoolYearBounds(PDO $pdo, int $schoolYearId): array
{
    $st = $pdo->prepare("SELECT year_label FROM school_years WHERE id = ?");
    $st->execute([$schoolYearId]);
    $label = (string) ($st->fetchColumn() ?? '');

    preg_match_all('/\d{2,4}/', $label, $m);
    $nums = $m[0] ?? [];
    if (count($nums) < 2)
        return [0, 0];

    $y1 = (int) $nums[0];
    $y2 = (int) $nums[1];
    if ($y1 < 100)
        $y1 += 2000;
    if ($y2 < 100)
        $y2 += 2000;
    return [$y1, $y2];
}

function semesterDateRange(PDO $pdo, int $schoolYearId, string $semesterCode): array
{
    // if ($schoolYearId <= 0 || $semesterCode === '')
    //     return ['', ''];

    // [$startYear, $endYear] = parseSchoolYearBounds($pdo, $schoolYearId);
    // if ($startYear <= 0 || $endYear <= 0)
    //     return ['', ''];

    // $sem = strtoupper(trim($semesterCode));
    // if ($sem === 'HK1')
    //     return ["{$startYear}-08-01", "{$endYear}-07-01"];   // ← NỚI RỘNG ĐẾN THÁNG 7 (bao quát tháng 3)
    // if ($sem === 'HK2')
    //     return ["{$endYear}-01-01", "{$endYear}-08-01"];
    // if ($sem === 'HK3')
    //     return ["{$endYear}-06-01", "{$endYear}-08-01"];
    return ['', ''];
}

/**
 * Kiểm tra cột có tồn tại không (để robust giữa local/server).
 */
function db_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $db = (string) $pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($db === '')
            return false;

        $st = $pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $st->execute([$db, $table, $column]);
        return ((int) $st->fetchColumn() > 0);
    } catch (Throwable $e) {
        return false;
    }
}

/* ======================
   ROUTER
====================== */
try {

    // ===== OPTIONS: school years =====
    if ($action === 'school_year_options') {
        header('Content-Type: application/json; charset=utf-8');

        $rows = $pdo->query("
            SELECT id, year_label
            FROM school_years
            WHERE year_label IS NOT NULL
            ORDER BY year_label DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        json_ok($rows);
    }

    // ===== OPTIONS: semesters =====
    if ($action === 'semester_options') {
        header('Content-Type: application/json; charset=utf-8');

        // Nếu server thiếu cột is_active/sort_order -> fallback an toàn
        $hasIsActive = db_has_column($pdo, 'semesters', 'is_active');
        $hasSort = db_has_column($pdo, 'semesters', 'sort_order');

        $sql = "SELECT code, label FROM semesters";
        $conds = [];
        if ($hasIsActive)
            $conds[] = "is_active = 1";
        if ($conds)
            $sql .= " WHERE " . implode(" AND ", $conds);
        $sql .= $hasSort ? " ORDER BY sort_order, code" : " ORDER BY code";

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // fallback cuối cùng nếu bảng rỗng
        if (!$rows) {
            $rows = [
                ['code' => 'HK1', 'label' => 'Học kỳ I'],
                ['code' => 'HK2', 'label' => 'Học kỳ II'],
            ];
        }

        json_ok($rows);
    }

    // ===== LIST ITEMS FOR SCORING =====
    if ($action === 'scoring_items') {
        header('Content-Type: application/json; charset=utf-8');

        $schoolYearId = (int) ($_GET['school_year'] ?? 0);
        $semesterCode = trim((string) ($_GET['semester'] ?? ''));

        if ($schoolYearId <= 0 || $semesterCode === '') {
            json_err('missing_filters', 400);
        }

        // campaigns
        $sqlCam = "
            SELECT cam.id, cam.title
            FROM campaigns cam
            WHERE (cam.status <> 'hidden' OR cam.status IS NULL)
              AND cam.school_year_id = :sy
              AND TRIM(cam.semester_code) = :sem
            ORDER BY cam.start_date, cam.id
        ";
        $stCam = $pdo->prepare($sqlCam);
        $stCam->execute([':sy' => $schoolYearId, ':sem' => $semesterCode]);
        $campaigns = $stCam->fetchAll(PDO::FETCH_ASSOC);

        // fees: dùng MAX(id) để luôn lấy giao dịch MỚI NHẤT của "Quỹ 1K"
        [$fromDate, $toDateEx] = semesterDateRange($pdo, $schoolYearId, $semesterCode);

        $sqlFee = "
            SELECT 
                MAX(ft.id) as id,
                COALESCE(NULLIF(TRIM(ft.item_name), ''), 'Quỹ 1K') AS title
            FROM finance_transactions ft
            WHERE (ft.status <> 'hidden' OR ft.status IS NULL)
              AND EXISTS (
                SELECT 1 
                FROM finance_transaction_participants ftp 
                WHERE ftp.transaction_id = ft.id
              )
        ";
        $paramsFee = [];
        if (db_has_column($pdo, 'finance_transactions', 'school_year_id')) {
            $sqlFee .= " AND ft.school_year_id = :sy ";
            $paramsFee[':sy'] = $schoolYearId;
        }

        if ($fromDate !== '' && $toDateEx !== '' && db_has_column($pdo, 'finance_transactions', 'created_at')) {
            $sqlFee .= " AND ft.created_at >= :fromDate AND ft.created_at <= :toDateEx ";
            $paramsFee[':fromDate'] = $fromDate;
            $paramsFee[':toDateEx'] = $toDateEx;
        }

        $sqlFee .= " GROUP BY TRIM(ft.item_name) ORDER BY MAX(ft.created_at) DESC, MAX(ft.id) DESC";
        $stFee = $pdo->prepare($sqlFee);
        $stFee->execute($paramsFee);
        $fees = $stFee->fetchAll(PDO::FETCH_ASSOC);

        json_ok([
            'campaigns' => $campaigns,
            'fees' => $fees,
            'year_label' => getSchoolYearLabel($pdo, $schoolYearId),
            'semester_label' => getSemesterLabel($pdo, $semesterCode) ?: romanSemesterFromCode($semesterCode),
        ]);
    }

    // ===== EXPORT EXCEL =====
    if ($action === 'export_scoring_summary') {

        // chỉ autoload khi export (tránh server thiếu vendor làm chết options/scoring_items)
        $vendor = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($vendor)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Missing vendor/autoload.php on server.\n";
            exit;
        }
        require_once $vendor;

        // Use classes after autoload
        $schoolYearId = (int) ($_POST['school_year'] ?? $_GET['school_year'] ?? 0);
        $semesterCode = trim((string) ($_POST['semester'] ?? $_GET['semester'] ?? ''));

        $pointsJson = (string) ($_POST['points_json'] ?? $_GET['points_json'] ?? '');
        $pointsPayload = [];
        if ($pointsJson !== '') {
            $tmp = json_decode($pointsJson, true);
            if (is_array($tmp))
                $pointsPayload = $tmp;
        }

        $totalMaxPoint = 10.0;

        // === LẤY CHÍNH XÁC NHỮNG MỤC BẠN ĐÃ TICK TỪ JS ===
        $campaignIds = array_keys($pointsPayload['campaigns'] ?? []);
        $feeIds = array_keys($pointsPayload['fees'] ?? []);

        // ===== Tính khoảng thời gian học kỳ (bắt buộc cho khoản thu) =====
        [$fromDate, $toDateEx] = semesterDateRange($pdo, $schoolYearId, $semesterCode);

        // campaigns
        $campaigns = [];
        if (!empty($campaignIds)) {
            $inCam = (count($campaignIds) === 1) ? '?' : str_repeat('?,', count($campaignIds) - 1) . '?';
            $sqlCam = "
                SELECT id, title
                FROM campaigns
                WHERE id IN ($inCam)
                ORDER BY start_date, id
            ";
            $stCam = $pdo->prepare($sqlCam);
            $stCam->execute($campaignIds);
            $campaigns = $stCam->fetchAll(PDO::FETCH_ASSOC);
        }

        // fees: chỉ lấy những khoản thu đã tick + tên chính xác
        $feeActivities = [];
        if (!empty($feeIds)) {
            $inFee = (count($feeIds) === 1) ? '?' : str_repeat('?,', count($feeIds) - 1) . '?';
            $sqlFee = "
                SELECT 
                    ft.id,
                    COALESCE(NULLIF(TRIM(ft.item_name), ''), 'Quỹ 1K') AS title
                FROM finance_transactions ft
                WHERE ft.id IN ($inFee)
            ";
            $stFee = $pdo->prepare($sqlFee);
            $stFee->execute($feeIds);
            $feeActivities = $stFee->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!$campaigns && !$feeActivities) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Không có dữ liệu để tổng hợp";
            exit;
        }

        // ===== LỚP =====
        $classes = $pdo->query("
            SELECT
                c.id   AS class_id,
                c.name AS class_name,
                d.name AS dept_name,
                GROUP_CONCAT(
                    DISTINCT COALESCE(u.fullname, u.username)
                    ORDER BY COALESCE(u.fullname, u.username)
                    SEPARATOR ', '
                ) AS gvcn_name,
                COUNT(DISTINCT m.id) AS class_size
            FROM gvcn_classes gc
            JOIN classes c       ON c.id = gc.class_id
            JOIN departments d   ON d.id = c.department_id
            LEFT JOIN users u    ON u.id = gc.user_id
            LEFT JOIN members m  ON m.class_id = c.id
            WHERE d.type = 'khoa'
            GROUP BY c.id, c.name, d.name
            ORDER BY d.name, gvcn_name, c.name
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!$classes) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Không có lớp thuộc Khoa có phân công GVCN";
            exit;
        }

        // ===== MAP phong trào theo lớp =====
        $campaignMap = [];
        if ($campaigns) {
            $ids = array_map(fn($c) => (int) $c['id'], $campaigns);
            $in = (count($ids) === 1) ? '?' : str_repeat('?,', count($ids) - 1) . '?';
            $stR = $pdo->prepare("
                SELECT class_id, campaign_id, joined_quantity
                FROM campaign_class_results
                WHERE campaign_id IN ($in)
            ");
            $stR->execute($ids);
            foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $campaignMap[(int) $r['class_id']][(int) $r['campaign_id']] = (int) $r['joined_quantity'];
            }
        }

        // ===== MAP đóng tiền theo lớp (chỉ những khoản thu đã tick) =====
        $feeMap = [];
        if (!empty($feeIds)) {
            $inFee = (count($feeIds) === 1) ? '?' : str_repeat('?,', count($feeIds) - 1) . '?';
            $sqlFeeCounts = "
                SELECT
                    m.class_id,
                    ftp.transaction_id,
                    COUNT(DISTINCT ftp.member_id) AS paid_count
                FROM finance_transaction_participants ftp
                JOIN members m ON m.id = ftp.member_id
                JOIN finance_transactions ft ON ft.id = ftp.transaction_id
                WHERE ft.id IN ($inFee)
                  AND (ft.status <> 'hidden' OR ft.status IS NULL)
            ";

            $paramsFeeCounts = $feeIds;

            if (db_has_column($pdo, 'finance_transactions', 'school_year_id')) {
                $sqlFeeCounts .= " AND ft.school_year_id = ?";
                $paramsFeeCounts[] = $schoolYearId;
            }

            if ($fromDate !== '' && $toDateEx !== '' && db_has_column($pdo, 'finance_transactions', 'created_at')) {
                $sqlFeeCounts .= " AND ft.created_at >= ? AND ft.created_at < ?";
                $paramsFeeCounts[] = $fromDate;
                $paramsFeeCounts[] = $toDateEx;
            }

            $sqlFeeCounts .= " GROUP BY m.class_id, ftp.transaction_id ";

            $stFC = $pdo->prepare($sqlFeeCounts);
            $stFC->execute($paramsFeeCounts);

            foreach ($stFC->fetchAll(PDO::FETCH_ASSOC) as $fc) {
                $feeMap[(int) $fc['class_id']][(int) $fc['transaction_id']] = (int) $fc['paid_count'];
            }
        }

        // ===== POINT MAP từ payload =====
        $campaignPointMap = $pointsPayload['campaigns'] ?? [];
        $feePointMap = $pointsPayload['fees'] ?? [];

        $getPoint = function (string $type, int $id) use ($campaignPointMap, $feePointMap): float {
            if ($type === 'campaign') {
                $v = $campaignPointMap[(string) $id] ?? $campaignPointMap[$id] ?? 0;
                return is_numeric($v) ? (float) $v : 0.0;
            }
            if ($type === 'fee') {
                $v = $feePointMap[(string) $id] ?? $feePointMap[$id] ?? 0;
                return is_numeric($v) ? (float) $v : 0.0;
            }
            return 0.0;
        };

        // =========================
        // EXCEL (1 cột/mục)
        // =========================


        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Tổng hợp phong trào');

        // Default font (tránh lỗi font lạ trên server)
        $spreadsheet->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(12);

        $titleRow = 6;
        $headerRow1 = 7;
        $headerRow2 = 8;
        $rowNum = 9;

        $COL_A = 1;
        $COL_B = 2;
        $COL_C = 3;
        $COL_D = 4;
        $COL_E = 5;
        $colIdx = 6; // F

        $feeColIndexById = [];
        foreach ($feeActivities as $tx) {
            $feeColIndexById[(int) $tx['id']] = $colIdx++;
        }

        $campColIndexById = [];
        foreach ($campaigns as $cam) {
            $campColIndexById[(int) $cam['id']] = $colIdx++;
        }

        $totalColIdx = $colIdx++;
        $rateColIdx = $colIdx++;
        $noteColIdx = $colIdx++;

        $lastColIdx = $noteColIdx;
        $lastColLetter = Coordinate::stringFromColumnIndex($lastColIdx);

        $yearLabel = getSchoolYearLabel($pdo, $schoolYearId);
        $semText = romanSemesterFromCode($semesterCode);

        $line1 = trim('TỔNG HỢP PHONG TRÀO ' . $semText);
        $line2 = $yearLabel !== '' ? ('NĂM HỌC ' . $yearLabel) : '';
        $titleText = trim($line1 . "\n" . $line2);

        // =========================
// HEADER (Dòng 1 -> 4) KHÔNG VIỀN
// =========================
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
        $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";
        $place = "Quận 8";
        $dateLine = $place . ", ngày " . date('j') . " tháng " . date('n') . " năm " . date('Y');

        // Khối trái A..F
        $leftEndColIdx = min(6, $lastColIdx);
        $leftEndLetter = Coordinate::stringFromColumnIndex($leftEndColIdx);

        // Khối phải = 6 cột cuối (tự co giãn theo số cột thực tế)
        $rightStartColIdx = max($lastColIdx - 3, $leftEndColIdx + 1);
        $rightStartLetter = Coordinate::stringFromColumnIndex($rightStartColIdx);

        // A1:F4 (merge) - KHÔNG VIỀN
        $sheet->setCellValue("A1", $orgLeft);
        $sheet->mergeCells("A1:{$leftEndLetter}4");
        $sheet->getStyle("A1:{$leftEndLetter}4")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        // Khối phải (merge) - KHÔNG VIỀN
        $sheet->setCellValue("{$rightStartLetter}1", $orgRight);
        $sheet->mergeCells("{$rightStartLetter}1:{$lastColLetter}3");
        $sheet->getStyle("{$rightStartLetter}1:{$lastColLetter}3")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Dòng 4 bên phải (italic, right) - KHÔNG VIỀN
        $sheet->setCellValue("{$rightStartLetter}4", $dateLine);
        $sheet->mergeCells("{$rightStartLetter}4:{$lastColLetter}4");
        $sheet->getStyle("{$rightStartLetter}4:{$lastColLetter}4")->applyFromArray([
            'font' => ['italic' => true, 'size' => 12],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_RIGHT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Row heights theo mẫu
        $sheet->getRowDimension(1)->setRowHeight(20.5);
        $sheet->getRowDimension(2)->setRowHeight(15.75);
        $sheet->getRowDimension(3)->setRowHeight(15.75);
        $sheet->getRowDimension(4)->setRowHeight(32.25);

        // =========================
// TITLE (Dòng 5 -> 6) như cũ
// =========================
        $sheet->setCellValue("A5", $line1);
        $sheet->mergeCells("A5:{$lastColLetter}5");
        $sheet->getStyle("A5:{$lastColLetter}5")->applyFromArray([
            'font' => ['bold' => true, 'size' => 18],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->setCellValue("A6", $line2);
        $sheet->mergeCells("A6:{$lastColLetter}6");
        $sheet->getStyle("A6:{$lastColLetter}6")->applyFromArray([
            'font' => ['bold' => true, 'size' => 15],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getRowDimension(5)->setRowHeight(33.0);
        $sheet->getRowDimension(6)->setRowHeight(28.5);


        // Header cố định A..E
        $sheet->setCellValueByColumnAndRow($COL_A, $headerRow1, 'TT');
        $sheet->setCellValueByColumnAndRow($COL_B, $headerRow1, 'TT');
        $sheet->setCellValueByColumnAndRow($COL_C, $headerRow1, 'TT');
        $sheet->setCellValueByColumnAndRow($COL_D, $headerRow1, 'HỌ TÊN GVCN/CVHT');
        $sheet->setCellValueByColumnAndRow($COL_E, $headerRow1, 'TÊN LỚP');

        foreach ([$COL_A, $COL_B, $COL_C, $COL_D, $COL_E] as $cc) {
            $a = Coordinate::stringFromColumnIndex($cc) . $headerRow1;
            $b = Coordinate::stringFromColumnIndex($cc) . $headerRow2;
            $sheet->mergeCells("$a:$b");
        }

        // Header khoản thu: row7 tên, row8 điểm tối đa
        foreach ($feeActivities as $tx) {
            $txId = (int) $tx['id'];
            $c = $feeColIndexById[$txId];

            $sheet->setCellValueByColumnAndRow($c, $headerRow1, mb_strtoupper((string) $tx['title'], 'UTF-8'));
            $sheet->setCellValueByColumnAndRow($c, $headerRow2, (float) $getPoint('fee', $txId));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($c) . $headerRow2)
                ->getNumberFormat()->setFormatCode('0.00');
        }

        // Header phong trào
        foreach ($campaigns as $cam) {
            $camId = (int) $cam['id'];
            $c = $campColIndexById[$camId];

            $sheet->setCellValueByColumnAndRow($c, $headerRow1, mb_strtoupper((string) $cam['title'], 'UTF-8'));
            $sheet->setCellValueByColumnAndRow($c, $headerRow2, (float) $getPoint('campaign', $camId));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($c) . $headerRow2)
                ->getNumberFormat()->setFormatCode('0.00');
        }

        $sheet->setCellValueByColumnAndRow($totalColIdx, $headerRow1, 'TỔNG CỘNG (ĐIỂM)');
        $sheet->setCellValueByColumnAndRow($rateColIdx, $headerRow1, 'TỈ LỆ 100%');
        $sheet->setCellValueByColumnAndRow($noteColIdx, $headerRow1, 'GHI CHÚ');

        $sheet->setCellValueByColumnAndRow($totalColIdx, $headerRow2, $totalMaxPoint);

        // Rate column: dùng dạng % thật (giá trị 1 = 100%)
        $sheet->setCellValueByColumnAndRow($rateColIdx, $headerRow2, 1);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($rateColIdx) . $headerRow2)
            ->getNumberFormat()->setFormatCode('0%');

        // Note column: không còn % nữa
        $sheet->setCellValueByColumnAndRow($noteColIdx, $headerRow2, '');


        $sheet->getStyle("A{$headerRow1}:{$lastColLetter}{$headerRow2}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true
            ],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
        ]);

        $sheet->getStyle("A{$headerRow2}:{$lastColLetter}{$headerRow2}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FF0000']],
        ]);

        // widths
        $sheet->getColumnDimension('A')->setWidth(4);
        $sheet->getColumnDimension('B')->setWidth(4);
        $sheet->getColumnDimension('C')->setWidth(4);
        $sheet->getColumnDimension('D')->setWidth(30);
        $sheet->getColumnDimension('E')->setWidth(22);

        foreach ($feeColIndexById as $idx) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($idx))->setWidth(18);
        }
        foreach ($campColIndexById as $idx) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($idx))->setWidth(26);
        }

        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($totalColIdx))->setWidth(14);
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($rateColIdx))->setWidth(12);
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($noteColIdx))->setWidth(24);

        $sheet->getRowDimension($headerRow1)->setRowHeight(60);
        $sheet->getRowDimension($headerRow2)->setRowHeight(22);

        // BODY
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
        $currentDept = null;
        $deptIndex = -1;

        $sttClassInDept = 0;
        $sttTeacherGlobal = 0;
        $sttTeacherInDept = 0;

        $i = 0;
        $n = count($classes);

        while ($i < $n) {
            $deptName = (string) $classes[$i]['dept_name'];

            if ($currentDept !== $deptName) {
                $currentDept = $deptName;
                $deptIndex++;

                $sttClassInDept = 0;
                $sttTeacherInDept = 0;

                $label = ($roman[$deptIndex] ?? (string) ($deptIndex + 1)) . ". KHOA " . mb_strtoupper($deptName, 'UTF-8');

                $sheet->setCellValue("A{$rowNum}", $label);
                $sheet->mergeCells("A{$rowNum}:{$lastColLetter}{$rowNum}");
                $sheet->getStyle("A{$rowNum}:{$lastColLetter}{$rowNum}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FF0000']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                ]);

                $rowNum++;
            }

            $teacher = trim((string) $classes[$i]['gvcn_name']);
            $teacherKey = mb_strtolower($teacher, 'UTF-8');

            $groupStartRow = $rowNum;

            $sttTeacherGlobal++;
            $sttTeacherInDept++;

            while ($i < $n) {
                $cls = $classes[$i];

                $dept2 = (string) $cls['dept_name'];
                $teacher2 = trim((string) $cls['gvcn_name']);
                $teacherKey2 = mb_strtolower($teacher2, 'UTF-8');

                if ($dept2 !== $currentDept || $teacherKey2 !== $teacherKey)
                    break;

                $classId = (int) $cls['class_id'];
                $classSize = (int) $cls['class_size'];
                $noteParts = []; // dùng để build "Quỹ 1k 30/40 || Quỹ đoàn 38/40"

                $sttClassInDept++;
                $sheet->setCellValueByColumnAndRow($COL_A, $rowNum, $sttClassInDept);
                $sheet->setCellValueByColumnAndRow($COL_E, $rowNum, (string) $cls['class_name']);

                $rowTotalPoint = 0.0;

                // fees: ghi paid/size
                foreach ($feeActivities as $tx) {
                    $txId = (int) $tx['id'];
                    $paid = (int) ($feeMap[$classId][$txId] ?? 0);
                    $ratioText = ($classSize > 0) ? "{$paid}/{$classSize}" : "0/0";

                    // Ghi chú: ghi đủ số khoản thu
                    $title = trim((string) $tx['title']);
                    if ($title === '')
                        $title = 'Khoản thu';
                    $noteParts[] = $title . ' ' . $ratioText;

                    // Tính điểm và ghi điểm vào cột khoản thu
                    $maxPoint = (float) $getPoint('fee', $txId);
                    $rate = ($classSize > 0) ? ($paid / $classSize) : 0.0;
                    if ($rate > 1)
                        $rate = 1;

                    $earned = round($rate * $maxPoint, 2);

                    $cidx = $feeColIndexById[$txId];
                    $addr = Coordinate::stringFromColumnIndex($cidx) . $rowNum;
                    $sheet->setCellValueByColumnAndRow($cidx, $rowNum, $earned);
                    $sheet->getStyle($addr)->getNumberFormat()->setFormatCode('0.00');

                    $rowTotalPoint += $earned;
                }


                // campaigns: ghi score
                foreach ($campaigns as $cam) {
                    $camId = (int) $cam['id'];
                    $joined = (int) ($campaignMap[$classId][$camId] ?? 0);

                    $maxPoint = (float) $getPoint('campaign', $camId);
                    $rate = ($classSize > 0) ? ($joined / $classSize) : 0.0;
                    if ($rate > 1)
                        $rate = 1;

                    $earned = round($rate * $maxPoint, 2);

                    $cidx = $campColIndexById[$camId];
                    $addr = Coordinate::stringFromColumnIndex($cidx) . $rowNum;
                    $sheet->setCellValueByColumnAndRow($cidx, $rowNum, $earned);
                    $sheet->getStyle($addr)->getNumberFormat()->setFormatCode('0.00');

                    $rowTotalPoint += $earned;
                }


                // Tổng điểm (cột duy nhất về điểm)
                $sheet->setCellValueByColumnAndRow($totalColIdx, $rowNum, round($rowTotalPoint, 2));
                $sheet->getStyle(Coordinate::stringFromColumnIndex($totalColIdx) . $rowNum)
                    ->getNumberFormat()->setFormatCode('0.00');

                // Rate: dạng % thật, ví dụ 0.85 => 85%
                $rateDec = ($totalMaxPoint > 0) ? ($rowTotalPoint / $totalMaxPoint) : 0.0;
                if ($rateDec > 1)
                    $rateDec = 1;

                $rateAddr = Coordinate::stringFromColumnIndex($rateColIdx) . $rowNum;
                $sheet->setCellValueByColumnAndRow($rateColIdx, $rowNum, $rateDec);
                $sheet->getStyle($rateAddr)->getNumberFormat()->setFormatCode('0%');

                // Note: danh sách khoản thu paid/size
                $noteText = implode(' || ', $noteParts);
                $noteAddr = Coordinate::stringFromColumnIndex($noteColIdx) . $rowNum;
                $sheet->setCellValueExplicit($noteAddr, $noteText, DataType::TYPE_STRING);
                $sheet->getStyle($noteAddr)->getAlignment()->setWrapText(true);


                $rowNum++;
                $i++;
            }

            $groupEndRow = $rowNum - 1;

            $sheet->setCellValueByColumnAndRow($COL_B, $groupStartRow, $sttTeacherGlobal);
            $sheet->setCellValueByColumnAndRow($COL_C, $groupStartRow, $sttTeacherInDept);
            $sheet->setCellValueByColumnAndRow($COL_D, $groupStartRow, mb_strtoupper($teacher, 'UTF-8'));

            if ($groupEndRow > $groupStartRow) {
                foreach ([$COL_B, $COL_C, $COL_D] as $cc) {
                    $colLetter = Coordinate::stringFromColumnIndex($cc);
                    $sheet->mergeCells("{$colLetter}{$groupStartRow}:{$colLetter}{$groupEndRow}");
                }
            }

            $bL = Coordinate::stringFromColumnIndex($COL_B);
            $cL = Coordinate::stringFromColumnIndex($COL_C);
            $dL = Coordinate::stringFromColumnIndex($COL_D);

            $sheet->getStyle("{$bL}{$groupStartRow}:{$cL}{$groupEndRow}")
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);

            $sheet->getStyle("{$dL}{$groupStartRow}:{$dL}{$groupEndRow}")
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
        }

        // Style body
        $bodyStartRow = $headerRow2 + 1;
        $bodyEndRow = $rowNum - 1;

        if ($bodyEndRow >= $bodyStartRow) {
            $sheet->getStyle("A{$bodyStartRow}:{$lastColLetter}{$bodyEndRow}")->applyFromArray([
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);

            $rateLetter = Coordinate::stringFromColumnIndex($rateColIdx);
            $noteLetter = Coordinate::stringFromColumnIndex($noteColIdx);

            $sheet->getStyle("{$rateLetter}{$headerRow1}:{$rateLetter}{$bodyEndRow}")
                ->getFont()->getColor()->setRGB('FF0000');
            $sheet->getStyle("{$noteLetter}{$headerRow1}:{$noteLetter}{$bodyEndRow}")
                ->getFont()->getColor()->setRGB('FF0000');
        }

        // Export headers
        $fileName = 'tinh-diem-tong-hop';
        $fileName .= '-' . slugify($yearLabel ?: 'namhoc');
        $fileName .= '-' . slugify($semesterCode ?: 'hocky');
        $fileName .= ".xlsx";

        while (ob_get_level() > 0)
            ob_end_clean();
        header_remove();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    json_err('Unknown action', 400, ['action' => $action]);

} catch (Throwable $e) {
    // Trả JSON lỗi rõ ràng cho JS (không còn HTML)
    json_err($e->getMessage(), 500, [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}
