<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Smartstore Proxy – SKU-Abfrage
 * Zweck: Gibt genau ein Produkt anhand der SKU zurück
 * Datei: product.php
 */

// === Smartstore API Keys ===
$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

// === Parameter ===
$sku = isset($_GET['sku']) ? trim($_GET['sku']) : null;
if (!$sku) {
    http_response_code(400);
    echo json_encode(["error" => "Fehlende SKU"]);
    exit;
}

// === Smartstore-Endpunkt ===
$smartstoreUrl = "https://www.loebbeshop.de/odata/v1/Products?\$filter=Sku eq '{$sku}'";

// === Auth vorbereiten ===
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

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
        "status" => $httpCode
    ]);
    exit;
}

// === Antwort prüfen und ggf. reduzieren ===
$data = json_decode($response, true);
if (isset($data['value']) && count($data['value']) > 0) {
    $product = $data['value'][0];
    echo json_encode([
        "query" => $sku,
        "result" => [
            "Name" => $product["Name"] ?? "",
            "ShortDescription" => $product["ShortDescription"] ?? "",
            "MetaDescription" => $product["MetaDescription"] ?? "",
            "Sku" => $product["Sku"] ?? "",
            "Price" => $product["Price"] ?? 0,
            "Id" => $product["Id"] ?? 0
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo json_encode(["query" => $sku, "result" => null]);
}
