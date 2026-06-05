<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/activity_log.php';


auth_guard();



$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = $_SESSION['user_id'];

function json_ok($data = null)
{
  echo json_encode(['ok' => true, 'data' => $data]);
  exit;
}
function json_err($msg, $code = 400)
{
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

function get_current_duty_week_id(PDO $pdo): int
{
  // 🔥 TUẦN MỤC TIÊU: TUẦN SAU
  $monday = date('Y-m-d', strtotime('monday next week'));
  $friday = date('Y-m-d', strtotime('friday next week'));

  // 1) đã có tuần chưa?
  $stmt = $pdo->prepare("
    SELECT id
    FROM duty_weeks
    WHERE week_start = ?
    LIMIT 1
  ");
  $stmt->execute([$monday]);
  $id = $stmt->fetchColumn();
  if ($id)
    return (int) $id;

  // 2) tạo tuần mới + copy lịch từ tuần trước
  $pdo->beginTransaction();
  try {
    $pdo->prepare("
      INSERT INTO duty_weeks (week_start, week_end)
      VALUES (?, ?)
    ")->execute([$monday, $friday]);

    $newWeekId = (int) $pdo->lastInsertId();

    // lấy tuần gần nhất trước đó
    $prev = $pdo->prepare("
      SELECT id
      FROM duty_weeks
      WHERE week_start < ?
      ORDER BY week_start DESC
      LIMIT 1
    ");
    $prev->execute([$monday]);
    $prevWeekId = (int) ($prev->fetchColumn() ?: 0);

    if ($prevWeekId) {
      // COPY lịch rảnh
      // Nếu bảng duty_availability có confirmed: set confirmed = 0 cho tuần mới
      $pdo->prepare("
        INSERT INTO duty_availability (user_id, week_id, day, shift, confirmed)
        SELECT user_id, ?, day, shift, 0
        FROM duty_availability
        WHERE week_id = ?
      ")->execute([$newWeekId, $prevWeekId]);

      // COPY lịch học
      $pdo->prepare("
        INSERT INTO duty_study_schedule (user_id, week_id, day, shift, has_class)
        SELECT user_id, ?, day, shift, has_class
        FROM duty_study_schedule
        WHERE week_id = ?
      ")->execute([$newWeekId, $prevWeekId]);

      // tạo pending choice cho những user đã có lịch (availability hoặc study) tuần trước
      $pdo->prepare("
        INSERT IGNORE INTO duty_week_choices (week_id, user_id, choice)
        SELECT DISTINCT ?, user_id, 'pending'
        FROM (
          SELECT user_id FROM duty_availability WHERE week_id = ?
          UNION
          SELECT user_id FROM duty_study_schedule WHERE week_id = ?
        ) t
      ")->execute([$newWeekId, $prevWeekId, $prevWeekId]);
    }

    log_activity(
      'create',
      'duty',
      'duty_weeks',
      $newWeekId,
      'Tạo tuần trực mới và copy lịch tuần trước (user pending chọn giữ/sửa)'
    );

    $pdo->commit();
    return $newWeekId;

  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}
function get_duty_week_by_offset(PDO $pdo, int $offset = 1): array
{
  // offset: -1 tuần trước, 0 tuần này, 1 tuần sau (mặc định giữ behavior cũ)
  $monday = new DateTime();
  $monday->setISODate((int) date('o'), (int) date('W'), 1); // monday this week
  if ($offset !== 0)
    $monday->modify(($offset > 0 ? '+' : '') . $offset . ' week');

  $friday = clone $monday;
  $friday->modify('+4 day');

  $weekStart = $monday->format('Y-m-d');
  $weekEnd = $friday->format('Y-m-d');

  // tìm duty_weeks
  $st = $pdo->prepare("SELECT id FROM duty_weeks WHERE week_start = ? LIMIT 1");
  $st->execute([$weekStart]);
  $weekId = (int) ($st->fetchColumn() ?: 0);

  // nếu chưa có tuần, tạo
  if (!$weekId) {
    $pdo->prepare("INSERT INTO duty_weeks (week_start, week_end) VALUES (?, ?)")
      ->execute([$weekStart, $weekEnd]);
    $weekId = (int) $pdo->lastInsertId();
  }

  // map ngày hiển thị T2..T6 => dd/mm
  $dates = [];
  for ($d = 0; $d < 5; $d++) {
    $tmp = clone $monday;
    $tmp->modify("+$d day");
    $dates[2 + $d] = $tmp->format('d/m');
  }

  return [
    'week_id' => $weekId,
    'week_start' => $weekStart,
    'week_end' => $weekEnd,
    'dates' => $dates,
  ];
}

function week_has_schedule(PDO $pdo, int $weekId): bool
{
  $st = $pdo->prepare("SELECT COUNT(*) FROM duty_assignments WHERE week_id=?");
  $st->execute([$weekId]);
  return ((int) $st->fetchColumn() > 0);
}

function get_user_week_choice(PDO $pdo, int $weekId, int $userId): string
{
  // ensure row exists
  $pdo->prepare("
    INSERT IGNORE INTO duty_week_choices (week_id, user_id, choice)
    VALUES (?, ?, 'pending')
  ")->execute([$weekId, $userId]);

  $st = $pdo->prepare("
    SELECT choice
    FROM duty_week_choices
    WHERE week_id=? AND user_id=?
    LIMIT 1
  ");
  $st->execute([$weekId, $userId]);
  return (string) ($st->fetchColumn() ?: 'pending');
}


switch ($action) {
  case 'get_week_meta':
    try {
      $offset = (int) ($_GET['offset'] ?? 1);
      $meta = get_duty_week_by_offset($pdo, $offset);
      json_ok($meta);
    } catch (Throwable $e) {
      json_err('Lỗi load week meta');
    }
    break;

  case 'get_my_update_prompt':
    try {
      $weekId = get_current_duty_week_id($pdo);

      // đã xếp lịch => khóa, khỏi prompt
      if (week_has_schedule($pdo, $weekId)) {
        json_ok(['locked' => true, 'show_prompt' => false, 'choice' => 'locked']);
      }

      $choice = get_user_week_choice($pdo, $weekId, $userId);

      // confirmed: nếu user chưa có availability hoặc còn confirmed=0 thì vẫn cần prompt
      $st = $pdo->prepare("
      SELECT COALESCE(MIN(confirmed), 0)
      FROM duty_availability
      WHERE week_id=? AND user_id=?
    ");
      $st->execute([$weekId, $userId]);
      $confirmed = (int) $st->fetchColumn();

      // show modal khi:
      // - choice còn pending (chưa bấm giữ/sửa)
      // hoặc - confirmed=0 (chưa xác nhận lịch rảnh tuần mới)
      $show = ($choice === 'pending') || ($confirmed === 0);

      json_ok([
        'locked' => false,
        'week_id' => $weekId,
        'choice' => $choice,
        'confirmed' => $confirmed,
        'show_prompt' => $show
      ]);

    } catch (Throwable $e) {
      json_err('Lỗi load prompt cập nhật tuần');
    }
    break;

  case 'get_my_week_choice':
    try {
      $weekId = get_current_duty_week_id($pdo);

      if (week_has_schedule($pdo, $weekId)) {
        json_ok(['locked' => true, 'choice' => 'locked', 'confirmed' => 1]);
      }

      $choice = get_user_week_choice($pdo, $weekId, $userId);

      // THAY ĐỔI: confirmed giờ dựa vào lịch học thay vì availability
      $st = $pdo->prepare("
      SELECT COUNT(*) 
      FROM duty_study_schedule 
      WHERE week_id = ? AND user_id = ?
    ");
      $st->execute([$weekId, $userId]);
      $studyCount = (int) $st->fetchColumn();

      // Nếu đã có lịch học → coi như confirmed = 1 (không cần prompt nữa)
      $confirmed = ($studyCount > 0) ? 1 : 0;

      json_ok([
        'locked' => false,
        'choice' => $choice,
        'confirmed' => $confirmed
      ]);

    } catch (Throwable $e) {
      json_err('Lỗi load lựa chọn tuần');
    }
    break;


  case 'set_my_week_choice':
    try {
      $weekId = get_current_duty_week_id($pdo);

      // nếu đã xếp lịch thì cấm đổi
      if (week_has_schedule($pdo, $weekId)) {
        json_err('Tuần này BCH đã xếp lịch, bạn không thể thay đổi', 403);
      }

      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input)
        json_err('Dữ liệu không hợp lệ');

      $choice = $input['choice'] ?? '';
      if (!in_array($choice, ['keep', 'edit'], true)) {
        json_err('Choice không hợp lệ');
      }

      $pdo->prepare("
      INSERT INTO duty_week_choices (week_id, user_id, choice, decided_at)
      VALUES (?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE choice=VALUES(choice), decided_at=VALUES(decided_at)
    ")->execute([$weekId, $userId, $choice]);

      // nếu bạn muốn dùng confirmed để đồng bộ:
      // keep => confirmed=1, edit => confirmed=0
      // if ($choice === 'keep') {
      //   $pdo->prepare("
      //   UPDATE duty_availability
      //   SET confirmed = 1
      //   WHERE week_id = ? AND user_id = ?
      // ")->execute([$weekId, $userId]);
      // }


      log_activity(
        'update',
        'duty',
        'week_choice',
        null,
        $choice === 'keep' ? 'User chọn GIỮ lịch tuần sau' : 'User chọn SỬA lịch tuần sau'
      );

      json_ok(['choice' => $choice]);

    } catch (Throwable $e) {
      json_err('Lỗi lưu lựa chọn tuần');
    }
    break;

  case 'check_need_register':
    try {
      if (!can('duty', 'view')) {
        json_ok(['need' => false]);
      }

      $weekId = get_current_duty_week_id($pdo);

      // nếu tuần đã xếp lịch thì khỏi nhắc
      if (week_has_schedule($pdo, $weekId)) {
        json_ok([
          'need' => false,
          'locked' => true,
          'week_id' => $weekId
        ]);
      }

      // 1) đếm lịch rảnh
      $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM duty_availability
      WHERE week_id = ? AND user_id = ?
    ");
      $st->execute([$weekId, $userId]);
      $availCnt = (int) $st->fetchColumn();

      // 2) đếm lịch học
      $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM duty_study_schedule
      WHERE week_id = ? AND user_id = ?
    ");
      $st->execute([$weekId, $userId]);
      $studyCnt = (int) $st->fetchColumn();

      // 3) lấy choice (pending/keep/edit)
      $choice = get_user_week_choice($pdo, $weekId, $userId); // đảm bảo luôn có row

      // 4) confirmed của availability: nếu chưa có record thì coi như 0
      $st = $pdo->prepare("
      SELECT COALESCE(MIN(confirmed), 0)
      FROM duty_availability
      WHERE week_id = ? AND user_id = ?
    ");
      $st->execute([$weekId, $userId]);
      $confirmed = (int) $st->fetchColumn(); // 0/1

      /**
       * ✅ Logic hiển thị modal:
       * - Nếu chưa có availability và study => chắc chắn cần đăng ký
       * - Hoặc choice còn pending => cần nhắc user bấm giữ/sửa
       * - Hoặc confirmed = 0 => lịch tuần mới copy chưa xác nhận lại
       */
      $need = false;

      if ($availCnt === 0 && $studyCnt === 0) {
        $need = true;
      } else if ($choice === 'pending') {
        $need = true;
      } else if ($confirmed === 0) {
        $need = true;
      }

      json_ok([
        'need' => $need,
        'week_id' => $weekId,
        'availability_count' => $availCnt,
        'study_count' => $studyCnt,
        'choice' => $choice,
        'confirmed' => $confirmed
      ]);

    } catch (Throwable $e) {
      json_err('Lỗi check đăng ký lịch');
    }
    break;


  /* ===== BCH ===== */
  case 'get_my_availability':
    try {
      $weekId = get_current_duty_week_id($pdo);

      $stmt = $pdo->prepare("
      SELECT day, shift
      FROM duty_availability
      WHERE user_id = ?
        AND week_id = ?
    ");
      $stmt->execute([$userId, $weekId]);

      json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
      json_err('Lỗi load lịch rảnh');
    }
    break;


  case 'save_my_availability':

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input)
      json_err('Dữ liệu không hợp lệ');

    $items = $input['items'] ?? [];  // cho phép rỗng hoàn toàn

    // $items = $input['items'] ?? [];
    // if (!is_array($items) || count($items) === 0) {
    //   json_err('Bạn chưa chọn buổi nào');
    // }

    $weekId = get_current_duty_week_id($pdo);

    // 🔒 KHÓA SỬA NẾU ĐÃ XẾP LỊCH
    // if (week_has_schedule($pdo, $weekId)) {
    //   json_err('BCH đã xếp lịch cho tuần này, bạn không thể sửa', 403);
    // }
    // ====== LẤY LỊCH HỌC TUẦN NÀY ĐỂ CHẶN TRÙNG ======
    $studyStmt = $pdo->prepare("
    SELECT day, shift
    FROM duty_study_schedule
    WHERE user_id = ?
      AND week_id = ?
      AND has_class = 1
  ");
    $studyStmt->execute([$userId, $weekId]);

    $studyMap = [];
    foreach ($studyStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
      $studyMap[((int) $s['day']) . '-' . $s['shift']] = true; // 2-morning
    }

    // map điểm
    $scoreMap = [
      'morning' => 1,
      'afternoon' => 1,
      'break_morning' => 0.5,
      'break_afternoon' => 0.5
    ];

    $validItems = [];
    $seen = [];
    $totalScore = 0;

    foreach ($items as $i) {
      $day = (int) ($i['day'] ?? 0);
      $shift = (string) ($i['shift'] ?? '');

      if ($day < 2 || $day > 6)
        continue;
      if (!isset($scoreMap[$shift]))
        continue;


      // ====== CHẶN TRÙNG LỊCH HỌC (SÁNG/CHIỀU) ======
      if (($shift === 'morning' || $shift === 'afternoon') && isset($studyMap[$day . '-' . $shift])) {
        // OPTION A: bỏ qua slot trùng
        // continue;

        // OPTION B: báo lỗi (khuyến nghị)
        $shiftLabel = ($shift === 'morning') ? 'Sáng' : 'Chiều';
        json_err("Không thể đăng ký rảnh trùng lịch học: Thứ $day ($shiftLabel)");
      }

      $key = $day . '-' . $shift;
      if (isset($seen[$key]))
        continue;
      $seen[$key] = true;

      $validItems[] = ['day' => $day, 'shift' => $shift];
      $totalScore += $scoreMap[$shift];
    }

    // if ($totalScore < 3) {
    //   json_err('Phải đăng ký tối thiểu 3 điểm (2 ra chơi = 1 buổi thường)');
    // }

    try {
      $pdo->beginTransaction();

      // LUÔN XÓA HẾT record cũ của user trong tuần này
      $delStmt = $pdo->prepare("
            DELETE FROM duty_availability 
            WHERE user_id = ? AND week_id = ?
        ");
      $delStmt->execute([$userId, $weekId]);
      $deletedCount = $delStmt->rowCount();

      // Nếu có items mới thì insert
      if (!empty($validItems)) {
        $insertStmt = $pdo->prepare("
                INSERT INTO duty_availability (user_id, week_id, day, shift, confirmed)
                VALUES (?,?,?,?,1)
            ");
        foreach ($validItems as $v) {
          $insertStmt->execute([$userId, $weekId, $v['day'], $v['shift']]);
        }
      }

      // Log để debug
      log_activity(
        'update',
        'duty',
        'availability',
        null,
        "Cập nhật lịch rảnh: xóa $deletedCount record cũ, thêm " . count($validItems) . " record mới"
      );

      // Nếu muốn set choice = 'edit' chỉ khi có thay đổi thực sự
      if ($deletedCount > 0 || !empty($validItems)) {
        $pdo->prepare("
                INSERT INTO duty_week_choices (week_id, user_id, choice, decided_at)
                VALUES (?, ?, 'edit', NOW())
                ON DUPLICATE KEY UPDATE choice='edit', decided_at=NOW()
            ")->execute([$weekId, $userId]);
      }

      $pdo->commit();
      json_ok([
        'totalScore' => $totalScore,
        'saved' => count($validItems),
        'deleted' => $deletedCount
      ]);

    } catch (Throwable $e) {
      $pdo->rollBack();
      error_log("Save availability error: " . $e->getMessage()); // debug
      json_err('Lỗi lưu dữ liệu: ' . $e->getMessage());
    }
    break;


  case 'save_my_study':

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input)
      json_err('Dữ liệu không hợp lệ');

    $items = $input['items'] ?? [];
    if (!is_array($items))
      $items = [];

    $weekId = get_current_duty_week_id($pdo);

    // if (week_has_schedule($pdo, $weekId)) {
    //   json_err('BCH đã xếp lịch cho tuần này, bạn không thể sửa', 403);
    // }
    // ====== LẤY LỊCH RẢNH TUẦN NÀY ĐỂ CHẶN TRÙNG ======
    // chỉ cần morning/afternoon vì lịch học chỉ có 2 ca đó
    $availStmt = $pdo->prepare("
    SELECT day, shift
    FROM duty_availability
    WHERE user_id = ?
      AND week_id = ?
      AND shift IN ('morning','afternoon')
  ");
    $availStmt->execute([$userId, $weekId]);

    $availMap = [];
    foreach ($availStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
      $availMap[((int) $a['day']) . '-' . $a['shift']] = true; // 2-morning
    }

    // ====== LỌC + CHỐNG TRÙNG + CHẶN TRÙNG LỊCH RẢNH ======
    $validItems = [];
    $seen = [];

    foreach ($items as $i) {
      $day = (int) ($i['day'] ?? 0);
      $shift = (string) ($i['shift'] ?? '');

      if (!in_array($day, [2, 3, 4, 5, 6], true))
        continue;
      if (!in_array($shift, ['morning', 'afternoon'], true))
        continue;

      $key = $day . '-' . $shift;

      // chống gửi trùng
      if (isset($seen[$key]))
        continue;
      $seen[$key] = true;

      // CHẶN: lịch học trùng lịch rảnh
      if (isset($availMap[$key])) {
        $shiftLabel = ($shift === 'morning') ? 'Sáng' : 'Chiều';
        json_err("Không thể lưu lịch học trùng lịch rảnh: Thứ $day ($shiftLabel)");
      }

      $validItems[] = ['day' => $day, 'shift' => $shift];
    }
    // THÊM ĐOẠN NÀY (khuyến nghị)
    if (empty($validItems)) {
      json_err("Bạn phải đăng ký ít nhất 1 buổi học.");
    }
    try {
      $pdo->beginTransaction();

      $pdo->prepare("
      DELETE FROM duty_study_schedule
      WHERE user_id = ? AND week_id = ?
    ")->execute([$userId, $weekId]);

      $stmt = $pdo->prepare("
      INSERT INTO duty_study_schedule
        (user_id, week_id, day, shift, has_class)
      VALUES (?,?,?,?,1)
    ");

      foreach ($validItems as $v) {
        $stmt->execute([$userId, $weekId, $v['day'], $v['shift']]);
      }

      log_activity(
        'update',
        'duty',
        'study_schedule',
        null,
        'Cập nhật lịch học'
      );
      $pdo->prepare("
  INSERT INTO duty_week_choices (week_id, user_id, choice, decided_at)
  VALUES (?, ?, 'edit', NOW())
  ON DUPLICATE KEY UPDATE choice='edit', decided_at=NOW()
")->execute([$weekId, $userId]);

      $pdo->commit();
      json_ok(['saved' => count($validItems)]);

    } catch (Throwable $e) {
      $pdo->rollBack();
      json_err('Lỗi lưu lịch học');
    }

    break;



  case 'get_my_study':
    try {
      $weekId = get_current_duty_week_id($pdo);

      $stmt = $pdo->prepare("
    SELECT day, shift
    FROM duty_study_schedule
    WHERE user_id = ?
      AND week_id = ?
  ");
      $stmt->execute([$userId, $weekId]);

      json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
      json_err('Lỗi load lịch học');
    }
    break;

  case 'get_week_update_notice':
    try {
      if (!can('duty', 'view')) {
        json_ok(['need_update' => false]);
      }

      $weekId = get_current_duty_week_id($pdo);

      // Nếu đã xếp lịch thì khỏi nhắc nữa
      if (week_has_schedule($pdo, $weekId)) {
        json_ok([
          'need_update' => false,
          'week_id' => $weekId,
          'pending_users' => 0
        ]);
      }

      // Chỉ nhắc khi có user duty chưa confirmed
      $permId = $pdo->query("SELECT id FROM permissions WHERE code='duty' LIMIT 1")->fetchColumn();
      if (!$permId)
        json_ok(['need_update' => false, 'week_id' => $weekId, 'pending_users' => 0]);

      $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT da.user_id)
      FROM duty_availability da
      JOIN user_permissions up
        ON up.user_id = da.user_id
       AND up.permission_id = ?
       AND up.can_view = 1
      WHERE da.week_id = ?
        AND da.confirmed = 0
    ");
      $stmt->execute([(int) $permId, $weekId]);
      $pendingUsers = (int) $stmt->fetchColumn();

      json_ok([
        'need_update' => ($pendingUsers > 0),
        'week_id' => $weekId,
        'pending_users' => $pendingUsers
      ]);

    } catch (Throwable $e) {
      json_err('Lỗi check week update notice');
    }
    break;


  case 'get_week_overview':
    try {
      $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 1;
      $weekMeta = get_duty_week_by_offset($pdo, $offset);
      $weekId = $weekMeta['week_id'];

      // lấy permission duty
      $permId = $pdo->query("
      SELECT id FROM permissions WHERE code = 'duty' LIMIT 1
    ")->fetchColumn();

      if (!$permId) {
        json_err('Chưa có permission duty');
      }

      /**
       * 1️⃣ TỔNG USER CÓ QUYỀN VIEW DUTY
       */
      $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT up.user_id)
      FROM user_permissions up
      WHERE up.permission_id = ?
        AND up.can_view = 1
    ");
      $stmt->execute([$permId]);
      $totalUsers = (int) $stmt->fetchColumn();

      /**
       * 2️⃣ ĐÃ ĐĂNG KÝ LỊCH RẢNH
       */
      $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT da.user_id)
      FROM duty_availability da
      JOIN user_permissions up 
        ON up.user_id = da.user_id
      WHERE da.week_id = ?
        AND up.permission_id = ?
        AND up.can_view = 1
    ");
      $stmt->execute([$weekId, $permId]);
      $registered = (int) $stmt->fetchColumn();

      /**
       * 3️⃣ TUẦN NÀY ĐÃ CÓ LỊCH TRỰC CHƯA?
       * Có record trong duty_assignments => đã có lịch
       */
      $stmt = $pdo->prepare("
  SELECT COUNT(*) 
  FROM duty_assignments
  WHERE week_id = ?
");
      $stmt->execute([$weekId]);
      $hasSchedule = ((int) $stmt->fetchColumn() > 0);

      json_ok([
        'total_users' => $totalUsers,
        'registered_users' => $registered,
        'unregistered_users' => max(0, $totalUsers - $registered),
        'has_schedule' => $hasSchedule,
      ]);


    } catch (Throwable $e) {
      json_err('Lỗi load tổng quan');
    }
    break;

  case 'get_week_members':
    try {
      $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 1;
      $weekMeta = get_duty_week_by_offset($pdo, $offset);
      $weekId = $weekMeta['week_id'];

      // permission duty
      $permId = $pdo->query("
      SELECT id FROM permissions WHERE code = 'duty' LIMIT 1
    ")->fetchColumn();

      if (!$permId) {
        json_err('Chưa có permission duty');
      }

      /**
       * Lấy danh sách user có quyền duty
       * fullname: ưu tiên members.fullname → fallback users.fullname
       * avatar: lấy từ users.avatar_url
       */
      $stmt = $pdo->prepare("
      SELECT 
        u.id,
        COALESCE(m.fullname, u.fullname) AS fullname,
        u.username,
        u.avatar_url,
        COUNT(da.id) AS free_count

      FROM users u

      JOIN user_permissions up
        ON up.user_id = u.id
       AND up.permission_id = ?
       AND up.can_view = 1

      LEFT JOIN members m
        ON m.user_id = u.id

      LEFT JOIN duty_availability da
        ON da.user_id = u.id
       AND da.week_id = ?

      GROUP BY u.id, m.fullname, u.fullname, u.username, u.avatar_url
      ORDER BY free_count DESC, fullname
    ");

      $stmt->execute([$permId, $weekId]);

      json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));

    } catch (Throwable $e) {
      json_err('Lỗi load danh sách BCH');
    }
    break;



  case 'get_my_week_schedule':
    try {
      $weekId = get_current_duty_week_id($pdo);

      $stmt = $pdo->prepare("
      SELECT day, shift
      FROM duty_assignments
      WHERE user_id = ?
        AND week_id = ?
    ");
      $stmt->execute([$userId, $weekId]);

      json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
      json_err('Lỗi load lịch trực');
    }
    break;


  case 'generate_week':
    try {
      $input = json_decode(file_get_contents('php://input'), true);
      $userIds = $input['user_ids'] ?? [];
      if (!is_array($userIds) || empty($userIds))
        json_err('Chưa chọn thành viên để xếp lịch');

      $userIds = array_values(array_unique(array_map('intval', $userIds)));
      if (count($userIds) === 0)
        json_err('Danh sách user không hợp lệ');

      $weekId = get_current_duty_week_id($pdo);
      $pdo->beginTransaction();

      $pdo->prepare("DELETE FROM duty_assignments WHERE week_id=?")->execute([$weekId]);

      $MIN_PER_SHIFT = 2;
      $MAX_PER_SHIFT = 3;
      $MAX_SCORE_PER_USER = 3.0;

      $dayMap = [2 => 'T2', 3 => 'T3', 4 => 'T4', 5 => 'T5', 6 => 'T6'];
      $shiftMap = ['morning' => 'sang', 'afternoon' => 'chieu'];

      $score = [];
      foreach ($userIds as $uid)
        $score[$uid] = 0.0;

      /* =========================
         PREFETCH STUDY + AVAIL
      ========================= */
      $study = [];
      $st = $pdo->prepare("
      SELECT user_id, day, shift
      FROM duty_study_schedule
      WHERE week_id=? AND has_class=1
        AND user_id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")
    ");
      $st->execute(array_merge([$weekId], $userIds));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $u = (int) $r['user_id'];
        $d = (int) $r['day'];
        $sh = (string) $r['shift'];
        $study[$u][$d][$sh] = true;
      }

      $avail = [];
      $st = $pdo->prepare("
      SELECT user_id, day, shift
      FROM duty_availability
      WHERE week_id=?
        AND user_id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")
    ");
      $st->execute(array_merge([$weekId], $userIds));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $u = (int) $r['user_id'];
        $d = (int) $r['day'];
        $sh = (string) $r['shift'];
        $avail[$u][$d][$sh] = true;
      }

      /* =========================
         HELPERS
      ========================= */
      $stableSort = function (array $uids, string $seed) {
        usort($uids, function ($a, $b) use ($seed) {
          $ha = sprintf('%u', crc32($seed . '-' . $a));
          $hb = sprintf('%u', crc32($seed . '-' . $b));
          if ($ha === $hb)
            return $a <=> $b;
          return ($ha < $hb) ? -1 : 1;
        });
        return $uids;
      };

      $sortUsersByScoreAsc = function () use (&$userIds, &$score) {
        $tmp = $userIds;
        usort($tmp, function ($a, $b) use (&$score) {
          $sa = $score[$a] ?? 0.0;
          $sb = $score[$b] ?? 0.0;
          if ($sa === $sb)
            return $a <=> $b;
          return ($sa < $sb) ? -1 : 1;
        });
        return $tmp;
      };

      $countSlot = function (int $weekId, string $dayEnum, string $shift) use ($pdo): int {
        $st = $pdo->prepare("SELECT COUNT(*) FROM duty_assignments WHERE week_id=? AND day=? AND shift=?");
        $st->execute([$weekId, $dayEnum, $shift]);
        return (int) $st->fetchColumn();
      };

      $hasAssignment = function (int $uid, int $weekId, string $dayEnum, string $shift) use ($pdo): bool {
        $st = $pdo->prepare("
        SELECT 1 FROM duty_assignments
        WHERE week_id=? AND user_id=? AND day=? AND shift=?
        LIMIT 1
      ");
        $st->execute([$weekId, $uid, $dayEnum, $shift]);
        return (bool) $st->fetchColumn();
      };

      $addAssignment = function (int $uid, int $weekId, string $dayEnum, string $shift, string $type, float $addScore) use ($pdo, &$score, $MAX_SCORE_PER_USER) {
        if (!isset($score[$uid]))
          return false;
        if (($score[$uid] ?? 0) + $addScore > $MAX_SCORE_PER_USER)
          return false;

        $pdo->prepare("
        INSERT INTO duty_assignments (week_id,user_id,day,shift,type,score)
        VALUES (?,?,?,?,?,?)
      ")->execute([$weekId, $uid, $dayEnum, $shift, $type, $addScore]);

        $score[$uid] += $addScore;
        return true;
      };

      // MAIN: phải đăng ký rảnh ca đó AND không trùng lịch học
      $canMain = function (int $uid, int $dayNum, string $availShift) use (&$avail, &$study): bool {
        if (empty($avail[$uid][$dayNum][$availShift]))
          return false;
        if (!empty($study[$uid][$dayNum][$availShift]))
          return false;
        return true;
      };

      // BREAK: (có học ca đó) OR (có đăng ký break_ tương ứng)
      $canBreak = function (int $uid, int $dayNum, string $availShift) use (&$avail, &$study): bool {
        if ($availShift === 'morning') {
          $hasStudy = !empty($study[$uid][$dayNum]['morning']);
          $hasBreak = !empty($avail[$uid][$dayNum]['break_morning']);
          return ($hasStudy || $hasBreak);
        } else {
          $hasStudy = !empty($study[$uid][$dayNum]['afternoon']);
          $hasBreak = !empty($avail[$uid][$dayNum]['break_afternoon']);
          return ($hasStudy || $hasBreak);
        }
      };

      /* =========================
         BUILD MAIN CELLS (10)
      ========================= */
      $mainCells = [];
      foreach ($dayMap as $dayNum => $dayEnum) {
        foreach ($shiftMap as $availShift => $mainShiftEnum) {
          $mainCells[] = [
            'kind' => 'main',
            'dayNum' => $dayNum,
            'dayEnum' => $dayEnum,
            'availShift' => $availShift,
            'shiftEnum' => $mainShiftEnum,
            'type' => 'thuong',
            'addScore' => 1.0
          ];
        }
      }

      /* =========================
         BUILD BREAK CELLS (10)
      ========================= */
      $breakCells = [];
      foreach ($dayMap as $dayNum => $dayEnum) {
        foreach ($shiftMap as $availShift => $mainShiftEnum) {
          $breakCells[] = [
            'kind' => 'break',
            'dayNum' => $dayNum,
            'dayEnum' => $dayEnum,
            'availShift' => $availShift,
            'shiftEnum' => ($availShift === 'morning' ? 'rachoi_s' : 'rachoi_c'),
            'type' => 'rachoi',
            'addScore' => 0.5
          ];
        }
      }

      $fillBaseline = function (array $cells, callable $canFn, string $seedPrefix) use ($weekId, $MIN_PER_SHIFT, $countSlot, $sortUsersByScoreAsc, $stableSort, $hasAssignment, $addAssignment, &$score) {
        for ($target = 1; $target <= $MIN_PER_SHIFT; $target++) {

          $ordered = $cells;
          usort($ordered, function ($a, $b) use ($weekId, $countSlot, $target) {
            $ca = $countSlot($weekId, $a['dayEnum'], $a['shiftEnum']);
            $cb = $countSlot($weekId, $b['dayEnum'], $b['shiftEnum']);
            $ra = ($ca < $target) ? 0 : 1;
            $rb = ($cb < $target) ? 0 : 1;
            if ($ra !== $rb)
              return $ra <=> $rb;
            if ($ca !== $cb)
              return $ca <=> $cb;
            $ha = sprintf('%u', crc32("cell-{$weekId}-{$a['dayEnum']}-{$a['shiftEnum']}"));
            $hb = sprintf('%u', crc32("cell-{$weekId}-{$b['dayEnum']}-{$b['shiftEnum']}"));
            if ($ha === $hb)
              return 0;
            return ($ha < $hb) ? -1 : 1;
          });

          foreach ($ordered as $cell) {
            while (true) {
              $cur = $countSlot($weekId, $cell['dayEnum'], $cell['shiftEnum']);
              if ($cur >= $target)
                break;

              $uids = $sortUsersByScoreAsc();
              $uids = $stableSort($uids, "{$seedPrefix}-{$target}-{$weekId}-{$cell['dayEnum']}-{$cell['shiftEnum']}");

              $picked = false;
              foreach ($uids as $uid) {
                if (($score[$uid] ?? 0) >= 3.0)
                  continue;
                if (!$canFn($uid, $cell['dayNum'], $cell['availShift']))
                  continue;
                if ($hasAssignment($uid, $weekId, $cell['dayEnum'], $cell['shiftEnum']))
                  continue;

                if ($addAssignment($uid, $weekId, $cell['dayEnum'], $cell['shiftEnum'], $cell['type'], $cell['addScore'])) {
                  $picked = true;
                  break;
                }
              }
              if (!$picked)
                break;
            }
          }
        }
      };

      /* ==================================================
         1) BASELINE MAIN TRƯỚC (0->1->2)
      ================================================== */
      $fillBaseline($mainCells, $canMain, 'main');

      /* ==================================================
         2) BÙ USER BẰNG MAIN TRƯỚC
      ================================================== */
      $pickBestMainForUser = function (int $uid) use (&$mainCells, $weekId, $countSlot, $MIN_PER_SHIFT, $MAX_PER_SHIFT, $canMain, $hasAssignment) {
        $cands = [];
        foreach ($mainCells as $cell) {
          if (!$canMain($uid, $cell['dayNum'], $cell['availShift']))
            continue;
          $cur = $countSlot($weekId, $cell['dayEnum'], $cell['shiftEnum']);
          if ($cur >= $MAX_PER_SHIFT)
            continue;
          if ($hasAssignment($uid, $weekId, $cell['dayEnum'], $cell['shiftEnum']))
            continue;
          $rank = ($cur < $MIN_PER_SHIFT) ? 0 : 1;
          $cands[] = [$rank, $cur, $cell];
        }
        if (!$cands)
          return null;
        usort($cands, function ($a, $b) use ($uid, $weekId) {
          if ($a[0] !== $b[0])
            return $a[0] <=> $b[0];
          if ($a[1] !== $b[1])
            return $a[1] <=> $b[1];
          $ha = sprintf('%u', crc32("u{$uid}-{$weekId}-{$a[2]['dayEnum']}-{$a[2]['shiftEnum']}"));
          $hb = sprintf('%u', crc32("u{$uid}-{$weekId}-{$b[2]['dayEnum']}-{$b[2]['shiftEnum']}"));
          if ($ha === $hb)
            return 0;
          return ($ha < $hb) ? -1 : 1;
        });
        return $cands[0][2];
      };

      $guard = 0;
      while (true) {
        $guard++;
        if ($guard > 4000)
          break;
        $changed = false;

        $uids = $sortUsersByScoreAsc();
        foreach ($uids as $uid) {
          if (($score[$uid] ?? 0) >= 3.0)
            continue;

          // ƯU TIÊN MAIN
          $cell = $pickBestMainForUser($uid);
          if ($cell) {
            if ($addAssignment($uid, $weekId, $cell['dayEnum'], $cell['shiftEnum'], $cell['type'], $cell['addScore'])) {
              $changed = true;
            }
          }
        }

        if (!$changed)
          break;
      }

      /* ==================================================
         3) BASELINE BREAK SAU (0->1->2)
      ================================================== */
      $fillBaseline($breakCells, $canBreak, 'break');

      /* ==================================================
         4) BÙ USER BẰNG BREAK (NẾU CHƯA ĐỦ 3)
      ================================================== */
      $pickBestBreakForUser = function (int $uid) use (&$breakCells, $weekId, $countSlot, $MIN_PER_SHIFT, $MAX_PER_SHIFT, $canBreak, $hasAssignment) {
        $cands = [];
        foreach ($breakCells as $cell) {
          if (!$canBreak($uid, $cell['dayNum'], $cell['availShift']))
            continue;
          $cur = $countSlot($weekId, $cell['dayEnum'], $cell['shiftEnum']);
          if ($cur >= $MAX_PER_SHIFT)
            continue;
          if ($hasAssignment($uid, $weekId, $cell['dayEnum'], $cell['shiftEnum']))
            continue;
          $rank = ($cur < $MIN_PER_SHIFT) ? 0 : 1;
          $cands[] = [$rank, $cur, $cell];
        }
        if (!$cands)
          return null;
        usort($cands, function ($a, $b) use ($uid, $weekId) {
          if ($a[0] !== $b[0])
            return $a[0] <=> $b[0];
          if ($a[1] !== $b[1])
            return $a[1] <=> $b[1];
          $ha = sprintf('%u', crc32("ub{$uid}-{$weekId}-{$a[2]['dayEnum']}-{$a[2]['shiftEnum']}"));
          $hb = sprintf('%u', crc32("ub{$uid}-{$weekId}-{$b[2]['dayEnum']}-{$b[2]['shiftEnum']}"));
          if ($ha === $hb)
            return 0;
          return ($ha < $hb) ? -1 : 1;
        });
        return $cands[0][2];
      };

      $guard = 0;
      while (true) {
        $guard++;
        if ($guard > 6000)
          break;
        $changed = false;

        $uids = $sortUsersByScoreAsc();
        foreach ($uids as $uid) {
          if (($score[$uid] ?? 0) >= 3.0)
            continue;

          $cell = $pickBestBreakForUser($uid);
          if ($cell) {
            if ($addAssignment($uid, $weekId, $cell['dayEnum'], $cell['shiftEnum'], $cell['type'], $cell['addScore'])) {
              $changed = true;
            }
          }
        }

        if (!$changed)
          break;
      }

      /* ==================================================
         5) TOP-UP 2->3 (CHỈ KHI TẤT CẢ CELL >=2)
            - MAIN trước rồi BREAK
      ================================================== */
      $allCells = array_merge($mainCells, $breakCells);
      $anyCellUnderMin = function () use ($allCells, $weekId, $countSlot, $MIN_PER_SHIFT) {
        foreach ($allCells as $cell) {
          if ($countSlot($weekId, $cell['dayEnum'], $cell['shiftEnum']) < $MIN_PER_SHIFT)
            return true;
        }
        return false;
      };

      if (!$anyCellUnderMin()) {
        // top-up MAIN
        foreach ($mainCells as $cell) {
          $cur = $countSlot($weekId, $cell['dayEnum'], $cell['shiftEnum']);
          if ($cur < 2 || $cur >= 3)
            continue;

          $uids = $sortUsersByScoreAsc();
          $uids = $stableSort($uids, "top-main-{$weekId}-{$cell['dayEnum']}-{$cell['shiftEnum']}");
          foreach ($uids as $uid) {
            if (($score[$uid] ?? 0) >= 3.0)
              continue;
            if (!$canMain($uid, $cell['dayNum'], $cell['availShift']))
              continue;
            if ($hasAssignment($uid, $weekId, $cell['dayEnum'], $cell['shiftEnum']))
              continue;

            if ($addAssignment($uid, $weekId, $cell['dayEnum'], $cell['shiftEnum'], 'thuong', 1.0))
              break;
          }
        }

        // top-up BREAK
        foreach ($breakCells as $cell) {
          $cur = $countSlot($weekId, $cell['dayEnum'], $cell['shiftEnum']);
          if ($cur < 2 || $cur >= 3)
            continue;

          $uids = $sortUsersByScoreAsc();
          $uids = $stableSort($uids, "top-break-{$weekId}-{$cell['dayEnum']}-{$cell['shiftEnum']}");
          foreach ($uids as $uid) {
            if (($score[$uid] ?? 0) >= 3.0)
              continue;
            if (!$canBreak($uid, $cell['dayNum'], $cell['availShift']))
              continue;
            if ($hasAssignment($uid, $weekId, $cell['dayEnum'], $cell['shiftEnum']))
              continue;

            if ($addAssignment($uid, $weekId, $cell['dayEnum'], $cell['shiftEnum'], 'rachoi', 0.5))
              break;
          }
        }
      }

      $pdo->commit();
      json_ok(['week_id' => $weekId, 'score' => $score]);

    } catch (Throwable $e) {
      $pdo->rollBack();
      json_err('Lỗi xếp lịch');
    }
    break;




  case 'get_week_schedule':
    try {
      $offset = (int) ($_GET['offset'] ?? 1);
      $meta = get_duty_week_by_offset($pdo, $offset);
      $weekId = (int) $meta['week_id'];

      $stmt = $pdo->prepare("
      SELECT
        da.id,
        da.user_id,
        da.day,
        da.shift,
        da.type,
        da.score, -- 👈 TRẢ ĐIỂM THẬT
        COALESCE(m.fullname, u.fullname, u.username) AS fullname

      FROM duty_assignments da
      JOIN users u ON u.id = da.user_id
      LEFT JOIN members m ON m.user_id = u.id

      WHERE da.week_id = ?

      ORDER BY
        FIELD(da.day, 'T2','T3','T4','T5','T6'),
        FIELD(da.shift, 'sang','chieu','rachoi_s','rachoi_c'),
        da.id ASC
    ");

      $stmt->execute([$weekId]);

      json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));

    } catch (Throwable $e) {
      json_err('Lỗi load lịch trực');
    }
    break;


  case 'bulk_delete_assignments':
    auth_guard();
    if (!can('duty', 'update'))
      json_err('Forbidden', 403);

    $data = json_decode(file_get_contents('php://input'), true);
    $items = $data['items'] ?? [];

    if (!is_array($items) || count($items) === 0) {
      json_err('Danh sách xóa rỗng');
    }

    // giới hạn để tránh spam
    if (count($items) > 200) {
      json_err('Tối đa 200 mục/lần');
    }

    $validDays = ['T2', 'T3', 'T4', 'T5', 'T6'];
    $validShifts = ['sang', 'chieu', 'rachoi_s', 'rachoi_c'];

    // ✅ giữ đúng logic hiện tại: thao tác trên "tuần sau"
    $weekId = get_current_duty_week_id($pdo);

    try {
      $pdo->beginTransaction();

      $del = $pdo->prepare("
      DELETE FROM duty_assignments
      WHERE week_id=? AND user_id=? AND day=? AND shift=?
      ORDER BY id ASC
      LIMIT 1
    ");

      $deleted = 0;

      foreach ($items as $it) {
        $uid = (int) ($it['user_id'] ?? 0);
        $day = (string) ($it['day'] ?? '');
        $shift = (string) ($it['shift'] ?? '');

        if (!$uid || !in_array($day, $validDays, true) || !in_array($shift, $validShifts, true)) {
          continue; // bỏ qua item lỗi
        }

        $del->execute([$weekId, $uid, $day, $shift]);
        $deleted += (int) $del->rowCount();
      }

      $pdo->commit();

      log_activity('delete', 'duty', 'assignment', null, "Admin xóa hàng loạt {$deleted} ca trực");
      json_ok(['deleted' => $deleted]);

    } catch (Throwable $e) {
      $pdo->rollBack();
      json_err('Lỗi xóa hàng loạt');
    }
    break;



  case 'get_free_stats':
    try {
      $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 1;
      $weekMeta = get_duty_week_by_offset($pdo, $offset);
      $weekId = $weekMeta['week_id'];

      // permission duty
      $permId = $pdo->query("
      SELECT id FROM permissions WHERE code='duty' LIMIT 1
    ")->fetchColumn();

      if (!$permId)
        json_err('Missing duty permission');

      /**
       * ======================
       * 1️⃣ SÁNG / CHIỀU
       * ======================
       */
      $stmt = $pdo->prepare("
      SELECT 
        da.day,
        da.shift,
        COUNT(DISTINCT da.user_id) AS free
      FROM duty_availability da

      JOIN user_permissions up
        ON up.user_id = da.user_id
       AND up.permission_id = ?
       AND up.can_view = 1

      LEFT JOIN duty_study_schedule ds
        ON ds.user_id = da.user_id
       AND ds.week_id = da.week_id
       AND ds.day = da.day
       AND ds.shift = da.shift

      LEFT JOIN duty_assignments asg
        ON asg.user_id = da.user_id
      AND asg.week_id = da.week_id
      AND asg.day = da.day
      AND asg.shift = CASE
          WHEN da.shift = 'morning' THEN 'sang'
          WHEN da.shift = 'afternoon' THEN 'chieu'
          ELSE NULL
      END


      WHERE da.week_id = ?
        AND ds.id IS NULL        -- không có lịch học
        AND asg.id IS NULL       -- chưa bị xếp trực

      GROUP BY da.day, da.shift
    ");
      $stmt->execute([$permId, $weekId]);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

      /**
       * ======================
       * 2️⃣ RA CHƠI (assignable)
       * ======================
       */
      $stmt = $pdo->prepare("
      SELECT
        d.day,
        'break' AS shift,
        COUNT(DISTINCT u.id) AS free
      FROM (
        SELECT 2 day UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
      ) d

      JOIN users u
      JOIN user_permissions up
        ON up.user_id = u.id
       AND up.permission_id = ?
       AND up.can_view = 1

      LEFT JOIN (
        SELECT user_id, day, COUNT(*) cnt
        FROM duty_assignments
        WHERE week_id = ?
        GROUP BY user_id, day
      ) da ON da.user_id = u.id AND da.day = d.day

      LEFT JOIN (
        SELECT user_id, COUNT(*) cnt
        FROM duty_assignments
        WHERE week_id = ?
        GROUP BY user_id
      ) total ON total.user_id = u.id

      WHERE COALESCE(total.cnt, 0) < 3      -- chưa đủ 3 buổi
        AND COALESCE(da.cnt, 0) = 0         -- chưa trực ngày đó

      GROUP BY d.day
    ");
      $stmt->execute([$permId, $weekId, $weekId]);
      $breakRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

      json_ok(array_merge($rows, $breakRows));

    } catch (Throwable $e) {
      json_err('Lỗi thống kê thời gian rảnh');
    }
    break;

  case 'move_assignment':
    auth_guard();

    if (!can('duty', 'update')) {
      json_err('Forbidden', 403);
    }

    $data = json_decode(file_get_contents('php://input'), true);

    $targetUserId = (int) ($data['user_id'] ?? 0);
    $fromDay = $data['from_day'] ?? null;
    $fromShift = $data['from_shift'] ?? null;
    $toDay = $data['to_day'] ?? null;
    $toShift = $data['to_shift'] ?? null;

    // NEW: copy mode
    $isCopy = !empty($data['copy']); // true => nhân đôi (giữ ca cũ)

    if (!$targetUserId || !$fromDay || !$fromShift || !$toDay || !$toShift) {
      json_err('Thiếu dữ liệu kéo thả');
    }

    $validDays = ['T2', 'T3', 'T4', 'T5', 'T6'];
    $validShifts = ['sang', 'chieu', 'rachoi_s', 'rachoi_c'];

    if (!in_array($fromDay, $validDays, true) || !in_array($toDay, $validDays, true)) {
      json_err('Ngày không hợp lệ');
    }
    if (!in_array($fromShift, $validShifts, true) || !in_array($toShift, $validShifts, true)) {
      json_err('Ca không hợp lệ');
    }

    // helper: shift -> (type, score)
    $metaForShift = function (string $shiftEnum): array {
      if (in_array($shiftEnum, ['rachoi_s', 'rachoi_c'], true)) {
        return ['type' => 'rachoi', 'score' => 0.5];
      }
      return ['type' => 'thuong', 'score' => 1.0];
    };

    // helper: map dayEnum/shiftEnum -> dayNum + availShift (morning/afternoon) for validation
    $dayEnumToNum = function (string $dayEnum): int {
      return (int) str_replace('T', '', $dayEnum); // T2->2
    };
    $shiftEnumToAvail = function (string $shiftEnum): array {
      // returns ['availShift'=>'morning|afternoon', 'isBreak'=>bool]
      if ($shiftEnum === 'sang')
        return ['availShift' => 'morning', 'isBreak' => false];
      if ($shiftEnum === 'chieu')
        return ['availShift' => 'afternoon', 'isBreak' => false];
      if ($shiftEnum === 'rachoi_s')
        return ['availShift' => 'morning', 'isBreak' => true];
      return ['availShift' => 'afternoon', 'isBreak' => true]; // rachoi_c
    };

    $weekId = get_current_duty_week_id($pdo);

    try {
      $pdo->beginTransaction();

      // 0) đảm bảo FROM record tồn tại + lấy score cũ để tính lại
      $st = $pdo->prepare("
      SELECT id, score
      FROM duty_assignments
      WHERE week_id=? AND user_id=? AND day=? AND shift=?
      ORDER BY id ASC
      LIMIT 1
    ");
      $st->execute([$weekId, $targetUserId, $fromDay, $fromShift]);
      $fromRow = $st->fetch(PDO::FETCH_ASSOC);
      if (!$fromRow) {
        $pdo->rollBack();
        json_err('Không tìm thấy ca để cập nhật');
      }
      $fromId = (int) $fromRow['id'];
      $oldScore = (float) $fromRow['score'];

      // 1) CHECK: 1 ca không quá 3 người (nếu sang ô khác)
      $limit = 3;
      if (!($fromDay === $toDay && $fromShift === $toShift)) {
        $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM duty_assignments
        WHERE week_id=? AND day=? AND shift=?
      ");
        $st->execute([$weekId, $toDay, $toShift]);
        $cntInTarget = (int) $st->fetchColumn();
        if ($cntInTarget >= $limit) {
          $pdo->rollBack();
          json_err("Ca này đã đủ {$limit} người");
        }
      }

      // 2) CHECK: không cho trùng đúng 1 người trong cùng 1 ca (đỡ rác UI)
      // (Toro muốn cho phép 1 người xuất hiện 2 lần cùng ca thì bỏ đoạn này)
      if (!($fromDay === $toDay && $fromShift === $toShift)) {
        $st = $pdo->prepare("
        SELECT 1 FROM duty_assignments
        WHERE week_id=? AND user_id=? AND day=? AND shift=?
        LIMIT 1
      ");
        $st->execute([$weekId, $targetUserId, $toDay, $toShift]);
        if ($st->fetchColumn()) {
          $pdo->rollBack();
          json_err("Người này đã có trong ca đích");
        }
      }

      // 3) CHECK: điểm tối đa 3.0 (move thì -old + new, copy thì +new)
      $newMeta = $metaForShift($toShift);
      $newType = $newMeta['type'];
      $newScore = (float) $newMeta['score'];

      $st = $pdo->prepare("SELECT COALESCE(SUM(score),0) FROM duty_assignments WHERE week_id=? AND user_id=?");
      $st->execute([$weekId, $targetUserId]);
      $currentTotal = (float) $st->fetchColumn();

      $afterTotal = $isCopy ? ($currentTotal + $newScore) : ($currentTotal - $oldScore + $newScore);
      if ($afterTotal > 5.0 + 1e-9) {
        $pdo->rollBack();
        json_err("Người này sẽ vượt quá 5 điểm/tuần");
      }

      // 4) APPLY
      if ($isCopy) {
        // COPY: insert record mới ở toDay/toShift, giữ record cũ
        $pdo->prepare("
        INSERT INTO duty_assignments (week_id,user_id,day,shift,type,score)
        VALUES (?,?,?,?,?,?)
      ")->execute([$weekId, $targetUserId, $toDay, $toShift, $newType, $newScore]);

        log_activity('create', 'duty', 'assignment', null, "Admin nhân đôi ca trực (copy)");
      } else {
        // MOVE: update đúng record FROM (theo id), tránh lỡ update nhầm khi có duplicate
        $pdo->prepare("
        UPDATE duty_assignments
        SET day=?, shift=?, type=?, score=?
        WHERE id=? AND week_id=? AND user_id=?
        LIMIT 1
      ")->execute([$toDay, $toShift, $newType, $newScore, $fromId, $weekId, $targetUserId]);

        log_activity('update', 'duty', 'assignment', null, "Admin kéo thả đổi ca trực (move)");
      }

      $pdo->commit();
      json_ok(['copy' => $isCopy]);

    } catch (Throwable $e) {
      $pdo->rollBack();
      json_err('Lỗi cập nhật lịch trực');
    }

    break;

  case 'delete_assignment':
    auth_guard();
    if (!can('duty', 'update'))
      json_err('Forbidden', 403);

    $data = json_decode(file_get_contents('php://input'), true);
    $targetUserId = (int) ($data['user_id'] ?? 0);
    $day = $data['day'] ?? '';
    $shift = $data['shift'] ?? '';

    $validDays = ['T2', 'T3', 'T4', 'T5', 'T6'];
    $validShifts = ['sang', 'chieu', 'rachoi_s', 'rachoi_c'];
    if (!$targetUserId || !in_array($day, $validDays, true) || !in_array($shift, $validShifts, true)) {
      json_err('Dữ liệu xoá không hợp lệ');
    }

    $weekId = get_current_duty_week_id($pdo);

    try {
      $pdo->beginTransaction();

      $st = $pdo->prepare("
      DELETE FROM duty_assignments
      WHERE week_id=? AND user_id=? AND day=? AND shift=?
      ORDER BY id ASC
      LIMIT 1
    ");
      $st->execute([$weekId, $targetUserId, $day, $shift]);

      if ($st->rowCount() === 0) {
        $pdo->rollBack();
        json_err('Không tìm thấy ca để xoá');
      }

      log_activity('delete', 'duty', 'assignment', null, "Admin xoá ca trực bằng kéo thả");
      $pdo->commit();
      json_ok();

    } catch (Throwable $e) {
      $pdo->rollBack();
      json_err('Lỗi xoá ca trực');
    }

    break;

  case 'add_assignment':
    auth_guard();
    if (!can('duty', 'update'))
      json_err('Forbidden', 403);

    $data = json_decode(file_get_contents('php://input'), true);

    $targetUserId = (int) ($data['user_id'] ?? 0);
    $day = $data['day'] ?? '';
    $shift = $data['shift'] ?? '';

    $validDays = ['T2', 'T3', 'T4', 'T5', 'T6'];
    $validShifts = ['sang', 'chieu', 'rachoi_s', 'rachoi_c'];

    if (!$targetUserId || !in_array($day, $validDays, true) || !in_array($shift, $validShifts, true)) {
      json_err('Dữ liệu thêm không hợp lệ');
    }

    $weekId = get_current_duty_week_id($pdo);

    $metaForShift = function (string $shiftEnum): array {
      if (in_array($shiftEnum, ['rachoi_s', 'rachoi_c'], true))
        return ['type' => 'rachoi', 'score' => 0.5];
      return ['type' => 'thuong', 'score' => 1.0];
    };

    try {
      $pdo->beginTransaction();

      // limit 3 người / ca
      $st = $pdo->prepare("SELECT COUNT(*) FROM duty_assignments WHERE week_id=? AND day=? AND shift=?");
      $st->execute([$weekId, $day, $shift]);
      if ((int) $st->fetchColumn() >= 3) {
        $pdo->rollBack();
        json_err("Ca này đã đủ 3 người");
      }

      // không trùng user trong cùng ca
      $st = $pdo->prepare("SELECT 1 FROM duty_assignments WHERE week_id=? AND user_id=? AND day=? AND shift=? LIMIT 1");
      $st->execute([$weekId, $targetUserId, $day, $shift]);
      if ($st->fetchColumn()) {
        $pdo->rollBack();
        json_err("Người này đã có trong ca");
      }

      // điểm <=3
      $meta = $metaForShift($shift);
      $addScore = (float) $meta['score'];

      $st = $pdo->prepare("SELECT COALESCE(SUM(score),0) FROM duty_assignments WHERE week_id=? AND user_id=?");
      $st->execute([$weekId, $targetUserId]);
      $total = (float) $st->fetchColumn();

      if ($total + $addScore > 5.0 + 1e-9) {
        $pdo->rollBack();
        json_err("Người này sẽ vượt quá 5 điểm/tuần");
      }

      $pdo->prepare("
      INSERT INTO duty_assignments (week_id,user_id,day,shift,type,score)
      VALUES (?,?,?,?,?,?)
    ")->execute([$weekId, $targetUserId, $day, $shift, $meta['type'], $addScore]);

      log_activity('create', 'duty', 'assignment', null, 'Admin thêm người vào ca trực (manual add)');
      $pdo->commit();
      json_ok();

    } catch (Throwable $e) {
      $pdo->rollBack();
      json_err('Lỗi thêm người');
    }

    break;


  case 'save_selected_users':

    if (!can('duty', 'update')) {
      json_err('Forbidden', 403);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $userIds = $data['user_ids'] ?? [];

    if (!is_array($userIds) || count($userIds) === 0) {
      json_err('Chưa chọn thành viên nào');
    }

    $weekId = get_current_duty_week_id($pdo);

    try {
      $pdo->beginTransaction();

      // reset danh sách cũ
      $pdo->prepare("
      DELETE FROM duty_selected_users
      WHERE week_id = ?
    ")->execute([$weekId]);

      $stmt = $pdo->prepare("
      INSERT INTO duty_selected_users (week_id, user_id)
      VALUES (?, ?)
    ");

      foreach ($userIds as $uid) {
        $stmt->execute([$weekId, (int) $uid]);
      }

      log_activity(
        'update',
        'duty',
        'selection',
        null,
        'Admin chọn danh sách xếp lịch trực'
      );

      $pdo->commit();
      json_ok();

    } catch (Throwable $e) {
      $pdo->rollBack();
      json_err('Lỗi lưu danh sách');
    }

    break;

  case 'get_user_availability':
    auth_guard();
    if (!can('duty', 'view')) {
      json_err('Forbidden', 403);
    }
    $targetUid = (int) ($_GET['user_id'] ?? 0);
    if (!$targetUid) {
      json_err('Thiếu User ID');
    }
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 1;
    $weekMeta = get_duty_week_by_offset($pdo, $offset);
    $weekId = $weekMeta['week_id'];

    // Lấy availability
    $stmt = $pdo->prepare("
      SELECT day, shift
      FROM duty_availability
      WHERE user_id = ? AND week_id = ?
    ");
    $stmt->execute([$targetUid, $weekId]);
    $avail = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Lấy study
    $stmt = $pdo->prepare("
      SELECT day, shift
      FROM duty_study_schedule
      WHERE user_id = ? AND week_id = ? AND has_class = 1
    ");
    $stmt->execute([$targetUid, $weekId]);
    $study = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Lấy assignments hiện tại
    $stmt = $pdo->prepare("
      SELECT id, day, shift, type, score
      FROM duty_assignments
      WHERE user_id = ? AND week_id = ?
    ");
    $stmt->execute([$targetUid, $weekId]);
    $assigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_ok([
      'availability' => $avail,
      'study' => $study,
      'assignments' => $assigns
    ]);
    break;

  case 'delete_user_assignments':
    auth_guard();
    if (!can('duty', 'update')) {
      json_err('Forbidden', 403);
    }
    $targetUid = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    if (!$targetUid) {
      json_err('Thiếu User ID');
    }
    $weekId = get_current_duty_week_id($pdo);

    try {
      $pdo->beginTransaction();
      $stmt = $pdo->prepare("
        DELETE FROM duty_assignments
        WHERE user_id = ? AND week_id = ?
      ");
      $stmt->execute([$targetUid, $weekId]);
      $deletedCount = $stmt->rowCount();

      log_activity(
        'delete',
        'duty',
        'assignment',
        null,
        "Admin xóa toàn bộ lịch trực của user ID $targetUid cho tuần ID $weekId (Xóa $deletedCount ca)"
      );

      $pdo->commit();
      json_ok(['deleted' => $deletedCount]);
    } catch (Throwable $e) {
      $pdo->rollBack();
      json_err('Lỗi xóa lịch trực thành viên: ' . $e->getMessage());
    }
    break;

  case 'get_week_availability_matrix':
    try {
      $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 1;
      $weekMeta = get_duty_week_by_offset($pdo, $offset);
      $weekId = $weekMeta['week_id'];

      // permission duty
      $permId = $pdo->query("SELECT id FROM permissions WHERE code='duty' LIMIT 1")->fetchColumn();
      if (!$permId) {
        json_err('Missing duty permission');
      }

      // Lấy toàn bộ lịch rảnh của các user có quyền duty trong tuần này
      $stmt = $pdo->prepare("
        SELECT da.user_id, da.day, da.shift
        FROM duty_availability da
        JOIN user_permissions up ON up.user_id = da.user_id
        WHERE da.week_id = ? AND up.permission_id = ? AND up.can_view = 1
      ");
      $stmt->execute([$weekId, $permId]);
      $availabilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

      // Lấy toàn bộ lịch học của các user có quyền duty trong tuần này
      $stmt = $pdo->prepare("
        SELECT ds.user_id, ds.day, ds.shift
        FROM duty_study_schedule ds
        JOIN user_permissions up ON up.user_id = ds.user_id
        WHERE ds.week_id = ? AND ds.has_class = 1 AND up.permission_id = ? AND up.can_view = 1
      ");
      $stmt->execute([$weekId, $permId]);
      $studySchedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

      $matrix = [];
      // Khởi tạo cho toàn bộ user có quyền duty để FE có đủ danh sách
      $stmt = $pdo->prepare("
        SELECT u.id
        FROM users u
        JOIN user_permissions up ON up.user_id = u.id
        WHERE up.permission_id = ? AND up.can_view = 1
      ");
      $stmt->execute([$permId]);
      $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
      
      foreach ($userIds as $uid) {
        $matrix[(int)$uid] = [
          'availability' => [],
          'study' => []
        ];
      }

      foreach ($availabilities as $a) {
        $uid = (int)$a['user_id'];
        if (isset($matrix[$uid])) {
          $matrix[$uid]['availability'][] = [
            'day' => (int)$a['day'],
            'shift' => $a['shift']
          ];
        }
      }

      foreach ($studySchedules as $s) {
        $uid = (int)$s['user_id'];
        if (isset($matrix[$uid])) {
          $matrix[$uid]['study'][] = [
            'day' => (int)$s['day'],
            'shift' => $s['shift']
          ];
        }
      }

      json_ok($matrix);
    } catch (Throwable $e) {
      json_err('Lỗi load ma trận lịch tuần: ' . $e->getMessage());
    }
    break;

  case 'suggest_week':
    try {
      $input = json_decode(file_get_contents('php://input'), true);
      $userIds = $input['user_ids'] ?? [];
      if (!is_array($userIds) || empty($userIds))
        json_err('Chưa chọn thành viên để gợi ý');

      $userIds = array_values(array_unique(array_map('intval', $userIds)));
      if (count($userIds) === 0)
        json_err('Danh sách user không hợp lệ');

      $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 1;
      $weekMeta = get_duty_week_by_offset($pdo, $offset);
      $weekId = $weekMeta['week_id'];

      $MIN_PER_SHIFT = 2;
      $MAX_PER_SHIFT = 3;
      $MAX_SCORE_PER_USER = 3.0;

      $dayMap = [2 => 'T2', 3 => 'T3', 4 => 'T4', 5 => 'T5', 6 => 'T6'];
      $shiftMap = ['morning' => 'sang', 'afternoon' => 'chieu'];

      $score = [];
      foreach ($userIds as $uid)
        $score[$uid] = 0.0;

      /* =========================
         PREFETCH STUDY + AVAIL
      ========================= */
      $study = [];
      $st = $pdo->prepare("
      SELECT user_id, day, shift
      FROM duty_study_schedule
      WHERE week_id=? AND has_class=1
        AND user_id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")
    ");
      $st->execute(array_merge([$weekId], $userIds));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $u = (int) $r['user_id'];
        $d = (int) $r['day'];
        $sh = (string) $r['shift'];
        $study[$u][$d][$sh] = true;
      }

      $avail = [];
      $st = $pdo->prepare("
      SELECT user_id, day, shift
      FROM duty_availability
      WHERE week_id=?
        AND user_id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")
    ");
      $st->execute(array_merge([$weekId], $userIds));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $u = (int) $r['user_id'];
        $d = (int) $r['day'];
        $sh = (string) $r['shift'];
        $avail[$u][$d][$sh] = true;
      }

      /* =========================
         HELPERS ON RAM
      ========================= */
      $assignmentsDraft = [];

      $countSlot = function (string $dayEnum, string $shift) use (&$assignmentsDraft): int {
        $cnt = 0;
        foreach ($assignmentsDraft as $a) {
          if ($a['day'] === $dayEnum && $a['shift'] === $shift) {
            $cnt++;
          }
        }
        return $cnt;
      };

      $hasAssignment = function (int $uid, string $dayEnum, string $shift) use (&$assignmentsDraft): bool {
        foreach ($assignmentsDraft as $a) {
          if ($a['user_id'] === $uid && $a['day'] === $dayEnum && $a['shift'] === $shift) {
            return true;
          }
        }
        return false;
      };

      $addAssignment = function (int $uid, string $dayEnum, string $shift, string $type, float $addScore) use (&$assignmentsDraft, &$score, $MAX_SCORE_PER_USER) {
        if (!isset($score[$uid]))
          return false;
        if (($score[$uid] ?? 0) + $addScore > $MAX_SCORE_PER_USER)
          return false;

        $assignmentsDraft[] = [
          'user_id' => $uid,
          'day' => $dayEnum,
          'shift' => $shift,
          'type' => $type,
          'score' => $addScore
        ];

        $score[$uid] += $addScore;
        return true;
      };

      $stableSort = function (array $uids, string $seed) {
        usort($uids, function ($a, $b) use ($seed) {
          $ha = sprintf('%u', crc32($seed . '-' . $a));
          $hb = sprintf('%u', crc32($seed . '-' . $b));
          if ($ha === $hb)
            return $a <=> $b;
          return ($ha < $hb) ? -1 : 1;
        });
        return $uids;
      };

      $sortUsersByScoreAsc = function () use (&$userIds, &$score) {
        $tmp = $userIds;
        usort($tmp, function ($a, $b) use (&$score) {
          $sa = $score[$a] ?? 0.0;
          $sb = $score[$b] ?? 0.0;
          if ($sa === $sb)
            return $a <=> $b;
          return ($sa < $sb) ? -1 : 1;
        });
        return $tmp;
      };

      $canMain = function (int $uid, int $dayNum, string $availShift) use (&$avail, &$study): bool {
        if (empty($avail[$uid][$dayNum][$availShift]))
          return false;
        if (!empty($study[$uid][$dayNum][$availShift]))
          return false;
        return true;
      };

      $canBreak = function (int $uid, int $dayNum, string $availShift) use (&$avail, &$study): bool {
        if ($availShift === 'morning') {
          $hasStudy = !empty($study[$uid][$dayNum]['morning']);
          $hasBreak = !empty($avail[$uid][$dayNum]['break_morning']);
          return ($hasStudy || $hasBreak);
        } else {
          $hasStudy = !empty($study[$uid][$dayNum]['afternoon']);
          $hasBreak = !empty($avail[$uid][$dayNum]['break_afternoon']);
          return ($hasStudy || $hasBreak);
        }
      };

      $mainCells = [];
      foreach ($dayMap as $dayNum => $dayEnum) {
        foreach ($shiftMap as $availShift => $mainShiftEnum) {
          $mainCells[] = [
            'kind' => 'main',
            'dayNum' => $dayNum,
            'dayEnum' => $dayEnum,
            'availShift' => $availShift,
            'shiftEnum' => $mainShiftEnum,
            'type' => 'thuong',
            'addScore' => 1.0
          ];
        }
      }

      $breakCells = [];
      foreach ($dayMap as $dayNum => $dayEnum) {
        foreach ($shiftMap as $availShift => $mainShiftEnum) {
          $breakCells[] = [
            'kind' => 'break',
            'dayNum' => $dayNum,
            'dayEnum' => $dayEnum,
            'availShift' => $availShift,
            'shiftEnum' => ($availShift === 'morning' ? 'rachoi_s' : 'rachoi_c'),
            'type' => 'rachoi',
            'addScore' => 0.5
          ];
        }
      }

      $fillBaseline = function (array $cells, callable $canFn, string $seedPrefix) use ($weekId, $MIN_PER_SHIFT, $countSlot, $sortUsersByScoreAsc, $stableSort, $hasAssignment, $addAssignment, &$score) {
        for ($target = 1; $target <= $MIN_PER_SHIFT; $target++) {
          $ordered = $cells;
          usort($ordered, function ($a, $b) use ($weekId, $countSlot, $target) {
            $ca = $countSlot($a['dayEnum'], $a['shiftEnum']);
            $cb = $countSlot($b['dayEnum'], $b['shiftEnum']);
            $ra = ($ca < $target) ? 0 : 1;
            $rb = ($cb < $target) ? 0 : 1;
            if ($ra !== $rb)
              return $ra <=> $rb;
            if ($ca !== $cb)
              return $ca <=> $cb;
            $ha = sprintf('%u', crc32("cell-{$weekId}-{$a['dayEnum']}-{$a['shiftEnum']}"));
            $hb = sprintf('%u', crc32("cell-{$weekId}-{$b['dayEnum']}-{$b['shiftEnum']}"));
            if ($ha === $hb)
              return 0;
            return ($ha < $hb) ? -1 : 1;
          });

          foreach ($ordered as $cell) {
            while (true) {
              $cur = $countSlot($cell['dayEnum'], $cell['shiftEnum']);
              if ($cur >= $target)
                break;

              $uids = $sortUsersByScoreAsc();
              $uids = $stableSort($uids, "{$seedPrefix}-{$target}-{$weekId}-{$cell['dayEnum']}-{$cell['shiftEnum']}");

              $picked = false;
              foreach ($uids as $uid) {
                if (($score[$uid] ?? 0) >= 3.0)
                  continue;
                if (!$canFn($uid, $cell['dayNum'], $cell['availShift']))
                  continue;
                if ($hasAssignment($uid, $cell['dayEnum'], $cell['shiftEnum']))
                  continue;

                if ($addAssignment($uid, $cell['dayEnum'], $cell['shiftEnum'], $cell['type'], $cell['addScore'])) {
                  $picked = true;
                  break;
                }
              }
              if (!$picked)
                break;
            }
          }
        }
      };

      // 1) Baseline Main
      $fillBaseline($mainCells, $canMain, 'main');

      // 2) Bù Main
      $pickBestMainForUser = function (int $uid) use (&$mainCells, $weekId, $countSlot, $MIN_PER_SHIFT, $MAX_PER_SHIFT, $canMain, $hasAssignment) {
        $cands = [];
        foreach ($mainCells as $cell) {
          if (!$canMain($uid, $cell['dayNum'], $cell['availShift']))
            continue;
          $cur = $countSlot($cell['dayEnum'], $cell['shiftEnum']);
          if ($cur >= $MAX_PER_SHIFT)
            continue;
          if ($hasAssignment($uid, $cell['dayEnum'], $cell['shiftEnum']))
            continue;
          $rank = ($cur < $MIN_PER_SHIFT) ? 0 : 1;
          $cands[] = [$rank, $cur, $cell];
        }
        if (!$cands)
          return null;
        usort($cands, function ($a, $b) use ($uid, $weekId) {
          if ($a[0] !== $b[0])
            return $a[0] <=> $b[0];
          if ($a[1] !== $b[1])
            return $a[1] <=> $b[1];
          $ha = sprintf('%u', crc32("u{$uid}-{$weekId}-{$a[2]['dayEnum']}-{$a[2]['shiftEnum']}"));
          $hb = sprintf('%u', crc32("u{$uid}-{$weekId}-{$b[2]['dayEnum']}-{$b[2]['shiftEnum']}"));
          if ($ha === $hb)
            return 0;
          return ($ha < $hb) ? -1 : 1;
        });
        return $cands[0][2];
      };

      $guard = 0;
      while (true) {
        $guard++;
        if ($guard > 4000)
          break;
        $changed = false;
        $uids = $sortUsersByScoreAsc();
        foreach ($uids as $uid) {
          if (($score[$uid] ?? 0) >= 3.0)
            continue;

          $cell = $pickBestMainForUser($uid);
          if ($cell) {
            if ($addAssignment($uid, $cell['dayEnum'], $cell['shiftEnum'], $cell['type'], $cell['addScore'])) {
              $changed = true;
            }
          }
        }
        if (!$changed)
          break;
      }

      // 3) Baseline Break
      $fillBaseline($breakCells, $canBreak, 'break');

      // 4) Bù Break
      $pickBestBreakForUser = function (int $uid) use (&$breakCells, $weekId, $countSlot, $MIN_PER_SHIFT, $MAX_PER_SHIFT, $canBreak, $hasAssignment) {
        $cands = [];
        foreach ($breakCells as $cell) {
          if (!$canBreak($uid, $cell['dayNum'], $cell['availShift']))
            continue;
          $cur = $countSlot($cell['dayEnum'], $cell['shiftEnum']);
          if ($cur >= $MAX_PER_SHIFT)
            continue;
          if ($hasAssignment($uid, $cell['dayEnum'], $cell['shiftEnum']))
            continue;
          $rank = ($cur < $MIN_PER_SHIFT) ? 0 : 1;
          $cands[] = [$rank, $cur, $cell];
        }
        if (!$cands)
          return null;
        usort($cands, function ($a, $b) use ($uid, $weekId) {
          if ($a[0] !== $b[0])
            return $a[0] <=> $b[0];
          if ($a[1] !== $b[1])
            return $a[1] <=> $b[1];
          $ha = sprintf('%u', crc32("ub{$uid}-{$weekId}-{$a[2]['dayEnum']}-{$a[2]['shiftEnum']}"));
          $hb = sprintf('%u', crc32("ub{$uid}-{$weekId}-{$b[2]['dayEnum']}-{$b[2]['shiftEnum']}"));
          if ($ha === $hb)
            return 0;
          return ($ha < $hb) ? -1 : 1;
        });
        return $cands[0][2];
      };

      $guard = 0;
      while (true) {
        $guard++;
        if ($guard > 6000)
          break;
        $changed = false;
        $uids = $sortUsersByScoreAsc();
        foreach ($uids as $uid) {
          if (($score[$uid] ?? 0) >= 3.0)
            continue;

          $cell = $pickBestBreakForUser($uid);
          if ($cell) {
            if ($addAssignment($uid, $cell['dayEnum'], $cell['shiftEnum'], $cell['type'], $cell['addScore'])) {
              $changed = true;
            }
          }
        }
        if (!$changed)
          break;
      }

      // 5) Top-up 2->3
      $allCells = array_merge($mainCells, $breakCells);
      $anyCellUnderMin = function () use ($allCells, $countSlot, $MIN_PER_SHIFT) {
        foreach ($allCells as $cell) {
          if ($countSlot($cell['dayEnum'], $cell['shiftEnum']) < $MIN_PER_SHIFT)
            return true;
        }
        return false;
      };

      if (!$anyCellUnderMin()) {
        // top-up MAIN
        foreach ($mainCells as $cell) {
          $cur = $countSlot($cell['dayEnum'], $cell['shiftEnum']);
          if ($cur < 2 || $cur >= 3)
            continue;

          $uids = $sortUsersByScoreAsc();
          $uids = $stableSort($uids, "top-main-{$weekId}-{$cell['dayEnum']}-{$cell['shiftEnum']}");
          foreach ($uids as $uid) {
            if (($score[$uid] ?? 0) >= 3.0)
              continue;
            if (!$canMain($uid, $cell['dayNum'], $cell['availShift']))
              continue;
            if ($hasAssignment($uid, $cell['dayEnum'], $cell['shiftEnum']))
              continue;

            if ($addAssignment($uid, $cell['dayEnum'], $cell['shiftEnum'], 'thuong', 1.0))
              break;
          }
        }

        // top-up BREAK
        foreach ($breakCells as $cell) {
          $cur = $countSlot($cell['dayEnum'], $cell['shiftEnum']);
          if ($cur < 2 || $cur >= 3)
            continue;

          $uids = $sortUsersByScoreAsc();
          $uids = $stableSort($uids, "top-break-{$weekId}-{$cell['dayEnum']}-{$cell['shiftEnum']}");
          foreach ($uids as $uid) {
            if (($score[$uid] ?? 0) >= 3.0)
              continue;
            if (!$canBreak($uid, $cell['dayNum'], $cell['availShift']))
              continue;
            if ($hasAssignment($uid, $cell['dayEnum'], $cell['shiftEnum']))
              continue;

            if ($addAssignment($uid, $cell['dayEnum'], $cell['shiftEnum'], 'rachoi', 0.5))
              break;
          }
        }
      }

      // Map fullname của các user
      $userNames = [];
      if (!empty($userIds)) {
        $st = $pdo->prepare("
          SELECT u.id, COALESCE(m.fullname, u.fullname, u.username) AS name
          FROM users u
          LEFT JOIN members m ON m.user_id = u.id
          WHERE u.id IN (" . implode(',', array_fill(0, count($userIds), '?')) . ")
        ");
        $st->execute($userIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $userNames[(int)$row['id']] = $row['name'];
        }
      }

      foreach ($assignmentsDraft as &$a) {
        $a['fullname'] = $userNames[$a['user_id']] ?? 'Không tên';
      }
      unset($a);

      json_ok([
        'week_id' => $weekId,
        'assignments' => $assignmentsDraft,
        'score' => $score
      ]);

    } catch (Throwable $e) {
      json_err('Lỗi gợi ý lịch trực: ' . $e->getMessage());
    }
    break;

  case 'save_week_schedule':
    auth_guard();
    if (!can('duty', 'update')) {
      json_err('Forbidden', 403);
    }

    try {
      $data = json_decode(file_get_contents('php://input'), true);
      $items = $data['items'] ?? [];
      $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 1;
      $weekMeta = get_duty_week_by_offset($pdo, $offset);
      $weekId = $weekMeta['week_id'];

      if (!is_array($items)) {
        json_err('Dữ liệu lưu không hợp lệ');
      }

      $validDays = ['T2', 'T3', 'T4', 'T5', 'T6'];
      $validShifts = ['sang', 'chieu', 'rachoi_s', 'rachoi_c'];

      $pdo->beginTransaction();

      // Xóa tất cả các ca trực cũ của tuần tương ứng
      $pdo->prepare("DELETE FROM duty_assignments WHERE week_id=?")->execute([$weekId]);

      // Chèn các ca trực mới
      $insert = $pdo->prepare("
        INSERT INTO duty_assignments (week_id, user_id, day, shift, type, score)
        VALUES (?, ?, ?, ?, ?, ?)
      ");

      foreach ($items as $it) {
        $uid = (int)($it['user_id'] ?? 0);
        $day = (string)($it['day'] ?? '');
        $shift = (string)($it['shift'] ?? '');
        $type = (string)($it['type'] ?? 'thuong');
        $scoreVal = (float)($it['score'] ?? 1.0);

        if (!$uid || !in_array($day, $validDays, true) || !in_array($shift, $validShifts, true)) {
          continue; // Bỏ qua phần tử lỗi
        }

        $insert->execute([$weekId, $uid, $day, $shift, $type, $scoreVal]);
      }

      log_activity('update', 'duty', 'assignment', null, "Admin lưu chính thức lịch trực tuần ID $weekId");
      $pdo->commit();
      json_ok();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      json_err('Lỗi lưu lịch trực chính thức: ' . $e->getMessage());
    }
    break;

  default:
    json_err('Invalid action');
}
