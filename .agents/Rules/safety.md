---
trigger: always_on
---

# Safety Rules — Giới Hạn An Toàn

> Các quy tắc này có **độ ưu tiên cao nhất**. Kể cả orchestrator cũng không được override.

---

## 1. Lệnh Shell — Whitelist

Chỉ các lệnh sau được phép thực thi:

```bash
# ✅ ĐƯỢC PHÉP
git clone | git pull | git push | git status | git log | git diff
docker build | docker run | docker ps | docker stop | docker logs
kubectl get | kubectl describe | kubectl apply | kubectl rollout
npm install | npm run | npm test | npm build
pip install | pytest | python -m
ls | cat | grep | find | echo | mkdir | cp | mv
```

```bash
# ❌ TUYỆT ĐỐI CẤM
rm -rf /           # Xóa toàn bộ hệ thống
chmod 777          # Mở quyền không kiểm soát
curl | bash        # Thực thi script từ internet
sudo su            # Leo thang quyền
dd if=/dev/        # Thao tác trực tiếp với disk
nc | netcat        # Tạo kết nối mạng tùy ý
> /etc/passwd      # Ghi đè file hệ thống
```

---

## 2. Phạm Vi File System

```yaml
allowed_read:
  - ./src/**
  - ./tests/**
  - ./docs/**
  - ./configs/**
  - /tmp/agent-workspace/**

allowed_write:
  - ./output/**
  - /tmp/agent-workspace/**
  - ./logs/**

forbidden:
  - /etc/**
  - /root/**
  - ~/.ssh/**
  - .env files (chỉ đọc, không ghi)
```

---

## 3. Giới Hạn Tài Nguyên

```yaml
max_execution_time: 300s       # Tối đa 5 phút mỗi task
max_output_tokens: 8192        # Giới hạn output mỗi lần gọi
max_retry_attempts: 2          # Số lần retry khi lỗi
max_concurrent_subagents: 5    # Số sub-agent chạy đồng thời
max_file_size_write: 10MB      # Kích thước file tối đa được ghi
```

---

## 4. Bảo Vệ Thông Tin Nhạy Cảm

Các pattern sau **không được xuất hiện** trong log, output, hay payload:

```regex
# Secrets
API_KEY=.*
SECRET_KEY=.*
PASSWORD=.*
TOKEN=.*
PRIVATE_KEY=.*

# Thông tin cá nhân
\b\d{3}-\d{2}-\d{4}\b        # SSN
\b4[0-9]{12}(?:[0-9]{3})?\b  # Visa card
[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}  # Email (trong log)
```

> Nếu phát hiện pattern trên trong input → **mask ngay** trước khi xử lý:
> `API_KEY=sk-xxx...` → `API_KEY=***REDACTED***`

---

## 5. Giới Hạn Theo Môi Trường

```yaml
environment: production
  allowed_actions:
    - read
    - analyze
    - generate_code
  forbidden_actions:
    - deploy          # Phải có human approval
    - delete_resource # Phải có human approval
    - modify_database # Phải có human approval

environment: staging
  allowed_actions:
    - read
    - analyze
    - generate_code
    - deploy
  forbidden_actions:
    - delete_resource
    - modify_database (schema changes)

environment: development
  allowed_actions: all
  note: "Vẫn áp dụng whitelist lệnh shell ở trên"
```

---

## 6. Hành Vi Khi Phát Hiện Vi Phạm

```
1. Dừng thực thi ngay lập tức
2. Log đầy đủ: timestamp, agent_id, action_attempted, reason
3. Trả về status: error với error_code: SAFETY_VIOLATION
4. Notify orchestrator để escalate
5. Không tự ý retry hành động bị từ chối
```

---

## 7. Human-in-the-Loop (Bắt buộc)

Các tình huống **bắt buộc phải hỏi người dùng** trước khi thực hiện:

- [ ] Deploy lên môi trường production
- [ ] Xóa resource (database, bucket, service)
- [ ] Thay đổi cấu hình infrastructure
- [ ] Merge vào branch `main` / `master`
- [ ] Thực thi migration database
- [ ] Tạo hoặc xóa IAM role/policy