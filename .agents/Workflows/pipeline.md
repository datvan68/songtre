---
description: > Định nghĩa các pipeline chuẩn cho từng loại task phổ biến trong dự án Software Development / DevOps.
---

# Pipeline — Luồng Xử Lý Cụ Thể

---

## Metadata

```yaml
version: 1.0.0
managed_by: orchestrator
trigger: orchestrator nhận task và map vào pipeline phù hợp
```

---

## Pipeline 1: Feature Development

**Khi nào dùng:** Phát triển tính năng mới từ requirement

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  code-agent │────▶│ test-agent  │────▶│review-agent │────▶│  doc-agent  │
│ sinh code   │     │ viết tests  │     │ review +    │     │ cập nhật    │
│             │     │             │     │ security    │     │ tài liệu    │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
```

### Các bước chi tiết

```yaml
pipeline_id: feature_development
steps:
  - step: 1
    agent: code-agent
    skill: code_gen
    action: "Sinh code theo requirement"
    input_from: user
    output_to: step_2
    on_failure: stop

  - step: 2
    agent: test-agent
    skill: code_gen (mode=test)
    action: "Viết unit test cho code vừa sinh"
    input_from: step_1
    output_to: step_3
    on_failure: retry_once

  - step: 3
    agent: review-agent
    skill: search + summarize
    action: "Review code quality, security, performance"
    input_from: step_1, step_2
    output_to: step_4
    on_failure: stop
    gate: "review-agent phải approve trước khi tiếp tục"

  - step: 4
    agent: doc-agent
    skill: code_gen (mode=document)
    action: "Cập nhật README, docstring, changelog"
    input_from: step_1, step_3
    output_to: orchestrator
    on_failure: warn_only
```

---

## Pipeline 2: Bug Fix

**Khi nào dùng:** Sửa lỗi từ bug report hoặc log

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  code-agent │────▶│ test-agent  │────▶│review-agent │
│ fix bug     │     │ verify fix  │     │ confirm     │
└─────────────┘     └─────────────┘     └─────────────┘
```

```yaml
pipeline_id: bug_fix
steps:
  - step: 1
    agent: orchestrator
    skill: search
    action: "Tìm root cause từ log/error message"
    input_from: user
    output_to: step_2

  - step: 2
    agent: code-agent
    skill: code_gen (mode=fix)
    action: "Sửa bug dựa theo root cause"
    input_from: step_1
    output_to: step_3
    on_failure: stop

  - step: 3
    agent: test-agent
    skill: code_gen (mode=test)
    action: "Viết regression test cho bug này"
    input_from: step_2
    output_to: step_4
    on_failure: warn_only

  - step: 4
    agent: review-agent
    skill: summarize
    action: "Xác nhận fix đúng, không introduce bug mới"
    input_from: step_2, step_3
    output_to: orchestrator
    gate: must_approve
```

---

## Pipeline 3: DevOps / Infrastructure

**Khi nào dùng:** Tạo/cập nhật Dockerfile, k8s manifest, CI/CD pipeline, Terraform

```
┌─────────────┐     ┌─────────────┐     ┌──────────────────┐
│devops-agent │────▶│review-agent │────▶│ Human Approval   │
│ sinh infra  │     │ review IaC  │     │ (bắt buộc với    │
│ code        │     │             │     │  production)     │
└─────────────┘     └─────────────┘     └──────────────────┘
```

```yaml
pipeline_id: devops_infra
steps:
  - step: 1
    agent: devops-agent
    skill: code_gen (mode=infra)
    action: "Sinh infrastructure code theo yêu cầu"
    input_from: user
    output_to: step_2
    on_failure: stop

  - step: 2
    agent: review-agent
    skill: search + summarize
    action: "Review security, best practices cho IaC"
    input_from: step_1
    output_to: step_3
    gate: must_approve

  - step: 3
    type: human_gate
    condition: "environment == production"
    message: "Vui lòng review và xác nhận trước khi apply"
    output_to: orchestrator
```

---

## Pipeline 4: Code Review (PR Review)

**Khi nào dùng:** Tự động review pull request

```yaml
pipeline_id: pr_review
steps:
  - step: 1
    agent: orchestrator
    skill: search (code_search)
    action: "Lấy git diff của PR"
    input_from: user (PR link)
    output_to: step_2

  - step: 2
    agent: review-agent
    skill: summarize (mode=structured)
    action: "Tóm tắt thay đổi trong PR"
    input_from: step_1
    output_to: step_3

  - step: 3
    agent: review-agent
    skill: search + code_gen
    action: "Phát hiện: bug, security issue, code smell, missing tests"
    input_from: step_1
    output_to: step_4

  - step: 4
    agent: doc-agent
    skill: summarize (mode=action_items)
    action: "Tổng hợp comments cần sửa thành danh sách"
    input_from: step_2, step_3
    output_to: orchestrator
```

---

## Quy Tắc Chung Cho Tất Cả Pipeline

```
1. Mỗi step phải trả output đúng schema (xem global.md)
2. Nếu một step fail và on_failure=stop → toàn pipeline dừng
3. Gate steps: agent phía sau không chạy cho đến khi gate được approve
4. Thời gian tối đa mỗi pipeline: 10 phút
5. Log toàn bộ bước, kể cả khi thành công
6. Orchestrator phải thông báo người dùng khi pipeline hoàn thành hoặc bị gián đoạn
```

---

## Mapping Task → Pipeline

| Từ khoá trong task | Pipeline |
|---|---|
| "thêm tính năng", "implement", "viết code mới" | `feature_development` |
| "sửa lỗi", "fix bug", "lỗi", "crash" | `bug_fix` |
| "dockerfile", "k8s", "deploy", "terraform", "CI/CD" | `devops_infra` |
| "review PR", "kiểm tra code", "pull request" | `pr_review` |