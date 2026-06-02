<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/db.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/config/google-client.php';

auth_guard();
if (!is_admin()) {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => 0, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function json_ok($data = null)
{
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => 1, 'data' => $data], JSON_UNESCAPED_UNICODE);
  exit;
}

function json_err($msg, $code = 400, $extra = null)
{
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  $payload = ['ok' => 0, 'error' => $msg];
  if ($extra !== null)
    $payload['extra'] = $extra;
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function setting_get(PDO $pdo, string $k, $default = null)
{
  $st = $pdo->prepare("SELECT v FROM app_settings WHERE k=? LIMIT 1");
  $st->execute([$k]);
  $v = $st->fetchColumn();
  return ($v === false || $v === null) ? $default : $v;
}

function setting_set(PDO $pdo, string $k, string $v): void
{
  $st = $pdo->prepare("
    INSERT INTO app_settings (k, v) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE v=VALUES(v)
  ");
  $st->execute([$k, $v]);
}

// Shared Drive flags
function driveOpts(array $opt = []): array
{
  return $opt + ['supportsAllDrives' => true];
}

function normalizeFolderId(?string $s): string
{
  $s = trim((string) $s);
  if ($s === '')
    return '';

  // nếu user dán nguyên URL /folders/XXXX thì bóc ID ra
  if (preg_match('~drive/folders/([A-Za-z0-9_-]+)~', $s, $m)) {
    return $m[1];
  }
  return $s;
}

/* =========================
   STATUS
========================= */
if ($action === 'status') {
  $dbId = (string) setting_get($pdo, 'gdrive_folder_id', '');
  $dbId = trim($dbId);

  $folderInfo = null;
  $err = null;

  if ($dbId !== '') {
    try {
      $drive = getDriveService();

      // supportsAllDrives để đọc folder trong Shared Drive
      $folder = $drive->files->get($dbId, [
        'supportsAllDrives' => true,
        'fields' => 'id,name,mimeType,driveId',
      ]);

      $folderInfo = [
        'id' => $folder->getId(),
        'name' => $folder->getName(),
        'mimeType' => $folder->getMimeType(),
        'driveId' => $folder->getDriveId(),
      ];
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }

  json_ok([
    'service_email' => getServiceAccountEmail(),
    'folder_id_db' => $dbId,
    'folder' => $folderInfo,     // null nếu chưa cấu hình / không truy cập được
    'folder_error' => $err,      // để debug nếu cần
  ]);
}


/* =========================
   SAVE
========================= */
if ($action === 'save') {
  $folderId = normalizeFolderId($_POST['folder_id'] ?? '');
  if ($folderId === '')
    json_err('Thiếu Folder ID');

  setting_set($pdo, 'gdrive_folder_id', $folderId);
  json_ok(['msg' => 'Đã lưu Folder ID', 'folder_id_saved' => $folderId]);
}

/* =========================
   TEST
========================= */
if ($action === 'test') {
  // ƯU TIÊN ID TỪ REQUEST, fallback DB
  $folderIdReq = normalizeFolderId($_GET['folder_id'] ?? ($_POST['folder_id'] ?? ''));
  $folderIdDb = (string) setting_get($pdo, 'gdrive_folder_id', '');
  $folderId = $folderIdReq !== '' ? $folderIdReq : $folderIdDb;

  if ($folderId === '')
    json_err('Chưa cấu hình Folder ID');

  try {
    $drive = getDriveService();

    // 1) GET folder để xác nhận SA nhìn thấy folder
    $folder = $drive->files->get($folderId, driveOpts([
      'fields' => 'id,name,mimeType,driveId,parents',
    ]));

    if ($folder->getMimeType() !== 'application/vnd.google-apps.folder') {
      json_err('ID này không phải folder', 400, [
        'folder_id_used' => $folderId,
        'mimeType' => $folder->getMimeType(),
        'name' => $folder->getName(),
      ]);
    }

    // 2) list permissions (có thể fail tuỳ policy, nên bọc try/catch)
    $permData = null;
    try {
      $perms = $drive->permissions->listPermissions($folderId, driveOpts([
        'fields' => 'permissions(id,type,emailAddress,role)',
      ]));
      $permData = $perms->getPermissions();
    } catch (Throwable $exPerm) {
      $permData = ['error' => $exPerm->getMessage()];
    }

    // 3) Create file test
    $tmpName = 'test_connect_' . date('Ymd_His') . '.txt';
    $content = "OK " . date('c');

    $meta = new \Google\Service\Drive\DriveFile([
      'name' => $tmpName,
      'parents' => [$folderId],
    ]);

    $created = $drive->files->create($meta, driveOpts([
      'data' => $content,
      'mimeType' => 'text/plain; charset=utf-8',
      'uploadType' => 'multipart',
      'fields' => 'id,webViewLink',
    ]));

    $createdId = $created->getId();

    // 4) Cleanup: delete file test (KHÔNG làm fail test nếu delete lỗi)
    $cleanup = ['attempted' => false, 'deleted' => false, 'error' => null];
    if ($createdId) {
      $cleanup['attempted'] = true;
      try {
        // Shared Drive: delete cũng cần supportsAllDrives
        $drive->files->delete($createdId, ['supportsAllDrives' => true]);
        $cleanup['deleted'] = true;
      } catch (Throwable $exDel) {
        $cleanup['error'] = $exDel->getMessage();
      }
    }

    json_ok([
      'msg' => 'Kết nối Google Drive OK',
      'service_email' => getServiceAccountEmail(),
      'folder_id_used' => $folderId,
      'folder_id_db' => $folderIdDb,
      'folder_id_req' => $folderIdReq,
      'folder' => [
        'id' => $folder->getId(),
        'name' => $folder->getName(),
        'driveId' => $folder->getDriveId(),
        'parents' => $folder->getParents(),
      ],
      'created_file_id' => $createdId,
      'created_link' => method_exists($created, 'getWebViewLink') ? $created->getWebViewLink() : null,
      'cleanup' => $cleanup,
      'permissions' => $permData,
    ]);
  } catch (Throwable $e) {
    json_err('Test lỗi: ' . $e->getMessage(), 500, [
      'service_email' => getServiceAccountEmail(),
      'folder_id_db' => $folderIdDb,
      'folder_id_req' => $folderIdReq,
      'folder_id_used' => $folderId,
    ]);
  }
}

json_err('Bad action', 400);
