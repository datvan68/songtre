<?php
declare(strict_types=1);

if (!function_exists('calculate_all_classes_scores')) {
    function calculate_all_classes_scores(PDO $pdo, int $schoolYearId, string $semesterCode, array $pointsPayload, ?array $pagination = null): array
    {
        $totalMaxPoint = 10.0;
        $campaignIds = array_keys($pointsPayload['campaigns'] ?? []);
        $feeIds = array_keys($pointsPayload['fees'] ?? []);

        [$fromDate, $toDateEx] = semesterDateRange($pdo, $schoolYearId, $semesterCode);

        // campaigns
        $campaigns = [];
        if (!empty($campaignIds)) {
            $inCam = (count($campaignIds) === 1) ? '?' : str_repeat('?,', count($campaignIds) - 1) . '?';
            $sqlCam = "
                SELECT id, title, target
                FROM campaigns
                WHERE id IN ($inCam)
                ORDER BY start_date, id
            ";
            $stCam = $pdo->prepare($sqlCam);
            $stCam->execute($campaignIds);
            $campaigns = $stCam->fetchAll(PDO::FETCH_ASSOC);
        }

        // fees
        $feeActivities = [];
        if (!empty($feeIds)) {
            $inFee = (count($feeIds) === 1) ? '?' : str_repeat('?,', count($feeIds) - 1) . '?';
            $sqlFee = "
                SELECT 
                    ft.id,
                    COALESCE(NULLIF(TRIM(ft.item_name), ''), 'Quỹ 1K') AS title,
                    COALESCE(fi.target_type, 'tat_ca') AS target_type
                FROM finance_transactions ft
                LEFT JOIN finance_items fi ON fi.name = ft.item_name AND fi.type = 'income'
                WHERE ft.id IN ($inFee)
            ";
            $stFee = $pdo->prepare($sqlFee);
            $stFee->execute($feeIds);
            $feeActivities = $stFee->fetchAll(PDO::FETCH_ASSOC);
        }

        // Lọc và phân trang lớp
        $whereConds = ["d.type = 'khoa'", "c.status = 1"];
        $queryParams = [];

        if ($pagination !== null) {
            $search = trim((string)($pagination['search'] ?? ''));
            $dept = trim((string)($pagination['dept_name'] ?? ''));

            if ($search !== '') {
                $whereConds[] = "(c.name LIKE :search OR u.fullname LIKE :search OR u.username LIKE :search)";
                $queryParams[':search'] = '%' . $search . '%';
            }
            if ($dept !== '') {
                $whereConds[] = "d.name = :dept_name";
                $queryParams[':dept_name'] = $dept;
            }
        }

        $whereSql = implode(" AND ", $whereConds);

        // 1. Đếm tổng số lớp thỏa mãn bộ lọc
        $sqlCount = "
            SELECT COUNT(DISTINCT c.id)
            FROM gvcn_classes gc
            JOIN classes c       ON c.id = gc.class_id
            JOIN departments d   ON d.id = c.department_id
            LEFT JOIN users u    ON u.id = gc.user_id
            WHERE $whereSql
        ";
        $stCount = $pdo->prepare($sqlCount);
        $stCount->execute($queryParams);
        $totalCount = (int)$stCount->fetchColumn();

        // 2. Lấy danh sách lớp phân trang
        $sqlClasses = "
            SELECT
                c.id   AS class_id,
                c.name AS class_name,
                d.name AS dept_name,
                GROUP_CONCAT(
                    DISTINCT COALESCE(u.fullname, u.username)
                    ORDER BY COALESCE(u.fullname, u.username)
                    SEPARATOR ', '
                ) AS gvcn_name,
                COUNT(DISTINCT m.id) AS class_size,
                COUNT(DISTINCT CASE WHEN (m.stop_follow = 0 OR m.stop_follow IS NULL) THEN m.id END) AS tat_ca_count,
                COUNT(DISTINCT CASE WHEN (m.stop_follow = 0 OR m.stop_follow IS NULL) AND LOWER(CAST(m.type AS CHAR)) IN ('member','doanvien','doan_vien','dv','doan-vien','doan vien','đoàn viên','doan') THEN m.id END) AS doan_vien_count,
                COUNT(DISTINCT CASE WHEN (m.stop_follow = 0 OR m.stop_follow IS NULL) AND LOWER(CAST(m.type AS CHAR)) IN ('youth','thanhnien','thanh_nien','tn','thanh-nien','thanh nien','thanh') THEN m.id END) AS thanh_nien_count
            FROM gvcn_classes gc
            JOIN classes c       ON c.id = gc.class_id
            JOIN departments d   ON d.id = c.department_id
            LEFT JOIN users u    ON u.id = gc.user_id
            LEFT JOIN members m  ON m.class_id = c.id
            WHERE $whereSql
            GROUP BY c.id, c.name, d.name
            ORDER BY d.name, gvcn_name, c.name
        ";

        if ($pagination !== null) {
            $page = max(1, (int)($pagination['page'] ?? 1));
            $limit = max(1, (int)($pagination['limit'] ?? 20));
            $offset = ($page - 1) * $limit;
            $sqlClasses .= " LIMIT " . $limit . " OFFSET " . $offset;
        }

        $stClasses = $pdo->prepare($sqlClasses);
        $stClasses->execute($queryParams);
        $classes = $stClasses->fetchAll(PDO::FETCH_ASSOC);

        $classIds = array_map(fn($c) => (int)$c['class_id'], $classes);

        // Map kết quả phong trào
        $campaignMap = [];
        if ($campaigns && !empty($classIds)) {
            $ids = array_map(fn($c) => (int) $c['id'], $campaigns);
            $in = (count($ids) === 1) ? '?' : str_repeat('?,', count($ids) - 1) . '?';
            $inClass = (count($classIds) === 1) ? '?' : str_repeat('?,', count($classIds) - 1) . '?';
            
            $stR = $pdo->prepare("
                SELECT class_id, campaign_id, joined_quantity, score
                FROM campaign_class_results
                WHERE campaign_id IN ($in) AND class_id IN ($inClass)
            ");
            $stR->execute(array_merge($ids, $classIds));
            foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $campaignMap[(int) $r['class_id']][(int) $r['campaign_id']] = [
                    'joined' => (int) $r['joined_quantity'],
                    'score' => $r['score'] !== null ? (float)$r['score'] : null
                ];
            }
        }

        // Map kết quả đóng phí
        $feeMap = [];
        if (!empty($feeIds) && !empty($classIds)) {
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
                $inClass = (count($classIds) === 1) ? '?' : str_repeat('?,', count($classIds) - 1) . '?';
                
                $sqlFeeCounts = "
                    SELECT
                        m.class_id,
                        ft.item_name,
                        COUNT(DISTINCT ftp.member_id) AS paid_count
                    FROM finance_transaction_participants ftp
                    JOIN members m ON m.id = ftp.member_id
                    JOIN finance_transactions ft ON ft.id = ftp.transaction_id
                    WHERE ft.item_name IN ($inItemNames)
                      AND m.class_id IN ($inClass)
                      AND (ft.status <> 'hidden' OR ft.status IS NULL)
                ";
                $paramsFeeCounts = array_merge($itemNames, $classIds);

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

        $rows = [];
        foreach ($classes as $cls) {
            $classId = (int) $cls['class_id'];
            $classSize = (int) $cls['class_size'];
            $rowTotalPoint = 0.0;
            $noteParts = [];

            $feeScores = [];
            foreach ($feeActivities as $tx) {
                $txId = (int) $tx['id'];
                $paid = (int) ($feeMap[$classId][$txId] ?? 0);
                
                $targetType = $tx['target_type'] ?? 'tat_ca';
                $requiredCount = (int)$cls['tat_ca_count'];
                if ($targetType === 'doan_vien') {
                    $requiredCount = (int)$cls['doan_vien_count'];
                } elseif ($targetType === 'thanh_nien') {
                    $requiredCount = (int)$cls['thanh_nien_count'];
                }
                
                $ratioText = ($requiredCount > 0) ? "{$paid}/{$requiredCount}" : "0/0";
                $title = trim((string) $tx['title']) ?: 'Khoản thu';
                $noteParts[] = $title . ' ' . $ratioText;

                $maxPoint = (float) $getPoint('fee', $txId);
                $rate = ($requiredCount > 0) ? ($paid / $requiredCount) : ($paid > 0 ? 1.0 : 0.0);
                $rate = min(1.0, max(0.0, $rate));
                $earned = round($rate * $maxPoint, 2);

                $feeScores[$txId] = [
                    'id' => $txId,
                    'title' => $title,
                    'paid' => $paid,
                    'earned' => $earned,
                    'max_point' => $maxPoint
                ];
                $rowTotalPoint += $earned;
            }

            $campaignScores = [];
            foreach ($campaigns as $cam) {
                $camId = (int) $cam['id'];
                $campRes = $campaignMap[$classId][$camId] ?? null;
                $joined = isset($campRes['joined']) ? (int)$campRes['joined'] : 0;
                $scoreInResult = isset($campRes['score']) ? $campRes['score'] : null;

                $maxPoint = (float) $getPoint('campaign', $camId);
                if ($scoreInResult !== null) {
                    $rate = $scoreInResult / 10.0;
                } else {
                    $target = (int) ($cam['target'] ?? 0);
                    $requiredCount = $target > 0 ? $target : (int)$cls['class_size'];
                    $rate = ($requiredCount > 0) ? ($joined / $requiredCount) : 0.0;
                }

                $rate = min(1.0, max(0.0, $rate));
                $earned = round($rate * $maxPoint, 2);
                $campaignScores[$camId] = [
                    'id' => $camId,
                    'title' => $cam['title'],
                    'joined' => $joined,
                    'earned' => $earned,
                    'max_point' => $maxPoint
                ];
                $rowTotalPoint += $earned;
            }

            $rowTotalPoint = round($rowTotalPoint, 2);
            $performanceRate = ($totalMaxPoint > 0) ? ($rowTotalPoint / $totalMaxPoint) : 0.0;
            if ($performanceRate > 1) $performanceRate = 1.0;

            $rows[] = [
                'class_id' => $classId,
                'class_name' => $cls['class_name'],
                'dept_name' => $cls['dept_name'],
                'gvcn_name' => $cls['gvcn_name'],
                'class_size' => $classSize,
                'fee_scores' => $feeScores,
                'campaign_scores' => $campaignScores,
                'total_score' => $rowTotalPoint,
                'performance_rate' => $performanceRate,
                'note' => implode(' || ', $noteParts)
            ];
        }

        // Lấy danh sách tất cả các khoa để render bộ lọc ở client
        $depts = $pdo->query("
            SELECT DISTINCT d.name 
            FROM departments d
            JOIN classes c ON c.department_id = d.id
            WHERE d.type = 'khoa'
            ORDER BY d.name
        ")->fetchAll(PDO::FETCH_COLUMN);

        return [
            'campaigns' => $campaigns,
            'fees' => $feeActivities,
            'classes_scores' => $rows,
            'total_count' => $totalCount,
            'departments' => $depts
        ];
    }
}

// preview_scoring_summary
if ($action === 'preview_scoring_summary') {
    header('Content-Type: application/json; charset=utf-8');

    $schoolYearId = (int) ($_POST['school_year'] ?? $_GET['school_year'] ?? 0);
    $semesterCode = trim((string) ($_POST['semester'] ?? $_GET['semester'] ?? ''));
    $pointsJson = (string) ($_POST['points_json'] ?? $_GET['points_json'] ?? '');

    $page = (int) ($_POST['page'] ?? $_GET['page'] ?? 1);
    $limit = (int) ($_POST['limit'] ?? $_GET['limit'] ?? 20);
    $search = trim((string) ($_POST['search'] ?? $_GET['search'] ?? ''));
    $deptName = trim((string) ($_POST['dept_name'] ?? $_GET['dept_name'] ?? ''));

    if ($schoolYearId <= 0 || $semesterCode === '') {
        json_err('missing_filters', 400);
    }

    $pointsPayload = [];
    if ($pointsJson !== '') {
        $tmp = json_decode($pointsJson, true);
        if (is_array($tmp)) {
            $pointsPayload = $tmp;
        }
    }

    $pagination = [
        'page' => $page,
        'limit' => $limit,
        'search' => $search,
        'dept_name' => $deptName
    ];

    $result = calculate_all_classes_scores($pdo, $schoolYearId, $semesterCode, $pointsPayload, $pagination);
    
    $result['page'] = $page;
    $result['limit'] = $limit;

    json_ok($result);
}

// class_scoring_detail
if ($action === 'class_scoring_detail') {
    header('Content-Type: application/json; charset=utf-8');

    $classId = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
    $schoolYearId = (int) ($_GET['school_year'] ?? $_POST['school_year'] ?? 0);
    $semesterCode = trim((string) ($_GET['semester'] ?? $_POST['semester'] ?? ''));
    $pointsJson = (string) ($_GET['points_json'] ?? $_POST['points_json'] ?? '');

    if ($classId <= 0 || $schoolYearId <= 0 || $semesterCode === '') {
        json_err('missing_parameters', 400);
    }

    $pointsPayload = [];
    if ($pointsJson !== '') {
        $tmp = json_decode($pointsJson, true);
        if (is_array($tmp)) {
            $pointsPayload = $tmp;
        }
    }

    $campaignIds = array_keys($pointsPayload['campaigns'] ?? []);
    $feeIds = array_keys($pointsPayload['fees'] ?? []);

    // 1. Get class members
    $stM = $pdo->prepare("
        SELECT m.id AS member_id, m.user_id, COALESCE(m.fullname, u.fullname, u.username) AS fullname, u.username
        FROM members m
        JOIN users u ON u.id = m.user_id
        WHERE m.class_id = ?
        ORDER BY m.fullname, u.username
    ");
    $stM->execute([$classId]);
    $members = $stM->fetchAll(PDO::FETCH_ASSOC);

    if (!$members) {
        json_ok(['members' => []]);
    }

    $memberIds = array_map(fn($m) => (int)$m['member_id'], $members);
    $userIds = array_map(fn($m) => (int)$m['user_id'], $members);

    // 2. Get fees participation
    $feeParticipation = [];
    if (!empty($feeIds) && !empty($memberIds)) {
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
            $inMem = (count($memberIds) === 1) ? '?' : str_repeat('?,', count($memberIds) - 1) . '?';
            
            [$fromDate, $toDateEx] = semesterDateRange($pdo, $schoolYearId, $semesterCode);
            
            $sqlF = "
                SELECT ft.item_name, ftp.member_id
                FROM finance_transaction_participants ftp
                JOIN finance_transactions ft ON ft.id = ftp.transaction_id
                WHERE ft.item_name IN ($inItemNames) 
                  AND ftp.member_id IN ($inMem)
                  AND (ft.status <> 'hidden' OR ft.status IS NULL)
            ";
            $paramsF = array_merge($itemNames, $memberIds);
            
            if (db_has_column($pdo, 'finance_transactions', 'school_year_id')) {
                $sqlF .= " AND ft.school_year_id = ?";
                $paramsF[] = $schoolYearId;
            }

            if ($fromDate !== '' && $toDateEx !== '' && db_has_column($pdo, 'finance_transactions', 'created_at')) {
                $sqlF .= " AND ft.created_at >= ? AND ft.created_at < ?";
                $paramsF[] = $fromDate;
                $paramsF[] = $toDateEx;
            }
            
            $stF = $pdo->prepare($sqlF);
            $stF->execute($paramsF);
            
            foreach ($stF->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $txId = $feeItems[$row['item_name']];
                $feeParticipation[(int)$row['member_id']][$txId] = true;
            }
        }
    }

    // 3. Get campaigns participation
    $campParticipation = [];
    if (!empty($campaignIds) && !empty($userIds)) {
        $inCam = (count($campaignIds) === 1) ? '?' : str_repeat('?,', count($campaignIds) - 1) . '?';
        $inUser = (count($userIds) === 1) ? '?' : str_repeat('?,', count($userIds) - 1) . '?';

        // Get attendance logs
        $sqlA = "
            SELECT campaign_id, user_id
            FROM attendance_logs
            WHERE campaign_id IN ($inCam) AND user_id IN ($inUser) AND result = 'ok'
        ";
        $stA = $pdo->prepare($sqlA);
        $stA->execute(array_merge($campaignIds, $userIds));
        $attRows = $stA->fetchAll(PDO::FETCH_ASSOC);

        // Get registrations
        $sqlR = "
            SELECT campaign_id, user_id, status
            FROM registrations
            WHERE campaign_id IN ($inCam) AND user_id IN ($inUser)
        ";
        $stR = $pdo->prepare($sqlR);
        $stR->execute(array_merge($campaignIds, $userIds));
        $regRows = $stR->fetchAll(PDO::FETCH_ASSOC);

        // Map attendance
        foreach ($attRows as $row) {
            $campParticipation[(int)$row['user_id']][(int)$row['campaign_id']] = true;
        }

        // Map registration (status !== 'approved' -> joined)
        foreach ($regRows as $row) {
            if ($row['status'] !== 'approved') {
                $campParticipation[(int)$row['user_id']][(int)$row['campaign_id']] = true;
            }
        }
    }

    // 4. Build member list with status details
    $memberDetails = [];
    foreach ($members as $m) {
        $mid = (int)$m['member_id'];
        $uid = (int)$m['user_id'];

        $feesDetails = [];
        foreach ($feeIds as $fid) {
            $feesDetails[$fid] = isset($feeParticipation[$mid][$fid]);
        }

        $campsDetails = [];
        foreach ($campaignIds as $cid) {
            $campsDetails[$cid] = isset($campParticipation[$uid][$cid]);
        }

        $memberDetails[] = [
            'member_id' => $mid,
            'fullname' => $m['fullname'],
            'username' => $m['username'],
            'fees' => $feesDetails,
            'campaigns' => $campsDetails
        ];
    }

    json_ok([
        'members' => $memberDetails
    ]);
}
