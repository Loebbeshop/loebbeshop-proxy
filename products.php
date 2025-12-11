<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * LöbbeShop Smartstore Proxy (Version 2.2)
 * ----------------------------------------
 * Unterstützt:
 *  - ?q=Wartungsset
 *  - ?num=7513046
 *  - ?q=Wartungsset&num=7513046
 */

$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

$q   = isset($_GET['q'])   ? trim($_GET['q'])   : null;
$num = isset($_GET['num']) ? trim($_GET['num']) : null;
$top = 200;

$baseUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top={$top}";

if ($num && $q) {
    // Kombinierte Suche
    $filter = "(contains(ShortDescription,'{$num}') or contains(Name,'{$num}') or contains(Sku,'{$num}'))";
    $filter .= " and (contains(Name,'{$q}') or contains(ShortDescription,'{$q}'))";
    $smartstoreUrl = "{$baseUrl}&\$filter=" . urlencode($filter);
} elseif ($num) {
    // Nur Herstellernummer
    $filter = "contains(ShortDescription,'{$num}') or contains(Name,'{$num}') or contains(Sku,'{$num}')";
    $smartstoreUrl = "{$baseUrl}&\$filter=" . urlencode($filter);
} elseif ($q) {
    // Nur Suchbegriff
    $filter = "contains(Name,'{$q}') or contains(ShortDescription,'{$q}')";
    $smartstoreUrl = "{$baseUrl}&\$filter=" . urlencode($filter);
} else {
    $smartstoreUrl = "{$baseUrl}";
}

// Authentifizierung
$authHeader = 'Basic ' . base64_encode("{$publicKey}:{$secretKey}");

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $smartstoreUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: {$authHeader}",
        "Accept: application/json",
        "User-Agent: LoebbeshopProxy/2.2"
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 25
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(["error" => "Proxy-Fehler: $error"]);
    exit;
}

if ($httpCode !== 200) {
    echo json_encode([
        "error" => "Smartstore-API antwortete mit HTTP $httpCode",
        "status" => $httpCode,
        "url" => $smartstoreUrl
    ]);
    exit;
}

$data = json_decode($response, true);
if (!$data || !isset($data['value'])) {
    echo json_encode([
        "error" => "Smartstore-API Fehler oder keine Antwort",
        "status" => $httpCode,
        "url" => $smartstoreUrl
    ]);
    exit;
}

$results = array_map(function ($item) {
    return [
        "Id" => $item['Id'] ?? null,
        "Sku" => $item['Sku'] ?? null,
        "Name" => $item['Name'] ?? '(Kein Name)',
        "ShortDescription" => $item['ShortDescription'] ?? '',
        "Price" => isset($item['Price']) ? round($item['Price'], 2) : null,
        "ProductUrl" => "https://www.loebbeshop.de/search/?q=" . urlencode($item['Sku'])
    ];
}, $data['value']);

echo json_encode([
    "query" => $q,
    "herstellnummer" => $num,
    "result_count" => count($results),
    "results" => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
