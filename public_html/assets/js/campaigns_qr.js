const API_URL = "controllers/campaigns_qr_api.php";


let map, marker, circle;
let lastReverseText = "";

function initMapOSM() {
  const defaultLatLng = [10.7360270, 106.6811972]; // BK Nam Sài Gòn

  map = L.map("map").setView(defaultLatLng, 16);

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "© OpenStreetMap",
  }).addTo(map);

  marker = L.marker(defaultLatLng, { draggable: true }).addTo(map);

  circle = L.circle(defaultLatLng, {
    radius: 50,
    color: "blue",
    fillOpacity: 0.15,
  }).addTo(map);

  // GÁN MẶC ĐỊNH
  setLatLng(defaultLatLng[0], defaultLatLng[1]);

  // CLICK MAP
  map.on("click", (e) => {
    setLatLng(e.latlng.lat, e.latlng.lng);
  });

  // DRAG MARKER
  marker.on("dragend", (e) => {
    const p = e.target.getLatLng();
    setLatLng(p.lat, p.lng);
  });

  document.getElementById("btnSearchAddress").onclick = searchAddress;

}

function setLatLng(lat, lng) {
  marker.setLatLng([lat, lng]);
  circle.setLatLng([lat, lng]);

  document.getElementById("lat").value = lat;
  document.getElementById("lng").value = lng;

  // 🔥 CLICK MAP → TỰ GHI ĐỊA CHỈ
  reverseGeocode(lat, lng);
}


async function searchAddress() {
  const inputEl = document.getElementById("addressInput");
  let q = inputEl.value.trim();

  if (!q) {
    toast("Vui lòng nhập địa chỉ", "error");
    return;
  }

  // ✅ Nếu text đang là do reverseGeocode vừa đổ vào → khỏi tìm
  if (lastReverseText && q === lastReverseText) {
    toast("Địa chỉ này là từ click bản đồ rồi, không cần tìm lại.", "info");
    return;
  }
  document.getElementById("addressInput")?.addEventListener("input", () => {
    lastReverseText = "";
  });
  // ✅ Cắt query cho gọn (Nominatim dễ match hơn)
  // Lấy tối đa 3 cụm đầu trước dấu phẩy
  q = q.split(",").slice(0, 3).join(",").trim();

  try {
    const res = await fetch(`controllers/geocode.php?q=${encodeURIComponent(q)}`);
    const data = await res.json();

    if (!Array.isArray(data) || !data.length) {
      toast("Không tìm thấy địa chỉ", "error");
      return;
    }

    const lat = parseFloat(data[0].lat);
    const lng = parseFloat(data[0].lon);

    map.setView([lat, lng], 17);
    setLatLng(lat, lng);

  } catch (e) {
    console.error(e);
    toast("Lỗi tìm địa chỉ", "error");
  }
}

async function reverseGeocode(lat, lng) {
  try {
    const res = await fetch(
      `controllers/campaigns_qr_api.php?action=reverse_geocode&lat=${lat}&lng=${lng}`
    );
    const json = await res.json();

    if (json.ok && json.display_name) {
      lastReverseText = json.display_name; // ✅ lưu lại
      document.getElementById("addressInput").value = json.display_name;
    }
  } catch (e) {
    console.error("Reverse geocode error", e);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  initMapOSM()
  let lat = document.getElementById("lat")?.value;
  let lng = document.getElementById("lng")?.value;


  /* =======================================================
     TẠO QR — LẤY VỊ TRÍ TỪ MAP (OSM)
  ======================================================= */
  const btnCreate = document.getElementById("btnCreateQR");
  const expiresInput = document.getElementById("expires_at");

  /* =======================================================
   TẠO QR — LẤY VỊ TRÍ + THỜI GIAN + ĐỊA CHỈ
======================================================= */

  if (btnCreate) {
    btnCreate.addEventListener("click", async () => {

      const starts = document.getElementById("starts_at")?.value.trim();
      const expires = document.getElementById("expires_at")?.value.trim();
      const lat = document.getElementById("lat")?.value;
      const lng = document.getElementById("lng")?.value;
      const address = document.getElementById("addressInput")?.value.trim();

      if (!starts)
        return toast("Vui lòng chọn thời gian bắt đầu!", "error");

      if (!expires)
        return toast("Vui lòng chọn thời gian kết thúc!", "error");

      if (!lat || !lng)
        return toast("Vui lòng chọn vị trí trên bản đồ!", "error");

      if (!address)
        return toast("Đang lấy địa chỉ, vui lòng đợi giây lát…", "info");

      const fd = new FormData();
      fd.append("action", "create");
      fd.append("campaign_id", window.CAMPAIGN_ID);
      fd.append("starts_at", starts);
      fd.append("expires_at", expires);
      fd.append("lat", lat);
      fd.append("lng", lng);
      fd.append("address", address);

      btnCreate.disabled = true;

      try {
        const res = await api(API_URL, { method: "POST", body: fd });
        const json = await res.json();

        if (!json.ok) {
          btnCreate.disabled = false;
          return toast(json.error || "Lỗi tạo QR", "error");
        }

        toast("Tạo QR thành công!", "success");
        setTimeout(() => location.reload(), 600);

      } catch (e) {
        console.error(e);
        btnCreate.disabled = false;
        toast("Lỗi không xác định!", "error");
      }
    });
  }


  /* =======================================================
     GIA HẠN QR – GIỮ NGUYÊN
  ======================================================= */
  document.querySelectorAll(".js-extend").forEach(btn => {

    btn.onclick = () => {

      const eventId = btn.closest("tr").dataset.id;

      const wrap = document.createElement("div");
      wrap.innerHTML = `
        <form id="extendForm" class="grid gap-3">
          <input type="hidden" name="action" value="extend">
          <input type="hidden" name="id" value="${eventId}">

          <div>
            <label class="block text-sm mb-1">Thời gian mới</label>
            <input id="extendTime" name="expires_at"
                   type="text"
                   class="w-full px-3 py-2 border rounded-lg"
                   placeholder="Chọn thời gian...">
          </div>

          <div class="flex justify-end gap-2 mt-3">
            <button type="button" onclick="closeModal()"
                    class="px-4 py-2 border rounded-lg">Hủy</button>

            <button class="px-4 py-2 bg-blue-600 text-white rounded-lg">
              Lưu
            </button>
          </div>
        </form>
      `;

      modal(wrap, "Gia hạn QR", "small");

      flatpickr("#extendTime", {
        enableTime: true,
        dateFormat: "Y-m-d H:i",
        time_24hr: true
      });

      wrap.querySelector("#extendForm").onsubmit = async (e) => {
        e.preventDefault();

        const fd = new FormData(e.target);
        const res = await api(API_URL, { method: "POST", body: fd });
        const json = await res.json();

        if (!json.ok) return toast(json.error, "error");

        toast("Gia hạn thành công!", "success");
        closeModal();
        setTimeout(() => location.reload(), 500);
      };
    };
  });

  /* =======================================================
     XÓA QR – GIỮ NGUYÊN
  ======================================================= */
  function openDeleteConfirm(id, code) {
    const root = document.getElementById("global-modal-root");

    root.innerHTML = `
      <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-[9999]">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
          <h2 class="text-lg font-semibold mb-3 text-red-600">
            Xác nhận xóa QR
          </h2>

          <p class="text-sm text-gray-700 mb-4">
            Bạn có chắc muốn xóa mã QR này không?<br>
            <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded mt-2 inline-block">
              ${code}
            </span>
          </p>

          <div class="flex justify-end gap-3">
            <button
              onclick="document.getElementById('global-modal-root').innerHTML='';"
              class="px-4 py-2 rounded bg-gray-200 hover:bg-gray-300 text-sm">
              Hủy
            </button>

            <button
              class="px-4 py-2 rounded bg-red-600 hover:bg-red-700 text-white text-sm"
              onclick="confirmDelete(${id})">
              Xóa
            </button>
          </div>
        </div>
      </div>
    `;
  }
  const addressInput = document.getElementById("addressInput");

  if (addressInput) {
    addressInput.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        searchAddress();
      }
    });
  }

  async function confirmDelete(id) {
    const fd = new URLSearchParams();
    fd.append("action", "delete");
    fd.append("id", id);

    const res = await api(API_URL, { method: "POST", body: fd });
    const json = await res.json();

    if (!json.ok) {
      toast(json.error || "Không thể xóa", "error");
      return;
    }

    document.getElementById("global-modal-root").innerHTML = "";
    toast("Xóa thành công!", "success");
    setTimeout(() => location.reload(), 500);
  }

  window.confirmDelete = confirmDelete;

  document.querySelectorAll(".js-del-event").forEach(btn => {
    btn.addEventListener("click", () => {
      const id = btn.dataset.id;
      const code = btn.closest("tr").querySelector("td").innerText.trim();
      openDeleteConfirm(id, code);
    });
  });
});
