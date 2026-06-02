<?php
// display_errors controlled centrally in index.php / bootstrap
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/activity_log.php';


use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Shuchkin\SimpleXLSXGen;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Color;

auth_guard();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$action = trim($action);
function parseSchoolYearBounds(PDO $pdo, int $schoolYearId): array
{
    // trả về [startYear, endYear] dạng 2025, 2026
    $st = $pdo->prepare("SELECT year_label FROM school_years WHERE id = ?");
    $st->execute([$schoolYearId]);
    $label = (string) ($st->fetchColumn() ?? '');

    // bắt cả "2025-2026" hoặc "NH25-26"...
    preg_match_all('/\d{2,4}/', $label, $m);
    $nums = $m[0] ?? [];

    if (count($nums) < 2) {
        // fallback: không parse được
        return [0, 0];
    }

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
    if ($schoolYearId <= 0 || $semesterCode === '')
        return ['', ''];
    [$startYear, $endYear] = parseSchoolYearBounds($pdo, $schoolYearId);
    if ($startYear <= 0 || $endYear <= 0)
        return ['', ''];

    $sem = strtoupper(trim($semesterCode));

    switch ($sem) {
        case 'HK1':
            return ["{$startYear}-08-01", "{$endYear}-01-01"];
        case 'HK2':
            return ["{$endYear}-01-01", "{$endYear}-08-01"];
        case 'HK3':
            return ["{$endYear}-06-01", "{$endYear}-08-01"];
        default:
            return ['', ''];
    }
}

if ($action === 'semester_options') {
    header('Content-Type: application/json; charset=utf-8');

    $rows = $pdo->query("
        SELECT code, label
        FROM semesters
        WHERE is_active = 1
        ORDER BY sort_order, code
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'campaign_options') {
    header('Content-Type: application/json; charset=utf-8');

    $schoolYearId = (int) ($_GET['school_year'] ?? 0);
    $semesterCode = trim((string) ($_GET['semester'] ?? ''));

    $sql = "
        SELECT id, title
        FROM campaigns
        WHERE (status <> 'hidden' OR status IS NULL)
    ";
    $params = [];

    if ($schoolYearId > 0) {
        $sql .= " AND school_year_id = :school_year_id";
        $params[':school_year_id'] = $schoolYearId;
    }

    if ($semesterCode !== '') {
        $sql .= " AND TRIM(semester_code) = :semester_code";
        $params[':semester_code'] = $semesterCode;
    }

    $sql .= " ORDER BY start_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'ok' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}





if ($action === 'campaign_class_stats') {
    header('Content-Type: application/json; charset=utf-8');

    $campaignId = (int) ($_GET['campaign_id'] ?? 0);
    if (!$campaignId) {
        echo json_encode(['ok' => false, 'error' => 'Invalid campaign_id']);
        exit;
    }

    try {
        // Lưu ý:
        // - Không cần lọc school_year/semester ở đây nữa vì campaign_id đã xác định phong trào
        // - Quan trọng: :campaign_id chỉ xuất hiện 1 lần -> tránh lỗi HY093

        $sql = "
            SELECT
                c.id   AS class_id,
                c.name AS class_name,

                COUNT(DISTINCT m.id) AS class_size,
                COUNT(DISTINCT r.user_id) AS joined_count,
                COALESCE(MAX(ccr.score), 0) AS score

            FROM classes c
            LEFT JOIN members m
                ON m.class_id = c.id

            JOIN campaigns cam
                ON cam.id = :campaign_id

            LEFT JOIN registrations r
                ON r.user_id = m.user_id
               AND r.campaign_id = cam.id
               AND r.score > 0

            LEFT JOIN campaign_class_results ccr
                ON ccr.class_id = c.id
               AND ccr.campaign_id = cam.id

            GROUP BY c.id, c.name
            HAVING joined_count > 0
            ORDER BY joined_count DESC, c.name
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':campaign_id' => $campaignId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            echo json_encode([
                'ok' => false,
                'empty' => true,
                'message' => 'Chưa có lớp phát sinh từ đoàn viên đã chấm'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $data = [];
        foreach ($rows as $r) {
            $size = (int) $r['class_size'];
            $join = (int) $r['joined_count'];

            $percent = $size > 0 ? round($join * 100 / $size, 1) : 0;

            $data[] = [
                'class_name' => $r['class_name'],
                'ratio' => "$join/$size",
                'percent' => $percent,
                'score' => (float) $r['score']
            ];
        }

        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}


if ($action === 'campaign_dept_stats') {
    header('Content-Type: application/json; charset=utf-8');
    auth_guard();

    $campaignId = (int) ($_GET['campaign_id'] ?? 0);
    if (!$campaignId) {
        echo json_encode(['ok' => false, 'error' => 'Invalid campaign_id']);
        exit;
    }

    try {
        $stmtScore = $pdo->prepare("SELECT score FROM campaigns WHERE id = ?");
        $stmtScore->execute([$campaignId]);
        $campaignScore = (float) ($stmtScore->fetchColumn() ?? 0);

        // :campaign_id chỉ xuất hiện 1 lần
        $sql = "
            SELECT
              d.id AS dept_id,
              d.name AS raw_name,
              d.type AS dept_type,

              COUNT(DISTINCT m.id) AS dept_size,
              COUNT(DISTINCT r.user_id) AS joined_count

            FROM departments d
            LEFT JOIN classes c
              ON c.department_id = d.id
            LEFT JOIN members m
              ON m.class_id = c.id

            JOIN campaigns cam
              ON cam.id = :campaign_id

            LEFT JOIN registrations r
              ON r.user_id = m.user_id
             AND r.campaign_id = cam.id
             AND r.score > 0

            GROUP BY d.id, d.name, d.type
            HAVING joined_count > 0
            ORDER BY joined_count DESC, d.name
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':campaign_id' => $campaignId]);

        $data = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $size = (int) $r['dept_size'];
            $join = (int) $r['joined_count'];

            $percent = $size > 0 ? round($join * 100 / $size, 1) : 0;
            $score = round($percent * $campaignScore / 100, 1);

            if ($r['dept_type'] === 'khoa') {
                $deptName = preg_match('/^Khoa\s+/iu', $r['raw_name']) ? $r['raw_name'] : 'Khoa ' . $r['raw_name'];
            } elseif ($r['dept_type'] === 'phong') {
                $deptName = preg_match('/^Phòng\s+/iu', $r['raw_name']) ? $r['raw_name'] : 'Phòng ' . $r['raw_name'];
            } else {
                $deptName = $r['raw_name'];
            }

            $data[] = [
                'dept_name' => $deptName,
                'ratio' => $size > 0 ? "$join/$size" : "0/0",
                'percent' => $percent,
                'score' => $score
            ];
        }

        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

    exit;
}





function slugify($str)
{
    $str = mb_strtolower($str, 'UTF-8');

    // bỏ dấu tiếng Việt
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

    // bỏ ký tự lạ
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    $str = trim($str, '-');

    return $str;
}
function romanSemesterFromCode(string $code): string
{
    $code = strtoupper(trim($code));
    switch ($code) {
        case 'HK1':
            return 'HỌC KỲ I';
        case 'HK2':
            return 'HỌC KỲ II';
        case 'HK3':
            return 'HỌC KỲ III';
        default:
            return ($code !== '' ? "HỌC KỲ $code" : '');
    }
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

function buildExportTitle(PDO $pdo, int $campaignId, int $schoolYearId, string $semesterCode): array
{
    // lấy tên phong trào từ DB để chắc chắn đúng
    $st = $pdo->prepare("SELECT title FROM campaigns WHERE id = ?");
    $st->execute([$campaignId]);
    $campaignTitle = (string) ($st->fetchColumn() ?? '');

    $yearLabel = getSchoolYearLabel($pdo, $schoolYearId);

    // ưu tiên label trong bảng semesters, fallback về code HK1/HK2...
    $semLabel = getSemesterLabel($pdo, $semesterCode);
    $semText = '';
    if ($semesterCode !== '') {
        // nếu label đã là "Học kỳ 2" thì bạn có thể dùng label;
        // còn nếu bạn muốn luôn roman (I/II) thì dùng romanSemesterFromCode
        $semText = romanSemesterFromCode($semesterCode);
    }

    $line1 = 'THỐNG KÊ PHONG TRÀO ' . mb_strtoupper($campaignTitle, 'UTF-8');
    if ($semText !== '')
        $line1 .= ' ' . $semText;

    $line2 = ($yearLabel !== '') ? ('NĂM HỌC ' . $yearLabel) : '';

    return [$line1, $line2];
}

if ($action === 'school_year_options') {
    header('Content-Type: application/json; charset=utf-8');

    $rows = $pdo->query("
        SELECT id, year_label
        FROM school_years
        WHERE year_label IS NOT NULL
        ORDER BY year_label DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}


if ($action === 'export_campaign_class') {
    auth_guard();
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
    $campaignId = (int) ($_GET['campaign_id'] ?? 0);
    $title = trim((string) ($_GET['title'] ?? ''));
    $schoolYearId = (int) ($_GET['school_year'] ?? 0);


    if (!$campaignId) {
        http_response_code(400);
        exit('Invalid campaign');
    }
    // Lấy info phong trào để làm tiêu đề
    $stmtInfo = $pdo->prepare("
    SELECT 
        cam.title,
        sy.year_label,
        se.label AS semester_label,
        cam.semester_code
    FROM campaigns cam
    LEFT JOIN school_years sy ON sy.id = cam.school_year_id
    LEFT JOIN semesters se ON se.code = cam.semester_code
    WHERE cam.id = :id
");
    $stmtInfo->execute([':id' => $campaignId]);
    $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

    $campaignTitle = $info['title'] ?? $title ?? 'PHONG TRÀO';
    $yearLabel = $info['year_label'] ?? '';
    $semesterLabel = $info['semester_label'] ?? ($info['semester_code'] ?? $semesterCode);

    // =========================
    // QUERY THỐNG KÊ THEO LỚP
    // (CHỈ SV ĐÃ ĐƯỢC CHẤM ĐIỂM)
    // =========================
    $sql = "
SELECT
    c.name AS class_name,
    COUNT(DISTINCT m.id)      AS total,
    COUNT(DISTINCT r.user_id) AS joined,
    ROUND(
        COUNT(DISTINCT r.user_id) * 100.0 /
        NULLIF(COUNT(DISTINCT m.id), 0),
    2) AS percent,
    COALESCE(MAX(ccr.score), 0) AS score
FROM campaigns cam
JOIN classes c
    -- giữ classes độc lập như bạn đang làm (nếu muốn tất cả lớp)
    ON 1=1
LEFT JOIN members m
    ON m.class_id = c.id
LEFT JOIN registrations r
    ON r.user_id = m.user_id
   AND r.campaign_id = cam.id
   AND r.score > 0
LEFT JOIN campaign_class_results ccr
    ON ccr.class_id = c.id
   AND ccr.campaign_id = cam.id
WHERE cam.id = :campaign_id
";

    $params = [':campaign_id' => $campaignId];

    if ($schoolYearId > 0) {
        $sql .= " AND cam.school_year_id = :school_year_id";
        $params[':school_year_id'] = $schoolYearId;
    }

    if ($semesterCode !== '') {
        $sql .= " AND cam.semester_code = :semester_code";
        $params[':semester_code'] = $semesterCode;
    }

    $sql .= "
GROUP BY c.id, c.name
HAVING joined > 0
ORDER BY joined DESC, c.name
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);


    $semesterCode = trim((string) ($_GET['semester'] ?? ''));

    $stmt->execute([
        ':campaign_id' => $campaignId,
        ':school_year_id' => $schoolYearId,
        ':semester_code' => $semesterCode
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =========================
    // DATA XLSX
    // =========================
    $data = [];
    while (ob_get_level() > 0)
        ob_end_clean();
    header_remove();
    // header
    $data[] = [
        'Lớp',
        'Số người tham gia',
        'Tỷ lệ %',
        'Điểm'
    ];

    foreach ($rows as $r) {
        $data[] = [
            $r['class_name'],
            "{$r['joined']}/{$r['total']}",
            $r['percent'] . '%',
            (float) $r['score']      // 👈 ĐIỂM LẤY TỪ DB
        ];
    }

    // =========================
    // TÊN FILE
    // =========================
    $slugTitle = $title ? slugify($title) : 'phong-trao';
    $filename = "thong-ke-lop-phong-trao-{$slugTitle}.xlsx";

    // =========================
    // EXPORT
    // =========================
    [$t1, $t2] = buildExportTitle($pdo, $campaignId, $schoolYearId, $semesterCode);

    // =========================
// EXPORT XLSX (PhpSpreadsheet)
// =========================
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Thong ke');

    // Số cột của bảng (A..D)
    $lastCol = 'D';

    // --- Header 3 dòng ---
    $sheet->setCellValue('A1', 'THỐNG KÊ PHONG TRÀO THEO LỚP');
    $sheet->mergeCells("A1:{$lastCol}1");

    $sheet->setCellValue('A2', mb_strtoupper($campaignTitle, 'UTF-8'));
    $sheet->mergeCells("A2:{$lastCol}2");

    $line3 = trim("Năm học {$yearLabel}  |  {$semesterLabel}");
    $sheet->setCellValue('A3', $line3);
    $sheet->mergeCells("A3:{$lastCol}3");

    // Style header
    $sheet->getStyle("A1:{$lastCol}3")->applyFromArray([
        'font' => ['bold' => true, 'size' => 14],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
    ]);

    // Cho 3 dòng tự giãn chiều cao theo nội dung (Excel sẽ auto khi wrap)
    $sheet->getRowDimension(1)->setRowHeight(-1);
    $sheet->getRowDimension(2)->setRowHeight(-1);
    $sheet->getRowDimension(3)->setRowHeight(-1);

    // --- Dòng tiêu đề cột (bắt đầu từ row 5) ---
    $headerRow = 5;
    $sheet->setCellValue("A{$headerRow}", 'Lớp');
    $sheet->setCellValue("B{$headerRow}", 'Số người tham gia');
    $sheet->setCellValue("C{$headerRow}", 'Tỷ lệ %');
    $sheet->setCellValue("D{$headerRow}", 'Điểm');

    $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E5E7EB']
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
        ],
    ]);

    // --- Data ---
    $rowNum = $headerRow + 1;
    foreach ($rows as $r) {
        $joined = (int) ($r['joined'] ?? 0);
        $total = (int) ($r['total'] ?? 0);

        $sheet->setCellValue("A{$rowNum}", $r['class_name'] ?? '');
        $sheet->setCellValue("B{$rowNum}", "{$joined}/{$total}");
        $sheet->setCellValue("C{$rowNum}", (float) ($r['percent'] ?? 0) . '%');
        $sheet->setCellValue("D{$rowNum}", (float) ($r['score'] ?? 0));

        $rowNum++;
    }

    $lastRow = $rowNum - 1;

    // Border + wrap toàn bảng
    $sheet->getStyle("A{$headerRow}:{$lastCol}{$lastRow}")->applyFromArray([
        'alignment' => [
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
        ],
    ]);

    // Canh lề dữ liệu
    $sheet->getStyle("A" . ($headerRow + 1) . ":A{$lastRow}")
        ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

    $sheet->getStyle("B" . ($headerRow + 1) . ":C{$lastRow}")
        ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $sheet->getStyle("D" . ($headerRow + 1) . ":D{$lastRow}")
        ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

    // AutoSize cột để “vừa chữ”
    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }



    // Tên file
    $slugTitle = $title ? slugify($title) : slugify($campaignTitle);
    $filename = "thong-ke-lop-phong-trao-{$slugTitle}.xlsx";

    // Xuất file (nhớ dọn output buffer như bạn đã fix trước đó)
    while (ob_get_level() > 0)
        ob_end_clean();
    header_remove();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;


}

function formatDeptName(string $name, string $type): string
{
    $name = trim($name);

    if ($type === 'phong') {
        // ❗ chỉ xóa "Khoa " nếu KHÔNG phải "Khoa học"
        $name = preg_replace('/^Khoa\s+(?!học)/iu', '', $name);

        if (!preg_match('/^Phòng\s+/iu', $name)) {
            return 'Phòng ' . $name;
        }
        return $name;
    }

    if ($type === 'khoa') {
        // ❗ chỉ xóa "Phòng " nếu là prefix thật
        $name = preg_replace('/^Phòng\s+/iu', '', $name);

        if (!preg_match('/^Khoa\s+/iu', $name)) {
            return 'Khoa ' . $name;
        }
        return $name;
    }

    return $name;
}



if ($action === 'export_campaign_dept') {
    auth_guard();
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
    $campaignId = (int) ($_GET['campaign_id'] ?? 0);
    $title = trim((string) ($_GET['title'] ?? ''));
    $schoolYearId = (int) ($_GET['school_year'] ?? 0);
    $semesterCode = trim((string) ($_GET['semester'] ?? ''));


    if (!$campaignId) {
        http_response_code(400);
        exit('Invalid campaign');
    }

    // =========================
    // LẤY ĐIỂM PHONG TRÀO
    // =========================
    $stmtScore = $pdo->prepare("SELECT score FROM campaigns WHERE id = ?");
    $stmtScore->execute([$campaignId]);
    $campaignScore = (float) ($stmtScore->fetchColumn() ?? 0);

    // Lấy info phong trào để làm tiêu đề
    $stmtInfo = $pdo->prepare("
    SELECT 
        cam.title,
        sy.year_label,
        se.label AS semester_label,
        cam.semester_code
    FROM campaigns cam
    LEFT JOIN school_years sy ON sy.id = cam.school_year_id
    LEFT JOIN semesters se ON se.code = cam.semester_code
    WHERE cam.id = :id
");
    $stmtInfo->execute([':id' => $campaignId]);
    $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

    $campaignTitle = $info['title'] ?? $title ?? 'PHONG TRÀO';
    $yearLabel = $info['year_label'] ?? '';
    $semesterLabel = $info['semester_label'] ?? ($info['semester_code'] ?? $semesterCode);

    // =========================
    // QUERY THỐNG KÊ KHOA / PHÒNG
    // =========================
    $sql = "
SELECT 
  d.name AS dept_name,
  d.type AS dept_type,

  COUNT(DISTINCT r.user_id) AS joined,
  COUNT(DISTINCT m.id) AS total

FROM campaigns cam
JOIN departments d ON 1=1
LEFT JOIN classes c
  ON c.department_id = d.id
LEFT JOIN members m
  ON m.class_id = c.id
LEFT JOIN registrations r
  ON r.user_id = m.user_id
 AND r.campaign_id = cam.id
 AND r.score > 0

WHERE cam.id = :campaign_id
";

    $params = [':campaign_id' => $campaignId];

    if ($schoolYearId > 0) {
        $sql .= " AND cam.school_year_id = :school_year_id";
        $params[':school_year_id'] = $schoolYearId;
    }

    if ($semesterCode !== '') {
        $sql .= " AND cam.semester_code = :semester_code";
        $params[':semester_code'] = $semesterCode;
    }

    $sql .= "
GROUP BY d.id, d.name, d.type
HAVING joined > 0
ORDER BY joined DESC, d.name
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);


    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =========================
    // DATA XLSX
    // =========================
    $data = [];
    $data[] = ['Khoa / Phòng', 'Số người tham gia', 'Tỷ lệ %', 'Điểm'];

    while (ob_get_level() > 0)
        ob_end_clean();
    header_remove();

    foreach ($rows as $r) {
        $deptLabel = formatDeptName($r['dept_name'], $r['dept_type']);

        $joined = (int) $r['joined'];
        $total = (int) $r['total'];

        $percent = $total > 0
            ? round($joined * 100 / $total, 2)
            : 0;

        $score = round($percent * $campaignScore / 100, 1);

        $data[] = [
            $deptLabel,
            "{$joined}/{$total}",
            "{$percent}%",
            $score
        ];
    }

    // =========================
    // TÊN FILE
    // =========================
    $slugTitle = $title ? slugify($title) : 'phong-trao';
    $filename = "thong-ke-khoa-phong-trao-{$slugTitle}.xlsx";

    // =========================
    // EXPORT
    // =========================
    [$t1, $t2] = buildExportTitle($pdo, $campaignId, $schoolYearId, $semesterCode);

    // =========================
// EXPORT XLSX (PhpSpreadsheet)
// =========================
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Thong ke');

    // Số cột của bảng (A..D)
    $lastCol = 'D';

    // --- Header 3 dòng ---
    $sheet->setCellValue('A1', 'THỐNG KÊ PHONG TRÀO THEO KHOA/PHÒNG');
    $sheet->mergeCells("A1:{$lastCol}1");

    $sheet->setCellValue('A2', mb_strtoupper($campaignTitle, 'UTF-8'));
    $sheet->mergeCells("A2:{$lastCol}2");

    $line3 = trim("Năm học {$yearLabel}  | {$semesterLabel}");
    $sheet->setCellValue('A3', $line3);
    $sheet->mergeCells("A3:{$lastCol}3");

    // Style header
    $sheet->getStyle("A1:{$lastCol}3")->applyFromArray([
        'font' => ['bold' => true, 'size' => 14],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
    ]);

    // Cho 3 dòng tự giãn chiều cao theo nội dung (Excel sẽ auto khi wrap)
    $sheet->getRowDimension(1)->setRowHeight(-1);
    $sheet->getRowDimension(2)->setRowHeight(-1);
    $sheet->getRowDimension(3)->setRowHeight(-1);

    // --- Dòng tiêu đề cột (bắt đầu từ row 5) ---
    $headerRow = 5;
    $sheet->setCellValue("A{$headerRow}", 'Khoa / Phòng');
    $sheet->setCellValue("B{$headerRow}", 'Số người tham gia');
    $sheet->setCellValue("C{$headerRow}", 'Tỷ lệ %');
    $sheet->setCellValue("D{$headerRow}", 'Điểm');

    $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E5E7EB']
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
        ],
    ]);

    // --- Data ---
    $rowNum = $headerRow + 1;

    foreach ($rows as $r) {
        $deptLabel = formatDeptName($r['dept_name'], $r['dept_type']);

        $joined = (int) ($r['joined'] ?? 0);
        $total = (int) ($r['total'] ?? 0);

        $rate = ($total > 0) ? ($joined / $total) : 0.0;     // 0..1
        $score = round($rate * $campaignScore, 1);            // giống UI

        $sheet->setCellValue("A{$rowNum}", $deptLabel);
        $sheet->setCellValue("B{$rowNum}", "{$joined}/{$total}");
        $sheet->setCellValue("C{$rowNum}", $rate);            // số, không cộng '%'
        $sheet->setCellValue("D{$rowNum}", $score);           // số

        $rowNum++;
    }

    $lastRow = $rowNum - 1;

    // Format phần trăm + điểm giống UI
    if ($lastRow >= $headerRow + 1) {
        $sheet->getStyle("C" . ($headerRow + 1) . ":C{$lastRow}")
            ->getNumberFormat()->setFormatCode('0.0%');

        $sheet->getStyle("D" . ($headerRow + 1) . ":D{$lastRow}")
            ->getNumberFormat()->setFormatCode('0.0');
    }


    // Border + wrap toàn bảng
    $sheet->getStyle("A{$headerRow}:{$lastCol}{$lastRow}")->applyFromArray([
        'alignment' => [
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            'wrapText' => true
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
        ],
    ]);

    // Canh lề dữ liệu
    $sheet->getStyle("A" . ($headerRow + 1) . ":A{$lastRow}")
        ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

    $sheet->getStyle("B" . ($headerRow + 1) . ":C{$lastRow}")
        ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $sheet->getStyle("D" . ($headerRow + 1) . ":D{$lastRow}")
        ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

    // AutoSize cột để “vừa chữ”
    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }



    // Tên file
    $slugTitle = $title ? slugify($title) : slugify($campaignTitle);
    $filename = "thong-ke-Khoa-phong-trao-{$slugTitle}.xlsx";

    // Xuất file (nhớ dọn output buffer như bạn đã fix trước đó)
    while (ob_get_level() > 0)
        ob_end_clean();
    header_remove();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

}
// ======================
// EXPORT EXCEL TỔNG HỢP (TẤT CẢ PHONG TRÀO)
// Gồm 2 sheet: Theo lớp + Theo khoa/phòng
// Có cột Năm học / Học kỳ / Phong trào để phân loại
// ======================
if ($action === 'export_campaign_summary') {
    auth_guard();

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    $schoolYearId = (int) ($_GET['school_year'] ?? 0);
    $semesterCode = trim((string) ($_GET['semester'] ?? ''));

    $yearLabel = $schoolYearId > 0 ? getSchoolYearLabel($pdo, $schoolYearId) : '';
    // ưu tiên label trong DB, fallback roman HK1/HK2...
    $semesterLabel = '';
    if ($semesterCode !== '') {
        $semesterLabel = getSemesterLabel($pdo, $semesterCode);
        if ($semesterLabel === '')
            $semesterLabel = romanSemesterFromCode($semesterCode);
    }

    // 1) Lấy danh sách phong trào theo filter
    $sqlCam = "
        SELECT cam.id, cam.title, cam.score, cam.school_year_id, cam.semester_code, cam.start_date,
               sy.year_label
        FROM campaigns cam
        LEFT JOIN school_years sy ON sy.id = cam.school_year_id
        WHERE (cam.status <> 'hidden' OR cam.status IS NULL)
    ";
    $paramsCam = [];

    if ($schoolYearId > 0) {
        $sqlCam .= " AND cam.school_year_id = :school_year_id ";
        $paramsCam[':school_year_id'] = $schoolYearId;
    }
    if ($semesterCode !== '') {
        $sqlCam .= " AND TRIM(cam.semester_code) = :semester_code ";
        $paramsCam[':semester_code'] = $semesterCode;
    }

    $sqlCam .= " ORDER BY cam.start_date DESC, cam.id DESC ";

    $stCam = $pdo->prepare($sqlCam);
    $stCam->execute($paramsCam);
    $campaigns = $stCam->fetchAll(PDO::FETCH_ASSOC);

    // Nếu không có phong trào nào theo bộ lọc, vẫn xuất file với thông báo
    $hasCampaign = !empty($campaigns);

    // Tạo danh sách campaign_id để query tập trung
    $campaignIds = array_map(fn($r) => (int) $r['id'], $campaigns);
    $placeholders = $campaignIds ? implode(',', array_fill(0, count($campaignIds), '?')) : '';

    // 2) Query tổng hợp THEO LỚP (chỉ những user đã được chấm score > 0)
    $classRows = [];
    if ($campaignIds) {
        $sqlClass = "
            SELECT
                cam.id AS campaign_id,
                cam.title AS campaign_title,
                sy.year_label AS year_label,
                cam.semester_code AS semester_code,

                c.id AS class_id,
                c.name AS class_name,

                COALESCE(cs.total, 0) AS total_members,
                COUNT(DISTINCT r.user_id) AS joined_count,
                COALESCE(MAX(ccr.score), 0) AS score
            FROM registrations r
            JOIN campaigns cam
                ON cam.id = r.campaign_id
            LEFT JOIN school_years sy
                ON sy.id = cam.school_year_id

            JOIN members m
                ON m.user_id = r.user_id
            JOIN classes c
                ON c.id = m.class_id

            LEFT JOIN (
                SELECT class_id, COUNT(DISTINCT id) AS total
                FROM members
                GROUP BY class_id
            ) cs ON cs.class_id = c.id

            LEFT JOIN campaign_class_results ccr
                ON ccr.class_id = c.id
               AND ccr.campaign_id = cam.id

            WHERE r.score > 0
              AND cam.id IN ($placeholders)

            GROUP BY cam.id, c.id
            HAVING joined_count > 0
            ORDER BY sy.year_label DESC, cam.semester_code, cam.start_date DESC, joined_count DESC, c.name
        ";
        $stClass = $pdo->prepare($sqlClass);
        $stClass->execute($campaignIds);
        $classRows = $stClass->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3) Query tổng hợp THEO KHOA/PHÒNG
    $deptRows = [];
    if ($campaignIds) {
        $sqlDept = "
            SELECT
                cam.id AS campaign_id,
                cam.title AS campaign_title,
                sy.year_label AS year_label,
                cam.semester_code AS semester_code,
                cam.score AS campaign_score,

                d.id AS dept_id,
                d.name AS dept_name,
                d.type AS dept_type,

                COALESCE(ds.total, 0) AS total_members,
                COUNT(DISTINCT r.user_id) AS joined_count
            FROM registrations r
            JOIN campaigns cam
                ON cam.id = r.campaign_id
            LEFT JOIN school_years sy
                ON sy.id = cam.school_year_id

            JOIN members m
                ON m.user_id = r.user_id
            JOIN classes c
                ON c.id = m.class_id
            JOIN departments d
                ON d.id = c.department_id

            LEFT JOIN (
                SELECT
                    d2.id AS dept_id,
                    COUNT(DISTINCT m2.id) AS total
                FROM departments d2
                LEFT JOIN classes c2 ON c2.department_id = d2.id
                LEFT JOIN members m2 ON m2.class_id = c2.id
                GROUP BY d2.id
            ) ds ON ds.dept_id = d.id

            WHERE r.score > 0
              AND cam.id IN ($placeholders)

            GROUP BY cam.id, d.id
            HAVING joined_count > 0
            ORDER BY sy.year_label DESC, cam.semester_code, cam.start_date DESC, joined_count DESC, d.name
        ";
        $stDept = $pdo->prepare($sqlDept);
        $stDept->execute($campaignIds);
        $deptRows = $stDept->fetchAll(PDO::FETCH_ASSOC);
    }

    // 4) Tạo file Excel (2 sheet)
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

    // ---------- helper style ----------
    $applySheetHeader = function ($sheet, string $reportTitle, string $yearLabel, string $semesterLabel, string $lastCol): int {
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        $Coordinate = '\PhpOffice\PhpSpreadsheet\Cell\Coordinate';
        $Alignment = '\PhpOffice\PhpSpreadsheet\Style\Alignment';

        $lastColIdx = $Coordinate::columnIndexFromString($lastCol);

        // Header org (dòng 1-4)
        $orgLeft = "ĐOÀN PHƯỜNG CHÁNH HƯNG\nBCH ĐOÀN TRƯỜNG\nCAO ĐẲNG BÁCH KHOA\nNAM SÀI GÒN\n***";
        $orgRight = "ĐOÀN TNCS HỒ CHÍ MINH";
        $place = "Quận 8";
        $dateLine = $place . ", ngày " . date('j') . " tháng " . date('n') . " năm " . date('Y');

        // Đảm bảo còn chỗ cho khối phải (ít nhất 4 cột nếu đủ)
        $rightWidth = min(4, $lastColIdx);                      // khối phải tối đa 4 cột
        $rightStartColIdx = max(1, $lastColIdx - $rightWidth + 1);

        // Khối trái tối đa 6 cột nhưng không được đè lên khối phải
        $leftEndColIdx = min(6, $rightStartColIdx - 1);
        if ($leftEndColIdx < 1) {
            // fallback: nếu sheet quá ít cột thì gom hết về trái
            $leftEndColIdx = min(6, $lastColIdx);
            $rightStartColIdx = $leftEndColIdx + 1;
            if ($rightStartColIdx > $lastColIdx)
                $rightStartColIdx = $lastColIdx;
        }

        $leftEndLetter = $Coordinate::stringFromColumnIndex($leftEndColIdx);
        $rightStartLetter = $Coordinate::stringFromColumnIndex($rightStartColIdx);

        // A1: (khối trái) - không viền
        $sheet->setCellValue("A1", $orgLeft);
        $sheet->mergeCells("A1:{$leftEndLetter}4");
        $sheet->getStyle("A1:{$leftEndLetter}4")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
            'alignment' => [
                'horizontal' => $Alignment::HORIZONTAL_CENTER,
                'vertical' => $Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        // Khối phải: dòng 1-3 - không viền
        $sheet->setCellValue("{$rightStartLetter}1", $orgRight);
        $sheet->mergeCells("{$rightStartLetter}1:{$lastCol}3");
        $sheet->getStyle("{$rightStartLetter}1:{$lastCol}3")->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
            'alignment' => [
                'horizontal' => $Alignment::HORIZONTAL_CENTER,
                'vertical' => $Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Dòng 4 bên phải (italic, right) - không viền
        $sheet->setCellValue("{$rightStartLetter}4", $dateLine);
        $sheet->mergeCells("{$rightStartLetter}4:{$lastCol}4");
        $sheet->getStyle("{$rightStartLetter}4:{$lastCol}4")->applyFromArray([
            'font' => ['italic' => true, 'size' => 12],
            'alignment' => [
                'horizontal' => $Alignment::HORIZONTAL_RIGHT,
                'vertical' => $Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Row heights header 1-4
        $sheet->getRowDimension(1)->setRowHeight(20.5);
        $sheet->getRowDimension(2)->setRowHeight(15.75);
        $sheet->getRowDimension(3)->setRowHeight(15.75);
        $sheet->getRowDimension(4)->setRowHeight(32.25);

        // Title (dòng 5-6)
        $line1 = $reportTitle;

        $lineYear = ($yearLabel !== '') ? ("NĂM HỌC {$yearLabel}") : "TẤT CẢ NĂM HỌC";
        $lineSem = ($semesterLabel !== '') ? $semesterLabel : "TẤT CẢ HỌC KỲ";
        $line2 = $lineYear . " - " . $lineSem;

        $sheet->setCellValue("A5", $line1);
        $sheet->mergeCells("A5:{$lastCol}5");
        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
            'font' => ['bold' => true, 'size' => 18],
            'alignment' => [
                'horizontal' => $Alignment::HORIZONTAL_CENTER,
                'vertical' => $Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $sheet->setCellValue("A6", $line2);
        $sheet->mergeCells("A6:{$lastCol}6");
        $sheet->getStyle("A6:{$lastCol}6")->applyFromArray([
            'font' => ['bold' => true, 'size' => 15],
            'alignment' => [
                'horizontal' => $Alignment::HORIZONTAL_CENTER,
                'vertical' => $Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $sheet->getRowDimension(5)->setRowHeight(33.0);
        $sheet->getRowDimension(6)->setRowHeight(28.5);

        // Table header sẽ bắt đầu từ dòng 7
        return 7;
    };


    $applyTableHeaderStyle = function ($sheet, string $fromCell, string $toCell) {
        $sheet->getStyle("{$fromCell}:{$toCell}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E5E7EB']
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
            ],
        ]);
    };

    // =========================
    // SHEET 1: THEO LỚP
    // =========================
    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('Theo lop');

    $lastCol1 = 'G';
    $headerRow = $applySheetHeader(
        $sheet1,
        'BÁO CÁO TỔNG HỢP PHONG TRÀO - THEO LỚP',
        $yearLabel,
        $semesterLabel,
        $lastCol1
    );

    $sheet1->setCellValue("A{$headerRow}", 'Năm học');
    $sheet1->setCellValue("B{$headerRow}", 'Học kỳ');
    $sheet1->setCellValue("C{$headerRow}", 'Lớp');
    $sheet1->setCellValue("D{$headerRow}", 'Phong trào');
    $sheet1->setCellValue("E{$headerRow}", 'Số người tham gia');
    $sheet1->setCellValue("F{$headerRow}", 'Tỷ lệ %');
    $sheet1->setCellValue("G{$headerRow}", 'Điểm');

    $applyTableHeaderStyle($sheet1, "A{$headerRow}", "{$lastCol1}{$headerRow}");

    $rowNum = $headerRow + 1;
    if (!$hasCampaign) {
        $sheet1->setCellValue("A{$rowNum}", 'Không có phong trào theo bộ lọc đã chọn.');
        $sheet1->mergeCells("A{$rowNum}:{$lastCol1}{$rowNum}");
    } elseif (empty($classRows)) {
        $sheet1->setCellValue("A{$rowNum}", 'Không có dữ liệu phát sinh theo lớp (chưa có đăng ký được chấm điểm).');
        $sheet1->mergeCells("A{$rowNum}:{$lastCol1}{$rowNum}");
    } else {
        foreach ($classRows as $r) {
            $total = (int) ($r['total_members'] ?? 0);
            $joined = (int) ($r['joined_count'] ?? 0);
            $rate = ($total > 0) ? ($joined / $total) : 0.0; // 0..1

            $semText = $r['semester_code'] ? romanSemesterFromCode($r['semester_code']) : '';

            $sheet1->setCellValue("A{$rowNum}", $r['year_label'] ?? '');
            $sheet1->setCellValue("B{$rowNum}", $semText);
            $sheet1->setCellValue("C{$rowNum}", $r['class_name'] ?? '');
            $sheet1->setCellValue("D{$rowNum}", $r['campaign_title'] ?? '');
            $sheet1->setCellValue("E{$rowNum}", "{$joined}/{$total}");
            $sheet1->setCellValue("F{$rowNum}", $rate); // number
            $sheet1->setCellValue("G{$rowNum}", (float) ($r['score'] ?? 0));

            $rowNum++;
        }

        $lastRow = $rowNum - 1;

        // Format % + score
        $sheet1->getStyle("F" . ($headerRow + 1) . ":F{$lastRow}")
            ->getNumberFormat()->setFormatCode('0.0%');
        $sheet1->getStyle("G" . ($headerRow + 1) . ":G{$lastRow}")
            ->getNumberFormat()->setFormatCode('0.0');

        // Border + wrap toàn bảng
        $sheet1->getStyle("A{$headerRow}:{$lastCol1}{$lastRow}")->applyFromArray([
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
            ],
        ]);

        // Canh lề
        $sheet1->getStyle("A" . ($headerRow + 1) . ":D{$lastRow}")
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        $sheet1->getStyle("E" . ($headerRow + 1) . ":F{$lastRow}")
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("G" . ($headerRow + 1) . ":G{$lastRow}")
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

    }

    // Cột D (Phong trào): nhỏ lại + xuống hàng
    $sheet1->getColumnDimension('D')->setAutoSize(false);
    $sheet1->getColumnDimension('D')->setWidth(36); // bạn chỉnh 24/26/30 tùy ý

    // Các cột còn lại vẫn AutoSize
    foreach (range('A', $lastCol1) as $col) {
        if ($col === 'D')
            continue;
        $sheet1->getColumnDimension($col)->setAutoSize(true);
    }


    // =========================
    // SHEET 2: THEO KHOA/PHÒNG
    // =========================
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Theo khoa');

    $lastCol2 = 'G';
    $headerRow = $applySheetHeader(
        $sheet2,
        'BÁO CÁO TỔNG HỢP PHONG TRÀO - THEO KHOA/PHÒNG',
        $yearLabel,
        $semesterLabel,
        $lastCol2
    );


    $sheet2->setCellValue("A{$headerRow}", 'Năm học');
    $sheet2->setCellValue("B{$headerRow}", 'Học kỳ');
    $sheet2->setCellValue("C{$headerRow}", 'Khoa/Phòng');
    $sheet2->setCellValue("D{$headerRow}", 'Phong trào');
    $sheet2->setCellValue("E{$headerRow}", 'Số người tham gia');
    $sheet2->setCellValue("F{$headerRow}", 'Tỷ lệ %');
    $sheet2->setCellValue("G{$headerRow}", 'Điểm');

    $applyTableHeaderStyle($sheet2, "A{$headerRow}", "{$lastCol2}{$headerRow}");

    $rowNum = $headerRow + 1;
    if (!$hasCampaign) {
        $sheet2->setCellValue("A{$rowNum}", 'Không có phong trào theo bộ lọc đã chọn.');
        $sheet2->mergeCells("A{$rowNum}:{$lastCol2}{$rowNum}");
    } elseif (empty($deptRows)) {
        $sheet2->setCellValue("A{$rowNum}", 'Không có dữ liệu phát sinh theo khoa/phòng (chưa có đăng ký được chấm điểm).');
        $sheet2->mergeCells("A{$rowNum}:{$lastCol2}{$rowNum}");
    } else {
        foreach ($deptRows as $r) {
            $total = (int) ($r['total_members'] ?? 0);
            $joined = (int) ($r['joined_count'] ?? 0);
            $rate = ($total > 0) ? ($joined / $total) : 0.0; // 0..1

            $campaignScore = (float) ($r['campaign_score'] ?? 0);
            $score = round($rate * $campaignScore, 1);

            $deptLabel = formatDeptName((string) ($r['dept_name'] ?? ''), (string) ($r['dept_type'] ?? ''));

            $semText = $r['semester_code'] ? romanSemesterFromCode($r['semester_code']) : '';

            $sheet2->setCellValue("A{$rowNum}", $r['year_label'] ?? '');
            $sheet2->setCellValue("B{$rowNum}", $semText);
            $sheet2->setCellValue("C{$rowNum}", $deptLabel);
            $sheet2->setCellValue("D{$rowNum}", $r['campaign_title'] ?? '');
            $sheet2->setCellValue("E{$rowNum}", "{$joined}/{$total}");
            $sheet2->setCellValue("F{$rowNum}", $rate); // number
            $sheet2->setCellValue("G{$rowNum}", $score);

            $rowNum++;
        }

        $lastRow = $rowNum - 1;

        $sheet2->getStyle("F" . ($headerRow + 1) . ":F{$lastRow}")
            ->getNumberFormat()->setFormatCode('0.0%');
        $sheet2->getStyle("G" . ($headerRow + 1) . ":G{$lastRow}")
            ->getNumberFormat()->setFormatCode('0.0');

        $sheet2->getStyle("A{$headerRow}:{$lastCol2}{$lastRow}")->applyFromArray([
            'alignment' => [
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText' => true
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]
            ],
        ]);

        $sheet2->getStyle("A" . ($headerRow + 1) . ":D{$lastRow}")
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        $sheet2->getStyle("E" . ($headerRow + 1) . ":F{$lastRow}")
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle("G" . ($headerRow + 1) . ":G{$lastRow}")
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

    }

    // Cột D (Phong trào): nhỏ lại + xuống hàng
    $sheet2->getColumnDimension('D')->setAutoSize(false);
    $sheet2->getColumnDimension('D')->setWidth(36);

    // Các cột còn lại vẫn AutoSize
    foreach (range('A', $lastCol2) as $col) {
        if ($col === 'D')
            continue;
        $sheet2->getColumnDimension($col)->setAutoSize(true);
    }


    // 5) Output file
    $fileYear = ($yearLabel !== '') ? slugify($yearLabel) : 'tat-ca-nam-hoc';
    $fileSem = ($semesterCode !== '') ? slugify($semesterCode) : 'tat-ca-hoc-ky';
    $filename = "tong-hop-phong-trao-{$fileYear}-{$fileSem}.xlsx";

    while (ob_get_level() > 0)
        ob_end_clean();
    header_remove();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
