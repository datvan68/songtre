<?php
// controllers/statistics/members.php

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$ROOT = dirname(__DIR__, 2);

require_once $ROOT . '/config/auth.php';
require_once $ROOT . '/config/db.php';

auth_guard();

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$action = trim($action);

function json_ok($arr = array())
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(array('ok' => true), $arr), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'message' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}
function forbidden()
{
    json_err('Forbidden', 403);
}

if (function_exists('can')) {
    if (!can('statistics', 'view')) forbidden();
}

function table_exists(PDO $pdo, $table)
{
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $st->execute(array($table));
    return (int) $st->fetchColumn() > 0;
}

function column_exists(PDO $pdo, $table, $column)
{
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
    ");
    $st->execute(array($table, $column));
    return (int) $st->fetchColumn() > 0;
}

function first_existing_column(PDO $pdo, $table, $candidates)
{
    foreach ($candidates as $c) {
        if (column_exists($pdo, $table, $c)) return $c;
    }
    return '';
}

function dept_label_sql()
{
    return "
      CASE
        WHEN d.type='khoa'  THEN (CASE WHEN d.name REGEXP '^(Khoa)[[:space:]]'  THEN d.name ELSE CONCAT('Khoa ', d.name) END)
        WHEN d.type='phong' THEN (CASE WHEN d.name REGEXP '^(Phòng)[[:space:]]' THEN d.name ELSE CONCAT('Phòng ', d.name) END)
        ELSE d.name
      END
    ";
}

function member_type_case()
{
    return "
      LOWER(CAST(m.type AS CHAR)) IN (
        'member','doanvien','doan_vien','dv','doan-vien','doan vien','đoàn viên','doan'
      )
    ";
}

function youth_type_case()
{
    return "
      LOWER(CAST(m.type AS CHAR)) IN (
        'youth','thanhnien','thanh_nien','tn','thanh-nien','thanh nien','thanh'
      )
    ";
}

if ($action === 'members_insights') {
    try {
        // Detect columns
        $lockedCol = first_existing_column($pdo, 'members', array('is_locked', 'locked'));
        $stopCol   = first_existing_column($pdo, 'members', array('stop_follow', 'is_stopped', 'stopped'));
        $hasType   = column_exists($pdo, 'members', 'type');

        // course detection (không hardcode crs.code)
        $courseExpr = "";
        $courseJoin = "";
        $courseEnabled = 0;

        if (column_exists($pdo, 'classes', 'course_id') && table_exists($pdo, 'courses')) {
            $courseJoin = "LEFT JOIN courses crs ON crs.id = c.course_id";

            $courseLabelCol = first_existing_column($pdo, 'courses', array('name', 'year_label', 'label', 'title', 'code'));
            if ($courseLabelCol !== '') {
                $courseExpr = "COALESCE(NULLIF(crs.`{$courseLabelCol}`,''), CONCAT('Khóa ', crs.id))";
            } else {
                $courseExpr = "CONCAT('Khóa ', crs.id)";
            }
            $courseEnabled = 1;

        } elseif (column_exists($pdo, 'classes', 'course')) {
            $courseExpr = "NULLIF(c.`course`,'')";
            $courseEnabled = 1;

        } elseif (column_exists($pdo, 'classes', 'course_name')) {
            $courseExpr = "NULLIF(c.`course_name`,'')";
            $courseEnabled = 1;

        } elseif (column_exists($pdo, 'members', 'course')) {
            $courseExpr = "NULLIF(m.`course`,'')";
            $courseEnabled = 1;
        }

        $deptLabel = dept_label_sql();

        // Aggregate expressions
        $exprLocked = $lockedCol ? "SUM(CASE WHEN m.`{$lockedCol}` = 1 THEN 1 ELSE 0 END)" : "0";
        $exprStop   = $stopCol   ? "SUM(CASE WHEN m.`{$stopCol}` = 1 THEN 1 ELSE 0 END)" : "0";

        $exprMemberCnt = $hasType ? ("SUM(CASE WHEN " . member_type_case() . " THEN 1 ELSE 0 END)") : "COUNT(m.id)";
        $exprYouthCnt  = $hasType ? ("SUM(CASE WHEN " . youth_type_case() . " THEN 1 ELSE 0 END)") : "0";

        // Meta totals
        $sqlMeta = "
          SELECT
            COUNT(*) AS total_people,
            {$exprMemberCnt} AS total_members,
            {$exprYouthCnt}  AS total_youth,
            {$exprLocked}    AS total_locked,
            {$exprStop}      AS total_stopped
          FROM members m
        ";
        $meta = $pdo->query($sqlMeta)->fetch(PDO::FETCH_ASSOC);
        if (!$meta) $meta = array();

        // Top classes
        $sqlTopClass = "
          SELECT
            c.id   AS class_id,
            c.name AS class_name,
            d.id   AS dept_id,
            {$deptLabel} AS dept_name,
            COUNT(m.id) AS total_people,
            {$exprMemberCnt} AS members_count,
            {$exprYouthCnt}  AS youth_count,
            {$exprLocked}    AS locked_count,
            {$exprStop}      AS stopped_count
          FROM classes c
          LEFT JOIN departments d ON d.id = c.department_id
          LEFT JOIN members m ON m.class_id = c.id
          GROUP BY c.id, c.name, d.id, d.name, d.type
          HAVING COUNT(m.id) > 0
          ORDER BY {$exprMemberCnt} DESC, COUNT(m.id) DESC, c.name ASC
          LIMIT 10
        ";
        $topClasses = $pdo->query($sqlTopClass)->fetchAll(PDO::FETCH_ASSOC);

        // Top departments
        $sqlTopDept = "
          SELECT
            d.id AS dept_id,
            {$deptLabel} AS dept_name,
            d.type AS dept_type,
            COUNT(m.id) AS total_people,
            {$exprMemberCnt} AS members_count,
            {$exprYouthCnt}  AS youth_count
          FROM departments d
          LEFT JOIN classes c ON c.department_id = d.id
          LEFT JOIN members m ON m.class_id = c.id
          GROUP BY d.id, d.name, d.type
          HAVING COUNT(m.id) > 0
          ORDER BY {$exprMemberCnt} DESC, COUNT(m.id) DESC, {$deptLabel} ASC
          LIMIT 10
        ";
        $topDepts = $pdo->query($sqlTopDept)->fetchAll(PDO::FETCH_ASSOC);

        // Course stats
        $courseRows = array();
        if ($courseExpr !== "") {
            $sqlCourse = "
              SELECT
                {$courseExpr} AS course_label,
                COUNT(m.id) AS total_people,
                {$exprMemberCnt} AS members_count,
                {$exprYouthCnt}  AS youth_count
              FROM classes c
              {$courseJoin}
              LEFT JOIN members m ON m.class_id = c.id
              GROUP BY course_label
              HAVING course_label <> '' AND COUNT(m.id) > 0
              ORDER BY {$exprMemberCnt} DESC, COUNT(m.id) DESC, course_label ASC
              LIMIT 20
            ";
            $st = $pdo->query($sqlCourse);
            $courseRows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        }

        // Locked/Stopped by class (TOP vấn đề)
        // IMPORTANT: không dùng (locked_count + stopped_count) vì alias aggregate trong biểu thức sẽ gây lỗi 1247 trên MySQL/MariaDB cũ.
        $sqlStatusByClass = "
          SELECT
            c.id   AS class_id,
            c.name AS class_name,
            COUNT(m.id) AS total_people,
            {$exprMemberCnt} AS members_count,
            {$exprLocked}    AS locked_count,
            {$exprStop}      AS stopped_count
          FROM classes c
          LEFT JOIN members m ON m.class_id = c.id
          GROUP BY c.id, c.name
          HAVING COUNT(m.id) > 0
          ORDER BY ({$exprLocked} + {$exprStop}) DESC,
                   {$exprLocked} DESC,
                   {$exprStop} DESC,
                   c.name ASC
          LIMIT 25
        ";
        $statusByClass = $pdo->query($sqlStatusByClass)->fetchAll(PDO::FETCH_ASSOC);

        json_ok(array(
            'meta' => array(
                'has_type' => $hasType ? 1 : 0,
                'locked_col' => $lockedCol,
                'stop_col' => $stopCol,
                'course_enabled' => $courseEnabled ? 1 : 0,
            ),
            'totals' => $meta,
            'top_classes' => $topClasses,
            'top_depts' => $topDepts,
            'course_stats' => $courseRows,
            'status_by_class' => $statusByClass,
        ));
    } catch (Throwable $e) {
        json_err("Không thể tải members_insights: " . $e->getMessage(), 500);
    }
}

json_err("Unknown action", 400);
