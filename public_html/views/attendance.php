<div class="flex">

  <main class="flex-1 p-6 bg-bg min-h-screen">
    <div class="grid-container">
      <div class="mb-6">
        <h1 class="font-heading text-3xl font-bold">Điểm danh QR</h1>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-card p-6 rounded-2xl shadow-card">
          <h3 class="font-heading font-semibold mb-3">Check-in</h3>
          <div class="flex items-center gap-2 mb-3">
            <input id="code" class="flex-1 px-3 py-2 border rounded-lg" placeholder="Nhập mã sự kiện (EVT:xxxxxx)">
            <button id="btnCheckin" class="bg-secondary text-white px-4 py-2 rounded-lg">Điểm danh</button>
          </div>
          <div id="scanMsg" class="text-sm text-subtext"></div>
        </div>

        <div class="bg-card p-6 rounded-2xl shadow-card">
          <h3 class="font-heading font-semibold mb-3">Lịch sử</h3>
          <div id="history" class="space-y-2 max-h-96 overflow-y-auto"></div>
        </div>
      </div>

      <?php if (is_admin()): ?>
        <div class="bg-card p-6 rounded-2xl shadow-card mt-6">
          <h3 class="font-heading font-semibold mb-3">Tạo mã sự kiện</h3>
          <form id="evtForm" class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <input name="title" class="px-3 py-2 border rounded-lg md:col-span-2" placeholder="Tên sự kiện" required>
            <input name="starts_at" type="datetime-local" class="px-3 py-2 border rounded-lg" required>

            <input name="expires_at" type="datetime-local" class="px-3 py-2 border rounded-lg" required>

            <input type="hidden" name="lat" id="lat">
            <input type="hidden" name="lng" id="lng">
            <input type="hidden" name="address" id="addressInput">

            <button class="bg-primary text-white px-4 py-2 rounded-lg">Tạo mã</button>
          </form>
          <div id="evtList" class="text-sm text-subtext mt-3"></div>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
  function toast(m) { notify(m) }

  async function refreshLogs() {
    const res = await api('controllers/attendance.php?action=logs');
    const data = await res.json();
    const wrap = document.getElementById('history');
    wrap.innerHTML = data.map(l => `<div class="border rounded-lg p-2 flex items-center justify-between">
    <div class="text-sm">${l.time} • ${l.event_code} • ${l.fullname || l.username || ''}</div>
    <span class="text-xs px-2 py-1 rounded-full ${l.result === 'ok' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}">${l.result}</span>
  </div>`).join('') || '<div class="text-subtext">Chưa có bản ghi.</div>';
  }
  refreshLogs();

  document.getElementById('btnCheckin').onclick = async () => {
    const code = document.getElementById('code').value.trim();
    if (!code) return;
    const fd = new FormData(); fd.append('action', 'checkin'); fd.append('code', code);
    const res = await api('controllers/attendance.php', { method: 'POST', body: fd });
    const j = await res.json();
    if (j.ok) { toast('Điểm danh thành công'); refreshLogs(); } else { toast(j.error || 'Lỗi'); }
  };

  <?php if (is_admin()): ?>
    async function refreshEvents() {
      const res = await api('controllers/attendance.php?action=list_events');
      const ev = await res.json();
      document.getElementById('evtList').innerHTML = ev.map(e => `• ${e.title} – <b>${e.code}</b> – hết hạn: ${e.expires_at}
    <button class="ml-2 text-accent-red" onclick="delEvt(${e.id})">Xóa</button>`).join('<br>') || 'Chưa có sự kiện.';
    }
    refreshEvents();

    document.getElementById('evtForm').onsubmit = async (e) => {
      e.preventDefault();
      const f = new FormData(e.target);
      const code = 'EVT:' + Math.floor(100000 + Math.random() * 900000);
      f.append('action', 'create_event');
      f.append('code', code);
      const res = await api('controllers/attendance.php', { method: 'POST', body: f });
      const j = await res.json();
      if (j.ok) { toast('Đã tạo ' + code); e.target.reset(); refreshEvents(); } else { toast(j.error || 'Lỗi'); }
    };

    async function delEvt(id) {
      if (!confirm('Xóa sự kiện?')) return;
      const fd = new FormData(); fd.append('action', 'delete_event'); fd.append('id', id);
      const res = await api('controllers/attendance.php', { method: 'POST', body: fd });
      const j = await res.json(); if (j.ok) { refreshEvents(); } else toast(j.error || 'Lỗi');
    }
  <?php endif; ?>
</script>