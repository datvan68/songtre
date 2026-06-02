<?php
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}

// User-Agent BẮT BUỘC
$ua = "QR-Attendance-System/1.0 (contact: admin@localhost)";

$url = "https://nominatim.openstreetmap.org/search?" . http_build_query([
    'q' => $q,
    'format' => 'json',
    'limit' => 5,
    'addressdetails' => 1
]);

$opts = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: $ua\r\n"
    ]
];

$ctx = stream_context_create($opts);
$res = @file_get_contents($url, false, $ctx);

if ($res === false) {
    echo json_encode([]);
    exit;
}

$data = json_decode($res, true);

// Ép kiểu chuẩn cho JS
$out = [];
foreach ($data as $d) {
    if (isset($d['lat'], $d['lon'])) {
        $out[] = [
            'lat' => $d['lat'],
            'lon' => $d['lon'],
            'display_name' => $d['display_name'] ?? ''
        ];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
