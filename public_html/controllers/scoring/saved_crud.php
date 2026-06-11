<?php
declare(strict_types=1);

if ($action === 'update_saved_class_score') {
    if (!is_admin() && !can('scoring', 'update')) {
        json_err('Forbidden', 403);
    }

    header('Content-Type: application/json; charset=utf-8');

    $id = (int) ($_POST['id'] ?? 0);
    $feeScoresRaw = $_POST['fee_scores'] ?? '';
    $campaignScoresRaw = $_POST['campaign_scores'] ?? '';
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($id <= 0) {
        json_err('missing_id', 400);
    }

    // 1. Validate record exists
    $stSelect = $pdo->prepare("SELECT * FROM class_semester_scores WHERE id = ?");
    $stSelect->execute([$id]);
    $record = $stSelect->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        json_err('Record not found', 404);
    }

    $feeScores = is_string($feeScoresRaw) ? json_decode($feeScoresRaw, true) : $feeScoresRaw;
    $campaignScores = is_string($campaignScoresRaw) ? json_decode($campaignScoresRaw, true) : $campaignScoresRaw;

    if (!is_array($feeScores)) {
        $feeScores = json_decode($record['fee_scores'] ?? '{}', true) ?: [];
    }
    if (!is_array($campaignScores)) {
        $campaignScores = json_decode($record['campaign_scores'] ?? '{}', true) ?: [];
    }

    // 2. Re-calculate total_score = sum(fee earned) + sum(campaign earned)
    $totalScore = 0.0;
    foreach ($feeScores as $fee) {
        $totalScore += (float) ($fee['earned'] ?? 0.0);
    }
    foreach ($campaignScores as $camp) {
        $totalScore += (float) ($camp['earned'] ?? 0.0);
    }

    $totalScore = round($totalScore, 2);
    $totalMaxPoint = 10.0;
    $performanceRate = ($totalMaxPoint > 0) ? ($totalScore / $totalMaxPoint) : 0.0;
    if ($performanceRate > 1) {
        $performanceRate = 1.0;
    }

    // 3. Update class_semester_scores
    $stUpdate = $pdo->prepare("
        UPDATE class_semester_scores 
        SET fee_scores = ?, campaign_scores = ?, total_score = ?, performance_rate = ?, note = ? 
        WHERE id = ?
    ");

    $stUpdate->execute([
        json_encode($feeScores, JSON_UNESCAPED_UNICODE),
        json_encode($campaignScores, JSON_UNESCAPED_UNICODE),
        $totalScore,
        $performanceRate,
        $note,
        $id
    ]);

    json_ok([
        'message' => 'Updated successfully',
        'total_score' => $totalScore,
        'performance_rate' => $performanceRate
    ]);
}

if ($action === 'delete_saved_class_score') {
    if (!is_admin() && !can('scoring', 'delete')) {
        json_err('Forbidden', 403);
    }

    header('Content-Type: application/json; charset=utf-8');

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        json_err('missing_id', 400);
    }

    // 1. Get class details from the score record
    $stSelect = $pdo->prepare("SELECT school_year_id, semester_code, class_id FROM class_semester_scores WHERE id = ?");
    $stSelect->execute([$id]);
    $record = $stSelect->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        json_err('Record not found', 404);
    }

    $schoolYearId = (int) $record['school_year_id'];
    $semesterCode = $record['semester_code'];
    $classId = (int) $record['class_id'];

    $pdo->beginTransaction();
    try {
        // 2. Delete class score
        $stDelClass = $pdo->prepare("DELETE FROM class_semester_scores WHERE id = ?");
        $stDelClass->execute([$id]);

        // 3. Delete student scores for this class in this school year and semester
        $stDelMember = $pdo->prepare("
            DELETE FROM member_semester_scores 
            WHERE school_year_id = ? AND semester_code = ? AND class_id = ?
        ");
        $stDelMember->execute([$schoolYearId, $semesterCode, $classId]);

        $pdo->commit();
        json_ok('Deleted successfully');
    } catch (Throwable $t) {
        $pdo->rollBack();
        json_err('Delete failed: ' . $t->getMessage(), 500);
    }
}
