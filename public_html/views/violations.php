<?php
// public_html/views/violations.php
require __DIR__ . '/../config/db.php';

if (!can('violations', 'view')) {
  echo "<section class='p-6 text-red-500 font-bold'>403 - Bạn không có quyền truy cập chức năng này</section>";
  exit;
}

$canCreateOrUpdate = can('violations', 'create') || can('violations', 'update');
$canDelete = can('violations', 'delete');
?>

<div class="flex">
  <main class="flex-1 bg-bg min-h-screen p-6">
    <div class="w-full">
      <!-- ===== HEADER ===== -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="font-heading text-3xl font-bold text-gray-900">Kỷ luật & Vi phạm</h1>
      </div>

      <!-- ===== CONTENT GRID ===== -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- CỘT TRÁI: TRA CỨU & GHI NHẬN VI PHẠM -->
        <div class="space-y-6">
          
          <!-- CARD 1: TRA CỨU MSSV -->
          <div class="bg-white rounded-2xl shadow-card border border-gray-100 p-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
              <i data-lucide="search" class="w-5 h-5 text-primary"></i>
              Tra cứu sinh viên
            </h2>
            
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Mã số sinh viên (MSSV)</label>
                <div class="flex gap-2">
                  <input type="text" id="vSearchMssv" 
                    class="flex-1 px-4 py-2 border rounded-xl focus:ring-2 focus:ring-primary focus:border-primary outline-none transition text-sm font-medium uppercase"
                    placeholder="Nhập MSSV (Ví dụ: CD23A-01)..." autocomplete="off" />
                  <button type="button" id="btnVSearch"
                    class="px-4 py-2 bg-primary hover:bg-blue-700 text-white rounded-xl text-sm font-semibold transition flex items-center gap-1">
                    Tìm
                  </button>
                </div>
              </div>

              <!-- KHU VỰC HIỂN THỊ THÔNG TIN SINH VIÊN -->
              <div id="vStudentCard" class="hidden p-4 rounded-2xl bg-slate-50 border border-slate-100 transition-all duration-300">
                <div class="flex items-start gap-4">
                  <!-- Badge Số lần vi phạm thông minh -->
                  <div id="vCountBadge" class="w-16 h-16 shrink-0 rounded-full border flex flex-col items-center justify-center transition-all duration-300 shadow-sm">
                    <span class="text-xs font-bold leading-none uppercase">Lỗi</span>
                    <span id="vCountVal" class="text-2xl font-black mt-0.5 leading-none">0</span>
                  </div>
                  
                  <div class="min-w-0 flex-1 space-y-1">
                    <h3 id="vStudentName" class="font-extrabold text-gray-900 text-base leading-tight"></h3>
                    <p class="text-xs font-semibold text-primary uppercase" id="vStudentMssv"></p>
                    <div class="text-xs text-gray-600 space-y-0.5 pt-1">
                      <div>Lớp: <span id="vStudentClass" class="font-semibold text-gray-800"></span></div>
                      <div>Khoa: <span id="vStudentDept" class="font-semibold text-gray-800"></span></div>
                      <div>SĐT: <span id="vStudentPhone" class="font-semibold text-gray-800"></span></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <?php if ($canCreateOrUpdate): ?>
            <!-- CARD 2: FORM GHI NHẬN VI PHẠM -->
            <div class="bg-white rounded-2xl shadow-card border border-gray-100 p-6">
              <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i data-lucide="alert-circle" class="w-5 h-5 text-rose-500"></i>
                Ghi nhận vi phạm
              </h2>

              <form id="vRecordForm" class="space-y-4">
                <input type="hidden" name="action" value="save">
                <input type="hidden" id="vFormMemberId" name="member_id" value="0">

                <div>
                  <label class="block text-sm font-semibold text-gray-700 mb-1">Lý do vi phạm *</label>
                  <textarea id="vFormReason" name="reason" rows="3" required disabled
                    class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-primary focus:border-primary outline-none transition text-sm disabled:bg-gray-50 disabled:cursor-not-allowed"
                    placeholder="Vui lòng chọn sinh viên trước khi ghi lý do..."></textarea>
                </div>

                <div>
                  <label class="block text-sm font-semibold text-gray-700 mb-1">Hình thức xử lý *</label>
                  <select id="vFormTreatment" name="treatment" required disabled
                    class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-primary focus:border-primary outline-none transition text-sm disabled:bg-gray-50 disabled:cursor-not-allowed">
                    <option value="" disabled selected>-- Chọn hình thức --</option>
                    <option value="Trừ 1 điểm rèn luyện">Trừ 1 điểm rèn luyện</option>
                    <option value="Khiển trách trước chi đoàn">Khiển trách trước chi đoàn</option>
                    <option value="Cảnh cáo toàn trường">Cảnh cáo toàn trường</option>
                    <option value="Đình chỉ tham gia hoạt động">Đình chỉ tham gia hoạt động</option>
                    <option value="Khác">Hình thức kỷ luật khác...</option>
                  </select>
                </div>

                <div id="vFormCustomTreatmentWrap" class="hidden">
                  <label class="block text-sm font-semibold text-gray-700 mb-1">Chi tiết hình thức khác *</label>
                  <input type="text" id="vFormCustomTreatment" 
                    class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-primary focus:border-primary outline-none transition text-sm"
                    placeholder="Nhập hình thức xử lý cụ thể..." />
                </div>

                <button type="submit" id="btnVSave" disabled
                  class="w-full py-2.5 bg-primary hover:bg-blue-700 text-white font-bold rounded-xl text-sm transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                  <i data-lucide="check" class="w-4 h-4"></i>
                  Lưu vi phạm
                </button>
              </form>
            </div>
          <?php endif; ?>

        </div>

        <!-- CỘT PHẢI: BẢNG CHI TIẾT CÁC LẦN VI PHẠM -->
        <div class="lg:col-span-2">
          <div class="bg-white rounded-2xl shadow-card border border-gray-100 overflow-hidden flex flex-col h-full min-h-[500px]">
            <div class="p-6 border-b border-gray-50 flex items-center justify-between">
              <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                <i data-lucide="list" class="w-5 h-5 text-gray-600"></i>
                Bảng chi tiết các lần vi phạm
              </h2>
              <div id="vDetailSummary" class="text-xs text-subtext font-medium"></div>
            </div>

            <!-- BẢNG NỘI DUNG -->
            <div class="flex-1 overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-700">
                  <tr>
                    <th class="px-4 py-3 text-center w-12 font-semibold">STT</th>
                    <th class="px-4 py-3 text-left w-36 font-semibold">Ngày vi phạm</th>
                    <th class="px-4 py-3 text-left font-semibold">Lý do vi phạm</th>
                    <th class="px-4 py-3 text-left w-48 font-semibold">Hình thức xử lý</th>
                    <th class="px-4 py-3 text-left w-36 font-semibold">Người lập</th>
                    <?php if ($canDelete): ?>
                      <th class="px-4 py-3 text-center w-20 font-semibold">Thao tác</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody id="tbodyViolations" class="text-gray-600">
                  <tr>
                    <td colspan="<?= $canDelete ? 6 : 5 ?>" class="px-4 py-16 text-center text-gray-400 font-medium">
                      <div class="flex flex-col items-center gap-2">
                        <i data-lucide="id-card" class="w-12 h-12 text-gray-300"></i>
                        Vui lòng nhập đúng mã số sinh viên ở cột bên trái để tra cứu chi tiết vi phạm
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>

<script>
  window.VIOLATIONS_CAN = {
    view: <?= can('violations', 'view') ? 'true' : 'false' ?>,
    create: <?= can('violations', 'create') ? 'true' : 'false' ?>,
    update: <?= can('violations', 'update') ? 'true' : 'false' ?>,
    delete: <?= can('violations', 'delete') ? 'true' : 'false' ?>
  };

  // Logic xử lý Frontend
  document.addEventListener("DOMContentLoaded", () => {
    const vSearchMssv = document.getElementById("vSearchMssv");
    const btnVSearch = document.getElementById("btnVSearch");
    const vStudentCard = document.getElementById("vStudentCard");
    const vCountBadge = document.getElementById("vCountBadge");
    const vCountVal = document.getElementById("vCountVal");
    const vStudentName = document.getElementById("vStudentName");
    const vStudentMssv = document.getElementById("vStudentMssv");
    const vStudentClass = document.getElementById("vStudentClass");
    const vStudentDept = document.getElementById("vStudentDept");
    const vStudentPhone = document.getElementById("vStudentPhone");

    const vFormMemberId = document.getElementById("vFormMemberId");
    const vFormReason = document.getElementById("vFormReason");
    const vFormTreatment = document.getElementById("vFormTreatment");
    const vFormCustomTreatmentWrap = document.getElementById("vFormCustomTreatmentWrap");
    const vFormCustomTreatment = document.getElementById("vFormCustomTreatment");
    const btnVSave = document.getElementById("btnVSave");
    const vRecordForm = document.getElementById("vRecordForm");
    const tbodyViolations = document.getElementById("tbodyViolations");
    const vDetailSummary = document.getElementById("vDetailSummary");

    let currentMember = null;

    // 1) Xử lý hiển thị chọn hình thức khác
    if (vFormTreatment) {
      vFormTreatment.addEventListener("change", (e) => {
        if (e.target.value === "Khác") {
          vFormCustomTreatmentWrap.classList.remove("hidden");
          vFormCustomTreatment.required = true;
        } else {
          vFormCustomTreatmentWrap.classList.add("hidden");
          vFormCustomTreatment.required = false;
        }
      });
    }

    // 2) Tìm kiếm sinh viên theo MSSV
    async function searchStudent() {
      const mssv = vSearchMssv.value.trim();
      if (!mssv) {
        toast("⚠️ Vui lòng nhập mã số sinh viên", "warning");
        return;
      }

      try {
        const res = await fetch(`controllers/violations.php?action=get_member&mssv=${encodeURIComponent(mssv)}`, {
          headers: { "X-Requested-With": "XMLHttpRequest" }
        });
        const json = await res.json();

        if (!json.ok) {
          toast(json.error || "Không tìm thấy sinh viên", "error");
          resetForms();
          return;
        }

        // Đổ dữ liệu sinh viên
        currentMember = json.data;
        vStudentName.textContent = currentMember.fullname;
        vStudentMssv.textContent = `MSSV: ${currentMember.mssv}`;
        vStudentClass.textContent = currentMember.class_name || "Chưa cập nhật";
        vStudentDept.textContent = currentMember.dept_name || "Chưa cập nhật";
        vStudentPhone.textContent = currentMember.phone || "—";
        if (vFormMemberId) {
          vFormMemberId.value = currentMember.id;
        }

        // Cập nhật số lần vi phạm & màu sắc badge
        updateCountBadge(currentMember.violation_count);

        vStudentCard.classList.remove("hidden");

        // Bật Form nhập vi phạm
        if (window.VIOLATIONS_CAN.create || window.VIOLATIONS_CAN.update) {
          vFormReason.disabled = false;
          vFormTreatment.disabled = false;
          btnVSave.disabled = false;
          vFormReason.placeholder = "Nhập lý do cụ thể sinh viên vi phạm...";
        }

        // Tải danh sách vi phạm chi tiết
        loadViolationsList(currentMember.id);

      } catch (err) {
        toast("❌ Lỗi kết nối máy chủ", "error");
      }
    }

    btnVSearch.addEventListener("click", searchStudent);
    vSearchMssv.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        searchStudent();
      }
    });

    // 3) Tải danh sách các lần vi phạm
    async function loadViolationsList(memberId) {
      tbodyViolations.innerHTML = `
        <tr>
          <td colspan="${window.VIOLATIONS_CAN.delete ? 6 : 5}" class="px-4 py-8 text-center text-gray-400">
            Đang tải lịch sử vi phạm...
          </td>
        </tr>`;

      try {
        const res = await fetch(`controllers/violations.php?action=list_by_member&member_id=${memberId}`, {
          headers: { "X-Requested-With": "XMLHttpRequest" }
        });
        const json = await res.json();

        if (!json.ok || !Array.isArray(json.data)) {
          tbodyViolations.innerHTML = `
            <tr>
              <td colspan="${window.VIOLATIONS_CAN.delete ? 6 : 5}" class="px-4 py-8 text-center text-red-500 font-semibold">
                Không thể tải lịch sử vi phạm
              </td>
            </tr>`;
          return;
        }

        if (json.data.length === 0) {
          tbodyViolations.innerHTML = `
            <tr>
              <td colspan="${window.VIOLATIONS_CAN.delete ? 6 : 5}" class="px-4 py-12 text-center text-emerald-600 font-bold bg-emerald-50/50">
                🎉 Sinh viên này chưa ghi nhận lần vi phạm nào.
              </td>
            </tr>`;
          vDetailSummary.textContent = "Chưa có vi phạm";
          return;
        }

        vDetailSummary.textContent = `Tổng cộng: ${json.data.length} lần vi phạm`;
        tbodyViolations.innerHTML = "";

        json.data.forEach((v, index) => {
          let dateStr = "—";
          if (v.created_at) {
            try {
              const date = new Date(v.created_at.replace(" ", "T"));
              if (!isNaN(date.getTime())) {
                dateStr = date.toLocaleDateString("vi-VN", {
                  day: "2-digit",
                  month: "2-digit",
                  year: "numeric",
                  hour: "2-digit",
                  minute: "2-digit"
                });
              } else {
                dateStr = v.created_at;
              }
            } catch (e) {
              dateStr = v.created_at;
            }
          }

          const tr = document.createElement("tr");
          tr.className = "border-t hover:bg-slate-50 transition-colors";
          tr.innerHTML = `
            <td class="px-4 py-3 text-center font-semibold text-gray-500">${index + 1}</td>
            <td class="px-4 py-3 text-left whitespace-nowrap text-xs font-semibold text-gray-500">${dateStr}</td>
            <td class="px-4 py-3 text-left font-medium text-gray-800 break-all">${escapeHtml(v.reason)}</td>
            <td class="px-4 py-3 text-left whitespace-nowrap"><span class="px-2 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">${escapeHtml(v.treatment)}</span></td>
            <td class="px-4 py-3 text-left text-xs font-medium text-gray-600">${escapeHtml(v.creator_name || "Hệ thống")}</td>
            ${window.VIOLATIONS_CAN.delete ? `
              <td class="px-4 py-3 text-center">
                <button type="button" class="px-2 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-bold transition js-v-delete" data-id="${v.id}">
                  Xóa
                </button>
              </td>
            ` : ""}
          `;
          tbodyViolations.appendChild(tr);
        });

      } catch (err) {
        tbodyViolations.innerHTML = `
          <tr>
            <td colspan="${window.VIOLATIONS_CAN.delete ? 6 : 5}" class="px-4 py-8 text-center text-red-500 font-semibold">
              Lỗi kết nối máy chủ
            </td>
          </tr>`;
      }
    }

    // 4) Xóa vi phạm
    tbodyViolations.addEventListener("click", (e) => {
      const btn = e.target.closest(".js-v-delete");
      if (!btn) return;

      const id = btn.dataset.id;
      modal(`
        <div class="text-center space-y-4 p-2">
          <p class="text-gray-700 text-sm">Bạn có chắc chắn muốn <b>xóa bỏ</b> lần vi phạm này của sinh viên?</p>
          <div class="flex justify-center gap-3">
            <button class="px-4 py-2 border rounded-xl text-sm" onclick="closeModal()">Hủy</button>
            <button id="confirmVDelete" class="px-4 py-2 bg-rose-600 text-white rounded-xl text-sm font-bold">Đồng ý xóa</button>
          </div>
        </div>
      `, "Xác nhận xóa kỷ luật");

      document.getElementById("confirmVDelete").onclick = async () => {
        closeModal();
        try {
          const fd = new FormData();
          fd.append("action", "delete");
          fd.append("id", id);

          const res = await fetch("controllers/violations.php", {
            method: "POST",
            body: fd,
            headers: { "X-Requested-With": "XMLHttpRequest" }
          });
          const json = await res.json();

          if (json.ok) {
            toast("🗑️ Đã xóa ghi nhận vi phạm thành công", "success");
            // Reload
            if (currentMember) {
              // Cập nhật lại số lần vi phạm trên card
              currentMember.violation_count = Math.max(0, currentMember.violation_count - 1);
              updateCountBadge(currentMember.violation_count);
              loadViolationsList(currentMember.id);
            }
          } else {
            toast(json.error || "Không thể xóa vi phạm", "error");
          }
        } catch {
          toast("Lỗi kết nối đến máy chủ", "error");
        }
      };
    });

    // 5) Thêm vi phạm mới
    if (vRecordForm) {
      vRecordForm.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (!currentMember) return;

        const fd = new FormData(vRecordForm);
        
        // Nếu là hình thức khác, lấy giá trị tự nhập
        if (vFormTreatment && vFormTreatment.value === "Khác") {
          fd.set("treatment", vFormCustomTreatment.value.trim());
        }

        try {
          const res = await fetch("controllers/violations.php", {
            method: "POST",
            body: fd,
            headers: { "X-Requested-With": "XMLHttpRequest" }
          });
          const json = await res.json();

          if (json.ok) {
            toast("✅ Ghi nhận vi phạm sinh viên thành công!", "success");
            if (vFormReason) vFormReason.value = "";
            if (vFormTreatment) vFormTreatment.value = "";
            if (vFormCustomTreatmentWrap) vFormCustomTreatmentWrap.classList.add("hidden");
            if (vFormCustomTreatment) vFormCustomTreatment.value = "";
            
            // Cập nhật số lần vi phạm trên card
            currentMember.violation_count++;
            updateCountBadge(currentMember.violation_count);
            
            // Tải lại bảng chi tiết
            loadViolationsList(currentMember.id);
          } else {
            toast(json.error || "Lỗi khi lưu vi phạm", "error");
          }
        } catch {
          toast("❌ Không thể kết nối máy chủ", "error");
        }
      });
    }

    // 6) Cập nhật Badge số lần vi phạm chuyển màu sinh động
    function updateCountBadge(count) {
      vCountVal.textContent = count;
      vCountBadge.className = "w-16 h-16 shrink-0 rounded-full border flex flex-col items-center justify-center transition-all duration-300 shadow-sm";
      
      if (count === 0) {
        vCountBadge.classList.add("bg-emerald-50", "text-emerald-700", "border-emerald-200");
      } else if (count <= 2) {
        vCountBadge.classList.add("bg-amber-50", "text-amber-700", "border-amber-200");
      } else {
        vCountBadge.classList.add("bg-rose-50", "text-rose-700", "border-rose-200", "animate-pulse");
      }
    }

    // 7) Reset các form về trạng thái ban đầu
    function resetForms() {
      currentMember = null;
      if (vFormMemberId) vFormMemberId.value = "0";
      vStudentCard.classList.add("hidden");
      if (vFormReason) {
        vFormReason.value = "";
        vFormReason.disabled = true;
        vFormReason.placeholder = "Vui lòng chọn sinh viên trước khi ghi lý do...";
      }
      if (vFormTreatment) {
        vFormTreatment.value = "";
        vFormTreatment.disabled = true;
      }
      if (vFormCustomTreatmentWrap) vFormCustomTreatmentWrap.classList.add("hidden");
      if (vFormCustomTreatment) vFormCustomTreatment.value = "";
      if (btnVSave) btnVSave.disabled = true;

      tbodyViolations.innerHTML = `
        <tr>
          <td colspan="${window.VIOLATIONS_CAN.delete ? 6 : 5}" class="px-4 py-16 text-center text-gray-400 font-medium">
            <div class="flex flex-col items-center gap-2">
              <i data-lucide="id-card" class="w-12 h-12 text-gray-300"></i>
              Vui lòng nhập đúng mã số sinh viên ở cột bên trái để tra cứu chi tiết vi phạm
            </div>
          </td>
        </tr>`;
      vDetailSummary.textContent = "";
    }

    function escapeHtml(str = "") {
      return String(str)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
    }

    // Khởi tạo Lucide Icons nếu có
    if (window.lucide) {
      lucide.createIcons();
    }
  });
</script>
