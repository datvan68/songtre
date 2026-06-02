// Load tab theo URL
document.addEventListener("DOMContentLoaded", () => {


    const tab = new URLSearchParams(window.location.search).get("tab") || "dept";
    tab === "user"
        ? (activateUserTab(), loadUserRanking())
        : (activateDeptTab(), loadDeptRanking());

    initTabs();
});


let deptData = []; // dữ liệu khoa
let userData = []; // dữ liệu cá nhân

function removeVietnamese(str) {
    return str.normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")  // bỏ dấu
        .replace(/đ/g, "d").replace(/Đ/g, "D") // đ → d
        .toLowerCase(); // viết thường
}

// Cập nhật URL không reload
function updateUrl(tab) {
    const url = new URL(window.location.href);
    url.searchParams.set("tab", tab);
    window.history.pushState({}, "", url);
}

// Kích hoạt tab Khoa
function activateDeptTab() {
    tabDept.classList.add("tab-active");
    tabUser.classList.remove("tab-active");
    contentDept.classList.remove("hidden");
    contentUser.classList.add("hidden");
}

// Kích hoạt tab Cá nhân
function activateUserTab() {
    tabUser.classList.add("tab-active");
    tabDept.classList.remove("tab-active");
    contentUser.classList.remove("hidden");
    contentDept.classList.add("hidden");
}

// Xử lý nhấn tab
function initTabs() {
    tabDept.onclick = () => { activateDeptTab(); loadDeptRanking(); updateUrl("dept"); };
    tabUser.onclick = () => { activateUserTab(); loadUserRanking(); updateUrl("user"); };
}

// Load top khoa
async function loadDeptRanking() {
    contentDept.innerHTML = loading();
    const res = await api("controllers/leaderboard.php?action=departments");

    if (res.status === 403) {
        contentDept.innerHTML = error("Bạn không có quyền xem dữ liệu");
        return;
    }

    const json = await res.json();
    if (json.status !== "success") return contentDept.innerHTML = error(json.message);
    deptData = json.data;
    renderDept(deptData);
}

// Load top cá nhân
async function loadUserRanking() {
    contentUser.innerHTML = loading();
    const res = await api("controllers/leaderboard.php?action=list");
    const json = await res.json();   // 👈 BẮT BUỘC

    if (res.status === 403) {
        contentUser.innerHTML = error("Bạn không có quyền xem dữ liệu");
        return;
    }

    if (json.status !== "success") return contentUser.innerHTML = error(json.message);
    userData = json.data;
    renderUsers(userData);
}

// Render bảng khoa
function renderDept(rows) {
    if (!rows.length) return contentDept.innerHTML = empty();
    let html = `
    <table class="w-full text-left">
      <thead>
        <tr class="border-b text-gray-600">
          <th class="py-2 text-center w-[50px]">#</th>
          <th class="py-2 px-3">Khoa</th>
          <th class="py-2 px-3 text-right">Điểm</th>
        </tr>
      </thead><tbody>
    `;
    rows.forEach((d, i) => {
        html += `
        <tr class="border-b hover:bg-gray-50 ${rankColor(i)}">
          <td class="py-2 text-center">${i < 3 ? badge(i) : `<span>${i + 1}</span>`}</td>
          <td class="py-2 px-3">${d.dept_name}</td>
          <td class="py-2 px-3 text-right font-bold">${d.total_score}</td>
        </tr>`;
    });
    html += "</tbody></table>";
    contentDept.innerHTML = html;
}

// Render bảng cá nhân
function renderUsers(rows) {
    if (!rows.length) return contentUser.innerHTML = empty();
    let html = `
    <table class="w-full text-left">
      <thead>
        <tr class="border-b text-gray-600">
          <th class="py-2 text-center w-[50px]">#</th>
          <th class="py-2 px-3">Họ tên</th>
          <th class="py-2 px-3">Lớp</th>
          <th class="py-2 px-3 text-right">Điểm</th>
        </tr>
      </thead><tbody>
    `;
    rows.forEach((u, i) => {
        html += `
        <tr class="border-b hover:bg-gray-50 ${rankColor(i)}">
          <td class="py-2 text-center">${i < 3 ? badge(i) : `<span>${i + 1}</span>`}</td>
          <td class="py-2 px-3">${u.fullname}</td>
          <td class="py-2 px-3">${u.classname ?? "-"}</td>
          <td class="py-2 px-3 text-right font-bold">${u.total_score}</td>
        </tr>`;
    });
    html += "</tbody></table>";
    contentUser.innerHTML = html;
}
// Xử lý chọn filter trong select
filterSelect.addEventListener("change", () => {
    const type = filterSelect.value;
    const tab = new URLSearchParams(window.location.search).get("tab") || "dept";

    // RESET mặc định
    if (!type) {
        tab === "dept"
            ? renderDept(deptData)
            : renderUsers(userData);
        return;
    }

    // Áp filter
    tab === "dept"
        ? applyDeptFilter(type)
        : applyUserFilter(type);
});


// Tìm kiếm real-time
searchBox.addEventListener("input", () => {
    const key = removeVietnamese(searchBox.value.trim());
    const tab = new URLSearchParams(window.location.search).get("tab") || "dept";

    if (tab === "dept") {
        const filtered = deptData.filter(d =>
            removeVietnamese(d.dept_name).includes(key)
        );
        renderDept(filtered);
    } else {
        const filtered = userData.filter(u =>
            removeVietnamese(u.fullname).includes(key) ||
            (u.classname && removeVietnamese(u.classname).includes(key))
        );
        renderUsers(filtered);
    }
});


// Lọc Khoa
function applyDeptFilter(type) {
    let rows = [...deptData];
    if (type === "score_desc") rows.sort((a, b) => b.total_score - a.total_score);
    if (type === "score_asc") rows.sort((a, b) => a.total_score - b.total_score);
    if (type === "top10") rows = rows.slice(0, 10);
    if (type === "top20") rows = rows.slice(0, 20);
    renderDept(rows);
}

// Lọc Cá nhân
function applyUserFilter(type) {
    let rows = [...userData];
    if (type === "score_desc") rows.sort((a, b) => b.total_score - a.total_score);
    if (type === "score_asc") rows.sort((a, b) => a.total_score - b.total_score);
    if (type === "top10") rows = rows.slice(0, 10);
    if (type === "top20") rows = rows.slice(0, 20);
    renderUsers(rows);
}

// Icon top
function badge(i) {
    const icons = ["🥇", "🥈", "🥉"];
    return `<span class="text-2xl leading-none">${icons[i]}</span>`;
}

// Màu top
function rankColor(i) {
    return i === 0 ? "bg-yellow-100"
        : i === 1 ? "bg-[#E5E7EB]"
            : i === 2 ? "bg-orange-100"
                : "";
}

// UI phụ
function loading() { return `<div class="text-center py-4 text-gray-500">Đang tải...</div>`; }
function empty() { return `<div class="text-center py-4 text-gray-500">Không có dữ liệu</div>`; }
function error(msg) { return `<div class="text-center py-4 text-red-500">${msg}</div>`; }
