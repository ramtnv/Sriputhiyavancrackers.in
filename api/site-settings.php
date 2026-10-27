<?php
header('Content-Type: application/json; charset=utf-8');
$settingsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'site-settings.json';

function respond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!is_file($settingsPath)) respond(200, new stdClass());
    $settings = json_decode(file_get_contents($settingsPath));
    if (!is_object($settings)) respond(500, ['success' => false, 'message' => 'Settings file is invalid.']);
    respond(200, $settings);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['success' => false, 'message' => 'Method not allowed.']);
$raw = file_get_contents('php://input');
if (strlen($raw) > 100000) respond(413, ['success' => false, 'message' => 'Settings payload is too large.']);
$settings = json_decode($raw, true);
if (!is_array($settings)) respond(400, ['success' => false, 'message' => 'Settings must be a JSON object.']);
$width = filter_var($settings['width'] ?? null, FILTER_VALIDATE_INT);
if ($width === false || $width < 720 || $width > 1600) respond(400, ['success' => false, 'message' => 'Page width must be between 720 and 1600 pixels.']);
$colors = $settings['colors'] ?? [];
if (!is_array($colors)) respond(400, ['success' => false, 'message' => 'Colors must be an object.']);
foreach ($colors as $color) {
    if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) respond(400, ['success' => false, 'message' => 'Colors must use six-digit hex values.']);
}
$settings['width'] = $width;
$encoded = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
if (file_put_contents($settingsPath, $encoded . PHP_EOL, LOCK_EX) === false) respond(500, ['success' => false, 'message' => 'Unable to save site settings.']);
respond(200, ['success' => true]);
