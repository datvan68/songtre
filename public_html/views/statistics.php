<?php
// display_errors controlled centrally in index.php / bootstrap
error_reporting(E_ALL);

$stats = [];

/**
 * ===== MEMBERS: đoàn viên / thanh niên =====
 * members.type = 'member' | 'youth'
 */
$sql = "
  SELECT
    SUM(type = 'member') AS total_members,
    SUM(type = 'youth')  AS total_youth
  FROM members
";
$row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
$stats['total_members'] = (int) ($row['total_members'] ?? 0);
$stats['total_youth'] = (int) ($row['total_youth'] ?? 0);


/**
 * ===== CAMPAIGNS: tổng phong trào =====
 */
$stats['total_campaigns'] = (int) $pdo
    ->query("SELECT COUNT(*) FROM campaigns")
    ->fetchColumn();


/**
 * ===== REGISTRATIONS: tổng lượt đăng ký =====
 */
$stats['total_registrations'] = (int) $pdo
    ->query("SELECT COUNT(*) FROM registrations")
    ->fetchColumn();


/**
 * ===== ATTENDANCE: tổng lượt điểm danh =====
 * bảng attendance_log
 */
$stats['total_attendance'] = (int) $pdo
    ->query("SELECT COUNT(*) FROM attendance_logs")
    ->fetchColumn();


/**
 * ===== NOMINATIONS: tổng đề cử thi đua =====
 */
$stats['total_nominations'] = (int) $pdo
    ->query("SELECT COUNT(*) FROM nominations")
    ->fetchColumn();


/**
 * ===== USERS: tổng tài khoản =====
 */
$stats['total_users'] = (int) $pdo
    ->query("SELECT COUNT(*) FROM users")
    ->fetchColumn();


?>

<section class="p-6">
    <div class="w-full">

        <!-- ===== HEADER ===== -->
        <div class="mb-8">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <h1 class="font-heading text-3xl font-bold">
                        THỐNG KÊ TỔNG HỢP HỆ THỐNG ĐOÀN TRƯỜNG
                    </h1>
                    <p class="text-subtext">
                        Tổng hợp dữ liệu các phân hệ: Đoàn viên, Phong trào, Điểm danh, Thi đua,
                        Thông báo, Tài khoản, Lịch công tác, Thiết bị, Nhật ký
                    </p>
                </div>
            </div>
        </div>

        <!-- ===== TAB NAVIGATION ===== -->
        <div class="mb-8 overflow-x-auto tab-scroll">
            <div class="flex gap-2 min-w-max pb-2">
                <button data-tab="overview"
                    class="tab-btn px-4 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg whitespace-nowrap">
                    Tổng quan
                </button>

                <button data-tab="members"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Đoàn viên
                </button>

                <button data-tab="campaigns"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Phong trào – Chiến dịch
                </button>

                <button data-tab="attendance"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Điểm danh – QR
                </button>

                <button data-tab="nominations"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Thi đua – Khen thưởng
                </button>

                <button data-tab="notifications"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Thông báo
                </button>

                <button data-tab="accounts"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Tài khoản &amp; Phân quyền
                </button>

                <button data-tab="schedule"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Lịch công tác
                </button>

                <button data-tab="inventory"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Thiết bị – Đồ dùng
                </button>

                <button data-tab="finance"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Thu - Chi
                </button>

                <button data-tab="violations"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Kỷ luật - Vi phạm
                </button>

                <button data-tab="logs"
                    class="tab-btn px-4 py-2.5 bg-gray-100 text-gray-700 hover:bg-gray-200 text-sm font-medium rounded-lg whitespace-nowrap">
                    Nhật ký hoạt động
                </button>
            </div>
        </div>

        <!-- ===== TAB: TỔNG QUAN ===== -->
        <div data-tab-panel="overview">
            <div id="tab-overview"></div>
        </div>

        <!-- ===== TAB KHÁC (PLACEHOLDER) ===== -->
        <div data-tab-panel="members" class="hidden"></div>
        <div data-tab-panel="campaigns" class="hidden">
            <div id="tab-campaigns"></div>
        </div>
        <div data-tab-panel="attendance" class="hidden"></div>
        <div data-tab-panel="nominations" class="hidden"></div>
        <div data-tab-panel="notifications" class="hidden"></div>
        <div data-tab-panel="accounts" class="hidden"></div>
        <div data-tab-panel="schedule" class="hidden"></div>
        <div data-tab-panel="inventory" class="hidden"></div>
        <div data-tab-panel="finance" class="hidden"></div>
        <div data-tab-panel="violations" class="hidden"></div>
        <div data-tab-panel="logs" class="hidden"></div>

    </div>
</section>
<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
<script>
    window.STATS = <?= json_encode($stats, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/statistics/members.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/campaigns.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/attendance.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/nominations.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/notifications.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/accounts.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/schedule.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/inventory.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/finance.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/violations.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/logs.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>assets/js/statistics/statistics.js?v=<?= time() ?>"></script>