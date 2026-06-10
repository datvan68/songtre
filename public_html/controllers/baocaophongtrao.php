<?php
// Ensure consistent session/cookie handling for direct AJAX calls to controller (same as index.php)
require __DIR__ . '/../config/security.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';
require __DIR__ . '/../config/google-client.php';

use Google\Service\Drive;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

header('Content-Type: application/json; charset=utf-8');
if (ob_get_length()) ob_clean();

$user = auth_user();
$userId = $user['id'] ?? 0;
$userName = $user['fullname'] ?? $user['username'] ?? 'Bạn';

if ($userId <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Vui lòng đăng nhập để thực hiện thao tác này'], JSON_UNESCAPED_UNICODE);
  exit;
}

function forbidden() {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Bạn không có quyền thực hiện thao tác này'], JSON_UNESCAPED_UNICODE);
  exit;
}

function json_ok($data = []) {
  echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
  exit;
}
function json_err($msg, $code = 400) {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

// Auto create table
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS phong_trao_reports (
      id INT AUTO_INCREMENT PRIMARY KEY,
      campaign_id INT NOT NULL,
      user_id INT NOT NULL,
      activity_date DATE NOT NULL,
      participants INT DEFAULT 0,
      location VARCHAR(255) DEFAULT '',
      description TEXT,
      photos JSON NULL,
      status ENUM('pending','approved','rejected') DEFAULT 'pending',
      submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      reviewed_by INT NULL,
      review_note TEXT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX (campaign_id),
      INDEX (user_id),
      INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  // ignore
}

// Auto setup permission 'baocaophongtrao'
try {
  $findParent = $pdo->query("
    SELECT parent_id FROM permissions 
    WHERE code IN ('campaigns', 'duty', 'attend_list') 
      AND parent_id IS NOT NULL 
    LIMIT 1
  ");
  $parentId = $findParent->fetchColumn();
  if (!$parentId) {
    $parentId = null;
  }

  $checkPerm = $pdo->prepare("SELECT id FROM permissions WHERE code = 'baocaophongtrao' LIMIT 1");
  $checkPerm->execute();
  $permId = $checkPerm->fetchColumn();

  if (!$permId) {
    $stmt = $pdo->prepare("
      INSERT INTO permissions (code, name, grp, sort_order, parent_id)
      VALUES ('baocaophongtrao', 'Báo cáo phong trào', 'Hoạt động', 50, ?)
    ");
    $stmt->execute([$parentId]);
    $permId = (int) $pdo->lastInsertId();
  } else {
    $permId = (int) $permId;
    $stmt = $pdo->prepare("
      UPDATE permissions 
      SET parent_id = ?, grp = 'Hoạt động' 
      WHERE id = ?
    ");
    $stmt->execute([$parentId, $permId]);
  }

  // Grant full rights to admin roles
  $pdo->prepare("
    INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
    SELECT id, ?, 1, 1, 1, 1, 1, 1
    FROM roles
    WHERE name LIKE '%admin%' OR id IN (1, 2)
    ON DUPLICATE KEY UPDATE
      can_view = VALUES(can_view),
      can_create = VALUES(can_create),
      can_update = VALUES(can_update),
      can_review = VALUES(can_review),
      can_delete = VALUES(can_delete),
      can_print = VALUES(can_print)
  ")->execute([$permId]);

  $pdo->prepare("
    INSERT INTO user_permissions (user_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
    SELECT u.id, ?, 1, 1, 1, 1, 1, 1
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.name LIKE '%admin%' OR r.id IN (1, 2)
    ON DUPLICATE KEY UPDATE
      can_view = VALUES(can_view),
      can_create = VALUES(can_create),
      can_update = VALUES(can_update),
      can_review = VALUES(can_review),
      can_delete = VALUES(can_delete),
      can_print = VALUES(can_print)
  ")->execute([$permId]);

  // Grant view + create to ALL other roles so regular users can see "Phong trào đang diễn ra" list and submit reports
  $pdo->prepare("
    INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
    SELECT id, ?, 1, 1, 0, 0, 0, 0
    FROM roles
    WHERE name NOT LIKE '%admin%'
    ON DUPLICATE KEY UPDATE
      can_view = VALUES(can_view),
      can_create = VALUES(can_create),
      can_update = VALUES(can_update),
      can_review = VALUES(can_review),
      can_delete = VALUES(can_delete),
      can_print = VALUES(can_print)
  ")->execute([$permId]);

  $pdo->prepare("
    INSERT INTO user_permissions (user_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
    SELECT u.id, ?, 1, 1, 0, 0, 0, 0
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.name NOT LIKE '%admin%'
    ON DUPLICATE KEY UPDATE
      can_view = VALUES(can_view),
      can_create = VALUES(can_create),
      can_update = VALUES(can_update),
      can_review = VALUES(can_review),
      can_delete = VALUES(can_delete),
      can_print = VALUES(can_print)
  ")->execute([$permId]);
} catch (Throwable $pErr) {
  // ignore
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'export_xlsx') {
    // special: output xlsx, not json
    if (!can('baocaophongtrao', 'view')) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    // build same filters as list, but no page limit
    $kw = trim($_GET['kw'] ?? '');
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    $st = trim($_GET['status'] ?? '');
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';

    $where = '1=1';
    $params = [];
    if ($kw) {
        $where .= " AND (c.title LIKE ? OR r.location LIKE ? OR r.description LIKE ?)";
        $kwLike = "%$kw%";
        $params[] = $kwLike; $params[] = $kwLike; $params[] = $kwLike;
    }
    if ($campaignId > 0) { $where .= " AND r.campaign_id = ?"; $params[] = $campaignId; }
    if ($st) { $where .= " AND r.status = ?"; $params[] = $st; }
    if ($from) { $where .= " AND r.activity_date >= ?"; $params[] = $from; }
    if ($to) { $where .= " AND r.activity_date <= ?"; $params[] = $to; }

    $sql = "
        SELECT r.id, c.title as movement_name, 
               COALESCE(u.fullname, u.username) as submitter_name,
               r.activity_date, r.participants, r.location, r.description,
               r.status, r.submitted_at, r.review_note
        FROM phong_trao_reports r 
        LEFT JOIN campaigns c ON c.id = r.campaign_id 
        LEFT JOIN users u ON u.id = r.user_id
        WHERE $where 
        ORDER BY r.submitted_at DESC 
        LIMIT 5000
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Bao cao phong trao');

    // title
    $sheet->setCellValue('A1', 'BÁO CÁO HOẠT ĐỘNG PHONG TRÀO');
    $sheet->mergeCells('A1:I1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->setCellValue('A2', 'Ngày xuất: ' . date('d/m/Y H:i'));
    $sheet->mergeCells('A2:I2');

    // headers row 4
    $headers = ['ID', 'Phong trào', 'Người gửi', 'Ngày hoạt động', 'SL tham gia', 'Địa điểm', 'Trạng thái', 'Ngày gửi', 'Ghi chú duyệt'];
    $col = 1;
    foreach ($headers as $h) {
        $cell = $sheet->getCellByColumnAndRow($col, 4);
        $cell->setValue($h);
        $col++;
    }
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ];
    $sheet->getStyle('A4:I4')->applyFromArray($headerStyle);

    // data
    $rowNum = 5;
    $statusMap = ['pending'=>'Đang chờ','approved'=>'Đã duyệt','rejected'=>'Từ chối'];
    foreach ($rows as $r) {
        $sheet->setCellValue("A{$rowNum}", (int)$r['id']);
        $sheet->setCellValue("B{$rowNum}", (string)($r['movement_name'] ?? ''));
        $sheet->setCellValue("C{$rowNum}", (string)($r['submitter_name'] ?? ''));
        $sheet->setCellValue("D{$rowNum}", (string)($r['activity_date'] ?? ''));
        $sheet->setCellValue("E{$rowNum}", (int)($r['participants'] ?? 0));
        $sheet->setCellValue("F{$rowNum}", (string)($r['location'] ?? ''));
        $sheet->setCellValue("G{$rowNum}", $statusMap[$r['status']] ?? $r['status']);
        $sheet->setCellValue("H{$rowNum}", (string)($r['submitted_at'] ?? ''));
        $sheet->setCellValue("I{$rowNum}", (string)($r['review_note'] ?? ''));
        $rowNum++;
    }

    $lastRow = $rowNum - 1;
    if ($lastRow >= 5) {
        $sheet->getStyle("A5:I{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]
        ]);
    }

    // widths
    $sheet->getColumnDimension('A')->setWidth(8);
    $sheet->getColumnDimension('B')->setWidth(30);
    $sheet->getColumnDimension('C')->setWidth(20);
    $sheet->getColumnDimension('D')->setWidth(14);
    $sheet->getColumnDimension('E')->setWidth(12);
    $sheet->getColumnDimension('F')->setWidth(22);
    $sheet->getColumnDimension('G')->setWidth(12);
    $sheet->getColumnDimension('H')->setWidth(18);
    $sheet->getColumnDimension('I')->setWidth(30);

    if (function_exists('log_activity')) {
        log_activity('export', 'phong_trao_reports', 'Báo cáo phong trào', null, 'Xuất Excel báo cáo');
    }

    // output
    while (ob_get_level() > 0) ob_end_clean();
    header_remove();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="baocao_phongtrao_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// Helper functions for Drive (copied/adapted from achievements)
function get_root_drive_folder_id(PDO $pdo): string
{
  static $cache = null;
  if ($cache !== null) return $cache;

  $st = $pdo->prepare("SELECT val FROM app_settings WHERE `key` = 'gdrive_folder_id' LIMIT 1");
  $st->execute();
  $val = $st->fetchColumn();
  $cache = $val ? (string)$val : '';
  return $cache;
}

function ensure_drive_subfolder(string $parentFolderId, string $subName): string
{
  if ($parentFolderId === '') return '';

  $drive = new Drive(getGoogleClient());

  // check if exists
  $q = "mimeType='application/vnd.google-apps.folder' and name='{$subName}' and '{$parentFolderId}' in parents and trashed=false";
  $files = $drive->files->listFiles([
    'q' => $q,
    'fields' => 'files(id, name)',
    'supportsAllDrives' => true,
    'includeItemsFromAllDrives' => true,
  ]);
  if (count($files->getFiles()) > 0) {
    return $files->getFiles()[0]->getId();
  }

  // create
  $fileMetadata = new Google\Service\Drive\DriveFile([
    'name' => $subName,
    'mimeType' => 'application/vnd.google-apps.folder',
    'parents' => [$parentFolderId]
  ]);
  $folder = $drive->files->create($fileMetadata, [
    'fields' => 'id',
    'supportsAllDrives' => true,
  ]);
  return $folder->getId();
}

function get_phongtrao_drive_folder_id(PDO $pdo): string
{
  $root = get_root_drive_folder_id($pdo);
  if ($root === '') return '';
  return ensure_drive_subfolder($root, 'Minh chứng báo cáo phong trào');
}

function upload_phongtrao_to_drive(PDO $pdo, string $tmpPath, string $fileName): array
{
  $drive = new Drive(getGoogleClient());

  $folderId = get_phongtrao_drive_folder_id($pdo);
  if ($folderId === '') {
    throw new Exception('Chưa cấu hình Google Drive folder (app_settings:gdrive_folder_id).');
  }

  $mime = @mime_content_type($tmpPath) ?: 'application/octet-stream';
  $finalName = $fileName;
  $finalName = trim((string) $finalName) ?: 'file';

  $ext = pathinfo($finalName, PATHINFO_EXTENSION);
  $base = $ext ? substr($finalName, 0, -(strlen($ext) + 1)) : $finalName;

  $suffix = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
  $finalName = $ext ? "{$base}_{$suffix}.{$ext}" : "{$base}_{$suffix}";

  $fileMetadata = new Google\Service\Drive\DriveFile([
    'name' => $finalName,
    'parents' => [$folderId]
  ]);

  $file = $drive->files->create($fileMetadata, [
    'data' => file_get_contents($tmpPath),
    'mimeType' => $mime,
    'uploadType' => 'multipart',
    'fields' => 'id, webViewLink',
    'supportsAllDrives' => true
  ]);

  // public read
  try {
    $drive->permissions->create(
      $file->id,
      new Google\Service\Drive\Permission(['type' => 'anyone', 'role' => 'reader']),
      ['supportsAllDrives' => true]
    );
  } catch (Throwable $e) {}

  return [
    'id' => $file->id,
    'webViewLink' => $file->webViewLink ?? '',
    'name' => $finalName,
  ];
}



if ($action === 'list_movements') {
  try {
    if (!can('baocaophongtrao', 'view') && !can('baocaophongtrao', 'create')) forbidden();
    // list active/suitable campaigns for reporting (prefer recent/active)
    $stmt = $pdo->query("
      SELECT id, title as name, status, end_date as deadline 
      FROM campaigns 
      WHERE (end_date IS NULL OR end_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY))
      ORDER BY 
        CASE WHEN status IN ('active','hidden') THEN 0 ELSE 1 END,
        start_date DESC 
      -- Load ALL matching (recent) campaigns from DB; UI will limit visible display to ~5 with internal scroll
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $today = date('Y-m-d');
    foreach ($rows as &$r) {
      $r['id'] = (int)$r['id'];
      $rawEnd = $r['deadline'] ?? null;
      $r['deadline'] = $rawEnd ? date('d/m/Y', strtotime($rawEnd)) : '';
      if (in_array($r['status'], ['active','hidden'])) {
        $r['status'] = 'Đang diễn ra';
      } elseif ($rawEnd && $rawEnd < $today) {
        $r['status'] = 'Đã kết thúc';
      } else {
        $r['status'] = 'Sắp kết thúc';
      }
    }
    json_ok(['movements' => $rows]);
  } catch (Throwable $e) {
    json_err('Lỗi tải phong trào: ' . $e->getMessage(), 500);
  }
}

if ($action === 'submit') {
  if (!can('baocaophongtrao', 'create')) forbidden();
  $campaignId = (int)($_POST['campaign_id'] ?? 0);
  $activityDate = $_POST['activity_date'] ?? '';
  $participants = (int)($_POST['participants'] ?? 0);
  $location = trim($_POST['location'] ?? '');
  $description = trim($_POST['description'] ?? '');

  if ($campaignId <= 0 || !$activityDate || !$description) {
    json_err('Vui lòng chọn phong trào, ngày và mô tả hoạt động');
  }
  if ($participants < 0) $participants = 0;
  if ($participants > 10000) json_err('Số lượng tham gia không hợp lệ');
  if (strlen($description) < 5) json_err('Nội dung hoạt động quá ngắn');
  if (strlen($description) > 5000) json_err('Nội dung quá dài');
  // basic date sanity
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $activityDate)) json_err('Ngày hoạt động không hợp lệ');
  $photoCount = 0;
  if (!empty($_FILES['photos']['name'][0])) {
    foreach ($_FILES['photos']['error'] as $e) { if ($e === UPLOAD_ERR_OK) $photoCount++; }
  }
  if ($photoCount > 5) json_err('Tối đa 5 ảnh');

  // handle photos upload -> Google Drive (like achievements)
  $photos = []; // array of ['id'=>, 'url'=>, 'name'=> ]
  if (!empty($_FILES['photos']['name'][0])) {
    $files = $_FILES['photos'];
    $n = count($files['name']);
    $maxPhotos = 5;
    $countUploaded = 0;
    for ($i = 0; $i < $n && $countUploaded < $maxPhotos; $i++) {
      if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
      $tmp = $files['tmp_name'][$i];
      if (!$tmp || !is_uploaded_file($tmp)) continue;
      $orig = (string)($files['name'][$i] ?? 'photo.jpg');
      // basic size limit 5MB
      if (($files['size'][$i] ?? 0) > 5*1024*1024) continue;
      try {
        $driveFile = upload_phongtrao_to_drive($pdo, $tmp, $orig); // {id, webViewLink, name}
        if (!empty($driveFile['id'])) {
          $photos[] = [
            'id' => $driveFile['id'],
            'url' => $driveFile['webViewLink'] ?? '',
            'name' => $driveFile['name'] ?? $orig
          ];
          $countUploaded++;
        }
      } catch (Throwable $ex) {
        // continue with others; log?
      }
    }
  }

  $photosJson = json_encode($photos, JSON_UNESCAPED_UNICODE);

  $stmt = $pdo->prepare("
    INSERT INTO phong_trao_reports 
    (campaign_id, user_id, activity_date, participants, location, description, photos, status, submitted_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
  ");
  $stmt->execute([$campaignId, $userId, $activityDate, $participants, $location, $description, $photosJson]);
  $newId = (int)$pdo->lastInsertId();

  log_activity('create', 'phong_trao_reports', 'Báo cáo phong trào', $newId, 'Gửi báo cáo hoạt động');

  json_ok(['id' => $newId]);
}

if ($action === 'list_my') {
  if (!can('baocaophongtrao', 'view') && !can('baocaophongtrao', 'create')) forbidden();
  $stmt = $pdo->prepare("
    SELECT r.*, c.title as movement_name 
    FROM phong_trao_reports r 
    LEFT JOIN campaigns c ON c.id = r.campaign_id 
    WHERE r.user_id = ? 
    ORDER BY r.submitted_at DESC
  ");
  $stmt->execute([$userId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['movementId'] = (int)$r['campaign_id'];
    $r['movement'] = $r['movement_name'] ?: 'Phong trào #' . $r['campaign_id'];
    $r['reporter'] = $userName;
    $r['activityDate'] = $r['activity_date'];
    $r['participants'] = (int)$r['participants'];
    $r['photos'] = json_decode($r['photos'] ?? '[]', true) ?: [];
    $r['status'] = $r['status'];
    $r['submittedAt'] = $r['submitted_at'];
    $r['reviewNote'] = $r['review_note'] ?: '';
  }
  json_ok(['reports' => $rows]);
}

if ($action === 'list') {
  try {
    if (!can('baocaophongtrao', 'view')) forbidden();
    // for management, with filters + pagination
    $kw = trim($_GET['kw'] ?? '');
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    $st = trim($_GET['status'] ?? '');
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $ps = max(5, min(100, (int)($_GET['page_size'] ?? 10)));

    $where = '1=1';
    $params = [];

    if ($kw) {
      $where .= " AND (c.title LIKE ? OR r.location LIKE ? OR r.description LIKE ?)";
      $kwLike = "%$kw%";
      $params[] = $kwLike;
      $params[] = $kwLike;
      $params[] = $kwLike;
    }
    if ($campaignId > 0) {
      $where .= " AND r.campaign_id = ?";
      $params[] = $campaignId;
    }
    if ($st) {
      $where .= " AND r.status = ?";
      $params[] = $st;
    }
    if ($from) {
      $where .= " AND r.activity_date >= ?";
      $params[] = $from;
    }
    if ($to) {
      $where .= " AND r.activity_date <= ?";
      $params[] = $to;
    }

    // count
    $countSql = "
      SELECT COUNT(*) 
      FROM phong_trao_reports r 
      LEFT JOIN campaigns c ON c.id = r.campaign_id 
      WHERE $where
    ";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $ps;
    $sql = "
      SELECT r.*, c.title as movement_name, 
             COALESCE(u.fullname, u.username) as submitter_name
      FROM phong_trao_reports r 
      LEFT JOIN campaigns c ON c.id = r.campaign_id 
      LEFT JOIN users u ON u.id = r.user_id
      WHERE $where 
      ORDER BY r.submitted_at DESC 
      LIMIT $ps OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
      $r['id'] = (int)$r['id'];
      $r['movementId'] = (int)$r['campaign_id'];
      $r['movement'] = $r['movement_name'] ?: 'Phong trào #' . $r['campaign_id'];
      $r['reporter'] = $r['submitter_name'] ?: ('User#' . ($r['user_id'] ?? ''));
      $r['activityDate'] = $r['activity_date'];
      $r['participants'] = (int)$r['participants'];
      $r['photos'] = json_decode($r['photos'] ?? '[]', true) ?: [];
      $r['submittedAt'] = $r['submitted_at'];
      $r['reviewNote'] = $r['review_note'] ?: '';
    }

    json_ok([
      'data' => $rows,
      'total' => $total,
      'page' => $page,
      'page_size' => $ps,
      'total_pages' => max(1, ceil($total / $ps))
    ]);
  } catch (Throwable $e) {
    json_err('Lỗi tải danh sách quản lý: ' . $e->getMessage(), 500);
  }
}

if ($action === 'approve') {
  if (!can('baocaophongtrao', 'approve')) forbidden();
  $id = (int)($_POST['id'] ?? 0);
  $note = trim($_POST['note'] ?? '');
  if ($id <= 0) json_err('Invalid id');

  $pdo->prepare("UPDATE phong_trao_reports SET status='approved', reviewed_by=?, review_note=? WHERE id=?")
      ->execute([$userId, $note, $id]);
  log_activity('approve', 'phong_trao_reports', 'Báo cáo phong trào', $id, 'Duyệt báo cáo');
  json_ok();
}

if ($action === 'reject') {
  if (!can('baocaophongtrao', 'approve')) forbidden();
  $id = (int)($_POST['id'] ?? 0);
  $note = trim($_POST['note'] ?? '');
  if ($id <= 0) json_err('Invalid id');

  $pdo->prepare("UPDATE phong_trao_reports SET status='rejected', reviewed_by=?, review_note=? WHERE id=?")
      ->execute([$userId, $note, $id]);
  log_activity('reject', 'phong_trao_reports', 'Báo cáo phong trào', $id, 'Từ chối báo cáo');
  json_ok();
}

if ($action === 'delete') {
  if (!can('baocaophongtrao', 'delete')) forbidden();
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) json_err('Invalid id');

  $pdo->prepare("DELETE FROM phong_trao_reports WHERE id=?")->execute([$id]);
  log_activity('delete', 'phong_trao_reports', 'Báo cáo phong trào', $id, 'Xóa báo cáo');
  json_ok();
}

if ($action === 'seed') {
  if (!can('baocaophongtrao', 'create')) forbidden();
  // seed sample reports (demo only)
  $campIds = $pdo->query("SELECT id FROM campaigns ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
  if (empty($campIds)) {
    // create a temp campaign for demo (best effort; may need more fields depending on schema)
    try {
      $pdo->prepare("INSERT INTO campaigns (title, status, start_date, end_date) VALUES ('Phong trào mẫu seed', 'active', CURDATE()-INTERVAL 10 DAY, CURDATE()+INTERVAL 5 DAY)")->execute();
      $newC = (int)$pdo->lastInsertId();
      if ($newC > 0) $campIds = [$newC];
    } catch (Throwable $e) {
      // ignore, will have no camps
    }
  }
  $c1 = $campIds[0] ?? 0;
  $c2 = $campIds[1] ?? $c1;
  $c3 = $campIds[2] ?? $c1;

  $samples = [
    [$c1, $userId, date('Y-m-d', strtotime('-3 day')), 25, 'Công viên 23/9', 'Hoạt động nhặt rác và tuyên truyền môi trường.', 'approved', 'Tốt, đầy đủ'],
    [$c2, $userId, date('Y-m-d', strtotime('-1 day')), 12, 'Trường THCS', 'Tổ chức talkshow chia sẻ kinh nghiệm.', 'pending', ''],
    [$c3, $userId, date('Y-m-d', strtotime('-7 day')), 40, 'Hội trường', 'Lễ ra quân chiến dịch hè.', 'approved', ''],
    [$c1, $userId, date('Y-m-d', strtotime('-5 day')), 8, 'Online', 'Họp online chuẩn bị.', 'rejected', 'Thiếu minh chứng rõ ràng'],
  ];
  $ins = 0;
  foreach ($samples as $s) {
    if (($s[0] ?? 0) <= 0) continue; // skip if no valid campaign
    // avoid exact dups by checking recent similar
    $chk = $pdo->prepare("SELECT 1 FROM phong_trao_reports WHERE user_id=? AND campaign_id=? AND activity_date=? LIMIT 1");
    $chk->execute([$s[1], $s[0], $s[2]]);
    if ($chk->fetchColumn()) continue;
    try {
      $pdo->prepare("INSERT INTO phong_trao_reports (campaign_id,user_id,activity_date,participants,location,description,status,review_note,submitted_at) VALUES (?,?,?,?,?,?,?,? , NOW() - INTERVAL 1 HOUR)")
          ->execute($s);
      $ins++;
    } catch (Throwable $e) {}
  }
  json_ok(['seeded' => $ins]);
}

json_err('Unknown action');
