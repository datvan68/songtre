<?php
require_once __DIR__ . '/public_html/config/db.php';

$class = $pdo->query("SELECT * FROM classes WHERE name = 'CD25A-CSSD'")->fetch();
if (!$class) {
    echo "Class not found\n";
    exit;
}

echo "Class ID: {$class['id']}\n";

$members = $pdo->query("SELECT id, fullname FROM members WHERE class_id = {$class['id']}")->fetchAll();
$memberIds = array_column($members, 'id');
echo "Members count: " . count($memberIds) . "\n";

if (count($memberIds) > 0) {
    $inMem = implode(',', $memberIds);
    $ftps = $pdo->query("
        SELECT ftp.transaction_id, COUNT(*) as count 
        FROM finance_transaction_participants ftp 
        WHERE ftp.member_id IN ($inMem)
        GROUP BY ftp.transaction_id
    ")->fetchAll();
    
    echo "Transactions for this class:\n";
    print_r($ftps);
    
    foreach ($ftps as $ftp) {
        $tx = $pdo->query("SELECT id, item_name, trans_date, school_year_id, semester, created_at FROM finance_transactions WHERE id = {$ftp['transaction_id']}")->fetch();
        print_r($tx);
    }
}
