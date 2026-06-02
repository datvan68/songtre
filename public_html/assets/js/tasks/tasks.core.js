// assets/js/tasks/tasks.core.js
const TASKS_API = BASE_URL + 'controllers/tasks.php';

const Tasks = {
  view: document.getElementById('tasks-app')?.dataset.view || 'user',

  toast(m) {
    if (typeof window.toast === 'function') return window.toast(m);
    if (typeof window.notify === 'function') return window.notify(m);
    alert(m);
  },

  qs(sel, root = document) {
    return root.querySelector(sel);
  },

  escape(s = "") {
    return String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  },

  fmtDT(s) {
    if (!s) return "";
    const iso = String(s).replace(" ", "T");
    const d = new Date(iso);
    if (isNaN(d.getTime())) return String(s);
    const pad = (n) => String(n).padStart(2, "0");
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  },

  toDTLocal(s) {
    if (!s) return "";
    return String(s).replace(" ", "T").slice(0, 16);
  },

  badgeStatus(st) {
    if (st === "done") return `<span class="px-2 py-1 rounded-full text-xs bg-green-100 text-green-700">Hoàn thành</span>`;
    if (st === "doing") return `<span class="px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-700">Đang làm</span>`;
    return `<span class="px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700">Chưa làm</span>`;
  },

  badgePriority(p) {
    if (p === "high") return `<span class="px-2 py-1 rounded-full text-xs bg-red-100 text-red-700">Cao</span>`;
    if (p === "low") return `<span class="px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700">Thấp</span>`;
    return `<span class="px-2 py-1 rounded-full text-xs bg-yellow-100 text-yellow-700">Trung bình</span>`;
  },

  async api(action, data = null, method = 'POST') {
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin'
    };
    if (data !== null) opts.body = JSON.stringify(data);

    // GET thì không gửi body
    const url = `${TASKS_API}?action=${encodeURIComponent(action)}`;
    const res = await fetch(url, method === 'GET' ? { method: 'GET', credentials: 'same-origin' } : opts);

    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      console.error('Tasks API không phải JSON:', text);
      throw new Error('Invalid JSON');
    }
  }
};
window.Tasks = Tasks;
