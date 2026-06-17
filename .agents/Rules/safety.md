---
trigger: always_on
priority: highest
applies_to: all_agents
override: none   # Không agent nào, kể cả orchestrator, được override file này
---

# Safety Rules — Giới Hạn An Toàn

> Các quy tắc này có **độ ưu tiên cao nhất** trong toàn bộ hệ thống. Kể cả orchestrator cũng không được override. Mọi vi phạm phải được log và báo cáo ngay lập tức.

---

## 1. Lệnh Shell — Whitelist

Chỉ các lệnh sau được phép thực thi. Bất kỳ lệnh nào không có trong danh sách → từ chối, trả `SAFETY_VIOLATION`.

```bash
# ✅ Version Control
git clone | git pull | git push | git fetch
git status | git log | git diff | git checkout | git branch
git add | git commit | git stash | git tag

# ✅ Docker
docker build | docker run | docker ps | docker stop
docker logs | docker inspect | docker images | docker pull
docker-compose up | docker-compose down | docker-compose build

# ✅ Kubernetes
kubectl get | kubectl describe | kubectl apply | kubectl delete
kubectl rollout | kubectl logs | kubectl exec | kubectl port-forward
kubectl config view | kubectl config use-context

# ✅ Node.js / npm / yarn
npm install | npm run | npm test | npm build | npm audit
yarn install | yarn run | yarn test | yarn build

# ✅ Python
pip install | pip list | pip show
pytest | python -m | python3 -m

# ✅ File System (giới hạn trong allowed paths)
ls | cat | grep | find | echo | head | tail | wc
mkdir | cp | mv | touch | diff | stat

# ✅ Tiện ích thông thường
jq | yq | curl (GET only, không pipe vào shell) | wget (download only)
zip | unzip | tar (extract only) | base64 | sha256sum
```

```bash
# ❌ TUYỆT ĐỐI CẤM — Vi phạm ngay lập tức kích hoạt SAFETY_VIOLATION
rm -rf /                  # Xóa toàn bộ hệ thống
rm -rf * (ở root paths)   # Xóa hàng loạt không kiểm soát
chmod 777                 # Mở quyền không kiểm soát
chown -R                  # Thay đổi ownership hàng loạt
curl <url> | bash         # Thực thi script từ internet trực tiếp
wget <url> | bash         # Tương tự
sudo su | sudo -i | su -  # Leo thang quyền root
dd if=/dev/               # Thao tác trực tiếp với disk
nc | netcat | ncat        # Tạo kết nối mạng tùy ý (reverse shell)
> /etc/passwd             # Ghi đè file hệ thống
iptables | ufw            # Thay đổi firewall
crontab -e                # Thêm scheduled task
ssh-keygen | ssh-copy-id  # Tạo/phân phối SSH key
eval "$(...)"`            # Thực thi dynamic string
```

> **Lưu ý `curl`:** Chỉ được dùng để GET API/JSON data. Tuyệt đối không `curl <url> | bash` hay `curl <url> | sh`.

---

## 2. Phạm Vi File System

```yaml
allowed_read:
  - ./src/**
  - ./tests/**
  - ./docs/**
  - ./configs/**
  - ./.agents/**          # Đọc agent definitions
  - /tmp/agent-workspace/**

allowed_write:
  - ./output/**
  - /tmp/agent-workspace/**
  - ./logs/**
  - ./docs/**             # doc-agent cập nhật tài liệu

allowed_read_only:        # Chỉ đọc, tuyệt đối không ghi/xóa
  - .env                  # Đọc để lấy config, không được log nội dung
  - .env.local
  - .env.staging
  - .env.production

forbidden_read_write:
  - /etc/**
  - /root/**
  - ~/.ssh/**
  - /proc/**
  - /sys/**
  - /boot/**
  - .env* (ghi — tuyệt đối cấm ghi vào bất kỳ .env file nào)
```

> **Quy tắc `.env`:** Agent có thể đọc `.env` để lấy config (ví dụ `DATABASE_URL`), nhưng **tuyệt đối không** log, print, hay truyền nội dung ra ngoài. Giá trị từ `.env` phải được mask ngay khi xuất hiện trong output.

---

## 3. Giới Hạn Tài Nguyên

```yaml
max_execution_time: 300s        # Tối đa 5 phút mỗi task (hard limit)
max_output_tokens: 8192         # Giới hạn output mỗi lần gọi model
max_retry_attempts: 2           # Số lần retry khi API_ERROR hoặc TOOL_TIMEOUT
max_concurrent_subagents: 5     # Số sub-agent chạy đồng thời (nhất quán với orchestrator.md)
max_file_size_write: 10MB       # Kích thước file tối đa được ghi
max_pipeline_duration: 600s     # Tổng thời gian tối đa một pipeline (10 phút)
checkpoint_ttl: 3600s           # Checkpoint hết hạn sau 1 giờ nếu không resume
```

> Khi `max_execution_time` bị vượt: agent dừng ngay, trả `TOOL_TIMEOUT`, lưu checkpoint nếu có thể.

---

## 4. Bảo Vệ Thông Tin Nhạy Cảm

Các pattern sau **không được xuất hiện** trong log, output, payload, hay notification:

```regex
# API Keys & Secrets
(?i)(api[_-]?key|secret[_-]?key|access[_-]?token|auth[_-]?token)\s*=\s*\S+
(?i)(password|passwd|pwd)\s*=\s*\S+
(?i)(private[_-]?key|client[_-]?secret)\s*=\s*\S+
TOKEN\s*=\s*\S+
PRIVATE_KEY\s*=\s*\S+

# Thông tin cá nhân
\b\d{3}-\d{2}-\d{4}\b                            # US SSN
\b4[0-9]{12}(?:[0-9]{3})?\b                      # Visa card
\b5[1-5][0-9]{14}\b                              # Mastercard
[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,} # Email (trong log/payload)
```

**Quy trình mask bắt buộc:**
```
Input:   DATABASE_URL=postgres://admin:secretpass@host:5432/db
Output:  DATABASE_URL=postgres://***REDACTED***@host:5432/db

Input:   API_KEY=sk-1234abcd
Output:  API_KEY=***REDACTED***
```

> Mask phải xảy ra **trước khi xử lý** — không bao giờ log raw secret dù chỉ để debug.

---

## 5. Giới Hạn Theo Môi Trường

```yaml
environment: production
  allowed_actions:
    - read
    - analyze
    - generate_code      # Sinh code/config nhưng chưa apply
    - security_scan
  forbidden_actions:
    - deploy             # Bắt buộc human approval trước
    - delete_resource    # Bắt buộc human approval trước
    - modify_database    # Bắt buộc human approval trước
    - kubectl apply      # Chỉ được dùng sau khi human approve
    - terraform apply    # Chỉ được dùng sau khi human approve
  note: "Mọi action thực thi trên production đều cần human_gate trong pipeline"

environment: staging
  allowed_actions:
    - read
    - analyze
    - generate_code
    - security_scan
    - deploy             # Được phép nhưng phải qua review-agent approve
  forbidden_actions:
    - delete_resource
    - modify_database    # Schema changes — cần human approval
  note: "deploy trên staging được phép nhưng phải có review-agent gate"

environment: development
  allowed_actions: all
  note: "Vẫn áp dụng whitelist lệnh shell và file system rules ở trên"
```

---

## 6. Hành Vi Khi Phát Hiện Vi Phạm

Khi bất kỳ agent nào phát hiện hành động vi phạm safety:

```
1. Dừng thực thi ngay lập tức — không thực hiện dù một phần
2. Log đầy đủ theo schema bên dưới
3. Trả về status: error với error_code: SAFETY_VIOLATION
4. Notify orchestrator để escalate
5. Không tự ý retry hành động bị từ chối
6. Không tiếp tục pipeline — orchestrator quyết định hướng xử lý
```

**Violation Log Schema:**

```json
{
  "timestamp": "ISO-8601",
  "agent_id": "tên-agent-vi-phạm",
  "task_id": "uuid-v4",
  "pipeline_id": "tên-pipeline",
  "step": 2,
  "violation_type": "SHELL_FORBIDDEN | FILE_FORBIDDEN | ENV_FORBIDDEN | SECRET_EXPOSURE | RESOURCE_LIMIT",
  "action_attempted": "rm -rf /tmp/agent-workspace/../../../etc",
  "reason": "Lệnh shell không có trong whitelist",
  "blocked": true,
  "notify": ["orchestrator"]
}
```

---

## 7. Human-in-the-Loop (Bắt Buộc)

Các tình huống **bắt buộc phải dừng pipeline và hỏi người dùng** trước khi thực hiện:

**Môi trường Production:**
- [ ] Deploy bất kỳ thứ gì lên production (`kubectl apply`, `terraform apply`, ...)
- [ ] Xóa resource (database, bucket, service, namespace, IAM role)
- [ ] Thay đổi cấu hình infrastructure (network, firewall, load balancer)
- [ ] Thực thi database migration (schema changes)
- [ ] Tạo hoặc xóa IAM role/policy/permission

**Mọi môi trường:**
- [ ] Merge vào branch `main` / `master` / `release/*`
- [ ] Xóa branch từ remote repository
- [ ] Reset hoặc rebase history của branch đã share
- [ ] Thao tác với secrets/credentials (rotate, revoke, generate)
- [ ] Thay đổi cấu hình CI/CD pipeline ảnh hưởng đến production workflow

**Human Gate Request Schema** (orchestrator gửi cho người dùng):

```json
{
  "type": "approval_required",
  "task_id": "uuid-v4",
  "pipeline_id": "devops_infra",
  "step": 3,
  "environment": "production",
  "action_summary": "Apply k8s manifest thay đổi replica count từ 2 → 5 cho service api-gateway",
  "artifacts_to_review": [
    {
      "type": "file",
      "path": "./output/k8s-manifest.yaml",
      "description": "K8s deployment manifest đã được review bởi devops-agent"
    }
  ],
  "risk_level": "medium | high | critical",
  "triggered_by": "pipeline_rule: environment == production",
  "message": "Vui lòng review artifact và xác nhận để tiếp tục."
}
```
