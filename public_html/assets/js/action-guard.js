// ======================================
// GENERIC IN-FLIGHT ACTION GUARD
// ======================================
const actionRunning = new Map();

document.addEventListener("click", async (e) => {
  const btn = e.target.closest("[data-action]");
  if (!btn) return;

  const action = btn.dataset.action;
  if (!action) return;

  // Key phân biệt action + button cụ thể
  const key = action + "::" + (btn.dataset.key || "");

  // Đang chạy → bỏ qua
  if (actionRunning.has(key)) {
    e.preventDefault();
    return;
  }

  actionRunning.set(key, true);

  try {
    // GỌI HANDLER GẮN TRÊN BUTTON
    const handlerName = btn.dataset.handler;
    if (!handlerName || typeof window[handlerName] !== "function") {
      console.warn("No handler for action:", action);
      return;
    }

    await window[handlerName](btn);
  } finally {
    actionRunning.delete(key);
  }
});
