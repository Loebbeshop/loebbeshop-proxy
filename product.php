<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Smartstore PHP-Proxy – Einzelprodukt-Suche (über SKU)
 * Wenn die SKU existiert, liefert der Proxy Produktdaten
 * und einen korrekt formatierten Suchlink:
 * https://www.loebbeshop.de/search/?q={SKU}
 */

$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

$sku = isset($_GET['sku']) ? trim($_GET['sku']) : '';
if ($sku === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Fehlende SKU']);
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

// === Antwort prüfen ===
$data = json_decode($response, true);
if (empty($data['value'])) {
    echo json_encode([
        "query" => $sku,
        "message" => "Kein Produkt mit dieser SKU gefunden.",
        "shop_link" => "https://www.loebbeshop.de/search/?q=" . urlencode($sku)
    ]);
    exit;
}

// === Produkt gefunden ===
$product = $data['value'][0];

echo json_encode([
    "query" => $sku,
    "result" => [
        "Name" => $product["Name"],
        "ShortDescription" => $product["ShortDescription"],
        "MetaDescription" => $product["MetaDescription"],
        "Sku" => $product["Sku"],
        "Price" => isset($product["Price"]) ? $product["Price"] : null,
        "Id" => $product["Id"]
    ],
    "redirect" => "https://www.loebbeshop.de/search/?q=" . urlencode($sku)
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
