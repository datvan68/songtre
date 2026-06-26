# Task Scope: Tính Điểm Thi Đua Học Kỳ

## Vai trò & pipeline

- Agent điều phối: `orchestrator`
- Workflow tham chiếu: `.agents/Workflows/orchestrator.md`
- Pipeline áp dụng: `feature_development` trong `.agents/Workflows/pipeline.md`
- Artifact cần tạo/cập nhật: UI/logic cho page `Tính Điểm Thi Đua Học Kỳ`

## Mục tiêu

Cập nhật page `Tính Điểm Thi Đua Học Kỳ` để người dùng có thể:

1. Xóa/bỏ từng danh mục đang nằm trong cấu hình tính điểm để chọn danh mục khác.
2. Chỉ tính điểm và hiển thị khu vực `Xem trước & Tổng hợp` sau khi người dùng bấm nút xác nhận cấu hình điểm.

## File liên quan

- `public_html/views/scoring.php`
- `public_html/assets/js/scoring.js`
- `public_html/controllers/scoring.php`
- Các module controller dưới `public_html/controllers/scoring/` nếu cần kiểm tra API tính điểm hiện có.

## Hiện trạng ghi nhận

- Page chính nằm tại `public_html/views/scoring.php`.
- Logic cấu hình, thêm mục, phân bổ điểm, tính preview nằm tại `public_html/assets/js/scoring.js`.
- Bảng cấu hình hiện có cột `Tác vụ` và hàm `removeConfigItem(key)` để bỏ mục khỏi cấu hình.
- Preview hiện đang được gọi tự động qua `loadPreviewScoring()` sau khi tải/chỉnh cấu hình, ví dụ sau `loadScoringItems()` và `computeAndApplyDistribution()`.
- Button xuất Excel và lưu điểm đang được enable/disable theo tổng điểm bằng `10.00`, nhưng chưa có trạng thái “đã xác nhận cấu hình”.

## Yêu cầu chi tiết

### 1. Icon xóa danh mục trong cấu hình tính điểm

Trong section `Cấu hình tính điểm`:

- Mỗi dòng mục tính điểm phải có icon xóa rõ ràng ở cột `Tác vụ`.
- Icon xóa dùng để bỏ mục khỏi danh sách đang tính điểm, không xóa dữ liệu gốc trong hệ thống.
- Sau khi xóa:
  - Xóa item khỏi mảng cấu hình hiện tại.
  - Cập nhật lại `selectedKeys`.
  - Cập nhật lại tổng điểm, điểm còn lại, badge số lượng mục.
  - Lưu lại draft cấu hình nếu đang dùng localStorage draft.
  - Item vừa xóa phải xuất hiện lại trong modal `Thêm mục tính điểm` để có thể chọn lại.
- Nên có xác nhận trước khi bỏ mục, nhất là khi mục đã có điểm khác `0`.
- Tooltip/title nên ghi rõ: `Bỏ khỏi cấu hình` hoặc `Xóa mục này khỏi cấu hình`.

### 2. Button xác nhận cấu hình điểm trước khi tính preview

Trong section `Cấu hình tính điểm`, thêm button:

- ID đề xuất: `btnConfirmScoringConfig`
- Nhãn đề xuất: `Xác nhận cấu hình điểm`
- Vị trí: cùng hàng với `Thêm mục phong trào / khoản thu` và `Chia đều phần còn lại`, hoặc ở cuối bảng cấu hình để người dùng thao tác sau khi nhập điểm.

Luồng mới:

1. Người dùng chọn `Năm học` và `Học kỳ`.
2. Người dùng thêm/bỏ mục tính điểm và nhập/chia điểm.
3. Hệ thống chỉ cập nhật phần cấu hình và tổng điểm, chưa gọi API preview.
4. Khi tổng điểm đúng `10.00`, button `Xác nhận cấu hình điểm` được bật.
5. Khi người dùng bấm `Xác nhận cấu hình điểm`:
   - Validate có năm học, học kỳ.
   - Validate có ít nhất một mục tính điểm.
   - Validate tổng điểm đúng `10.00`.
   - Lưu trạng thái cấu hình đã xác nhận.
   - Gọi `loadPreviewScoring()`.
   - Mở/hiển thị section `Xem trước & Tổng hợp`.
   - Cho phép sử dụng `Xuất Excel`, `Lưu điểm học kỳ`, filter/search preview, đổi chế độ xem.

Khi cấu hình thay đổi sau khi đã xác nhận:

- Đánh dấu cấu hình là “chưa xác nhận”.
- Ẩn hoặc reset preview hiện tại để tránh người dùng thấy dữ liệu tính theo cấu hình cũ.
- Disable `Xuất Excel` và `Lưu điểm học kỳ` cho tới khi bấm xác nhận lại.
- Thông báo trạng thái đề xuất: `Cấu hình đã thay đổi, vui lòng xác nhận lại để xem trước kết quả.`

## Điều chỉnh logic JavaScript đề xuất

Trong `public_html/assets/js/scoring.js`:

- Thêm state:
  - `let isConfigConfirmed = false;`
  - Có thể thêm `let confirmedConfigSignature = "";` để so sánh cấu hình hiện tại với cấu hình đã xác nhận.
- Thêm helper:
  - `getConfigSignature()`: serialize năm học, học kỳ, danh sách item, điểm và trạng thái khóa.
  - `markConfigDirty()`: set `isConfigConfirmed = false`, reset preview UI, disable preview actions.
  - `canConfirmConfig()`: kiểm tra năm học, học kỳ, items và tổng điểm.
  - `confirmScoringConfig()`: validate, set confirmed state, lưu draft, gọi preview.
- Gỡ các lệnh gọi `loadPreviewScoring()` tự động khỏi các nhánh chỉnh cấu hình như:
  - Sau `loadScoringItems()`
  - Sau `computeAndApplyDistribution()`
  - Sau thêm/xóa item nếu đang gọi gián tiếp
- `loadPreviewScoring()` chỉ nên chạy khi:
  - Người dùng bấm `Xác nhận cấu hình điểm`.
  - Người dùng thao tác trong preview sau khi cấu hình đã xác nhận, ví dụ reload, search, filter, đổi trang, đổi view mode.
- Các event `previewSearchClass`, `previewFilterDept`, pagination, reload preview phải kiểm tra `isConfigConfirmed` trước khi gọi API.
- `submitExport()` và `saveSemesterScores()` phải kiểm tra cấu hình đã xác nhận, ngoài điều kiện tổng điểm `10.00`.

## Điều chỉnh UI đề xuất

Trong `public_html/views/scoring.php`:

- Thêm button `btnConfirmScoringConfig`.
- Thêm vùng trạng thái nhỏ nếu cần, ví dụ:
  - ID đề xuất: `configConfirmStatus`
  - Nội dung ban đầu: `Vui lòng xác nhận cấu hình điểm để xem trước kết quả.`
- Cập nhật thông báo `previewEmptyMessage`:
  - Khi chưa chọn năm học/học kỳ: `Chọn Năm học và Học kỳ để cấu hình điểm.`
  - Khi đã chọn nhưng chưa xác nhận: `Vui lòng xác nhận cấu hình điểm để xem trước bảng điểm thi đua.`
- Section preview chỉ hiển thị bảng/filter sau khi cấu hình đã xác nhận thành công.

## Tiêu chí nghiệm thu

- Chọn năm học/học kỳ xong không tự động gọi preview.
- Thêm mục tính điểm không tự động show preview.
- Xóa một mục khỏi cấu hình bằng icon xóa thành công và mục đó có thể được chọn lại trong modal thêm mục.
- Nếu tổng điểm khác `10.00`, button xác nhận bị disable hoặc bấm vào sẽ báo lỗi rõ ràng.
- Khi bấm `Xác nhận cấu hình điểm` với tổng điểm đúng `10.00`, preview mới được tải và hiển thị.
- Sau khi đã xác nhận, nếu sửa điểm/thêm mục/xóa mục/khóa mở khóa, preview bị reset hoặc báo cần xác nhận lại.
- `Xuất Excel` và `Lưu điểm học kỳ` không chạy khi cấu hình chưa xác nhận.
- Không thay đổi API/backend nếu payload hiện tại của `preview_scoring_summary`, `export_scoring_summary`, `save_scoring_summary` vẫn đáp ứng đủ.

## Kiểm thử đề xuất

- Test thủ công trên trình duyệt:
  - Chọn năm học/học kỳ.
  - Thêm 2-3 mục phong trào/khoản thu.
  - Nhập điểm tổng khác `10.00` và kiểm tra không xác nhận được.
  - Chia/nhập điểm để tổng đúng `10.00`, bấm xác nhận, kiểm tra preview hiển thị.
  - Xóa một mục bằng icon xóa, kiểm tra preview bị reset và phải xác nhận lại.
  - Mở modal thêm mục, kiểm tra mục vừa xóa xuất hiện lại.
  - Thử xuất Excel/lưu điểm trước và sau xác nhận.
- Kiểm tra console browser không có lỗi JavaScript.
- Nếu có môi trường test tự động, thêm test cho helper validate/signature/dirty state trong `scoring.js`.

## Ngoài phạm vi

- Không xóa dữ liệu phong trào/khoản thu khỏi database.
- Không thay đổi công thức tính điểm backend nếu logic hiện tại đã đúng.
- Không thay đổi section `Quản lý điểm tích lũy học kỳ` trừ khi cần disable/refresh sau khi lưu điểm.
