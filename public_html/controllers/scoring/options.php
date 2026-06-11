<?php
declare(strict_types=1);

// options.php
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

if ($action === 'semester_options') {
    header('Content-Type: application/json; charset=utf-8');

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

    if (!$rows) {
        $rows = [
            ['code' => 'HK1', 'label' => 'Học kỳ I'],
            ['code' => 'HK2', 'label' => 'Học kỳ II'],
        ];
    }

    json_ok($rows);
}
