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

    if (!can('attend_list', 'view')) {
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

    $uid = $_SESSION['user_id'] ?? 0;

    // lấy role
    $stmt = $pdo->prepare("
  SELECT r.name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = ?
");
    $stmt->execute([$uid]);
    $currentRole = $stmt->fetchColumn();

    $scope = null;
    $gvcnClassIds = [];

    // ===== BÍ THƯ =====
    if ($currentRole === 'bithu') {
        $stmt = $pdo->prepare("
        SELECT chidoan_group_id, department_id, course_id, class_id
        FROM bithu_scopes
        WHERE user_id = ?
        LIMIT 1
    ");
        $stmt->execute([$uid]);
        $scope = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$scope) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'NO_SCOPE']);
            exit;
        }
    }

    // ===== GVCN =====
    if ($currentRole === 'gvcn') {
        $stmt = $pdo->prepare("
        SELECT class_id
        FROM gvcn_classes
        WHERE user_id = ?
    ");
        $stmt->execute([$uid]);
        $gvcnClassIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'class_id');

        if (empty($gvcnClassIds)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'NO_CLASS_SCOPE']);
            exit;
        }
    }
    $whereScope = '';
    $params = [$cid];

    // ===== ÁP SCOPE =====
    if ($currentRole === 'bithu') {

        if ((int) $scope['chidoan_group_id'] === 1) {
            // bí thư lớp
            $whereScope .= " AND m.class_id = ? ";
            $params[] = (int) $scope['class_id'];
        } else {
            // bí thư giáo viên
            $whereScope .= " AND m.chidoan_group_id = 2 ";
        }

    } elseif ($currentRole === 'gvcn') {

        $in = implode(',', array_fill(0, count($gvcnClassIds), '?'));
        $whereScope .= " AND m.class_id IN ($in) ";
        $params = array_merge($params, $gvcnClassIds);
    }

    // Đếm tổng
    $countStm = $pdo->prepare("
    SELECT COUNT(*)
    FROM attendance_logs l
    JOIN users u ON u.id = l.user_id
    LEFT JOIN members m ON m.user_id = u.id
    WHERE l.campaign_id = ?
    $whereScope
");
    $countStm->execute($params);

    $totalRows = (int) $countStm->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $limit));

    // Lấy dữ liệu
    $stm = $pdo->prepare("
SELECT 
    l.id AS id,
    COALESCE(m.fullname, u.username) AS fullname,

    CASE
        WHEN m.chidoan_group_id = 1 THEN c.name
        WHEN m.chidoan_group_id = 2 THEN d.name
        ELSE '—'
    END AS class_name,

    l.time,
    l.session

FROM attendance_logs l
JOIN users u ON u.id = l.user_id
LEFT JOIN members m ON m.user_id = u.id
LEFT JOIN classes c ON c.id = m.class_id
LEFT JOIN departments d ON d.id = m.department_id

WHERE l.campaign_id = ?
$whereScope

ORDER BY l.time DESC
LIMIT $limit OFFSET $offset
");

    $stm->execute($params);



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
$allCampaigns = $pdo->query("SELECT id, title, school_year_id FROM campaigns ORDER BY id DESC")->fetchAll();

// Lấy campaign_id hiện tại
$cid = (int) ($_GET['campaign_id'] ?? 0);



// ===========================================
// LẤY THÔNG TIN PHONG TRÀO
// ===========================================
$campaign = null;

if ($cid > 0) {
    $stm = $pdo->prepare("SELECT * FROM campaigns WHERE id=? LIMIT 1");
    $stm->execute([$cid]);
    $campaign = $stm->fetch();
}

?>

<div class="p-6">
    <div class="w-full">

        <!-- DROPDOWN CHỌN PHONG TRÀO -->
        <div class=" flex items-center gap-3 w-full mb-6">
            <label class="text-gray-700 font-medium whitespace-nowrap font-bold">
                Chọn phong trào:
            </label>

            <div class="relative w-full max-w-full">
                <input id="campaignSearch" type="text"
                    class="px-4 py-2 border rounded-lg bg-white shadow-sm w-full max-w-full focus:ring-2 focus:ring-primary"
                    placeholder="Nhập để tìm phong trào..." autocomplete="off"
                    value="<?= htmlspecialchars($campaign['title'] ?? '') ?>" />

                <input type="hidden" id="campaignId" value="<?= (int) $cid ?>">

                <div id="campaignDropdown"
                    class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden hidden">
                    <div id="campaignDropdownList" class="max-h-64 overflow-auto"></div>
                </div>
            </div>

        </div>

        <!-- HEADER -->
        <!-- HÀNG 1: title + nút -->
        <div class="flex items-center justify-between mb-1">

            <h1 id="campaignTitle" class="font-heading text-3xl font-bold">
                <?= htmlspecialchars($campaign['title'] ?? 'Chưa chọn phong trào') ?>
            </h1>

            <?php if (can('attendance', 'print')): ?>
                <button id="exportBtn" class="hidden px-5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 shadow-sm shrink-0 transition-all duration-200">
                    ⬇ Xuất Excel
                </button>
            <?php endif; ?>

        </div>

        <!-- HÀNG 2: subtitle & filter -->
        <div class="flex flex-wrap items-center justify-between mb-4 gap-3">
            <p class="text-gray-500">Danh sách điểm danh</p>
            <div class="flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="text-gray-500 font-medium">Năm học:</span>
                <select id="filterAttendSchoolYear" class="px-3 py-1.5 border rounded-lg bg-white text-sm shadow-sm">
                    <option value="">Tất cả năm học</option>
                </select>
            </div>
        </div>





        <!-- CARD TABLE -->
        <div class="bg-white shadow-lg rounded-2xl overflow-hidden border border-gray-100">
            <div class="overflow-x-auto">

                <table class="min-w-full text-sm text-gray-800">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr class="text-gray-600 uppercase text-xs tracking-wider">
                            <th class="px-5 py-3 text-center w-10">
                                <input type="checkbox" id="selectAllAttend" class="rounded border-gray-300 text-primary focus:ring-primary w-4 h-4 cursor-pointer">
                            </th>
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
        const selectAll = document.getElementById("selectAllAttend");
        if (selectAll) selectAll.checked = false;

        if (!rows || rows.length === 0) {
            body.innerHTML = `
                <tr>
                    <td colspan="5" class="py-8 text-center text-gray-500 italic">
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
                <tr class="hover:bg-gray-50 transition border-b border-gray-100">
                    <td class="px-5 py-3 text-center">
                        <input type="checkbox" class="attend-checkbox rounded border-gray-300 text-primary focus:ring-primary w-4 h-4 cursor-pointer" data-id="${r.id || ''}">
                    </td>
                    <td class="px-5 py-3 font-medium text-gray-900">${r.fullname}</td>
                    <td class="px-5 py-3 text-gray-600">${r.class_name}</td>
                    <td class="px-5 py-3 text-gray-500">${time}</td>
                    <td class="px-5 py-3">
                        <span class="inline-block px-2 py-1 rounded-md bg-blue-50 text-blue-700 text-xs font-medium">
                            ${sessionText[r.session] || "Không xác định"}
                        </span>
                    </td>
                </tr>
            `;
        }).join("");
    }

    function toggleExportBtn() {
        const btn = document.getElementById("exportBtn");
        if (!btn) return;
        const checkedBoxes = document.querySelectorAll(".attend-checkbox:checked");
        if (checkedBoxes.length > 0) {
            btn.classList.remove("hidden");
        } else {
            btn.classList.add("hidden");
        }
    }

    // Toggle tất cả checkbox khi click Chọn tất cả
    document.addEventListener("change", e => {
        if (e.target && (e.target.classList.contains("attend-checkbox") || e.target.id === "selectAllAttend")) {
            if (e.target.id === "selectAllAttend") {
                const checked = e.target.checked;
                const boxes = document.querySelectorAll(".attend-checkbox");
                boxes.forEach(box => box.checked = checked);
            }
            toggleExportBtn();
        }
    });

    // Lắng nghe click nút xuất Excel động
    document.addEventListener("click", e => {
        const btn = e.target.closest("#exportBtn");
        if (!btn) return;
        
        const checkedBoxes = document.querySelectorAll(".attend-checkbox:checked");
        const ids = Array.from(checkedBoxes).map(box => box.dataset.id).filter(id => id);
        if (ids.length === 0) return;
        
        window.location.href = `<?= BASE_URL ?>controllers/attendance_export.php?campaign_id=${CURRENT_CAMPAIGN}&ids=${ids.join(",")}`;
    });

    // ============================
    // COMBO SEARCH PHONG TRÀO (INPUT + DROPDOWN)
    // ============================

    const CAMPAIGNS = <?= json_encode($allCampaigns, JSON_UNESCAPED_UNICODE) ?>;

    const inp = document.getElementById("campaignSearch");
    const hid = document.getElementById("campaignId");
    const dd = document.getElementById("campaignDropdown");
    const ddList = document.getElementById("campaignDropdownList");

    function normText(s) {
        s = (s || "").toString().toLowerCase().trim();
        // bỏ dấu cho dễ tìm
        try {
            return s.normalize("NFD").replace(/\p{Diacritic}/gu, "");
        } catch (e) {
            return s;
        }
    }

    function openDropdown() {
        dd.classList.remove("hidden");
    }
    function closeDropdown() {
        dd.classList.add("hidden");
    }

    function renderDropdown(filterText = "") {
        const f = normText(filterText);
        const sySelect = document.getElementById("filterAttendSchoolYear");
        const syId = sySelect ? Number(sySelect.value || 0) : 0;

        const items = CAMPAIGNS.filter(x => {
            if (syId > 0 && Number(x.school_year_id) !== syId) return false;
            if (!f) return true;
            return normText(x.title).includes(f);
        });

        if (!items.length) {
            ddList.innerHTML = `
                <div class="px-4 py-3 text-sm text-gray-500 italic">
                    Không tìm thấy phong trào phù hợp
                </div>
            `;
            return;
        }

        ddList.innerHTML = items.map(x => `
            <button
                type="button"
                class="w-full text-left px-4 py-3 hover:bg-gray-50 transition flex items-center justify-between gap-3"
                data-id="${x.id}"
                data-title="${String(x.title).replace(/"/g, "&quot;")}"
            >
                <span class="text-gray-800 font-medium truncate">${x.title}</span>
                <span class="text-xs text-gray-400 shrink-0">#${x.id}</span>
            </button>
        `).join("");
    }

    async function setCampaign(id, title, fromUser = true) {
        id = parseInt(id);

        if (!id) return;

        hid.value = id;
        inp.value = title || "";

        // Update URL
        window.history.replaceState({}, "", `?p=attend_list&campaign_id=${id}`);

        // Update TITLE trên header
        document.getElementById("campaignTitle").textContent = title || "Không xác định";

        CURRENT_CAMPAIGN = id;
        closeDropdown();

        // Load lại bảng trang 1
        await loadAttendance(1);
    }

    // Click input => mở list
    inp.addEventListener("focus", () => {
        renderDropdown(""); // ✅ luôn show full list
        openDropdown();
        inp.select();       // ✅ bôi đen text cho dễ gõ đổi
    });

    inp.addEventListener("click", () => {
        renderDropdown(""); // ✅ luôn show full list
        openDropdown();
        inp.select();       // ✅ bôi đen text cho dễ gõ đổi
    });


    // Gõ => lọc
    inp.addEventListener("input", () => {
        renderDropdown(inp.value);
        openDropdown();
    });

    // Click item
    ddList.addEventListener("mousedown", (e) => {
        const btn = e.target.closest("button[data-id]");
        if (!btn) return;
        e.preventDefault(); // tránh blur trước khi chọn
        setCampaign(btn.dataset.id, btn.dataset.title, true);
    });

    // Click ngoài => đóng
    document.addEventListener("click", (e) => {
        const wrap = inp.closest(".relative");
        if (!wrap.contains(e.target)) closeDropdown();
    });

    // Enter nếu đúng tên thì chọn item gần đúng nhất
    inp.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            closeDropdown();
        }

        if (e.key === "Enter") {
            e.preventDefault();
            const val = normText(inp.value);

            // tìm item khớp nhất (contains)
            const found = CAMPAIGNS.find(x => normText(x.title) === val)
                || CAMPAIGNS.find(x => normText(x.title).includes(val))
                || CAMPAIGNS[0];

            if (found) setCampaign(found.id, found.title, true);
        }
    });

    // INIT: set theo campaign hiện tại
    (function initCampaignBox() {
        // Nạp năm học
        async function loadAttendSchoolYears() {
            const selectEl = document.getElementById("filterAttendSchoolYear");
            if (!selectEl) return;
            try {
                const data = await apiFetch("controllers/school_years.php?action=list_active");
                if (data.ok && Array.isArray(data.data)) {
                    data.data.forEach(y => {
                        const opt = document.createElement("option");
                        opt.value = y.id;
                        opt.textContent = y.year_label;
                        selectEl.appendChild(opt);
                    });
                }
            } catch (e) {
                console.error("Failed to load school years", e);
            }
        }
        loadAttendSchoolYears();

        // Lắng nghe đổi năm học để lọc/reset
        document.getElementById("filterAttendSchoolYear")?.addEventListener("change", () => {
            const sySelect = document.getElementById("filterAttendSchoolYear");
            const syId = sySelect ? Number(sySelect.value || 0) : 0;
            
            // Nếu phong trào hiện tại không thuộc năm học được chọn, reset nó
            if (syId > 0 && CURRENT_CAMPAIGN > 0) {
                const cur = CAMPAIGNS.find(x => parseInt(x.id) === CURRENT_CAMPAIGN);
                if (cur && Number(cur.school_year_id) !== syId) {
                    hid.value = "0";
                    inp.value = "";
                    document.getElementById("campaignTitle").textContent = "Chưa chọn phong trào";
                    const exportBtn = document.getElementById("exportBtn");
                    if (exportBtn) exportBtn.classList.add("hidden");
                    CURRENT_CAMPAIGN = 0;
                    renderTable([]);
                    document.getElementById("pagination").innerHTML = "";
                    window.history.replaceState({}, "", `?p=attend_list`);
                }
            }
        });

        const currentId = parseInt(hid.value || "0");

        // ✅ Chỉ auto-load nếu URL có campaign_id
        const current = CAMPAIGNS.find(x => parseInt(x.id) === currentId);
        if (current) {
            setCampaign(current.id, current.title, false);
            return;
        }

        // ✅ Không chọn gì hết khi mới vào
        CURRENT_CAMPAIGN = 0;
        renderTable([]); // hiện "Chưa có ai điểm danh" hoặc bạn đổi message tuỳ
        document.getElementById("pagination").innerHTML = "";
    })();

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
        if (!CURRENT_CAMPAIGN || CURRENT_CAMPAIGN <= 0) {
            renderTable([]);
            document.getElementById("pagination").innerHTML = "";
            return;
        }

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