<?php
// views/baocaophongtrao.php
$cu = null;
if (function_exists('auth_user')) {
  $cu = auth_user();
}
$currentUserName = $cu ? ($cu['full_name'] ?? $cu['username'] ?? 'Bạn') : 'Bạn (khách)';
$BASE_URL = defined('BASE_URL') ? BASE_URL : '';

// Lightweight auto-grant for regular users on page load (so they immediately get view/create without having to hit an action first).
// This complements the full setup that lives in the controller. Run BEFORE computing $can* vars.
try {
  if (isset($pdo) && function_exists('can')) {
    // ensure the permission row exists
    $checkPerm = $pdo->prepare("SELECT id FROM permissions WHERE code = 'baocaophongtrao' LIMIT 1");
    $checkPerm->execute();
    $permId = $checkPerm->fetchColumn();
    if ($permId) {
      $permId = (int)$permId;
      $uid = $cu['id'] ?? 0;
      $roleId = $cu['role_id'] ?? 0;

      if ($roleId > 0) {
        // give view + create to this user's role (idempotent)
        $pdo->prepare("
          INSERT IGNORE INTO role_permissions (role_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
          VALUES (?, ?, 1, 1, 0, 0, 0, 0)
        ")->execute([$roleId, $permId]);
      }
      if ($uid > 0) {
        $pdo->prepare("
          INSERT IGNORE INTO user_permissions (user_id, permission_id, can_view, can_create, can_update, can_review, can_delete, can_print)
          VALUES (?, ?, 1, 1, 0, 0, 0, 0)
        ")->execute([$uid, $permId]);
      }
    }
  }
} catch (Throwable $e) {
  // silent, don't break the page
}

$canBaocaoView = function_exists('can') ? can('baocaophongtrao', 'view') : true;
$canBaocaoCreate = function_exists('can') ? can('baocaophongtrao', 'create') : true;
$canBaocaoApprove = function_exists('can') ? can('baocaophongtrao', 'approve') : true;
$canBaocaoDelete = function_exists('can') ? can('baocaophongtrao', 'delete') : true;
?>
<!-- FontAwesome for icons in this view -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
    .movement-card {
        transition: all 0.3s ease;
    }
    .movement-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    }
    .baocao-tab {
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        font-weight: 500;
        border-bottom: 2px solid transparent;
        color: #4b5563;
        transition: all 0.2s;
    }
    .baocao-tab.active {
        color: #1d4ed8;
        border-bottom-color: #2563eb;
        font-weight: 600;
    }
    .baocao-tab:hover:not(.active) {
        color: #1f2937;
        background: #f8fafc;
    }
    .report-table th {
        font-weight: 600;
        color: #374151;
    }
    .report-row:hover {
        background: #f8fafc;
    }
    .status-badge {
        font-size: 0.75rem;
        padding: 0.125rem 0.625rem;
        border-radius: 9999px;
        font-weight: 500;
        white-space: nowrap;
    }
    .photo-chip {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        font-size: 0.75rem;
        padding: 0.125rem 0.5rem;
        border-radius: 0.5rem;
    }
    .filter-input {
        font-size: 0.875rem;
    }
    .action-btn {
        padding: 0.25rem 0.5rem;
        border-radius: 0.375rem;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        transition: all 0.1s;
    }
    .action-btn:hover {
        filter: brightness(0.95);
    }
    .modal {
        animation: fadeInScale 0.15s ease-out forwards;
    }
    @keyframes fadeInScale {
        from { opacity: 0; transform: scale(0.96); }
        to { opacity: 1; transform: scale(1); }
    }
</style>

<div class="w-full">

    <!-- Main Content -->
    <div class="w-full">
        <!-- MAIN TABS: Báo cáo | Quản lý báo cáo (sử dụng chung baocao-tab style) -->
        <div class="flex border-b mb-4 justify-start">
            <button type="button" onclick="switchMainTab('bao-cao')" id="main-tab-bao-cao"
                class="baocao-tab active flex items-center gap-2">
                <i class="fas fa-file-alt"></i>
                <span>Báo cáo</span>
            </button>
            <?php if ($canBaocaoApprove || $canBaocaoDelete): ?>
            <button type="button" onclick="switchMainTab('quan-ly')" id="main-tab-quan-ly"
                class="baocao-tab flex items-center gap-2">
                <i class="fas fa-tasks"></i>
                <span>Quản lý báo cáo</span>
            </button>
            <?php endif; ?>
        </div>

        <div class="flex items-center justify-between mb-2">
            <h2 id="main-title" class="text-2xl font-bold text-gray-800">Báo cáo hoạt động phong trào</h2>
            <div id="manage-stats-inline" class="hidden text-sm text-gray-500">
                <span id="total-reports-badge" class="px-2.5 py-0.5 bg-gray-100 rounded-full">0 báo cáo</span>
            </div>
        </div>

        <!-- CONTENT: BÁO CÁO -->
        <div id="content-bao-cao">
            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Phần 1: Phong trào diễn ra -->
                <div class="w-full lg:w-80">
                    <div id="movements-sidebar" class="bg-white border rounded-2xl shadow-sm p-6 h-fit">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="font-semibold text-lg text-gray-800">Phong trào đang diễn ra</h2>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="fetchMovements()" title="Tải lại danh sách phong trào"
                                        class="text-blue-600 hover:text-blue-800 p-1">
                                    <i class="fas fa-sync-alt text-xs"></i>
                                </button>
                                <span id="movement-count" class="text-xs bg-blue-100 text-blue-700 px-3 py-1 rounded-full">0 phong trào</span>
                            </div>
                        </div>

                        <div id="movements-list" class="space-y-4 max-h-[320px] overflow-y-auto pr-1">
                            <!-- JS render TẤT CẢ (từ DB, đã filter recent); chỉ hiển thị ~5 visible, scroll cho phần còn lại -->
                        </div>

                        <div class="mt-8 pt-6 border-t">
                            <a href="?p=campaigns" 
                               class="w-full py-3 text-blue-600 hover:bg-blue-50 rounded-xl border border-blue-200 flex items-center justify-center gap-2 text-sm font-medium transition-colors">
                                <i class="fas fa-list"></i>
                                Xem tất cả phong trào
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Phần 2: Gửi báo cáo -->
                <div class="flex-1">
                    <div class="mb-8">
                        <?php if ($canBaocaoCreate): ?>
                        <h3 class="font-semibold text-xl mb-4 flex items-center gap-2 text-gray-800">
                            <i class="fas fa-paper-plane text-blue-600"></i> Gửi báo cáo
                        </h3>
                        <div class="bg-white rounded-2xl shadow-sm border p-8">
                            <form id="report-form" onsubmit="submitReport(event)">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- Chọn phong trào -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Phong trào <span class="text-red-500">*</span></label>
                                        <select id="movement-select" 
                                                class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 bg-white text-gray-800">
                                            <!-- JS sẽ populate -->
                                        </select>
                                    </div>

                                    <!-- Ngày hoạt động -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Ngày hoạt động <span class="text-red-500">*</span></label>
                                        <input type="date" id="activity-date" 
                                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 text-gray-800">
                                    </div>

                                    <!-- Số lượng tham gia -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Số lượng tham gia <span class="text-red-500">*</span></label>
                                        <input type="number" id="participants" value="15" min="1"
                                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 text-gray-800">
                                    </div>

                                    <!-- Địa điểm -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Địa điểm tổ chức</label>
                                        <input type="text" id="location" placeholder="Ví dụ: Công viên 23/9, Quận 1"
                                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 text-gray-800">
                                    </div>

                                    <!-- Nội dung hoạt động -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Nội dung hoạt động <span class="text-red-500">*</span></label>
                                        <textarea id="description" rows="5" 
                                            placeholder="Mô tả chi tiết hoạt động đã diễn ra..."
                                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 resize-none text-gray-800"></textarea>
                                    </div>

                                    <!-- Hình ảnh -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Hình ảnh minh chứng</label>
                                        <div class="border border-dashed border-gray-300 rounded-xl p-8 text-center hover:bg-gray-50 transition-colors" id="photo-dropzone">
                                            <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                                            <p class="text-gray-600">Kéo thả hình ảnh hoặc</p>
                                            <label class="cursor-pointer inline-block mt-2 px-6 py-2 bg-blue-50 text-blue-600 rounded-xl hover:bg-blue-100">
                                                Chọn file
                                                <input type="file" id="photos" multiple accept="image/*" class="hidden">
                                            </label>
                                            <p class="text-xs text-gray-500 mt-2">PNG, JPG, JPEG (tối đa 5 ảnh)</p>
                                        </div>
                                        <div id="uploaded-files" class="mt-4 flex flex-wrap gap-2"></div>
                                    </div>
                                </div>

                                <div class="mt-8 flex gap-4">
                                    <button type="submit"
                                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-medium py-4 rounded-2xl transition-colors flex items-center justify-center gap-2">
                                        <i class="fas fa-paper-plane"></i>
                                        GỬI BÁO CÁO
                                    </button>
                                    <button type="button" onclick="resetForm()"
                                            class="px-8 py-4 border border-gray-300 hover:bg-gray-50 font-medium rounded-2xl transition-colors">
                                        Làm mới
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php else: ?>
                        <div class="bg-white border rounded-2xl p-6 text-sm text-gray-500">Bạn không có quyền gửi báo cáo mới.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- BÁO CÁO CỦA TÔI (danh sách đầy đủ thông tin) -->
            <div class="mt-8">
                <h3 class="font-semibold text-xl mb-4 flex items-center gap-2 text-gray-800">
                    <i class="fas fa-list text-blue-600"></i> Báo cáo của tôi
                </h3>
                <div id="my-reports-list">
                    <!-- JS sẽ render bảng đầy đủ thông tin cá nhân -->
                </div>
            </div>
        </div>  <!-- end content-bao-cao -->

        <!-- CONTENT: QUẢN LÝ BÁO CÁO (toàn bộ, có tác vụ và bộ lọc) -->
        <div id="content-quan-ly" class="hidden">
            <!-- PANEL: QUẢN LÝ BÁO CÁO (chi tiết + đầy đủ tác vụ + bộ lọc) -->
            <div id="panel-manage">
            <!-- Stats cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-xs text-gray-500">TỔNG BÁO CÁO</div>
                    <div id="stat-total" class="text-3xl font-bold text-gray-800 mt-1">0</div>
                </div>
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-xs text-gray-500">ĐANG CHỜ DUYỆT</div>
                    <div id="stat-pending" class="text-3xl font-bold text-yellow-600 mt-1">0</div>
                </div>
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-xs text-gray-500">ĐÃ DUYỆT</div>
                    <div id="stat-approved" class="text-3xl font-bold text-green-600 mt-1">0</div>
                </div>
                <div class="bg-white border rounded-2xl p-4">
                    <div class="text-xs text-gray-500">TỪ CHỐI</div>
                    <div id="stat-rejected" class="text-3xl font-bold text-red-600 mt-1">0</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white border rounded-2xl p-4 mb-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[180px]">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Tìm kiếm</label>
                        <input type="text" id="filter-keyword" placeholder="Tìm phong trào, người gửi, địa điểm, nội dung..." 
                               class="filter-input w-full px-3 py-2 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500">
                    </div>

                    <div class="w-full sm:w-44">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Phong trào</label>
                        <select id="filter-movement" class="filter-input w-full px-3 py-2 border border-gray-300 rounded-xl bg-white">
                            <option value="">Tất cả phong trào</option>
                        </select>
                    </div>

                    <div class="w-full sm:w-36">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Trạng thái</label>
                        <select id="filter-status" class="filter-input w-full px-3 py-2 border border-gray-300 rounded-xl bg-white">
                            <option value="">Tất cả</option>
                            <option value="pending">Đang chờ</option>
                            <option value="approved">Đã duyệt</option>
                            <option value="rejected">Từ chối</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Từ ngày</label>
                        <input type="date" id="filter-from" class="filter-input px-3 py-2 border border-gray-300 rounded-xl">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Đến ngày</label>
                        <input type="date" id="filter-to" class="filter-input px-3 py-2 border border-gray-300 rounded-xl">
                    </div>

                    <div class="flex gap-2">
                        <button type="button" onclick="resetFilters()" 
                                class="px-4 py-2 text-sm border border-gray-300 rounded-xl hover:bg-gray-50">Xóa lọc</button>
                        <button type="button" onclick="applyFilters()" 
                                class="px-4 py-2 text-sm bg-blue-600 text-white rounded-xl hover:bg-blue-700">Lọc</button>
                        <?php if ($canBaocaoCreate): ?>
                        <button type="button" onclick="seedSampleData()" title="Tạo dữ liệu mẫu demo (nếu chưa có)"
                                class="px-3 py-2 text-sm bg-amber-100 text-amber-700 border border-amber-200 rounded-xl hover:bg-amber-200">+ Seed mẫu</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Bulk actions bar / thanh tác vụ (export always visible, bulk actions when selected) -->
            <div id="bulk-bar" class="mb-3 bg-blue-50 border border-blue-200 text-blue-800 rounded-2xl px-4 py-2 flex items-center gap-3 text-sm">
                <div id="bulk-selected-actions" class="hidden flex items-center gap-3">
                    <span id="bulk-count">Đã chọn 0</span>
                    <?php if ($canBaocaoApprove): ?>
                    <button type="button" onclick="bulkApprove()" class="action-btn bg-green-600 text-white px-3 py-1 text-xs hover:bg-green-700">
                        <i class="fas fa-check-circle"></i> Duyệt
                    </button>
                    <button type="button" onclick="bulkReject()" class="action-btn bg-red-600 text-white px-3 py-1 text-xs hover:bg-red-700">
                        <i class="fas fa-times-circle"></i> Không duyệt
                    </button>
                    <?php endif; ?>
                    <?php if ($canBaocaoDelete): ?>
                    <button type="button" onclick="bulkDelete()" class="action-btn bg-gray-700 text-white px-3 py-1 text-xs hover:bg-gray-800">
                        <i class="fas fa-trash"></i> Xóa
                    </button>
                    <?php endif; ?>
                    <button type="button" onclick="clearSelection()" class="ml-auto text-xs underline">Bỏ chọn</button>
                </div>
                <div class="ml-auto">
                    <button type="button" onclick="exportReportsXLSX()" 
                            class="action-btn bg-white border border-blue-300 text-blue-700 px-3 py-1 text-xs hover:bg-blue-100 flex items-center gap-1">
                        <i class="fas fa-file-excel"></i> Xuất Excel
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-2xl shadow-sm border overflow-x-auto">
                <table class="w-full text-sm border-collapse min-w-[1100px]">
                    <thead class="bg-gray-50 text-xs uppercase">
                        <tr>
                            <th class="w-9 px-3 py-3">
                                <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)" class="w-4 h-4 accent-blue-600">
                            </th>
                            <th class="px-3 py-3 text-left w-14">ID</th>
                            <th class="px-3 py-3 text-left">Phong trào</th>
                            <th class="px-3 py-3 text-left">Người gửi</th>
                            <th class="px-3 py-3 text-center w-16">SL</th>
                            <th class="px-3 py-3 text-left">Địa điểm</th>
                            <th class="px-3 py-3 text-left w-24">Trạng thái</th>
                            <th class="px-3 py-3 text-left w-24">Ngày gửi</th>
                            <th class="px-3 py-3 text-center sticky right-0 bg-gray-50 z-10 border-l w-36">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="manage-tbody" class="divide-y divide-gray-100 text-gray-700">
                        <!-- JS render rows -->
                    </tbody>
                </table>
                <div id="manage-empty" class="hidden p-8 text-center text-gray-500">
                    <i class="fas fa-inbox text-3xl mb-2 block"></i>
                    Không có báo cáo nào phù hợp với bộ lọc.
                </div>
            </div>

            <div class="mt-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs text-gray-500">
                <div class="flex items-center gap-2">
                    <div id="manage-result-count"></div>
                    <select id="manage-page-size" class="border rounded px-1.5 py-0.5 text-xs bg-white" onchange="changePageSize(this.value)">
                        <option value="5">5</option>
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                    </select>
                    <span class="text-[10px]">/ trang</span>
                </div>
                <div id="manage-pagination" class="flex items-center gap-1"></div>
            </div>
        </div>  <!-- end panel-manage -->
    </div>  <!-- end content-quan-ly -->
</div>  <!-- end flex-1 -->
</div>  <!-- end outer row flex -->

<!-- Modal chi tiết báo cáo -->
<div id="report-modal" class="hidden fixed inset-0 bg-black/50 z-[99999] flex items-center justify-center p-4" onclick="if (event.target.id === 'report-modal') closeModal()">
    <div class="modal bg-white rounded-3xl shadow-2xl w-full max-w-3xl max-h-[92vh] overflow-auto" onclick="event.stopImmediatePropagation()">
        <div class="px-6 pt-5 pb-3 border-b flex items-center justify-between sticky top-0 bg-white z-10 rounded-t-3xl">
            <div>
                <div class="text-xs uppercase tracking-wider text-gray-500">Chi tiết báo cáo</div>
                <div id="modal-title" class="text-xl font-semibold text-gray-800"></div>
            </div>
            <button type="button" onclick="closeModal()" class="text-2xl leading-none px-2 text-gray-400 hover:text-gray-600">&times;</button>
        </div>

        <div class="p-6 space-y-5 text-sm">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                <div>
                    <div class="text-gray-500 text-xs mb-0.5">PHONG TRÀO</div>
                    <div id="modal-movement" class="font-medium text-gray-800"></div>
                </div>
                <div>
                    <div class="text-gray-500 text-xs mb-0.5">NGƯỜI GỬI</div>
                    <div id="modal-reporter" class="font-medium text-gray-800"></div>
                </div>
                <div>
                    <div class="text-gray-500 text-xs mb-0.5">NGÀY HOẠT ĐỘNG</div>
                    <div id="modal-date" class="font-medium"></div>
                </div>
                <div>
                    <div class="text-gray-500 text-xs mb-0.5">SỐ LƯỢNG THAM GIA</div>
                    <div id="modal-participants" class="font-medium"></div>
                </div>
                <div class="md:col-span-2">
                    <div class="text-gray-500 text-xs mb-0.5">ĐỊA ĐIỂM</div>
                    <div id="modal-location" class="font-medium"></div>
                </div>
                <div class="md:col-span-2">
                    <div class="text-gray-500 text-xs mb-0.5">NỘI DUNG HOẠT ĐỘNG</div>
                    <div id="modal-description" class="whitespace-pre-wrap text-gray-700 leading-relaxed bg-gray-50 p-3 rounded-xl border"></div>
                </div>
            </div>

            <!-- Trạng thái + ghi chú -->
            <div>
                <div class="flex items-center gap-3">
                    <div>
                        <div class="text-gray-500 text-xs mb-0.5">TRẠNG THÁI</div>
                        <div id="modal-status"></div>
                    </div>
                    <div class="flex-1">
                        <div class="text-gray-500 text-xs mb-0.5">GHI CHÚ DUYỆT (nếu có)</div>
                        <div id="modal-note" class="text-gray-600 italic min-h-[1.25rem]"></div>
                    </div>
                </div>
            </div>

            <!-- Hình ảnh minh chứng -->
            <div>
                <div class="text-gray-500 text-xs mb-2">HÌNH ẢNH MINH CHỨNG</div>
                <div id="modal-photos" class="flex flex-wrap gap-3"></div>
                <div id="modal-no-photos" class="text-gray-400 text-xs italic hidden">Không có hình ảnh đính kèm.</div>
            </div>
        </div>

        <div class="p-4 border-t bg-gray-50 rounded-b-3xl flex flex-wrap gap-2 justify-end sticky bottom-0">
            <div id="modal-actions" class="flex flex-wrap gap-2 w-full sm:w-auto justify-end"></div>
            <button type="button" onclick="closeModal()" 
                    class="px-5 py-2 text-sm border border-gray-300 rounded-2xl hover:bg-white">Đóng</button>
        </div>
    </div>
</div>

<!-- Toast thông báo -->
<div id="toast" class="hidden fixed bottom-6 right-6 bg-green-600 text-white px-6 py-4 rounded-2xl shadow-xl flex items-center gap-3 z-[9999] transition-transform duration-300">
    <i class="fas fa-check-circle"></i>
    <span id="toast-message">Báo cáo đã được gửi thành công!</span>
</div>

<script>
    // ===== PHP injected =====
    const CURRENT_USER = <?= json_encode($currentUserName, JSON_UNESCAPED_UNICODE) ?>;
    const BASE_URL = '<?= addslashes($BASE_URL) ?>';
    // Use relative URL for the internal API. This is the most reliable way to ensure
    // same-origin requests (cookies/session are sent) even if BASE_URL points to a different
    // environment (e.g. prod URL while developing locally).
    const API_URL = 'controllers/baocaophongtrao.php';
    console.log('[baocaophongtrao] Using relative API_URL =', API_URL, '(BASE_URL was:', BASE_URL, ')');  // for debugging connection issues

    // permissions
    const CAPS = {
      can_view: <?= $canBaocaoView ? 'true' : 'false' ?>,
      can_create: <?= $canBaocaoCreate ? 'true' : 'false' ?>,
      can_approve: <?= $canBaocaoApprove ? 'true' : 'false' ?>,
      can_delete: <?= $canBaocaoDelete ? 'true' : 'false' ?>
    };

    // real data from backend
    let movements = [];
    let myReports = [];
    let manageData = { data: [], total: 0, page: 1, total_pages: 1 };

    // legacy recentReports (derived) - kept for minimal compat
    let recentReports = [];

    // selected for bulk
    let selectedReportIds = new Set();

    // ===== FETCH FROM BACKEND =====
    async function fetchMovements() {
        const container = document.getElementById('movements-list');
        const cntEl = document.getElementById('movement-count');
        if (container) {
            container.innerHTML = '<div class="text-xs text-gray-400 italic p-2 flex items-center gap-2"><i class="fas fa-spinner fa-spin"></i> Đang tải phong trào...</div>';
        }
        try {
            const res = await fetch(API_URL + '?action=list_movements', { credentials: 'same-origin' });
            const contentType = res.headers.get('content-type') || '';
            let j;
            if (contentType.includes('application/json')) {
                j = await res.json();
            } else {
                const text = await res.text();
                console.error('list_movements non-JSON response (status ' + res.status + '):', text.substring(0, 800));
                if (container) container.innerHTML = '<div class="text-xs text-red-500 italic p-2">Lỗi server khi tải phong trào (không phải JSON, xem Console). Status: ' + res.status + '</div>';
                if (cntEl) cntEl.textContent = '0 phong trào';
                return;
            }
            if (!res.ok) {
                const err = (j && j.error) ? j.error : ('HTTP ' + res.status);
                console.warn('list_movements HTTP error:', err, j);
                if (container) container.innerHTML = '<div class="text-xs text-red-500 italic p-2">Lỗi tải phong trào: ' + err + '</div>';
                if (cntEl) cntEl.textContent = '0 phong trào';
                return;
            }
            if (j.ok && j.movements) {
                movements = j.movements;
                renderMovements();
                renderMovementSelect();
                populateFilterMovement();
            } else {
                const errMsg = (j && j.error) ? j.error : 'Phản hồi không hợp lệ';
                console.warn('list_movements:', errMsg, j);
                if (container) container.innerHTML = '<div class="text-xs text-red-500 italic p-2">Không thể tải danh sách phong trào: ' + errMsg + '</div>';
                if (cntEl) cntEl.textContent = '0 phong trào';
            }
        } catch (e) {
            console.error('fetchMovements', e);
            if (container) container.innerHTML = '<div class="text-xs text-red-500 italic p-2">Lỗi kết nối khi tải phong trào (xem Console cho chi tiết).</div>';
            if (cntEl) cntEl.textContent = '0 phong trào';
        }
    }

    async function fetchMyReports() {
        try {
            const res = await fetch(API_URL + '?action=list_my', { credentials: 'same-origin' });
            const j = await res.json();
            if (j.ok && j.reports) {
                myReports = j.reports;
                renderMyReports();
            } else if (j.ok && Array.isArray(j)) {
                myReports = j;
                renderMyReports();
            }
        } catch (e) { console.error('fetchMyReports', e); }
    }

    async function fetchManageList(resetPage = false) {
        if (resetPage) currentPage = 1;
        const params = new URLSearchParams();
        params.set('action', 'list');
        params.set('page', currentPage);
        params.set('page_size', pageSize);

        const kw = document.getElementById('filter-keyword')?.value || '';
        if (kw) params.set('kw', kw);
        const mov = document.getElementById('filter-movement')?.value || '';
        if (mov) params.set('campaign_id', mov);
        const st = document.getElementById('filter-status')?.value || '';
        if (st) params.set('status', st);
        const from = document.getElementById('filter-from')?.value || '';
        if (from) params.set('from', from);
        const to = document.getElementById('filter-to')?.value || '';
        if (to) params.set('to', to);

        try {
            const res = await fetch(API_URL + '?' + params.toString(), { credentials: 'same-origin' });
            const contentType = res.headers.get('content-type') || '';
            let j;
            if (contentType.includes('application/json')) {
                j = await res.json();
            } else {
                const text = await res.text();
                console.error('fetchManageList non-JSON response (status ' + res.status + '):', text.substring(0, 800));
                return;
            }
            if (!res.ok || !j.ok) {
                const err = (j && j.error) ? j.error : ('HTTP ' + res.status);
                console.error('fetchManageList error:', err, j);
                const tbody = document.getElementById('manage-tbody');
                const empty = document.getElementById('manage-empty');
                if (tbody) tbody.innerHTML = '';
                if (empty) {
                    empty.innerHTML = '<div class="p-8 text-center text-red-500">Lỗi tải dữ liệu: ' + err + '</div>';
                    empty.classList.remove('hidden');
                }
                return;
            }
            manageData = { data: j.data || [], total: j.total || 0, page: j.page || 1, total_pages: j.total_pages || 1 };
            renderManagementTable();
            updateStats();
            updateTotalBadge();
        } catch (e) { 
            console.error('fetchManageList', e); 
            const tbody = document.getElementById('manage-tbody');
            const empty = document.getElementById('manage-empty');
            if (tbody) tbody.innerHTML = '';
            if (empty) {
                empty.innerHTML = '<div class="p-8 text-center text-red-500">Lỗi kết nối khi tải danh sách quản lý.</div>';
                empty.classList.remove('hidden');
            }
        }
    }

    // current tab
    let currentTab = 'submit';

    // pagination state for Quản lý báo cáo table
    let currentPage = 1;
    let pageSize = 10;

    // temp photos for the submit form
    window.selectedPhotos = [];

    // ===== RENDER MOVEMENTS (sidebar) =====
    function renderMovements() {
        const container = document.getElementById('movements-list');
        if (!container) return;
        container.innerHTML = '';

        if (!movements || movements.length === 0) {
            let html = '<div class="text-xs text-gray-500 italic p-2">Không có phong trào. Hãy tạo phong trào ở menu Phong trào, hoặc dùng Seed mẫu để có dữ liệu demo.</div>';
            if (CAPS.can_create) {
                html += '<button onclick="seedSampleData()" class="mt-2 w-full text-[10px] px-2 py-1 bg-amber-100 hover:bg-amber-200 text-amber-700 rounded">+ Seed dữ liệu mẫu</button>';
            }
            container.innerHTML = html;
            const cnt = document.getElementById('movement-count');
            if (cnt) cnt.textContent = '0 phong trào';
            return;
        }

        movements.forEach(m => {
            const div = document.createElement('div');
            div.className = `movement-card p-4 border rounded-2xl cursor-pointer hover:border-blue-400 transition-all duration-200 bg-white`;
            div.innerHTML = `
                <div class="flex justify-between items-start">
                    <div>
                        <p class="font-medium text-sm text-gray-800">${m.name}</p>
                        <p class="text-xs text-gray-500 mt-1">${m.deadline}</p>
                    </div>
                    <span class="text-[10px] px-2.5 py-1 rounded-full ${m.status === 'Đang diễn ra' ? 'bg-green-100 text-green-700' : (m.status === 'Đã kết thúc' ? 'bg-gray-100 text-gray-600' : 'bg-orange-100 text-orange-700')}">
                        ${m.status}
                    </span>
                </div>
            `;
            div.onclick = () => selectMovement(m.id);
            container.appendChild(div);
        });

        const cnt = document.getElementById('movement-count');
        if (cnt) {
          const n = movements.length;
          cnt.textContent = n + (n > 5 ? ' (cuộn để xem thêm)' : '') + ' phong trào';
        }
    }

    function renderMovementSelect() {
        const select = document.getElementById('movement-select');
        if (!select) return;
        select.innerHTML = '<option value="">-- Chọn phong trào --</option>';
        movements.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.id;
            opt.textContent = m.name;
            select.appendChild(opt);
        });
    }

    function populateFilterMovement() {
        const sel = document.getElementById('filter-movement');
        if (!sel) return;
        sel.innerHTML = '<option value="">Tất cả phong trào</option>';
        movements.forEach(m => {
            const o = document.createElement('option');
            o.value = m.id;
            o.textContent = m.name;
            sel.appendChild(o);
        });
    }

    // ===== DERIVE PERSONAL RECENT (now from myReports) =====
    function getPersonalReports() {
        const list = myReports || [];
        return list
            .filter(r => r.reporter === CURRENT_USER || r.reporter.includes('Bạn'))
            .sort((a, b) => (b.submittedAt || '').localeCompare(a.submittedAt || ''));
    }

    // (old renderRecentReports removed - now using renderMyReports for full personal list)

    // ===== SELECT FROM SIDEBAR =====
    function selectMovement(id) {
        const select = document.getElementById('movement-select');
        if (select) select.value = id;

        const quanContent = document.getElementById('content-quan-ly');
        if (quanContent && !quanContent.classList.contains('hidden')) {
            const fMov = document.getElementById('filter-movement');
            if (fMov) fMov.value = id;
            applyFilters();
        } else {
            const form = document.getElementById('report-form');
            if (form) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    // ===== STATUS HELPERS =====
    function getStatusInfo(status) {
        if (status === 'approved') return { label: 'Đã duyệt', cls: 'bg-green-100 text-green-700' };
        if (status === 'rejected') return { label: 'Từ chối', cls: 'bg-red-100 text-red-700' };
        return { label: 'Đang chờ', cls: 'bg-yellow-100 text-yellow-700' };
    }

    function formatDate(d) {
        if (!d) return '—';
        if (/^\d{4}-\d{2}-\d{2}$/.test(d)) {
            const [y,m,da] = d.split('-');
            return `${da}/${m}/${y}`;
        }
        return d;
    }

    // ===== MAIN TAB SWITCH (Báo cáo / Quản lý báo cáo) =====
    function switchMainTab(tab) {
        const baoContent = document.getElementById('content-bao-cao');
        const quanContent = document.getElementById('content-quan-ly');
        const btnBao = document.getElementById('main-tab-bao-cao');
        const btnQuan = document.getElementById('main-tab-quan-ly');
        const titleEl = document.getElementById('main-title');
        const statsEl = document.getElementById('manage-stats-inline');

        if (tab === 'bao-cao') {
            baoContent.classList.remove('hidden');
            quanContent.classList.add('hidden');

            btnBao.classList.add('active');
            btnQuan.classList.remove('active');

            if (titleEl) titleEl.textContent = 'Báo cáo hoạt động phong trào';
            if (statsEl) statsEl.classList.add('hidden');

            // ensure movements loaded for sidebar
            if (!movements || movements.length === 0) {
                fetchMovements();
            } else {
                renderMovements();
            }
            // render personal list when entering
            renderMyReports();
        } else {
            baoContent.classList.add('hidden');
            quanContent.classList.remove('hidden');

            btnQuan.classList.add('active');
            btnBao.classList.remove('active');

            if (titleEl) titleEl.textContent = 'Quản lý báo cáo';
            if (statsEl) statsEl.classList.remove('hidden');

            fetchManageList();
            updateStats();
            updateTotalBadge();
        }
    }

    // ===== RENDER BÁO CÁO CỦA TÔI (full info table) =====
    function renderMyReports() {
        const container = document.getElementById('my-reports-list');
        if (!container) return;

        const personal = myReports || [];
        if (personal.length === 0) {
            container.innerHTML = '<div class="bg-white border rounded-2xl p-6 text-center text-gray-500 italic">Bạn chưa gửi báo cáo nào.</div>';
            return;
        }

        let html = `
            <div class="bg-white rounded-2xl shadow-sm border overflow-x-auto">
                <table class="w-full text-sm border-collapse min-w-[1000px]">
                    <thead class="bg-gray-50 text-xs uppercase">
                        <tr>
                            <th class="px-3 py-3 text-left w-14">ID</th>
                            <th class="px-3 py-3 text-left">Phong trào</th>
                            <th class="px-3 py-3 text-center w-16">SL</th>
                            <th class="px-3 py-3 text-left">Địa điểm</th>
                            <th class="px-3 py-3 text-center w-24">Trạng thái</th>
                            <th class="px-3 py-3 text-left w-24">Ngày gửi</th>
                            <th class="px-3 py-3 text-center w-20">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-700">
        `;

        personal.forEach(r => {
            const st = getStatusInfo(r.status);
            html += `
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-3 font-mono text-xs text-gray-500">#${r.id}</td>
                    <td class="px-3 py-3 font-medium">${r.movement}</td>
                    <td class="px-3 py-3 text-center">${r.participants}</td>
                    <td class="px-3 py-3 text-gray-600 truncate max-w-[180px]" title="${r.location || ''}">${r.location || '—'}</td>
                    <td class="px-3 py-3 text-center">
                        <span class="status-badge ${st.cls}">${st.label}</span>
                    </td>
                    <td class="px-3 py-3 text-xs text-gray-500">${r.submittedAt || '—'}</td>
                    <td class="px-3 py-3 text-center">
                        <button type="button" onclick="viewReportDetail(${r.id})" 
                                class="action-btn border border-gray-300 hover:bg-blue-50 px-2 py-1 text-xs">
                            <i class="fas fa-eye mr-1"></i> Xem
                        </button>
                    </td>
                </tr>
            `;
        });

        html += `
                    </tbody>
                </table>
            </div>
            <div class="text-xs text-gray-500 mt-2">Tổng ${personal.length} báo cáo của bạn</div>
        `;

        container.innerHTML = html;
    }

    // ===== SUBMIT REPORT (ENHANCED) =====
    async function submitReport(e) {
        e.preventDefault();

        if (!CAPS.can_create) { alert('Bạn không có quyền gửi báo cáo'); return; }

        const movementId = parseInt(document.getElementById('movement-select').value, 10);
        if (!movementId) {
            alert("Vui lòng chọn phong trào!");
            return;
        }
        const selectedMovement = movements.find(m => m.id === movementId);
        if (!selectedMovement) return;

        const activityDate = document.getElementById('activity-date').value || new Date().toISOString().split('T')[0];
        const participants = parseInt(document.getElementById('participants').value, 10) || 0;
        const location = (document.getElementById('location').value || '').trim();
        const description = (document.getElementById('description').value || '').trim();

        // client validation
        if (!activityDate) {
            alert("Vui lòng chọn ngày hoạt động!");
            return;
        }
        if (participants < 1) {
            alert("Số lượng tham gia phải >= 1");
            return;
        }
        if (participants > 10000) {
            alert("Số lượng tham gia quá lớn");
            return;
        }
        if (!description || description.length < 5) {
            alert("Vui lòng nhập nội dung hoạt động (ít nhất 5 ký tự)!");
            return;
        }
        if (description.length > 5000) {
            alert("Nội dung quá dài (tối đa 5000 ký tự)");
            return;
        }
        const today = new Date().toISOString().split('T')[0];
        if (activityDate > today) {
            const ok = await window.showConfirmModal({
              title: 'Ngày trong tương lai',
              message: 'Ngày hoạt động ở tương lai. Tiếp tục gửi báo cáo?',
              confirmText: 'Tiếp tục',
              cancelText: 'Hủy'
            });
            if (!ok.confirmed) return;
        }

        // real submit with FormData for photos
        const fd = new FormData();
        fd.append('action', 'submit');
        fd.append('campaign_id', movementId);
        fd.append('activity_date', activityDate);
        fd.append('participants', participants);
        fd.append('location', location);
        fd.append('description', description);

        (window.selectedPhotos || []).forEach(p => {
            if (p.file) fd.append('photos[]', p.file, p.name);
        });

        try {
            const res = await fetch(API_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await res.json();
            if (!j.ok) {
                alert(j.error || 'Gửi báo cáo thất bại');
                return;
            }
            showToast("Báo cáo đã được gửi thành công! Cảm ơn bạn. Báo cáo đang chờ duyệt.");
            resetForm();
            await fetchMyReports();
            await fetchManageList();
            updateStats();
            updateTotalBadge();
        } catch (e) {
            console.error(e);
            alert('Lỗi kết nối backend');
        }
    }

    // ===== PHOTO HANDLING FOR FORM =====
    function initPhotoUpload() {
        const input = document.getElementById('photos');
        const drop = document.getElementById('photo-dropzone');
        const list = document.getElementById('uploaded-files');
        if (!input || !list) return;

        window.selectedPhotos = window.selectedPhotos || [];

        function renderUploadedList() {
            list.innerHTML = '';
            window.selectedPhotos.forEach((p, idx) => {
                const chip = document.createElement('div');
                chip.className = 'photo-chip flex items-center gap-1.5 pr-1';
                chip.innerHTML = `
                    <i class="fas fa-image text-gray-400 ml-1.5"></i>
                    <span class="truncate max-w-[140px]">${p.name}</span>
                    <button type="button" class="ml-1 w-5 h-5 flex items-center justify-center text-gray-400 hover:text-red-500" title="Xóa">&times;</button>
                `;
                chip.querySelector('button').onclick = (ev) => {
                    ev.stopPropagation();
                    window.selectedPhotos.splice(idx, 1);
                    renderUploadedList();
                };
                list.appendChild(chip);
            });
        }

        function addFiles(fileList) {
            Array.from(fileList).slice(0, 5).forEach(file => {
                if (!file.type.startsWith('image/')) return;
                if (window.selectedPhotos.some(p => p.name === file.name)) return;
                window.selectedPhotos.push({ name: file.name, file: file });
            });
            renderUploadedList();
        }

        input.onchange = () => {
            addFiles(input.files);
            input.value = '';
        };

        if (drop) {
            ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('bg-blue-50'); }));
            ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('bg-blue-50'); }));
            drop.addEventListener('drop', e => {
                if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
            });
        }

        window.clearSelectedPhotos = () => { window.selectedPhotos = []; renderUploadedList(); };
    }

    // ===== TAB SWITCHING =====
    function switchTab(tab) {
        currentTab = tab;
        const submitP = document.getElementById('panel-submit');
        const manageP = document.getElementById('panel-manage');
        const btnS = document.getElementById('tab-btn-submit');
        const btnM = document.getElementById('tab-btn-manage');
        const title = document.getElementById('main-title');
        const inlineStats = document.getElementById('manage-stats-inline');

        if (tab === 'submit') {
            submitP.classList.remove('hidden');
            manageP.classList.add('hidden');
            btnS.classList.add('active');
            btnM.classList.remove('active');
            if (title) title.textContent = 'Báo cáo hoạt động phong trào';
            if (inlineStats) inlineStats.classList.add('hidden');
        } else {
            submitP.classList.add('hidden');
            manageP.classList.remove('hidden');
            btnS.classList.remove('active');
            btnM.classList.add('active');
            if (title) title.textContent = 'Quản lý các báo cáo đã gửi';
            if (inlineStats) inlineStats.classList.remove('hidden');
            populateFilterMovement();
            fetchManageList();
            updateStats();
            updateTotalBadge();

            const ps = document.getElementById('manage-page-size');
            if (ps) ps.value = pageSize;
        }
    }

    // ===== MANAGEMENT TABLE + FILTERS (server side now) =====
    // getFilteredReports removed - filters handled in fetchManageList / backend
    function getFilteredReports() { return []; }

    function renderManagementTable() {
        const tbody = document.getElementById('manage-tbody');
        const empty = document.getElementById('manage-empty');
        if (!tbody) return;

        const pageData = (manageData && manageData.data) || [];
        const total = (manageData && manageData.total) || 0;
        const totalPages = (manageData && manageData.total_pages) || 1;
        const page = (manageData && manageData.page) || currentPage;

        tbody.innerHTML = '';

        if (pageData.length === 0) {
            empty?.classList.remove('hidden');
            const rc = document.getElementById('manage-result-count');
            if (rc) rc.textContent = '0 kết quả';
            renderPagination(total, totalPages, page);
            return;
        }
        empty?.classList.add('hidden');

        const rc = document.getElementById('manage-result-count');
        const from = (page - 1) * pageSize + 1;
        const to = from + pageData.length - 1;
        if (rc) rc.textContent = `Hiển thị ${from} - ${to} / ${total} báo cáo`;

        pageData.forEach(r => {
            const statusInfo = getStatusInfo(r.status);
            const tr = document.createElement('tr');
            tr.className = 'report-row';
            tr.innerHTML = `
                <td class="px-3 py-3">
                    <input type="checkbox" class="row-cb w-4 h-4 accent-blue-600" data-id="${r.id}" ${selectedReportIds.has(r.id) ? 'checked' : ''}>
                </td>
                <td class="px-3 py-3 font-mono text-xs text-gray-500">#${r.id}</td>
                <td class="px-3 py-3 font-medium">${r.movement}</td>
                <td class="px-3 py-3">${r.reporter}</td>
                <td class="px-3 py-3 text-center">${r.participants}</td>
                <td class="px-3 py-3 text-gray-600 truncate max-w-[160px]" title="${r.location || ''}">${r.location || '—'}</td>
                <td class="px-3 py-3 text-left">
                    <span class="status-badge ${statusInfo.cls}">${statusInfo.label}</span>
                </td>
                <td class="px-3 py-3 text-xs text-gray-500">${r.submittedAt || '—'}</td>
                <td class="px-3 py-2 sticky right-0 bg-white border-l z-10">
                    <div class="flex items-center justify-center gap-1 flex-wrap">
                        ${CAPS.can_view ? `
                        <button type="button" class="action-btn border border-gray-300 hover:bg-gray-100" onclick="viewReportDetail(${r.id})" title="Xem chi tiết">
                            <i class="fas fa-eye text-blue-600"></i>
                        </button>` : ''}
                        ${r.status === 'pending' && CAPS.can_approve ? `
                        <button type="button" class="action-btn bg-green-100 hover:bg-green-200 text-green-700" onclick="approveReport(${r.id})" title="Duyệt">
                            <i class="fas fa-check-circle"></i>
                        </button>
                        <button type="button" class="action-btn bg-red-100 hover:bg-red-200 text-red-700" onclick="rejectReport(${r.id})" title="Không duyệt">
                            <i class="fas fa-times-circle"></i>
                        </button>` : ''}
                    </div>
                </td>
            `;
            const cb = tr.querySelector('.row-cb');
            if (cb) {
                cb.onchange = () => {
                    if (cb.checked) selectedReportIds.add(r.id);
                    else selectedReportIds.delete(r.id);
                    updateBulkBar();
                };
            }
            tbody.appendChild(tr);
        });

        renderPagination(total, totalPages, page);
        updateBulkBar();
    }

    function renderPagination(total, totalPages, page) {
        const container = document.getElementById('manage-pagination');
        if (!container) return;
        container.innerHTML = '';

        if (totalPages <= 1) return;

        const btnClass = 'px-2.5 py-1 text-xs border rounded hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed';

        // Prev
        const prev = document.createElement('button');
        prev.innerHTML = '&laquo;';
        prev.className = btnClass;
        prev.disabled = page === 1;
        if (!prev.disabled) prev.onclick = () => goToPage(page - 1);
        container.appendChild(prev);

        // Page numbers (simple 1..total or limited)
        const maxShow = 7;
        let start = 1;
        let end = totalPages;

        if (totalPages > maxShow) {
            start = Math.max(1, page - 3);
            end = Math.min(totalPages, page + 3);
            if (start === 1) end = maxShow;
            if (end === totalPages) start = totalPages - maxShow + 1;
        }

        if (start > 1) {
            const b1 = document.createElement('button');
            b1.textContent = '1';
            b1.className = btnClass;
            b1.onclick = () => goToPage(1);
            container.appendChild(b1);
            if (start > 2) {
                const dots = document.createElement('span');
                dots.textContent = '...';
                dots.className = 'px-1 text-xs';
                container.appendChild(dots);
            }
        }

        for (let i = start; i <= end; i++) {
            const b = document.createElement('button');
            b.textContent = i;
            b.className = btnClass + (i === page ? ' bg-blue-600 text-white border-blue-600' : '');
            if (i !== page) b.onclick = () => goToPage(i);
            container.appendChild(b);
        }

        if (end < totalPages) {
            if (end < totalPages - 1) {
                const dots = document.createElement('span');
                dots.textContent = '...';
                dots.className = 'px-1 text-xs';
                container.appendChild(dots);
            }
            const bLast = document.createElement('button');
            bLast.textContent = totalPages;
            bLast.className = btnClass;
            bLast.onclick = () => goToPage(totalPages);
            container.appendChild(bLast);
        }

        // Next
        const next = document.createElement('button');
        next.innerHTML = '&raquo;';
        next.className = btnClass;
        next.disabled = page === totalPages;
        if (!next.disabled) next.onclick = () => goToPage(page + 1);
        container.appendChild(next);
    }

    function goToPage(page) {
        currentPage = page;
        fetchManageList();
    }

    function changePageSize(newSize) {
        pageSize = parseInt(newSize, 10) || 10;
        currentPage = 1;
        fetchManageList();
    }

    function applyFilters() {
        fetchManageList(true);
    }

    function resetFilters() {
        ['filter-keyword','filter-movement','filter-status','filter-from','filter-to'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        fetchManageList(true);
    }

    function attachFilterListeners() {
        const ids = ['filter-keyword','filter-movement','filter-status','filter-from','filter-to'];
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            // server-side filter: trigger fetch (apply resets page)
            const handler = () => applyFilters();
            el.addEventListener('input', handler);
            el.addEventListener('change', handler);
        });
    }

    // ===== BULK =====
    function updateBulkBar() {
        const bar = document.getElementById('bulk-bar');
        const selectedActions = document.getElementById('bulk-selected-actions');
        const cnt = document.getElementById('bulk-count');
        if (!bar || !selectedActions || !cnt) return;
        const n = selectedReportIds.size;
        if (n > 0) {
            cnt.textContent = `Đã chọn ${n}`;
            selectedActions.classList.remove('hidden');
        } else {
            selectedActions.classList.add('hidden');
        }
    }

    function toggleSelectAll(checkbox) {
        const tbody = document.getElementById('manage-tbody');
        if (!tbody) return;
        const cbs = tbody.querySelectorAll('.row-cb');
        cbs.forEach(cb => {
            const id = parseInt(cb.dataset.id, 10);
            if (checkbox.checked) {
                selectedReportIds.add(id);
                cb.checked = true;
            } else {
                selectedReportIds.delete(id);
                cb.checked = false;
            }
        });
        updateBulkBar();
    }

    function clearSelection() {
        selectedReportIds.clear();
        document.querySelectorAll('#manage-tbody .row-cb').forEach(cb => cb.checked = false);
        const allCb = document.getElementById('select-all');
        if (allCb) allCb.checked = false;
        updateBulkBar();
    }

    async function bulkApprove() {
        if (selectedReportIds.size === 0) return;
        if (!CAPS.can_approve) { alert('Bạn không có quyền duyệt'); return; }
        const res = await window.showConfirmModal({
          title: 'Duyệt nhiều báo cáo',
          message: 'Nhập ghi chú duyệt (chung cho các báo cáo đã chọn, tùy chọn):',
          confirmText: 'Duyệt',
          cancelText: 'Hủy',
          input: {
            label: 'Ghi chú duyệt',
            placeholder: 'Nhập ghi chú (tùy chọn)...',
            type: 'textarea',
            defaultValue: ''
          }
        });
        if (!res.confirmed) return;
        const note = res.value || '';
        for (const id of selectedReportIds) {
            try {
                await fetch(API_URL, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action: 'approve', id, note}),
                    credentials: 'same-origin'
                });
            } catch (e) {}
        }
        selectedReportIds.clear();
        await fetchManageList();
        updateStats();
        renderMyReports();
        showToast('Đã duyệt các báo cáo đã chọn.');
    }

    async function bulkReject() {
        if (selectedReportIds.size === 0) return;
        if (!CAPS.can_approve) { alert('Bạn không có quyền từ chối'); return; }
        const res = await window.showConfirmModal({
          title: 'Từ chối nhiều báo cáo',
          message: 'Nhập lý do từ chối (chung):',
          confirmText: 'Từ chối',
          cancelText: 'Hủy',
          danger: true,
          input: {
            label: 'Lý do từ chối',
            placeholder: 'Nhập lý do...',
            type: 'textarea',
            defaultValue: ''
          }
        });
        if (!res.confirmed) return;
        const note = res.value || '';
        for (const id of selectedReportIds) {
            try {
                await fetch(API_URL, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action: 'reject', id, note}),
                    credentials: 'same-origin'
                });
            } catch (e) {}
        }
        selectedReportIds.clear();
        await fetchManageList();
        updateStats();
        renderMyReports();
        showToast('Đã từ chối các báo cáo đã chọn.');
    }

    async function bulkDelete() {
        if (selectedReportIds.size === 0) return;
        if (!CAPS.can_delete) { alert('Bạn không có quyền xóa'); return; }
        const ok = await window.showConfirmModal({
          title: 'Xóa nhiều báo cáo',
          message: `Xóa ${selectedReportIds.size} báo cáo đã chọn? Hành động không thể hoàn tác.`,
          confirmText: 'Xóa',
          cancelText: 'Hủy',
          danger: true
        });
        if (!ok.confirmed) return;
        for (const id of selectedReportIds) {
            try {
                await fetch(API_URL, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action: 'delete', id}),
                    credentials: 'same-origin'
                });
            } catch (e) {}
        }
        selectedReportIds.clear();
        await fetchManageList();
        updateStats();
        renderMyReports();
        updateTotalBadge();
        showToast('Đã xóa các báo cáo đã chọn.');
    }

    // ===== SINGLE ACTIONS =====
    async function approveReport(id) {
        if (!CAPS.can_approve) { alert('Bạn không có quyền duyệt'); return false; }
        const res = await window.showConfirmModal({
          title: 'Duyệt báo cáo',
          message: 'Nhập ghi chú duyệt (tùy chọn):',
          confirmText: 'Duyệt',
          cancelText: 'Hủy',
          input: {
            label: 'Ghi chú duyệt',
            placeholder: 'Nhập ghi chú (tùy chọn)...',
            type: 'textarea',
            defaultValue: ''
          }
        });
        if (!res.confirmed) return false;
        const note = res.value || '';
        try {
            const res2 = await fetch(API_URL, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action: 'approve', id, note}),
                credentials: 'same-origin'
            });
            const j = await res2.json();
            if (j.ok) {
                await fetchManageList();
                updateStats();
                renderMyReports();
                showToast('Đã duyệt báo cáo #' + id);
            } else if (j.error) {
                alert(j.error);
            }
        } catch (e) { console.error(e); }
        return true;
    }

    async function rejectReport(id) {
        if (!CAPS.can_approve) { alert('Bạn không có quyền từ chối'); return false; }
        const res = await window.showConfirmModal({
          title: 'Từ chối báo cáo',
          message: 'Nhập lý do từ chối:',
          confirmText: 'Từ chối',
          cancelText: 'Hủy',
          danger: true,
          input: {
            label: 'Lý do từ chối',
            placeholder: 'Nhập lý do...',
            type: 'textarea',
            defaultValue: ''
          }
        });
        if (!res.confirmed) return false;
        const note = res.value || '';
        try {
            const res2 = await fetch(API_URL, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action: 'reject', id, note}),
                credentials: 'same-origin'
            });
            const j = await res2.json();
            if (j.ok) {
                await fetchManageList();
                updateStats();
                renderMyReports();
                showToast('Đã từ chối báo cáo #' + id);
            } else if (j.error) {
                alert(j.error);
            }
        } catch (e) { console.error(e); }
        return true;
    }

    async function deleteReport(id) {
        if (!CAPS.can_delete) { alert('Bạn không có quyền xóa'); return; }
        try {
            const res = await fetch(API_URL, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action: 'delete', id}),
                credentials: 'same-origin'
            });
            const j = await res.json();
            if (j.ok) {
                await fetchManageList();
                updateStats();
                renderMyReports();
                updateTotalBadge();
                showToast('Đã xóa báo cáo.');
            } else if (j.error) {
                alert(j.error);
            }
        } catch (e) { console.error(e); }
    }

    // ===== DETAIL MODAL =====
    function viewReportDetail(id) {
        let r = (myReports || []).find(x => x.id === id);
        if (!r) r = ((manageData && manageData.data) || []).find(x => x.id === id);
        if (!r) return;

        document.getElementById('modal-title').textContent = `#${r.id} — ${r.movement}`;
        document.getElementById('modal-movement').textContent = r.movement;
        document.getElementById('modal-reporter').textContent = r.reporter;
        document.getElementById('modal-date').textContent = formatDate(r.activityDate) + (r.activityDate ? ` (${r.activityDate})` : '');
        document.getElementById('modal-participants').textContent = r.participants + ' người';
        document.getElementById('modal-location').textContent = r.location || '—';
        document.getElementById('modal-description').textContent = r.description || '(không có mô tả)';

        const st = getStatusInfo(r.status);
        const statusEl = document.getElementById('modal-status');
        statusEl.innerHTML = `<span class="status-badge ${st.cls} text-base px-3 py-0.5">${st.label}</span>`;

        const noteEl = document.getElementById('modal-note');
        noteEl.textContent = r.reviewNote ? r.reviewNote : '(chưa có ghi chú)';

        const photosWrap = document.getElementById('modal-photos');
        const noPhotos = document.getElementById('modal-no-photos');
        photosWrap.innerHTML = '';
        if (r.photos && r.photos.length > 0) {
            noPhotos.classList.add('hidden');
            r.photos.forEach((p, i) => {
                const name = (typeof p === 'string') ? p : (p.name || p.url || 'ảnh');
                let openUrl = '';
                if (typeof p === 'string') {
                    if (/^https?:\/\//i.test(p)) openUrl = p;
                    else openUrl = BASE_URL + 'uploads/phongtrao_reports/' + p; // legacy local
                } else {
                    openUrl = p.url || (p.id ? ('https://drive.google.com/file/d/' + p.id + '/view') : '');
                }
                const div = document.createElement('div');
                div.className = 'w-28 h-20 border rounded-xl overflow-hidden bg-gray-100 flex flex-col cursor-pointer';
                div.innerHTML = `
                    <div class="flex-1 flex items-center justify-center text-[10px] text-gray-400 bg-[repeating-linear-gradient(45deg,#f1f5f9,#f1f5f9_4px,#e2e8f0_4px,#e2e8f0_8px)]">
                        <i class="fas fa-image text-xl"></i>
                    </div>
                    <div class="px-1.5 py-0.5 text-[10px] truncate bg-white border-t text-center" title="${name}">${name}</div>
                `;
                div.onclick = () => {
                    if (openUrl) window.open(openUrl, '_blank');
                };
                photosWrap.appendChild(div);
            });
        } else {
            noPhotos.classList.remove('hidden');
        }

        const actions = document.getElementById('modal-actions');
        actions.innerHTML = '';
        if (r.status === 'pending' && CAPS.can_approve) {
            const b1 = document.createElement('button');
            b1.className = 'px-4 py-2 text-sm rounded-2xl bg-green-600 text-white flex items-center gap-2 hover:bg-green-700';
            b1.innerHTML = '<i class="fas fa-check-circle"></i> Duyệt báo cáo';
            b1.onclick = async () => { if (await approveReport(id)) closeModal(); };
            actions.appendChild(b1);

            const b2 = document.createElement('button');
            b2.className = 'px-4 py-2 text-sm rounded-2xl bg-red-600 text-white flex items-center gap-2 hover:bg-red-700';
            b2.innerHTML = '<i class="fas fa-times-circle"></i> Không duyệt';
            b2.onclick = async () => { if (await rejectReport(id)) closeModal(); };
            actions.appendChild(b2);
        }

        if (CAPS.can_delete) {
            const del = document.createElement('button');
            del.className = 'px-4 py-2 text-sm rounded-2xl border border-red-300 text-red-600 hover:bg-red-50 flex items-center gap-2';
            del.innerHTML = '<i class="fas fa-trash"></i> Xóa';
            del.onclick = async () => {
              const ok = await window.showConfirmModal({
                title: 'Xóa báo cáo',
                message: 'Xóa báo cáo này? Hành động không thể hoàn tác.',
                confirmText: 'Xóa',
                cancelText: 'Hủy',
                danger: true
              });
              if (ok.confirmed) { deleteReport(id); closeModal(); }
            };
            actions.appendChild(del);
        } else if (!CAPS.can_approve) {
            // no actions possible, show note
            const note = document.createElement('span');
            note.className = 'text-xs text-gray-500 px-2';
            note.textContent = 'Không có quyền thao tác';
            actions.appendChild(note);
        }

        const m = document.getElementById('report-modal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }

    function closeModal() {
        const m = document.getElementById('report-modal');
        m.classList.remove('flex');
        m.classList.add('hidden');
        fetchManageList();
        renderMyReports();
        updateStats();
    }

    // ===== STATS + BADGES =====
    function updateStats() {
        const total = (manageData && manageData.total) || 0;
        // stats pending etc would require full summary from backend; approximate with 0 for now or fetch extra
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        set('stat-total', total);
        set('stat-pending', 0);
        set('stat-approved', 0);
        set('stat-rejected', 0);
    }

    function updateTotalBadge() {
        const el = document.getElementById('total-reports-badge');
        if (el) el.textContent = ((manageData && manageData.total) || 0) + ' báo cáo';
    }

    // ===== EXPORT XLSX (real Excel via backend) =====
    async function exportReportsXLSX() {
        const params = new URLSearchParams();
        params.set('action', 'export_xlsx');

        const kw = document.getElementById('filter-keyword')?.value || '';
        if (kw) params.set('kw', kw);
        const mov = document.getElementById('filter-movement')?.value || '';
        if (mov) params.set('campaign_id', mov);
        const st = document.getElementById('filter-status')?.value || '';
        if (st) params.set('status', st);
        const from = document.getElementById('filter-from')?.value || '';
        if (from) params.set('from', from);
        const to = document.getElementById('filter-to')?.value || '';
        if (to) params.set('to', to);

        try {
            // Use direct navigation for binary download (simpler, no CORS/blob issues for xlsx)
            const url = API_URL + '?' + params.toString();
            const a = document.createElement('a');
            a.href = url;
            // filename will be set by server header
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        } catch (e) {
            alert('Lỗi xuất Excel');
        }
    }

    // legacy alias
    window.exportReportsCSV = exportReportsXLSX;

    function showToast(message) {
        const toast = document.getElementById('toast');
        if (!toast) return;
        document.getElementById('toast-message').textContent = message;
        toast.classList.remove('hidden');
        toast.style.transform = 'translateY(0)';
        setTimeout(() => {
            toast.style.transform = 'translateY(100px)';
            setTimeout(() => toast.classList.add('hidden'), 300);
        }, 3800);
    }

    function resetForm() {
        const form = document.getElementById('report-form');
        if (form) form.reset();
        const dateInput = document.getElementById('activity-date');
        if (dateInput) dateInput.value = new Date().toISOString().split('T')[0];
        const up = document.getElementById('uploaded-files');
        if (up) up.innerHTML = '';
        if (window.clearSelectedPhotos) window.clearSelectedPhotos();
        else window.selectedPhotos = [];
    }

    async function seedSampleData() {
        const ok = await window.showConfirmModal({
          title: 'Seed dữ liệu mẫu',
          message: 'Tạo thêm dữ liệu mẫu báo cáo phong trào (demo)?',
          confirmText: 'Seed',
          cancelText: 'Hủy'
        });
        if (!ok.confirmed) return;
        try {
            const res = await fetch(API_URL + '?action=seed', {method:'POST', credentials: 'same-origin'});
            const j = await res.json();
            if (j.ok) {
                showToast('Đã seed ' + (j.seeded||0) + ' báo cáo mẫu.');
                await fetchManageList(true);
                await fetchMyReports();
                await fetchMovements();  // refresh sidebar in case new campaign was created
                updateStats();
                updateTotalBadge();
            } else {
                alert(j.error || 'Seed thất bại (cần quyền create)');
            }
        } catch(e){ alert('Lỗi seed'); }
    }

    function attachGlobalListeners() {
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const modal = document.getElementById('report-modal');
                if (modal && !modal.classList.contains('hidden')) closeModal();
            }
        });
    }

    // ===== INIT =====
    (function init() {
        const dateInput = document.getElementById('activity-date');
        if (dateInput) dateInput.value = new Date().toISOString().split('T')[0];

        initPhotoUpload();
        attachFilterListeners();
        attachGlobalListeners();

        updateStats();
        updateTotalBadge();

        // default to "Báo cáo" tab (will render empty then populate)
        switchMainTab('bao-cao');

        // start real data loads (fetch will render when done)
        fetchMovements();
        fetchMyReports();

        // legacy recent will be filled after myReports arrives
        // (getPersonalReports used in other legacy paths)

        const psEl = document.getElementById('manage-page-size');
        if (psEl) {
            psEl.value = pageSize;
        }

        window.BAOCAO_PHONGTRAO = { movements, myReports, manageData, CAPS, switchMainTab, switchTab, renderManagementTable, renderMyReports };
    })();
</script>
