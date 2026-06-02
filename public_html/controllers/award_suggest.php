<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/db.php';

auth_guard();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function json_ok($data = null)
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_json_input()
{
    $raw = file_get_contents('php://input');
    if (!$raw)
        return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function status_level($st)
{
    // approved = chưa chấm
    // cancelled = bỏ qua
    if ($st === 'excellent')
        return 2;
    if ($st === 'good')
        return 1;
    if ($st === 'incomplete')
        return 0;
    return -1;
}

try {

    switch ($action) {

        /* =======================================================
           META
        ======================================================= */
        case 'meta':
            try {

                // ✅ Danh hiệu khen thưởng: lấy từ reward_titles
                $titles = $pdo->query("
      SELECT id, name
      FROM reward_titles
      ORDER BY name ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

                // ✅ Năm học: lấy từ school_years
                $years = $pdo->query("
      SELECT year_label
      FROM school_years
      ORDER BY year_label DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

                json_ok([
                    'titles' => $titles,
                    'school_years' => $years
                ]);

            } catch (Throwable $e) {
                json_err("Lỗi meta: " . $e->getMessage(), 500);
            }
            break;



        /* =======================================================
           RULE GET
        ======================================================= */
        case 'rule_get':
            try {
                $titleId = (int) ($_GET['title_id'] ?? 0);
                $schoolYear = trim($_GET['school_year'] ?? '');
                $semester = trim($_GET['semester'] ?? 'ALL');

                if (!$titleId || !$schoolYear)
                    json_err('Thiếu title_id hoặc school_year');

                $stmt = $pdo->prepare("
          SELECT * FROM award_rules
          WHERE title_id = ? AND school_year = ? AND semester = ?
          LIMIT 1
        ");
                $stmt->execute([$titleId, $schoolYear, $semester]);
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$rule)
                    json_ok(['rule' => null, 'items' => []]);

                $stmt2 = $pdo->prepare("
          SELECT i.campaign_id, i.required_status, c.title
          FROM award_rule_items i
          JOIN campaigns c ON c.id = i.campaign_id
          WHERE i.rule_id = ?
          ORDER BY c.start_date DESC, c.id DESC
        ");
                $stmt2->execute([(int) $rule['id']]);
                $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                json_ok(['rule' => $rule, 'items' => $items]);
            } catch (Throwable $e) {
                json_err("Lỗi tải tiêu chí: " . $e->getMessage(), 500);
            }
            break;


        /* =======================================================
           RULE SAVE
        ======================================================= */
        case 'rule_save':
            try {
                $input = get_json_input();

                $titleId = (int) ($input['title_id'] ?? 0);
                $schoolYear = trim($input['school_year'] ?? '');
                $semester = trim($input['semester'] ?? 'ALL');
                $minRequired = trim($input['min_required'] ?? 'excellent');
                $items = $input['items'] ?? [];

                if (!$titleId || !$schoolYear)
                    json_err('Thiếu title_id hoặc school_year');
                if (!in_array($semester, ['HK1', 'HK2', 'ALL'], true))
                    $semester = 'ALL';
                if (!in_array($minRequired, ['excellent', 'good'], true))
                    $minRequired = 'excellent';
                if (!is_array($items) || empty($items))
                    json_err('Chưa chọn phong trào bắt buộc');

                $pdo->beginTransaction();

                // upsert rule
                $stmt = $pdo->prepare("
          SELECT id FROM award_rules
          WHERE title_id=? AND school_year=? AND semester=?
          LIMIT 1
        ");
                $stmt->execute([$titleId, $schoolYear, $semester]);
                $ruleId = (int) $stmt->fetchColumn();

                if ($ruleId) {
                    $stmtU = $pdo->prepare("
            UPDATE award_rules
            SET min_required=?, is_active=1
            WHERE id=?
          ");
                    $stmtU->execute([$minRequired, $ruleId]);

                    $pdo->prepare("DELETE FROM award_rule_items WHERE rule_id=?")->execute([$ruleId]);
                } else {
                    $stmtI = $pdo->prepare("
            INSERT INTO award_rules (title_id, school_year, semester, min_required, is_active)
            VALUES (?,?,?,?,1)
          ");
                    $stmtI->execute([$titleId, $schoolYear, $semester, $minRequired]);
                    $ruleId = (int) $pdo->lastInsertId();
                }

                $stmtItem = $pdo->prepare("
          INSERT INTO award_rule_items (rule_id, campaign_id, required_status)
          VALUES (?,?,?)
        ");

                foreach ($items as $it) {
                    $campaignId = (int) ($it['campaign_id'] ?? 0);
                    $req = $it['required_status'] ?? 'excellent';
                    if (!$campaignId)
                        continue;
                    if (!in_array($req, ['excellent', 'good'], true))
                        $req = 'excellent';
                    $stmtItem->execute([$ruleId, $campaignId, $req]);
                }

                $pdo->commit();
                json_ok(['rule_id' => $ruleId]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction())
                    $pdo->rollBack();
                json_err("Lỗi lưu tiêu chí: " . $e->getMessage(), 500);
            }
            break;


        /* =======================================================
           SUGGEST
        ======================================================= */
        case 'suggest':
            try {
                $input = get_json_input();

                $titleId = (int) ($input['title_id'] ?? 0);
                $schoolYear = trim($input['school_year'] ?? '');
                $semester = trim($input['semester'] ?? 'ALL');

                if (!$titleId || !$schoolYear)
                    json_err('Thiếu title_id hoặc school_year');
                if (!in_array($semester, ['HK1', 'HK2', 'ALL'], true))
                    $semester = 'ALL';

                // load rule
                $stmt = $pdo->prepare("
          SELECT * FROM award_rules
          WHERE title_id=? AND school_year=? AND semester=?
          LIMIT 1
        ");
                $stmt->execute([$titleId, $schoolYear, $semester]);
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$rule)
                    json_err('Chưa thiết lập điều kiện cho danh hiệu này');

                $stmt2 = $pdo->prepare("
          SELECT campaign_id, required_status
          FROM award_rule_items
          WHERE rule_id=?
        ");
                $stmt2->execute([(int) $rule['id']]);
                $reqItems = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                if (empty($reqItems))
                    json_err('Rule rỗng (chưa chọn phong trào)');

                $campaignIds = array_map(fn($x) => (int) $x['campaign_id'], $reqItems);
                $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));

                // load candidates (members)
                $users = $pdo->query("
          SELECT
            u.id AS user_id,
            COALESCE(m.fullname, u.fullname, u.username) AS fullname,
            m.mssv,
            c.name AS class_name,
            d.name AS dept_name,
            m.stop_follow
          FROM users u
          LEFT JOIN members m ON m.user_id = u.id
          LEFT JOIN classes c ON c.id = m.class_id
          LEFT JOIN departments d ON d.id = m.department_id
          WHERE (m.stop_follow IS NULL OR m.stop_follow = 0)
        ")->fetchAll(PDO::FETCH_ASSOC);

                // load registrations for required campaigns
                $stmtR = $pdo->prepare("
          SELECT user_id, campaign_id, status
          FROM registrations
          WHERE campaign_id IN ($placeholders)
        ");
                $stmtR->execute($campaignIds);
                $regs = $stmtR->fetchAll(PDO::FETCH_ASSOC);

                // index reg by user_id + campaign_id
                $regMap = [];
                foreach ($regs as $r) {
                    $uid = (int) $r['user_id'];
                    $cid = (int) $r['campaign_id'];
                    $regMap[$uid][$cid] = $r['status'];
                }

                // evaluate per user
                $result = [];
                foreach ($users as $u) {
                    $uid = (int) $u['user_id'];

                    $matched = 0;
                    $missing = [];
                    $pending = [];

                    foreach ($reqItems as $req) {
                        $cid = (int) $req['campaign_id'];
                        $need = $req['required_status']; // excellent/good

                        $st = $regMap[$uid][$cid] ?? null;

                        if ($st === null) {
                            $missing[] = [
                                'campaign_id' => $cid,
                                'need' => $need,
                                'have' => 'none'
                            ];
                            continue;
                        }

                        if ($st === 'approved') {
                            $pending[] = [
                                'campaign_id' => $cid,
                                'need' => $need,
                                'have' => 'approved'
                            ];
                            continue;
                        }

                        if ($st === 'cancelled') {
                            $missing[] = [
                                'campaign_id' => $cid,
                                'need' => $need,
                                'have' => 'cancelled'
                            ];
                            continue;
                        }

                        // compare level
                        $haveLv = status_level($st);
                        $needLv = status_level($need);

                        if ($haveLv >= $needLv) {
                            $matched++;
                        } else {
                            $missing[] = [
                                'campaign_id' => $cid,
                                'need' => $need,
                                'have' => $st
                            ];
                        }
                    }

                    $totalReq = count($reqItems);
                    $readiness = $totalReq ? round(($matched / $totalReq) * 100) : 0;

                    // smart status
                    $status = 'NOT_ELIGIBLE';
                    if (empty($missing) && empty($pending))
                        $status = 'ELIGIBLE';
                    else if (count($missing) === 1 && empty($pending))
                        $status = 'NEAR';
                    else if (!empty($pending))
                        $status = 'PENDING_GRADE';

                    $result[] = [
                        'user_id' => $uid,
                        'mssv' => $u['mssv'],
                        'fullname' => $u['fullname'],
                        'class_name' => $u['class_name'],
                        'dept_name' => $u['dept_name'],
                        'status' => $status,
                        'readiness' => $readiness,
                        'missing' => $missing,
                        'pending' => $pending,
                        'matched_count' => $matched,
                        'total_required' => $totalReq
                    ];
                }

                // sort: eligible > near > pending > not
                $rank = ['ELIGIBLE' => 1, 'NEAR' => 2, 'PENDING_GRADE' => 3, 'NOT_ELIGIBLE' => 4];
                usort($result, function ($a, $b) use ($rank) {
                    $ra = $rank[$a['status']] ?? 9;
                    $rb = $rank[$b['status']] ?? 9;
                    if ($ra !== $rb)
                        return $ra <=> $rb;
                    return $b['readiness'] <=> $a['readiness'];
                });

                // ✅ CHỈ LẤY: gần đủ + đủ điều kiện
                $result = array_values(array_filter($result, function ($r) {
                    return in_array($r['status'], ['ELIGIBLE', 'NEAR'], true);
                }));


                json_ok([
                    'rule' => $rule,
                    'required_campaigns' => $reqItems,
                    'rows' => $result
                ]);
            } catch (Throwable $e) {
                json_err("Lỗi gợi ý: " . $e->getMessage(), 500);
            }
            break;


        /* =======================================================
           NOTIFY CANDIDATE  (BẤM TẠO ĐỀ CỬ -> GỬI THÔNG BÁO USER)
        ======================================================= */
        case 'notify_candidate':
            try {
                $input = get_json_input();

                $userId = (int) ($input['user_id'] ?? 0);
                $titleId = (int) ($input['title_id'] ?? 0);
                $schoolYear = trim($input['school_year'] ?? '');
                $semester = trim($input['semester'] ?? 'ALL');

                if (!$userId)
                    json_err("Thiếu user_id");
                if (!$titleId)
                    json_err("Thiếu title_id");
                if (!$schoolYear)
                    json_err("Thiếu school_year");
                if (!in_array($semester, ['HK1', 'HK2', 'ALL'], true))
                    $semester = 'ALL';

                // lấy tên danh hiệu
                $stmtT = $pdo->prepare("SELECT name FROM reward_titles WHERE id=? LIMIT 1");
                $stmtT->execute([$titleId]);
                $titleName = $stmtT->fetchColumn();
                if (!$titleName)
                    $titleName = "Danh hiệu #" . $titleId;

                // lấy tên user
                $stmtU = $pdo->prepare("
          SELECT
            u.id,
            COALESCE(m.fullname, u.fullname, u.username) AS fullname,
            m.mssv
          FROM users u
          LEFT JOIN members m ON m.user_id = u.id
          WHERE u.id = ?
          LIMIT 1
        ");
                $stmtU->execute([$userId]);
                $u = $stmtU->fetch(PDO::FETCH_ASSOC);
                if (!$u)
                    json_err("Không tìm thấy user");

                $fullname = $u['fullname'] ?? 'Bạn';

                // link user bấm vào -> tới trang đề cử (Toro có thể đổi tab theo đúng module của Toro)
                // Mình để tab=user cho hợp lý (user tự thấy thông tin)
                $link = "/index.php?p=nominations&tab=form"
                    . "&prefill_title_id=" . urlencode((string) $titleId)
                    . "&prefill_school_year=" . urlencode($schoolYear);

                $msg = $fullname . " đã đủ điều kiện để được đề cử danh hiệu \"" . $titleName . "\" (" . $schoolYear . "). Nhấn để gửi yêu cầu xem.";

                $pdo->prepare("INSERT INTO notifications (message, user_id, link) VALUES (?, ?, ?)")
                    ->execute([$msg, $userId, $link]);

                json_ok([
                    'sent' => true,
                    'user_id' => $userId,
                    'link' => $link
                ]);
            } catch (Throwable $e) {
                json_err("Lỗi gửi thông báo: " . $e->getMessage(), 500);
            }
            break;


        default:
            json_err('Action không hợp lệ', 404);
    }
} catch (Throwable $e) {
    json_err("Server lỗi: " . $e->getMessage(), 500);
}
