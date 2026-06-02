<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');
ob_clean();


$action = $_GET['action'] ?? '';

try {

    /* ===============================
       1️⃣ TOP THEO KHOA
    =============================== */
    if ($action === 'departments') {

        $sql = "
    SELECT 
        d.id AS dept_id,
        d.name AS dept_name,
        SUM(r.score) AS total_score
    FROM departments d
    LEFT JOIN members m ON m.department_id = d.id
    LEFT JOIN registrations r 
        ON r.user_id = m.user_id
       AND r.status IN ('good','excellent')
    WHERE d.type = 'khoa'
    GROUP BY d.id
    HAVING SUM(r.score) > 0
    ORDER BY total_score DESC
";


        $stm = $pdo->prepare($sql);
        $stm->execute();
        $rows = $stm->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $rows]);
        exit;
    }

    /* ===============================
       2️⃣ XẾP HẠNG CÁ NHÂN (mặc định)
    =============================== */
    if ($action === 'list') {

        $sql = "
    SELECT 
        m.id AS member_id,
        m.fullname,
        m.mssv,
        m.class_id,
        c.name AS classname,
        SUM(r.score) AS total_score
    FROM members m
    LEFT JOIN registrations r 
        ON r.user_id = m.user_id 
       AND r.status IN ('good','excellent')
    LEFT JOIN classes c ON c.id = m.class_id
    GROUP BY m.id
    HAVING SUM(r.score) > 0
    ORDER BY total_score DESC
";


        $stm = $pdo->prepare($sql);
        $stm->execute();
        $list = $stm->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $list]);
        exit;
    }

    throw new Exception("Invalid action");

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
