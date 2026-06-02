// assets/js/award_suggest.js
(function () {
    const APP = document.getElementById("award-suggest-app");
    if (!APP) return;

    const BASE_URL = window.__AWARD_SUGGEST_BASE_URL__ || "/";
    const CAMPAIGNS = Array.isArray(window.__AWARD_SUGGEST_CAMPAIGNS__)
        ? window.__AWARD_SUGGEST_CAMPAIGNS__
        : [];

    // ========= Helpers =========
    const $ = (id) => document.getElementById(id);

    function toast(msg, type = "info") {
        if (window.toast) return window.toast(msg, type);
        if (window.notify) return window.notify(msg, type);
        console[type === "error" ? "error" : "log"](msg);
    }

    function esc(s) {
        return String(s ?? "")
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }

    function badgeStatus(st) {
        // ELIGIBLE / NEAR / PENDING_GRADE / NOT_ELIGIBLE
        if (st === "ELIGIBLE")
            return `<span class="px-2 py-1 rounded-lg text-xs font-bold bg-green-100 text-green-700">ĐỦ</span>`;
        if (st === "NEAR")
            return `<span class="px-2 py-1 rounded-lg text-xs font-bold bg-orange-100 text-orange-700">GẦN ĐỦ</span>`;
        if (st === "PENDING_GRADE")
            return `<span class="px-2 py-1 rounded-lg text-xs font-bold bg-blue-100 text-blue-700">CHƯA CHẤM</span>`;
        return `<span class="px-2 py-1 rounded-lg text-xs font-bold bg-gray-100 text-gray-700">CHƯA ĐỦ</span>`;
    }

    function labelSuggestStatusVN(st) {
        if (st === "ELIGIBLE") return "Đủ điều kiện";
        if (st === "NEAR") return "Gần đủ";
        if (st === "PENDING_GRADE") return "Chưa chấm";
        return "Chưa đủ";
    }

    function labelReq(req) {
        if (req === "excellent") return "Xuất sắc";
        if (req === "good") return "Tốt";
        return String(req || "-");
    }

    function labelHave(have) {
        // registrations.status: approved/excellent/good/incomplete/cancelled/none
        if (have === "excellent") return "Xuất sắc";
        if (have === "good") return "Tốt";
        if (have === "incomplete") return "Chưa hoàn thành";
        if (have === "approved") return "Chưa chấm";
        if (have === "cancelled") return "Hủy";
        if (have === "none") return "Không tham gia";
        return String(have || "-");
    }

    async function api(action, data = null, method = "POST") {
        const url =
            BASE_URL +
            "controllers/award_suggest.php?action=" +
            encodeURIComponent(action);

        const opt = { method };

        if (method === "POST") {
            opt.headers = { "Content-Type": "application/json" };
            opt.body = JSON.stringify(data || {});
        }

        const res = await fetch(url, opt);

        let j = null;
        try {
            j = await res.json();
        } catch (e) {
            throw new Error("JSON parse lỗi (server trả HTML hoặc 500).");
        }

        if (!j?.ok) throw new Error(j?.error || "API lỗi");
        return j.data;
    }

    function findCampaignById(id) {
        const n = parseInt(id, 10);
        if (!Number.isFinite(n)) return null;
        return CAMPAIGNS.find((x) => Number(x.id) === n) || null;
    }

    // ========= Tabs (URL sync) =========
    const tabBtns = document.querySelectorAll(".award-tab-btn");
    const panels = document.querySelectorAll(".award-tab-panel");

    function getTabFromUrl() {
        const url = new URL(window.location.href);
        return url.searchParams.get("tab") || "";
    }

    function setTabToUrl(tab) {
        const url = new URL(window.location.href);
        url.searchParams.set("tab", tab);
        window.history.replaceState({}, "", url.toString());
    }

    function activateTab(tab, updateUrl = true) {
        if (!tab) tab = tabBtns[0]?.dataset.tab || "";

        // ✅ Active giống “Tổng quan”: nền đen chữ trắng
        // ✅ Hover giống “Xếp lịch”: hover:bg-gray-50
        tabBtns.forEach((b) => {
            b.classList.remove("text-blue-600", "border-blue-600");
            b.classList.add("text-gray-500", "border-transparent");
        });

        const btnActive = [...tabBtns].find((b) => b.dataset.tab === tab);
        if (btnActive) {
            btnActive.classList.add("text-blue-600", "border-blue-600");
            btnActive.classList.remove("text-gray-500", "border-transparent");
        }

        panels.forEach((p) => p.classList.add("hidden"));
        document
            .querySelector(`.award-tab-panel[data-panel="${tab}"]`)
            ?.classList.remove("hidden");

        if (updateUrl) setTabToUrl(tab);
    }

    tabBtns.forEach((btn) => {
        btn.addEventListener("click", () => {
            activateTab(btn.dataset.tab, true);
        });
    });

    activateTab(getTabFromUrl() || (tabBtns[0]?.dataset.tab || ""), false);

    window.addEventListener("popstate", () => {
        activateTab(getTabFromUrl(), false);
    });

    // ========= State (Suggest) =========
    let META = { titles: [], school_years: [] };
    let REQUIRED_ITEMS = [];
    let SUGGEST_ROWS = [];

    let SUGGEST_STATE = {
        page: 1,
        pageSize: 10, // ✅ mặc định 10
        q: "",
        status: "",
    };

    // ========= Elements (Suggest) =========
    const asTitleSelect = $("asTitleSelect");
    const asYearSelect = $("asYearSelect");
    const asSemesterSelect = $("asSemesterSelect");
    const btnRunSuggest = $("btnRunSuggest");
    const btnReloadSuggest = $("btnReloadSuggest");

    const kpiTotal = $("kpiTotal");
    const kpiEligible = $("kpiEligible");
    const kpiNear = $("kpiNear");
    const kpiPending = $("kpiPending");

    const asSearch = $("asSearch");
    const asStatusFilter = $("asStatusFilter");
    const asPageSize = $("asPageSize");

    const asSearchBox = $("asSearchBox");
    const asSearchDropdown = $("asSearchDropdown");

    const asTableBody = $("asTableBody");
    const asPagerInfo = $("asPagerInfo");
    const asPrevPage = $("asPrevPage");
    const asNextPage = $("asNextPage");
    const asPageNums = $("asPageNums");

    function updateRunButtonState() {
        const ok = !!asTitleSelect.value && !!asYearSelect.value;
        if (btnRunSuggest) btnRunSuggest.disabled = !ok;
    }

    function computeKPIs(rows) {
        let total = rows.length;
        let eligible = 0;
        let near = 0;
        let pending = 0;

        rows.forEach((r) => {
            if (r.status === "ELIGIBLE") eligible++;
            else if (r.status === "NEAR") near++;
            else if (r.status === "PENDING_GRADE") pending++;
        });

        if (kpiTotal) kpiTotal.textContent = total;
        if (kpiEligible) kpiEligible.textContent = eligible;
        if (kpiNear) kpiNear.textContent = near;
        if (kpiPending) kpiPending.textContent = pending;
    }

    function normText(s) {
        return String(s || "")
            .toLowerCase()
            .normalize("NFD")                 // tách dấu
            .replace(/[\u0300-\u036f]/g, "")  // bỏ dấu
            .replace(/[^a-z0-9]+/g, " ")      // bỏ ngoặc, ký tự lạ -> space
            .trim();
    }

    function applyFilters(rows) {
        const st = SUGGEST_STATE.status || "";

        // Query normalize -> tách token
        const qRaw = (SUGGEST_STATE.q || "").trim();
        const qNorm = normText(qRaw);
        const tokens = qNorm ? qNorm.split(/\s+/).filter(Boolean) : [];

        return rows.filter((r) => {
            if (st && r.status !== st) return false;

            if (!tokens.length) return true;

            const hayNorm = normText(
                (r.mssv || "") + " " +
                (r.fullname || "") + " " +
                (r.class_name || "") + " " +
                (r.dept_name || "")
            );

            // ✅ phải match hết token (tên + mssv gõ chung vẫn ra)
            return tokens.every((t) => hayNorm.includes(t));
        });
    }


    function paginate(rows) {
        const pageSize = SUGGEST_STATE.pageSize;
        const total = rows.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (SUGGEST_STATE.page > totalPages) SUGGEST_STATE.page = totalPages;

        const start = (SUGGEST_STATE.page - 1) * pageSize;
        const end = Math.min(start + pageSize, total);
        return { total, totalPages, start, end, pageRows: rows.slice(start, end) };
    }

    function renderPager(total, totalPages, start, end) {
        if (!asPagerInfo) return;

        asPagerInfo.textContent =
            total === 0 ? "0 - 0 / 0" : start + 1 + " - " + end + " / " + total;

        if (asPrevPage) asPrevPage.disabled = SUGGEST_STATE.page <= 1;
        if (asNextPage) asNextPage.disabled = SUGGEST_STATE.page >= totalPages;

        if (!asPageNums) return;

        asPageNums.innerHTML = "";
        const maxBtns = 7;

        let from = Math.max(1, SUGGEST_STATE.page - 3);
        let to = Math.min(totalPages, from + maxBtns - 1);
        from = Math.max(1, to - maxBtns + 1);

        for (let p = from; p <= to; p++) {
            const b = document.createElement("button");
            b.className =
                "px-3 py-1.5 rounded-lg border text-sm font-semibold " +
                (p === SUGGEST_STATE.page
                    ? "bg-gray-900 text-white border-gray-900"
                    : "bg-white hover:bg-gray-50");

            b.textContent = p;
            b.addEventListener("click", () => {
                SUGGEST_STATE.page = p;
                renderSuggestTable();
            });
            asPageNums.appendChild(b);
        }
    }

    // ========= Suggest Search Autocomplete =========
    let AS_OPEN = false;
    let AS_ACTIVE_INDEX = -1;
    let AS_LAST_LIST = [];

    function openAsDropdown() {
        if (!asSearchDropdown) return;
        asSearchDropdown.classList.remove("hidden");
        AS_OPEN = true;
    }

    function closeAsDropdown() {
        if (!asSearchDropdown) return;
        asSearchDropdown.classList.add("hidden");
        AS_OPEN = false;
        AS_ACTIVE_INDEX = -1;
    }

    function buildAsHintText(u) {
        const fullname = (u.fullname || "").trim();
        const mssv = (u.mssv || "").trim();
        const cls = (u.class_name || "").trim();
        const dept = (u.dept_name || "").trim();

        // hiển thị đẹp: Fullname (MSSV) • Lớp • Khoa
        const left = fullname || "(Chưa có tên)";
        const mid = mssv ? " (" + mssv + ")" : "";
        const right = [cls, dept].filter(Boolean).join(" • ");
        return { left: left + mid, right };
    }

    function filterAsCandidates(q) {
        const s = String(q || "").trim().toLowerCase();
        if (!s) return [];

        // chưa chạy suggest thì không có data để gợi ý
        if (!Array.isArray(SUGGEST_ROWS) || !SUGGEST_ROWS.length) return [];

        let list = SUGGEST_ROWS.filter((r) => {
            const hay =
                (r.mssv || "") + " " +
                (r.fullname || "") + " " +
                (r.class_name || "") + " " +
                (r.dept_name || "");

            return hay.toLowerCase().includes(s);
        });

        // ưu tiên ĐỦ điều kiện lên trước cho “gợi ý” nhìn đã hơn
        const rank = { ELIGIBLE: 1, NEAR: 2, PENDING_GRADE: 3, NOT_ELIGIBLE: 4 };
        list.sort((a, b) => {
            const ra = rank[a.status] || 9;
            const rb = rank[b.status] || 9;
            if (ra !== rb) return ra - rb;
            return (b.readiness || 0) - (a.readiness || 0);
        });

        return list.slice(0, 10);
    }

    function renderAsDropdown(list) {
        if (!asSearchDropdown) return;

        AS_LAST_LIST = Array.isArray(list) ? list : [];
        AS_ACTIVE_INDEX = -1;

        if (!AS_LAST_LIST.length) {
            asSearchDropdown.innerHTML = `
            <div class="px-4 py-3 text-sm text-gray-500">
                Không có gợi ý phù hợp
            </div>
        `;
            openAsDropdown();
            return;
        }

        asSearchDropdown.innerHTML = AS_LAST_LIST.map((u, idx) => {
            const t = buildAsHintText(u);
            return `
            <button type="button"
                class="as-opt w-full text-left px-4 py-3 hover:bg-gray-50 border-b last:border-b-0"
                data-idx="${idx}">
                <div class="text-sm font-semibold text-gray-900">
                    ${esc(t.left)}
                </div>
                ${t.right ? `<div class="text-xs text-gray-500 mt-0.5">${esc(t.right)}</div>` : ""}
            </button>
        `;
        }).join("");

        asSearchDropdown.querySelectorAll(".as-opt").forEach((btn) => {
            btn.addEventListener("click", () => {
                const idx = parseInt(btn.dataset.idx || "-1", 10);
                const u = AS_LAST_LIST[idx];
                if (!u) return;

                // chọn xong -> fill vào input (cho dễ nhìn)
                const fullname = (u.fullname || "").trim();
                const mssv = (u.mssv || "").trim();
                asSearch.value = fullname ? (fullname + (mssv ? " (" + mssv + ")" : "")) : (mssv || "");

                // set filter thật sự theo text input
                SUGGEST_STATE.q = asSearch.value || "";
                SUGGEST_STATE.page = 1;
                renderSuggestTable();

                closeAsDropdown();
            });
        });

        openAsDropdown();
    }

    function highlightAsOption() {
        if (!asSearchDropdown) return;
        const opts = asSearchDropdown.querySelectorAll(".as-opt");
        opts.forEach((o) => o.classList.remove("bg-blue-50"));

        if (AS_ACTIVE_INDEX >= 0 && AS_ACTIVE_INDEX < opts.length) {
            opts[AS_ACTIVE_INDEX].classList.add("bg-blue-50");
            opts[AS_ACTIVE_INDEX].scrollIntoView({ block: "nearest" });
        }
    }

    function renderMissingCell(row) {
        const missing = Array.isArray(row.missing) ? row.missing : [];
        const pending = Array.isArray(row.pending) ? row.pending : [];

        if (!missing.length && !pending.length) {
            return `<span class="text-xs text-gray-400">—</span>`;
        }

        const parts = [];

        if (pending.length) {
            parts.push(
                `<div class="text-xs font-semibold text-blue-700 mb-1">Chưa chấm:</div>` +
                pending
                    .slice(0, 3)
                    .map((m) => {
                        return `<div class="text-xs text-blue-700">#${esc(
                            m.campaign_id
                        )} (cần ${esc(labelReq(m.need))})</div>`;
                    })
                    .join("")
            );
            if (pending.length > 3) {
                parts.push(
                    `<div class="text-xs text-blue-700">+${pending.length - 3
                    } mục khác</div>`
                );
            }
        }

        if (missing.length) {
            parts.push(
                `<div class="text-xs font-semibold text-orange-700 mt-2 mb-1">Thiếu:</div>` +
                missing
                    .slice(0, 3)
                    .map((m) => {
                        return `<div class="text-xs text-orange-700">#${esc(
                            m.campaign_id
                        )} (cần ${esc(labelReq(m.need))}, hiện ${esc(
                            labelHave(m.have)
                        )})</div>`;
                    })
                    .join("")
            );
            if (missing.length > 3) {
                parts.push(
                    `<div class="text-xs text-orange-700">+${missing.length - 3
                    } mục khác</div>`
                );
            }
        }

        return parts.join("");
    }

    async function notifyCandidate(uid, btnEl) {
        const row = SUGGEST_ROWS.find((x) => String(x.user_id) === String(uid));
        const fullname = row?.fullname || "sinh viên";

        const title_id = parseInt(asTitleSelect?.value || "0", 10);
        const school_year = asYearSelect?.value || "";
        const semester = asSemesterSelect?.value || "ALL";

        if (!uid || !title_id || !school_year) {
            toast("Thiếu danh hiệu / năm học để gửi thông báo.", "error");
            return;
        }

        const oldText = btnEl ? btnEl.textContent : "";
        if (btnEl) {
            btnEl.disabled = true;
            btnEl.textContent = "Đang gửi...";
        }

        try {
            await api(
                "notify_candidate",
                { user_id: uid, title_id, school_year, semester },
                "POST"
            );

            toast("Đã gửi yêu cầu đề cử cho " + fullname + ".", "success");

            if (btnEl) {
                btnEl.textContent = "Đã gửi";
                btnEl.classList.remove("bg-green-600", "hover:bg-green-700");
                btnEl.classList.add("bg-gray-200", "text-gray-500");
            }
        } catch (e) {
            toast("Gửi thông báo thất bại: " + e.message, "error");
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.textContent = oldText || "Tạo đề cử";
            }
        }
    }

    function renderSuggestTable() {
        const filtered = applyFilters(SUGGEST_ROWS);
        computeKPIs(filtered);

        const { total, totalPages, start, end, pageRows } = paginate(filtered);
        renderPager(total, totalPages, start, end);

        if (!asTableBody) return;

        if (!pageRows.length) {
            asTableBody.innerHTML = `
        <tr>
          <td colspan="8" class="px-4 py-6 text-center text-gray-500">
            Không có dữ liệu phù hợp bộ lọc.
          </td>
        </tr>
      `;
            return;
        }

        asTableBody.innerHTML = pageRows
            .map((r) => {
                const readiness = Number.isFinite(r.readiness) ? r.readiness : 0;
                const canCreate = r.status === "ELIGIBLE";

                return `
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 text-gray-800 font-semibold">${esc(
                    r.mssv || ""
                )}</td>
            <td class="px-4 py-3 text-gray-800">${esc(
                    r.fullname || ""
                )}</td>
            <td class="px-4 py-3 text-gray-700">${esc(
                    r.class_name || ""
                )}</td>
            <td class="px-4 py-3 text-gray-700">${esc(r.dept_name || "")}</td>
            <td class="px-4 py-3">${badgeStatus(r.status)}</td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <div class="w-28 h-2 rounded-full bg-gray-200 overflow-hidden">
                  <div class="h-2 bg-gray-900" style="width:${Math.max(
                    0,
                    Math.min(100, readiness)
                )}%"></div>
                </div>
                <span class="text-xs font-bold text-gray-800">${esc(
                    readiness
                )}%</span>
              </div>
              <div class="text-xs text-gray-500 mt-1">
                ${esc(r.matched_count)}/${esc(r.total_required)}
              </div>
            </td>
            <td class="px-4 py-3">
              ${renderMissingCell(r)}
            </td>
            <td class="px-4 py-3 text-right sticky right-0 bg-white">
              <div class="flex items-center justify-end gap-2">
                <button
                  class="btnRowDetail px-3 py-1.5 rounded-lg border bg-white hover:bg-gray-50 text-xs font-semibold"
                  data-user="${esc(r.user_id)}"
                >
                  Chi tiết
                </button>

                <button
                  class="btnCreateNomination px-3 py-1.5 rounded-lg text-xs font-semibold
                    ${canCreate
                        ? "bg-green-600 hover:bg-green-700 text-white"
                        : "bg-gray-200 text-gray-500 cursor-not-allowed"
                    }"
                  data-user="${esc(r.user_id)}"
                  ${canCreate ? "" : "disabled"}
                  title="${canCreate
                        ? "Gửi yêu cầu đề cử cho sinh viên"
                        : "Chỉ gửi khi ĐỦ điều kiện"
                    }"
                >
                  Tạo đề cử
                </button>
              </div>
            </td>
          </tr>
        `;
            })
            .join("");

        // Bind detail buttons
        document.querySelectorAll(".btnRowDetail").forEach((btn) => {
            btn.addEventListener("click", () => {
                const uid = btn.dataset.user;
                const row = SUGGEST_ROWS.find((x) => String(x.user_id) === String(uid));
                if (!row) return;

                const req = Array.isArray(REQUIRED_ITEMS) ? REQUIRED_ITEMS : [];
                const missing = Array.isArray(row.missing) ? row.missing : [];
                const pending = Array.isArray(row.pending) ? row.pending : [];

                const byCid = {};
                missing.forEach((m) => {
                    byCid[String(m.campaign_id)] = m;
                });
                pending.forEach((m) => {
                    byCid[String(m.campaign_id)] = m;
                });

                let html = `
          <div style="font-family: ui-sans-serif, system-ui; padding:12px;">
            <div style="font-weight:800; font-size:16px; color:#111827;">
              ${esc(row.fullname || "")} (${esc(row.mssv || "")})
            </div>
            <div style="margin-top:4px; font-size:12px; color:#6B7280;">
              ${esc(row.class_name || "")} • ${esc(row.dept_name || "")}
            </div>

            <div style="margin-top:12px; font-weight:700; color:#111827;">
              Checklist tiêu chí
            </div>
            <div style="margin-top:8px; font-size:12px; color:#374151;">
        `;

                req.forEach((it) => {
                    const cid = it.campaign_id;
                    const need = it.required_status;
                    const info = byCid[String(cid)];

                    const camp = findCampaignById(cid);
                    const campName = camp?.title || it.title || "";

                    // ✅ KHÔNG hiện "#51" nữa, chỉ hiện tên
                    const titleText = campName ? esc(campName) : "Phong trào";

                    if (!info) {
                        html += `<div>- ${titleText}: cần ${esc(labelReq(need))} → đạt</div>`;
                    } else {
                        html += `<div>- ${titleText}: cần ${esc(labelReq(need))} → hiện ${esc(
                            labelHave(info.have)
                        )}</div>`;
                    }
                });

                html += `
            </div>

            <div style="margin-top:12px; font-size:12px; color:#6B7280;">
              Trạng thái: <b>${esc(
                    labelSuggestStatusVN(row.status)
                )}</b> • Sẵn sàng: <b>${esc(row.readiness)}%</b>
            </div>
          </div>
        `;

                if (window.openModal) {
                    window.openModal("Chi tiết gợi ý", html);
                } else {
                    const w = window.open("", "_blank", "width=520,height=520");
                    if (w) w.document.write(html);
                    else alert("Popup bị chặn. Mở chi tiết thất bại.");
                }
            });
        });

        // Bind create nomination => notify only (NO redirect)
        document.querySelectorAll(".btnCreateNomination").forEach((btn) => {
            btn.addEventListener("click", async () => {
                const uid = parseInt(btn.dataset.user || "0", 10);
                if (!uid) return;
                await notifyCandidate(uid, btn);
            });
        });
    }

    async function loadMeta() {
        try {
            const data = await api("meta", {}, "POST");
            META = data || { titles: [], school_years: [] };

            // Fill suggest selects
            if (asTitleSelect) {
                asTitleSelect.innerHTML = `<option value="">-- Chọn danh hiệu --</option>`;
                (META.titles || []).forEach((t) => {
                    const op = document.createElement("option");
                    op.value = t.id;
                    op.textContent = t.name;
                    asTitleSelect.appendChild(op);
                });
            }

            if (asYearSelect) {
                asYearSelect.innerHTML = `<option value="">-- Chọn năm học --</option>`;
                (META.school_years || []).forEach((y) => {
                    const op = document.createElement("option");
                    op.value = y;
                    op.textContent = y;
                    asYearSelect.appendChild(op);
                });
            }

            // ✅ default pageSize = 10
            if (asPageSize) {
                if (!asPageSize.value) asPageSize.value = "10";
                SUGGEST_STATE.pageSize = parseInt(asPageSize.value || "10", 10);
            }

            // Fill rule selects too
            const ruleTitleSelect = $("ruleTitleSelect");
            const ruleYearSelect = $("ruleYearSelect");

            if (ruleTitleSelect) {
                ruleTitleSelect.innerHTML = `<option value="">-- Chọn danh hiệu --</option>`;
                (META.titles || []).forEach((t) => {
                    const op = document.createElement("option");
                    op.value = t.id;
                    op.textContent = t.name;
                    ruleTitleSelect.appendChild(op);
                });
            }

            if (ruleYearSelect) {
                ruleYearSelect.innerHTML = `<option value="">-- Chọn năm học --</option>`;
                (META.school_years || []).forEach((y) => {
                    const op = document.createElement("option");
                    op.value = y;
                    op.textContent = y;
                    ruleYearSelect.appendChild(op);
                });
            }

            updateRunButtonState();
        } catch (e) {
            toast("Lỗi meta: " + e.message, "error");
        }
    }

    async function runSuggest() {
        const title_id = asTitleSelect?.value || "";
        const school_year = asYearSelect?.value || "";
        const semester = asSemesterSelect?.value || "ALL";

        if (!title_id || !school_year) {
            toast("Chọn danh hiệu và năm học trước.", "error");
            return;
        }

        if (btnRunSuggest) {
            btnRunSuggest.disabled = true;
            btnRunSuggest.textContent = "Đang xử lý...";
        }

        try {
            const data = await api(
                "suggest",
                { title_id, school_year, semester },
                "POST"
            );

            REQUIRED_ITEMS = data.required_campaigns || [];
            SUGGEST_ROWS = data.rows || [];

            SUGGEST_STATE.page = 1;
            renderSuggestTable();

            toast("Đã chạy gợi ý. Tổng: " + SUGGEST_ROWS.length, "success");
        } catch (e) {
            toast("Lỗi gợi ý: " + e.message, "error");
            if (asTableBody) {
                asTableBody.innerHTML = `
          <tr>
            <td colspan="8" class="px-4 py-6 text-center text-red-600">
              Không thể chạy gợi ý. ${esc(e.message)}
            </td>
          </tr>
        `;
            }
        } finally {
            if (btnRunSuggest) {
                btnRunSuggest.disabled = false;
                btnRunSuggest.textContent = "Chạy gợi ý";
            }
        }
    }

    // ========= Suggest Events =========
    asTitleSelect?.addEventListener("change", updateRunButtonState);
    asYearSelect?.addEventListener("change", updateRunButtonState);
    btnRunSuggest?.addEventListener("click", runSuggest);
    btnReloadSuggest?.addEventListener("click", loadMeta);

    asSearch?.addEventListener("input", () => {
        SUGGEST_STATE.q = asSearch.value || "";
        SUGGEST_STATE.page = 1;
        renderSuggestTable();

        // render dropdown gợi ý
        const list = filterAsCandidates(asSearch.value);
        if (!asSearch.value.trim()) closeAsDropdown();
        else renderAsDropdown(list);
    });

    asSearch?.addEventListener("focus", () => {
        const list = filterAsCandidates(asSearch.value);
        if (!asSearch.value.trim()) return;
        renderAsDropdown(list);
    });

    asSearch?.addEventListener("keydown", (e) => {
        if (!AS_OPEN) return;

        if (e.key === "Escape") {
            closeAsDropdown();
            return;
        }

        if (e.key === "ArrowDown") {
            e.preventDefault();
            AS_ACTIVE_INDEX = Math.min(AS_ACTIVE_INDEX + 1, AS_LAST_LIST.length - 1);
            highlightAsOption();
            return;
        }

        if (e.key === "ArrowUp") {
            e.preventDefault();
            AS_ACTIVE_INDEX = Math.max(AS_ACTIVE_INDEX - 1, 0);
            highlightAsOption();
            return;
        }

        if (e.key === "Enter") {
            if (AS_ACTIVE_INDEX >= 0 && AS_ACTIVE_INDEX < AS_LAST_LIST.length) {
                e.preventDefault();
                const u = AS_LAST_LIST[AS_ACTIVE_INDEX];
                if (!u) return;

                const fullname = (u.fullname || "").trim();
                const mssv = (u.mssv || "").trim();
                asSearch.value = fullname ? (fullname + (mssv ? " (" + mssv + ")" : "")) : (mssv || "");

                SUGGEST_STATE.q = asSearch.value || "";
                SUGGEST_STATE.page = 1;
                renderSuggestTable();

                closeAsDropdown();
            }
        }
    });

    // click ngoài đóng dropdown
    document.addEventListener("click", (e) => {
        if (!asSearchBox) return;
        if (!asSearchBox.contains(e.target)) closeAsDropdown();
    });


    asStatusFilter?.addEventListener("change", () => {
        SUGGEST_STATE.status = asStatusFilter.value || "";
        SUGGEST_STATE.page = 1;
        renderSuggestTable();
    });

    asPageSize?.addEventListener("change", () => {
        SUGGEST_STATE.pageSize = parseInt(asPageSize.value || "10", 10);
        SUGGEST_STATE.page = 1;
        renderSuggestTable();
    });

    asPrevPage?.addEventListener("click", () => {
        if (SUGGEST_STATE.page > 1) {
            SUGGEST_STATE.page--;
            renderSuggestTable();
        }
    });

    asNextPage?.addEventListener("click", () => {
        SUGGEST_STATE.page++;
        renderSuggestTable();
    });

    // ========= Rule Builder =========
    const ruleTitleSelect = $("ruleTitleSelect");
    const ruleYearSelect = $("ruleYearSelect");
    const ruleSemesterSelect = $("ruleSemesterSelect");
    const btnLoadRule = $("btnLoadRule");
    const btnSaveRule = $("btnSaveRule");

    const ruleCampaignSearch = $("ruleCampaignSearch");
    const ruleRequiredStatus = $("ruleRequiredStatus");
    const btnAddRuleItem = $("btnAddRuleItem");

    const ruleItemsBody = $("ruleItemsBody");
    const ruleItemCount = $("ruleItemCount");

    let RULE_ITEMS = [];
    let CURRENT_RULE = null;
    function campaignDisplay(c) {
        // ✅ chỉ hiện TÊN phong trào trong ô input
        return String(c.title || "").trim();
    }
    // ========= Rule Campaign Autocomplete (CUSTOM, không lệch như datalist) =========
    const ruleCampaignBox = $("ruleCampaignBox");
    const ruleCampaignDropdown = $("ruleCampaignDropdown");

    // sort mới -> cũ
    const RULE_CAMPAIGNS = CAMPAIGNS.slice().sort((a, b) => (b.id || 0) - (a.id || 0));

    let RC_OPEN = false;
    let RC_ACTIVE_INDEX = -1;
    let RC_LAST_LIST = [];

    // ✅ lưu ID đã chọn theo thứ tự token (để khỏi bị trùng tên gây sai)
    let RC_SELECTED_IDS = [];

    function openRuleDropdown() {
        if (!ruleCampaignDropdown) return;
        ruleCampaignDropdown.classList.remove("hidden");
        RC_OPEN = true;
    }

    function closeRuleDropdown() {
        if (!ruleCampaignDropdown) return;
        ruleCampaignDropdown.classList.add("hidden");
        RC_OPEN = false;
        RC_ACTIVE_INDEX = -1;
    }

    // =====================
    // ✅ MULTI SELECT SEPARATOR: ||
    // =====================
    const RULE_SEP = "||";
    const RULE_SEP_SPLIT_RE = /\s*\|\|\s*/;     // tách token theo ||
    const RULE_SEP_TAIL_RE = /\s*\|\|\s*$/;     // check cuối chuỗi có || không

    function endsWithRuleSep(v) {
        return RULE_SEP_TAIL_RE.test(String(v || ""));
    }

    function getRuleTokensFromInput(v) {
        // bỏ "||" cuối nếu user đang để " ... || "
        const raw = String(v || "").replace(RULE_SEP_TAIL_RE, "").trim();
        if (!raw) return [];

        return raw
            .split(RULE_SEP_SPLIT_RE)
            .map((x) => x.trim())
            .filter(Boolean);
    }

    function getRuleLastToken(v) {
        // lấy token cuối để lọc dropdown
        const raw = String(v || "");
        const parts = raw.split(RULE_SEP_SPLIT_RE).map((x) => x.trim());
        return String(parts[parts.length - 1] || "").trim();
    }

    function setRuleTokensToInput(tokens, addSepTail = true) {
        const clean = (tokens || [])
            .map((x) => String(x || "").trim())
            .filter(Boolean);

        // hiển thị đẹp: "A || B || "
        ruleCampaignSearch.value =
            clean.join(" || ") + (addSepTail ? " || " : "");
    }


    function syncSelectedIdsWithInput() {
        const tokens = getRuleTokensFromInput(ruleCampaignSearch.value);

        // user xóa bớt => cắt mảng id theo
        if (tokens.length < RC_SELECTED_IDS.length) {
            RC_SELECTED_IDS = RC_SELECTED_IDS.slice(0, tokens.length);
            return;
        }

        // user đang gõ token mới => tokens = selected + 1 => ok
        if (tokens.length === RC_SELECTED_IDS.length) return;
        if (tokens.length === RC_SELECTED_IDS.length + 1) return;

        // nếu lệch quá => user sửa giữa chừng => reset
        RC_SELECTED_IDS = [];
    }


    function filterRuleCampaigns(inputValue) {
        // ✅ chỉ lọc theo token cuối cùng sau dấu phẩy
        const last = getRuleLastToken(inputValue);
        const s = String(last || "").trim().toLowerCase();

        if (!s) return RULE_CAMPAIGNS.slice(0, 12);

        // nếu nhập số -> ưu tiên match ID
        const idMatch = s.match(/^(\d+)/);
        const numeric = idMatch ? parseInt(idMatch[1], 10) : 0;

        let list = RULE_CAMPAIGNS.filter((c) => {
            const idStr = String(c.id || "");
            const title = String(c.title || "").toLowerCase();

            if (numeric) {
                if (idStr.startsWith(String(numeric))) return true;
            }
            return title.includes(s) || idStr.includes(s);
        });

        return list.slice(0, 12);
    }
    function inputEndsWithComma(v) {
        return /,\s*$/.test(String(v || ""));
    }

    function renderRuleDropdown(list) {
        if (!ruleCampaignDropdown) return;

        RC_LAST_LIST = Array.isArray(list) ? list : [];
        RC_ACTIVE_INDEX = -1;

        if (!RC_LAST_LIST.length) {
            ruleCampaignDropdown.innerHTML = `
          <div class="px-4 py-3 text-sm text-gray-500">
            Không có kết quả phù hợp
          </div>
        `;
            openRuleDropdown();
            return;
        }

        ruleCampaignDropdown.innerHTML = RC_LAST_LIST.map((c, idx) => {
            const title = esc(String(c.title || "").trim() || "(Không có tên)");
            const y = esc(c.school_year || "-");
            const sem = esc(c.semester || "-");
            const id = esc(c.id);

            return `
          <button
            type="button"
            class="rule-camp-opt w-full text-left px-4 py-3 hover:bg-gray-50 border-b last:border-b-0"
            data-idx="${idx}"
            data-id="${id}"
          >
            <div class="text-sm font-semibold text-gray-900">
              ${title}
            </div>
            <div class="text-xs text-gray-500 mt-0.5">
              ${y} / ${sem} • ID: <span class="font-semibold text-gray-700">${id}</span>
            </div>
          </button>
        `;
        }).join("");

        // bind click
        ruleCampaignDropdown.querySelectorAll(".rule-camp-opt").forEach((btn) => {
            btn.addEventListener("click", () => {
                const idx = parseInt(btn.dataset.idx || "-1", 10);
                const c = RC_LAST_LIST[idx];
                if (!c) return;

                const inputVal = ruleCampaignSearch.value || "";
                const endsComma = inputEndsWithComma(inputVal);
                const lastToken = getRuleLastToken(inputVal);
                const tokens = getRuleTokensFromInput(inputVal);

                let nextTokens = [];

                // ✅ nếu đang "Phong trào A, " => append thêm phong trào mới
                if (endsComma || !lastToken) {
                    nextTokens = tokens.concat([campaignDisplay(c)]);
                    RC_SELECTED_IDS = RC_SELECTED_IDS.slice(0, tokens.length);
                } else {
                    // ✅ nếu đang gõ "Phong trào A, ho..." => replace token đang gõ
                    const prefix = tokens.length ? tokens.slice(0, -1) : [];
                    nextTokens = prefix.concat([campaignDisplay(c)]);
                    RC_SELECTED_IDS = RC_SELECTED_IDS.slice(0, prefix.length);
                }

                RC_SELECTED_IDS.push(parseInt(c.id, 10));

                setRuleTokensToInput(nextTokens, true);

                // ✅ chọn xong vẫn show danh sách tiếp để chọn nhiều cái
                renderRuleDropdown(filterRuleCampaigns(ruleCampaignSearch.value));
            });
        });


        openRuleDropdown();
    }

    function highlightActiveOption() {
        if (!ruleCampaignDropdown) return;
        const opts = ruleCampaignDropdown.querySelectorAll(".rule-camp-opt");
        opts.forEach((o) => o.classList.remove("bg-blue-50"));

        if (RC_ACTIVE_INDEX >= 0 && RC_ACTIVE_INDEX < opts.length) {
            opts[RC_ACTIVE_INDEX].classList.add("bg-blue-50");
            opts[RC_ACTIVE_INDEX].scrollIntoView({ block: "nearest" });
        }
    }

    // input events
    ruleCampaignSearch?.addEventListener("input", () => {
        syncSelectedIdsWithInput();

        const list = filterRuleCampaigns(ruleCampaignSearch.value);
        renderRuleDropdown(list);
    });

    ruleCampaignSearch?.addEventListener("focus", () => {
        syncSelectedIdsWithInput();
        const list = filterRuleCampaigns(ruleCampaignSearch.value);
        renderRuleDropdown(list);
    });

    ruleCampaignSearch?.addEventListener("keydown", (e) => {
        if (!RC_OPEN) return;

        if (e.key === "Escape") {
            closeRuleDropdown();
            return;
        }

        if (e.key === "ArrowDown") {
            e.preventDefault();
            RC_ACTIVE_INDEX = Math.min(RC_ACTIVE_INDEX + 1, RC_LAST_LIST.length - 1);
            highlightActiveOption();
            return;
        }

        if (e.key === "ArrowUp") {
            e.preventDefault();
            RC_ACTIVE_INDEX = Math.max(RC_ACTIVE_INDEX - 1, 0);
            highlightActiveOption();
            return;
        }

        if (e.key === "Enter") {
            if (RC_ACTIVE_INDEX >= 0 && RC_ACTIVE_INDEX < RC_LAST_LIST.length) {
                e.preventDefault();
                const c = RC_LAST_LIST[RC_ACTIVE_INDEX];
                if (!c) return;

                const inputVal = ruleCampaignSearch.value || "";
                const endsComma = inputEndsWithComma(inputVal);
                const lastToken = getRuleLastToken(inputVal);
                const tokens = getRuleTokensFromInput(inputVal);

                let nextTokens = [];

                if (endsComma || !lastToken) {
                    nextTokens = tokens.concat([campaignDisplay(c)]);
                    RC_SELECTED_IDS = RC_SELECTED_IDS.slice(0, tokens.length);
                } else {
                    const prefix = tokens.length ? tokens.slice(0, -1) : [];
                    nextTokens = prefix.concat([campaignDisplay(c)]);
                    RC_SELECTED_IDS = RC_SELECTED_IDS.slice(0, prefix.length);
                }

                RC_SELECTED_IDS.push(parseInt(c.id, 10));

                setRuleTokensToInput(nextTokens, true);
                renderRuleDropdown(filterRuleCampaigns(ruleCampaignSearch.value));
            }
        }

    });

    // click outside => đóng dropdown
    document.addEventListener("click", (e) => {
        if (!ruleCampaignBox) return;
        if (!ruleCampaignBox.contains(e.target)) closeRuleDropdown();
    });



    function parseCampaignIdFromInput(v) {
        const s = String(v || "").trim();
        if (!s) return 0;
        const m = s.match(/^(\d+)/);
        return m ? parseInt(m[1], 10) : 0;
    }

    function parseCampaignIdsFromInputMulti(v) {
        const raw = String(v || "").trim();
        if (!raw) return { ids: [], invalid: [] };

        const parts = raw.split(",").map(x => x.trim()).filter(Boolean);

        let ids = [];
        let invalid = [];

        parts.forEach((p) => {
            const m = p.match(/^(\d+)/); // lấy số ở đầu chuỗi
            if (m) {
                const n = parseInt(m[1], 10);
                if (Number.isFinite(n) && n > 0) ids.push(n);
                else invalid.push(p);
            } else {
                invalid.push(p);
            }
        });

        // unique
        ids = Array.from(new Set(ids));
        return { ids, invalid };
    }

    function getSelectedCampaignIds() {
        // Nếu user đã chọn 1 phong trào từ dropdown (dataset.campaignId)
        const ds = ruleCampaignSearch?.dataset?.campaignId || "";
        const single = parseInt(ds, 10);

        // Nếu input có dấu phẩy => coi như multi
        const hasComma = String(ruleCampaignSearch?.value || "").includes(",");

        if (Number.isFinite(single) && single > 0 && !hasComma) {
            return { ids: [single], invalid: [] };
        }

        // Multi mode: parse theo dấu ,
        const parsed = parseCampaignIdsFromInputMulti(ruleCampaignSearch?.value || "");

        // nếu có ds mà chưa nằm trong list thì thêm vào (trường hợp lỡ chọn dropdown rồi gõ thêm)
        if (Number.isFinite(single) && single > 0 && !parsed.ids.includes(single)) {
            parsed.ids.unshift(single);
            parsed.ids = Array.from(new Set(parsed.ids));
        }

        return parsed;
    }


    function renderRuleItems() {
        if (!ruleItemCount || !ruleItemsBody) return;

        ruleItemCount.textContent = RULE_ITEMS.length;

        if (!RULE_ITEMS.length) {
            ruleItemsBody.innerHTML = `
        <tr>
          <td colspan="4" class="px-4 py-6 text-center text-gray-500">
            Chưa có phong trào bắt buộc nào.
          </td>
        </tr>
      `;
            if (btnSaveRule) btnSaveRule.disabled = true;
            return;
        }

        if (btnSaveRule) btnSaveRule.disabled = false;

        ruleItemsBody.innerHTML = RULE_ITEMS.map((it) => {
            const camp = findCampaignById(it.campaign_id);
            const title =
                camp?.title || it.title || "(Không tìm thấy tên phong trào)";
            const req = it.required_status || "excellent";

            return `
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3 font-semibold text-gray-800">#${esc(
                it.campaign_id
            )}</td>
          <td class="px-4 py-3 text-gray-800">${esc(title)}</td>
          <td class="px-4 py-3">
            <span class="px-2 py-1 rounded-lg text-xs font-bold ${req === "excellent"
                    ? "bg-green-100 text-green-700"
                    : "bg-blue-100 text-blue-700"
                }">
              ${esc(labelReq(req))}
            </span>
          </td>
          <td class="px-4 py-3 text-right">
            <button
              class="btnRemoveRuleItem px-3 py-1.5 rounded-lg border bg-white hover:bg-gray-50 text-xs font-semibold"
              data-id="${esc(it.campaign_id)}"
            >
              Xóa
            </button>
          </td>
        </tr>
      `;
        }).join("");

        document.querySelectorAll(".btnRemoveRuleItem").forEach((b) => {
            b.addEventListener("click", () => {
                const cid = parseInt(b.dataset.id, 10);
                RULE_ITEMS = RULE_ITEMS.filter((x) => Number(x.campaign_id) !== cid);
                renderRuleItems();
            });
        });
    }

    async function loadRule() {
        const title_id = ruleTitleSelect?.value || "";
        const school_year = ruleYearSelect?.value || "";
        const semester = ruleSemesterSelect?.value || "ALL";

        if (!title_id || !school_year) {
            toast("Chọn danh hiệu và năm học trước.", "error");
            return;
        }

        if (btnLoadRule) {
            btnLoadRule.disabled = true;
            btnLoadRule.textContent = "Đang tải...";
        }

        try {
            const qs =
                "?action=rule_get" +
                "&title_id=" +
                encodeURIComponent(title_id) +
                "&school_year=" +
                encodeURIComponent(school_year) +
                "&semester=" +
                encodeURIComponent(semester);

            const res = await fetch(BASE_URL + "controllers/award_suggest.php" + qs);
            const j = await res.json();
            if (!j?.ok) throw new Error(j?.error || "Lỗi tải tiêu chí");

            CURRENT_RULE = j.data.rule || null;
            const items = j.data.items || [];

            RULE_ITEMS = items.map((x) => ({
                campaign_id: parseInt(x.campaign_id, 10),
                required_status: x.required_status || "excellent",
                title: x.title || "",
            }));

            renderRuleItems();
            toast(
                CURRENT_RULE
                    ? "Đã tải tiêu chí hiện tại."
                    : "Chưa có tiêu chí, bạn tạo mới được luôn.",
                "success"
            );
        } catch (e) {
            toast("Lỗi tải tiêu chí: " + e.message, "error");
            RULE_ITEMS = [];
            renderRuleItems();
        } finally {
            if (btnLoadRule) {
                btnLoadRule.disabled = false;
                btnLoadRule.textContent = "Tải tiêu chí";
            }
        }
    }

    async function saveRule() {
        const title_id = ruleTitleSelect?.value || "";
        const school_year = ruleYearSelect?.value || "";
        const semester = ruleSemesterSelect?.value || "ALL";

        if (!title_id || !school_year) {
            toast("Chọn danh hiệu và năm học trước.", "error");
            return;
        }
        if (!RULE_ITEMS.length) {
            toast("Chưa có phong trào bắt buộc.", "error");
            return;
        }

        if (btnSaveRule) {
            btnSaveRule.disabled = true;
            btnSaveRule.textContent = "Đang lưu...";
        }

        try {
            const payload = {
                title_id: parseInt(title_id, 10),
                school_year,
                semester,
                min_required: "excellent",
                items: RULE_ITEMS.map((x) => ({
                    campaign_id: parseInt(x.campaign_id, 10),
                    required_status: x.required_status || "excellent",
                })),
            };

            const data = await api("rule_save", payload, "POST");
            toast(
                "Đã lưu tiêu chí thành công (rule_id=" + (data?.rule_id || "?") + ").",
                "success"
            );
        } catch (e) {
            toast("Lỗi lưu tiêu chí: " + e.message, "error");
        } finally {
            if (btnSaveRule) {
                btnSaveRule.disabled = false;
                btnSaveRule.textContent = "Lưu tiêu chí";
            }
        }
    }

    btnLoadRule?.addEventListener("click", loadRule);
    btnSaveRule?.addEventListener("click", saveRule);

    btnAddRuleItem?.addEventListener("click", () => {
        const req = ruleRequiredStatus?.value || "excellent";

        const tokens = getRuleTokensFromInput(ruleCampaignSearch.value);
        if (!tokens.length) {
            toast("Chưa nhập phong trào nào.", "error");
            return;
        }

        // ✅ ưu tiên dùng RC_SELECTED_IDS (đúng tuyệt đối dù trùng tên)
        let ids = [];
        if (RC_SELECTED_IDS.length >= tokens.length) {
            ids = RC_SELECTED_IDS.slice(0, tokens.length);
        } else {
            // fallback: nếu user tự gõ ID, vẫn parse được
            ids = tokens
                .map((t) => {
                    const m = String(t).trim().match(/^(\d+)/);
                    return m ? parseInt(m[1], 10) : 0;
                })
                .filter((x) => Number.isFinite(x) && x > 0);
        }

        ids = Array.from(new Set(ids));

        if (!ids.length) {
            toast("Không nhận diện được phong trào. Hãy chọn từ dropdown hoặc nhập ID.", "error");
            return;
        }

        let added = 0;
        let skipped = 0;

        ids.forEach((cid) => {
            if (RULE_ITEMS.some((x) => Number(x.campaign_id) === Number(cid))) {
                skipped++;
                return;
            }

            const camp = findCampaignById(cid);
            RULE_ITEMS.push({
                campaign_id: cid,
                required_status: req,
                title: camp?.title || "",
            });
            added++;
        });

        renderRuleItems();

        // reset input
        ruleCampaignSearch.value = "";
        RC_SELECTED_IDS = [];
        closeRuleDropdown();

        if (added && skipped) {
            toast("Đã thêm " + added + " phong trào (" + skipped + " cái đã có sẵn).", "success");
        } else if (added) {
            toast("Đã thêm " + added + " phong trào vào danh sách bắt buộc.", "success");
        } else {
            toast("Các phong trào này đã có sẵn trong danh sách.", "error");
        }
    });


    // ========= Init =========
    loadMeta();
})();
(function enhanceAllAwardSuggestSelects() {
  const esc = (s = "") =>
    String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");

  // ---- shared close manager (1 document click) ----
  const widgets = [];
  document.addEventListener("click", (e) => {
    widgets.forEach(w => {
      if (!w.dropdown) return;
      if (w.dropdown.contains(e.target) || w.input === e.target) return;
      w.close();
    });
  });

  function enhanceSelect(selectId, opts = {}) {
    const select = document.getElementById(selectId);
    if (!select) return null;

    const parent = select.parentElement;
    if (!parent) return null;

    // parent needs relative for absolute dropdown
    parent.classList.add("relative");

    const inputId = opts.inputId || `${selectId}Search`;
    const dropdownId = opts.dropdownId || `${selectId}Dropdown`;
    const listId = opts.listId || `${selectId}List`;

    // tránh init 2 lần
    if (document.getElementById(inputId)) return null;

    const input = document.createElement("input");
    input.type = "text";
    input.id = inputId;
    input.autocomplete = "off";

    // lấy placeholder: ưu tiên opts.placeholder, nếu không lấy option đầu
    const firstOptText = select.options?.[0]?.textContent?.trim() || "";
    input.placeholder = opts.placeholder || firstOptText || "Chọn...";

    // copy class từ select sang input (để nhìn giống nhau)
    input.className = select.className || "";
    // đảm bảo input không bị background xám do browser
    if (!input.className.includes("bg-")) input.classList.add("bg-white");

    // searchable = false => input readonly (dùng như select dropdown)
    const searchable = opts.searchable !== false;
    if (!searchable) input.readOnly = true;

    // dropdown
    const dropdown = document.createElement("div");
    dropdown.id = dropdownId;
    dropdown.className =
      "absolute left-0 top-full mt-2 w-full z-50 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden hidden";

    const list = document.createElement("div");
    list.id = listId;
    list.className = "max-h-72 overflow-auto";
    dropdown.appendChild(list);

    // hide select but keep it for value + change listeners
    select.classList.add("hidden");
    select.tabIndex = -1;

    // insert input before select, dropdown after input
    parent.insertBefore(input, select);
    parent.insertBefore(dropdown, select);

    let lastRendered = [];

    const open = () => dropdown.classList.remove("hidden");
    const close = () => dropdown.classList.add("hidden");

    function getItems() {
      return [...select.options].map(o => ({
        value: String(o.value ?? ""),
        title: String(o.textContent ?? "").trim(),
        disabled: !!o.disabled
      }));
    }

    function syncInputFromSelect() {
      const v = String(select.value || "");
      const opt = [...select.options].find(o => String(o.value) === v);
      // value rỗng => để trống input cho dễ gõ
      input.value = v ? (opt?.textContent?.trim() || "") : "";
    }

    function render(qText, forceFull = false) {
      const q = (qText || "").trim().toLowerCase();
      const items = getItems().filter(it => it.title); // bỏ option rỗng title

      const filtered = (!searchable || forceFull || !q)
        ? items
        : items.filter(it => it.title.toLowerCase().includes(q));

      lastRendered = filtered.slice(0, 80);

      list.innerHTML = lastRendered.map(it => {
        const dis = it.disabled ? "opacity-50 pointer-events-none" : "";
        return `
          <button type="button"
            class="w-full text-left px-3 py-2 hover:bg-gray-50 text-sm ${dis}"
            data-value="${esc(it.value)}"
            data-title="${esc(it.title)}">
            ${esc(it.title)}
          </button>
        `;
      }).join("") || `<div class="px-3 py-2 text-sm text-gray-500">Không tìm thấy</div>`;

      open();
    }

    // focus/click => show full list
    input.addEventListener("focus", () => render("", true));
    input.addEventListener("click", () => render("", true));

    // typing => filter
    input.addEventListener("input", () => {
      if (!searchable) return;
      render(input.value, false);

      // nếu đang gõ mà select đang có value cũ => reset về rỗng để tránh “kẹt filter”
      if (opts.resetOnTyping) {
        select.value = "";
        select.dispatchEvent(new Event("change", { bubbles: true }));
      }
    });

    // click chọn
    list.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-value]");
      if (!btn) return;

      const value = btn.dataset.value ?? "";
      const title = btn.dataset.title ?? "";

      select.value = value;
      select.dispatchEvent(new Event("change", { bubbles: true }));

      input.value = value ? title : "";
      close();
    });

    // keyboard
    input.addEventListener("keydown", (e) => {
      if (e.key === "Escape") return close();

      if (e.key === "Enter" && !dropdown.classList.contains("hidden")) {
        e.preventDefault();
        const first = lastRendered?.[0];
        if (!first) return;

        select.value = first.value;
        select.dispatchEvent(new Event("change", { bubbles: true }));

        input.value = first.value ? first.title : "";
        close();
      }
    });

    // nếu code khác set select.value => sync lại input
    select.addEventListener("change", syncInputFromSelect);

    // options load async (JS fill) => observer tự refresh + sync
    const mo = new MutationObserver(() => {
      // nếu dropdown đang mở thì render lại cho đúng data
      if (!dropdown.classList.contains("hidden")) {
        render(input.value, !searchable);
      }
      syncInputFromSelect();
    });
    mo.observe(select, { childList: true, subtree: true });

    // init
    syncInputFromSelect();

    const api = {
      input,
      dropdown,
      close,
      refresh: () => {
        syncInputFromSelect();
        if (!dropdown.classList.contains("hidden")) render(input.value, !searchable);
      }
    };

    widgets.push(api);
    return api;
  }

  // ===== APPLY: tất cả select trong award_suggest.php =====
  enhanceSelect("asTitleSelect",      { placeholder: "Chọn danh hiệu...", searchable: true });
  enhanceSelect("asYearSelect",       { placeholder: "Chọn năm học...", searchable: true });
  enhanceSelect("asSemesterSelect",   { placeholder: "Chọn học kỳ...", searchable: false });
  enhanceSelect("asStatusFilter",     { placeholder: "Trạng thái...", searchable: false });
  enhanceSelect("asPageSize",         { placeholder: "Số dòng...", searchable: false });

  enhanceSelect("ruleTitleSelect",    { placeholder: "Chọn danh hiệu...", searchable: true });
  enhanceSelect("ruleYearSelect",     { placeholder: "Chọn năm học...", searchable: true });
  enhanceSelect("ruleSemesterSelect", { placeholder: "Chọn học kỳ...", searchable: false });
  enhanceSelect("ruleRequiredStatus", { placeholder: "Mức yêu cầu...", searchable: false });
})();
