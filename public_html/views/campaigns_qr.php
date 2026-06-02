<?php

function sessionFromDate($dt)
{

    if (!$dt)
        return '-';
    $tz = new DateTimeZone('Asia/Ho_Chi_Minh');
    $d = new DateTime($dt, $tz);
    $h = (int) $d->format('H');

    if ($h >= 5 && $h < 12)
        return 'Buổi sáng';
    if ($h >= 12 && $h < 18)
        return 'Buổi chiều';
    return 'Buổi tối';
}

function fmtDateTime($dt)
{
    if (!$dt)
        return '-';
    try {
        $d = new DateTime($dt, new DateTimeZone('Asia/Ho_Chi_Minh'));
        return $d->format('d-m-Y H:i');
    } catch (Exception $e) {
        return '-';
    }
}

?>

<section>
    <div class="grid-container">
        <div class="mb-4">
            <a href="<?= BASE_URL ?>index.php?p=campaigns" class="px-4 py-2 bg-gray-100 rounded hover:bg-gray-200">
                ← Trở về danh sách phong trào
            </a>
        </div>

        <!-- CREATE QR -->
        <div class="bg-white p-6 rounded-xl shadow border mb-8">
            <h1 class="text-2xl font-bold mb-4">Tạo QR điểm danh: <?= htmlspecialchars($c['title']) ?></h1>

            <div class="space-y-4">
                <div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <!-- Thời gian bắt đầu -->
                        <div>
                            <label class="font-medium block mb-1">Thời gian bắt đầu</label>
                            <input id="starts_at" type="text" class="w-full border p-2 rounded"
                                placeholder="Chọn thời gian bắt đầu...">
                            <p class="text-xs text-gray-500 mt-1">
                                QR chỉ có hiệu lực sau thời điểm này.
                            </p>
                        </div>

                        <!-- Thời gian kết thúc -->
                        <div>
                            <label class="font-medium block mb-1">Thời gian hết hạn</label>
                            <input id="expires_at" type="text" class="w-full border p-2 rounded"
                                placeholder="Chọn thời gian hết hạn...">
                            <p class="text-xs text-gray-500 mt-1">
                                Sau thời gian này, QR sẽ tự động khóa.
                            </p>
                        </div>

                    </div>
                </div>

                <button id="btnCreateQR" class="px-5 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    Tạo QR mới
                </button>
                <div>
                    <label class="font-medium block mb-1">Địa chỉ điểm danh</label>

                    <div class="flex gap-2 mb-2">
                        <input type="text" id="addressInput" class="flex-1 border p-2 rounded"
                            placeholder="Nhập địa chỉ hoặc click trên bản đồ">

                        <button type="button" id="btnSearchAddress"
                            class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300">
                            Tìm
                        </button>
                    </div>

                    <div id="map" class="w-full h-[300px] rounded border"></div>

                    <p class="text-xs text-gray-500 mt-1">
                        Có thể nhập địa chỉ hoặc click trực tiếp trên bản đồ để chọn vị trí.
                    </p>

                    <input type="hidden" id="lat" value="">
                    <input type="hidden" id="lng" value="">
                </div>



            </div>
        </div>

        <!-- LIST -->
        <div class="bg-white p-6 rounded-xl shadow border">
            <h2 class="text-xl font-bold mb-4">Danh sách mã QR đã tạo</h2>

            <table class="w-full border text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="border p-2 text-left ">Buổi</th>
                        <th class="border p-2 text-left w-[340px]">Địa điểm điểm danh</th>
                        <th class="border p-2 text-left">Thời gian</th>
                        <th class="border p-2 text-left">Trạng thái</th>
                        <th class="border p-2 text-center">Hành động</th>
                    </tr>
                </thead>

                <tbody data-role="admin">

                    <?php if (empty($events)): ?>
                        <tr>
                            <td colspan="5" class="border p-3 text-center text-gray-500">
                                Chưa tạo mã QR nào.
                            </td>
                        </tr>

                    <?php else:
                        foreach ($events as $e):

                            $manual = $e['manual_status'] ?? 'open';
                            $exp = $e['expires_at'];

                            $expired = false;
                            if ($exp) {
                                $d1 = new DateTime($exp, new DateTimeZone('Asia/Ho_Chi_Minh'));
                                $d2 = new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
                                $expired = ($d1 <= $d2);
                            }

                            // ƯU TIÊN: khóa thủ công
                            if ($manual === 'locked') {
                                $status_text = "Đã khóa";
                                $status_class = "text-red-600 font-semibold";
                                $locked_state = 1;

                            } elseif ($expired) {
                                $status_text = "Đã hết hạn";
                                $status_class = "text-orange-600 font-semibold";
                                $locked_state = 0;

                            } else {
                                $status_text = "Đang mở";
                                $status_class = "text-green-600";
                                $locked_state = 0;
                            }


                            ?>

                            <tr data-id="<?= $e['id'] ?>">
                                <td class="border p-2 text-sm font-medium">
                                    <?php
                                    switch ($e['session'] ?? '') {
                                        case 'morning':
                                            echo 'Buổi sáng';
                                            break;
                                        case 'afternoon':
                                            echo 'Buổi chiều';
                                            break;
                                        case 'evening':
                                            echo 'Buổi tối';
                                            break;
                                        default:
                                            echo '-';
                                    }
                                    ?>
                                </td>
                                <td class="border p-2 w-[340px]">
                                    <div class="text-sm">
                                        <?= htmlspecialchars($e['address'] ?: 'Chưa chọn địa điểm') ?>
                                    </div>
                                </td>

                                <td class="border p-2 text-xs leading-5">
                                    <div>
                                        <span class="font-medium">Bắt đầu:</span>
                                        <?= fmtDateTime($e['starts_at']) ?>
                                    </div>
                                    <div>
                                        <span class="font-medium">Hết hạn:</span>
                                        <?= fmtDateTime($e['expires_at']) ?>
                                    </div>

                                </td>


                                <td class="border p-2">
                                    <span class="status-text <?= $status_class ?>">
                                        <?= $status_text ?>
                                    </span>
                                </td>

                                <td class="border p-2">
                                    <div class="flex justify-center items-center gap-2">

                                        <!-- Xem / In QR -->
                                        <a href="<?= BASE_URL ?>index.php?p=campaigns_check&code=<?= urlencode($e['code']) ?>"
                                            data-no-spa="1" class="px-3 min-w-[100px] h-[32px] flex items-center justify-center
                  bg-green-600 text-white rounded text-xs font-semibold">
                                            Xem / In QR
                                        </a>

                                        <!-- Gia hạn -->
                                        <button type="button" class="js-extend px-3 min-w-[75px] h-[32px] flex items-center justify-center
                   bg-yellow-500 text-white rounded text-xs font-semibold">
                                            Gia hạn
                                        </button>

                                        <!-- XÓA -->
                                        <button type="button" class="js-del-event px-3 min-w-[75px] h-[32px] flex items-center justify-center
                   bg-red-600 text-white rounded text-xs font-semibold hover:bg-red-700" data-id="<?= $e['id'] ?>">
                                            Xóa
                                        </button>
                                    </div>
                                </td>
                            </tr>

                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>


        <!-- MODAL ROOT -->
        <div id="global-modal-root"></div>

        <!-- TOAST -->
        <div id="toastBox" class="fixed top-4 right-4 space-y-2 pointer-events-none"></div>

        <!-- FLATPICKR -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

        <script>
            flatpickr("#starts_at", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                time_24hr: true,
                defaultDate: new Date()
            });

            flatpickr("#expires_at", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                time_24hr: true
            });
        </script>


        <script>window.CAMPAIGN_ID = <?= (int) $cid ?>;</script>
        <!-- Leaflet -->
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

        <!-- Nominatim search (OSM) -->
        <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
        <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />

        <script src="<?= BASE_URL ?>assets/js/campaigns_qr.js?v=<?= time() ?>"></script>

    </div>
</section>
<style>
    /* Map luôn nằm dưới modal */
    #map,
    .leaflet-container {
        position: relative;
        z-index: 1 !important;
    }
</style>