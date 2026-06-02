<?php
// config/google-client.php

// đảm bảo autoload (trong trường hợp controller quên include)
if (!class_exists(\Google\Client::class)) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

use Google\Client;
use Google\Service\Drive;

function getGoogleClient(): Client
{
    $client = new Client();
    $client->setApplicationName('SongTre Shared Upload');

    $jsonPath = __DIR__ . '/service-account.json';
    if (!is_file($jsonPath)) {
        throw new Exception("Missing service-account.json at: {$jsonPath}");
    }

    $client->setAuthConfig($jsonPath);

    // Shared Drive + upload file
    $client->setScopes([
        'https://www.googleapis.com/auth/drive',
    ]);

    return $client;
}

function getServiceAccountEmail(): ?string
{
    $p = __DIR__ . '/service-account.json';
    if (!is_file($p)) return null;
    $j = json_decode((string)file_get_contents($p), true);
    return $j['client_email'] ?? null;
}

function getDriveService(): Drive
{
    // Nếu lỗi autoload, báo rõ để debug
    if (!class_exists(\Google\Service\Drive::class)) {
        throw new Exception('Google\\Service\\Drive class not found. Check vendor/autoload.php and package versions.');
    }
    return new Drive(getGoogleClient());
}

/**
 * Option helper: dùng cho files->get / create / listFiles
 * - listFiles nên có includeItemsFromAllDrives
 */
function driveGetCreateOpts(array $opt = []): array
{
    return $opt + [
        'supportsAllDrives' => true,
    ];
}

function driveListOpts(array $opt = []): array
{
    return $opt + [
        'supportsAllDrives' => true,
        'includeItemsFromAllDrives' => true,
    ];
}
