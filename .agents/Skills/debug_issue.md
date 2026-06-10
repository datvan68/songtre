# Skill: Debug Issue — Phân Tích & Tìm Nguyên Nhân Lỗi

> Skill phân tích lỗi có hệ thống cho TypeScript/Node.js.
> Mục tiêu: tìm **root cause**, không chỉ fix **symptom**.

---

## Metadata

```yaml
skill_id: debug_issue
version: 1.0.0
language: TypeScript
supported_agents:
  - code-agent
  - review-agent
  - orchestrator
tools_required:
  - code_search
  - search (web_search cho known issues)
  - explain_code
  - gemini_generate
output_triggers:
  - Sau debug thành công → gọi implement_feature (mode=fix)
  - Sau fix → gọi write_test (viết regression test)
```

---

## Input Schema

```json
{
  "error_type": "runtime_error | logic_error | performance | type_error | test_failure",
  "evidence": {
    "error_message": "full stack trace hoặc error message",
    "logs": "relevant log lines (không cần toàn bộ log)",
    "reproduction_steps": ["bước 1", "bước 2"],
    "environment": "development | staging | production",
    "first_occurred": "khi nào lỗi bắt đầu xuất hiện (nếu biết)"
  },
  "context": {
    "affected_files": ["./src/modules/payment/payment.service.ts"],
    "recent_changes": "mô tả thay đổi gần nhất trước khi lỗi xảy ra",
    "frequency": "always | intermittent | rare"
  }
}
```

---

## Output Schema

```json
{
  "agent_id": "code-agent",
  "status": "root_cause_found | investigation_needed | cannot_determine",
  "result": {
    "root_cause": {
      "description": "mô tả nguyên nhân gốc bằng Tiếng Việt",
      "file": "./src/modules/payment/payment.service.ts",
      "line": 87,
      "code_snippet": "đoạn code gây lỗi",
      "why": "giải thích tại sao đây là nguyên nhân"
    },
    "contributing_factors": ["yếu tố phụ 1", "yếu tố phụ 2"],
    "fix_suggestion": {
      "approach": "mô tả cách fix ngắn gọn",
      "code_example": "đoạn code fix nếu đơn giản",
      "complexity": "simple | medium | complex"
    },
    "regression_test_needed": true,
    "similar_risks": [
      "Vị trí tương tự trong codebase có thể có cùng lỗi: ./src/modules/order/order.service.ts:123"
    ]
  },
  "next_action": "implement_feature (mode=fix)",
  "message": "Tìm thấy root cause, sẵn sàng để fix"
}
```

---

## Quy Trình Debug Có Hệ Thống

### Bước 1 — Đọc lỗi đúng cách
```
Stack trace TypeScript/Node.js cần đọc từ dưới lên:
  - Dòng cuối = điểm khởi phát lỗi ban đầu
  - Dòng đầu = nơi lỗi được bắt hoặc re-throw

Error: Cannot read properties of undefined (reading 'email')
    at UserService.createUser (user.service.ts:42:28)   ← Điểm lỗi
    at OrderService.placeOrder (order.service.ts:87:5)  ← Caller
    at POST /api/orders (order.controller.ts:23:3)      ← Entry point
         ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
         Đọc từ đây lên để hiểu context đầy đủ
```

### Bước 2 — Phân tích theo loại lỗi

#### Runtime Error — `Cannot read properties of undefined/null`
```typescript
// Nguyên nhân thường gặp:
// 1. Không check null/undefined trước khi access property
const user = await getUser(id) // Có thể trả null!
console.log(user.email)        // ❌ Crash nếu user là null

// Debug steps:
// → Tìm hàm getUser, xem khi nào trả null
// → Tìm nơi gọi getUser, xem có check null không
// → Xác định: tại sao lần này getUser trả null?

// Fix:
const user = await getUser(id)
if (!user) throw new NotFoundError('User', id)
console.log(user.email) // ✅ TypeScript biết user không null
```

#### Type Error — TypeScript compile error
```typescript
// Nguyên nhân thường gặp:
// 1. Type mismatch giữa function signature và caller
// 2. Missing property trong object literal
// 3. Incompatible generic types

// Debug steps:
// → Đọc toàn bộ error message (không chỉ dòng đầu)
// → TypeScript thường chỉ rõ "expected X, received Y"
// → Tìm type definition của function/variable đang lỗi

// Example error:
// Argument of type 'string | undefined' is not assignable
// to parameter of type 'string'
// → Nguyên nhân: email có thể undefined nhưng function đòi string
// → Fix: thêm null check hoặc non-null assertion có giải thích
```

#### Logic Error — Code chạy nhưng kết quả sai
```typescript
// Khó nhất — không có error message rõ ràng
// Debug approach: Binary search + logging

// Bước 1: Xác định "điểm cuối cùng đúng" và "điểm đầu tiên sai"
// Bước 2: Log intermediate values ở giữa khoảng đó
// Bước 3: Thu hẹp phạm vi đến function cụ thể
// Bước 4: Viết unit test reproduce lỗi trước khi fix

// Ví dụ: calculateDiscount trả kết quả sai
function calculateDiscount(price: number, coupon: Coupon): number {
  // Bug tiềm ẩn: percentage được lưu là 0.2 hay 20?
  return price * coupon.discountRate
  //            ^^^^^^^^^^^^^^^^^^^
  //  Nếu discountRate = 20 (không phải 0.2) → discount 20x giá!
}

// Fix: Làm rõ unit trong type
type Coupon = {
  discountRate: number // 0–1 (percentage / 100), e.g., 0.2 = 20% off
}
```

#### Performance Issue — Chậm, timeout, memory leak
```typescript
// Common patterns cần tìm:

// 1. N+1 query
for (const order of orders) {
  const user = await userRepo.findById(order.userId) // Query trong loop!
}
// Fix: batch query
const userIds = orders.map(o => o.userId)
const users = await userRepo.findByIds(userIds)

// 2. Missing await làm mất back-pressure
for (const item of largeArray) {
  processItem(item) // Không await → spawn hàng nghìn promises cùng lúc
}
// Fix: sequential hoặc batched
for (const item of largeArray) {
  await processItem(item) // Sequential
}
// Hoặc batched parallel:
const BATCH_SIZE = 10
for (let i = 0; i < largeArray.length; i += BATCH_SIZE) {
  await Promise.all(largeArray.slice(i, i + BATCH_SIZE).map(processItem))
}

// 3. Memory leak từ event listener không được cleanup
class Service {
  init() {
    emitter.on('event', this.handler) // Listener không bao giờ removed!
  }
  // Fix: implement cleanup
  destroy() {
    emitter.off('event', this.handler)
  }
}
```

#### Test Failure — Test fail sau thay đổi
```
Debug approach cho test failure:
  1. Đọc fail message: "Expected X, received Y"
  2. Tìm assert nào fail
  3. Hỏi: test fail vì code sai hay test expectation sai?
     → Nếu code sai: fix code
     → Nếu test sai (requirement thay đổi): update test + document lý do
     → Nếu test flaky: tìm non-determinism (timing, random, external state)
```

---

## Các Lỗi TypeScript/Node.js Phổ Biến

```yaml
"Cannot find module":
  cause: Import path sai, file không tồn tại, tsconfig paths chưa cấu hình
  fix: Kiểm tra relative path, tsconfig paths, file extension

"Promise<void> returned":
  cause: Quên await trong async function
  fix: Thêm await, hoặc return promise nếu muốn chain

"ECONNREFUSED":
  cause: Service (DB, Redis, external API) không chạy
  fix: Kiểm tra service status, env vars, network config

"Maximum call stack exceeded":
  cause: Infinite recursion
  fix: Tìm base case bị thiếu hoặc circular dependency

"Cannot access X before initialization":
  cause: Circular import hoặc sử dụng biến trước khi declare
  fix: Tái cấu trúc imports, dùng lazy initialization

"Unhandled Promise Rejection":
  cause: async function throw lỗi nhưng không được await hoặc .catch()
  fix: Thêm try/catch hoặc .catch() handler

"heap out of memory":
  cause: Memory leak, xử lý dataset quá lớn trong memory
  fix: Stream processing, pagination, tìm vòng lặp giữ reference
```

---

## Quy Tắc Khi Debug

```
✅ Tìm root cause — không chỉ làm cho test pass
✅ Luôn viết regression test để ngăn lỗi quay lại
✅ Tìm similar risks trong codebase sau khi fix
✅ Document lý do fix nếu solution không rõ ràng

❌ Không dùng try/catch để nuốt lỗi mà không log
❌ Không thêm if/else để bypass lỗi mà không hiểu nguyên nhân
❌ Không xoá test đang fail thay vì fix lỗi
❌ Không sửa bug và refactor cùng lúc trong 1 commit
```