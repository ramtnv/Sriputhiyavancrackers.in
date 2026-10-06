<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$rawInput = file_get_contents('php://input');
if ($rawInput === false || trim($rawInput) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Request body is empty.']);
    exit;
}

$data = json_decode($rawInput, true);
if (!is_array($data) || !isset($data['fileName'], $data['data'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
    exit;
}

$fileName = basename((string) $data['fileName']);
if ($fileName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing file name.']);
    exit;
}

if (!preg_match('/\.xlsx$/i', $fileName)) {
    $fileName .= '.xlsx';
}

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'orderfiles';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create orderfiles directory.']);
    exit;
}

$values = $data['data'];
if (!is_array($values)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File data is not valid.']);
    exit;
}

$binary = '';
foreach ($values as $value) {
    $byte = (int) $value;
    if ($byte < 0 || $byte > 255) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File data contains invalid byte values.']);
        exit;
    }
    $binary .= chr($byte);
}

$savePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
$written = file_put_contents($savePath, $binary, LOCK_EX);
if ($written === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to write file to server.']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'File saved successfully.',
    'path' => '/orderfiles/' . $fileName
]);
