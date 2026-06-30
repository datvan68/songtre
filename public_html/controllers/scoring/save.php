<?php
declare(strict_types=1);

// save_scoring_summary
if ($action === 'save_scoring_summary') {
    if (!is_admin() && !can('scoring', 'update')) {
        json_err('Forbidden', 403);
    }

    header('Content-Type: application/json; charset=utf-8');

    $schoolYearId = (int) ($_POST['school_year'] ?? 0);
    $semesterCode = trim((string) ($_POST['semester'] ?? ''));
    $pointsJson = (string) ($_POST['points_json'] ?? '');

    if ($schoolYearId <= 0 || $semesterCode === '' || $pointsJson === '') {
        json_err('missing_parameters', 400);
    }

    $pointsPayload = json_decode($pointsJson, true);
    if (!is_array($pointsPayload)) {
        json_err('invalid_points_json', 400);
    }

    // 1. Calculate dynamic scores for ALL classes (no pagination)
    $calcResult = calculate_all_classes_scores($pdo, $schoolYearId, $semesterCode, $pointsPayload, null);
    $classesScores = $calcResult['classes_scores'] ?? [];
    $campaigns = $calcResult['campaigns'] ?? [];
    $fees = $calcResult['fees'] ?? [];

    $campaignIds = array_map(fn($c) => (int) $c['id'], $campaigns);
    $feeIds = array_map(fn($f) => (int) $f['id'], $fees);

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

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Delete existing scores for this school year & semester
        $stDelClass = $pdo->prepare("DELETE FROM class_semester_scores WHERE school_year_id = ? AND semester_code = ?");
        $stDelClass->execute([$schoolYearId, $semesterCode]);

        $stDelMember = $pdo->prepare("DELETE FROM member_semester_scores WHERE school_year_id = ? AND semester_code = ?");
        $stDelMember->execute([$schoolYearId, $semesterCode]);

        // Prepared statements for insertion
        $stInsClass = $pdo->prepare("
            INSERT INTO class_semester_scores 
            (school_year_id, semester_code, class_id, class_size, fee_scores, campaign_scores, total_score, performance_rate, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stInsMember = $pdo->prepare("
            INSERT INTO member_semester_scores
            (school_year_id, semester_code, class_id, member_id, user_id, fee_scores, campaign_scores, fee_score, campaign_score, total_score)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($classesScores as $cls) {
            $classId = (int) $cls['class_id'];
            $classSize = (int) $cls['class_size'];

            // Save class semester score
            $feeScoresJson = json_encode($cls['fee_scores'], JSON_UNESCAPED_UNICODE);
            $campaignScoresJson = json_encode($cls['campaign_scores'], JSON_UNESCAPED_UNICODE);

            $stInsClass->execute([
                $schoolYearId,
                $semesterCode,
                $classId,
                $classSize,
                $feeScoresJson,
                $campaignScoresJson,
                $cls['total_score'],
                $cls['performance_rate'],
                $cls['note']
            ]);

            // Calculate & save individual scores for this class members
            if ($classSize > 0) {
                // Get class members
                $stM = $pdo->prepare("
                    SELECT m.id AS member_id, m.user_id 
                    FROM members m 
                    WHERE m.class_id = ?
                ");
                $stM->execute([$classId]);
                $members = $stM->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($members)) {
                    $memberIds = array_map(fn($m) => (int)$m['member_id'], $members);
                    $userIds = array_map(fn($m) => (int)$m['user_id'], $members);

                    // Get fees participation
                    $feeParticipation = [];
                    if (!empty($feeIds)) {
                        $inFee = str_repeat('?,', count($feeIds) - 1) . '?';
                        $inMem = str_repeat('?,', count($memberIds) - 1) . '?';
                        $stF = $pdo->prepare("
                            SELECT transaction_id, member_id 
                            FROM finance_transaction_participants 
                            WHERE transaction_id IN ($inFee) AND member_id IN ($inMem)
                        ");
                        $stF->execute(array_merge($feeIds, $memberIds));
                        foreach ($stF->fetchAll(PDO::FETCH_ASSOC) as $row) {
                            $feeParticipation[(int)$row['member_id']][(int)$row['transaction_id']] = true;
                        }
                    }

                    // Get campaigns participation
                    $campParticipation = [];
                    if (!empty($campaignIds)) {
                        $inCam = str_repeat('?,', count($campaignIds) - 1) . '?';
                        $inUser = str_repeat('?,', count($userIds) - 1) . '?';

                        // Attendance logs
                        $stA = $pdo->prepare("
                            SELECT campaign_id, user_id 
                            FROM attendance_logs 
                            WHERE campaign_id IN ($inCam) AND user_id IN ($inUser) AND result = 'ok'
                        ");
                        $stA->execute(array_merge($campaignIds, $userIds));
                        $attRows = $stA->fetchAll(PDO::FETCH_ASSOC);

                        // Registrations
                        $stR = $pdo->prepare("
                            SELECT campaign_id, user_id, status 
                            FROM registrations 
                            WHERE campaign_id IN ($inCam) AND user_id IN ($inUser)
                        ");
                        $stR->execute(array_merge($campaignIds, $userIds));
                        $regRows = $stR->fetchAll(PDO::FETCH_ASSOC);

                        $userRegMap = [];
                        foreach ($regRows as $row) {
                            $userRegMap[(int)$row['user_id']][(int)$row['campaign_id']] = $row['status'];
                        }

                        foreach ($attRows as $row) {
                            $camId = (int)$row['campaign_id'];
                            $uId = (int)$row['user_id'];
                            if (isset($userRegMap[$uId][$camId])) {
                                $campParticipation[$uId][$camId] = true;
                            }
                        }

                        foreach ($regRows as $row) {
                            $camId = (int)$row['campaign_id'];
                            $uId = (int)$row['user_id'];
                            if ($row['status'] === 'approved') {
                                $campParticipation[$uId][$camId] = true;
                            }
                        }
                    }

                    // Save each member score
                    foreach ($members as $m) {
                        $memberId = (int)$m['member_id'];
                        $userId = (int)$m['user_id'];

                        $memberFeeScores = [];
                        $totalMemberFeeScore = 0.0;
                        foreach ($fees as $f) {
                            $fId = (int) $f['id'];
                            $hasPaid = isset($feeParticipation[$memberId][$fId]);
                            $earned = $hasPaid ? (float) $getPoint('fee', $fId) : 0.0;
                            $memberFeeScores[$fId] = [
                                'title' => $f['title'],
                                'paid' => $hasPaid,
                                'earned' => $earned
                            ];
                            $totalMemberFeeScore += $earned;
                        }

                        $memberCampScores = [];
                        $totalMemberCampScore = 0.0;
                        foreach ($campaigns as $c) {
                            $cId = (int) $c['id'];
                            $hasJoined = isset($campParticipation[$userId][$cId]);
                            $earned = $hasJoined ? (float) $getPoint('campaign', $cId) : 0.0;
                            $memberCampScores[$cId] = [
                                'title' => $c['title'],
                                'joined' => $hasJoined,
                                'earned' => $earned
                            ];
                            $totalMemberCampScore += $earned;
                        }

                        $totalMemberScore = $totalMemberFeeScore + $totalMemberCampScore;

                        $stInsMember->execute([
                            $schoolYearId,
                            $semesterCode,
                            $classId,
                            $memberId,
                            $userId,
                            json_encode($memberFeeScores, JSON_UNESCAPED_UNICODE),
                            json_encode($memberCampScores, JSON_UNESCAPED_UNICODE),
                            $totalMemberFeeScore,
                            $totalMemberCampScore,
                            $totalMemberScore
                        ]);
                    }
                }
            }
        }

        $pdo->commit();
        json_ok('Saved successfully');
    } catch (Throwable $t) {
        $pdo->rollBack();
        json_err('Transaction failed: ' . $t->getMessage(), 500);
    }
}

// list_saved_semesters
if ($action === 'list_saved_semesters') {
    header('Content-Type: application/json; charset=utf-8');

    $rows = $pdo->query("
        SELECT DISTINCT css.school_year_id, css.semester_code, sy.year_label
        FROM class_semester_scores css
        JOIN school_years sy ON sy.id = css.school_year_id
        ORDER BY sy.year_label DESC, css.semester_code DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Format label
    foreach ($rows as &$r) {
        $r['semester_label'] = romanSemesterFromCode($r['semester_code']);
    }

    json_ok($rows);
}

// list_saved_classes
if ($action === 'list_saved_classes') {
    header('Content-Type: application/json; charset=utf-8');

    $schoolYearId = (int) ($_GET['school_year'] ?? $_POST['school_year'] ?? 0);
    $semesterCode = trim((string) ($_GET['semester'] ?? $_POST['semester'] ?? ''));
    $search = trim((string) ($_GET['search'] ?? $_POST['search'] ?? ''));
    $deptName = trim((string) ($_GET['dept_name'] ?? $_POST['dept_name'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? $_POST['page'] ?? 1));
    $limit = max(1, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    if ($schoolYearId <= 0 || $semesterCode === '') {
        json_err('missing_filters', 400);
    }

    $whereConds = ["css.school_year_id = :sy", "css.semester_code = :sem", "d.type = 'khoa'"];
    $queryParams = [
        ':sy' => $schoolYearId,
        ':sem' => $semesterCode
    ];

    if ($search !== '') {
        $whereConds[] = "(c.name LIKE :search OR u.fullname LIKE :search OR u.username LIKE :search)";
        $queryParams[':search'] = '%' . $search . '%';
    }
    if ($deptName !== '') {
        $whereConds[] = "d.name = :dept_name";
        $queryParams[':dept_name'] = $deptName;
    }

    $whereSql = implode(" AND ", $whereConds);

    // Count
    $sqlCount = "
        SELECT COUNT(DISTINCT css.id)
        FROM class_semester_scores css
        JOIN classes c ON c.id = css.class_id
        JOIN departments d ON d.id = c.department_id
        LEFT JOIN gvcn_classes gc ON gc.class_id = c.id
        LEFT JOIN users u ON u.id = gc.user_id
        WHERE $whereSql
    ";
    $stCount = $pdo->prepare($sqlCount);
    $stCount->execute($queryParams);
    $totalCount = (int)$stCount->fetchColumn();

    // Query
    $sqlClasses = "
        SELECT 
            css.id,
            css.class_id,
            css.class_size,
            css.total_score,
            css.performance_rate,
            css.note,
            css.fee_scores,
            css.campaign_scores,
            c.name AS class_name,
            d.name AS dept_name,
            GROUP_CONCAT(
                DISTINCT COALESCE(u.fullname, u.username)
                ORDER BY COALESCE(u.fullname, u.username)
                SEPARATOR ', '
            ) AS gvcn_name
        FROM class_semester_scores css
        JOIN classes c ON c.id = css.class_id
        JOIN departments d ON d.id = c.department_id
        LEFT JOIN gvcn_classes gc ON gc.class_id = c.id
        LEFT JOIN users u ON u.id = gc.user_id
        WHERE $whereSql
        GROUP BY css.id, css.class_id, css.class_size, css.total_score, css.performance_rate, css.note, c.name, d.name
        ORDER BY d.name ASC, gvcn_name ASC, c.name ASC
        LIMIT $limit OFFSET $offset
    ";

    $stClasses = $pdo->prepare($sqlClasses);
    $stClasses->execute($queryParams);
    $rows = $stClasses->fetchAll(PDO::FETCH_ASSOC);

    // Decode JSON scores
    foreach ($rows as &$r) {
        $r['fee_scores'] = json_decode($r['fee_scores'], true);
        $r['campaign_scores'] = json_decode($r['campaign_scores'], true);
    }

    // Get all department names for filter
    $depts = $pdo->query("
        SELECT DISTINCT d.name 
        FROM departments d
        JOIN classes c ON c.department_id = d.id
        WHERE d.type = 'khoa'
        ORDER BY d.name
    ")->fetchAll(PDO::FETCH_COLUMN);

    json_ok([
        'classes_scores' => $rows,
        'total_count' => $totalCount,
        'departments' => $depts,
        'page' => $page,
        'limit' => $limit
    ]);
}

// saved_class_detail
if ($action === 'saved_class_detail') {
    header('Content-Type: application/json; charset=utf-8');

    $classSemesterId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($classSemesterId <= 0) {
        json_err('missing_id', 400);
    }

    // Get class details
    $stClass = $pdo->prepare("
        SELECT css.*, c.name AS class_name 
        FROM class_semester_scores css
        JOIN classes c ON c.id = css.class_id
        WHERE css.id = ?
    ");
    $stClass->execute([$classSemesterId]);
    $classData = $stClass->fetch(PDO::FETCH_ASSOC);

    if (!$classData) {
        json_err('not_found', 404);
    }

    $classData['fee_scores'] = json_decode($classData['fee_scores'], true);
    $classData['campaign_scores'] = json_decode($classData['campaign_scores'], true);

    // Get members scores
    $stMembers = $pdo->prepare("
        SELECT mss.*, m.mssv, COALESCE(m.fullname, u.fullname, u.username) AS fullname, u.username
        FROM member_semester_scores mss
        JOIN members m ON m.id = mss.member_id
        JOIN users u ON u.id = mss.user_id
        WHERE mss.school_year_id = ? AND mss.semester_code = ? AND mss.class_id = ?
        ORDER BY m.fullname ASC, u.username ASC
    ");
    $stMembers->execute([
        $classData['school_year_id'],
        $classData['semester_code'],
        $classData['class_id']
    ]);
    $membersScores = $stMembers->fetchAll(PDO::FETCH_ASSOC);

    foreach ($membersScores as &$m) {
        $m['fee_scores'] = json_decode($m['fee_scores'], true);
        $m['campaign_scores'] = json_decode($m['campaign_scores'], true);
    }

    json_ok([
        'class_score' => $classData,
        'members_scores' => $membersScores
    ]);
}

// delete_saved_semester
if ($action === 'delete_saved_semester') {
    if (!is_admin() && !can('scoring', 'delete')) {
        json_err('Forbidden', 403);
    }

    header('Content-Type: application/json; charset=utf-8');

    $schoolYearId = (int) ($_POST['school_year'] ?? 0);
    $semesterCode = trim((string) ($_POST['semester'] ?? ''));

    if ($schoolYearId <= 0 || $semesterCode === '') {
        json_err('missing_parameters', 400);
    }

    $pdo->beginTransaction();
    try {
        $st1 = $pdo->prepare("DELETE FROM class_semester_scores WHERE school_year_id = ? AND semester_code = ?");
        $st1->execute([$schoolYearId, $semesterCode]);

        $st2 = $pdo->prepare("DELETE FROM member_semester_scores WHERE school_year_id = ? AND semester_code = ?");
        $st2->execute([$schoolYearId, $semesterCode]);

        $pdo->commit();
        json_ok('Deleted successfully');
    } catch (Throwable $t) {
        $pdo->rollBack();
        json_err('Delete failed: ' . $t->getMessage(), 500);
    }
}
