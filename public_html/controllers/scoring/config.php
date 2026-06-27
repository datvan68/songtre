<?php
declare(strict_types=1);

// config.php
if ($action === 'scoring_items') {
    header('Content-Type: application/json; charset=utf-8');

    $schoolYearId = (int) ($_GET['school_year'] ?? 0);
    $semesterCode = trim((string) ($_GET['semester'] ?? ''));

    if ($schoolYearId <= 0 || $semesterCode === '') {
        json_err('missing_filters', 400);
    }

    // campaigns
    $sqlCam = "
        SELECT 
            MAX(cam.id) as id, 
            cam.title
        FROM campaigns cam
        WHERE (cam.status <> 'hidden' OR cam.status IS NULL)
          AND cam.school_year_id = :sy
          AND TRIM(cam.semester_code) = :sem
        GROUP BY cam.title
        ORDER BY MAX(cam.start_date) ASC, MAX(cam.id) ASC
    ";
    $stCam = $pdo->prepare($sqlCam);
    $stCam->execute([':sy' => $schoolYearId, ':sem' => $semesterCode]);
    $campaigns = $stCam->fetchAll(PDO::FETCH_ASSOC);

    // fees
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

    $sqlFee .= " GROUP BY ft.item_name ORDER BY MAX(ft.created_at) DESC, MAX(ft.id) DESC";
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
