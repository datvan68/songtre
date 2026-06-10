# Skill: Refactor Code — Tái Cấu Trúc Code

> Skill tái cấu trúc code TypeScript/Node.js: cải thiện chất lượng mà **không thay đổi behaviour**.
> **Quy tắc vàng: Test phải pass trước và sau khi refactor — nếu không có test, viết test trước.**

---

## Metadata

```yaml
skill_id: refactor_code
version: 1.0.0
language: TypeScript
supported_agents:
  - code-agent
  - review-agent
prerequisite:
  - "Test phải tồn tại và pass trước khi refactor"
  - "Nếu chưa có test → gọi write_test trước"
tools_required:
  - code_search
  - gemini_generate
```

---

## Nguyên Tắc Cốt Lõi

```
1. Refactor = thay đổi cấu trúc, KHÔNG thay đổi behaviour
2. Luôn có test xanh trước khi bắt đầu
3. Refactor từng bước nhỏ, commit từng bước
4. Nếu tìm thấy bug trong lúc refactor → ghi lại, KHÔNG sửa lúc đó
5. Dừng ngay nếu test bắt đầu fail
```

---

## Loại Refactor

| Type | Mô tả | Khi nào dùng |
|---|---|---|
| `extract_function` | Tách đoạn code thành function riêng | Function quá dài > 40 dòng |
| `extract_module` | Tách logic ra file/module riêng | File > 300 dòng, nhiều concerns |
| `rename` | Đổi tên cho rõ nghĩa hơn | Tên không tự mô tả được |
| `simplify_logic` | Đơn giản hoá điều kiện phức tạp | Nested if/else > 3 cấp |
| `remove_duplication` | Hợp nhất code bị lặp | Logic giống nhau ở nhiều chỗ |
| `improve_types` | Cải thiện TypeScript types | Quá nhiều any, loose typing |
| `error_handling` | Chuẩn hoá cách xử lý lỗi | Mixed throw/return, inconsistent |

---

## Input Schema

```json
{
  "refactor_type": "extract_function | extract_module | rename | simplify_logic | remove_duplication | improve_types | error_handling",
  "target": {
    "file_path": "./src/modules/order/order.service.ts",
    "function_name": "processOrder",
    "code_smell": "mô tả vấn đề hiện tại"
  },
  "existing_tests": ["./tests/unit/order/order.service.test.ts"],
  "constraints": {
    "preserve_public_api": true,
    "max_diff_size": "medium"
  }
}
```

---

## Output Schema

```json
{
  "agent_id": "code-agent",
  "status": "success | error",
  "result": {
    "files_changed": [
      {
        "path": "./src/modules/order/order.service.ts",
        "action": "modify",
        "content": "<TypeScript code sau refactor>"
      },
      {
        "path": "./src/modules/order/order.helpers.ts",
        "action": "create",
        "content": "<extracted helper functions>"
      }
    ],
    "changes_summary": "tóm tắt thay đổi bằng Tiếng Việt",
    "behaviour_preserved": true,
    "public_api_changed": false,
    "bugs_found_during_refactor": [
      "Phát hiện: getUserById không handle case id là empty string — cần tạo issue riêng"
    ]
  },
  "next_action": "write_test (nếu cần update test) | review_code",
  "message": "Refactor hoàn thành, public API không thay đổi"
}
```

---

## Patterns Refactor Phổ Biến Trong TypeScript

### Extract Function — Tách function quá dài
```typescript
// ❌ TRƯỚC — một function làm quá nhiều việc (60 dòng)
async function createOrder(dto: CreateOrderDto) {
  // 15 dòng validate input
  if (!dto.items.length) throw new Error(...)
  if (dto.items.some(i => i.quantity <= 0)) throw new Error(...)
  // ... 10 dòng validate nữa

  // 20 dòng tính giá
  let subtotal = 0
  for (const item of dto.items) { ... }
  const tax = subtotal * TAX_RATE
  // ... 15 dòng tính phí ship, discount, v.v.

  // 25 dòng tạo order
  const order = await orderRepo.create({ ... })
  await inventoryService.reserve(dto.items)
  await notificationService.sendConfirmation(order.id)
  return order
}

// ✅ SAU — mỗi function một trách nhiệm
async function createOrder(dto: CreateOrderDto) {
  validateOrderItems(dto.items)               // Validate
  const pricing = calculateOrderPricing(dto)  // Tính giá
  return await persistOrder(dto, pricing)     // Lưu và notify
}

function validateOrderItems(items: OrderItem[]): void { ... }
function calculateOrderPricing(dto: CreateOrderDto): OrderPricing { ... }
async function persistOrder(dto, pricing): Promise<Order> { ... }
```

### Remove Duplication — Loại bỏ code lặp
```typescript
// ❌ TRƯỚC — logic validate email lặp ở 3 nơi
// user.service.ts
if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error('Invalid email')

// auth.service.ts
if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error('Invalid email')

// invite.service.ts
if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error('Invalid email')

// ✅ SAU — extract vào shared utility
// src/shared/validators/email.validator.ts
export function assertValidEmail(email: string): void {
  const emailSchema = z.string().email()
  const result = emailSchema.safeParse(email)
  if (!result.success) throw new ValidationError('email', 'Email không hợp lệ')
}

// Tất cả nơi dùng:
import { assertValidEmail } from '@/shared/validators'
assertValidEmail(email)
```

### Improve Types — Thay thế any và weak types
```typescript
// ❌ TRƯỚC — loose typing
function processWebhook(payload: any) {
  const event = payload.type
  const data = payload.data as any
  return { event, userId: data.user_id }
}

// ✅ SAU — strong typing với discriminated union
type WebhookPayload =
  | { type: 'user.created'; data: { user_id: string; email: string } }
  | { type: 'payment.completed'; data: { payment_id: string; amount: number } }
  | { type: 'order.cancelled'; data: { order_id: string; reason: string } }

function processWebhook(payload: WebhookPayload) {
  switch (payload.type) {
    case 'user.created':
      return { event: payload.type, userId: payload.data.user_id }
    case 'payment.completed':
      return { event: payload.type, paymentId: payload.data.payment_id }
    // TypeScript báo lỗi nếu bỏ sót case
  }
}
```

### Simplify Conditionals — Làm phẳng if/else lồng nhau
```typescript
// ❌ TRƯỚC — deeply nested (arrow anti-pattern)
async function getAccessLevel(userId: string) {
  const user = await getUser(userId)
  if (user) {
    if (user.isActive) {
      if (!user.isBanned) {
        if (user.subscription) {
          if (user.subscription.isValid()) {
            return 'premium'
          } else {
            return 'expired'
          }
        } else {
          return 'free'
        }
      } else {
        return 'banned'
      }
    } else {
      return 'inactive'
    }
  } else {
    return 'not_found'
  }
}

// ✅ SAU — early return (guard clauses)
async function getAccessLevel(userId: string): Promise<AccessLevel> {
  const user = await getUser(userId)

  if (!user)              return 'not_found'
  if (!user.isActive)     return 'inactive'
  if (user.isBanned)      return 'banned'
  if (!user.subscription) return 'free'
  if (!user.subscription.isValid()) return 'expired'

  return 'premium'
}
```

### Error Handling Standardization
```typescript
// ❌ TRƯỚC — mixed error handling styles
// File A: throw string
throw 'User not found'

// File B: throw generic Error
throw new Error('Conflict')

// File C: return null (không nhất quán)
return null

// ✅ SAU — custom error hierarchy + Result type nhất quán
// src/shared/errors/index.ts
export class AppError extends Error {
  constructor(
    message: string,
    public readonly code: string,
    public readonly statusCode: number
  ) {
    super(message)
    this.name = this.constructor.name
  }
}

export class NotFoundError extends AppError {
  constructor(resource: string, id: string) {
    super(`${resource} với id '${id}' không tồn tại`, 'NOT_FOUND', 404)
  }
}

export class ConflictError extends AppError {
  constructor(message: string) {
    super(message, 'CONFLICT', 409)
  }
}
```

---

## Quy Trình Refactor An Toàn

```
Bước 1: Xác nhận test tồn tại và pass
  → npm run test:unit -- <file_liên_quan>
  → Nếu fail: DỪNG, báo lỗi

Bước 2: Thực hiện refactor từng bước nhỏ
  → Mỗi bước: 1 loại thay đổi
  → Không mix refactor + fix bug + thêm feature

Bước 3: Chạy test sau mỗi bước
  → Nếu test fail: revert bước đó, tìm nguyên nhân

Bước 4: Update test nếu cần (chỉ khi rename/restructure)
  → Không được xoá test case để cho pass
  → Không được bỏ assert để cho pass

Bước 5: Trả kết quả với diff rõ ràng
  → Liệt kê file nào thay đổi
  → Tóm tắt "trước → sau" bằng Tiếng Việt
  → Ghi lại bug tìm thấy (nếu có) để tạo issue riêng
```