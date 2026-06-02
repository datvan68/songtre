<?php
// views/baocaophongtrao.php
?>
<!-- FontAwesome for icons in this view -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
    .movement-card {
        transition: all 0.3s ease;
    }
    .movement-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    }
</style>

<div class="flex flex-col lg:flex-row w-full gap-6">
    <!-- Sidebar: Danh sách phong trào -->
    <div class="w-full lg:w-80 bg-white border rounded-2xl shadow-sm p-6 shrink-0 h-fit">
        <div class="flex justify-between items-center mb-6">
            <h2 class="font-semibold text-lg text-gray-800">Phong trào đang diễn ra</h2>
            <span id="movement-count" class="text-xs bg-blue-100 text-blue-700 px-3 py-1 rounded-full">4 phong trào</span>
        </div>

        <div id="movements-list" class="space-y-4">
            <!-- JS sẽ render danh sách phong trào -->
        </div>

        <div class="mt-8 pt-6 border-t">
            <button onclick="showAllMovements()" 
                    class="w-full py-3 text-blue-600 hover:bg-blue-50 rounded-xl border border-blue-200 flex items-center justify-center gap-2 text-sm font-medium transition-colors">
                <i class="fas fa-plus"></i>
                Xem tất cả phong trào
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Báo cáo hoạt động</h2>

        <!-- Form báo cáo -->
        <div class="bg-white rounded-2xl shadow-sm border p-8">
            <form id="report-form" onsubmit="submitReport(event)">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Chọn phong trào -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Phong trào <span class="text-red-500">*</span></label>
                        <select id="movement-select" 
                                class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 bg-white text-gray-800">
                            <!-- JS sẽ populate -->
                        </select>
                    </div>

                    <!-- Ngày hoạt động -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Ngày hoạt động <span class="text-red-500">*</span></label>
                        <input type="date" id="activity-date" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 text-gray-800">
                    </div>

                    <!-- Số lượng tham gia -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Số lượng tham gia <span class="text-red-500">*</span></label>
                        <input type="number" id="participants" value="15" min="1"
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 text-gray-800">
                    </div>

                    <!-- Địa điểm -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Địa điểm tổ chức</label>
                        <input type="text" id="location" placeholder="Ví dụ: Công viên 23/9, Quận 1"
                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 text-gray-800">
                    </div>

                    <!-- Nội dung hoạt động -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nội dung hoạt động <span class="text-red-500">*</span></label>
                        <textarea id="description" rows="5" 
                            placeholder="Mô tả chi tiết hoạt động đã diễn ra..."
                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500 resize-none text-gray-800"></textarea>
                    </div>

                    <!-- Hình ảnh -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Hình ảnh minh chứng</label>
                        <div class="border border-dashed border-gray-300 rounded-xl p-8 text-center hover:bg-gray-50 transition-colors">
                            <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3"></i>
                            <p class="text-gray-600">Kéo thả hình ảnh hoặc</p>
                            <label class="cursor-pointer inline-block mt-2 px-6 py-2 bg-blue-50 text-blue-600 rounded-xl hover:bg-blue-100">
                                Chọn file
                                <input type="file" id="photos" multiple accept="image/*" class="hidden">
                            </label>
                            <p class="text-xs text-gray-500 mt-2">PNG, JPG, JPEG (tối đa 5 ảnh)</p>
                        </div>
                        <div id="uploaded-files" class="mt-4 flex flex-wrap gap-2"></div>
                    </div>
                </div>

                <div class="mt-8 flex gap-4">
                    <button type="submit"
                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-medium py-4 rounded-2xl transition-colors flex items-center justify-center gap-2">
                        <i class="fas fa-paper-plane"></i>
                        GỬI BÁO CÁO
                    </button>
                    <button type="button" onclick="resetForm()"
                            class="px-8 py-4 border border-gray-300 hover:bg-gray-50 font-medium rounded-2xl transition-colors">
                        Làm mới
                    </button>
                </div>
            </form>
        </div>

        <!-- Lịch sử báo cáo gần đây -->
        <div class="mt-10">
            <h3 class="font-semibold text-lg mb-4 text-gray-800">Báo cáo gần đây của bạn</h3>
            <div id="recent-reports" class="space-y-4">
                <!-- JS sẽ render -->
            </div>
        </div>
    </div>
</div>

<!-- Toast thông báo -->
<div id="toast" class="hidden fixed bottom-6 right-6 bg-green-600 text-white px-6 py-4 rounded-2xl shadow-xl flex items-center gap-3 z-[9999] transition-transform duration-300">
    <i class="fas fa-check-circle"></i>
    <span id="toast-message">Báo cáo đã được gửi thành công!</span>
</div>

<script>
    // Dữ liệu mẫu phong trào từ Admin
    const movements = [
        {
            id: 1,
            name: "Chiến dịch Bảo vệ Môi trường 2026",
            status: "Đang diễn ra",
            deadline: "30/06/2026"
        },
        {
            id: 2,
            name: "Phong trào Hiến máu nhân đạo",
            status: "Đang diễn ra",
            deadline: "15/06/2026"
        },
        {
            id: 3,
            name: "Tuần lễ Văn hóa Thanh niên",
            status: "Sắp kết thúc",
            deadline: "05/06/2026"
        },
        {
            id: 4,
            name: "Chương trình Áo ấm cho em",
            status: "Đang diễn ra",
            deadline: "20/07/2026"
        }
    ];

    // Lịch sử báo cáo mẫu
    let recentReports = [
        {
            id: 101,
            movement: "Chiến dịch Bảo vệ Môi trường 2026",
            date: "28/05/2026",
            participants: 25,
            status: "Đã duyệt"
        },
        {
            id: 100,
            movement: "Phong trào Hiến máu nhân đạo",
            date: "20/05/2026",
            participants: 12,
            status: "Đang chờ"
        }
    ];

    // Render danh sách phong trào
    function renderMovements() {
        const container = document.getElementById('movements-list');
        if (!container) return;
        container.innerHTML = '';

        movements.forEach(m => {
            const div = document.createElement('div');
            div.className = `movement-card p-4 border rounded-2xl cursor-pointer hover:border-blue-400 transition-all duration-200 ${m.id === 1 ? 'border-blue-500 bg-blue-50' : 'bg-white'}`;
            div.innerHTML = `
                <div class="flex justify-between items-start">
                    <div>
                        <p class="font-medium text-sm text-gray-800">${m.name}</p>
                        <p class="text-xs text-gray-500 mt-1">${m.deadline}</p>
                    </div>
                    <span class="text-[10px] px-2.5 py-1 rounded-full ${m.status === 'Đang diễn ra' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'}">
                        ${m.status}
                    </span>
                </div>
            `;
            div.onclick = () => selectMovement(m.id);
            container.appendChild(div);
        });
    }

    // Render select options
    function renderMovementSelect() {
        const select = document.getElementById('movement-select');
        if (!select) return;
        select.innerHTML = '<option value="">-- Chọn phong trào --</option>';
        
        movements.forEach(m => {
            const option = document.createElement('option');
            option.value = m.id;
            option.textContent = m.name;
            select.appendChild(option);
        });
    }

    // Render báo cáo gần đây
    function renderRecentReports() {
        const container = document.getElementById('recent-reports');
        if (!container) return;
        container.innerHTML = '';

        recentReports.forEach(report => {
            const div = document.createElement('div');
            div.className = "bg-white border rounded-2xl p-5 flex items-center justify-between shadow-sm";
            div.innerHTML = `
                <div>
                    <p class="font-medium text-gray-800">${report.movement}</p>
                    <p class="text-sm text-gray-500">${report.date} • ${report.participants} người tham gia</p>
                </div>
                <div class="text-right">
                    <span class="inline-block px-4 py-1 text-xs rounded-full ${report.status === 'Đã duyệt' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}">
                        ${report.status}
                    </span>
                </div>
            `;
            container.appendChild(div);
        });
    }

    // Chọn phong trào từ sidebar
    function selectMovement(id) {
        const select = document.getElementById('movement-select');
        if (select) {
            select.value = id;
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Gửi báo cáo
    function submitReport(e) {
        e.preventDefault();
        
        const movementId = document.getElementById('movement-select').value;
        if (!movementId) {
            alert("Vui lòng chọn phong trào!");
            return;
        }

        const selectedMovement = movements.find(m => m.id == movementId);

        // Thêm vào lịch sử
        recentReports.unshift({
            id: Date.now(),
            movement: selectedMovement.name,
            date: document.getElementById('activity-date').value || '01/06/2026',
            participants: parseInt(document.getElementById('participants').value) || 0,
            status: "Đang chờ"
        });

        renderRecentReports();
        showToast("Báo cáo đã được gửi thành công! Cảm ơn bạn.");
        resetForm();
    }

    function showToast(message) {
        const toast = document.getElementById('toast');
        if (!toast) return;
        document.getElementById('toast-message').textContent = message;
        toast.classList.remove('hidden');
        toast.style.transform = 'translateY(0)';
        
        setTimeout(() => {
            toast.style.transform = 'translateY(100px)';
            setTimeout(() => {
                toast.classList.add('hidden');
            }, 300);
        }, 4000);
    }

    function resetForm() {
        const form = document.getElementById('report-form');
        if (form) form.reset();
        const dateInput = document.getElementById('activity-date');
        if (dateInput) {
            dateInput.value = new Date().toISOString().split('T')[0];
        }
    }

    function showAllMovements() {
        alert("Đang mở danh sách đầy đủ các phong trào từ Admin...");
    }

    // Khởi tạo
    (function() {
        renderMovements();
        renderMovementSelect();
        renderRecentReports();
        
        // Set ngày hiện tại
        const dateInput = document.getElementById('activity-date');
        if (dateInput) {
            dateInput.value = new Date().toISOString().split('T')[0];
        }
    })();
</script>
