---
description: Định nghĩa các pipeline chuẩn cho từng loại task phổ biến trong dự án Software Development / DevOps. Orchestrator map task vào pipeline phù hợp và thực thi theo đúng thứ tự / cấu trúc song song được khai báo.
---

# Pipeline — Luồng Xử Lý Cụ Thể

---

## Metadata

```yaml
version: 2.0.0
managed_by: orchestrator
trigger: orchestrator nhận task và map vào pipeline phù hợp
max_pipeline_duration: 10m
checkpointing: enabled
checkpoint_store: redis
resume_from_last_success: true
```

---

## Quy Ước Syntax

```yaml
# Tuần tự: step chạy sau khi step trước hoàn thành
- step: N
  agent: agent-id

# Song song: tất cả items trong parallel[] chạy đồng thời
- step: N
  parallel:
    - agent: agent-a
      skill: skill_a
      action: "..."
    - agent: agent-b
      skill: skill_b
      action: "..."
  sync_at: step_N+1   # chờ toàn bộ nhánh xong mới tiếp tục

# Có điều kiện: chỉ chạy nếu condition đúng
- step: N
  agent: agent-id
  condition: "biểu thức điều kiện"

# Human gate
- step: N
  type: human_gate
  condition: "biểu thức kích hoạt"
  message: "Nội dung yêu cầu xác nhận"
```

---

## Pipeline 1: Feature Development

**Khi nào dùng:** Phát triển tính năng mới từ requirement

```
┌─────────────┐     ┌──────────────────────────┐     ┌─────────────┐     ┌─────────────┐
│  code-agent │────▶│  SONG SONG:               │────▶│review-agent │────▶│  doc-agent  │
│  sinh code  │     │  test-agent (unit tests)  │     │ review +    │     │  cập nhật   │
│             │     │  doc-agent  (draft docs)  │     │ security    │     │  tài liệu   │
└─────────────┘     └──────────────────────────┘     └─────────────┘     └─────────────┘
```

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
    checkpoint: after

  - step: 2
    parallel:
      - agent: test-agent
        skill: code_gen (mode=test)
        action: "Viết unit test cho code vừa sinh"
        input_from: step_1
        on_failure: retry_once
      - agent: doc-agent
        skill: summarize (mode=draft)
        action: "Tạo bản nháp docstring và README section"
        input_from: step_1
        on_failure: warn_only
    sync_at: step_3
    checkpoint: after

  - step: 3
    agent: review-agent
    skill: search + summarize + security_scan
    action: "Review code quality, security, performance; kiểm tra test coverage"
    input_from: step_1, step_2
    output_to: step_4
    on_failure: stop
    gate: "review-agent phải approve trước khi tiếp tục"
    checkpoint: after

  - step: 4
    agent: doc-agent
    skill: code_gen (mode=document)
    action: "Hoàn thiện README, docstring, changelog dựa trên bản nháp (step_2) và kết quả review (step_3)"
    input_from: step_2, step_3
    output_to: orchestrator
    on_failure: warn_only
    notify_on_failure:
      type: warn
      message: "doc-agent không hoàn thiện được tài liệu, tiếp tục mà không có changelog."
      notify: [user]
      log_level: warn
```

---

## Pipeline 2: Bug Fix

**Khi nào dùng:** Sửa lỗi từ bug report hoặc log

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  code-agent │────▶│  code-agent │────▶│  test-agent │────▶│review-agent │
│ phân tích   │     │  fix bug    │     │ regression  │     │ confirm fix │
│ root cause  │     │             │     │ test        │     │             │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
```

```yaml
pipeline_id: bug_fix
steps:
  - step: 1
    agent: code-agent          # ✅ code-agent chịu trách nhiệm phân tích, không phải orchestrator
    skill: search
    action: "Phân tích log/error message, xác định root cause"
    input_from: user
    output_to: step_2
    on_failure: stop
    checkpoint: after

  - step: 2
    agent: code-agent
    skill: code_gen (mode=fix)
    action: "Sửa bug dựa theo root cause từ step_1"
    input_from: step_1
    output_to: step_3
    on_failure: stop
    checkpoint: after

  - step: 3
    agent: test-agent
    skill: code_gen (mode=test)
    action: "Viết regression test để đảm bảo bug không tái xuất"
    input_from: step_2
    output_to: step_4
    on_failure: warn_only
    notify_on_failure:
      type: warn
      message: "test-agent không tạo được regression test."
      notify: [user]
      log_level: warn
    checkpoint: after

  - step: 4
    agent: review-agent
    skill: summarize + security_scan
    action: "Xác nhận fix đúng root cause, không introduce bug mới, không có security regression"
    input_from: step_2, step_3
    output_to: orchestrator
    on_failure: stop
    gate: must_approve
```

---

## Pipeline 3: DevOps / Infrastructure

**Khi nào dùng:** Tạo/cập nhật Dockerfile, k8s manifest, CI/CD pipeline, Terraform

```
┌──────────────┐     ┌──────────────────────────────┐     ┌──────────────────┐
│ devops-agent │────▶│  SONG SONG:                   │────▶│ Human Approval   │
│ sinh infra   │     │  review-agent (code quality)  │     │ (nếu production) │
│ code         │     │  devops-agent (security IaC)  │     │                  │
└──────────────┘     └──────────────────────────────┘     └──────────────────┘
```

```yaml
pipeline_id: devops_infra
steps:
  - step: 1
    agent: devops-agent
    skill: code_gen (mode=infra)
    action: "Sinh infrastructure code theo yêu cầu (Dockerfile, k8s manifest, Terraform, CI/CD config)"
    input_from: user
    output_to: step_2
    on_failure: stop
    checkpoint: after

  - step: 2
    parallel:
      - agent: review-agent
        skill: search + summarize
        action: "Review code quality, best practices, cấu trúc IaC"
        input_from: step_1
        on_failure: stop
      - agent: devops-agent
        skill: security_scan
        action: "Quét security: exposed secrets, overprivileged roles, insecure defaults trong IaC"
        input_from: step_1
        on_failure: stop
    sync_at: step_3
    gate: "Cả hai nhánh phải approve mới tiếp tục"
    checkpoint: after

  - step: 3
    type: human_gate
    condition: "environment == production"
    message: "⚠️ Thay đổi infrastructure ảnh hưởng production. Vui lòng review artifact và xác nhận trước khi apply."
    output_to: orchestrator
```

---

## Pipeline 4: Code Review (PR Review)

**Khi nào dùng:** Tự động review pull request

```
┌─────────────┐     ┌──────────────────────────────────┐     ┌─────────────┐
│ code-agent  │────▶│  SONG SONG:                       │────▶│  doc-agent  │
│ lấy git diff│     │  review-agent (logic/security)    │     │ tổng hợp   │
│             │     │  devops-agent (nếu có infra file) │     │ action items│
└─────────────┘     └──────────────────────────────────┘     └─────────────┘
```

```yaml
pipeline_id: pr_review
steps:
  - step: 1
    agent: code-agent
    skill: search (mode=code_search)
    action: "Lấy git diff của PR, phân loại file thay đổi (source code vs infra files)"
    input_from: user (PR link)
    output_to: step_2
    on_failure: stop
    checkpoint: after

  - step: 2
    parallel:
      - agent: review-agent
        skill: search + summarize + security_scan
        action: "Phát hiện bug, security issue, code smell, missing tests trong source code changes"
        input_from: step_1
        on_failure: stop
      - agent: devops-agent
        skill: search + summarize + security_scan
        action: "Review IaC changes nếu PR chứa Dockerfile, k8s, Terraform, CI/CD config"
        input_from: step_1
        condition: "pr_contains_infra_files == true"
        on_failure: stop
    sync_at: step_3
    checkpoint: after

  - step: 3
    agent: doc-agent
    skill: summarize (mode=action_items)
    action: "Tổng hợp toàn bộ comments từ review-agent và devops-agent thành danh sách action items có priority"
    input_from: step_2
    output_to: orchestrator
    on_failure: warn_only
    notify_on_failure:
      type: warn
      message: "doc-agent không tổng hợp được action items."
      notify: [user]
      log_level: warn
```

---

## Quy Tắc Chung Cho Tất Cả Pipeline

```
1. Mọi sub-agent đều nhận shared_context đầy đủ từ orchestrator (xem orchestrator.md)
2. Mỗi step phải trả output đúng schema của orchestrator
3. Nếu một step fail và on_failure=stop → lưu checkpoint, toàn pipeline dừng
4. Gate steps: agent phía sau không chạy cho đến khi gate được approve
5. Thời gian tối đa mỗi pipeline: 10 phút
6. Checkpoint được lưu sau mỗi step thành công — pipeline có thể resume từ bước tiếp theo
7. Log toàn bộ bước, kể cả khi thành công, kèm duration_ms
8. Orchestrator thông báo người dùng khi pipeline hoàn thành, bị gián đoạn, hoặc cần approval
9. on_failure=warn_only phải kèm notify_on_failure schema — không được warn ngầm
10. Orchestrator KHÔNG thực thi skill nào trong bất kỳ pipeline nào
```

---

## Mapping Task → Pipeline

| Từ khoá trong task | Pipeline |
|---|---|
| "thêm tính năng", "implement", "viết code mới", "feature" | `feature_development` |
| "sửa lỗi", "fix bug", "lỗi", "crash", "error", "phân tích log" | `bug_fix` |
| "dockerfile", "k8s", "deploy", "terraform", "ci/cd", "infra" | `devops_infra` |
| "review PR", "kiểm tra code", "pull request", "git diff" | `pr_review` |
