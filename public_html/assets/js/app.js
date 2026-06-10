// Polyfill String.prototype.contains cho các SDK bên thứ ba (như Zalo SDK ztr.js)
if (!String.prototype.contains) {
  String.prototype.contains = String.prototype.includes;
}

function api(path, options = {}) {
  const base = window.BASE_URL.replace(/\/+$/, "");
  const p = path.replace(/^\/+/, "");
  return fetch(`${base}/${p}`, options);
}

function apiFetch(path, options = {}) {
  const base = window.BASE_URL.replace(/\/+$/, "");
  const p = path.replace(/^\/+/, "");
  return fetch(`${base}/${p}`, options).then(res => res.json());
}


// ====================================================================
// ✅ TOAST THÔNG BÁO – FIXED TOP RIGHT (KHÔNG PHỤ THUỘC SCROLL)
// ====================================================================
function toast(message, type = "info", duration = 2500, opts = {}) {

  // --- Tạo container nếu chưa có
  let container = document.getElementById("toast-container");
  if (!container) {
    container = document.createElement("div");
    container.id = "toast-container";

    container.style.position = "fixed";
    container.style.top = "1rem";
    container.style.right = "1rem";
    container.style.zIndex = "9999";
    container.style.pointerEvents = "none";

    container.style.display = "flex";
    container.style.flexDirection = "column";
    container.style.gap = "8px";

    document.body.appendChild(container);
  }


  // --- Màu theo loại thông báo
  const colors = {
    success: "bg-green-600 text-white",
    error: "bg-red-600 text-white",
    info: "bg-blue-600 text-white",
    warning: "bg-yellow-500 text-black",
  };

  const color = colors[type] || colors.info;

  // --- Tạo toast item
  const el = document.createElement("div");
  el.className = `
    px-4 py-2 rounded-lg shadow-lg
    pointer-events-auto
    animate-toast-in
    ${color}
  `;

  // ✅ SUPPORT HTML
  if (opts.html) el.innerHTML = message;
  else el.textContent = message;

  container.appendChild(el);

  // ✅ AUTO CLOSE
  if (duration > 0) {
    setTimeout(() => {
      el.classList.add("animate-toast-out");
      setTimeout(() => el.remove(), 300);
    }, duration);
  }

  // ✅ QUAN TRỌNG: TRẢ ELEMENT
  return el;
}



// ====================================================================
// ✅ CSS animation fadeIn/fadeOut
// ====================================================================
const style = document.createElement("style");
style.textContent = `
@keyframes toastIn {
  from {
    opacity: 0;
    transform: translateY(-10px) scale(0.95);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@keyframes toastOut {
  from {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  to {
    opacity: 0;
    transform: translateY(-10px) scale(0.95);
  }
}

.animate-toast-in {
  animation: toastIn 0.25s ease-out forwards;
}

.animate-toast-out {
  animation: toastOut 0.2s ease-in forwards;
}
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-10px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes fadeOut {
  from { opacity: 1; transform: translateY(0); }
  to   { opacity: 0; transform: translateY(-10px); }
}
.animate-fade-in  { animation: fadeIn 0.3s ease-out forwards; }
.animate-fade-out { animation: fadeOut 0.3s ease-in forwards; }
`;
document.head.appendChild(style);

const MODAL_STACK = [];

// ====================================================================
// ✅ MODAL CHUNG (FIX + SUPPORT noHeader)
// ====================================================================
function modal(content, title = "", size = "default", options = {}) {
  const { noHeader = false } = options;

  const modalIndex = MODAL_STACK.length;


  // Overlay
  const root = document.createElement("div");
  root.className =
    "app-modal fixed inset-0 bg-black/50 flex items-center justify-center animate-fade-in";
  root.style.zIndex = 1000 + modalIndex * 10;


  // Width
  const widthClass =
    size === "small"
      ? "w-[min(380px,90vw)]"
      : size === "medium"
        ? "w-[min(520px,90vw)]"
        : size === "large"
          ? "w-[min(860px,92vw)]"
          : "w-[min(520px,90vw)]";

  // Modal box
  root.innerHTML = `
    <div id="modalBox"
      class="bg-white rounded-2xl shadow-card border
             ${noHeader ? "p-6" : "p-4"}
             ${widthClass}
             max-h-[88vh] overflow-auto animate-fade-in">

      ${noHeader
      ? ""
      : `
        <div class="flex items-center justify-between mb-2">
          <div class="font-heading font-semibold">${title}</div>
          <button class="text-gray-500 hover:text-black px-2 py-1" id="modalCloseBtn">✕</button>
        </div>
      `
    }

      <div class="modal-content"></div>
    </div>
  `;

  document.body.appendChild(root);

  const box = root.querySelector("#modalBox");
  const container = root.querySelector(".modal-content");

  // Inject content
  if (content instanceof Node) container.appendChild(content);
  else container.innerHTML = content;

  // Ngăn click trong modal
  box.addEventListener("click", (e) => e.stopPropagation());

  // ESC – luôn cho
  const escHandler = (e) => {
    if (e.key === "Escape") closeModal();
  };
  document.addEventListener("keydown", escHandler);

  // Click backdrop – luôn cho
  // root.addEventListener("click", (e) => {
  //   if (e.target === root && MODAL_STACK.at(-1) === root) {
  //     closeModal();
  //   }
  // });



  // Close button
  if (!noHeader) {
    root.querySelector("#modalCloseBtn").onclick = () => closeModal();
  }

  // Cleanup
  root.cleanup = () => {
    document.removeEventListener("keydown", escHandler);
  };

  // =========================
  // ⌨ ENTER = primary action
  // =========================
  const enterHandler = (e) => {
    if (e.key !== "Enter") return;

    // Không bắt Enter trong textarea
    if (e.target.tagName === "TEXTAREA") return;

    // Tìm nút ưu tiên
    const primaryBtn =
      box.querySelector("[data-primary]") ||
      box.querySelector('button[type="submit"]');

    if (primaryBtn) {
      e.preventDefault();
      primaryBtn.click();
    }
  };

  document.addEventListener("keydown", enterHandler);

  // cleanup thêm
  const oldCleanup = root.cleanup;
  root.cleanup = () => {
    oldCleanup?.();
    document.removeEventListener("keydown", enterHandler);
  };
  MODAL_STACK.push(root);

}



// =========================
// Close modal
// =========================
function closeModal() {
  const modal = MODAL_STACK.pop();
  if (!modal) return;

  modal.cleanup?.();
  modal.remove();
}



// ====================================================================
// ✅ NOTIFY POPUP (modal nhỏ)
// ====================================================================
window.notify = (title, msg, type = "success") => {
  openNotify(title, msg, type, false);
};

window.notifyReload = (title, msg, type = "success") => {
  openNotify(title, msg, type, true);
};

function openNotify(title, message, type, reload) {
  const modal = document.getElementById("notifyModal");
  const titleEl = document.getElementById("notifyTitle");
  const msgEl = document.getElementById("notifyMessage");
  const closeBtn = document.getElementById("notifyClose");

  titleEl.textContent = title;
  msgEl.textContent = message;

  titleEl.className =
    "text-lg font-semibold mb-3 " +
    (type === "error" ? "text-red-600" : "text-green-600");

  modal.classList.remove("hidden");

  // ==========================
  // CLOSE HANDLERS
  // ==========================
  const closeNotify = () => {
    modal.classList.add("hidden");

    document.removeEventListener("keydown", escHandler);
    modal.removeEventListener("click", backdropHandler);

    if (reload) location.reload();
  };

  // ❌ nút đóng
  closeBtn.onclick = closeNotify;

  // ⌨ ESC
  const escHandler = (e) => {
    if (e.key === "Escape" && MODAL_STACK.at(-1) === root) {
      closeModal();
    }
  };

  document.addEventListener("keydown", escHandler);

  // 🖱 click ra ngoài box (backdrop)
  const backdropHandler = (e) => {
    // chỉ đóng khi click đúng backdrop, không phải content
    if (e.target === modal) {
      closeNotify();
    }
  };
  modal.addEventListener("click", backdropHandler);
}

