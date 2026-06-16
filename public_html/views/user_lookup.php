<?php
// views/user_lookup.php
if (function_exists('can')) {
    $allow = can('user_lookup', 'view');
    if (!$allow) {
        echo "<section class='p-6 bg-white rounded-2xl shadow-sm'>
            <div class='font-semibold text-lg'>Bạn không có quyền truy cập</div>
            <div class='text-sm text-gray-500 mt-1'>Cần quyền xem thành viên/tài khoản để tra cứu.</div>
          </section>";
        return;
    }
}
?>
<?php
$meId = (int) ($_SESSION['user_id'] ?? 0);
$currentRole = '';
if ($meId > 0) {
    try {
        $st = $pdo->prepare("
            SELECT LOWER(r.name)
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $st->execute([$meId]);
        $currentRole = (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $currentRole = '';
    }
}

// quyền vào trang vẫn là can('user_lookup','view') như bạn đang check
$canAll = false;
if (function_exists('can')) {
    // đồng bộ giống controller
    $canAll = can('user_lookup', 'review') || can('members', 'review');
}

// role có scope được search trong phạm vi (không cần canAll)
$isScopedRole = in_array($currentRole, ['bithu', 'gvcn'], true);

// chỉ self-only khi: không canAll + không thuộc role scoped
$isSelfOnly = (!$canAll && !$isScopedRole);

?>

<section class="p-6 w-full">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
            <div>
                <div class="text-xl font-semibold">Tra cứu thông tin người dùng</div>
                <div class="text-sm text-gray-500 mt-1">
                    Chọn 1 user để xem đầy đủ hồ sơ + phong trào + điểm + QR + nhiệm vụ + trực BCH...
                </div>
            </div>


        </div>

        <div class="p-6">
            <div id="user-lookup-app" data-endpoint="<?= BASE_URL ?>controllers/user_lookup.php"
                data-me-id="<?= $meId ?>" data-self-only="<?= $isSelfOnly ? '1' : '0' ?>"
                data-me-mssv="<?= htmlspecialchars($meMssv, ENT_QUOTES, 'UTF-8') ?>"
                class="grid grid-cols-1 lg:grid-cols-12 gap-6">

                <!-- LEFT: Search -->
                <div class="lg:col-span-4">
                    <div class="rounded-2xl border border-gray-100 bg-gray-50 p-4">
                        <div class="font-semibold mb-2">Tìm user</div>

                        <div class="relative">
                            <input id="ul-q" type="text"
                                placeholder="<?= $isSelfOnly ? 'Tài khoản của bạn' : 'Nhập tên / username / MSSV...' ?>"
                                value="<?= $isSelfOnly ? htmlspecialchars($meMssv, ENT_QUOTES, 'UTF-8') : '' ?>"
                                <?= $isSelfOnly ? 'disabled' : '' ?> class="w-full rounded-xl border border-gray-200 bg-white px-4 py-3 outline-none focus:ring-2
                            focus:ring-blue-200
                            <?= $isSelfOnly ? 'opacity-70 cursor-not-allowed' : '' ?>" autocomplete="off" />


                            <?php if (!$isSelfOnly): ?>
                                <div id="ul-dropdown"
                                    class="absolute z-50 mt-2 w-full rounded-xl border border-gray-200 bg-white shadow-lg hidden max-h-[360px] overflow-auto">
                                </div>
                            <?php endif; ?>
                            <div class="mt-4 text-xs text-gray-500">
                                <?php if ($isSelfOnly): ?>
                                    Bạn chỉ được xem thông tin của chính mình.
                                <?php elseif (!$canAll && $isScopedRole): ?>
                                    Bạn được xem người dùng trong phạm vi lớp/khoa mình quản lý.
                                <?php else: ?>
                                    Bạn có thể bấm vào 1 dòng trong danh sách để load “Full info”.
                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                    <div id="container-summary" class="mt-4 rounded-2xl border border-gray-100 p-4 hidden">
                        <div class="font-semibold mb-2">Tóm tắt nhanh</div>
                        <div id="ul-summary" class="text-sm text-gray-600"></div>
                    </div>
                    <div id="container-paid" class="mt-4 rounded-2xl border border-gray-100 p-4 hidden">
                        <div class="font-semibold mb-2">Tiền đã đóng</div>
                        <div id="ul-paid" class="text-sm text-gray-600"></div>
                    </div>
                    <div id="container-sidebar-inventory" class="mt-4 rounded-2xl border border-gray-100 p-4 hidden">
                        <div class="font-semibold mb-2">Thiết bị</div>
                        <div id="ul-sidebar-inventory" class="text-sm text-gray-600"></div>
                    </div>

                </div>

                <!-- RIGHT: Detail -->
                <div class="lg:col-span-8">
                    <div class="rounded-2xl border border-gray-100">
                        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                            <div class="font-semibold">Chi tiết hồ sơ</div>

                            <div class="flex items-center gap-2">
                                <button id="ul-btn-refresh"
                                    class="px-3 py-2 text-sm rounded-xl border border-gray-200 hover:bg-gray-50">
                                    Refresh
                                </button>

                                <?php if (!$isSelfOnly): ?>
                                    <button id="ul-btn-clear"
                                        class="px-3 py-2 text-sm rounded-xl border border-gray-200 hover:bg-gray-50">
                                        Clear
                                    </button>
                                <?php endif; ?>

                            </div>
                        </div>

                        <!-- Tabs -->
                        <div class="px-5 pt-4">
                            <div id="ul-tabs" class="flex flex-wrap gap-2">
                                <!-- injected -->
                            </div>
                        </div>

                        <div class="p-5">
                            <div id="ul-detail" class="text-sm text-gray-600 w-full">
                                <div class="w-full rounded-2xl border border-gray-100 bg-gray-50 p-6">
                                    <div class="font-semibold text-gray-900">Chưa chọn user</div>
                                    <div class="text-sm text-gray-500 mt-1">
                                        Chọn 1 user ở bên trái để hiển thị thông tin.
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>

            </div>
        </div>
    </div>
</section>

<script src="<?= BASE_URL ?>assets/js/user_lookup.js?v=<?= time() ?>"></script>