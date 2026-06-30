<?php
declare(strict_types=1);

if (!function_exists('json_ok')) {
    function json_ok($data = null): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('json_err')) {
    function json_err(string $msg, int $code = 400, array $extra = []): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code($code);
        echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function slugify(string $str): string
{
    $str = mb_strtolower($str, 'UTF-8');
    $map = [
        'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
        'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
        'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
        'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
        'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
        'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'đ' => 'd'
    ];
    $str = strtr($str, $map);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    $str = trim((string) $str, '-');
    return $str;
}

function romanSemesterFromCode(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code === 'HK1')
        return 'HỌC KỲ I';
    if ($code === 'HK2')
        return 'HỌC KỲ II';
    if ($code === 'HK3')
        return 'HỌC KỲ III';
    return ($code !== '' ? ("HỌC KỲ " . $code) : '');
}

function getSchoolYearLabel(PDO $pdo, int $schoolYearId): string
{
    if ($schoolYearId <= 0)
        return '';
    $st = $pdo->prepare("SELECT year_label FROM school_years WHERE id = ?");
    $st->execute([$schoolYearId]);
    return (string) ($st->fetchColumn() ?? '');
}

function getSemesterLabel(PDO $pdo, string $semesterCode): string
{
    $semesterCode = trim($semesterCode);
    if ($semesterCode === '')
        return '';
    $st = $pdo->prepare("SELECT label FROM semesters WHERE code = ?");
    $st->execute([$semesterCode]);
    return (string) ($st->fetchColumn() ?? '');
}

function parseSchoolYearBounds(PDO $pdo, int $schoolYearId): array
{
    $st = $pdo->prepare("SELECT year_label FROM school_years WHERE id = ?");
    $st->execute([$schoolYearId]);
    $label = (string) ($st->fetchColumn() ?? '');

    preg_match_all('/\d{2,4}/', $label, $m);
    $nums = $m[0] ?? [];
    if (count($nums) < 2)
        return [0, 0];

    $y1 = (int) $nums[0];
    $y2 = (int) $nums[1];
    if ($y1 < 100)
        $y1 += 2000;
    if ($y2 < 100)
        $y2 += 2000;
    return [$y1, $y2];
}

function semesterDateRange(PDO $pdo, int $schoolYearId, string $semesterCode): array
{
    // Giữ nguyên logic ban đầu, trả về ['', '']
    return ['', ''];
}

function db_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $db = (string) $pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($db === '')
            return false;

        $st = $pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $st->execute([$db, $table, $column]);
        return ((int) $st->fetchColumn() > 0);
    } catch (Throwable $e) {
        return false;
    }
}

if (!function_exists('recalculate_unlocked_campaign_scores')) {
    function recalculate_unlocked_campaign_scores(PDO $pdo, array $campaignIds): void
    {
        if (empty($campaignIds)) {
            return;
        }

        // Find campaign ids that are LOCKED in campaign_class_results
        $inCam = (count($campaignIds) === 1) ? '?' : str_repeat('?,', count($campaignIds) - 1) . '?';
        $st = $pdo->prepare("
            SELECT campaign_id 
            FROM campaign_class_results 
            WHERE campaign_id IN ($inCam) AND locked = 1
            GROUP BY campaign_id
        ");
        $st->execute($campaignIds);
        $lockedCampaignIds = $st->fetchAll(PDO::FETCH_COLUMN);
        $lockedCampaignIds = array_map('intval', $lockedCampaignIds);

        // Recalculate campaigns that are not locked (including new ones not yet in results)
        $unlockedCampaignIds = array_diff($campaignIds, $lockedCampaignIds);

        if (empty($unlockedCampaignIds)) {
            return;
        }

        // For each unlocked campaign, perform recalculation
        foreach ($unlockedCampaignIds as $campaignId) {
            $campaignId = (int)$campaignId;

            $sql = "
                INSERT INTO campaign_class_results (
                    campaign_id,
                    class_id,
                    joined_quantity,
                    target_quantity,
                    score
                )
                SELECT
                    r.campaign_id,
                    c.id,
                    COUNT(DISTINCT m.user_id) AS joined_quantity,
                    COALESCE(ccs.target_quantity, 0) AS target_quantity,
                    CASE WHEN COUNT(DISTINCT m.user_id) > 0 THEN 10.0 ELSE 0.0 END AS score
                FROM members m
                JOIN classes c ON c.id = m.class_id
                JOIN registrations r
                    ON r.user_id = m.user_id
                   AND r.campaign_id = ?
                LEFT JOIN attendance_logs al
                    ON al.user_id = m.user_id
                   AND al.campaign_id = r.campaign_id
                   AND al.result = 'ok'
                LEFT JOIN campaign_class_scores ccs
                    ON ccs.campaign_id = r.campaign_id
                   AND ccs.class_id = c.id
                WHERE
                    al.user_id IS NOT NULL
                    OR r.status = 'approved'
                GROUP BY r.campaign_id, c.id, ccs.target_quantity
                ON DUPLICATE KEY UPDATE
                    joined_quantity = VALUES(joined_quantity),
                    target_quantity = VALUES(target_quantity),
                    score = VALUES(score),
                    calculated_at = CURRENT_TIMESTAMP
            ";

            $pdo->prepare($sql)->execute([$campaignId]);
        }
    }
}

