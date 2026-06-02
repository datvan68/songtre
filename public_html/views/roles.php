<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

auth_guard();
if (!can('roles', 'view')) {
  http_response_code(403);
  echo "<section class='p-6 text-red-600 font-semibold'>
    403 – Bạn không có quyền truy cập trang này.
  </section>";
  exit;
}

$roles = $pdo->query("
  SELECT id,  name, description, is_active
  FROM roles
  ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="flex min-w-0">
  <main class="flex-1 p-6 bg-bg min-h-screen min-w-0">

    <div class="w-full">

      <!-- Header -->
      <div class="mb-6">
        <h1 class="font-heading text-3xl font-bold">Quản lý Role</h1>
        <p class="text-gray-500 mt-1 text-sm">
          Quản lý vai trò hệ thống và phân quyền mặc định cho từng role.
        </p>
      </div>

      <!-- Actions -->
      <div class="flex justify-end mb-4">
        <?php if (can('roles', 'create')): ?>
          <button id="btnAddRole" class="px-4 py-2 bg-blue-600 text-white rounded-lg">
            ➕ Thêm Role
          </button>
        <?php endif; ?>
      </div>

      <!-- Table -->
      <div class="bg-card rounded-2xl shadow-card p-6 overflow-x-auto">
        <table class="w-full border-collapse min-w-max">
          <thead class="bg-gray-50 text-xs uppercase text-subtext">
            <tr>
              <th class="px-3 py-2 text-left">Tên role</th>
              <th class="px-3 py-2 text-left">Mô tả</th>
              <th class="px-3 py-2 text-center">Trạng thái</th>
              <th class="px-3 py-2 text-right">Hành động</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roles as $r): ?>
              <tr class="border-t hover:bg-gray-50">
                <td class="px-3 py-2"><?= htmlspecialchars($r['name']) ?></td>
                <td class="px-3 py-2 text-gray-500">
                  <?= htmlspecialchars($r['description'] ?? '—') ?>
                </td>
                <td class="px-3 py-2 text-center">
                  <?= $r['is_active'] ? '✔️' : '❌' ?>
                </td>
                <td class="px-3 py-2 text-right space-x-2">
                  <?php if (can('roles', 'update')): ?>
                    <button class="px-3 py-1 bg-gray-100 rounded js-edit" data-id="<?= $r['id'] ?>"
                      data-name="<?= htmlspecialchars($r['name']) ?>"
                      data-desc="<?= htmlspecialchars($r['description'] ?? '') ?>" data-active="<?= $r['is_active'] ?>">
                      ✏ Sửa
                    </button>
                  <?php endif; ?>

                  <?php if (can('roles', 'update')): ?>
                    <button class="px-3 py-1 bg-indigo-600 text-white rounded js-perm" data-id="<?= $r['id'] ?>"
                      data-name="<?= htmlspecialchars($r['name']) ?>">
                      🔐 Quyền
                    </button>
                  <?php endif; ?>

                  <?php if (can('roles', 'delete')): ?>
                    <button class="px-3 py-1 bg-red-500 text-white rounded js-del" data-id="<?= $r['id'] ?>">
                      🗑 Xóa
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </div>
  </main>
</div>

<div id="modalRoot" class="hidden fixed inset-0 bg-black/30 flex items-center justify-center z-[9999]"></div>

<script src="<?= BASE_URL ?>assets/js/roles.js?v=<?= time() ?>"></script>