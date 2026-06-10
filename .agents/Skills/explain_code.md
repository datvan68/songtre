# Skill: Explain Code — Giải Thích Code

> Skill giúp agent phân tích và giải thích code TypeScript/Node.js ở nhiều cấp độ khác nhau:
> cho người mới, cho người review, hoặc để tạo tài liệu kỹ thuật.

---

## Metadata

```yaml
skill_id: explain_code
version: 1.0.0
language: TypeScript
supported_agents:
  - review-agent
  - doc-agent
  - orchestrator
tools_required:
  - gemini_generate
  - code_search
use_cases:
  - Onboarding developer mới vào module
  - Tạo tài liệu API / architecture
  - Code review — giải thích tại sao code có vấn đề
  - Debug — trace luồng xử lý
```

---

## Các Chế Độ Giải Thích

| Mode | Đối tượng | Output |
|---|---|---|
| `beginner` | Developer mới, junior | Giải thích từng dòng, ví dụ thực tế |
| `technical` | Senior dev, reviewer | Tập trung pattern, trade-off, potential issues |
| `architecture` | Tech lead, architect | Luồng dữ liệu, dependency, design decision |
| `debug` | Developer đang fix bug | Trace execution path, identify failure points |
| `review` | Code reviewer | Highlight vấn đề, suggest improvement |
| `docs` | Tạo tài liệu | JSDoc, README section, API reference |

---

## Input Schema

```json
{
  "mode": "beginner | technical | architecture | debug | review | docs",
  "code": "đoạn code TypeScript cần giải thích",
  "file_path": "./src/modules/payment/payment.service.ts",
  "focus": "phần cụ thể cần giải thích sâu (tuỳ chọn)",
  "context": {
    "related_files": ["./src/types/payment.types.ts"],
    "error_if_any": "thông báo lỗi nếu đang debug (tuỳ chọn)",
    "question": "câu hỏi cụ thể của developer (tuỳ chọn)"
  },
  "output_format": "markdown | inline_comments | jsdoc"
}
```

---

## Output Schema

```json
{
  "agent_id": "review-agent",
  "status": "success | error",
  "result": {
    "explanation": "nội dung giải thích chính bằng Tiếng Việt",
    "annotated_code": "code với comment giải thích được chèn vào (nếu output_format=inline_comments)",
    "key_concepts": ["concept 1", "concept 2"],
    "potential_issues": [
      {
        "line": 42,
        "severity": "warning | error | info",
        "issue": "mô tả vấn đề",
        "suggestion": "cách khắc phục"
      }
    ],
    "related_docs": ["link tài liệu liên quan"]
  },
  "next_action": null,
  "message": "Đã phân tích xong"
}
```

---

## Ví Dụ Theo Từng Mode

### Mode: `technical` — Phân tích chuyên sâu
```
Input code:
  async function processPayment(orderId: string): Promise<Result<Payment>> {
    const order = await orderRepo.findById(orderId)
    if (!order) return { success: false, error: new NotFoundError(orderId) }
    const payment = await paymentGateway.charge(order.amount, order.currency)
    await orderRepo.updateStatus(orderId, 'paid')
    return { success: true, data: payment }
  }

Output explanation mong đợi:
  - Nhận diện pattern: Result type thay vì throw/catch
  - Race condition tiềm ẩn: nếu updateStatus fail sau khi charge thành công
  - Missing: transaction / idempotency key
  - Missing: retry logic cho payment gateway
  - Suggest: dùng outbox pattern hoặc saga cho distributed transaction
```

### Mode: `debug` — Trace lỗi
```
Khi nhận error_if_any, agent phải:
  1. Xác định dòng code nào có thể gây ra lỗi này
  2. Trace ngược execution path từ điểm lỗi
  3. Liệt kê các state có thể dẫn đến lỗi
  4. Đề xuất thêm logging / assertion để confirm nguyên nhân
```

### Mode: `review` — Code review chuyên nghiệp
```
Phân tích theo thứ tự ưu tiên:
  🔴 Critical (phải sửa trước khi merge):
    - Security vulnerabilities
    - Logic errors / incorrect behaviour
    - Missing error handling cho critical path

  🟡 Warning (nên sửa):
    - Performance issues
    - Code smell, violation of SOLID
    - Missing test coverage cho edge cases

  🟢 Suggestion (cân nhắc):
    - Readability improvement
    - Better naming
    - More idiomatic TypeScript
```

---

## Chuẩn Viết Giải Thích

### Cấu trúc giải thích chuẩn (mode `technical` / `architecture`)
```markdown
## Tổng quan
[1–2 câu: file/function này làm gì trong hệ thống]

## Luồng xử lý
[Mô tả step-by-step, dùng số thứ tự]
1. ...
2. ...

## Design decisions
[Tại sao code viết như vậy — không phải cách khác]

## Vấn đề tiềm ẩn
[Những gì có thể xảy ra trong production]

## Đề xuất cải thiện
[Concrete, actionable — không chung chung]
```

### Quy tắc khi giải thích
```
✅ Dùng Tiếng Việt cho giải thích, giữ Tiếng Anh cho tên hàm/biến
✅ Giải thích "tại sao" chứ không chỉ "cái gì"
✅ Đưa ví dụ cụ thể khi giải thích concept trừu tượng
✅ Chỉ ra potential issue thay vì chỉ nói "code sai"
✅ Khi review, luôn đề xuất code thay thế cụ thể

❌ Không giải thích theo kiểu "dòng này print ra màn hình"
❌ Không dùng "obviously" hay "clearly" — nếu rõ thì không cần giải thích
❌ Không đánh giá chủ quan như "code này rất tệ"
❌ Không bỏ sót security issues dù nhỏ
```

---

## Tích Hợp Với Các Skill Khác

```yaml
explain_code → write_test:
  Khi phát hiện code thiếu test coverage
  → Tự động trigger write_test với danh sách function cần test

explain_code → implement_feature:
  Khi phát hiện bug trong quá trình review
  → Trả về potential_issues để code-agent fix

explain_code → docs:
  Khi mode=docs
  → Output được dùng trực tiếp làm nội dung README / API docs
```