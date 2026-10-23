<?php
header('Content-Type: application/json; charset=utf-8');

function sendResponse($statusCode, $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function readXmlFromWorkbook($workbook, $path)
{
    $xml = $workbook->getFromName($path);
    if ($xml === false) {
        throw new RuntimeException('Workbook is missing ' . $path);
    }

    $document = new DOMDocument();
    if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
        throw new RuntimeException('Workbook contains invalid XML.');
    }

    return $document;
}

function normalizeWorkbookPath($path)
{
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', ltrim($path, '/'))) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($parts === []) {
                throw new RuntimeException('Workbook contains an invalid worksheet path.');
            }
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
}

function getOrderName($filePath)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZIP extension is required to read order workbooks.');
    }

    $workbook = new ZipArchive();
    if ($workbook->open($filePath) !== true) {
        throw new RuntimeException('Unable to open workbook.');
    }

    try {
        $mainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $officeRelationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $packageRelationshipNamespace = 'http://schemas.openxmlformats.org/package/2006/relationships';

        $workbookDocument = readXmlFromWorkbook($workbook, 'xl/workbook.xml');
        $workbookXPath = new DOMXPath($workbookDocument);
        $workbookXPath->registerNamespace('x', $mainNamespace);
        $workbookXPath->registerNamespace('r', $officeRelationshipNamespace);
        $sheet = $workbookXPath->query('/x:workbook/x:sheets/x:sheet')->item(0);
        if (!$sheet instanceof DOMElement) {
            throw new RuntimeException('Workbook contains no worksheets.');
        }

        $relationshipId = $sheet->getAttributeNS($officeRelationshipNamespace, 'id');
        $relationshipsDocument = readXmlFromWorkbook($workbook, 'xl/_rels/workbook.xml.rels');
        $relationshipsXPath = new DOMXPath($relationshipsDocument);
        $relationshipsXPath->registerNamespace('r', $packageRelationshipNamespace);
        $worksheetTarget = null;
        foreach ($relationshipsXPath->query('/r:Relationships/r:Relationship') as $relationship) {
            if ($relationship->getAttribute('Id') === $relationshipId) {
                $worksheetTarget = $relationship->getAttribute('Target');
                break;
            }
        }
        if ($worksheetTarget === null || $worksheetTarget === '') {
            throw new RuntimeException('Workbook worksheet could not be located.');
        }

        $worksheetPath = normalizeWorkbookPath(
            substr($worksheetTarget, 0, 1) === '/' ? $worksheetTarget : 'xl/' . $worksheetTarget
        );
        $worksheetDocument = readXmlFromWorkbook($workbook, $worksheetPath);
        $worksheetXPath = new DOMXPath($worksheetDocument);
        $worksheetXPath->registerNamespace('x', $mainNamespace);
        $cell = $worksheetXPath->query('//x:c[@r="D2"]')->item(0);
        if (!$cell instanceof DOMElement) {
            return '';
        }

        if ($cell->getAttribute('t') === 'inlineStr') {
            $inlineString = $worksheetXPath->query('./x:is', $cell)->item(0);
            return $inlineString ? trim($inlineString->textContent) : '';
        }

        $valueNode = $worksheetXPath->query('./x:v', $cell)->item(0);
        if (!$valueNode) {
            return '';
        }

        $value = trim($valueNode->textContent);
        if ($cell->getAttribute('t') !== 's') {
            return $value;
        }

        $sharedStringsDocument = readXmlFromWorkbook($workbook, 'xl/sharedStrings.xml');
        $sharedStringsXPath = new DOMXPath($sharedStringsDocument);
        $sharedStringsXPath->registerNamespace('x', $mainNamespace);
        $sharedString = $sharedStringsXPath->query('/x:sst/x:si')->item((int) $value);
        return $sharedString ? trim($sharedString->textContent) : '';
    } finally {
        $workbook->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(405, ['success' => false, 'message' => 'Only GET requests are allowed.']);
}

$ordersDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'orderfiles';
if (!is_dir($ordersDirectory)) {
    sendResponse(500, ['success' => false, 'message' => 'Order files directory was not found.']);
}
if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
    sendResponse(500, [
        'success' => false,
        'message' => 'The PHP ZIP and DOM extensions are required to read order workbooks.',
    ]);
}

$orders = [];
foreach (scandir($ordersDirectory) as $fileName) {
    if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'xlsx') {
        continue;
    }

    $filePath = $ordersDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (!is_file($filePath)) {
        continue;
    }

    try {
        $orders[] = [
            'fileName' => $fileName,
            'name' => getOrderName($filePath),
            'lastModified' => date(DATE_ATOM, filemtime($filePath)),
        ];
    } catch (Throwable $error) {
        error_log('Unable to read order workbook ' . $fileName . ': ' . $error->getMessage());
    }
}

sendResponse(200, ['orders' => $orders]);
