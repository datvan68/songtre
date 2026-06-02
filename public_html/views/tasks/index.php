<?php
// views/tasks/index.php  (hoặc views/tasks/tasks.php tuỳ cấu trúc của Toro)
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

auth_guard();

$userId = (int)($_SESSION['user_id'] ?? 0);

// Lấy role name giống duty
$stmt = $pdo->prepare("
  SELECT r.name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = ?
");
$stmt->execute([$userId]);
$role = (string)$stmt->fetchColumn(); // admin | ...

// Quy ước: admin task nếu role=admin hoặc có quyền create/update/delete tasks
// Nếu hệ thống can() của Toro có sẵn thì dùng cho đúng RBAC
$view = 'user';
if (function_exists('can')) {
  if (can('tasks', 'create') || can('tasks', 'update') || can('tasks', 'delete')) $view = 'admin';
} else {
  if ($role === 'admin') $view = 'admin';
}
?>

<section class="p-6 relative z-0" id="tasks-app" data-view="<?= htmlspecialchars($view) ?>">
  <div class="bg-white rounded-xl border shadow-sm overflow-hidden">
    <div class="relative z-0 isolate overflow-hidden md:overflow-visible">

      <?php if ($view === 'admin'): ?>
        <?php include __DIR__ . '/admin.php'; ?>
      <?php else: ?>
        <?php include __DIR__ . '/user.php'; ?>
      <?php endif; ?>

    </div>
  </div>
</section>

<!-- ================= CORE ================= -->
<script src="<?= BASE_URL ?>assets/js/tasks/tasks.core.js?v=<?= time() ?>"></script>

<?php if ($view === 'admin'): ?>
  <script src="<?= BASE_URL ?>assets/js/tasks/tasks.admin.js?v=<?= time() ?>"></script>
<?php else: ?>
  <script src="<?= BASE_URL ?>assets/js/tasks/tasks.user.js?v=<?= time() ?>"></script>
<?php endif; ?>
