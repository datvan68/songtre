<?php
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/google-client.php';
require_once __DIR__ . '/../config/activity_log.php';
use Google\Service\Drive;



// ✅ chặn truy cập nếu chưa đăng nhập (giống achievements)
auth_guard();

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

/* =====================================================
   RESPONSE DEFAULT = JSON
   (riêng download_attachment sẽ override header)
===================================================== */
header('Content-Type: application/json; charset=utf-8');
if (ob_get_length())
  ob_clean();

/* =====================================================
   JSON HELPERS (để dùng json_err/json_ok giống achievements)
===================================================== */

if (!function_exists('json_ok')) {
  function json_ok(array $data = []): void
  {
    echo json_encode(array_merge(['ok' => 1], $data), JSON_UNESCAPED_UNICODE);
    exit;
  }
}
if (!function_exists('json_err')) {
  function json_err(string $msg, int $code = 400, array $extra = []): void
  {
    http_response_code($code);
    echo json_encode(array_merge(['ok' => 0, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* =====================================================
   PERMISSION HELPERS
===================================================== */
function forbidden(string $msg = 'FORBIDDEN')
{
  http_response_code(403);
  echo json_encode(['ok' => 0, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

function require_view_or_review(string $msg = 'NO_PERMISSION')
{
  if (function_exists('can')) {
    if (!can('nominations', 'view') && !can('nominations', 'review')) {
      forbidden($msg);
    }
  }
}

function current_user_id(): int
{
  return (int) ($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
}

/* =====================================================
   UPLOAD RULES
===================================================== */
const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xlsx'];
const ALLOWED_MIME = [
  'application/pdf',
  'image/jpeg',
  'image/png',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
];

/* =====================================================
   MIME HELPER - FIX .docx, .xlsx bị nhầm thành zip
===================================================== */
function get_upload_mime_type(string $filepath, string $originalName): string
{
  $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

  $mimeMap = [
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'doc' => 'application/msword',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xls' => 'application/vnd.ms-excel',
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
  ];

  if (isset($mimeMap[$ext])) {
    return $mimeMap[$ext];
  }

  // fallback
  $mime = @mime_content_type($filepath) ?: 'application/octet-stream';
  return $mime;
}

const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

/* =====================================================
   SETTINGS + BASE URL
===================================================== */
function setting_get(PDO $pdo, string $k, $default = null)
{
  $st = $pdo->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
  $st->execute([$k]);
  $v = $st->fetchColumn();
  return ($v === false || $v === null) ? $default : $v;
}

// Root folder đã cấu hình ở trang "Google Drive"
function get_root_drive_folder_id(PDO $pdo): string
{
  return trim((string) setting_get($pdo, 'gdrive_folder_id', ''));
}

// Tạo (nếu chưa có) folder con trong Shared Drive
function ensure_drive_subfolder(string $parentFolderId, string $subName): string
{
  $drive = new Drive(getGoogleClient());

  $nameEsc = str_replace("'", "\\'", $subName);
  $q = "mimeType='application/vnd.google-apps.folder'
        and name='{$nameEsc}'
        and '{$parentFolderId}' in parents
        and trashed=false";

  $list = $drive->files->listFiles([
    'q' => $q,
    'pageSize' => 1,
    'fields' => 'files(id,name)',
    'supportsAllDrives' => true,
    'includeItemsFromAllDrives' => true,
  ]);

  $files = $list->getFiles();
  if (!empty($files))
    return $files[0]->getId();

  $meta = new Google\Service\Drive\DriveFile([
    'name' => $subName,
    'mimeType' => 'application/vnd.google-apps.folder',
    'parents' => [$parentFolderId],
  ]);

  $created = $drive->files->create($meta, [
    'fields' => 'id,name',
    'supportsAllDrives' => true,
  ]);

  return $created->getId();
}

// Folder dùng cho nominations (tự tạo trong root)
function get_nominations_drive_folder_id(PDO $pdo): string
{
  $root = get_root_drive_folder_id($pdo);
  if ($root === '')
    return '';
  return ensure_drive_subfolder($root, 'Hồ sơ đề nghị thi đua');
}

/**
 * ✅ Lấy BASE_URL từ config/base_url.php
 * - hỗ trợ nhiều format (string | array)
 * - fallback: tự dựng từ request
 */
function get_base_url(): string
{
  static $base = null;
  if ($base !== null)
    return $base;

  $pathBaseUrl = __DIR__ . '/../config/base_url.php';
  if (is_file($pathBaseUrl)) {
    $cfg = require $pathBaseUrl;

    if (is_string($cfg) && trim($cfg) !== '') {
      return $base = rtrim(trim($cfg), '/');
    }
    if (is_array($cfg)) {
      if (!empty($cfg['base_url'])) {
        return $base = rtrim(trim((string) $cfg['base_url']), '/');
      }
      if (!empty($cfg['env']) && !empty($cfg['base_url'][$cfg['env']])) {
        return $base = rtrim(trim((string) $cfg['base_url'][$cfg['env']]), '/');
      }
      foreach (['prod', 'production', 'dev', 'local'] as $k) {
        if (!empty($cfg[$k]))
          return $base = rtrim(trim((string) $cfg[$k]), '/');
      }
    }
  }

  $pathApp = __DIR__ . '/../config/app.php';
  if (is_file($pathApp)) {
    $cfg = require $pathApp;
    if (is_array($cfg) && !empty($cfg['env']) && !empty($cfg['base_url'][$cfg['env']])) {
      return $base = rtrim(trim((string) $cfg['base_url'][$cfg['env']]), '/');
    }
  }

  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base = $scheme . '://' . $host;
  return rtrim($base, '/');
}

function require_nomination_update(): void
{
  if (function_exists('can') && !can('nominations', 'update')) {
    forbidden('NO_UPDATE_PERMISSION');
  }
}

function attachment_type_code_exists(PDO $pdo, string $code): bool
{
  $st = $pdo->prepare("SELECT 1 FROM attachment_types WHERE code=? LIMIT 1");
  $st->execute([$code]);
  return (bool) $st->fetchColumn();
}

function generate_attachment_type_code(PDO $pdo): string
{
  // code unique, không cần phụ thuộc label để khỏi lỗi dấu tiếng Việt
  for ($i = 0; $i < 50; $i++) {
    $code = 'hs_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
    if (!attachment_type_code_exists($pdo, $code))
      return $code;
  }
  // fallback cực hiếm
  return 'hs_' . time();
}
/* =====================================================
   DRIVE UPLOAD
===================================================== */
function drive_unique_name_in_folder(PDO $pdo, string $folderId, string $desiredName): string
{
  $drive = new Drive(getGoogleClient());

  $desiredName = trim($desiredName);
  if ($desiredName === '')
    $desiredName = 'file';

  // tách base + ext để thêm (1) đúng chuẩn
  $ext = pathinfo($desiredName, PATHINFO_EXTENSION);
  $base = ($ext !== '') ? substr($desiredName, 0, -(strlen($ext) + 1)) : $desiredName;

  // escape quotes cho query
  $escapeQ = function ($s) {
    return str_replace("'", "\\'", $s);
  };

  // kiểm tra tồn tại theo name + parent folder
  $exists = function (string $name) use ($drive, $folderId, $escapeQ): bool {
    $nameEsc = $escapeQ($name);
    $q = "name='{$nameEsc}' and '{$folderId}' in parents and trashed=false";
    $list = $drive->files->listFiles([
      'q' => $q,
      'pageSize' => 1,
      'fields' => 'files(id)',
      'supportsAllDrives' => true,
      'includeItemsFromAllDrives' => true,
    ]);
    return !empty($list->getFiles());
  };

  // nếu chưa trùng => dùng luôn
  if (!$exists($desiredName))
    return $desiredName;

  // nếu trùng => thêm (1)(2)...
  $i = 1;
  while (true) {
    $candidate = ($ext !== '')
      ? "{$base} ({$i}).{$ext}"
      : "{$base} ({$i})";

    if (!$exists($candidate))
      return $candidate;
    $i++;

    // safety break tránh vòng lặp vô hạn (rất hiếm)
    if ($i > 5000)
      return $desiredName . ' (' . time() . ')';
  }
}

/* =====================================================
   DRIVE UPLOAD - ĐÃ SỬA LỖI 403 + MIME
===================================================== */
function upload_to_drive(PDO $pdo, string $tmpPath, string $fileName): array
{
  $client = getGoogleClient();
  $drive = new Drive($client);

  $folderId = get_nominations_drive_folder_id($pdo);
  if ($folderId === '') {
    throw new Exception('Chưa cấu hình Google Drive folder (app_settings:gdrive_folder_id).');
  }

  // ✅ MIME đúng cho .docx, .xlsx...
  $mime = get_upload_mime_type($tmpPath, $fileName);

  // ✅ giữ tên gốc, nếu trùng thì thêm (1)(2)...
  $finalName = drive_unique_name_in_folder($pdo, $folderId, $fileName);

  $fileMetadata = new Google\Service\Drive\DriveFile([
    'name' => $finalName,
    'parents' => [$folderId]
  ]);

  $file = $drive->files->create(
    $fileMetadata,
    [
      'data' => file_get_contents($tmpPath),
      'mimeType' => $mime,
      'uploadType' => 'multipart',
      'fields' => 'id, webViewLink',
      'supportsAllDrives' => true
    ]
  );

  // ❌ ĐÃ XÓA hoàn toàn phần permissions->create (để tránh lỗi 403)
  // File sẽ inherit quyền từ folder "Hồ sơ đề nghị thi đua"

  return [
    'id' => (string) $file->id,
    'url' => (string) $file->webViewLink
  ];
}

/* =====================================================
   FILE HELPERS
===================================================== */
function safe_ext(string $filename): string
{
  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
  return $ext ?: 'bin';
}

function extract_drive_file_id(string $url): string
{
  $url = trim($url);
  if ($url === '')
    return '';

  if (preg_match('~\/d\/([a-zA-Z0-9_-]{10,})\/~', $url, $m))
    return $m[1];
  if (preg_match('~[?&]id=([a-zA-Z0-9_-]{10,})~', $url, $m))
    return $m[1];

  return '';
}

function upload_nomination_files(PDO $pdo, int $nominationId): void
{
  if (empty($_FILES['attachments']))
    return;
  if (empty($_FILES['attachments']['tmp_name']) || !is_array($_FILES['attachments']['tmp_name']))
    return;

  foreach ($_FILES['attachments']['tmp_name'] as $typeId => $tmp) {
    if (!$tmp || !is_uploaded_file($tmp))
      continue;

    $typeId = (int) $typeId;
    if (!$typeId)
      continue;

    $originalName = (string) ($_FILES['attachments']['name'][$typeId] ?? 'file');
    $size = (int) ($_FILES['attachments']['size'][$typeId] ?? 0);

    if ($size > MAX_FILE_SIZE)
      throw new Exception('Mỗi file tối đa 5MB');

    $ext = safe_ext($originalName);
    if (!in_array($ext, ALLOWED_EXT, true))
      throw new Exception('Loại file không được phép');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    if (!in_array($mime, ALLOWED_MIME, true))
      throw new Exception('File không hợp lệ');

    $cleanOriginal = preg_replace('~[^\pL0-9\._\-\(\)\s]+~u', '_', $originalName);
    $cleanOriginal = trim($cleanOriginal);
    if ($cleanOriginal === '')
      $cleanOriginal = "file.$ext";

    // prefix để chống trùng nhưng vẫn giữ tên gốc phía sau
    $driveName = $cleanOriginal; // ✅ giữ nguyên tên gốc
    $driveFile = upload_to_drive($pdo, $tmp, $driveName);

    $pdo->prepare("
      INSERT INTO nominations_files (nomination_id, attachment_type_id, file_path, created_at)
      VALUES (?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        file_path = VALUES(file_path),
        created_at = NOW()
    ")->execute([
          $nominationId,
          $typeId,
          $driveFile['url']
        ]);
  }
}

/* =====================================================
   ACTION
===================================================== */
$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));

try {

  /* =====================================================
     USER: LIST HỒ SƠ CỦA MÌNH
  ===================================================== */
  if ($action === 'list_user') {

    if (function_exists('can') && !can('nominations', 'create') && !can('nominations', 'view')) {
      forbidden('NO_PERMISSION_LIST_USER');
    }

    $uid = current_user_id();
    if (!$uid)
      json_ok(['data' => []]);

    $stmt = $pdo->prepare("
      SELECT
        n.id,
        t.name AS title_name,
        n.school_year,
        n.dept,
        n.proposer_pos,
        n.status,
        n.reviewer_note,
        n.created_at,
        (SELECT COUNT(*) FROM nominations_files nf WHERE nf.nomination_id = n.id) AS files_count
      FROM nominations n
      LEFT JOIN reward_titles t ON t.id = n.title_id
      WHERE n.user_id = ?
      ORDER BY n.created_at DESC
    ");
    $stmt->execute([$uid]);

    json_ok(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($action === 'attachment_types_list') {
    require_nomination_update();

    $rows = $pdo->query("
    SELECT id, code, label, sort_order, is_active
    FROM attachment_types
    ORDER BY sort_order ASC, id ASC
  ")->fetchAll(PDO::FETCH_ASSOC);

    json_ok(['data' => $rows]);
  }
  if ($action === 'attachment_types_add') {
    require_nomination_update();

    $label = trim((string) ($_POST['label'] ?? ''));
    $titleId = (int) ($_POST['title_id'] ?? 0);

    if ($label === '')
      json_err('Chưa nhập tên loại hồ sơ', 400);

    $code = generate_attachment_type_code($pdo);

    // sort_order auto tăng
    $sort = (int) ($pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM attachment_types")->fetchColumn());

    try {
      $pdo->beginTransaction();

      $st = $pdo->prepare("
      INSERT INTO attachment_types(code, label, sort_order, is_active)
      VALUES(?, ?, ?, 1)
    ");
      $st->execute([$code, $label, $sort]);

      $newId = (int) $pdo->lastInsertId();

      // ✅ AUTO MAP vào reward_title_attachment_types nếu có title_id
      if ($titleId > 0 && $newId > 0) {
        // map sort_order theo danh hiệu
        $mapSort = (int) ($pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM reward_title_attachment_types WHERE title_id=?")
          ->execute([$titleId]) ? 0 : 0);

        // cách chuẩn: query riêng để lấy mapSort
        $st2 = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM reward_title_attachment_types WHERE title_id=?");
        $st2->execute([$titleId]);
        $mapSort = (int) $st2->fetchColumn();

        $pdo->prepare("
        INSERT INTO reward_title_attachment_types(title_id, attachment_type_id, is_required, sort_order)
        VALUES(?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE
          is_required = VALUES(is_required),
          sort_order = VALUES(sort_order)
      ")->execute([$titleId, $newId, $mapSort]);
      }

      $pdo->commit();

      if (function_exists('log_activity')) {
        log_activity('create', 'nominations', 'Loại hồ sơ', $newId, 'Thêm loại hồ sơ: ' . $label);
      }

      json_ok(['id' => $newId, 'code' => $code, 'label' => $label]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction())
        $pdo->rollBack();
      json_err('Lỗi thêm loại hồ sơ: ' . $e->getMessage(), 500);
    }
  }
  if ($action === 'attachment_types_update') {
    require_nomination_update();

    $id = (int) ($_POST['id'] ?? 0);
    $label = trim((string) ($_POST['label'] ?? ''));

    if ($id <= 0)
      json_err('Thiếu ID', 400);
    if ($label === '')
      json_err('Chưa nhập tên', 400);

    $pdo->prepare("UPDATE attachment_types SET label=? WHERE id=?")->execute([$label, $id]);

    if (function_exists('log_activity')) {
      log_activity('update', 'nominations', 'Loại hồ sơ', $id, 'Cập nhật loại hồ sơ: ' . $label);
    }

    json_ok();
  }
  if ($action === 'attachment_types_delete') {
    require_nomination_update();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0)
      json_err('Thiếu ID', 400);

    // soft delete
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE attachment_types SET is_active=0 WHERE id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM reward_title_attachment_types WHERE attachment_type_id=?")->execute([$id]);
    $pdo->commit();

    if (function_exists('log_activity')) {
      log_activity('delete', 'nominations', 'Loại hồ sơ', $id, 'Ẩn loại hồ sơ (soft-delete) ID=' . $id);
    }

    json_ok();
  }

  if ($action === 'rtat_unlink') {
    require_nomination_update();

    $titleId = (int) ($_POST['title_id'] ?? 0);
    $attId = (int) ($_POST['attachment_type_id'] ?? 0);

    if ($titleId <= 0 || $attId <= 0) {
      json_err('Thiếu tham số', 400);
    }

    $pdo->prepare("
    DELETE FROM reward_title_attachment_types
    WHERE title_id = ? AND attachment_type_id = ?
  ")->execute([$titleId, $attId]);

    json_ok();
  }
  /* =====================================================
     META FORM
  ===================================================== */
  if ($action === 'form_meta') {

    if (function_exists('can') && !can('nominations', 'view'))
      forbidden('FORBIDDEN');

    $positions = $pdo->query("
      SELECT id, name
      FROM reward_positions
      ORDER BY sort_order ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $titles = $pdo->query("
      SELECT id, name
      FROM reward_titles
      WHERE is_active = 1
      ORDER BY sort_order ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $attachments = $pdo->query("
      SELECT id, code, label
      FROM attachment_types
      WHERE is_active = 1
      ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $school_years = $pdo->query("
      SELECT year_label
      FROM school_years
      ORDER BY year_label DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $rows = $pdo->query("
      SELECT
        g.id   AS group_id,
        g.name AS group_name,

        c.id       AS chidoan_id,
        c.unit_id  AS dept_id,

        CASE
          WHEN d.type = 'phong' THEN
            CASE WHEN d.name LIKE 'Phòng %' THEN d.name ELSE CONCAT('Phòng ', d.name) END
          WHEN d.type = 'khoa' THEN
            CASE WHEN d.name LIKE 'Khoa %' THEN d.name ELSE CONCAT('Khoa ', d.name) END
          ELSE d.name
        END AS chidoan_name

      FROM chidoan_groups g
      JOIN chidoans c
        ON c.group_id = g.id
       AND c.is_active = 1
      JOIN departments d
        ON d.id = c.unit_id
      ORDER BY
        CASE
          WHEN g.id = 2 THEN 0
          WHEN g.id = 1 THEN 1
          ELSE 2
        END,
        CASE
          WHEN d.type = 'khoa'  THEN 0
          WHEN d.type = 'phong' THEN 1
          ELSE 2
        END,
        d.name,
        c.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $tmp = [];
    foreach ($rows as $r) {
      $gid = $r['group_id'];
      if (!isset($tmp[$gid])) {
        $tmp[$gid] = [
          'group_id' => $gid,
          'name' => $r['group_name'],
          'items' => []
        ];
      }
      $tmp[$gid]['items'][] = [
        'chidoan_id' => $r['chidoan_id'],
        'dept_id' => $r['dept_id'],
        'name' => $r['chidoan_name']
      ];
    }

    json_ok([
      'positions' => $positions,
      'titles' => $titles,
      'groups' => array_values($tmp),
      'attachments' => $attachments,
      'school_years' => $school_years
    ]);
  }


  if ($action === 'attachments_by_title') {
    if (function_exists('can') && !can('nominations', 'create') && !can('nominations', 'view')) {
      forbidden('FORBIDDEN');
    }

    $titleId = (int) ($_GET['title_id'] ?? 0);
    if ($titleId <= 0)
      json_ok(['data' => []]);

    $st = $pdo->prepare("
    SELECT
      at.id, at.code, at.label,
      COALESCE(rtat.is_required, 1) AS is_required
    FROM reward_title_attachment_types rtat
    JOIN attachment_types at ON at.id = rtat.attachment_type_id
    WHERE rtat.title_id = ?
      AND at.is_active = 1
    ORDER BY rtat.sort_order ASC, at.sort_order ASC, at.id ASC
  ");
    $st->execute([$titleId]);

    json_ok(['data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
  }
  /* =====================================================
     DOWNLOAD ATTACHMENT (STREAM)
  ===================================================== */
  if ($action === 'download_attachment') {

    require_view_or_review('NO_VIEW_OR_REVIEW_PERMISSION');

    header_remove('Content-Type');
    if (ob_get_length())
      ob_end_clean();

    $nomId = (int) ($_GET['nomination_id'] ?? 0);
    $typeId = (int) ($_GET['attachment_type_id'] ?? 0);
    $mode = strtolower(trim((string) ($_GET['mode'] ?? 'download'))); // view|download

    if (!$nomId || !$typeId) {
      http_response_code(400);
      echo "Missing params";
      exit;
    }

    $uid = current_user_id();

    $st = $pdo->prepare("SELECT user_id FROM nominations WHERE id=? LIMIT 1");
    $st->execute([$nomId]);
    $ownerId = (int) ($st->fetchColumn() ?: 0);
    if (!$ownerId) {
      http_response_code(404);
      echo "Not found";
      exit;
    }

    if ($ownerId !== $uid && !(function_exists('can') && can('nominations', 'review'))) {
      http_response_code(403);
      echo "Forbidden";
      exit;
    }

    $st = $pdo->prepare("
      SELECT nf.file_path, at.label
      FROM nominations_files nf
      JOIN attachment_types at ON at.id = nf.attachment_type_id
      WHERE nf.nomination_id=? AND nf.attachment_type_id=?
      LIMIT 1
    ");
    $st->execute([$nomId, $typeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['file_path'])) {
      http_response_code(404);
      echo "File not found";
      exit;
    }

    $driveId = extract_drive_file_id((string) $row['file_path']);
    if ($driveId === '') {
      http_response_code(500);
      echo "Drive file id missing";
      exit;
    }

    $client = getGoogleClient();
    $drive = new Drive($client);

    try {
      $meta = $drive->files->get($driveId, [
        'fields' => 'name,mimeType',
        'supportsAllDrives' => true
      ]);

      $resp = $drive->files->get($driveId, [
        'alt' => 'media',
        'supportsAllDrives' => true
      ]);

      if (ob_get_length())
        ob_clean();
      header_remove('Content-Type');

      $mime = $meta->mimeType ?: 'application/octet-stream';
      $name = $meta->name ?: ('attachment_' . $nomId . '_' . $typeId);

      // bỏ prefix nom_<nomId>_<typeId>_
      $name = preg_replace('~^nom_\d+_\d+_~', '', $name);
      $name = str_replace(['"', "\r", "\n"], '', $name);

      header('Content-Type: ' . $mime);

      $disp = ($mode === 'view') ? 'inline' : 'attachment';
      $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
      $utf8 = rawurlencode($name);
      header("Content-Disposition: {$disp}; filename=\"{$ascii}\"; filename*=UTF-8''{$utf8}");

      $body = $resp->getBody();
      while (!$body->eof()) {
        echo $body->read(1024 * 1024);
        flush();
      }
      exit;

    } catch (Throwable $e) {
      http_response_code(500);
      echo "Download error: " . $e->getMessage();
      exit;
    }
  }

  /* =====================================================
     ADMIN: LIST ALL
  ===================================================== */
  if ($action === 'list') {

    if (function_exists('can') && !can('nominations', 'review')) {
      forbidden('NO_REVIEW_PERMISSION');
    }

    $uid = current_user_id();

    $stmt = $pdo->prepare("
      SELECT r.name
      FROM users u
      JOIN roles r ON r.id = u.role_id
      WHERE u.id = ?
      LIMIT 1
    ");
    $stmt->execute([$uid]);
    $role = (string) $stmt->fetchColumn();

    $where = " WHERE 1=1 ";
    $params = [];

    // === BỘ LỌC MỚI (năm học, học kỳ, danh hiệu) ===
    if (!empty($_GET['year'])) {
      $where .= " AND n.school_year = ? ";
      $params[] = $_GET['year'];
    }
    if (!empty($_GET['semester'])) {
      $where .= " AND n.semester = ? ";
      $params[] = $_GET['semester'];
    }
    if (!empty($_GET['title_id'])) {
      $where .= " AND n.title_id = ? ";
      $params[] = (int) $_GET['title_id'];
    }
    // ================================================

    if ($role === 'user') {
      $where .= " AND n.user_id = ? ";
      $params[] = $uid;

    } elseif ($role === 'bithu') {

      $stmt = $pdo->prepare("
        SELECT chidoan_group_id, department_id, class_id
        FROM bithu_scopes
        WHERE user_id = ?
        LIMIT 1
      ");
      $stmt->execute([$uid]);
      $scope = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$scope)
        forbidden('NO_SCOPE');

      if ((int) $scope['chidoan_group_id'] === 1) {
        $where .= " AND n.class_id = ? ";
        $params[] = (int) $scope['class_id'];
      } else {
        $where .= " AND n.dept = ? ";
        $params[] = $scope['department_id'];
      }

    } elseif ($role === 'gvcn') {

      $stmt = $pdo->prepare("SELECT class_id FROM gvcn_classes WHERE user_id = ?");
      $stmt->execute([$uid]);
      $classIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_id'));

      if (empty($classIds))
        forbidden('NO_CLASS_SCOPE');

      $in = implode(',', array_fill(0, count($classIds), '?'));
      $where .= " AND n.class_id IN ($in) ";
      $params = array_merge($params, $classIds);
    }

    $sql = "
      SELECT
        n.id,
        n.fullname,
        t.name AS title_name,
        n.school_year,
        n.semester,               
        n.dept,
        n.proposer_pos,
        n.status,
        n.reviewer_note,
        n.created_at,
        (SELECT COUNT(*) FROM nominations_files nf WHERE nf.nomination_id = n.id) AS files_count
      FROM nominations n
      LEFT JOIN reward_titles t ON t.id = n.title_id
      $where
      ORDER BY n.created_at DESC
    ";

    $stm = $pdo->prepare($sql);
    $stm->execute($params);

    json_ok(['data' => $stm->fetchAll(PDO::FETCH_ASSOC)]);
  }

  /* =====================================================
     CREATE (USER)
  ===================================================== */
  if ($action === 'create') {

    if (function_exists('can') && !can('nominations', 'create') && !can('nominations', 'view')) {
      forbidden('FORBIDDEN');
    }

    $uid = current_user_id();
    if (!$uid)
      throw new Exception('Không xác định được người dùng.');

    $fullname = trim((string) ($_POST['fullname'] ?? ''));
    $school_year = trim((string) ($_POST['school_year'] ?? ''));
    $semester = trim((string) ($_POST['semester'] ?? ''));        // ← THÊM DÒNG NÀY
    $dept = trim((string) ($_POST['dept'] ?? ''));
    $proposer_pos = trim((string) ($_POST['proposer_pos'] ?? ''));
    $title_id = (int) ($_POST['title_id'] ?? 0);

    if ($fullname === '' || $dept === '' || $proposer_pos === '' || $title_id <= 0) {
      throw new Exception('Vui lòng nhập đầy đủ thông tin bắt buộc.');
    }

    $course_id = (($_POST['course'] ?? '') !== '') ? (int) $_POST['course'] : null;
    $class_id = (($_POST['class'] ?? '') !== '') ? (int) $_POST['class'] : null;

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
      INSERT INTO nominations (
        user_id, type, fullname, school_year, semester, dept,
        proposer_pos, title_id,
        course_id, class_id,
        status, created_at
      )
      VALUES (?, 'self', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([
      $uid,
      $fullname,
      $school_year,
      $semester,                    // ← THÊM DÒNG NÀY
      $dept,
      $proposer_pos,
      $title_id,
      $course_id,
      $class_id
    ]);

    $nominationId = (int) $pdo->lastInsertId();

    upload_nomination_files($pdo, $nominationId);

    $link = "/index.php?p=nominations&tab=list";
    $msg = "📩 Hồ sơ mới từ {$fullname} ({$dept}) cần duyệt.";
    $pdo->prepare("INSERT INTO notifications (message, user_id, link) VALUES (?, NULL, ?)")
      ->execute([$msg, $link]);

    $pdo->commit();

    if (function_exists('log_activity')) {
      log_activity(
        'create',
        'nominations',
        'Hồ sơ đề nghị',
        null,
        'Nộp hồ sơ thi đua: ' . $fullname . ' (' . $dept . ')'
      );
    }

    json_ok(['msg' => 'Nộp đề nghị thành công!']);
  }

  /* =====================================================
     REVIEW (ADMIN)
  ===================================================== */
  if ($action === 'review') {

    if (function_exists('can') && !can('nominations', 'review')) {
      forbidden('NO_REVIEW_PERMISSION');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));

    if (!$id || !in_array($status, ['approved', 'rejected', 'pending'], true)) {
      throw new Exception('Thiếu tham số hợp lệ.');
    }

    $pdo->prepare("UPDATE nominations SET status=?, reviewer_note=? WHERE id=?")
      ->execute([$status, $note, $id]);

    $stmt = $pdo->prepare("SELECT user_id, fullname, dept FROM nominations WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $nom = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($nom) {
      $user_id = (int) $nom['user_id'];
      $fullname = (string) $nom['fullname'];
      $dept = (string) $nom['dept'];
      $link = "/index.php?p=nominations&tab=userlist";

      if ($status === 'approved') {
        $msg = "✅ Hồ sơ đề nghị của bạn ({$fullname} - {$dept}) đã được <b>DUYỆT</b>.";
      } elseif ($status === 'rejected') {
        $msg = "❌ Hồ sơ đề nghị của bạn ({$fullname} - {$dept}) đã bị <b>TỪ CHỐI</b>.";
        if ($note)
          $msg .= "<br><b>Lý do:</b> {$note}<br>";
      } else {
        $msg = "📄 Hồ sơ đề nghị của bạn ({$fullname} - {$dept}) đang được xem xét.";
      }

      $pdo->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)")
        ->execute([$user_id, $msg, $link]);

      if (function_exists('log_activity')) {
        $actionKey = ($status === 'approved') ? 'approve' : (($status === 'rejected') ? 'reject' : 'update');
        $actionText = ($status === 'approved') ? 'Duyệt' : (($status === 'rejected') ? 'Từ chối' : 'Cập nhật');

        log_activity(
          $actionKey,
          'nominations',
          'Hồ sơ đề nghị',
          null,
          $actionText . ' hồ sơ: ' . $fullname . ' (' . $dept . ')'
        );
      }
    }

    json_ok();
  }

  /* =====================================================
     GET DETAIL (kèm files + view_url/download_url dùng BASE_URL)
  ===================================================== */
  if ($action === 'get_detail') {

    require_view_or_review('NO_VIEW_OR_REVIEW_PERMISSION');

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0)
      json_err('Thiếu ID hồ sơ.', 400);

    $uid = current_user_id();
    if ($uid <= 0)
      json_err('Unauthorized', 401);

    $st = $pdo->prepare("SELECT user_id FROM nominations WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $ownerId = (int) ($st->fetchColumn() ?: 0);
    if ($ownerId <= 0)
      json_err('Không tìm thấy hồ sơ.', 404);

    if ($ownerId !== $uid && !(function_exists('can') && can('nominations', 'review'))) {
      json_err('Forbidden', 403);
    }

    $stmt = $pdo->prepare("
      SELECT
        n.*,
        t.name AS title_name,
        c.name  AS course_name,
        cl.name AS class_name
      FROM nominations n
      LEFT JOIN reward_titles t ON t.id = n.title_id
      LEFT JOIN courses c ON c.id = n.course_id
      LEFT JOIN classes cl ON cl.id = n.class_id
      WHERE n.id = ?
      LIMIT 1
    ");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data)
      json_err('Không tìm thấy hồ sơ.', 404);

    $stm2 = $pdo->prepare("
      SELECT
        at.code,
        at.label,
        nf.file_path,
        nf.attachment_type_id
      FROM nominations_files nf
      JOIN attachment_types at ON at.id = nf.attachment_type_id
      WHERE nf.nomination_id = ?
      ORDER BY at.sort_order ASC, at.id ASC
    ");
    $stm2->execute([$id]);
    $rows = $stm2->fetchAll(PDO::FETCH_ASSOC);

    $base = get_base_url(); // ✅ http://domain.com/doanthanhnien

    $attachments = [];
    foreach ($rows as $r) {
      if (empty($r['file_path']))
        continue;

      $typeId = (int) $r['attachment_type_id'];
      $prefix = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'); // -> /doanthanhnien

      $attachments[] = [
        'code' => $r['code'],
        'label' => $r['label'],

        // ✅ XEM: đi thẳng qua Google Drive viewer
        'view_url' => (string) $r['file_path'],  // webViewLink

        // ✅ TẢI: đi qua endpoint để stream file về
        'download_url' => $prefix . "/controllers/nominations.php?action=download_attachment&nomination_id=" . (int) $id . "&attachment_type_id=" . $typeId . "&mode=download",

      ];


    }

    $data['attachments'] = $attachments;

    json_ok(['data' => $data]);
  }

  /* =====================================================
     UPDATE (USER BỔ SUNG)
  ===================================================== */
  if ($action === 'update') {

    if (function_exists('can') && !can('nominations', 'view'))
      forbidden('FORBIDDEN');

    $uid = current_user_id();
    if (!$uid)
      throw new Exception('Không xác định được người dùng.');

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id)
      throw new Exception('Thiếu ID hồ sơ.');

    $stmt = $pdo->prepare("SELECT id FROM nominations WHERE id=? AND user_id=? LIMIT 1");
    $stmt->execute([$id, $uid]);
    if (!$stmt->fetchColumn()) {
      throw new Exception('Không tìm thấy hồ sơ hoặc bạn không có quyền.');
    }

    $fullname = trim((string) ($_POST['fullname'] ?? ''));
    $semester = trim((string) ($_POST['semester'] ?? ''));        // ← THÊM DÒNG NÀY
    $school_year = trim((string) ($_POST['school_year'] ?? ''));
    $dept = trim((string) ($_POST['dept'] ?? ''));
    $proposer_pos = trim((string) ($_POST['proposer_pos'] ?? ''));
    $title_id = (int) ($_POST['title_id'] ?? 0);

    if ($fullname === '' || $dept === '' || $proposer_pos === '' || $title_id <= 0) {
      throw new Exception('Vui lòng nhập đầy đủ thông tin bắt buộc.');
    }

    $course_id = (($_POST['course'] ?? '') !== '') ? (int) $_POST['course'] : null;
    $class_id = (($_POST['class'] ?? '') !== '') ? (int) $_POST['class'] : null;

    $pdo->beginTransaction();

    $pdo->prepare("
      UPDATE nominations
      SET fullname=?, school_year=?, semester=?, dept=?, proposer_pos=?, title_id=?,
          course_id=?, class_id=?,
          status='pending', reviewer_note=NULL, updated_at=NOW()
      WHERE id=? AND user_id=?
    ")->execute([
          $fullname,
          $school_year,
          $semester,                    // ← THÊM DÒNG NÀY
          $dept,
          $proposer_pos,
          $title_id,
          $course_id,
          $class_id,
          $id,
          $uid
        ]);

    upload_nomination_files($pdo, $id);

    $link = "/index.php?p=nominations&tab=list";
    $msg = "🔁 Hồ sơ bổ sung từ {$fullname} ({$dept}) cần duyệt lại.";
    $pdo->prepare("INSERT INTO notifications (message, user_id, link) VALUES (?, NULL, ?)")
      ->execute([$msg, $link]);

    $pdo->commit();

    if (function_exists('log_activity')) {
      log_activity(
        'update',
        'nominations',
        'Hồ sơ đề nghị',
        null,
        'Bổ sung hồ sơ thi đua: ' . $fullname . ' (' . $dept . ')'
      );
    }

    json_ok(['msg' => 'Đã cập nhật hồ sơ bổ sung!']);
  }

  /* =====================================================
     SCHOOL YEARS
  ===================================================== */
  if ($action === 'get_school_years') {

    if (function_exists('can') && !can('nominations', 'create') && !can('nominations', 'view')) {
      forbidden('FORBIDDEN');
    }

    $rows = $pdo->query("
      SELECT year_label
      FROM school_years
      ORDER BY year_label DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

    json_ok(['data' => $rows]);
  }

  /* =====================================================
     COURSES
  ===================================================== */
  if ($action === 'get_courses') {

    if (function_exists('can') && !can('nominations', 'create') && !can('nominations', 'view')) {
      forbidden('FORBIDDEN');
    }

    $stmt = $pdo->query("SELECT id, name FROM courses ORDER BY name ASC");
    json_ok(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  }

  /* =====================================================
     CLASSES BY DEPT + COURSE
  ===================================================== */
  if ($action === 'get_classes') {

    if (function_exists('can') && !can('nominations', 'create') && !can('nominations', 'view')) {
      forbidden('FORBIDDEN');
    }

    $deptId = (int) ($_GET['dept_id'] ?? 0);
    $courseId = (int) ($_GET['course_id'] ?? 0);

    if (!$deptId || !$courseId)
      json_ok(['data' => []]);

    $stm = $pdo->prepare("
      SELECT id, name
      FROM classes
      WHERE department_id = ?
        AND course_id = ?
      ORDER BY name
    ");
    $stm->execute([$deptId, $courseId]);

    json_ok(['data' => $stm->fetchAll(PDO::FETCH_ASSOC)]);
  }

  throw new Exception('Hành động không hợp lệ.');

} catch (Throwable $e) {
  if ($pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  json_err($e->getMessage(), 500);
}
