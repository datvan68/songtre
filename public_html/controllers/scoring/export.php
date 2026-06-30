<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// export_scoring_summary
if ($action === 'export_scoring_summary') {

    // chỉ autoload khi export (tránh server thiếu vendor làm chết options/scoring_items)
    $vendor = __DIR__ . '/../../vendor/autoload.php';
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
        FROM classes c
        JOIN departments d   ON d.id = c.department_id
        LEFT JOIN gvcn_classes gc ON gc.class_id = c.id
        LEFT JOIN users u    ON u.id = gc.user_id
        LEFT JOIN members m  ON m.class_id = c.id
        WHERE d.type = 'khoa' AND c.status = 1
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
    if (!empty($campaignIds)) {
        // Recalculate campaign scores for unlocked campaigns to ensure corrected data is exported
        if (function_exists('recalculate_unlocked_campaign_scores')) {
            recalculate_unlocked_campaign_scores($pdo, $campaignIds);
        }

        $inCam = (count($campaignIds) === 1) ? '?' : str_repeat('?,', count($campaignIds) - 1) . '?';
        $stGetCamNames = $pdo->prepare("SELECT id, title FROM campaigns WHERE id IN ($inCam)");
        $stGetCamNames->execute($campaignIds);
        
        $camItems = [];
        foreach ($stGetCamNames->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $camItems[$row['title']] = (int) $row['id'];
        }
        $camTitles = array_keys($camItems);

        if (!empty($camTitles)) {
            $inCamTitles = (count($camTitles) === 1) ? '?' : str_repeat('?,', count($camTitles) - 1) . '?';
            
            $sqlJoined = "
                SELECT 
                    m.class_id,
                    c.title,
                    COUNT(DISTINCT m.user_id) as joined_quantity
                FROM members m
                JOIN registrations r 
                    ON r.user_id = m.user_id
                LEFT JOIN attendance_logs al 
                    ON al.user_id = m.user_id 
                   AND al.campaign_id = r.campaign_id 
                   AND al.result = 'ok'
                JOIN campaigns c ON c.id = r.campaign_id
                WHERE c.title IN ($inCamTitles)
                  AND (c.status <> 'hidden' OR c.status IS NULL)
                  AND c.school_year_id = ?
                  AND TRIM(c.semester_code) = ?
                  AND (al.user_id IS NOT NULL OR r.status = 'approved')
                GROUP BY m.class_id, c.title
            ";
            $paramsCam = array_merge($camTitles, [$schoolYearId, $semesterCode]);
            
            $stJ = $pdo->prepare($sqlJoined);
            $stJ->execute($paramsCam);
            foreach ($stJ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $camId = $camItems[$r['title']];
                $campaignMap[(int) $r['class_id']][$camId] = [
                    'joined' => (int) $r['joined_quantity'],
                    'score' => null
                ];
            }

            $sqlCam = "
                SELECT 
                    ccr.class_id, 
                    c.title,
                    MAX(ccr.score) as score
                FROM campaign_class_results ccr
                JOIN campaigns c ON c.id = ccr.campaign_id
                WHERE c.title IN ($inCamTitles)
                  AND (c.status <> 'hidden' OR c.status IS NULL)
                  AND c.school_year_id = ?
                  AND TRIM(c.semester_code) = ?
                GROUP BY ccr.class_id, c.title
            ";
            
            $stR = $pdo->prepare($sqlCam);
            $stR->execute($paramsCam);
            foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cId = (int) $r['class_id'];
                $camId = $camItems[$r['title']];
                if (!isset($campaignMap[$cId][$camId])) {
                    $campaignMap[$cId][$camId] = ['joined' => 0, 'score' => null];
                }
                $campaignMap[$cId][$camId]['score'] = $r['score'] !== null ? (float)$r['score'] : null;
            }
        }
    }

    // ===== MAP đóng tiền theo lớp (chỉ những khoản thu đã tick) =====
    $feeMap = [];
    if (!empty($feeIds)) {
        $inFee = (count($feeIds) === 1) ? '?' : str_repeat('?,', count($feeIds) - 1) . '?';
        $stGetNames = $pdo->prepare("SELECT id, item_name FROM finance_transactions WHERE id IN ($inFee)");
        $stGetNames->execute($feeIds);
        
        $feeItems = [];
        foreach ($stGetNames->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $feeItems[$row['item_name']] = (int) $row['id'];
        }
        $itemNames = array_keys($feeItems);

        if (!empty($itemNames)) {
            $inItemNames = (count($itemNames) === 1) ? '?' : str_repeat('?,', count($itemNames) - 1) . '?';
            $sqlFeeCounts = "
                SELECT
                    m.class_id,
                    ft.item_name,
                    COUNT(DISTINCT ftp.member_id) AS paid_count
                FROM finance_transaction_participants ftp
                JOIN members m ON m.id = ftp.member_id
                JOIN finance_transactions ft ON ft.id = ftp.transaction_id
                WHERE ft.item_name IN ($inItemNames)
                  AND (ft.status <> 'hidden' OR ft.status IS NULL)
            ";

            $paramsFeeCounts = $itemNames;

            if (db_has_column($pdo, 'finance_transactions', 'school_year_id')) {
                $sqlFeeCounts .= " AND ft.school_year_id = ?";
                $paramsFeeCounts[] = $schoolYearId;
            }

            if ($fromDate !== '' && $toDateEx !== '' && db_has_column($pdo, 'finance_transactions', 'created_at')) {
                $sqlFeeCounts .= " AND ft.created_at >= ? AND ft.created_at < ?";
                $paramsFeeCounts[] = $fromDate;
                $paramsFeeCounts[] = $toDateEx;
            }

            $sqlFeeCounts .= " GROUP BY m.class_id, ft.item_name ";

            $stFC = $pdo->prepare($sqlFeeCounts);
            $stFC->execute($paramsFeeCounts);

            foreach ($stFC->fetchAll(PDO::FETCH_ASSOC) as $fc) {
                $txId = $feeItems[$fc['item_name']];
                $feeMap[(int) $fc['class_id']][$txId] = (int) $fc['paid_count'];
            }
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

    // TITLE
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
            $noteParts = [];

            $sttClassInDept++;
            $sheet->setCellValueByColumnAndRow($COL_A, $rowNum, $sttClassInDept);
            $sheet->setCellValueByColumnAndRow($COL_E, $rowNum, (string) $cls['class_name']);

            $rowTotalPoint = 0.0;

            // fees: ghi paid/size
            foreach ($feeActivities as $tx) {
                $txId = (int) $tx['id'];
                $paid = (int) ($feeMap[$classId][$txId] ?? 0);
                $ratioText = ($classSize > 0) ? "{$paid}/{$classSize}" : "0/0";

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
                $campRes = $campaignMap[$classId][$camId] ?? null;
                $joined = isset($campRes['joined']) ? (int)$campRes['joined'] : 0;
                $scoreInResult = isset($campRes['score']) ? $campRes['score'] : null;

                $maxPoint = (float) $getPoint('campaign', $camId);
                if ($scoreInResult !== null) {
                    $rate = $scoreInResult / 10.0;
                } else {
                    $rate = ($joined > 0) ? 1.0 : 0.0;
                }
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

            // Rate: dạng % thật
            $rateDec = ($totalMaxPoint > 0) ? ($rowTotalPoint / $totalMaxPoint) : 0.0;
            if ($rateDec > 1)
                $rateDec = 1;

            $rateAddr = Coordinate::stringFromColumnIndex($rateColIdx) . $rowNum;
            $sheet->setCellValueByColumnAndRow($rateColIdx, $rowNum, $rateDec);
            $sheet->getStyle($rateAddr)->getNumberFormat()->setFormatCode('0%');

            // Note
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
