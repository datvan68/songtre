/* global api, toast */

(() => {
    const tbody = document.getElementById("tbodyLogs");
    if (!tbody) return;
    const btnFirst = document.getElementById("btnFirst");
    const btnLast = document.getElementById("btnLast");

    const fUserInput = document.getElementById("fUserInput");
    const fUserHidden = document.getElementById("fUser");
    const fUserDropdown = document.getElementById("fUserDropdown");

    let USER_LIST = []; // cache users

    const fRole = document.getElementById("fRole");
    const fUser = document.getElementById("fUser");
    const fModule = document.getElementById("fModule");
    const fAct = document.getElementById("fAct");
    const fFrom = document.getElementById("fFrom");
    const fTo = document.getElementById("fTo");
    const fPerPage = document.getElementById("fPerPage");

    const txtTotal = document.getElementById("txtTotal");
    const btnPrev = document.getElementById("btnPrev");
    const btnNext = document.getElementById("btnNext");
    const pageInput = document.getElementById("pageInput");
    const pageTotal = document.getElementById("pageTotal");

    const btnReload = document.getElementById("btnReloadLogs");
    const btnClear = document.getElementById("btnClearFilters");
    const btnExport = document.getElementById("btnExportLogs");

    let state = {
        page: 1,
        total: 0,
        perPage: 10,
        totalPages: 1,
        canViewAll: 0,
        canExport: window.ACTIVITY_LOGS_CAN_EXPORT ? 1 : 0,
        metaLoaded: false,
        loading: false
    };


    function escapeHtml(s) {
        return String(s ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    function formatDateTime(val) {
        if (!val) return "";

        // "YYYY-MM-DD HH:MM:SS"
        const [date, time] = val.split(" ");
        if (!date || !time) return val;

        const [y, m, d] = date.split("-");
        if (!y || !m || !d) return val;

        return `${d}-${m}-${y} ${time}`;
    }


    function getParamsFromURL() {
        const p = new URLSearchParams(window.location.search);
        state.page = Math.max(1, parseInt(p.get("page") || "1", 10));
        fRole.value = p.get("role_id") || "";
        fUser.value = p.get("user_id") || "";
        fUserHidden.value = p.get("user_id") || "";
        fModule.value = p.get("module") || "";
        fAct.value = p.get("act") || "";
        fFrom.value = p.get("from") || "";
        fTo.value = p.get("to") || "";
        const pp = p.get("per_page");
        if (pp) fPerPage.value = pp;
        state.perPage = parseInt(fPerPage.value || "10", 10);
        pageInput.value = String(state.page);
    }

    function syncURL(push = true) {
        const p = new URLSearchParams(window.location.search);

        p.set("p", "activity_logs"); // giữ SPA view
        p.set("page", String(state.page));
        p.set("per_page", String(state.perPage));

        const roleId = fRole.value;
        const userId = fUserHidden.value;
        const module = fModule.value;
        const act = fAct.value;
        const from = fFrom.value;
        const to = fTo.value;

        if (roleId) p.set("role_id", roleId); else p.delete("role_id");
        if (userId) p.set("user_id", userId);
        else p.delete("user_id");
        if (module) p.set("module", module); else p.delete("module");
        if (act) p.set("act", act); else p.delete("act");
        if (from) p.set("from", from); else p.delete("from");
        if (to) p.set("to", to); else p.delete("to");

        const url = "?" + p.toString();
        if (push) history.pushState(null, "", url);
        else history.replaceState(null, "", url);
    }

    async function loadMeta() {
        if (state.metaLoaded) return;

        const res = await api("controllers/activity_logs.php?action=meta", { credentials: "include" });
        const j = await res.json();
        if (!j.ok) throw new Error(j.error || "Meta error");

        // roles
        fRole.innerHTML = `<option value="">-- Tất cả --</option>`;
        (j.roles || []).forEach(r => {
            const opt = document.createElement("option");
            opt.value = r.id;
            opt.textContent = r.name;
            fRole.appendChild(opt);
        });

        // users
        USER_LIST = j.users || [];
        (j.users || []).forEach(u => {
            const opt = document.createElement("option");
            opt.value = u.id;
            opt.textContent = u.display_name || u.username;
            fUser.appendChild(opt);
        });

        state.metaLoaded = true;
    }

    function renderUserDropdown(keyword = "") {
        const kw = keyword.toLowerCase();

        const items = USER_LIST.filter(u =>
            (u.username || "").toLowerCase().includes(kw) ||
            (u.fullname || "").toLowerCase().includes(kw)
        );

        if (!items.length) {
            fUserDropdown.innerHTML = `
      <div class="px-3 py-2 text-sm text-gray-400">Không tìm thấy</div>
    `;
            return;
        }

        fUserDropdown.innerHTML = items.map(u => `
    <div
      class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer"
      data-id="${u.id}"
    >
<div class="font-medium">${escapeHtml(u.username)}</div>
${u.fullname && u.fullname !== u.username
                ? `<div class="text-xs text-gray-500">${escapeHtml(u.fullname)}</div>`
                : ""}

    </div>
  `).join("");
    }
    fUserInput.addEventListener("input", debounce(() => {
        const v = fUserInput.value.trim();

        // luôn xóa hidden khi gõ
        fUserHidden.value = "";

        if (v === "") {
            // 🔥 xóa user_id khỏi URL

            const p = new URLSearchParams(window.location.search);
            p.delete("user_id");
            p.set("page", "1");
            history.replaceState(null, "", "?" + p.toString());

            fUserDropdown.classList.add("hidden");
            fUserHidden.value = ""; // 🔥 QUAN TRỌNG
            state.page = 1;
            loadList(false); // ❗ KHÔNG ghi lại user_id cũ
            return;
        }

        renderUserDropdown(v);
        fUserDropdown.classList.remove("hidden");
    }, 300));
    fPerPage.addEventListener("change", () => {
        state.page = 1; // đổi số dòng thì quay về trang 1
        state.perPage = parseInt(fPerPage.value, 10) || 10;

        loadList(true); // sync URL + reload SPA
    });

    fUserDropdown.addEventListener("click", e => {
        const item = e.target.closest("[data-id]");
        if (!item) return;

        fUserHidden.value = item.dataset.id;
        fUserInput.value = item.querySelector(".font-medium").textContent;
        fUserDropdown.classList.add("hidden");

        state.page = 1;
        loadList(true);
    });
    document.addEventListener("click", e => {
        if (!e.target.closest("#fUserInput") && !e.target.closest("#fUserDropdown")) {
            fUserDropdown.classList.add("hidden");
        }
    });

    function renderRows(rows) {
        if (!rows || rows.length === 0) {
            tbody.innerHTML = `
      <tr>
        <td colspan="8" class="text-center py-10 text-subtext">
          Không có dữ liệu
        </td>
      </tr>`;
            return;
        }

        /* ===== MAP TIẾNG VIỆT ===== */
        const MODULE_LABELS = {
            auth: 'Xác thực',
            account: 'Thông tin cá nhân',
            members: 'Quản lý đoàn viên',
            duty: 'Quản lý trực',
            campaigns: 'Phong trào',
            permissions: 'Phân quyền',
            registrations: 'ĐK Phong trào',
            nominations: 'Thi đua-Khen thưởng',
            departments: 'Khoa/Lớp',
            inventory: 'Quản lý thiết bị',
            reward_units: 'Danh mục',
            roles: 'Vai trò',
            system: 'Hệ thống',
            schedule: 'Lịch công tác'
        };

        const ACTION_LABELS = {
            login: 'Đăng nhập',
            logout: 'Đăng xuất',
            login_failed: 'Đăng nhập thất bại',

            create: 'Tạo mới',
            update: 'Cập nhật',
            delete: 'Xóa',
            review: 'Đánh giá',

            register: 'Đăng ký',
            cancel_register: 'Hủy đăng ký',

            approve: 'Duyệt',
            reject: 'Từ chối',

            import: 'Nhập',
            export: 'Xuất'
        };


        const TARGET_LABELS = {
            user: 'Người dùng',
            member: 'Đoàn viên',
            campaign: 'Phong trào',
            system: 'Hệ thống',
            role: 'Vai trò',
            availability: 'Lịch rảnh',
            study_schedule: 'Lịch học',
            assignment: 'Lịch trực',
            inventory_items: 'Thiết bị',
            inventory_categories: 'Danh mục thiết bị',
            permission: 'Quyền'
        };

        tbody.innerHTML = rows.map(r => {


            /* ===== USER ===== */
            const userHtml = `
        <div class="font-medium">${escapeHtml(r.username)}</div>
      `;

            /* ===== ROLE ===== */
            const roleHtml = r.role_name
                ? `<span class="px-2 py-0.5 rounded text-xs font-medium
            ${r.role_name === 'admin'
                    ? 'bg-purple-100 text-purple-700'
                    : 'bg-gray-100 text-gray-600'}">
            ${escapeHtml(r.role_name)}
          </span>`
                : '';

            /* ===== ACTION (MÀU + TIẾNG VIỆT) ===== */
            const actionClass = {
                login: 'bg-green-100 text-green-700',
                logout: 'bg-gray-200 text-gray-700',
                login_failed: 'bg-red-100 text-red-700',
                create: 'bg-blue-100 text-blue-700',
                update: 'bg-yellow-100 text-yellow-800',
                delete: 'bg-red-200 text-red-800',

                review: 'bg-purple-100 text-purple-700',   // đang duyệt
                approve: 'bg-emerald-100 text-emerald-700', // duyệt
                reject: 'bg-gray-300 text-gray-800'         // từ chối
            }[r.action] || 'bg-gray-100 text-gray-700';

            const actionText = ACTION_LABELS[r.action] || r.action;

            const actionHtml = `
        <span class="px-2 py-0.5 rounded text-xs font-semibold uppercase tracking-wide ${actionClass}">
          ${escapeHtml(actionText)}
        </span>
      `;

            /* ===== MODULE (TIẾNG VIỆT) ===== */
            const moduleText = MODULE_LABELS[r.module] || r.module;

            const moduleHtml = `
        <span class="text-xs font-medium ${r.module === 'auth' ? 'text-blue-700' : 'text-gray-600'
                }">
          ${escapeHtml(moduleText)}
        </span>
      `;

            /* ===== TARGET (GIỮ – VIỆT HÓA) ===== */
            const targetTypeText = TARGET_LABELS[r.target_type] || r.target_type;

            const targetHtml = r.target_type
                ? `<span class="text-xs">
            ${escapeHtml(targetTypeText)}
            ${r.target_id ? `<span class="text-gray-500"> #${r.target_id}</span>` : ''}
          </span>`
                : `<span class="text-gray-400">—</span>`;

            /* ===== DESCRIPTION ===== */
            const descHtml = r.description
                ? `<div class="text-sm text-gray-700 break-words max-w-[360px]">
                ${sanitizeLogHtml(r.description)}
          </div>`
                : `<span class="text-gray-400">—</span>`;

            /* ===== IP ===== */
            const ipText =
                r.ip_address === '::1' || r.ip_address === '127.0.0.1'
                    ? 'LOCAL'
                    : (r.ip_address || '');

            return `
        <tr class="hover:bg-gray-50 align-top text-center">
          <!-- Thời gian -->
            <td class="whitespace-nowrap text-sm text-gray-600">
                ${formatDateTime(r.created_at)}
            </td>


          <!-- User -->
          <td class="whitespace-nowrap">
            ${userHtml}
          </td>

          <!-- Role -->
          <td class="text-center">
            ${roleHtml}
          </td>

          <!-- Action -->
          <td class="text-center">
            ${actionHtml}
          </td>

          <!-- Module -->
          <td class="text-center">
            ${moduleHtml}
          </td>

          <!-- Target -->
          <td class="whitespace-nowrap text-center">
            ${targetHtml}
          </td>

          <!-- Mô tả -->
          <td class="text-center">
            ${descHtml}
          </td>

        </tr>
      `;
        })
            .join("");
    }


    function sanitizeLogHtml(html) {
        if (!html) return "";

        // CHỈ cho phép các tag an toàn
        return String(html)
            .replace(/<(?!\/?(b|strong|i|em|br)\b)[^>]*>/gi, "")
            .replace(/on\w+="[^"]*"/gi, "");
    }

    async function loadList(pushUrl = true) {
        if (state.loading) return;
        state.loading = true;

        try {
            tbody.innerHTML = `
      <tr>
        <td colspan="8" class="text-center py-10 text-subtext">
          Đang tải dữ liệu…
        </td>
      </tr>`;

            state.perPage = parseInt(fPerPage.value || "10", 10);

            if (pushUrl) syncURL(true);
            else syncURL(false);

            /* ===== BUILD QUERY ===== */

            const q = new URLSearchParams();
            q.set("action", "list");
            q.set("page", String(state.page));
            q.set("per_page", String(state.perPage));
            if (fUserHidden.value) {
                q.set("user_id", fUserHidden.value);
            }
            if (fRole.value !== "") q.set("role_id", fRole.value);
            if (fModule.value !== "") q.set("module", fModule.value);
            if (fAct.value !== "") q.set("act", fAct.value);
            if (fFrom.value) q.set("from", fFrom.value);
            if (fTo.value) q.set("to", fTo.value);


            const res = await api(
                "controllers/activity_logs.php?" + q.toString(),
                { credentials: "include" }
            );
            const j = await res.json();
            if (!j.ok) throw new Error(j.error || "Lỗi tải danh sách");

            state.total = j.total || 0;
            state.canViewAll = j.can_view_all ? 1 : 0;



            /* ===== PHÂN TRANG ===== */
            if (pageTotal) {
                pageTotal.textContent = String(
                    Math.max(1, Math.ceil(state.total / state.perPage))
                );
            }

            if (txtTotal) {
                txtTotal.textContent = String(state.total);
            }

            btnPrev && (btnPrev.disabled = state.page <= 1);
            btnFirst && (btnFirst.disabled = state.page <= 1);

            const maxPage = Math.max(1, Math.ceil(state.total / state.perPage));
            btnNext && (btnNext.disabled = state.page >= maxPage);
            btnLast && (btnLast.disabled = state.page >= maxPage);

            /* ===== MAP TIẾNG VIỆT ===== */
            const MODULE_LABELS = {
                auth: 'Xác thực',
                account: 'Thông tin cá nhân',
                members: 'Quản lý đoàn viên',
                duty: 'Quản lý trực',
                campaigns: 'Phong trào',
                permissions: 'Phân quyền',
                registrations: 'ĐK Phong trào',
                nominations: 'Thi đua-Khen thưởng',
                departments: 'Khoa/Lớp',
                inventory: 'Quản lý thiết bị',
                reward_units: 'Danh mục',
                roles: 'Vai trò',
                system: 'Hệ thống',
                schedule: 'Lịch công tác'
            };

            const ACTION_LABELS = {
                login: 'Đăng nhập',
                logout: 'Đăng xuất',
                login_failed: 'Đăng nhập thất bại',

                create: 'Tạo mới',
                update: 'Cập nhật',
                delete: 'Xóa',
                review: 'Đánh giá',

                register: 'Đăng ký',
                cancel_register: 'Hủy đăng ký',

                approve: 'Duyệt',
                reject: 'Từ chối',

                import: 'Nhập',
                export: 'Xuất'
            };


            /* ===== DROPDOWN MODULE (VIỆT HÓA) ===== */
            if (Array.isArray(j.modules)) {
                const cur = fModule.value;
                fModule.innerHTML =
                    `<option value="">-- Tất cả --</option>` +
                    j.modules.map(m => {
                        const label = MODULE_LABELS[m] || m;
                        return `<option value="${escapeHtml(m)}">${escapeHtml(label)}</option>`;
                    }).join("");
                fModule.value = cur;
            }

            /* ===== DROPDOWN ACTION (VIỆT HÓA) ===== */
            if (Array.isArray(j.actions)) {
                const cur = fAct.value;
                fAct.innerHTML =
                    `<option value="">-- Tất cả --</option>` +
                    [...new Set(j.actions)].map(a => {
                        const label = ACTION_LABELS[a] || a;
                        return `<option value="${escapeHtml(a)}">${escapeHtml(label)}</option>`;
                    }).join("");
                fAct.value = cur;
            }

            /* ===== KHÓA FILTER NẾU KHÔNG CÓ QUYỀN ===== */
            if (!state.canViewAll) {
                fRole.disabled = true;
                fUser.disabled = true;
                fRole.classList.add("opacity-60");
                fUser.classList.add("opacity-60");
            }

            /* ===== RENDER TABLE ===== */
            renderRows(j.rows || []);

            /* ===== UPDATE PAGER ===== */
            pageInput.value = String(state.page);
            btnPrev.disabled = state.page <= 1;
            btnNext.disabled =
                state.page >= Math.max(1, Math.ceil(state.total / state.perPage));

        } catch (e) {
            tbody.innerHTML = `
      <tr>
        <td colspan="8" class="text-center py-10 text-red-600 font-semibold">
          Lỗi tải dữ liệu
        </td>
      </tr>`;
            toast(e.message || "Lỗi tải dữ liệu");
        } finally {
            state.loading = false;
        }
    }


    function debounce(fn, wait = 350) {
        let t = null;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), wait);
        };
    }

    const onFilterChanged = debounce(() => {
        state.page = 1;
        loadList(true);
    }, 350);
    // ===== FILTER CHANGE EVENTS =====
    [fRole, fModule, fAct, fFrom, fTo].forEach(el => {
        if (!el) return;
        el.addEventListener("change", () => {
            state.page = 1;
            loadList(true); // đổi filter là lọc NGAY
        });
    });




    btnReload?.addEventListener("click", () => loadList(true));

    btnClear?.addEventListener("click", () => {
        // reset input
        fRole.value = "";
        fModule.value = "";
        fAct.value = "";
        fFrom.value = "";
        fTo.value = "";
        fPerPage.value = "10";

        // 🔥 reset user autocomplete
        fUserInput.value = "";
        fUserHidden.value = "";
        fUserDropdown.classList.add("hidden");

        // reset state
        state.page = 1;
        state.perPage = 10;

        // 🔥 reset URL chỉ giữ p
        const p = new URLSearchParams();
        p.set("p", "activity_logs");
        history.replaceState(null, "", "?" + p.toString());

        loadList(false);
    });



    btnFirst?.addEventListener("click", () => {
        if (state.page === 1) return;
        state.page = 1;
        loadList(true); // SPA – KHÔNG reload
    });

    btnPrev.addEventListener("click", () => {
        if (state.page <= 1) return;
        state.page--;
        loadList(true);
    });

    btnLast?.addEventListener("click", () => {
        const maxPage = Math.max(1, Math.ceil(state.total / state.perPage));
        if (state.page === maxPage) return;
        state.page = maxPage;
        loadList(true);
    });

    btnNext.addEventListener("click", () => {
        const maxPage = Math.max(1, Math.ceil(state.total / state.perPage));
        if (state.page >= maxPage) return;
        state.page++;
        loadList(true);
    });

    pageInput.addEventListener("keydown", (e) => {
        if (e.key !== "Enter") return;

        const v = parseInt(pageInput.value, 10);
        if (isNaN(v)) return;

        const maxPage = Math.max(1, Math.ceil(state.total / state.perPage));
        state.page = Math.min(Math.max(1, v), maxPage);
        loadList(true);
    });


    btnExport?.addEventListener("click", async () => {
        if (!state.canExport) return toast("Bạn không có quyền export");
        try {
            const q = new URLSearchParams();
            q.set("action", "export");
            q.set("page", "1");
            q.set("per_page", String(state.perPage));
            if (fRole.value) q.set("role_id", fRole.value);
            if (fUser.value) q.set("user_id", fUser.value);
            if (fModule.value) q.set("module", fModule.value);
            if (fAct.value) q.set("act", fAct.value);
            if (fFrom.value) q.set("from", fFrom.value);
            if (fTo.value) q.set("to", fTo.value);

            const res = await api("controllers/activity_logs.php?" + q.toString(), { credentials: "include" });
            const j = await res.json();
            if (!j.ok) throw new Error(j.error || "Export error");

            const blob = new Blob([j.csv], { type: "text/csv;charset=utf-8;" });
            const a = document.createElement("a");
            a.href = URL.createObjectURL(blob);
            a.download = j.filename || "activity_logs.csv";
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(a.href);

            toast("Đã export CSV");
        } catch (e) {
            toast(e.message || "Export lỗi");
        }
    });

    // Init
    (async () => {
        try {
            await loadMeta();
            getParamsFromURL();
            // replaceState để không spam history khi init
            syncURL(false);
            await loadList(false);
        } catch (e) {
            toast(e.message || "Init lỗi");
        }
    })();

    // back/forward browser
    window.addEventListener("popstate", () => {
        getParamsFromURL();
        loadList(false);
    });

})();
