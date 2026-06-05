<?php /* components/confirm_modal.php
   Reusable confirmation modal for the whole app.
   Usage in JS:
     // Simple confirm
     const { confirmed } = await window.showConfirmModal({
       title: 'Xóa báo cáo',
       message: 'Bạn có chắc muốn xóa báo cáo này? Hành động không thể hoàn tác.',
       confirmText: 'Xóa',
       cancelText: 'Hủy',
       danger: true
     });
     if (confirmed) { ... }

     // With input (for notes etc.)
     const { confirmed, value: note } = await window.showConfirmModal({
       title: 'Duyệt báo cáo',
       message: 'Nhập ghi chú duyệt (tùy chọn):',
       confirmText: 'Duyệt',
       cancelText: 'Hủy',
       input: {
         label: 'Ghi chú',
         placeholder: 'Nhập ghi chú...',
         type: 'textarea',
         defaultValue: ''
       }
     });
     if (confirmed) { ... use note ... }
*/ ?>
<div id="confirm-modal" class="hidden fixed inset-0 bg-black/50 z-[99999] flex items-center justify-center p-4" onclick="if (event.target.id === 'confirm-modal' && window.__closeConfirmModal) window.__closeConfirmModal(false)">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md" onclick="event.stopImmediatePropagation()">
    <div class="px-6 pt-6 pb-4">
      <div class="flex items-start gap-3">
        <div id="confirm-icon" class="mt-1 text-2xl"></div>
        <div class="flex-1">
          <h3 id="confirm-title" class="text-lg font-semibold text-gray-800"></h3>
          <div id="confirm-message" class="mt-2 text-sm text-gray-600 leading-relaxed"></div>

          <!-- Optional input for notes etc. -->
          <div id="confirm-input-wrapper" class="mt-4 hidden">
            <label id="confirm-input-label" class="block text-xs font-medium text-gray-600 mb-1"></label>
            <textarea id="confirm-input" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:border-blue-500 resize-y"></textarea>
          </div>
        </div>
      </div>
    </div>
    <div class="px-6 py-4 bg-gray-50 rounded-b-3xl flex justify-end gap-3">
      <button id="confirm-cancel"
              class="px-4 py-2 text-sm border border-gray-300 hover:bg-white rounded-2xl transition-colors font-medium">
        Hủy
      </button>
      <button id="confirm-ok"
              class="px-4 py-2 text-sm text-white rounded-2xl transition-colors font-medium">
        Xác nhận
      </button>
    </div>
  </div>
</div>

<script>
(function () {
  if (window.__CONFIRM_MODAL_INITED__) return;
  window.__CONFIRM_MODAL_INITED__ = true;

  let resolveFn = null;

  function get(id) { return document.getElementById(id); }

  function closeModal(confirmed) {
    const m = get('confirm-modal');
    if (!m) return;

    const inputWrapper = get('confirm-input-wrapper');
    const inputEl = get('confirm-input');
    let value = undefined;
    if (inputWrapper && !inputWrapper.classList.contains('hidden') && inputEl) {
      value = inputEl.value || '';
    }

    m.classList.remove('flex');
    m.classList.add('hidden');

    const cancelBtn = get('confirm-cancel');
    const okBtn = get('confirm-ok');
    if (cancelBtn) cancelBtn.onclick = null;
    if (okBtn) okBtn.onclick = null;
    if (inputEl) inputEl.value = '';

    const r = resolveFn;
    resolveFn = null;
    if (r) r({ confirmed: !!confirmed, value });
  }

  window.showConfirmModal = function (opts = {}) {
    return new Promise((resolve) => {
      const m = get('confirm-modal');
      if (!m) {
        console.warn('Confirm modal not present in DOM. Falling back to native confirm.');
        const fb = confirm(opts.message || 'Xác nhận?');
        return resolve({ confirmed: fb, value: undefined });
      }
      resolveFn = resolve;

      get('confirm-title').textContent = opts.title || 'Xác nhận';
      get('confirm-message').innerHTML = opts.message || 'Bạn có chắc chắn muốn thực hiện tác vụ này?';

      const cancelBtn = get('confirm-cancel');
      const okBtn = get('confirm-ok');

      cancelBtn.textContent = opts.cancelText || 'Hủy';
      okBtn.textContent = opts.confirmText || 'Xác nhận';

      const icon = get('confirm-icon');
      if (opts.danger) {
        icon.innerHTML = '<span class="text-2xl">⚠️</span>';
        okBtn.className = 'px-4 py-2 text-sm bg-red-600 hover:bg-red-700 text-white rounded-2xl transition-colors font-medium';
      } else {
        icon.innerHTML = '<span class="text-2xl">❓</span>';
        okBtn.className = 'px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-2xl transition-colors font-medium';
      }

      // Handle optional input
      const inputWrapper = get('confirm-input-wrapper');
      const inputEl = get('confirm-input');
      const inputLabel = get('confirm-input-label');
      const hasInput = !!(opts.input);
      if (hasInput && inputWrapper && inputEl) {
        inputWrapper.classList.remove('hidden');
        inputLabel.textContent = (opts.input.label || 'Nội dung') + (opts.input.required ? ' *' : '');
        inputEl.placeholder = opts.input.placeholder || '';
        inputEl.value = opts.input.defaultValue || '';
        if (opts.input.type === 'text') {
          inputEl.rows = 1;
        } else {
          inputEl.rows = opts.input.rows || 3;
        }
        // focus after show
        setTimeout(() => inputEl.focus(), 50);
      } else if (inputWrapper) {
        inputWrapper.classList.add('hidden');
      }

      m.classList.remove('hidden');
      m.classList.add('flex');

      const doConfirm = () => {
        if (hasInput && opts.input && opts.input.required && inputEl && !inputEl.value.trim()) {
          alert('Vui lòng nhập nội dung!');
          return;
        }
        closeModal(true);
      };

      cancelBtn.onclick = () => closeModal(false);
      okBtn.onclick = doConfirm;

      // ESC to cancel
      const onEsc = (e) => {
        if (e.key === 'Escape') {
          document.removeEventListener('keydown', onEsc);
          closeModal(false);
        }
      };
      document.addEventListener('keydown', onEsc, { once: true });

      window.__closeConfirmModal = (val) => {
        document.removeEventListener('keydown', onEsc);
        closeModal(!!val);
      };
    });
  };
})();
</script>
