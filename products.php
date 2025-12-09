<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Smartstore Proxy – Produktsuche (lokale Filterung)
 * Datei: products.php
 * Zweck: Gibt Produkte anhand eines Suchbegriffs zurück, ohne OData-Filter.
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

// === Smartstore-Endpunkt (ohne Filter oder Search) ===
$smartstoreUrl = "https://www.loebbeshop.de/odata/v1/Products?\$orderby=UpdatedOnUtc desc&\$top=200";

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
    CURLOPT_TIMEOUT => 30
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

// === Lokale Filterung ===
$data = json_decode($response, true);
if (!isset($data['value']) || !is_array($data['value'])) {
    echo json_encode(["error" => "Keine Daten von Smartstore erhalten"]);
    exit;
}

$qLower = mb_strtolower($q, 'UTF-8');
$results = [];

foreach ($data['value'] as $item) {
    $name = mb_strtolower($item['Name'] ?? '', 'UTF-8');
    $desc = mb_strtolower($item['ShortDescription'] ?? '', 'UTF-8');
    $sku = mb_strtolower($item['Sku'] ?? '', 'UTF-8');

    if (str_contains($name, $qLower) || str_contains($desc, $qLower) || str_contains($sku, $qLower)) {
        $results[] = [
            "Id" => $item["Id"] ?? null,
            "Sku" => $item["Sku"] ?? null,
            "Name" => $item["Name"] ?? "",
            "Manufacturer" => $item["Manufacturer"] ?? "",
            "Price" => isset($item["Price"]) ? round($item["Price"], 2) . " €" : "",
            "ShortDescription" => $item["ShortDescription"] ?? "",
            "ProductUrl" => "https://www.loebbeshop.de/product/" . ($item["Id"] ?? ""),
            "ImageUrl" => $item["ImageUrl"] ?? null
        ];

        if (count($results) >= $top) break;
    }
}

// === Antwort ===
if (count($results) > 0) {
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo json_encode(["error" => "Keine Produkte gefunden", "query" => $q]);
}
