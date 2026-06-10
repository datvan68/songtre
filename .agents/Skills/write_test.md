# Skill: Write Test — Viết Unit & Integration Test

> Skill viết test tự động cho TypeScript/Node.js.
> Test không phải là "optional" — đây là phần không thể tách rời của một tính năng.

---

## Metadata

```yaml
skill_id: write_test
version: 1.0.0
language: TypeScript
framework:
  unit: Vitest
  integration: Vitest + Supertest
  coverage: @vitest/coverage-v8
supported_agents:
  - test-agent
  - code-agent
triggers:
  - Tự động sau khi implement_feature hoàn thành
  - Tự động sau khi bug_fix hoàn thành
  - Thủ công khi được orchestrator yêu cầu
coverage_targets:
  unit: 80%        # Tối thiểu
  integration: 60% # Tối thiểu
  critical_paths: 100% # Bắt buộc
```

---

## Tại Sao Vitest (không phải Jest)?

```
✅ Vitest — Lý do chọn cho TypeScript/Node.js mới:
  - Native TypeScript (không cần babel transform)
  - Nhanh hơn Jest 2–5x nhờ Vite engine
  - API tương thích Jest (migrate dễ)
  - Built-in coverage với @vitest/coverage-v8
  - Hot module reload khi chạy watch mode
  - Hỗ trợ ESM tốt hơn Jest
```

---

## Thiết Lập Môi Trường (Một Lần Duy Nhất)

### Cài đặt
```bash
npm install -D vitest @vitest/coverage-v8 supertest @types/supertest
```

### vitest.config.ts
```typescript
import { defineConfig } from 'vitest/config'
import tsconfigPaths from 'vite-tsconfig-paths'

export default defineConfig({
  plugins: [tsconfigPaths()],
  test: {
    globals: true,
    environment: 'node',
    setupFiles: ['./tests/setup.ts'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
      thresholds: {
        lines: 80,
        functions: 80,
        branches: 75,
        statements: 80,
      },
      exclude: [
        'node_modules/**',
        'tests/**',
        '**/*.types.ts',
        '**/*.config.ts',
        '**/index.ts',       // Re-export files
      ],
    },
    include: ['tests/**/*.test.ts'],
    exclude: ['node_modules'],
  },
})
```

### tests/setup.ts
```typescript
import { beforeAll, afterAll, afterEach } from 'vitest'

// Reset mocks sau mỗi test để tránh test pollution
afterEach(() => {
  vi.restoreAllMocks()
})

// Database / external service setup nếu cần
beforeAll(async () => {
  // Setup test database, start mock servers...
})

afterAll(async () => {
  // Cleanup connections...
})
```

### package.json scripts
```json
{
  "scripts": {
    "test": "vitest run",
    "test:watch": "vitest",
    "test:coverage": "vitest run --coverage",
    "test:unit": "vitest run tests/unit",
    "test:integration": "vitest run tests/integration"
  }
}
```

---

## Input Schema

```json
{
  "test_type": "unit | integration | both",
  "target": {
    "file_path": "./src/modules/user/user.service.ts",
    "function_names": ["createUser", "getUserById"],
    "feature_description": "mô tả tính năng bằng Tiếng Việt"
  },
  "acceptance_criteria": [
    "createUser với email trùng phải trả error ConflictError",
    "getUserById với id không tồn tại phải trả null"
  ],
  "context": {
    "existing_code": "<nội dung file cần test>",
    "related_types": "<types/interfaces liên quan>",
    "mocking_required": ["UserRepository", "EmailService"]
  }
}
```

---

## Output Schema

```json
{
  "agent_id": "test-agent",
  "status": "success | error",
  "result": {
    "files": [
      {
        "path": "./tests/unit/modules/user/user.service.test.ts",
        "type": "unit",
        "content": "<TypeScript test code>",
        "test_count": 12,
        "covers_functions": ["createUser", "getUserById"]
      }
    ],
    "coverage_estimate": {
      "lines": "85%",
      "branches": "78%",
      "critical_paths_covered": true
    },
    "test_cases_summary": [
      "✅ createUser — happy path",
      "✅ createUser — email trùng → ConflictError",
      "✅ getUserById — tìm thấy",
      "✅ getUserById — không tìm thấy → null"
    ]
  },
  "next_action": null,
  "message": "Đã viết 12 test cases, ước tính coverage 85%"
}
```

---

## Chuẩn Viết Unit Test

### Cấu trúc file chuẩn — AAA Pattern
```typescript
// tests/unit/modules/user/user.service.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { UserService } from '@/modules/user/user.service'
import { UserRepository } from '@/modules/user/user.repository'
import { ConflictError } from '@/shared/errors'

// Mock dependencies — chỉ mock những gì cần thiết
vi.mock('@/modules/user/user.repository')

describe('UserService', () => {
  let userService: UserService
  let mockUserRepo: vi.Mocked<UserRepository>

  beforeEach(() => {
    // Tạo fresh instance trước mỗi test — không share state
    mockUserRepo = new UserRepository() as vi.Mocked<UserRepository>
    userService = new UserService(mockUserRepo)
  })

  // --- createUser ---
  describe('createUser', () => {
    it('nên tạo user thành công khi dữ liệu hợp lệ', async () => {
      // Arrange — chuẩn bị dữ liệu và mock
      const dto = { email: 'test@example.com', password: 'Password123', name: 'Test User' }
      mockUserRepo.findByEmail.mockResolvedValue(null)
      mockUserRepo.create.mockResolvedValue({ id: 'uuid-1', ...dto })

      // Act — gọi function cần test
      const result = await userService.createUser(dto)

      // Assert — kiểm tra kết quả
      expect(result.success).toBe(true)
      expect(result.data).toMatchObject({ email: dto.email, name: dto.name })
      expect(mockUserRepo.create).toHaveBeenCalledOnce()
    })

    it('nên trả ConflictError khi email đã tồn tại', async () => {
      // Arrange
      const dto = { email: 'existing@example.com', password: 'Password123', name: 'Test' }
      mockUserRepo.findByEmail.mockResolvedValue({ id: 'existing-id', ...dto })

      // Act
      const result = await userService.createUser(dto)

      // Assert
      expect(result.success).toBe(false)
      expect(result.error).toBeInstanceOf(ConflictError)
      expect(mockUserRepo.create).not.toHaveBeenCalled()
    })

    it('nên không lưu mật khẩu plaintext', async () => {
      // Arrange
      const dto = { email: 'new@example.com', password: 'PlainPassword', name: 'Test' }
      mockUserRepo.findByEmail.mockResolvedValue(null)
      mockUserRepo.create.mockResolvedValue({ id: 'uuid-1', ...dto })

      // Act
      await userService.createUser(dto)

      // Assert — password phải được hash trước khi lưu
      const savedData = mockUserRepo.create.mock.calls[0][0]
      expect(savedData.password).not.toBe('PlainPassword')
      expect(savedData.password).toMatch(/^\$2[ab]\$/) // bcrypt format
    })
  })
})
```

### Naming convention cho test cases
```typescript
// Format: "nên [kết quả mong đợi] khi [điều kiện]"
it('nên trả user khi id hợp lệ', ...)
it('nên trả null khi id không tồn tại', ...)
it('nên throw ValidationError khi email sai format', ...)
it('nên gọi repository đúng 1 lần', ...)

// ❌ Tên tệ — không rõ expect gì
it('test createUser', ...)
it('works', ...)
it('should work correctly', ...)
```

---

## Chuẩn Viết Integration Test

### Cấu trúc với Supertest
```typescript
// tests/integration/user.integration.test.ts
import { describe, it, expect, beforeAll, afterAll } from 'vitest'
import request from 'supertest'
import { app } from '@/app'
import { db } from '@/shared/database'
import { seedTestUser, cleanupTestUsers } from '../helpers/user.helper'

describe('POST /api/users — Integration', () => {
  beforeAll(async () => {
    // Dùng database test riêng biệt — KHÔNG dùng production DB
    await db.connect(process.env.TEST_DATABASE_URL)
  })

  afterAll(async () => {
    await cleanupTestUsers()
    await db.disconnect()
  })

  it('nên tạo user và trả 201 khi request hợp lệ', async () => {
    const response = await request(app)
      .post('/api/users')
      .send({ email: 'new@example.com', password: 'Password123', name: 'New User' })
      .expect(201)

    expect(response.body).toMatchObject({
      data: {
        email: 'new@example.com',
        name: 'New User',
      },
    })
    // Đảm bảo password KHÔNG có trong response
    expect(response.body.data.password).toBeUndefined()
  })

  it('nên trả 409 khi email đã tồn tại', async () => {
    // Seed user vào DB trước
    await seedTestUser({ email: 'existing@example.com' })

    const response = await request(app)
      .post('/api/users')
      .send({ email: 'existing@example.com', password: 'Password123', name: 'Dup' })
      .expect(409)

    expect(response.body.error.code).toBe('CONFLICT')
  })

  it('nên trả 422 khi email sai format', async () => {
    const response = await request(app)
      .post('/api/users')
      .send({ email: 'not-an-email', password: 'Password123', name: 'Test' })
      .expect(422)

    expect(response.body.error.code).toBe('VALIDATION_ERROR')
    expect(response.body.error.fields).toContain('email')
  })
})
```

---

## Danh Sách Test Cases Bắt Buộc

Với **mỗi function/endpoint**, agent phải viết tối thiểu các test cases sau:

```yaml
Bắt buộc — Happy path:
  - [ ] Input hợp lệ → output đúng format
  - [ ] Tất cả acceptance criteria được cover

Bắt buộc — Error cases:
  - [ ] Input thiếu field bắt buộc
  - [ ] Input sai kiểu dữ liệu
  - [ ] Resource không tồn tại (404)
  - [ ] Duplicate resource (409)

Bắt buộc — Boundary cases:
  - [ ] String rỗng ""
  - [ ] Giá trị tại boundary (min length, max length)
  - [ ] Null / undefined input

Bắt buộc — Security:
  - [ ] Password/secret không xuất hiện trong response
  - [ ] SQL injection pattern trong input (nếu dùng raw query)
  - [ ] Auth header thiếu → 401 (cho protected routes)

Tuỳ chọn — Edge cases:
  - [ ] Concurrent requests (race condition)
  - [ ] Extremely large input
  - [ ] Special characters trong string fields
```

---

## Quy Tắc Cốt Lõi

```
✅ Mỗi test phải độc lập — không phụ thuộc vào thứ tự chạy
✅ Mỗi test chỉ test MỘT behaviour
✅ Mock đúng layer — unit test mock repository, integration test dùng DB thật
✅ Test data phải được cleanup sau mỗi test/suite
✅ Tên test phải đọc như một câu mô tả behaviour

❌ Không share mutable state giữa các test cases
❌ Không dùng production database cho integration test
❌ Không mock quá nhiều — test sẽ mất giá trị
❌ Không bỏ qua test cho error paths với lý do "hiếm xảy ra"
❌ Không viết test chỉ để đạt coverage % mà không assert meaningful
```