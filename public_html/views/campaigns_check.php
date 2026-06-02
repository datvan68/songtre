<?php
$sessionVi = $sessionVi ?? '';

if (!isset($mode)) {
    echo "<h1>Mode not set</h1>";
    return;
}

if ($mode === "error"):
    ?>
    <div style='max-width:600px;margin:80px auto;padding:30px;
    border:1px solid #ccc;border-radius:16px;text-align:center;
    font-family:Arial;box-shadow:0 4px 20px rgba(0,0,0,0.1)'>

        <h2 style='color:#c00;margin-bottom:10px'><?= htmlspecialchars($error['title']) ?></h2>
        <p style='margin-bottom:16px'><?= htmlspecialchars($error['msg']) ?></p>

        <a href='<?= BASE_URL ?>index.php?p=campaigns' style='padding:10px 20px;background:#007bff;color:white;
              border-radius:8px;text-decoration:none'>
            Quay về phong trào
        </a>
    </div>
    <?php
    return;
endif;

if ($mode === "admin_view"):
    ?>
    <div style='max-width:650px;margin:60px auto;padding:35px;
    border:1px solid #e5e7eb;border-radius:18px;text-align:center;
    font-family:Arial,Helvetica,sans-serif;
    box-shadow:0 10px 30px rgba(15,23,42,0.12);background:#f9fafb'>

        <h1 style='font-size:24px;margin-bottom:4px;color:#111827'>
            QR điểm danh phong trào
        </h1>

        <h2 style='font-size:18px;margin-bottom:16px;color:#374151'>
            <?= htmlspecialchars($campTitle) ?>
        </h2>

        <div style='display:inline-block;padding:6px 12px;border-radius:999px;
                background:#e5f6f0;margin-bottom:16px;font-size:13px;color:<?= $statusColor ?>'>
            Trạng thái: <b><?= $statusText ?></b>
        </div>

        <div style='margin:0px 0'>
            <img src='https://api.qrserver.com/v1/create-qr-code/?size=360x360&data=<?= urlencode($qrUrl) ?>' alt='QR Code'
                style='display:block;margin:0 auto;border:1px solid #e5e7eb;
                    padding:12px;background:white;border-radius:12px'>
        </div>

        <a href="#" onclick="openPrintQR(); return false;" style="padding:10px 20px;background:#2563eb;color:#fff;border-radius:999px;
          display:inline-block;margin-top:18px;text-decoration:none;font-size:14px">
            In QR
        </a>

        <a href="<?= BASE_URL ?>index.php?p=campaigns_qr&campaign_id=<?= $cid ?>" style='padding:10px 20px;background:#2563eb;color:#fff;border-radius:999px;
              display:inline-block;margin-top:18px;margin-left:8px;text-decoration:none;font-size:14px'>
            Về trang tạo QR
        </a>

        <p style='margin-top:12px;font-size:13px;color:#6b7280'>
            Link quét: <span style='color:#2563eb'><?= htmlspecialchars($qrUrl) ?></span>
        </p>

        <p style='margin-top:14px;font-size:12px;color:#9ca3af'>
            Gợi ý: In QR này và dán trước cửa phòng cho sinh viên quét.
        </p>
    </div>

    <script>
        function openPrintQR() {
            const title = <?= json_encode($campTitle) ?>;
            const qrUrl = <?= json_encode($qrUrl) ?>;

            const popup = window.open("", "PRINT_QR",
                "width=850,height=1100,top=100,left=200");

            popup.document.write(`
        <html>
        <head>
            <title>QR <?= htmlspecialchars($campTitle) ?></title>
            <style>
                @page { size: A4; margin: 0; }
                body {
                    margin: 0; padding: 40px 0; background: #f3f4f6;
                    font-family: Arial, Helvetica, sans-serif;
                    display: flex; justify-content: center; align-items: center;
                    height: 100vh;
                }
                @media print { body { height: auto !important; } }
                .wrap {
                    background: white; width: 90%; max-width: 650px;
                    padding: 35px 20px; border-radius: 16px;
                    box-shadow: 0 10px 35px rgba(0, 0, 0, 0.15);
                    text-align: center;
                }
                .title { font-size: 26px; font-weight: bold; margin-bottom: 20px; color: #111; }
                .qr-box { display: flex; justify-content: center; margin: 0 auto 25px auto; }
                .qr-box img {
                    width: 500px; height: 500px; border-radius: 8px;
                    border: 4px solid #e5e7eb; background: white;
                }
                .desc { margin-top: 10px; font-size: 18px; color: #374151; }
            </style>
        </head>
        <body>
            <div class="wrap">
                <div class="title">${title}</div>
                <div class="qr-box">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=1000x1000&data=${encodeURIComponent(qrUrl)}">
                </div>
                <div class="desc">Quét QR để điểm danh</div>
            </div>
            <script>
                window.onload = () => {
                    window.print();
                    setTimeout(() => window.close(), 300);
                };
            <\/script>
        </body>
        </html>
    `);

            popup.document.close();
        }
    </script>
    <?php
    return;
endif;



/* ===============================
   USER GET → XÁC NHẬN GPS (ANTI-FAKE)
=============================== */
if ($mode === "user_confirm"):
    ?>
    <!DOCTYPE html>
    <html lang='vi'>

    <head>
        <meta charset='utf-8'>
        <title>Điểm danh phong trào</title>
    </head>

    <body style='background:#f3f4f6;font-family:Arial,Helvetica,sans-serif'>
        <div style='max-width:500px;margin:70px auto;padding:24px;
            background:white;border-radius:16px;
            border:1px solid #e5e7eb;
            box-shadow:0 10px 25px rgba(15,23,42,0.1);
            text-align:center'>

            <h1 style='font-size:22px;margin-bottom:8px;color:#111827'>
                Xác nhận điểm danh
            </h1>

            <h2 style='font-size:17px;margin-bottom:12px;color:#374151'>
                <?= htmlspecialchars($campTitle) ?>
            </h2>

            <p style='font-size:14px;color:#4b5563;margin-bottom:6px'>
                Buổi hiện tại: <b><?= $sessionVi ?></b>
            </p>

            <p style='font-size:13px;color:#6b7280;margin-bottom:16px'>
                Vui lòng bật GPS (Chính xác cao) và đứng yên 6–10 giây để hệ thống xác minh vị trí.
            </p>

            <p id='geoStatus' style='font-size:13px;color:#dc2626;margin-bottom:10px'>
                Đang kiểm tra vị trí của bạn...
            </p>

            <div id="gpsMeta" style="font-size:12px;color:#6b7280;margin-bottom:10px;display:none;">
                <span id="gpsSamples">0</span> mẫu • accuracy cuối: <span id="gpsAcc">-</span>m
            </div>

            <form id="checkinForm" method='post' style='margin-top:8px'>
                <input type='hidden' name='code' value='<?= htmlspecialchars($code, ENT_QUOTES) ?>'>
                <input type='hidden' id='lat' name='lat'>
                <input type='hidden' id='lng' name='lng'>
                <input type="hidden" id="accuracy" name="accuracy">
                <!-- ✅ NEW: gửi points JSON -->
                <input type="hidden" id="points" name="points">

                <button id="btnSubmit" type="submit" disabled style="padding:10px 20px;
               border-radius:999px;
               border:none;
               background:#9ca3af;
               color:white;
               font-size:14px;">
                    Đang lấy vị trí...
                </button>
            </form>

            <p style='margin-top:12px;font-size:12px;color:#9ca3af'>
                Nếu trình duyệt hỏi quyền vị trí, hãy chọn <b>Cho phép</b>.
            </p>
        </div>

        <script>
            const statusEl = document.getElementById('geoStatus');
            const metaEl = document.getElementById('gpsMeta');
            const samplesEl = document.getElementById('gpsSamples');
            const accEl = document.getElementById('gpsAcc');

            const btn = document.getElementById('btnSubmit');
            const latInput = document.getElementById('lat');
            const lngInput = document.getElementById('lng');
            const accInput = document.getElementById('accuracy');
            const pointsInput = document.getElementById('points');

            let gpsReady = false;
            let gpsPoints = [];

            // Policy (match backend)
            const MIN_POINTS = 4;
            const MAX_POINTS = 6;
            const INTERVAL_MS = 1500;
            const MAX_TOTAL_MS = 10000;

            function setBtn(state, text, color) {
                btn.disabled = !state;
                btn.textContent = text;
                btn.style.background = color || (state ? '#16a34a' : '#9ca3af');
            }

            async function getPosOnce() {
                return new Promise((resolve) => {
                    navigator.geolocation.getCurrentPosition(
                        (pos) => resolve({
                            lat: pos.coords.latitude,
                            lng: pos.coords.longitude,
                            acc: pos.coords.accuracy,
                            ts: Date.now()
                        }),
                        (err) => resolve({ err: true, code: err?.code || 0 }),
                        { enableHighAccuracy: true, timeout: 6000, maximumAge: 0 }
                    );
                });
            }

            async function collectPoints() {
                gpsPoints = [];
                gpsReady = false;

                metaEl.style.display = 'block';
                samplesEl.textContent = '0';
                accEl.textContent = '-';

                setBtn(false, 'Đang lấy vị trí...', '#9ca3af');
                statusEl.textContent = 'Đang lấy GPS ổn định (đứng yên 6–10 giây)...';
                statusEl.style.color = '#dc2626';

                const start = Date.now();

                while (gpsPoints.length < MAX_POINTS && (Date.now() - start) < MAX_TOTAL_MS) {
                    const p = await getPosOnce();
                    if (p && !p.err && p.lat && p.lng) {
                        gpsPoints.push(p);
                        samplesEl.textContent = String(gpsPoints.length);
                        accEl.textContent = String(Math.round(p.acc || 0));

                        // Cập nhật vị trí cuối vào input
                        latInput.value = p.lat;
                        lngInput.value = p.lng;
                        accInput.value = p.acc;

                        // Đủ tối thiểu và đã chạy >= 6s thì có thể dừng sớm
                        if (gpsPoints.length >= MIN_POINTS && (Date.now() - start) >= 6000) break;
                    }

                    await new Promise(r => setTimeout(r, INTERVAL_MS));
                }

                if (gpsPoints.length < MIN_POINTS) {
                    statusEl.textContent = 'Không lấy được GPS ổn định. Hãy bật Vị trí chính xác cao và thử lại.';
                    statusEl.style.color = '#dc2626';
                    setBtn(false, 'Không thể điểm danh', '#9ca3af');
                    gpsReady = false;
                    return;
                }

                // Set points JSON for backend checks (jitter/speed)
                pointsInput.value = JSON.stringify(gpsPoints);

                gpsReady = true;
                statusEl.textContent = 'Đã lấy GPS ổn định. Bạn có thể điểm danh.';
                statusEl.style.color = '#16a34a';
                setBtn(true, 'Xác nhận điểm danh', '#16a34a');
            }

            // ✅ Start collecting on load
            if (typeof window.IS_ADMIN_QR === "undefined" && navigator.geolocation) {
                collectPoints();
            } else {
                statusEl.textContent = 'Thiết bị không hỗ trợ GPS.';
                statusEl.style.color = '#dc2626';
                setBtn(false, 'Không thể điểm danh', '#9ca3af');
            }

            // SUBMIT
            document.getElementById("checkinForm")?.addEventListener("submit", async (e) => {
                e.preventDefault();

                if (!gpsReady) {
                    alert("Vui lòng chờ GPS ổn định trước khi điểm danh");
                    return;
                }

                const fd = new FormData(e.target);

                // chống bấm spam
                setBtn(false, 'Đang gửi...', '#9ca3af');

                try {
                    const res = await fetch(window.location.href, {
                        method: "POST",
                        body: fd,
                        headers: { "Accept": "application/json" }
                    });

                    const ct = res.headers.get("content-type") || "";
                    if (!ct.includes("application/json")) throw new Error("Server trả HTML thay vì JSON");

                    const json = await res.json();
                    function showCheckinError(json) {
                        const code = json?.error || "UNKNOWN";

                        // helper: message ngắn + hướng dẫn
                        const map = {
                            NEED_LOGIN: {
                                type: "error",
                                msg: "Vui lòng đăng nhập để điểm danh."
                            },
                            FORBIDDEN: {
                                type: "error",
                                msg: "Bạn không có quyền điểm danh."
                            },
                            ADMIN_NO_CHECKIN: {
                                type: "error",
                                msg: "Tài khoản quản trị không được điểm danh."
                            },
                            NOT_REGISTERED: {
                                type: "error",
                                msg: "Bạn chưa đăng ký phong trào này."
                            },
                            ALREADY_CHECKED: {
                                type: "info",
                                msg: "Bạn đã điểm danh buổi này rồi."
                            },

                            // ===== GPS basic =====
                            NO_GPS: {
                                type: "error",
                                msg: "Không nhận được vị trí. Hãy bật GPS và thử lại."
                            },
                            BAD_GPS: {
                                type: "error",
                                msg: "Tọa độ GPS không hợp lệ. Vui lòng bật GPS chính xác cao và thử lại."
                            },
                            EVENT_NO_LOCATION: {
                                type: "error",
                                msg: "Sự kiện chưa thiết lập vị trí điểm danh. Vui lòng báo quản trị."
                            },

                            // ===== Anti-fake core =====
                            BAD_ACCURACY: {
                                type: "error",
                                msg: `GPS chưa đủ chính xác (accuracy ${Math.round(json.accuracy || 0)}m). 
Hãy bật “Vị trí chính xác cao”, đứng yên 6–10 giây, rồi thử lại.`
                            },
                            NEED_STABLE_GPS: {
                                type: "error",
                                msg: `Chưa lấy được GPS ổn định (cần tối thiểu ${json.min_points || 4} mẫu). 
Hãy đứng yên 6–10 giây và thử lại.`
                            },
                            GPS_JUMP: {
                                type: "error",
                                msg: `Tín hiệu GPS nhảy bất thường (${Math.round(json.max_jitter || 0)}m). 
Vui lòng tắt ứng dụng giả lập vị trí / VPN GPS và thử lại.`
                            },
                            IMPOSSIBLE_SPEED: {
                                type: "error",
                                msg: `Tốc độ di chuyển bất thường (${Number(json.max_speed || 0).toFixed(2)} m/s). 
Hãy đứng yên, bật GPS chính xác cao và thử lại.`
                            },

                            // ===== Range =====
                            OUT_OF_RANGE: {
                                type: "error",
                                msg: `Bạn đang ở ngoài phạm vi điểm danh (${json.dist}m / cho phép ${json.allow}m). 
Hãy di chuyển lại gần địa điểm và thử lại.`
                            }
                        };

                        const item = map[code] || {
                            type: "error",
                            msg: `Có lỗi xảy ra (${code}). Vui lòng thử lại.`
                        };

                        // hiển thị
                        if (typeof toast === "function") {
                            toast(item.msg, item.type);
                        } else {
                            alert(item.msg);
                        }

                        // hành động đặc biệt
                        if (code === "NEED_LOGIN") {
                            if (typeof openLoginModal === "function") openLoginModal();
                        }

                        // những lỗi GPS nên tự thu lại points (nếu bạn có hàm collectPoints)
                        const shouldRecollect = ["BAD_ACCURACY", "NEED_STABLE_GPS", "GPS_JUMP", "IMPOSSIBLE_SPEED", "OUT_OF_RANGE", "NO_GPS", "BAD_GPS"];
                        return { code, shouldRecollect: shouldRecollect.includes(code) };
                    }

                    if (!json.ok) {
                        const r = showCheckinError(json);

                        // nếu bạn dùng bản view mình gửi (có collectPoints)
                        if (r.shouldRecollect && typeof collectPoints === "function") {
                            await collectPoints();
                        }

                        return;
                    }


                    // ✅ THÀNH CÔNG → reload hiển thị success
                    const url = new URL(window.location.href);
                    url.searchParams.set("success", "1");
                    window.location.href = url.toString();

                } catch (err) {
                    alert("Lỗi kết nối máy chủ");
                    console.error(err);
                    setBtn(true, 'Xác nhận điểm danh', '#16a34a');
                }
            });
        </script>
    </body>

    </html>
    <?php
    return;
endif;

if ($mode === "success"):
    ?>
    <div style='max-width:600px;margin:80px auto;padding:30px;
    border:1px solid #e5e7eb;border-radius:16px;text-align:center;
    font-family:Arial;box-shadow:0 4px 20px rgba(15,23,42,0.12);
    background:#f9fafb'>

        <h1 style='color:#16a34a;margin-bottom:12px;font-size:22px'>
            Điểm danh thành công!
        </h1>

        <h2 style='font-size:18px;color:#111827;margin-bottom:8px'>
            <?= htmlspecialchars($campTitle) ?>
        </h2>

        <p style='font-size:15px;margin:10px 0;color:#374151'>
            <b><?= $sessionVi ?></b> – lúc <b><?= $timeNow ?></b>
        </p>

        <p style='font-size:12px;color:#9ca3af;margin-bottom:16px'>
            Vị trí đã được kiểm tra.
        </p>

        <a href='<?= BASE_URL ?>index.php?p=campaigns' style='padding:10px 20px;background:#2563eb;color:white;
              border-radius:999px;font-size:14px;text-decoration:none'>
            Quay về phong trào
        </a>
    </div>
    <?php
    return;
endif;
