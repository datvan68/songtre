// assets/js/statistics/members.js
(() => {
  if (window.__STATS_MEMBERS_READY__) return;
  window.__STATS_MEMBERS_READY__ = true;

  window.StatsModules = window.StatsModules || {};

  // ======================
  // BASE + API
  // ======================
  const BASE_URL = (() => {
    const b = String(window.BASE_URL || "/");
    return b.slice(-1) === "/" ? b : b + "/";
  })();

  const MEMBERS_API = "controllers/statistics/members.php";

  function buildApi(action, extra) {
    let u = MEMBERS_API + "?action=" + encodeURIComponent(action);
    if (extra) {
      for (const k in extra) {
        if (!Object.prototype.hasOwnProperty.call(extra, k)) continue;
        u += "&" + encodeURIComponent(k) + "=" + encodeURIComponent(String(extra[k]));
      }
    }
    return u;
  }

  // ======================
  // UTIL
  // ======================
  const fmt = (n) => Number(n || 0).toLocaleString("vi-VN");

  const safeNum = (v) => {
    const x = Number(v);
    return Number.isFinite(x) ? x : 0;
  };

  const pct = (a, b, digits = 0) => {
    a = safeNum(a);
    b = safeNum(b);
    if (b <= 0) return 0;
    const p = (a / b) * 100;
    const k = Math.pow(10, digits);
    return Math.round(p * k) / k;
  };

  // tránh replaceAll để ổn định
  function esc(s) {
    const t = s == null ? "" : String(s);
    return t
      .split("&").join("&amp;")
      .split("<").join("&lt;")
      .split(">").join("&gt;")
      .split('"').join("&quot;")
      .split("'").join("&#039;");
  }

  function createIcons() {
    try {
      if (window.lucide && typeof window.lucide.createIcons === "function") {
        window.lucide.createIcons();
      }
    } catch (e) { }
  }

  function url(path) {
    if (/^https?:\/\//i.test(path)) return path;
    if (path && path.charAt(0) === "/") return path;
    return BASE_URL + path;
  }

  const toneMap = {
    indigo: {
      iconWrap: "bg-indigo-50 text-indigo-700",
      badge: "bg-indigo-50 text-indigo-700 border-indigo-100",
      bar: "bg-indigo-600",
    },
    emerald: {
      iconWrap: "bg-emerald-50 text-emerald-700",
      badge: "bg-emerald-50 text-emerald-700 border-emerald-100",
      bar: "bg-emerald-600",
    },
    sky: {
      iconWrap: "bg-sky-50 text-sky-700",
      badge: "bg-sky-50 text-sky-700 border-sky-100",
      bar: "bg-sky-600",
    },
    amber: {
      iconWrap: "bg-amber-50 text-amber-800",
      badge: "bg-amber-50 text-amber-800 border-amber-100",
      bar: "bg-amber-600",
    },
    rose: {
      iconWrap: "bg-rose-50 text-rose-700",
      badge: "bg-rose-50 text-rose-700 border-rose-100",
      bar: "bg-rose-600",
    },
    cyan: {
      iconWrap: "bg-cyan-50 text-cyan-700",
      badge: "bg-cyan-50 text-cyan-700 border-cyan-100",
      bar: "bg-cyan-600",
    },
    gray: {
      iconWrap: "bg-gray-100 text-gray-700",
      badge: "bg-gray-50 text-gray-700 border-gray-200",
      bar: "bg-gray-500",
    },
  };

  function badge(text, tone) {
    const t = toneMap[tone] || toneMap.gray;
    return (
      '<span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold border ' +
      t.badge +
      '">' +
      esc(text) +
      "</span>"
    );
  }

  function card(o) {
    const title = o.title;
    const value = o.value;
    const sub = o.sub || "";
    const icon = o.icon || "bar-chart-3";
    const tone = o.tone || "indigo";
    const t = toneMap[tone] || toneMap.indigo;

    return `
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <div class="text-sm text-gray-500">${esc(title)}</div>
            <div class="mt-1 text-2xl font-bold text-gray-900 leading-tight">${esc(value)}</div>
            ${sub ? `<div class="mt-2 text-xs text-gray-500">${sub}</div>` : ""}
          </div>
          <div class="shrink-0 w-10 h-10 rounded-xl flex items-center justify-center ${t.iconWrap}">
            <i data-lucide="${esc(icon)}" class="w-5 h-5"></i>
          </div>
        </div>
      </div>
    `;
  }

  function infoBox(o) {
    const tone = o.tone || "gray";
    const title = o.title || "";
    const text = o.text || "";

    const map = {
      gray: { b: "border-gray-200", bg: "bg-gray-50", tx: "text-gray-700", t: "text-gray-900" },
      emerald: { b: "border-emerald-200", bg: "bg-emerald-50", tx: "text-emerald-800", t: "text-emerald-900" },
      amber: { b: "border-amber-200", bg: "bg-amber-50", tx: "text-amber-800", t: "text-amber-900" },
      rose: { b: "border-rose-200", bg: "bg-rose-50", tx: "text-rose-800", t: "text-rose-900" },
      sky: { b: "border-sky-200", bg: "bg-sky-50", tx: "text-sky-800", t: "text-sky-900" },
    };
    const s = map[tone] || map.gray;

    return `
      <div class="p-3 rounded-xl border ${s.b} ${s.bg} ${s.tx} text-sm">
        <div class="font-semibold ${s.t}">${esc(title)}</div>
        <div class="mt-1">${text}</div>
      </div>
    `;
  }

  function progressRow(o) {
    const label = o.label || "";
    const valueText = o.valueText || "";
    const percent = safeNum(o.percent);
    const tone = o.tone || "indigo";
    const t = toneMap[tone] || toneMap.indigo;
    const p = Math.max(0, Math.min(100, percent));

    return `
      <div>
        <div class="flex items-center justify-between text-sm">
          <span class="text-gray-700 font-medium">${esc(label)}</span>
          <span class="text-gray-600">${valueText}</span>
        </div>
        <div class="mt-2 h-2 rounded-full bg-gray-100 overflow-hidden">
          <div class="h-full ${t.bar}" style="width:${p}%;"></div>
        </div>
      </div>
    `;
  }

  function bindJump(panelEl) {
    const nodes = panelEl.querySelectorAll("[data-jump-tab]");
    for (let i = 0; i < nodes.length; i++) {
      const btn = nodes[i];
      btn.addEventListener("click", (e) => {
        e.preventDefault();
        const tab = btn.getAttribute("data-jump-tab");
        if (tab && typeof window.activateTab === "function") window.activateTab(tab, true);
      });
    }
  }

  // ======================
  // INSIGHTS (API)
  // ======================
  let INS_CACHE = null;
  let INS_LOADING = null;

  async function fetchJSONStrict(u) {
    const res = await fetch(u, {
      method: "GET",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    });

    const ct = (res.headers.get("content-type") || "").toLowerCase();
    const text = await res.text();

    if (!ct.includes("application/json")) {
      const snip = esc(text.slice(0, 220));
      throw new Error("Non-JSON response (HTTP " + res.status + "). " + (ct || "no-ct") + " | " + snip);
    }

    let json = null;
    try {
      json = JSON.parse(text);
    } catch (e) {
      const snip2 = esc(text.slice(0, 220));
      throw new Error("Invalid JSON (HTTP " + res.status + "). " + snip2);
    }
    return json;
  }

  async function getInsights(force) {
    if (INS_CACHE && !force) return INS_CACHE;
    if (INS_LOADING && !force) return INS_LOADING;

    INS_LOADING = (async () => {
      const json = await fetchJSONStrict(buildApi("members_insights"));
      if (!json || !json.ok) throw new Error((json && json.message) ? json.message : "Insights API error");
      INS_CACHE = json;
      INS_LOADING = null;
      return INS_CACHE;
    })().catch((e) => {
      INS_LOADING = null;
      throw e;
    });

    return INS_LOADING;
  }

  function pickMemberMetric(ins) {
    const rows = Array.isArray(ins && ins.top_classes) ? ins.top_classes : [];
    let sumMembers = 0;
    let sumPeople = 0;
    for (let i = 0; i < rows.length; i++) {
      sumMembers += safeNum(rows[i].members_count);
      sumPeople += safeNum(rows[i].total_people);
    }
    if (sumMembers > 0) return { key: "members_count", label: "Đoàn viên" };
    if (sumPeople > 0) return { key: "total_people", label: "Tổng người" };
    return { key: "members_count", label: "Đoàn viên" };
  }

  function renderRankTable(cfg) {
    const title = cfg.title;
    const icon = cfg.icon;
    const tone = cfg.tone;
    const rows = Array.isArray(cfg.rows) ? cfg.rows : [];
    const metricKey = cfg.metricKey;
    const metricLabel = cfg.metricLabel;
    const noteRight = cfg.noteRight || "";

    const t = toneMap[tone] || toneMap.gray;

    if (!rows.length) {
      return `
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
              <div class="w-9 h-9 rounded-xl flex items-center justify-center ${t.iconWrap}">
                <i data-lucide="${esc(icon)}" class="w-5 h-5"></i>
              </div>
              <div class="font-semibold text-gray-900">${esc(title)}</div>
            </div>
            <div class="text-xs text-gray-500">${esc(noteRight)}</div>
          </div>
          <div class="mt-4 text-sm text-gray-500 italic">Chưa có dữ liệu để xếp hạng.</div>
        </div>
      `;
    }

    let maxVal = 0;
    for (let i = 0; i < rows.length; i++) {
      const v = safeNum(rows[i][metricKey]);
      if (v > maxVal) maxVal = v;
    }

    let rowsHTML = "";
    for (let i = 0; i < rows.length; i++) {
      const r = rows[i];
      const name = r.class_name || r.dept_name || r.course_label || "-";
      const val = safeNum(r[metricKey]);
      const p = maxVal > 0 ? Math.round((val / maxVal) * 100) : 0;

      rowsHTML += `
        <tr class="border-t hover:bg-gray-50 transition">
          <td class="px-3 py-2 text-center text-gray-500 w-[8%]">${i + 1}</td>
          <td class="px-3 py-2">
            <div class="font-medium text-gray-900">${esc(name)}</div>
            ${r.dept_name && r.class_name
          ? `<div class="text-xs text-gray-500 mt-0.5">${esc(r.dept_name)}</div>`
          : ""
        }
            <div class="mt-2 h-1.5 rounded-full bg-gray-100 overflow-hidden">
              <div class="h-full ${t.bar}" style="width:${p}%;"></div>
            </div>
          </td>
          <td class="px-3 py-2 text-right font-semibold text-gray-900 w-[22%]">${fmt(val)}</td>
        </tr>
      `;
    }

    return `
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <div class="flex items-center justify-between gap-3">
          <div class="flex items-center gap-2">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center ${t.iconWrap}">
              <i data-lucide="${esc(icon)}" class="w-5 h-5"></i>
            </div>
            <div>
              <div class="font-semibold text-gray-900">${esc(title)}</div>
              <div class="text-xs text-gray-500">Theo ${esc(metricLabel)}</div>
            </div>
          </div>
          <div class="text-xs text-gray-500">${esc(noteRight)}</div>
        </div>

        <div class="mt-4 overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr>
                <th class="px-3 py-2 text-center w-[8%]">#</th>
                <th class="px-3 py-2 text-left">Đơn vị</th>
                <th class="px-3 py-2 text-right w-[22%]">${esc(metricLabel)}</th>
              </tr>
            </thead>
            <tbody>${rowsHTML}</tbody>
          </table>
        </div>
      </div>
    `;
  }

  function renderStatusByClassTable(rows, totalMembers) {
    const list = Array.isArray(rows) ? rows : [];
    if (!list.length) {
      return `
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="font-semibold text-gray-900">Bị khóa / Ngừng theo dõi theo lớp</div>
          <div class="mt-3 text-sm text-gray-500 italic">Chưa có dữ liệu trạng thái theo lớp.</div>
        </div>
      `;
    }

    let sumMembers = 0;
    for (let i = 0; i < list.length; i++) sumMembers += safeNum(list[i].members_count);
    const baseKey = sumMembers > 0 ? "members_count" : "total_people";

    let rowsHTML = "";
    for (let i = 0; i < list.length; i++) {
      const r = list[i];
      const base = safeNum(r[baseKey]);
      const locked = safeNum(r.locked_count);
      const stopped = safeNum(r.stopped_count);
      const issue = locked + stopped;
      const issuePct = base > 0 ? pct(issue, base, 0) : 0;
      const tone = issuePct >= 30 ? "rose" : issuePct >= 15 ? "amber" : "emerald";

      rowsHTML += `
        <tr class="border-t hover:bg-gray-50 transition">
          <td class="px-3 py-2 text-center text-gray-500">${i + 1}</td>
          <td class="px-3 py-2">
            <div class="font-medium text-gray-900">${esc(r.class_name || "-")}</div>
            <div class="text-xs text-gray-500 mt-0.5">
              Nền: ${baseKey === "members_count" ? "Đoàn viên" : "Tổng người"} = <b>${fmt(base)}</b>
            </div>
          </td>
          <td class="px-3 py-2 text-center">${fmt(locked)}</td>
          <td class="px-3 py-2 text-center">${fmt(stopped)}</td>
          <td class="px-3 py-2 text-right">${badge(issuePct + "%", tone)}</td>
        </tr>
      `;
    }

    return `
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <div class="flex items-center justify-between gap-3">
          <div class="font-semibold text-gray-900">Bị khóa / Ngừng theo dõi theo lớp</div>
          <div class="text-xs text-gray-500">Tổng đoàn viên (overview): ${fmt(totalMembers)}</div>
        </div>

        <div class="mt-4 overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
              <tr>
                <th class="px-3 py-2 text-center w-[6%]">#</th>
                <th class="px-3 py-2 text-left">Lớp</th>
                <th class="px-3 py-2 text-center w-[12%]">Bị khóa</th>
                <th class="px-3 py-2 text-center w-[16%]">Ngừng theo dõi</th>
                <th class="px-3 py-2 text-right w-[14%]">% vấn đề</th>
              </tr>
            </thead>
            <tbody>${rowsHTML}</tbody>
          </table>
        </div>

        <div class="mt-3 text-xs text-gray-500">
          “% vấn đề” = (bị khóa + ngừng theo dõi) / (đoàn viên hoặc tổng người theo lớp).
        </div>
      </div>
    `;
  }

  function renderInsights(ins, totalsFromOverview) {
    const metric = pickMemberMetric(ins);

    const topClasses = (ins && ins.top_classes) ? ins.top_classes : [];
    const topDepts = (ins && ins.top_depts) ? ins.top_depts : [];
    const courseStats = (ins && ins.course_stats) ? ins.course_stats : [];
    const statusByClass = (ins && ins.status_by_class) ? ins.status_by_class : [];

    const courseEnabled = safeNum(ins && ins.meta && ins.meta.course_enabled) === 1;
    const totalMembers = safeNum(totalsFromOverview && totalsFromOverview.totalMembers);

    return `
      <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        ${renderRankTable({
      title: "Top lớp đông đoàn viên nhất",
      icon: "graduation-cap",
      tone: "indigo",
      rows: topClasses,
      metricKey: metric.key,
      metricLabel: metric.label,
      noteRight: "Top 10",
    })}

        ${renderRankTable({
      title: "Top khoa/phòng đông đoàn viên nhất",
      icon: "building-2",
      tone: "sky",
      rows: topDepts,
      metricKey: metric.key,
      metricLabel: metric.label,
      noteRight: "Top 10",
    })}

        ${courseEnabled
        ? renderRankTable({
          title: "Thống kê theo khóa (course)",
          icon: "layers",
          tone: "emerald",
          rows: courseStats,
          metricKey: metric.key,
          metricLabel: metric.label,
          noteRight: "Top 20",
        })
        : `
              <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="flex items-center gap-2">
                  <div class="w-9 h-9 rounded-xl flex items-center justify-center ${toneMap.gray.iconWrap}">
                    <i data-lucide="layers" class="w-5 h-5"></i>
                  </div>
                  <div class="font-semibold text-gray-900">Thống kê theo khóa (course)</div>
                </div>
                <div class="mt-3 text-sm text-gray-600">
                  Backend chưa xác định được dữ liệu course. Kiểm tra schema lớp/khóa và trả về <code class="px-1 bg-gray-50 border rounded">meta.course_enabled=1</code>.
                </div>
              </div>
            `
      }
      </div>

      <div class="mt-4">
        ${renderStatusByClassTable(statusByClass, totalMembers)}
      </div>
    `;
  }

  // ======================
  // MAIN RENDER
  // ======================
  window.StatsModules.members = async (panelEl) => {
    const el =
      panelEl ||
      document.querySelector('[data-tab-panel="members"]') ||
      document.getElementById("tab-members");

    if (!el) return;

    const STATS = window.STATS || {};

    const totalMembers = safeNum(STATS.total_members);
    const totalYouth = safeNum(STATS.total_youth);
    const totalPeople = totalMembers + totalYouth;
    const totalUsers = safeNum(STATS.total_users);

    const memPct = totalPeople > 0 ? pct(totalMembers, totalPeople, 0) : 0;
    const youthPct = totalPeople > 0 ? 100 - memPct : 0;

    const coveragePct = totalPeople > 0 ? pct(totalUsers, totalPeople, 0) : 0;
    const accountsPer100 = totalPeople > 0 ? (totalUsers / totalPeople) * 100 : 0;

    const notes = [];
    const warns = [];

    // Cảnh báo thật sự: không có members hoặc không có users
    if (totalPeople === 0) {
      warns.push(
        infoBox({
          tone: "rose",
          title: "Chưa có dữ liệu thành viên",
          text: `Không có đoàn viên/thanh niên. Kiểm tra bảng <b>members</b> và quy trình import.`,
        })
      );
    }
    if (totalUsers === 0) {
      warns.push(
        infoBox({
          tone: "amber",
          title: "Chưa có tài khoản",
          text: `Chưa có dữ liệu <b>users</b>. Nếu hệ thống mới, cần seed/admin trước.`,
        })
      );
    }

    // NOTE (không phải cảnh báo): users > people là bình thường nếu có tài khoản hệ thống/giáo viên/admin
    if (totalPeople > 0 && totalUsers > totalPeople) {
      const extra = totalUsers - totalPeople;
      notes.push(
        infoBox({
          tone: "sky",
          title: "Giải thích số liệu tài khoản",
          text: `Hệ thống có thể có tài khoản <b>admin/giáo viên/tài khoản kỹ thuật</b> không liên kết với <b>members</b>.
            Hiện đang dư khoảng <b>${fmt(extra)}</b> tài khoản so với tổng người.`,
        })
      );
    }

    // Gợi ý tốt hơn: phủ tài khoản thấp mới là vấn đề vận hành
    if (totalPeople > 0 && pct(totalUsers, totalPeople, 0) < 50) {
      notes.push(
        infoBox({
          tone: "amber",
          title: "Phủ tài khoản còn thấp",
          text: `Tỷ lệ phủ tài khoản đang <b>${pct(totalUsers, totalPeople, 0)}%</b>. Có thể cần tạo/đồng bộ users cho đoàn viên/thanh niên.`,
        })
      );
    }

    const okBox =
      warns.length === 0
        ? infoBox({
          tone: "emerald",
          title: "Dữ liệu cơ bản ổn",
          text: `Đã bật thống kê nâng cao: Top lớp/khoa, theo khóa, và trạng thái theo lớp.`,
        })
        : "";


    el.innerHTML = `
      <div class="mb-6">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
          <div>
            <h2 class="text-xl font-bold text-gray-900">Đoàn viên – Thanh niên</h2>
            <p class="mt-1 text-sm text-gray-500">
              Tổng hợp cơ cấu và thống kê nâng cao theo lớp/khoa/khóa, trạng thái khóa/ngừng theo dõi.
            </p>
            <div class="mt-2 flex items-center gap-2 flex-wrap">
              ${badge("Tổng người: " + fmt(totalPeople), "indigo")}
              ${badge("Đoàn viên: " + fmt(totalMembers) + " (" + memPct + "%)", "emerald")}
              ${badge("Thanh niên: " + fmt(totalYouth) + " (" + youthPct + "%)", "sky")}
            </div>
          </div>

          <div class="flex gap-2 flex-wrap justify-end">
            <a href="${esc(url("index.php?p=members"))}"
              class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 transition">
              Danh sách
            </a>
            <button type="button" id="btnMembersRefresh"
              class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-semibold hover:bg-gray-50 transition">
              Làm mới
            </button>
            <button type="button" data-jump-tab="overview"
              class="px-4 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-semibold hover:bg-gray-50 transition">
              Về tổng quan
            </button>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        ${card({ title: "Tổng người", value: fmt(totalPeople), sub: "Đoàn viên + Thanh niên", icon: "users", tone: "indigo" })}
        ${card({ title: "Đoàn viên", value: fmt(totalMembers), sub: "Tỷ lệ: <b>" + memPct + "%</b>", icon: "user-check", tone: "emerald" })}
        ${card({ title: "Thanh niên", value: fmt(totalYouth), sub: "Tỷ lệ: <b>" + youthPct + "%</b>", icon: "user", tone: "sky" })}
        ${card({
      title: "Phủ tài khoản",
      value: fmt(totalUsers) + " / " + fmt(totalPeople),
      sub: "Ước tính: <b>" + coveragePct + "%</b> • " + accountsPer100.toFixed(1) + " tài khoản / 100 người",
      icon: "shield-check",
      tone: coveragePct >= 70 ? "emerald" : coveragePct >= 40 ? "amber" : "rose",
    })}
      </div>

      <div class="mt-6 grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between">
            <div class="font-semibold text-gray-900">Cơ cấu</div>
            <div class="text-xs text-gray-500">Tổng: ${fmt(totalPeople)}</div>
          </div>
          <div class="mt-5 space-y-4">
            ${progressRow({ label: "Đoàn viên", valueText: fmt(totalMembers) + " (" + memPct + "%)", percent: memPct, tone: "emerald" })}
            ${progressRow({ label: "Thanh niên", valueText: fmt(totalYouth) + " (" + youthPct + "%)", percent: youthPct, tone: "sky" })}
            <div class="pt-3 border-t text-xs text-gray-500">
              Gợi ý: nếu tỷ lệ bất thường, kiểm tra quy ước <code class="px-1 bg-gray-50 border rounded">members.type</code>.
            </div>
          </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="font-semibold text-gray-900">Chỉ số vận hành</div>
          <div class="mt-5 space-y-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <div class="text-sm font-medium text-gray-800">Tỷ lệ phủ tài khoản</div>
                <div class="text-xs text-gray-500">users / (members + youth)</div>
              </div>
              <div class="text-lg font-bold text-gray-900">${coveragePct}%</div>
            </div>
            <div class="flex items-start justify-between gap-4">
              <div>
                <div class="text-sm font-medium text-gray-800">Tài khoản / 100 người</div>
                <div class="text-xs text-gray-500">Chuẩn hoá theo quy mô</div>
              </div>
              <div class="text-lg font-bold text-gray-900">${accountsPer100.toFixed(1)}</div>
            </div>

          </div>
        </div>

<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
  <div class="font-semibold text-gray-900">Ghi chú hệ thống</div>
  <div class="mt-2 text-xs text-gray-500">
    Các trường hợp tài khoản không nằm trong <code class="px-1 bg-gray-50 border rounded">members</code> là bình thường.
  </div>
  <div class="mt-4 space-y-3">
    ${warns.join("")}
    ${notes.join("")}
    ${okBox}
  </div>
</div>

      </div>

      <div class="mt-6" id="members-insights">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
          <div class="text-sm text-gray-500">Đang tải thống kê nâng cao...</div>
        </div>
      </div>
    `;

    bindJump(el);

    const wrap = el.querySelector("#members-insights");

    const reload = async (force) => {
      try {
        wrap.innerHTML = `
          <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div class="text-sm text-gray-500">Đang tải thống kê nâng cao...</div>
          </div>
        `;
        const ins = await getInsights(!!force);
        wrap.innerHTML = renderInsights(ins, { totalMembers: totalMembers });
        createIcons();
      } catch (e) {
        const msg = (e && e.message) ? e.message : String(e);
        wrap.innerHTML = infoBox({
          tone: "rose",
          title: "Không thể tải thống kê nâng cao",
          text:
            `<div class="text-sm">
              <div>Vui lòng kiểm tra:</div>
              <ul class="list-disc pl-5 mt-1 space-y-1">
                <li>Đúng URL: <code class="px-1 bg-white border rounded">` + esc(buildApi("members_insights")) + `</code></li>
                <li>Backend trả JSON (<code class="px-1 bg-white border rounded">{"ok":true}</code>)</li>
                <li>Session/login (fetch cần cookie)</li>
              </ul>
              <div class="mt-2 text-xs text-rose-700 break-words">` + esc(msg) + `</div>
            </div>`
        });
      }
    };

    const btn = el.querySelector("#btnMembersRefresh");
    if (btn) btn.addEventListener("click", () => reload(true));

    await reload(false);
    createIcons();
  };
})();
