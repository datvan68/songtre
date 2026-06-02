<?php
// config/security.php

// Chỉ bật Secure khi đã chạy HTTPS
$useHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'domain' => '',
  'secure' => $useHttps,        // HTTPS => true
  'httponly' => true,
  'samesite' => 'Lax',          // Lax ok cho web thường
]);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_start();
