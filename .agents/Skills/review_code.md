# Skill: Review Code — Kiểm Tra Chất Lượng Code

> Skill review code tự động cho TypeScript/Node.js trước khi merge.
> Một PR không được merge nếu chưa qua review và chưa có test.

---

## Metadata

```yaml
skill_id: review_code
version: 1.0.0
language: TypeScript
supported_agents:
  - review-agent
triggers:
  - Tự động sau implement_feature + write_test hoàn thành
  - Khi có PR mới cần review
  - Khi orchestrator yêu cầu kiểm tra code quality
tools_required:
  - code_search
  - explain_code
  - gemini_generate
```

---

## Input Schema

```json
{
  "review_target": {
    "type": "files | git_diff | pr",
    "content": "<code hoặc diff cần review>",
    "file_paths": ["./src/modules/user/user.service.ts"],
    "pr_description": "mô tả PR (tuỳ chọn)"
  },
  "context": {
    "related_test_files": ["./tests/unit/user.service.test.ts"],
    "acceptance_criteria": ["danh sách tiêu chí cần đáp ứng"],
    "environment": "development | staging | production"
  },
  "review_level": "standard | strict | security_focus"
}
```

---

## Output Schema

```json
{
  "agent_id": "review-agent",
  "status": "approved | changes_requested | blocked",
  "result": {
    "verdict": "approved | changes_required | blocked",
    "verdict_reason": "lý do bằng Tiếng Việt",
    "issues": [
      {
        "id": "R001",
        "severity": "critical | warning | suggestion",
        "category": "security | logic | performance | test | style",
        "file": "./src/modules/user/user.service.ts",
        "line": 42,
        "description": "mô tả vấn đề bằng Tiếng Việt",
        "current_code": "code hiện tại",
        "suggested_code": "code đề xuất thay thế",
        "must_fix": true
      }
    ],
    "test_coverage_check": {
      "has_tests": true,
      "test_file_path": "./tests/unit/user.service.test.ts",
      "missing_test_cases": ["getUserById với id null chưa được test"],
      "coverage_adequate": true
    },
    "summary": {
      "total_issues": 3,
      "critical": 0,
      "warnings": 2,
      "suggestions": 1
    }
  },
  "next_action": "implement_feature (fix issues) | null (nếu approved)",
  "message": "2 warnings cần sửa trước khi merge"
}
```

---

## Tiêu Chí Review — Theo Mức Độ Ưu Tiên

### 🔴 Critical — Chặn merge ngay lập tức

```typescript
// [CRITICAL] SQL Injection — không dùng parameterized query
const query = `SELECT * FROM users WHERE email = '${email}'`
// ✅ Fix: dùng parameterized query
const query = db.query('SELECT * FROM users WHERE email = $1', [email])

// [CRITICAL] Lưu password plaintext
await db.users.create({ email, password }) // password chưa hash!
// ✅ Fix: hash trước khi lưu
await db.users.create({ email, password: await bcrypt.hash(password, 10) })

// [CRITICAL] Expose sensitive data trong response
return { id, email, password, apiKey } // Trả về thông tin nhạy cảm!
// ✅ Fix: chỉ trả về field cần thiết
return { id, email }

// [CRITICAL] Bỏ qua error handling cho critical operation
await db.users.delete(userId) // Không check result, không handle lỗi
// ✅ Fix: handle result và throw nếu cần
const deleted = await db.users.delete(userId)
if (!deleted) throw new NotFoundError(userId)

// [CRITICAL] Missing authentication middleware trên route nhạy cảm
router.delete('/users/:id', deleteUser) // Ai cũng xoá được!
// ✅ Fix:
router.delete('/users/:id', authenticate, authorize('admin'), deleteUser)
```

### 🟡 Warning — Nên sửa trước khi merge

```typescript
// [WARNING] Sử dụng any type
function processData(data: any): any { ... }
// ✅ Fix: define proper types
function processData(data: ProcessDataInput): ProcessDataResult { ... }

// [WARNING] Không xử lý Promise rejection
fetch(url).then(r => r.json()) // Nếu fetch fail thì sao?
// ✅ Fix:
const result = await fetch(url)
if (!result.ok) throw new ExternalServiceError(result.status)

// [WARNING] Magic numbers
if (attempts > 3) { ... }     // 3 là gì?
// ✅ Fix:
const MAX_LOGIN_ATTEMPTS = 3
if (attempts > MAX_LOGIN_ATTEMPTS) { ... }

// [WARNING] God function — làm quá nhiều thứ
async function handleUserRegistration(dto) {
  // validate input (20 dòng)
  // hash password (5 dòng)
  // save to DB (10 dòng)
  // send welcome email (15 dòng)
  // create audit log (10 dòng)
}
// ✅ Fix: tách thành các function nhỏ, single responsibility
```

### 🟢 Suggestion — Cải thiện chất lượng

```typescript
// [SUGGESTION] Tên biến không rõ ý nghĩa
const d = new Date()
const u = await getUser(id)
// ✅ Better:
const registrationDate = new Date()
const existingUser = await getUser(id)

// [SUGGESTION] Điều kiện phức tạp, khó đọc
if (user && user.role === 'admin' && user.isActive && !user.isBanned) { ... }
// ✅ Extract thành named predicate:
const canAccess = (user: User) =>
  user.role === 'admin' && user.isActive && !user.isBanned
if (canAccess(user)) { ... }

// [SUGGESTION] Lặp code (DRY violation)
// Cùng logic validate email xuất hiện ở 3 file khác nhau
// ✅ Fix: extract thành shared utility
```

---

## Checklist Review Bắt Buộc

### Kiểm tra Test
```
[ ] File test tồn tại và đặt đúng path (tests/unit/ hoặc tests/integration/)
[ ] Happy path được test
[ ] Error cases được test (không chỉ test khi mọi thứ OK)
[ ] Tên test cases rõ ràng: "nên [kết quả] khi [điều kiện]"
[ ] Không có test chỉ để pass coverage mà không assert gì có nghĩa
[ ] Mock đúng — unit test không gọi DB/API thật
```

### Kiểm tra Security
```
[ ] Input được validate trước khi xử lý
[ ] Không lưu/log sensitive data (password, token, secret)
[ ] Authentication/Authorization đúng chỗ
[ ] Không có SQL injection / NoSQL injection
[ ] Rate limiting trên các endpoint public
[ ] Error message không tiết lộ internal info
```

### Kiểm tra TypeScript
```
[ ] Không có any type không có lý do
[ ] Return type được khai báo tường minh cho public function
[ ] Interface/Type được dùng nhất quán
[ ] Không dùng ts-ignore trừ có comment giải thích
[ ] Strict mode không bị tắt
```

### Kiểm tra Logic
```
[ ] Tất cả code path đều được handle
[ ] Không có off-by-one errors (< vs <=)
[ ] Điều kiện boolean không bị đảo ngược
[ ] Async/await đúng — không bỏ quên await
[ ] Không có race condition tiềm ẩn
```

---

## Quy Tắc Ra Verdict

```yaml
approved:
  - Không có critical issues
  - Có test đầy đủ (happy path + error cases)
  - Không có security vulnerabilities
  - Warning ≤ 3 (hoặc đã được giải thích rõ lý do chấp nhận)

changes_requested:
  - Có warning cần sửa
  - Test thiếu một số edge cases quan trọng
  - Code style không nhất quán với codebase

blocked:
  - Có bất kỳ critical issue nào
  - Hoàn toàn không có test
  - Security vulnerability rõ ràng
  - Logic sai so với acceptance criteria
```