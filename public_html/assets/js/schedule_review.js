

document.addEventListener("DOMContentLoaded", () => {

  const tabCal = document.getElementById("tabCalendar");
  const tabRev = document.getElementById("tabReview");

  tabCal.onclick = () => switchTab("calendar");
  tabRev.onclick = () => switchTab("review");

  const params = new URLSearchParams(window.location.search);
  const current = params.get("tab") || "calendar";
  switchTab(current);
});


// =======================================================
// CHUYỂN TAB
// =======================================================
function switchTab(tab) {

  const tabCal = document.getElementById("tabCalendar");
  const tabRev = document.getElementById("tabReview");

  const panelCal = document.getElementById("panelCalendar");
  const panelRev = document.getElementById("panelReview");

  // reset
  tabCal.classList.remove("active");
  tabRev.classList.remove("active");

  if (tab === "review") {
    tabRev.classList.add("active");

    panelCal.classList.add("hidden");
    panelRev.classList.remove("hidden");

    loadReviewData();
  } else {
    tabCal.classList.add("active");

    panelCal.classList.remove("hidden");
    panelRev.classList.add("hidden");

    // Fix FullCalendar resize
    setTimeout(() => {
      window.fullCalendarObj?.updateSize();
    }, 10);
  }

  const newUrl = window.BASE_URL + `index.php?p=schedule&tab=${tab}`;
  history.replaceState({}, "", newUrl);
}



// =======================================================
// BADGE TRẠNG THÁI (TIẾNG VIỆT + MÀU ĐẸP)
// =======================================================
function statusBadge(status) {
  const map = {
    approved: "bg-green-100 text-green-700",
    pending: "bg-yellow-100 text-yellow-700",
    rejected: "bg-red-100 text-red-700"
  };

  const label = {
    approved: "Đã duyệt",
    pending: "Đang chờ duyệt",
    rejected: "Từ chối"
  };

  return `
    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium ${map[status] || "bg-gray-100"}">
      ${label[status] || status}
    </span>
  `;
}





// =======================================================
// FORMAT NGÀY
// =======================================================
function formatVNDateTime(str) {
  if (!str) return "";
  const d = new Date(str.replace(" ", "T"));

  const day = String(d.getDate()).padStart(2, "0");
  const month = String(d.getMonth() + 1).padStart(2, "0");
  const year = d.getFullYear();

  const h = String(d.getHours()).padStart(2, "0");
  const m = String(d.getMinutes()).padStart(2, "0");

  return `${day}/${month}/${year} ${h}:${m}`;
}


// ===========================================================
// LOAD DANH SÁCH PENDING (ADMIN) / MY_EVENTS (USER)
// ===========================================================
async function loadReviewData(page = 1) {
  const box = document.getElementById("reviewList");
  const mobileBox = document.getElementById("reviewListMobile");
  const pager = document.getElementById("reviewPager");

  if (!box) return;

  const isReviewer = CAN.review === true;
  const canView = CAN.view === true;

  if (!isReviewer && !canView) {
    box.innerHTML = `
      <tr><td colspan="6" class="text-center text-red-500">
        Bạn không có quyền xem dữ liệu
      </td></tr>`;
    return;
  }

  const colspan = isReviewer ? 7 : 6;
  const action = isReviewer ? "pending" : "my_events";

  box.innerHTML = `
    <tr><td colspan="${colspan}" class="p-3 text-center">Đang tải...</td></tr>`;

  const res = await api(
    `controllers/schedule.php?action=${action}&page=${page}`
  );
  const json = await res.json();

  const rows = json.data || [];
  const totalPages = json.totalPages || 1;

  if (!rows.length) {
    box.innerHTML = `
      <tr>
        <td colspan="${colspan}" class="p-3 text-center text-gray-500">
          Không có dữ liệu
        </td>
      </tr>
    `;
    if (pager) pager.innerHTML = "";
    return;
  }

  box.innerHTML = "";
  mobileBox.innerHTML = "";

  rows.forEach(ev => {
    const tr = document.createElement("tr");
    tr.dataset.id = ev.id;

    tr.innerHTML = `
<td class="px-3 py-2 break-words whitespace-normal" data-label="Tiêu đề">
  ${ev.title}
</td>

<td class="px-3 py-2 whitespace-normal break-words" data-label="Người tạo">
  ${ev.create_by_name || ""}
</td>

<td class="px-2 py-2 whitespace-normal break-words text-left text-xs font-semibold text-gray-600 whitespace-nowrap"
    data-label="Lớp">
  ${ev.class_name || ""}
</td>


<td class="px-3 py-2 whitespace-normal break-words" data-label="Thời gian bắt đầu">
  ${formatVNDateTime(ev.start_date)}
</td>

<td class="px-3 py-2 whitespace-normal break-words" data-label="Thời gian kết thúc">
  ${formatVNDateTime(ev.end_date)}
</td>

<td class="px-3 py-2 text-left" data-label="Trạng thái">
  ${statusBadge(ev.status)}
</td>

${isReviewer ? `
<td class="px-3 py-2 text-right" data-label="Thao tác">
  ${ev.status === "pending" ? `
    <div class="flex justify-end gap-2">
      <button class="approve bg-green-600 text-white px-3 py-1 rounded-lg text-xs whitespace-nowrap">
        Duyệt
      </button>
      <button class="reject bg-red-600 text-white px-3 py-1 rounded-lg text-xs whitespace-nowrap">
        Từ chối
      </button>
    </div>
  ` : ""}
</td>
` : ""}
`;


    box.appendChild(tr);


    const card = document.createElement("div");
    card.dataset.id = ev.id;
    card.className = "bg-white rounded-xl border p-4 shadow-sm";

    card.innerHTML = `
  <div class="space-y-2 text-sm">

    <div class="flex justify-between">
      <span class="text-gray-500">Phong trào</span>
      <span class="font-medium text-right">${ev.title}</span>
    </div>

    <div class="flex justify-between">
      <span class="text-gray-500">Người tạo</span>
      <span class="font-medium text-right">${ev.create_by_name || ""}</span>
    </div>

    <div class="flex justify-between">
      <span class="text-gray-500">Lớp</span>
      <span class="font-medium text-right">${ev.class_name || ""}</span>
    </div>

    <div class="flex justify-between">
      <span class="text-gray-500">Bắt đầu</span>
      <span class="text-right">${formatVNDateTime(ev.start_date)}</span>
    </div>

    <div class="flex justify-between">
      <span class="text-gray-500">Kết thúc</span>
      <span class="text-right">${formatVNDateTime(ev.end_date)}</span>
    </div>

    <div class="flex justify-between items-center pt-1">
      <span class="text-gray-500">Trạng thái</span>
      ${statusBadge(ev.status)}
    </div>

    ${isReviewer && ev.status === "pending"
        ? `
      <div class="flex justify-end gap-2 pt-3">
        <button class="approve bg-green-600 text-white px-3 py-1 rounded-lg text-xs">
          Duyệt
        </button>
        <button class="reject bg-red-600 text-white px-3 py-1 rounded-lg text-xs">
          Từ chối
        </button>
      </div>
      `
        : ""
      }

  </div>
`;

    mobileBox.appendChild(card);

  });

  // ✅ RENDER PHÂN TRANG
  renderReviewPager(page, totalPages);
}


function openConfirmReview(id, status) {
  const isApprove = status === "approved";

  const title = isApprove ? "Xác nhận duyệt sự kiện" : "Xác nhận từ chối";
  const color = isApprove ? "bg-green-600 hover:bg-green-700" : "bg-red-600 hover:bg-red-700";
  const text = isApprove
    ? "Bạn có chắc chắn muốn <b class='text-green-600'>duyệt</b> sự kiện này không?"
    : "Bạn có chắc chắn muốn <b class='text-red-600'>từ chối</b> sự kiện này không?";

  const wrap = document.createElement("div");
  wrap.innerHTML = `
    <div class="space-y-4">
      <p class="text-gray-700 leading-relaxed">${text}</p>

      <div class="flex justify-end gap-3 pt-2">
        <button
          class="px-4 py-2 border rounded-lg hover:bg-gray-50"
          onclick="closeModal()">
          Hủy
        </button>

        <button
          id="confirmReviewBtn"
          type="button"
          data-primary
          class="px-4 py-2 text-white rounded-lg ${color}">
          Xác nhận
        </button>
      </div>
    </div>
  `;

  modal(wrap, title, "medium");

  // 🔥 GẮN EVENT VÀO DOM THẬT (KHÔNG PHẢI wrap)
  setTimeout(() => {
    const btn = document.querySelector("#confirmReviewBtn");
    if (!btn) {
      console.error("Không tìm thấy nút confirm");
      return;
    }

    btn.onclick = async () => {
      closeModal();
      await changeStatus(id, status);
    };
  }, 0);
}



// ==========================================================
// THAY ĐỔI TRẠNG THÁI (ADMIN)
// ==========================================================
async function changeStatus(id, status) {
  if (!CAN.review) {
    notify("Bạn không có quyền duyệt", "error");
    return;
  }
  const res = await api("controllers/schedule.php?action=review", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `id=${id}&status=${status}`
  });

  const data = await res.json();
  console.log("review response:", data);



  if (data.success) {
    notify("OK", "Đã cập nhật trạng thái");
    loadReviewData();
    window.fullCalendarObj?.refetchEvents();
  } else {
    alert("Lỗi duyệt: " + JSON.stringify(data));
  }
}

async function updateSchedulePendingCount() {
  if (!CAN.view) return;   // ❗ chặn từ đầu


  const badge = document.getElementById("schedulePendingBadge");
  if (!badge) return;

  try {
    if (!CAN.review) return;
    const res = await api("controllers/schedule.php?action=pending_count");
    const json = await res.json();

    if (json.success && json.count > 0) {
      badge.textContent = json.count;
      badge.classList.remove("hidden");
    } else {
      badge.classList.add("hidden");
    }
  } catch (err) {
    console.error("Không thể tải pending schedule:", err);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  if (document.getElementById("schedulePendingBadge")) {
    updateSchedulePendingCount();
    setInterval(updateSchedulePendingCount, 30000);
  }
});
function renderReviewPager(page, totalPages) {
  const box = document.getElementById("reviewPager");
  if (!box || totalPages <= 1) {
    box.innerHTML = "";
    return;
  }

  const prev = Math.max(1, page - 1);
  const next = Math.min(totalPages, page + 1);

  box.innerHTML = `
    <div class="flex items-center gap-2 select-none">

      <button
        class="px-3 py-1 border rounded-lg ${page === 1 ? "opacity-50 pointer-events-none" : ""}"
        onclick="loadReviewData(1)">
        &laquo;
      </button>

      <button
        class="px-3 py-1 border rounded-lg ${page === 1 ? "opacity-50 pointer-events-none" : ""}"
        onclick="loadReviewData(${prev})">
        &lsaquo;
      </button>

      <div class="flex items-center gap-1 text-sm">
        <input
          type="number"
          min="1"
          max="${totalPages}"
          value="${page}"
          class="w-12 px-2 py-1 border rounded-lg text-center"
          onchange="loadReviewData(this.value)">
        <span class="text-gray-500">/ ${totalPages}</span>
      </div>

      <button
        class="px-3 py-1 border rounded-lg ${page === totalPages ? "opacity-50 pointer-events-none" : ""}"
        onclick="loadReviewData(${next})">
        &rsaquo;
      </button>

      <button
        class="px-3 py-1 border rounded-lg ${page === totalPages ? "opacity-50 pointer-events-none" : ""}"
        onclick="loadReviewData(${totalPages})">
        &raquo;
      </button>

    </div>
  `;
}
document.addEventListener("click", (e) => {
  const btn = e.target.closest(".approve, .reject");
  if (!btn) return;

  const row = btn.closest("[data-id]");
  const id = row?.dataset?.id;
  if (!id) return;

  const status = btn.classList.contains("approve")
    ? "approved"
    : "rejected";

  // ✅ MỞ MODAL XÁC NHẬN
  openConfirmReview(id, status);
});


