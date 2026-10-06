<?php
header('Content-Type: application/json');

$root = dirname(__DIR__, 2);
$invoiceRoot = $root . DIRECTORY_SEPARATOR . 'Invoice';
if (!is_dir($invoiceRoot)) {
    mkdir($invoiceRoot, 0777, true);
    chmod($invoiceRoot, 0777);
}

$year = (int) date('Y');
$yearDir = $invoiceRoot . DIRECTORY_SEPARATOR . (string) $year;
if (!is_dir($yearDir)) {
    mkdir($yearDir, 0777, true);
    chmod($yearDir, 0777);
}

$highest = 0;
if (is_dir($yearDir)) {
    $files = scandir($yearDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || !preg_match('/\.pdf$/i', $file)) {
            continue;
        }

        $name = pathinfo($file, PATHINFO_FILENAME);
        if (preg_match('/(\d+)/', $name, $matches)) {
            $value = (int) $matches[1];
            if ($value > $highest) {
                $highest = $value;
            }
        }
    }
}

$next = $highest + 1;
$invoiceNo = 'SPC' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);

echo json_encode([
    'invoiceNo' => $invoiceNo,
    'year' => $year,
    'source' => 'server-folder'
]);
