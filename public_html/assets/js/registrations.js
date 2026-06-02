
function formatDateVN(dateStr) {
  if (!dateStr) return "-";

  const d = new Date(dateStr);
  if (isNaN(d)) return "-";

  const pad = n => String(n).padStart(2, "0");

  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

document.addEventListener("DOMContentLoaded", () => {


  // Nếu có badge (chỉ admin có)
  if (document.getElementById("pendingBadge")) {
    updatePendingCount(); // load ban đầu
    setInterval(updatePendingCount, 30000); // cập nhật mỗi 30s
  }

});

// === CẬP NHẬT SỐ LƯỢNG CHƯA ĐÁNH GIÁ ===
async function updatePendingCount() {
  const badge = document.getElementById("pendingBadge");
  if (!badge) return;

  try {
    const res = await api("controllers/registrations.php?action=pending_count");
    const text = await res.text();

    if (!text) return; // 🔒 chống rỗng

    const data = JSON.parse(text);

    if (data.success && data.count > 0) {
      badge.textContent = data.count;
      badge.classList.remove("hidden");
    } else {
      badge.classList.add("hidden");
    }

  } catch (err) {
    console.error("Không thể tải số lượng đăng ký chưa đánh giá:", err);
  }
}

function openCancelConfirm(id) {
  const html = `
    <form id="cancelForm" class="space-y-4">
      <input type="hidden" name="id" value="${id}">

      <p class="text-gray-700">
        Bạn có chắc chắn muốn <b class="text-red-600">hủy đăng ký</b> này không?
      </p>

      <p class="text-sm text-gray-500">
        Hành động này không thể hoàn tác.
      </p>

      <div class="flex justify-end gap-3 pt-2">
        <button type="button"
          onclick="closeModal()"
          class="px-4 py-2 border rounded-lg hover:bg-gray-50">
          Không
        </button>

        <button type="submit"
          class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
          Xác nhận hủy
        </button>
      </div>
    </form>
  `;

  const wrap = document.createElement("div");
  wrap.innerHTML = html.trim();
  modal(wrap.firstChild, "Xác nhận hủy đăng ký");

  const form = document.getElementById("cancelForm");
  form.addEventListener("submit", async e => {
    e.preventDefault();

    try {
      const fd = new FormData();
      fd.append("action", "cancel");
      fd.append("id", id);

      const res = await api("controllers/registrations.php", {
        method: "POST",
        body: fd
      });

      const json = await res.json();

      if (json.success) {
        toast("🗑️ Đã hủy đăng ký", "success");
        closeModal();

        await updatePendingCount();
        loadRegistrations(
          document.querySelector("#pagerRegistered input")?.value || 1
        );
      } else {
        toast(json.error || "Không thể hủy đăng ký", "error");
      }

    } catch (err) {
      toast("Không thể kết nối máy chủ", "error");
    }
  });
}

// === FORM ĐÁNH GIÁ HOÀN THÀNH ===
function openReviewForm(id, status = "", note = "") {
  // approved = đang tham gia -> coi như chưa đánh giá
  const normalizedStatus = (status === "approved") ? "" : (status || "");
  const isEdit = !!normalizedStatus;

  const formHtml = `
    <form id="reviewForm" class="space-y-3">
      <input type="hidden" name="action" value="review">
      <input type="hidden" name="id" value="${id}">

      <div>
        <label class="block text-sm font-medium mb-1">Kết quả hoàn thành</label>
        <select name="status" class="w-full border rounded-lg px-3 py-2 bg-gray-50" required>
          <option value="" disabled ${!normalizedStatus ? "selected" : ""}>
            -- Chọn kết quả đánh giá --
          </option>

          <option value="excellent" ${normalizedStatus === "excellent" ? "selected" : ""}>
            🏅 Hoàn thành xuất sắc (+7 điểm)
          </option>

          <option value="completed" ${normalizedStatus === "completed" || normalizedStatus === "good" ? "selected" : ""}>
            ✔️ Hoàn thành (+5 điểm)
          </option>

          <option value="incomplete" ${normalizedStatus === "incomplete" ? "selected" : ""}>
            ❌ Không hoàn thành (0 điểm)
          </option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Ghi chú</label>
        <textarea name="note" rows="3"
          class="w-full border rounded-lg px-3 py-2"
          placeholder="Ghi chú thêm">${note || ""}</textarea>
      </div>

      <div class="flex justify-end gap-2 mt-4">
        <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg">
          Hủy
        </button>
        <button type="submit"
          class="px-4 py-2 ${isEdit ? "bg-amber-500" : "bg-primary"} text-white rounded-lg">
          Lưu đánh giá
        </button>
      </div>
    </form>
  `;

  const temp = document.createElement("div");
  temp.innerHTML = formHtml.trim();
  modal(
    temp.firstChild,
    isEdit ? "Sửa đánh giá kết quả" : "Đánh giá kết quả phong trào"
  );

  const form = document.getElementById("reviewForm");
  form.addEventListener("submit", async e => {
    e.preventDefault();

    const fd = new FormData(form);

    try {
      const res = await api("controllers/registrations.php", {
        method: "POST",
        body: fd
      });
      const json = await res.json();

      if (json.success) {
        toast(
          isEdit ? "✏️ Đã cập nhật đánh giá" : "✅ Đã đánh giá thành công",
          "success"
        );
        closeModal();
        await updatePendingCount();
        loadRegistrations(
          document.querySelector("#pagerRegistered input")?.value || 1
        );
      } else {
        toast(json.error || "❌ Lỗi khi lưu đánh giá", "error");
      }
    } catch {
      toast("Không thể kết nối đến máy chủ", "error");
    }
  });
}


// === FORM CHẤM ĐIỂM ===
function openScoreForm(id, currentScore = 0, addedBefore = 0) {

  const formHtml = `
    <form id="scoreForm" class="space-y-3">
      <input type="hidden" name="action" value="score_add">
      <input type="hidden" name="id" value="${id}">

      <div>
        <label class="block text-sm font-medium mb-1">Điểm hiện tại</label>
        <input type="number"
          id="currentScore"
          class="w-full border rounded-lg px-3 py-2 bg-gray-100"
          value="${currentScore}"
          readonly>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Điểm cộng thêm</label>
        <input type="number"
          name="score_add"
          id="scoreAdd"
          class="w-full border rounded-lg px-3 py-2"
          placeholder="Nhập điểm cộng"
          value="${addedBefore}"
          required>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Tổng điểm mới</label>
        <input type="number"
          id="newScore"
          class="w-full border rounded-lg px-3 py-2 bg-green-50 font-semibold"
          value="${currentScore}"
          readonly>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Ghi chú</label>
        <textarea name="note" rows="3"
          class="w-full border rounded-lg px-3 py-2"
          placeholder="Ghi chú (nếu có)"></textarea>
      </div>

      <div class="flex justify-end gap-2 mt-4">
        <button type="button"
          onclick="closeModal()"
          class="px-4 py-2 border rounded-lg">
          Hủy
        </button>
        <button type="submit"
          class="px-4 py-2 bg-secondary text-white rounded-lg">
          Cập nhật điểm
        </button>
      </div>
    </form>
  `;

  const temp = document.createElement("div");
  temp.innerHTML = formHtml.trim();
  modal(temp.firstChild, "Cộng điểm phong trào");

  // 🔢 TÍNH TỔNG ĐIỂM REALTIME
  const scoreAddInput = document.getElementById("scoreAdd");
  const newScoreInput = document.getElementById("newScore");

  scoreAddInput.addEventListener("input", () => {
    const add = parseInt(scoreAddInput.value) || 0;
    newScoreInput.value = (currentScore - addedBefore) + add;
  });

  // SUBMIT
  const form = document.getElementById("scoreForm");
  form.addEventListener("submit", async e => {
    e.preventDefault();

    const fd = new FormData(form);

    try {
      const res = await api("controllers/registrations.php", {
        method: "POST",
        body: fd
      });

      const json = await res.json();

      if (json.success) {
        toast("➕ Đã cập nhật điểm", "success");
        closeModal();
        loadRegistrations(
          document.querySelector("#pagerRegistered input")?.value || 1
        );
      } else {
        toast(json.error || "❌ Không thể cập nhật điểm", "error");
      }
    } catch {
      toast("❌ Lỗi kết nối máy chủ", "error");
    }
  });
}

/* =====================================================
   PAGINATION – TAB 2
===================================================== */
const REG_API = "controllers/registrations.php";
let REG_PER_PAGE = parseInt(
  localStorage.getItem("reg_per_page") || "10",
  10
);

async function loadRegistrations(page = 1) {
  const campaignId =
    document.getElementById("filterCampaign")?.value || 0;
  const schoolYear =
    document.getElementById("filterRegSchoolYear")?.value || "";

  const res = await fetch(
    `${REG_API}?action=list&page=${page}&per_page=${REG_PER_PAGE}&campaign_id=${campaignId}&school_year=${schoolYear}`,
    { credentials: "include" }
  );

  const json = await res.json();
  if (!json.success) {
    toast(json.error || "Lỗi tải danh sách đăng ký", "error");
    return;
  }

  const tbody = document.getElementById("tbodyRegistered");
  tbody.innerHTML = "";

  const checkAll = document.getElementById("checkAllRegs");
  if (checkAll) checkAll.checked = false;
  const exportBtn = document.getElementById("btnExportRegistrations");
  if (exportBtn) exportBtn.classList.add("hidden");

  const esc = (s = "") =>
    String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");

  json.data.forEach(r => {
    const canAction = Number(r.can_action || 0) === 1;

    const qrOk = Number(r.qr_ok || 0) === 1;

    // chỉ coi là "chưa quét QR" khi đang approved mà chưa có QR ok
    const notQrYet = (r.status === "approved" && !qrOk);

    // quyền hiển thị cột thao tác
    const hasAnyActionPerm = (window.CAN_REG_REVIEW || window.CAN_REG_SCORE || window.CAN_REG_CANCEL);

    // note hiển thị
    const noteHtml = notQrYet
      ? `<span class="inline-block text-xs font-medium px-2 py-1 rounded-full bg-rose-50 text-rose-700">Chưa quét QR</span>
         ${r.note ? `<div class="mt-1 text-gray-700 text-sm break-all">${esc(r.note)}</div>` : ``}`
      : (r.note ? esc(r.note) : "");

    let statusText = "Không xác định";
    let statusColor = "bg-gray-50 text-gray-500";

    switch (r.status) {
      case "approved":
        statusText = "Đang tham gia";
        statusColor = "bg-gray-100 text-gray-700";
        break;

      case "excellent":
        statusText = "Hoàn thành xuất sắc";
        statusColor = "bg-green-100 text-green-700";
        break;

      case "good":
        statusText = "Hoàn thành tốt";
        statusColor = "bg-blue-100 text-blue-700";
        break;

      case "completed":
        statusText = "Hoàn thành";
        statusColor = "bg-indigo-100 text-indigo-700";
        break;

      case "incomplete":
        statusText = "Không hoàn thành";
        statusColor = "bg-yellow-100 text-yellow-800";
        break;

      case "cancelled":
        statusText = "Hủy";
        statusColor = "bg-red-100 text-red-700";
        break;

      default:
        statusText = "Không xác định";
        statusColor = "bg-gray-100 text-gray-700";
    }
    const reviewedStatuses = new Set(["excellent", "good", "completed", "incomplete"]);
    const isReviewed = reviewedStatuses.has(r.status);

    // ==================== PHẦN SỬA Ở ĐÂY ====================
    let actionHtml = '';

    if (notQrYet) {
      // Nút Hủy khi "Chưa quét QR" - style đồng đều với các nút khác
      actionHtml = `
        <div class="flex justify-end">
          <button 
            class="px-4 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded-lg transition min-w-[30px] js-admin-cancel"
            data-id="${r.id}">
            Hủy
          </button>
        </div>`;
    }
    else if (!canAction) {
      actionHtml = `<span class="text-gray-400 text-sm">-</span>`;
    }
    else {
      actionHtml = `
        <div class="flex justify-end gap-2 items-center">
          ${window.CAN_REG_REVIEW ? `
            <button 
              class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-xs font-medium rounded-lg js-review"
              data-id="${r.id}"
              data-status="${r.status || ''}"
              data-note="${encodeURIComponent(r.note || '')}">
              ${isReviewed ? "Sửa" : "Đánh giá"}
            </button>
          ` : ``}

          ${window.CAN_REG_SCORE ? `
            <button 
              class="px-3 py-1.5 bg-secondary hover:bg-blue-700 text-white text-xs font-medium rounded-lg js-score"
              data-id="${r.id}"
              data-score="${r.score || 0}"
              data-added="${r.added_score || 0}">
              + Điểm
            </button>
          ` : ``}

          ${window.CAN_REG_CANCEL ? `
            <button 
              class="px-4 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs font-medium rounded-lg transition min-w-[30px] js-admin-cancel"
              data-id="${r.id}">
              Hủy
            </button>
          ` : ``}
        </div>`;
    }
    // =======================================================

    tbody.insertAdjacentHTML("beforeend", `
      <tr class="border-t hover:bg-gray-50">
        <td data-label="Chọn" class="px-3 py-2 text-center w-[44px]">
          <input type="checkbox" class="reg-check align-middle cursor-pointer w-4 h-4" value="${r.id}">
        </td>

        <td data-label="Phong trào" class="px-3 py-2 w-[220px]">
          ${r.ctitle}
        </td>

        <td data-label="Lớp" class="px-3 py-2">
          ${r.class_name
        ? r.class_name
        : (r.dept_name
          ? (r.dept_type === 'phong'
            ? `Phòng ${r.dept_name}`
            : `Khoa ${r.dept_name}`)
          : '-'
        )
      }
        </td>

        <td data-label="Họ tên" class="px-3 py-2 whitespace-nowrap font-medium text-gray-800">
          ${r.fullname || '-'}
        </td>

        <td data-label="Ngày đăng ký" class="px-3 py-2 text-left text-subtext">
          ${formatDateVN(r.registered_at)}
        </td>

        <td data-label="Trạng thái" class="px-3 py-2">
          <span class="inline-block text-xs font-medium px-2 py-1 rounded-full ${statusColor}">
            ${statusText}
          </span>
        </td>

        <td data-label="Số điện thoại" class="px-3 py-2 text-center">
          ${r.phone || '-'}
        </td>

        <td data-label="Ghi chú" class="px-3 py-2 text-gray-700 text-sm break-all">
          ${noteHtml || ""}
        </td>

        ${hasAnyActionPerm ? `
          <td data-label="Thao tác" class="px-3 py-2 text-right whitespace-nowrap">
            ${actionHtml}
          </td>
        ` : ``}
      </tr>
    `);
  });

  if (json.data && json.data.length > 0) {
    renderPagerRegistered(json.page, json.totalPages);
  } else {
    document.getElementById("pagerRegistered").innerHTML = "";
  }
}

function renderPagerRegistered(page, totalPages) {
  const wrap = document.getElementById("pagerRegistered");

  // Không có trang nào → ẩn
  if (!totalPages || totalPages < 1) {
    wrap.innerHTML = "";
    return;
  }

  const prev = Math.max(1, page - 1);
  const next = Math.min(totalPages, page + 1);

  wrap.innerHTML = `
  <div class="flex items-center gap-2 justify-center select-none">

    <!-- FIRST -->
    <button
      class="px-3 py-1 border rounded-lg ${page === 1 ? "opacity-50 pointer-events-none" : ""}"
      onclick="loadRegistrations(1)"
      title="Trang đầu">
      &laquo;
    </button>

    <!-- PREV -->
    <button
      class="px-3 py-1 border rounded-lg ${page === 1 ? "opacity-50 pointer-events-none" : ""}"
      onclick="loadRegistrations(${prev})"
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
        onchange="loadRegistrations(this.value)"
      />
      <span class="text-gray-500">/ ${totalPages}</span>
    </div>

    <!-- NEXT -->
    <button
      class="px-3 py-1 border rounded-lg ${page === totalPages ? "opacity-50 pointer-events-none" : ""}"
      onclick="loadRegistrations(${next})"
      title="Trang sau">
      &rsaquo;
    </button>

    <!-- LAST -->
    <button
      class="px-3 py-1 border rounded-lg ${page === totalPages ? "opacity-50 pointer-events-none" : ""}"
      onclick="loadRegistrations(${totalPages})"
      title="Trang cuối">
      &raquo;
    </button>

  </div>
`;

}


/* AUTO LOAD KHI MỞ TAB 2 */
document.addEventListener("DOMContentLoaded", () => {

  const perPageSelect = document.getElementById("regPerPage");
  if (perPageSelect) {
    perPageSelect.value = REG_PER_PAGE;
  }

  // Populate school year filter
  const filterRegYear = document.getElementById("filterRegSchoolYear");
  if (filterRegYear && typeof loadFilterSchoolYears === "function") {
    loadFilterSchoolYears(filterRegYear);
  }

  if (document.getElementById("tbodyRegistered")) {
    loadRegistrations(1);
  }


  const filter = document.getElementById("filterCampaign");
  if (filter) {
    filter.addEventListener("change", () => loadRegistrations(1));
  }

  if (filterRegYear) {
    filterRegYear.addEventListener("change", () => loadRegistrations(1));
  }
});

document.addEventListener("click", async e => {

  // ĐÁNH GIÁ
  const reviewBtn = e.target.closest(".js-review");
  if (reviewBtn) {
    openReviewForm(
      reviewBtn.dataset.id,
      reviewBtn.dataset.status,
      decodeURIComponent(reviewBtn.dataset.note || "")
    ); return;
  }

  // CHẤM ĐIỂM
  const scoreBtn = e.target.closest(".js-score");
  if (scoreBtn) {
    openScoreForm(
      scoreBtn.dataset.id,
      Number(scoreBtn.dataset.score || 0),
      Number(scoreBtn.dataset.added || 0)
    ); return;
  }

  // HỦY ĐĂNG KÝ
  const cancelBtn = e.target.closest(".js-admin-cancel");
  if (cancelBtn) {
    openCancelConfirm(cancelBtn.dataset.id);
    return;
  }




});
function toggleRegExportBtn() {
  const btn = document.getElementById("btnExportRegistrations");
  if (!btn) return;
  const checked = document.querySelectorAll(".reg-check:checked");
  if (checked.length > 0) {
    btn.classList.remove("hidden");
  } else {
    btn.classList.add("hidden");
  }
}

document.getElementById("btnExportRegistrations")?.addEventListener("click", () => {
  const checked = [...document.querySelectorAll(".reg-check:checked")].map(cb => cb.value);
  if (checked.length === 0) return;

  const url = `controllers/registrations.php?action=export&ids=${checked.join(",")}`;

  // tải file
  window.location.href = url;
});
document.addEventListener("change", e => {
  if (e.target && (e.target.id === "checkAllRegs" || e.target.classList.contains("reg-check"))) {
    if (e.target.id === "checkAllRegs") {
      document.querySelectorAll(".reg-check").forEach(cb => {
        cb.checked = e.target.checked;
      });
    }
    toggleRegExportBtn();
  }
});
document.getElementById("btnBulkReview")?.addEventListener("click", () => {
  const ids = [...document.querySelectorAll(".reg-check:checked")]
    .map(cb => cb.value);

  if (ids.length === 0) {
    toast("⚠️ Vui lòng chọn ít nhất 1 đăng ký", "warning");
    return;
  }

  const html = `
    <form id="bulkReviewForm" class="space-y-3">
      <input type="hidden" name="action" value="bulk_review">

      <div>
        <label class="block text-sm font-medium mb-1">Kết quả đánh giá</label>
        <select name="status" class="w-full border rounded-lg px-3 py-2" required>
          <option value="">-- Chọn --</option>
          <option value="excellent">🏅 Hoàn thành xuất sắc</option>
          <option value="good">✅ Hoàn thành tốt</option>
          <option value="completed">✔️ Hoàn thành</option>
          <option value="incomplete">⚠️ Chưa hoàn thành</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Ghi chú chung</label>
        <textarea name="note" rows="3"
          class="w-full border rounded-lg px-3 py-2"></textarea>
      </div>

      <div class="flex justify-end gap-2 pt-2">
        <button type="button" onclick="closeModal()"
          class="px-4 py-2 border rounded-lg">Hủy</button>
        <button type="submit"
          class="px-4 py-2 bg-indigo-600 text-white rounded-lg">
          Áp dụng (${ids.length})
        </button>
      </div>
    </form>
  `;

  const wrap = document.createElement("div");
  wrap.innerHTML = html.trim();
  modal(wrap.firstChild, "Đánh giá hàng loạt");
});
document.addEventListener("submit", async e => {
  if (e.target.id !== "bulkReviewForm") return;
  e.preventDefault();

  const fd = new FormData(e.target);

  // 🔥 XÓA ids CŨ
  fd.delete("ids");

  // 🔥 THÊM ids[] ĐÚNG KIỂU PHP
  const ids = [...document.querySelectorAll(".reg-check:checked")]
    .map(cb => cb.value);

  ids.forEach(id => fd.append("ids[]", id));

  try {
    const res = await api("controllers/registrations.php", {
      method: "POST",
      body: fd
    });

    const json = await res.json();

    if (json.success) {
      toast("✅ Đã đánh giá hàng loạt", "success");
      closeModal();
      await updatePendingCount();
      loadRegistrations(
        document.querySelector("#pagerRegistered input")?.value || 1
      );
    } else {
      toast(json.error || "❌ Không thể đánh giá", "error");
    }
  } catch {
    toast("❌ Lỗi kết nối máy chủ", "error");
  }
});

document.getElementById("regPerPage")?.addEventListener("change", e => {
  REG_PER_PAGE = parseInt(e.target.value, 10) || 10;
  localStorage.setItem("reg_per_page", REG_PER_PAGE);
  loadRegistrations(1);
});

