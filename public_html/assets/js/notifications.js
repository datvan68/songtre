// === DROPDOWN THÔNG BÁO ===
const btnNoti = document.getElementById("btnNoti");
const notiMenu = document.getElementById("notiMenu");
const notiBadge = document.getElementById("notiBadge");
const notiList = document.getElementById("notiList");
const userRole = btnNoti?.dataset.role || "guest";

// 🟢 Cập nhật số thông báo chưa đọc
async function updateUnreadCount() {
    try {
        const res = await api("controllers/notifications.php?action=count_unread&nocache=" + Date.now());
        const data = await res.json();
        const count = data?.count || 0;
        if (count > 0) {
            notiBadge.textContent = count;
            notiBadge.classList.remove("hidden");
        } else {
            notiBadge.textContent = "";
            notiBadge.classList.add("hidden");
        }
    } catch (err) {
        console.warn("Không lấy được số thông báo chưa đọc:", err);
    }
}

// 🟢 Khi click chuông → chỉ mở/đóng dropdown, KHÔNG reset badge
btnNoti?.addEventListener("click", async (e) => {
    e.stopPropagation();
    notiMenu.classList.toggle("hidden");
    if (!notiMenu.classList.contains("hidden")) {
        await refreshNotiList();
    }
});

// 🟢 Làm mới danh sách thông báo nhỏ (dropdown)
async function refreshNotiList() {
    const res = await api("controllers/notifications.php?action=list&nocache=" + Date.now());
    const data = await res.json();
    notiList.innerHTML = "";

    if (!data.length) {
        notiList.innerHTML = `<div class="px-4 py-2 text-sm text-gray-500 border-b">Không có thông báo</div>`;
        return;
    }

    data.forEach((n) => {
        const isNew = !n.is_read;
        const item = document.createElement("div");
        item.className = `
      flex items-start gap-2 px-4 py-2 text-sm border-b transition-all cursor-pointer
      ${isNew ? "bg-blue-50 hover:bg-blue-100 text-gray-900 font-semibold" : "bg-white hover:bg-gray-50 text-gray-500"}
    `;
        item.innerHTML = `
      ${isNew
                ? '<span class="text-blue-500 mt-0.5">•</span>'
                : '<span class="w-1.5 h-1.5 mt-1 rounded-full bg-gray-300"></span>'}
      <div class="flex-1 leading-snug">
        <div>${n.message}</div>
        <div class="text-xs text-gray-400">${n.created_at}</div>
      </div>
    `;
        item.dataset.id = n.id;
        item.dataset.link = n.link || "";
        item.addEventListener("click", async () => handleNotificationClick(n.id, item, n.link));
        notiList.appendChild(item);
    });
}

// 🟢 Hàm xử lý khi click vào thông báo
async function handleNotificationClick(id, item, link) {
    try {
        await api(`controllers/notifications.php?action=mark_single&id=${id}`, { method: "POST" });

        // Cập nhật UI
        item.classList.remove("bg-blue-50", "text-gray-900", "font-semibold");
        item.classList.add("bg-white", "text-gray-500");
        const dot = item.querySelector("span");
        if (dot) {
            dot.className = "w-1.5 h-1.5 mt-1 rounded-full bg-gray-300";
            dot.textContent = "";
        }

        await updateUnreadCount();

        // Nếu có link → chuyển đến trang đó
        if (link) {
            window.location.href = link;
            return;
        }

        // Nếu là admin mà không có link, mặc định chuyển trang đăng ký
        if (userRole === "admin") {
            window.location.href = "/index.php?p=registrations";
        }
    } catch (err) {
        console.warn("Không thể đánh dấu đã đọc:", err);
    }
}

// 🟢 “Xem tất cả” trong modal
document.querySelector("#notiMenu .text-center")?.addEventListener("click", async () => {
    const res = await api("controllers/notifications.php?action=list");
    const data = await res.json();

    if (!data.length)
        return modal(`<div class='p-4 text-gray-500'>Không có thông báo nào</div>`, "Tất cả thông báo", "small");

    let html = `<div class='max-h-[420px] overflow-y-auto divide-y divide-gray-200' id="notiAllList">`;

    data.forEach((n) => {
        const isNew = !n.is_read;
        const disabled = isNew ? "disabled" : "";
        html += `
      <div data-id="${n.id}" data-link="${n.link || ""}"
        class="noti-item flex items-start justify-between gap-2 px-4 py-2 text-sm transition-all
        ${isNew ? "bg-blue-50 text-gray-900 font-semibold" : "bg-white text-gray-600"}">
        <div class="flex items-start gap-2 flex-1 cursor-pointer">
          ${isNew
                ? '<span class="text-blue-500 mt-0.5">•</span>'
                : '<input type="checkbox" class="chkDel mt-1 accent-red-500" ' + disabled + ">"}
          <div class="leading-snug">
            <div>${n.message}</div>
            <div class="text-xs text-gray-400">${n.created_at}</div>
          </div>
        </div>
      </div>`;
    });

    html += `
  </div>
  <div class="flex justify-between items-center p-3 border-t bg-gray-50">
    <div class="flex items-center gap-2">
      <button id="btnSelectAll"
        class="bg-gray-200 hover:bg-gray-300 text-gray-700 text-xs px-3 py-1 rounded">✔️ Chọn tất cả</button>
    </div>
    <button id="btnDeleteSelected"
      class="bg-red-500 hover:bg-red-600 text-white text-xs px-3 py-1 rounded disabled:opacity-50"
      disabled>🗑 Xóa các mục đã chọn</button>
  </div>`;

    modal(html, "Tất cả thông báo", "small");

    setTimeout(() => {
        const chkList = document.querySelectorAll(".chkDel");
        const btnDelSel = document.getElementById("btnDeleteSelected");
        const btnSelectAll = document.getElementById("btnSelectAll");

        // Chặn tất cả click vào .noti-item (để user/admin không vô tình mở thông báo)
        document.querySelectorAll(".noti-item").forEach((el) => {
            el.addEventListener("click", (e) => e.stopImmediatePropagation());
        });

        function updateDeleteButton() {
            const anyChecked = Array.from(chkList).some((c) => c.checked);
            btnDelSel.disabled = !anyChecked;
        }

        chkList.forEach((chk) => {
            chk.addEventListener("click", (e) => e.stopImmediatePropagation());
            chk.addEventListener("change", updateDeleteButton);
        });


        btnSelectAll.addEventListener("click", (e) => {
            e.stopImmediatePropagation();
            const allChecked = Array.from(chkList).every((c) => c.checked);
            chkList.forEach((c) => (c.checked = !allChecked));
            btnSelectAll.textContent = allChecked ? "✔️ Chọn tất cả" : "❌ Bỏ chọn tất cả";
            updateDeleteButton();
        });

        btnDelSel.addEventListener("click", async (e) => {
            e.stopImmediatePropagation(); // CHẶN LAN RA .noti-item

            const selected = Array.from(chkList)
                .filter((c) => c.checked)
                .map((c) => c.closest(".noti-item").dataset.id);

            if (!selected.length) return;

            const confirmBox = document.createElement("div");
            confirmBox.className = "p-6 text-center space-y-4";
            confirmBox.innerHTML = `
      <div class="text-xl font-semibold text-gray-800">Xác nhận xóa</div>
      <p class="text-gray-600 text-sm">
        Bạn có chắc muốn xóa <b>${selected.length}</b> thông báo đã chọn không?<br>
        Hành động này không thể hoàn tác.
      </p>
      <div class="flex justify-center gap-4 mt-4">
        <button id="btnCancelDel"
          class="px-4 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm">Hủy</button>
        <button id="btnConfirmDel"
          class="px-4 py-2 rounded-lg bg-red-500 hover:bg-red-600 text-white text-sm">Xóa</button>
      </div>
    `;

            modal(confirmBox, "Xóa thông báo", "medidum");

            setTimeout(() => {
                document.getElementById("btnCancelDel").addEventListener("click", (e) => {
                    e.stopImmediatePropagation();
                    closeModal();
                });

                document.getElementById("btnConfirmDel").addEventListener("click", async (e) => {
                    e.stopImmediatePropagation();

                    try {
                        await api("controllers/notifications.php?action=delete_selected", {
                            method: "POST",
                            headers: { "Content-Type": "application/x-www-form-urlencoded" },
                            body: "ids=" + selected.join(","),
                        });

                        selected.forEach((id) => {
                            const el = document.querySelector(`.noti-item[data-id='${id}']`);
                            if (el) el.remove();
                        });

                        closeModal();
                        btnDelSel.disabled = true;
                        updateUnreadCount();
                        // ✅ TOAST THÔNG BÁO GÓC PHẢI TRÊN
                        toast(`Đã xóa ${selected.length} thông báo`, "success");
                    } catch (err) {
                        closeModal();
                        modal(
                            "<div class='p-4 text-red-500 text-center'>Không thể xóa thông báo!</div>",
                            "Lỗi",
                            "small"
                        );
                    }
                });
            }, 200);
        });


        // ======================
        //   FIX CLICK MỞ THÔNG BÁO
        // ======================

        // CHẶN TẤT CẢ CLICK TRONG .noti-item (KHÔNG CHO LAN RA NGOÀI)
        document.querySelectorAll(".noti-item").forEach((el) => {
            el.addEventListener("click", (e) => e.stopImmediatePropagation());
        });

        // CHỈ CHO PHÉP click vào .leading-snug → mở thông báo
        document.querySelectorAll(".noti-item .leading-snug").forEach((item) => {
            item.addEventListener("click", async (e) => {
                e.stopImmediatePropagation();  // QUAN TRỌNG
                const wrapper = item.closest(".noti-item");
                const id = wrapper.dataset.id;
                const link = wrapper.dataset.link;
                await handleNotificationClick(id, wrapper, link);
            });
        });
    }, 300);
});

// 🔒 NOTIFICATIONS – POLLING ONLY (NO SSE)
window.addEventListener("load", () => {
    window.lastNotiId = 0;

    async function pollNoti() {
        try {
            const res = await api(
                "controllers/notifications.php?action=latest&nocache=" + Date.now()
            );
            const data = await res.json();

            if (data?.id && data.id > window.lastNotiId) {
                window.lastNotiId = data.id;
                await updateUnreadCount();
                refreshNotiList();
            }
        } catch (err) {
            console.warn("Polling notifications error:", err);
        }
    }

    // chạy ngay khi load
    pollNoti();

    // mỗi 5 giây
    setInterval(pollNoti, 5000);
});

document.addEventListener("click", (e) => {

    // ===== NOTIFICATION MENU =====
    if (
        btnNoti &&
        notiMenu &&
        !e.target.closest("#btnNoti") &&
        !e.target.closest("#notiMenu")
    ) {
        notiMenu.classList.add("hidden");
    }
});
