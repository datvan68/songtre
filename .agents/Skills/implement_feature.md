# Skill: Implement Feature — Phát Triển Tính Năng Mới

> Skill lập trình tính năng mới cho dự án TypeScript/Node.js theo chuẩn chuyên nghiệp.
> **Quy tắc bất biến: Không có code nào được merge mà không có test đi kèm.**

---

## Metadata

```yaml
skill_id: implement_feature
version: 1.0.0
language: TypeScript
runtime: Node.js
supported_agents:
  - code-agent
depends_on_skills:
  - write_test          # Bắt buộc chạy sau skill này
  - explain_code        # Tuỳ chọn — để tạo tài liệu
tools_required:
  - gemini_generate
  - code_search
```

---

## Nguyên Tắc Cốt Lõi

```
1. Tính năng chưa có test = chưa hoàn thành
2. Code phải tự mô tả được (self-documenting)
3. Một function chỉ làm một việc (Single Responsibility)
4. Xử lý lỗi rõ ràng — không để lỗi âm thầm
5. Không hardcode — dùng config / env vars
```

---

## Quy Trình Thực Hiện

```
Bước 1: Đọc context       → code_search toàn bộ module liên quan
Bước 2: Thiết kế interface → định nghĩa types/interfaces trước
Bước 3: Viết skeleton      → function signatures + JSDoc
Bước 4: Implement logic    → từng function một
Bước 5: Tự review          → checklist bên dưới
Bước 6: Gọi write_test     → BẮT BUỘC trước khi trả kết quả
```

---

## Input Schema

```json
{
  "feature_name": "tên tính năng (snake_case)",
  "description": "mô tả chi tiết yêu cầu bằng Tiếng Việt",
  "acceptance_criteria": [
    "điều kiện 1 để tính năng được coi là hoàn thành",
    "điều kiện 2..."
  ],
  "context": {
    "module_path": "./src/modules/user",
    "related_files": [
      "./src/types/user.types.ts",
      "./src/repositories/user.repository.ts"
    ],
    "existing_patterns": "mô tả pattern đang dùng trong project (nếu có)"
  },
  "constraints": {
    "must_use_async_await": true,
    "error_handling": "throw | return_result_type",
    "max_function_lines": 40
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
    "files": [
      {
        "path": "./src/modules/user/user.service.ts",
        "action": "create | modify",
        "content": "<TypeScript code>",
        "exports": ["UserService", "createUser", "getUserById"]
      }
    ],
    "types_added": ["CreateUserDto", "UserResponse"],
    "dependencies_required": ["zod", "bcrypt"],
    "changes_summary": "tóm tắt thay đổi bằng Tiếng Việt",
    "test_hints": [
      "Test case: createUser với email trùng phải throw ConflictError",
      "Test case: getUserById với id không tồn tại phải trả null"
    ]
  },
  "next_action": "write_test",
  "message": "Đã implement xong, chuyển sang viết test"
}
```

---

## Chuẩn Code TypeScript

### Cấu trúc file bắt buộc
```typescript
// 1. Imports (external → internal → types)
import { injectable, inject } from 'tsyringe'
import { UserRepository } from '../repositories/user.repository'
import type { CreateUserDto, UserResponse } from '../types/user.types'

// 2. Constants (nếu có)
const MAX_LOGIN_ATTEMPTS = 5

// 3. Class / Function chính với JSDoc đầy đủ
/**
 * Tạo người dùng mới trong hệ thống
 * @param dto - Dữ liệu tạo user đã được validate
 * @returns UserResponse nếu thành công
 * @throws ConflictError nếu email đã tồn tại
 * @throws ValidationError nếu dữ liệu không hợp lệ
 */
export async function createUser(dto: CreateUserDto): Promise<UserResponse> {
  // implementation
}
```

### Xử lý lỗi — Result Pattern (ưu tiên)
```typescript
// Dùng Result type thay vì throw/catch cho business logic
type Result<T, E = Error> =
  | { success: true; data: T }
  | { success: false; error: E }

// ✅ ĐÚNG
async function findUser(id: string): Promise<Result<User, NotFoundError>> {
  const user = await db.users.findById(id)
  if (!user) return { success: false, error: new NotFoundError(id) }
  return { success: true, data: user }
}

// ❌ SAI — throw ẩn làm khó test
async function findUser(id: string): Promise<User> {
  const user = await db.users.findById(id)
  if (!user) throw new Error('not found') // Khó mock, khó assert
  return user
}
```

### Validation với Zod
```typescript
import { z } from 'zod'

// Định nghĩa schema trước, derive type từ schema
const CreateUserSchema = z.object({
  email: z.string().email('Email không hợp lệ'),
  password: z.string().min(8, 'Mật khẩu tối thiểu 8 ký tự'),
  name: z.string().min(1).max(100),
})

type CreateUserDto = z.infer<typeof CreateUserSchema>
```

### Async/Await — Quy tắc bắt buộc
```typescript
// ✅ Luôn dùng async/await, không dùng .then().catch() chain
const result = await someAsyncOperation()

// ✅ Parallel khi các operations độc lập nhau
const [user, permissions] = await Promise.all([
  getUser(id),
  getPermissions(id),
])

// ❌ Tuyệt đối không để unhandled promise rejection
someAsyncFn() // THIẾU await — lỗi âm thầm
```

---

## Cấu Trúc Thư Mục Chuẩn

```
src/
├── modules/
│   └── {feature}/
│       ├── {feature}.controller.ts   # HTTP layer
│       ├── {feature}.service.ts      # Business logic
│       ├── {feature}.repository.ts   # Data access
│       ├── {feature}.types.ts        # Types & interfaces
│       └── {feature}.errors.ts       # Custom errors
├── shared/
│   ├── types/
│   ├── utils/
│   └── errors/
tests/
├── unit/
│   └── modules/{feature}/
└── integration/
    └── {feature}/
```

---

## Checklist Tự Review Trước Khi Trả Kết Quả

```
Code Quality
  [ ] Không có any type (trừ trường hợp thực sự không tránh được)
  [ ] Tất cả async function đều có await hoặc return Promise rõ ràng
  [ ] Không có console.log còn sót (dùng logger service)
  [ ] Tất cả error path đều được handle
  [ ] Không có magic numbers (dùng named constants)

Architecture
  [ ] Không có business logic trong controller
  [ ] Không có SQL / DB query trong service (để trong repository)
  [ ] Dependency injection đúng cách (không new() trực tiếp)
  [ ] Circular dependency không tồn tại

Readability
  [ ] Function name là động từ mô tả hành động
  [ ] Variable name rõ ý nghĩa, không viết tắt tuỳ tiện
  [ ] JSDoc đủ cho public API
  [ ] File không dài quá 300 dòng
```