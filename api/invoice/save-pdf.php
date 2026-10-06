<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST is allowed.']);
    exit;
}

$root = dirname(__DIR__, 2);
$invoiceRoot = $root . DIRECTORY_SEPARATOR . 'Invoice';
if (!is_dir($invoiceRoot)) {
    if (!mkdir($invoiceRoot, 0777, true) && !is_dir($invoiceRoot)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to create Invoice root folder.']);
        exit;
    }
    chmod($invoiceRoot, 0777);
}

$year = isset($_POST['year']) ? trim((string) $_POST['year']) : (string) date('Y');
$yearDir = $invoiceRoot . DIRECTORY_SEPARATOR . $year;
if (!is_dir($yearDir)) {
    if (!mkdir($yearDir, 0777, true) && !is_dir($yearDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to create year folder.']);
        exit;
    }
    chmod($yearDir, 0777);
}

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'PDF file not uploaded.']);
    exit;
}

$fileName = isset($_POST['filename']) && trim((string) $_POST['filename']) !== ''
    ? basename(trim((string) $_POST['filename']))
    : 'invoice.pdf';

if (!preg_match('/\.pdf$/i', $fileName)) {
    $fileName .= '.pdf';
}

$targetPath = $yearDir . DIRECTORY_SEPARATOR . $fileName;
if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath) === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save the PDF to the server.']);
    exit;
}

chmod($targetPath, 0777);

echo json_encode([
    'success' => true,
    'message' => 'Invoice PDF saved successfully.',
    'path' => '/Invoice/' . $year . '/' . $fileName
]);
