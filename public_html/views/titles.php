<section>
  <div class="grid-container">
    <div class="flex items-center justify-between mb-6">
      <h1 class="font-heading text-3xl font-bold">Xét danh hiệu</h1>
      <?php if (is_admin()): ?>
        <button id="btnAddTitle" class="bg-primary text-white px-4 py-2 rounded-lg hover:bg-blue-800">+ Thêm</button>
      <?php endif; ?>
    </div>

    <div class="bg-card rounded-2xl shadow-card overflow-x-auto">
      <table class="w-full">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Mã</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Nhóm</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Tên</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Tiêu chí</th>
            <th class="px-3 py-2 text-left text-xs font-medium text-subtext uppercase">Minh chứng</th>
            <?php if (is_admin()): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach($pdo->query("SELECT * FROM titles ORDER BY grp, name") as $t): ?>
            <tr class="border-t">
              <td class="px-3 py-2"><?= htmlspecialchars($t['code']) ?></td>
              <td class="px-3 py-2"><?= htmlspecialchars($t['grp']) ?></td>
              <td class="px-3 py-2"><?= htmlspecialchars($t['name']) ?></td>
              <td class="px-3 py-2"><?= htmlspecialchars($t['criteria']) ?></td>
              <td class="px-3 py-2"><?= htmlspecialchars($t['evidence']) ?></td>
              <?php if (is_admin()): ?>
              <td class="px-3 py-2 text-right">
                <button class="px-3 py-1 bg-gray-100 rounded-lg mr-1 js-edit" data-id="<?= (int)$t['id'] ?>">Sửa</button>
                <button class="px-3 py-1 bg-accent-red text-white rounded-lg js-del" data-id="<?= (int)$t['id'] ?>">Xóa</button>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script>
document.getElementById('btnAddTitle')?.addEventListener('click',()=>openTitleModal());
document.querySelectorAll('.js-edit').forEach(btn=>btn.addEventListener('click',()=>openTitleModal(btn.dataset.id)));
document.querySelectorAll('.js-del').forEach(btn=>btn.addEventListener('click',async ()=>{
  if(!confirm('Xóa danh hiệu?')) return;
  const fd = new FormData(); fd.append('action','delete'); fd.append('id',btn.dataset.id);
  const res = await fetch('controllers/titles.php',{method:'POST',body:fd}); const j=await res.json();
  if(j.ok) location.reload(); else toast(j.error||'Lỗi');
}));

async function openTitleModal(id){
  const wrap=document.createElement('div');
  wrap.innerHTML = `
  <form id="titleForm" class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <input type="hidden" name="action" value="${id?'update':'create'}">
    ${id? `<input type="hidden" name="id" value="${id}">` : `<div><label class="block text-sm">Mã</label><input name="code" required class="w-full px-3 py-2 border rounded-lg" value="TD${Math.floor(1000+Math.random()*9000)}"></div>`}
    <div>
      <label class="block text-sm">Nhóm</label>
      <select name="grp" class="w-full px-3 py-2 border rounded-lg">
        <option>Thi đua</option><option>Khen thưởng</option>
      </select>
    </div>
    <div class="md:col-span-2"><label class="block text-sm">Tên</label><input name="name" required class="w-full px-3 py-2 border rounded-lg"></div>
    <div class="md:col-span-2"><label class="block text-sm">Tiêu chí</label><textarea name="criteria" class="w-full px-3 py-2 border rounded-lg"></textarea></div>
    <div class="md:col-span-2"><label class="block text-sm">Minh chứng yêu cầu</label><input name="evidence" class="w-full px-3 py-2 border rounded-lg"></div>
    <div class="md:col-span-2 flex justify-end gap-2 mt-2">
      <button type="button" class="px-6 py-2 border rounded-lg" onclick="closeModal()">Hủy</button>
      <button class="px-6 py-2 bg-secondary text-white rounded-lg">Lưu</button>
    </div>
  </form>`;
  modal(wrap,'Thêm/Sửa danh hiệu');

  wrap.querySelector('#titleForm').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await fetch('controllers/titles.php',{method:'POST', body:fd});
    const j = await res.json();
    if(j.ok) location.reload(); else toast(j.error||'Lỗi');
  });
}
</script>
