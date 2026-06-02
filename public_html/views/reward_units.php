<?php
require_once __DIR__ . '/../config/auth.php';
if (!can('reward_units', 'view')) {
    echo "<section class='p-6'>403 - Forbidden</section>";
    exit;
}
?>

<section class="p-6">
    <div class="w-full">

        <h1 class="text-2xl font-bold mb-4 -mt-4">
            Quản lý Danh mục
        </h1>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-stretch">

            <div class="flex flex-col h-full gap-6">

                <!-- CHỨC VỤ -->
                <div class="bg-white rounded-xl shadow-card p-5 flex flex-col flex-1">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-lg">Chức vụ</h3>
                        <?php if (can('reward_units', 'create')): ?>
                            <button class="btn-primary" onclick="openPositionForm()">+ Thêm</button>
                        <?php endif; ?>
                    </div>

                    <ul id="positionList" class="divide-y text-sm flex-1 overflow-auto"></ul>

                    <div id="pagination-position" class="flex justify-center gap-2 pt-3 border-t"></div>
                </div>

                <!-- NHÓM CHI ĐOÀN -->
                <div class="bg-white rounded-xl shadow-card p-5 flex flex-col flex-1">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-lg">Nhóm chi đoàn</h3>
                        <?php if (can('reward_units', 'create')): ?>
                            <button class="btn-primary" onclick="openGroupForm()">+ Thêm</button>
                        <?php endif; ?>
                    </div>

                    <ul id="groupList" class="divide-y text-sm flex-1 overflow-auto"></ul>

                    <div id="pagination-group" class="flex justify-center gap-2 pt-3 border-t"></div>
                </div>

            </div>

            <div class="flex flex-col h-full gap-6">

                <!-- PHÒNG BAN -->
                <div class="bg-white rounded-xl shadow-card p-5 flex flex-col flex-1">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-lg">Phòng ban</h3>
                        <?php if (can('reward_units', 'create')): ?>
                            <button class="btn-primary" onclick="openDepartmentForm()">+ Thêm</button>
                        <?php endif; ?>
                    </div>

                    <ul id="departmentList" class="divide-y text-sm flex-1 overflow-auto"></ul>

                    <div id="pagination-department" class="flex justify-center gap-2 pt-3 border-t"></div>
                </div>

                <!-- DANH HIỆU ĐỀ NGHỊ -->
                <div class="bg-white rounded-xl shadow-card p-5 flex flex-col flex-1">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-lg">Danh hiệu đề nghị</h3>
                        <?php if (can('reward_units', 'create')): ?>
                            <button class="btn-primary" onclick="openTitleForm()">+ Thêm</button>
                        <?php endif; ?>
                    </div>

                    <ul id="titleList" class="divide-y text-sm flex-1 overflow-auto"></ul>

                    <div id="pagination-title" class="flex justify-center gap-2 pt-3 border-t"></div>
                </div>

            </div>

            <!-- CỘT 3: CHI ĐOÀN + NĂM HỌC -->
            <div class="flex flex-col h-full gap-6">

                <!-- ========== CHI ĐOÀN ========== -->
                <div class="bg-white rounded-xl shadow-card p-5 flex flex-col flex-1">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-bold text-lg">Chi đoàn</h3>
                        <?php if (can('reward_units', 'create')): ?>
                            <button class="btn-primary" onclick="openChidoanForm()">+ Thêm</button>
                        <?php endif; ?>
                    </div>

                    <ul id="chidoanList" class="divide-y text-sm flex-1 overflow-auto"></ul>

                    <div id="pagination-chidoan" class="flex justify-center gap-2 pt-4 border-t"></div>
                </div>

                <!-- ========== NĂM HỌC ========== -->
                <div class="bg-white rounded-xl shadow-card p-5 flex flex-col flex-1">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-lg">Năm học</h3>
                        <?php if (can('reward_units', 'create')): ?>
                            <button class="btn-primary" onclick="openSchoolYearForm()">+ Thêm</button>
                        <?php endif; ?>
                    </div>

                    <ul id="schoolYearList" class="divide-y text-sm flex-1 overflow-auto"></ul>

                    <div id="pagination-school_year" class="flex justify-center gap-2 pt-3 border-t"></div>
                </div>

            </div>

        </div>
    </div>
</section>

<script>
  window.PERM = {
    create: <?= can('reward_units', 'create') ? 'true' : 'false' ?>,
    update: <?= can('reward_units', 'update') ? 'true' : 'false' ?>,
    delete: <?= can('reward_units', 'delete') ? 'true' : 'false' ?>
  };
</script>

<script src="<?= BASE_URL ?>assets/js/reward_units.js?v=<?= time() ?>"></script>
