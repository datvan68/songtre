<?php
require __DIR__ . '/../config/db.php';

auth_guard();

if (!can('attend_list', 'view')) {
    http_response_code(403);

    if (isset($_GET['ajax'])) {
        echo json_encode([
            'success' => false,
            'error' => 'FORBIDDEN'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo "<section class='p-6 text-red-600 font-semibold'>
            403 – Bạn không có quyền xem danh sách điểm danh.
        </section>";
    }
    exit;
}



// ===================================================
// API MODE (ajax=1) — PHÂN TRANG
// ===================================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {

    auth_guard();

    if (!can('attendance', 'view')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Forbidden'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // phần code cũ giữ nguyên


    $cid = (int) ($_GET['campaign_id'] ?? 0);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    // Đếm tổng
    $countStm = $pdo->prepare("
        SELECT COUNT(*)
        FROM attendance_logs
        WHERE campaign_id = ?
    ");
    $countStm->execute([$cid]);
    $totalRows = (int) $countStm->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $limit));

    // Lấy dữ liệu
    $stm = $pdo->prepare("
        SELECT 
            m.fullname,
            m.class_name,
            l.time,
            l.session
        FROM attendance_logs l
        JOIN members m ON m.user_id = l.user_id
        WHERE l.campaign_id = ?
        ORDER BY l.time DESC
        LIMIT $limit OFFSET $offset
    ");
    $stm->execute([$cid]);

    echo json_encode([
        "success" => true,
        "rows" => $stm->fetchAll(),
        "page" => $page,
        "total_pages" => $totalPages
    ]);
    exit;
}



// ===========================================
// LẤY DANH SÁCH PHONG TRÀO
// ===========================================
$allCampaigns = $pdo->query("SELECT id, title FROM campaigns ORDER BY id DESC")->fetchAll();

// Lấy campaign_id hiện tại
$cid = (int) ($_GET['campaign_id'] ?? 0);

// Nếu chưa chọn phong trào → chọn cái đầu tiên
if ($cid <= 0 && !empty($allCampaigns)) {
    $cid = $allCampaigns[0]['id'];
}

// ===========================================
// LẤY THÔNG TIN PHONG TRÀO
// ===========================================
$stm = $pdo->prepare("SELECT * FROM campaigns WHERE id=? LIMIT 1");
$stm->execute([$cid]);
$campaign = $stm->fetch();
?>

<div class="p-6">
    <div class="grid-container">

        <!-- DROPDOWN CHỌN PHONG TRÀO -->
        <div class=" flex items-center gap-3 w-full mb-6">
            <label class="text-gray-700 font-medium whitespace-nowrap font-bold">
                Chọn phong trào:
            </label>

            <select id="campaignSelect" class="px-4 py-2 border rounded-lg bg-white shadow-sm w-full max-w-full">
                <?php foreach ($allCampaigns as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $c['id'] == $cid ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- HEADER -->
        <!-- HÀNG 1: title + nút -->
        <div class="flex items-center justify-between mb-1">

            <h1 id="campaignTitle" class="font-heading text-3xl font-bold ">
                <?= htmlspecialchars($campaign['title'] ?? 'Không xác định') ?>
            </h1>

            <?php if (can('attendance', 'print')): ?>
                <a id="exportBtn" href="<?= BASE_URL ?>controllers/attendance_export.php?campaign_id=<?= $cid ?>"
                    class="px-5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 shadow-sm shrink-0">
                    ⬇ Xuất Excel
                </a>
            <?php endif; ?>

        </div>

        <!-- HÀNG 2: subtitle -->
        <p class="text-gray-500 mb-4">Danh sách điểm danh</p>





        <!-- CARD TABLE -->
        <div class="bg-white shadow-lg rounded-2xl overflow-hidden border border-gray-100">
            <div class="overflow-x-auto">

                <table class="min-w-full text-sm text-gray-800">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr class="text-gray-600 uppercase text-xs tracking-wider">
                            <th class="px-5 py-3 text-left">Tên</th>
                            <th class="px-5 py-3 text-left">Lớp</th>
                            <th class="px-5 py-3 text-left">Thời gian</th>
                            <th class="px-5 py-3 text-left">Buổi</th>
                        </tr>
                    </thead>

                    <tbody id="attendanceBody" class="divide-y divide-gray-100">
                        <!-- JS sẽ fill dữ liệu -->
                    </tbody>

                </table>

            </div>
            <div id="pagination" class="flex items-center justify-center gap-2 py-4"></div>

        </div>

    </div>
</div>

<script>
    // ============================
    // RENDER BẢNG — GIỮ NGUYÊN LAYOUT
    // ============================
    function renderTable(rows) {
        const body = document.getElementById("attendanceBody");

        if (!rows || rows.length === 0) {
            body.innerHTML = `
                <tr>
                    <td colspan="4" class="py-8 text-center text-gray-500 italic">
                        Chưa có ai điểm danh
                    </td>
                </tr>
            `;
            return;
        }

        const sessionText = {
            morning: "Sáng",
            afternoon: "Chiều",
            evening: "Tối"
        };

        body.innerHTML = rows.map(r => {
            const time = new Date(r.time).toLocaleString("vi-VN");

            return `
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-5 py-3 font-medium">${r.fullname}</td>
                    <td class="px-5 py-3">${r.class_name}</td>
                    <td class="px-5 py-3">${time}</td>
                    <td class="px-5 py-3">
                        <span class="inline-block px-2 py-1 rounded-md bg-blue-50 text-blue-700 text-xs font-medium">
                            ${sessionText[r.session] || "Không xác định"}
                        </span>
                    </td>
                </tr>
            `;
        }).join("");
    }

    // ============================
    // SỰ KIỆN CHỌN PHONG TRÀO (AJAX)
    // ============================
    document.getElementById("campaignSelect").addEventListener("change", async function () {

        const id = this.value;

        // Cập nhật URL
        window.history.replaceState({}, "", `?p=attend_list&campaign_id=${id}`);

        // Cập nhật TITLE
        const title = this.options[this.selectedIndex].textContent;
        document.getElementById("campaignTitle").textContent = title;


        // Update link export
        document.getElementById("exportBtn").href =
            "<?= BASE_URL ?>controllers/attendance_export.php?campaign_id=" + id;

        // Fetch API
        const res = await api(`index.php?p=attend_list&ajax=1&campaign_id=${id}`);
        const data = await res.json();

        if (data.success) {
            CURRENT_CAMPAIGN = id;
            loadAttendance(1);
        }


    });

    // Load bảng lúc mở trang
    document.getElementById("campaignSelect").dispatchEvent(new Event("change"));
</script>

<script>
    
    let CURRENT_PAGE = 1;
    let TOTAL_PAGES = 1;
    let CURRENT_CAMPAIGN = <?= (int) $cid ?>;

    // ============================
    // RENDER PAGINATION
    // ============================
    function renderPagination() {
        const p = document.getElementById("pagination");

        const page = CURRENT_PAGE;
        const totalPages = TOTAL_PAGES;

        p.innerHTML = `
  <div class="flex items-center gap-2 justify-center select-none">

    <!-- FIRST -->
    <button
      class="px-3 py-1 border rounded-lg ${page === 1 ? "opacity-50 pointer-events-none" : ""}"
      onclick="gotoPage(1)"
      title="Trang đầu">
      &laquo;
    </button>

    <!-- PREV -->
    <button
      class="px-3 py-1 border rounded-lg ${page === 1 ? "opacity-50 pointer-events-none" : ""}"
      onclick="gotoPage(${page - 1})"
      title="Trang trước">
      &lsaquo;
    </button>

    <!-- INPUT -->
    <div class="flex items-center gap-1 text-sm">
      <input
        type="number"
        min="1"
        max="${totalPages}"
        value="${page}"
        class="w-12 px-2 py-1 border rounded-lg text-center focus:ring-2 focus:ring-primary"
        onchange="gotoPage(this.value)"
      />
      <span class="text-gray-500">/ ${totalPages}</span>
    </div>

    <!-- NEXT -->
    <button
      class="px-3 py-1 border rounded-lg ${page === totalPages ? "opacity-50 pointer-events-none" : ""}"
      onclick="gotoPage(${page + 1})"
      title="Trang sau">
      &rsaquo;
    </button>

    <!-- LAST -->
    <button
      class="px-3 py-1 border rounded-lg ${page === totalPages ? "opacity-50 pointer-events-none" : ""}"
      onclick="gotoPage(${totalPages})"
      title="Trang cuối">
      &raquo;
    </button>

  </div>
`;

    }

    // ============================
    // LOAD DATA THEO PAGE
    // ============================
    async function loadAttendance(page = 1) {


        page = Math.max(1, Math.min(page, TOTAL_PAGES));

        const res = await api(
            `index.php?p=attend_list&ajax=1&campaign_id=${CURRENT_CAMPAIGN}&page=${page}`
        );
        const data = await res.json();

        if (data.success) {
            CURRENT_PAGE = data.page;
            TOTAL_PAGES = data.total_pages;

            renderTable(data.rows);
            renderPagination();
        }
        if (TOTAL_PAGES <= 1 && (!data.rows || data.rows.length === 0)) {
            document.getElementById("pagination").innerHTML = "";
        }

    }

    function gotoPage(p) {
        loadAttendance(parseInt(p));
    }
</script>