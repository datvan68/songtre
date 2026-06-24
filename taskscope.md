# Taskscope: Theo dõi thành viên chưa đóng tiền - Ẩn thành viên ngừng theo dõi

## Mục tiêu

Ở mục **Thống kê tài chính > Theo dõi thành viên chưa đóng tiền**, các thành viên đã được đánh dấu **Ngừng theo dõi** không được xuất hiện trong bảng danh sách chưa đóng tiền.

Quy tắc áp dụng theo cột hiện có:

- Bảng `members`
- Cột trạng thái: `stop_follow`
- Thành viên đang theo dõi: `stop_follow = 0` hoặc `stop_follow IS NULL`
- Thành viên ngừng theo dõi: `stop_follow = 1`

## Pipeline áp dụng

Theo `.agents/Workflows/orchestrator.md` và `.agents/Workflows/pipeline.md`, yêu cầu này đi theo pipeline:

- `pipeline_id`: `feature_development`
- `code-agent`: cập nhật truy vấn lọc dữ liệu
- `test-agent`: kiểm tra bảng và xuất Excel không còn thành viên ngừng theo dõi
- `review-agent`: review logic lọc, phân quyền, dữ liệu xuất
- `doc-agent`: cập nhật ghi chú/taskscope nếu cần

## Phạm vi file

Các file cần kiểm tra/chỉnh chính:

- `public_html/controllers/statistics/finance.php`
- `public_html/assets/js/statistics/finance.js`

File tham chiếu trạng thái ngừng theo dõi:

- `public_html/controllers/members.php`
- `public_html/assets/js/members.js`
- `public_html/views/members.php`

## Hiện trạng

Trong `public_html/controllers/statistics/finance.php`:

- Action `unpaid_members` đang lấy danh sách thành viên chưa đóng tiền từ bảng `members`.
- Action `export_unpaid_members` đang xuất Excel danh sách thành viên chưa đóng tiền.
- Cả hai luồng hiện lọc theo khoa, khóa, lớp, khoản thu, năm học, học kỳ, phân loại thành viên.
- Cả hai luồng chưa có điều kiện loại bỏ thành viên `m.stop_follow = 1`.

Trong module thành viên:

- Trang danh sách thành viên đã có chức năng đánh dấu **Ngừng theo dõi**.
- Backend dùng action `update_stop_follow`.
- Dữ liệu được lưu vào `members.stop_follow`.

## Yêu cầu chức năng

1. Khi người dùng mở mục **Theo dõi thành viên chưa đóng tiền** và bấm **Tìm kiếm**, bảng chỉ hiển thị thành viên còn đang theo dõi.
2. Thành viên có `members.stop_follow = 1` không được xuất hiện trong bảng, dù còn thiếu khoản thu.
3. Thành viên có `members.stop_follow = 0` hoặc `NULL` vẫn được tính bình thường.
4. Bộ lọc hiện có phải giữ nguyên:
   - Khoản thu
   - Năm học
   - Học kỳ
   - Phân loại
   - Khoa / Phòng
   - Khóa
   - Lớp
   - Phân trang
5. Tổng số bản ghi và số trang phải tính theo danh sách đã loại thành viên ngừng theo dõi.
6. Nút **Xuất Excel chưa đóng** phải xuất cùng phạm vi dữ liệu với bảng, tức là cũng không có thành viên ngừng theo dõi.
7. Không thay đổi cách đánh dấu/ngừng đánh dấu theo dõi ở trang thành viên.

## Gợi ý triển khai

### Bước 1 - code-agent

Cập nhật action `unpaid_members` trong `public_html/controllers/statistics/finance.php`:

- Thêm điều kiện lọc vào `$where`:

```sql
AND (m.stop_follow = 0 OR m.stop_follow IS NULL)
```

- Điều kiện phải áp dụng trước cả truy vấn đếm (`COUNT`) và truy vấn danh sách.
- Không thêm filter mới trên giao diện, vì yêu cầu là mặc định luôn ẩn thành viên ngừng theo dõi.

### Bước 2 - code-agent

Cập nhật action `export_unpaid_members` trong `public_html/controllers/statistics/finance.php`:

- Dùng cùng điều kiện:

```sql
AND (m.stop_follow = 0 OR m.stop_follow IS NULL)
```

- Đảm bảo file Excel không xuất thành viên ngừng theo dõi.

### Bước 3 - test-agent

Kiểm thử các trường hợp:

- Một thành viên chưa đóng tiền và `stop_follow = 0` vẫn xuất hiện trong bảng.
- Một thành viên chưa đóng tiền và `stop_follow = NULL` vẫn xuất hiện trong bảng.
- Một thành viên chưa đóng tiền và `stop_follow = 1` không xuất hiện trong bảng.
- Phân trang không tính thành viên `stop_follow = 1`.
- Xuất Excel không có thành viên `stop_follow = 1`.
- Các bộ lọc khoản thu, năm học, học kỳ, khoa, khóa, lớp vẫn hoạt động.

## Acceptance Criteria

- Bảng **Theo dõi thành viên chưa đóng tiền** không hiển thị thành viên đã ngừng theo dõi.
- Tổng số dòng và phân trang khớp với dữ liệu sau khi lọc `stop_follow`.
- File Excel **Xuất Excel chưa đóng** không chứa thành viên đã ngừng theo dõi.
- Không phát sinh thay đổi ở chức năng quản lý thành viên/ngừng theo dõi.
- Không thay đổi dữ liệu đóng tiền, khoản thu, giao dịch thu/chi.

## Ngoài phạm vi

- Không thêm checkbox/bộ lọc mới để bật/tắt hiển thị thành viên ngừng theo dõi.
- Không chỉnh logic tạo khoản thu.
- Không chỉnh logic ghi nhận giao dịch đóng tiền.
- Không thay đổi trang danh sách thành viên.
- Không đổi schema database.

## Rủi ro cần lưu ý

- Nếu có dữ liệu cũ `stop_follow` bị `NULL`, cần xem như vẫn đang theo dõi để tránh ẩn nhầm.
- Nếu các báo cáo tài chính khác cũng dùng danh sách thành viên chưa đóng, cần kiểm tra riêng trước khi áp dụng rộng hơn.
- Cần đảm bảo bảng và Excel dùng cùng điều kiện để tránh lệch dữ liệu.
