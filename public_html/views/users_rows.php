<?php
$stmt = $pdo->query("
  SELECT 
    u.id,
    u.username,
    u.fullname,
    u.role_id,
    r.name AS role_name,
    EXISTS (
      SELECT 1 FROM members m WHERE m.user_id = u.id
    ) AS has_member
  FROM users u
  LEFT JOIN roles r ON r.id = u.role_id
  ORDER BY u.id DESC
");

while ($u = $stmt->fetch(PDO::FETCH_ASSOC)):
?>
<tr data-id="<?= $u['id'] ?>">
  <td class="username"><?= htmlspecialchars($u['username']) ?></td>
  <td class="fullname"><?= htmlspecialchars($u['fullname']) ?></td>
  <td class="role" data-role="<?= $u['role_id'] ?>">
    <?= htmlspecialchars($u['role_name']) ?>
  </td>
  <td class="text-right">
    <button
      class="js-edit text-blue-600"
      data-id="<?= $u['id'] ?>"
      data-username="<?= htmlspecialchars($u['username']) ?>"
      data-fullname="<?= htmlspecialchars($u['fullname']) ?>"
      data-role-id="<?= $u['role_id'] ?>"
      data-has-member="<?= $u['has_member'] ? 1 : 0 ?>"
    >Sửa</button>

    <button
      class="js-del text-red-600 ml-2"
      data-id="<?= $u['id'] ?>"
      data-username="<?= htmlspecialchars($u['username']) ?>"
    >Xóa</button>
  </td>
</tr>
<?php endwhile; ?>
