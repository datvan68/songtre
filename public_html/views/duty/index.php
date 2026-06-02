<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("
  SELECT r.name
  FROM users u
  JOIN roles r ON r.id = u.role_id
  WHERE u.id = ?
");
$stmt->execute([$userId]);
$role = $stmt->fetchColumn(); // admin | banchaphanh (hoặc role khác)
?>

<section class="p-6 relative z-0" id="duty-app" data-role="<?= htmlspecialchars($role) ?>">

  <div class="bg-white rounded-xl border shadow-sm overflow-hidden">

    <div class="relative z-0 isolate overflow-hidden md:overflow-visible">

      <?php if ($role === 'admin'): ?>
        <?php include __DIR__ . '/admin.php'; ?>
      <?php else: ?>
        <?php include __DIR__ . '/user.php'; ?>
      <?php endif; ?>

    </div>

  </div>
</section>


<!-- ================= CORE ================= -->
<script src="<?= BASE_URL ?>assets/js/duty/duty.core.js?v=<?= time() ?>"></script>

<?php if ($role === 'admin'): ?>
  <script src="<?= BASE_URL ?>assets/js/duty/duty.admin.js?v=<?= time() ?>"></script>
<?php else: ?>
  <script src="<?= BASE_URL ?>assets/js/duty/duty.user.js?v=<?= time() ?>"></script>
<?php endif; ?>