<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Smartstore Proxy – Produktsuche
 * Datei: products.php
 * Zweck: Gibt bis zu $top Produkte anhand eines Suchbegriffs zurück
 */

$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

// === Parameter ===
$q = isset($_GET['q']) ? trim($_GET['q']) : null;
$top = isset($_GET['top']) ? intval($_GET['top']) : 5;
if ($top <= 0 || $top > 50) $top = 5;

if (!$q) {
    http_response_code(400);
    echo json_encode(["error" => "Fehlender Suchbegriff"]);
    exit;
}

// === Auth vorbereiten ===
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

// === Smartstore-Endpunkt (mit $search statt $filter) ===
$encodedQuery = urlencode($q);
$smartstoreUrl = "https://www.loebbeshop.de/odata/v1/Products?\$search={$encodedQuery}&\$top={$top}";

// === cURL-Request ===
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $smartstoreUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: $authHeader",
        "Accept: application/json",
        "User-Agent: SmartstoreProxy/1.0 (+https://api.online-shop.services)"
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 20
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// === Fehlerbehandlung ===
if ($curlError) {
    http_response_code(500);
    echo json_encode(["error" => "Proxy-Fehler: " . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode([
        "error" => "Smartstore-API Fehler oder keine Antwort",
        "status" => $httpCode,
        "url" => $smartstoreUrl
    ]);
    exit;
}

// === Erfolgreiche Antwort ===
$data = json_decode($response, true);
if (isset($data['value']) && is_array($data['value'])) {
    $result = [];
    foreach ($data['value'] as $item) {
        $result[] = [
            "Id" => $item["Id"] ?? null,
            "Sku" => $item["Sku"] ?? null,
            "Name" => $item["Name"] ?? "",
            "Manufacturer" => $item["Manufacturer"] ?? "",
            "Price" => isset($item["Price"]) ? round($item["Price"], 2) . " €" : "",
            "ShortDescription" => $item["ShortDescription"] ?? "",
            "ProductUrl" => "https://www.loebbeshop.de/product/" . ($item["Id"] ?? ""),
            "ImageUrl" => $item["ImageUrl"] ?? null
        ];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo json_encode(["error" => "Keine Produkte gefunden", "query" => $q]);
}
