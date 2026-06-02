<?php
require_once __DIR__ . '/../config/auth.php';

if (!can('permissions', 'view')) {
  echo "<section class='p-6'>403 - Forbidden</section>";
  exit;
}

?>

<div class="flex">
  <main class="flex-1 p-6 bg-bg min-h-screen">

    <div class="w-full">

      <!-- Header -->
      <div class="mb-6">
        <h1 class="font-heading text-3xl font-bold">Quản lý tài khoản</h1>
        <p class="text-gray-500 mt-1 text-sm">Danh sách tài khoản hệ thống được tạo tự động khi thêm đoàn viên.</p>
      </div>

      <?php
      $rows = $pdo->query("
SELECT 
  u.id,
  u.username AS mssv,

  r.id   AS role_id,
  r.name AS role_name,

  COALESCE(m.fullname, u.fullname) AS fullname,
  c.name AS class_name,
  IF(m.id IS NULL, 0, 1) AS has_member

FROM users u
LEFT JOIN roles r ON r.id = u.role_id
LEFT JOIN members m ON m.user_id = u.id
LEFT JOIN classes c ON c.id = m.class_id

ORDER BY
  r.id,
  u.id
")->fetchAll();

      $roles = $pdo->query("
  SELECT id, name
  FROM roles
  WHERE is_active = 1
  ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

      ?>
      <!-- 🔍 Thanh tìm kiếm -->
      <div class="flex gap-3 mb-4">
        <input id="searchUser" class="flex-1 px-3 py-2 border rounded-lg min-w-0"
          placeholder="Tìm theo tài khoản, vai trò...">

        <select id="filterRole" class="px-3 py-2 border rounded-lg min-w-0">
          <option value="">Tất cả vai trò</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= $r['id'] ?>">
              <?= htmlspecialchars($r['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>


        <?php if (can('permissions', 'create')): ?>
          <button id="btnAddUser" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Thêm tài khoản
          </button>
        <?php endif; ?>

      </div>



      <!-- Table -->
      <div class="bg-card rounded-2xl shadow-card p-6 overflow-x-auto md:overflow-visible">
        <table class="border-collapse w-full md:min-w-full min-w-max" id="tableUsers">
          <thead class="bg-gray-50 text-xs text-subtext uppercase">
            <tr>
              <th class="px-3 py-2 text-left">MSSV</th>
              <th class="px-3 py-2 text-left">Họ tên</th>
              <th class="px-3 py-2 text-left">Lớp</th>
              <th class="px-3 py-2 text-left">Vai trò</th>
              <th class="px-3 py-2 text-right">Hành động</th>
            </tr>
          </thead>

          <tbody id="tbodyUsers"class="hidden">
            <?php foreach ($rows as $u): ?>
              <tr class="border-t hover:bg-gray-50">
                <!-- MSSV = username -->
                <td class="px-3 py-2 font-mono username">
                  <?= htmlspecialchars($u['mssv']) ?>
                </td>

                <td class="px-3 py-2 fullname" >
                  <?= htmlspecialchars($u['fullname'] ?? '—') ?>
                </td>

                <td class="px-3 py-2">
                  <?= htmlspecialchars($u['class_name'] ?? '—') ?>
                </td>

                <td class="px-3 py-2 role" data-role="<?= $u['role_id'] ?>">
                  <?= htmlspecialchars($u['role_name'] ?? '—') ?>
                </td>



                <td class="px-3 py-2 text-right">
                  <?php if (can('permissions', 'update')): ?>
                    <button class="px-3 py-1 bg-gray-100 rounded-lg hover:bg-gray-200 js-edit" data-id="<?= $u['id'] ?>"
                      data-username="<?= htmlspecialchars($u['mssv']) ?>" data-role-id="<?= (int) $u['role_id'] ?>"
                      data-fullname="<?= htmlspecialchars($u['fullname'] ?? '') ?>"
                      data-has-member="<?= $u['has_member'] ?>">
                      ✏ Sửa
                    </button>
                  <?php endif; ?>


                  <?php if (can('permissions', 'delete')): ?>
                    <button class="px-3 py-1 bg-red-500 text-white rounded-lg hover:bg-red-600 js-del"
                      data-id="<?= $u['id'] ?>">
                      🗑 Xóa
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>

        </table>
        <!-- PAGINATION -->
        <div id="paginationUsers" class="flex justify-center items-center gap-2 mt-4 select-none text-sm">

          <button data-act="first" class="px-2 py-1 border rounded hover:bg-gray-100">
            &laquo;
          </button>

          <button data-act="prev" class="px-2 py-1 border rounded hover:bg-gray-100">
            &lsaquo;
          </button>

          <input id="pageInputUsers" type="number" min="1" class="w-12 px-2 py-1 border rounded text-center" value="1">

          <span id="pageTotalUsers" class="text-gray-500">/ 1</span>

          <button data-act="next" class="px-2 py-1 border rounded hover:bg-gray-100">
            &rsaquo;
          </button>

          <button data-act="last" class="px-2 py-1 border rounded hover:bg-gray-100">
            &raquo;
          </button>
        </div>
      </div>
    </div>
  </main>
</div>

<div id="modalRoot" class="hidden fixed inset-0 bg-black/30 flex items-center justify-center z-[9999]"></div>

<script>
  window.__ROLES__ = <?= json_encode($roles) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/permissions.js?v=<?= time() ?>"></script>