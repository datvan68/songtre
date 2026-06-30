<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Setup action variable to prevent controllers from running handler logic on import
$action = 'test';

require_once __DIR__ . '/../public_html/config/db.php';
require_once __DIR__ . '/../public_html/controllers/scoring/helpers.php';
require_once __DIR__ . '/../public_html/controllers/scoring/calculate.php';

echo "Starting campaign scoring verification test...\n";

try {
    $pdo->beginTransaction();

    $rand = time() . '_' . rand(1000, 9999);
    $deptName = 'TEST_KHOA_VERIFY_' . $rand;
    $courseName = 'TEST_COURSE_VERIFY_' . $rand;
    $className1 = 'TEST_CLASS_1_' . $rand;
    $className2 = 'TEST_CLASS_2_' . $rand;
    $className3 = 'TEST_CLASS_3_' . $rand;
    $user1Name = 'test_user_1_' . $rand;
    $user2Name = 'test_user_2_' . $rand;
    $user3Name = 'test_user_3_' . $rand;
    $mssv1 = 'M1_' . $rand;
    $mssv2 = 'M2_' . $rand;
    $mssv3 = 'M3_' . $rand;
    $mssv4 = 'M4_' . $rand;

    // 1. Insert temporary department
    $pdo->prepare("INSERT INTO departments (name, type) VALUES (?, 'khoa')")->execute([$deptName]);
    $deptId = (int)$pdo->lastInsertId();
    echo "Created temp department ID: $deptId ($deptName)\n";

    // 2. Insert temporary course
    $pdo->prepare("INSERT INTO courses (name, status) VALUES (?, 1)")->execute([$courseName]);
    $courseId = (int)$pdo->lastInsertId();
    echo "Created temp course ID: $courseId ($courseName)\n";

    // 3. Insert temporary classes
    $pdo->prepare("INSERT INTO classes (name, department_id, course_id, status) VALUES (?, ?, ?, 1)")->execute([$className1, $deptId, $courseId]);
    $class1Id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO classes (name, department_id, course_id, status) VALUES (?, ?, ?, 1)")->execute([$className2, $deptId, $courseId]);
    $class2Id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO classes (name, department_id, course_id, status) VALUES (?, ?, ?, 1)")->execute([$className3, $deptId, $courseId]);
    $class3Id = (int)$pdo->lastInsertId();
    echo "Created temp classes: $class1Id, $class2Id, $class3Id\n";

    // 4. Insert/Get role
    $roleId = $pdo->query("SELECT id FROM roles LIMIT 1")->fetchColumn();
    if (!$roleId) {
        $pdo->prepare("INSERT INTO roles (name) VALUES ('TEST_ROLE')")->execute();
        $roleId = (int)$pdo->lastInsertId();
    }
    echo "Using role ID: $roleId\n";

    // 5. Insert temporary users
    $pdo->prepare("INSERT INTO users (username, password_hash, role_id) VALUES (?, 'hash', ?)")->execute([$user1Name, $roleId]);
    $user1Id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (username, password_hash, role_id) VALUES (?, 'hash', ?)")->execute([$user2Name, $roleId]);
    $user2Id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (username, password_hash, role_id) VALUES (?, 'hash', ?)")->execute([$user3Name, $roleId]);
    $user3Id = (int)$pdo->lastInsertId();
    echo "Created temp users: $user1Id, $user2Id, $user3Id\n";

    // 6. Insert temporary members
    // Class 1 has 1 student: user1
    $pdo->prepare("INSERT INTO members (user_id, mssv, fullname, department_id, course_id, class_id, class_name, type, stop_follow) VALUES (?, ?, 'Member 1', ?, ?, ?, ?, 'member', 0)")
        ->execute([$user1Id, $mssv1, $deptId, $courseId, $class1Id, $className1]);
    
    // Class 2 has 2 students: user2, user3
    $pdo->prepare("INSERT INTO members (user_id, mssv, fullname, department_id, course_id, class_id, class_name, type, stop_follow) VALUES (?, ?, 'Member 2', ?, ?, ?, ?, 'member', 0)")
        ->execute([$user2Id, $mssv2, $deptId, $courseId, $class2Id, $className2]);
    $pdo->prepare("INSERT INTO members (user_id, mssv, fullname, department_id, course_id, class_id, class_name, type, stop_follow) VALUES (?, ?, 'Member 3', ?, ?, ?, ?, 'member', 0)")
        ->execute([$user3Id, $mssv3, $deptId, $courseId, $class2Id, $className2]);

    // Class 3 has 1 student: not participating (user_id 999999, does not register)
    $pdo->prepare("INSERT INTO members (user_id, mssv, fullname, department_id, course_id, class_id, class_name, type, stop_follow) VALUES (999999, ?, 'Member 4', ?, ?, ?, ?, 'member', 0)")
        ->execute([$mssv4, $deptId, $courseId, $class3Id, $className3]);
    echo "Created temp members.\n";

    // 7. Insert temporary school year and semester
    $pdo->prepare("INSERT INTO school_years (year_label, is_active) VALUES ('2025-2026', 1)")->execute();
    $schoolYearId = (int)$pdo->lastInsertId();

    $semesterCode = 'HK_TEST_' . $rand;
    $pdo->prepare("INSERT INTO semesters (code, label) VALUES (?, 'Học kỳ test')")->execute([$semesterCode]);
    echo "Created temp school year ID: $schoolYearId, semester code: $semesterCode\n";

    // 8. Insert temporary campaign
    $pdo->prepare("INSERT INTO campaigns (code, title, school_year_id, school_year, semester_code, status) VALUES ('TEST_CAM_CODE', 'TEST_CAMPAIGN_1', ?, '2025-2026', ?, 'active')")->execute([$schoolYearId, $semesterCode]);
    $campaignId = (int)$pdo->lastInsertId();
    echo "Created temp campaign ID: $campaignId\n";

    // 9. Insert registrations for participants
    $pdo->prepare("INSERT INTO registrations (user_id, campaign_id, registered_at, status) VALUES (?, ?, NOW(), 'approved')")->execute([$user1Id, $campaignId]);
    $pdo->prepare("INSERT INTO registrations (user_id, campaign_id, registered_at, status) VALUES (?, ?, NOW(), 'approved')")->execute([$user2Id, $campaignId]);
    $pdo->prepare("INSERT INTO registrations (user_id, campaign_id, registered_at, status) VALUES (?, ?, NOW(), 'approved')")->execute([$user3Id, $campaignId]);
    echo "Created registrations.\n";

    // 10. Run recalculation of campaign results
    recalculate_unlocked_campaign_scores($pdo, [$campaignId]);
    echo "Recalculated campaign results in campaign_class_results.\n";

    // 11. Run calculate_all_classes_scores
    $pointsPayload = [
        'campaigns' => [
            $campaignId => 10.0
        ],
        'fees' => []
    ];
    $pagination = [
        'page' => 1,
        'limit' => 10,
        'search' => 'TEST_CLASS',
        'dept_name' => $deptName
    ];
    $calcResult = calculate_all_classes_scores($pdo, $schoolYearId, $semesterCode, $pointsPayload, $pagination);
    
    // 12. Check results
    $scores = $calcResult['classes_scores'] ?? [];
    
    $testClass1 = null;
    $testClass2 = null;
    $testClass3 = null;

    foreach ($scores as $s) {
        if ($s['class_id'] === $class1Id) $testClass1 = $s;
        if ($s['class_id'] === $class2Id) $testClass2 = $s;
        if ($s['class_id'] === $class3Id) $testClass3 = $s;
    }

    echo "\n=== VERIFICATION RESULTS ===\n";
    $success = true;

    // Test Case 1: Class with 1 participating student gets 100% (10.0 points)
    if ($testClass1) {
        $camScore1 = $testClass1['campaign_scores'][$campaignId] ?? null;
        if ($camScore1 && $camScore1['joined'] === 1 && $camScore1['earned'] == 10.0) {
            echo "PASS: Class 1 (1 student) joined = 1, earned = 10.0 / 10.0\n";
        } else {
            echo "FAIL: Class 1 (1 student) got joined = " . ($camScore1['joined'] ?? 'N/A') . ", earned = " . ($camScore1['earned'] ?? 'N/A') . "\n";
            $success = false;
        }
    } else {
        echo "FAIL: Class 1 results not found\n";
        $success = false;
    }

    // Test Case 2: Class with 2+ participating students gets the exact same score as 1 student
    if ($testClass2) {
        $camScore2 = $testClass2['campaign_scores'][$campaignId] ?? null;
        if ($camScore2 && $camScore2['joined'] === 2 && $camScore2['earned'] == 10.0) {
            echo "PASS: Class 2 (2 students) joined = 2, earned = 10.0 / 10.0\n";
        } else {
            echo "FAIL: Class 2 (2 students) got joined = " . ($camScore2['joined'] ?? 'N/A') . ", earned = " . ($camScore2['earned'] ?? 'N/A') . "\n";
            $success = false;
        }
    } else {
        echo "FAIL: Class 2 results not found\n";
        $success = false;
    }

    // Test Case 3: Class with 0 participating students gets 0 points
    if ($testClass3) {
        $camScore3 = $testClass3['campaign_scores'][$campaignId] ?? null;
        if ($camScore3 && $camScore3['joined'] === 0 && $camScore3['earned'] == 0.0) {
            echo "PASS: Class 3 (0 students) joined = 0, earned = 0.0 / 10.0\n";
        } else {
            echo "FAIL: Class 3 (0 students) got joined = " . ($camScore3['joined'] ?? 'N/A') . ", earned = " . ($camScore3['earned'] ?? 'N/A') . "\n";
            $success = false;
        }
    } else {
        echo "FAIL: Class 3 results not found\n";
        $success = false;
    }

    if ($success) {
        echo "\nSUCCESS: Campaign scoring binary model is verified successfully!\n";
    } else {
        echo "\nFAILURE: Some test cases failed.\n";
    }

} catch (Exception $e) {
    echo "Error encountered: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
        echo "Transaction rolled back. Database is clean.\n";
    }
}
