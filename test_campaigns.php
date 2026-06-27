<?php
require 'public_html/config/db.php';

$stmt = $pdo->query("
    SELECT c.title, c.id, ccr.class_id, ccr.joined_quantity, ccr.score 
    FROM campaign_class_results ccr 
    JOIN campaigns c ON c.id = ccr.campaign_id
    LIMIT 20
");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($results);

$stmt2 = $pdo->query("
    SELECT COUNT(*) as reg_count FROM registrations
");
print_r($stmt2->fetch());

$stmt3 = $pdo->query("
    SELECT COUNT(*) as att_count FROM attendance_logs WHERE result='ok'
");
print_r($stmt3->fetch());
