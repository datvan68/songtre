<?php
require __DIR__ . '/../config/db.php';

auth_guard();

if (!can('departments', 'view')) {
    http_response_code(403);
    echo "<section class='p-6'>403 - Forbidden</section>";
    exit;
}

$perPage = 15;

/* ===== KHOA ===== */
$totalDept = (int) $pdo->query("
  SELECT COUNT(*) FROM departments WHERE type = 'khoa'
")->fetchColumn();

$totalPagesDept = max(1, ceil($totalDept / $perPage));

$departments = $pdo->query("
  SELECT * FROM departments
  WHERE type = 'khoa'
  ORDER BY id DESC
  LIMIT $perPage OFFSET 0
")->fetchAll(PDO::FETCH_ASSOC);


/* ===== KHÓA ===== */
$totalCourse = (int) $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
$totalPagesCourse = max(1, ceil($totalCourse / $perPage));
$courses = $pdo->query("
  SELECT * FROM courses
  ORDER BY id DESC
  LIMIT $perPage OFFSET 0
")->fetchAll(PDO::FETCH_ASSOC);

/* ===== LỚP ===== */
$totalClass = (int) $pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
$totalPagesClass = max(1, ceil($totalClass / $perPage));
$classes = $pdo->query("
  SELECT id, name, department_id, course_id, status
  FROM classes
  ORDER BY id DESC
  LIMIT $perPage OFFSET 0
")->fetchAll(PDO::FETCH_ASSOC);





?>
<section class="p-6">
    <div class="w-full">
        <h1 class="font-heading text-3xl font-bold mb-6 flex items-center">
            Quản lý Khoa / Khóa học / Lớp
            <?php if (can('departments', 'create')): ?>
            <button onclick="openSchoolYearConfigModal()" class="ml-3 p-1.5 rounded-xl border bg-white hover:bg-gray-50 text-gray-500 hover:text-primary transition-all shadow-sm flex items-center justify-center" title="Cấu hình năm học">
                <i data-lucide="settings" class="w-5 h-5"></i>
            </button>
            <?php endif; ?>
        </h1>

        <div class="grid md:grid-cols-3 gap-6">

            <!-- === KHOA === -->
            <div class="bg-card rounded-2xl shadow-card p-4 flex flex-col">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="font-semibold text-lg">Khoa</h2>
                    <?php if (can('departments', 'create')): ?>
                        <button class="bg-primary text-white px-3 py-1 rounded-lg text-sm" onclick="openDeptModal()">+
                            Thêm</button>
                    <?php endif; ?>
                </div>

                <div class="flex-1 overflow-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left py-1">Tên Khoa</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tbodyDept">
                            <?php foreach ($departments as $d): ?>
                                <tr class="border-t">
                                    <td class="py-1"><?= htmlspecialchars($d['name']) ?></td>
                                    <td class="text-right whitespace-nowrap py-1">
                                        <?php if (can('departments', 'update')): ?>
                                            <button class="px-2 text-blue-600"
                                                onclick='openDeptModal(<?= $d["id"] ?>, <?= json_encode($d["name"]) ?>)'>
                                                Sửa
                                            </button>
                                        <?php endif; ?>

                                        <?php if (can('departments', 'delete')): ?>
                                            <button class="px-2 text-red-600"
                                                onclick="delItem('dept','delete_department',<?= $d['id'] ?>)">
                                                Xóa
                                            </button>
                                        <?php endif; ?>

                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pager-dept mt-4 pt-2 border-t flex justify-center"></div>
            </div>

            <!-- === KHÓA HỌC === -->
            <div class="bg-card rounded-2xl shadow-card p-4 flex flex-col">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="font-semibold text-lg">Khóa học</h2>
                    <?php if (can('departments', 'create')): ?>
                        <button class="bg-primary text-white px-3 py-1 rounded-lg text-sm" onclick="openCourseModal()">+
                            Thêm</button>
                    <?php endif; ?>
                </div>

                <div class="flex-1 overflow-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left py-1">Tên Khóa học</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tbodyCourse">
                            <?php foreach ($courses as $c): ?>
                                <tr class="border-t">
                                    <td class="py-1">
                                        <div class="flex items-center gap-2">
                                            <span><?= htmlspecialchars($c['name']) ?></span>
                                            <?php if (($c['status'] ?? 1) == 1): ?>
                                                <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded-full font-semibold">Đang theo dõi</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 bg-gray-100 text-gray-500 text-xs rounded-full font-semibold">Ngừng theo dõi</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-right whitespace-nowrap py-1">
                                        <?php if (can('departments', 'update')): ?>
                                            <button class="px-2 text-blue-600"
                                                onclick='openCourseModal(<?= $c["id"] ?>, <?= json_encode($c["name"]) ?>, <?= $c["status"] ?? 1 ?>)'>
                                                Sửa
                                            </button>
                                        <?php endif; ?>

                                        <?php if (can('departments', 'delete')): ?>
                                            <button class="px-2 text-red-600"
                                                onclick="delItem('course','delete_course',<?= $c['id'] ?>)">
                                                Xóa
                                            </button>
                                        <?php endif; ?>

                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pager-course mt-4 pt-2 border-t flex justify-center"></div>
            </div>

            <!-- === LỚP === -->
            <div class="bg-card rounded-2xl shadow-card p-4 flex flex-col">
                <div class="flex justify-between items-center mb-3">
                    <h2 class="font-semibold text-lg">Lớp</h2>
                    <?php if (can('departments', 'create')): ?>
                        <button class="bg-primary text-white px-3 py-1 rounded-lg text-sm" onclick="openClassModal()">+
                            Thêm</button>
                    <?php endif; ?>
                </div>

                <div class="flex-1 overflow-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left py-1">Tên lớp</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tbodyClass">
                            <?php foreach ($classes as $cl): ?>
                                <tr class="border-t">
                                    <td class="py-1">
                                        <div class="flex items-center gap-2">
                                            <span><?= htmlspecialchars($cl['name']) ?></span>
                                            <?php if (($cl['status'] ?? 1) == 1): ?>
                                                <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded-full font-semibold">Đang theo dõi</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 bg-gray-100 text-gray-500 text-xs rounded-full font-semibold">Ngừng theo dõi</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-right whitespace-nowrap py-1">
                                        <?php if (can('departments', 'update')): ?>
                                            <button class="px-2 text-blue-600" onclick='openClassModal(
                                                <?= $cl["id"] ?>,
                                                <?= json_encode($cl["name"]) ?>,
                                                <?= $cl["department_id"] ?>,
                                                <?= $cl["course_id"] ?>,
                                                <?= $cl["status"] ?? 1 ?>
                                                )'>
                                                Sửa
                                            </button>
                                        <?php endif; ?>

                                        <?php if (can('departments', 'delete')): ?>
                                            <button class="px-2 text-red-600"
                                                onclick="delItem('class','delete_class',<?= $cl['id'] ?>)">
                                                Xóa
                                            </button>
                                        <?php endif; ?>

                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pager-class mt-4 pt-2 border-t flex justify-center"></div>
            </div>

        </div>
    </div>
</section>



<script>
    // Truyền danh sách Khoa từ PHP sang JS
    window.departments = <?= json_encode($departments, JSON_UNESCAPED_UNICODE) ?>;
    window.courses = <?= json_encode($courses, JSON_UNESCAPED_UNICODE) ?>;

</script>


<script>
    window.PERM_DEPT = {
        create: <?= can('departments', 'create') ? 'true' : 'false' ?>,
        update: <?= can('departments', 'update') ? 'true' : 'false' ?>,
        delete: <?= can('departments', 'delete') ? 'true' : 'false' ?>
    };
</script>

<script src="<?= BASE_URL ?>assets/js/departments.js?v=<?= time() ?>"></script>

<script>
    window.TOTAL_PAGES_DEPT = <?= $totalPagesDept ?>;
    window.TOTAL_PAGES_COURSE = <?= $totalPagesCourse ?>;
    window.TOTAL_PAGES_CLASS = <?= $totalPagesClass ?>;
</script>