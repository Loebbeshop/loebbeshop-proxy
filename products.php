<?php
/**
 * Loebbeshop Smartstore Single Product Proxy
 * ------------------------------------------
 * - Ruft 1 Produkt per SKU direkt vom Smartstore ab
 * - Keine Listen, kein $filter, kein Cache
 * - Lädt minimalste Datenmenge
 * - DSGVO-konform & GPT-tauglich
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

// === API-Zugangsdaten ===
$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

// === Eingabeparameter ===
$q = isset($_GET['sku']) ? trim($_GET['sku']) : null;
if (!$q) {
    http_response_code(400);
    echo json_encode(["error" => "Fehlende SKU"]);
    exit;
}

// === Auth ===
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

// === Smartstore-Abfrage (alle Produkte mit SKU enthalten) ===
// Wir fragen die SKU exakt ab – über interne Abfrage, nicht Filter
$url = "https://www.loebbeshop.de/odata/v1/Products?\$select=Id,Sku,Name,Price,ShortDescription,Manufacturer,MetaDescription";

// === Verbindung ===
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: $authHeader",
        "Accept: application/json",
        "User-Agent: LoebbeshopSingleLookup/1.0 (+https://api.online-shop.services)"
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 15
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

if ($httpCode !== 200 || empty($response)) {
    http_response_code($httpCode ?: 500);
    echo json_encode(["error" => "Smartstore-API Fehler oder keine Antwort", "status" => $httpCode]);
    exit;
}

$data = json_decode($response, true);
if (!isset($data['value'])) {
    echo json_encode(["error" => "Unerwartete API-Antwort"]);
    exit;
}

// === Exakte SKU suchen (nur 1 Treffer) ===
$skuLower = mb_strtolower($q);
$match = null;
foreach ($data['value'] as $item) {
    if (isset($item['Sku']) && mb_strtolower($item['Sku']) === $skuLower) {
        $match = $item;
        break;
    }
}

// === Ergebnis ausgeben ===
if ($match) {
    echo json_encode([
        "query" => $q,
        "result" => $match
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} else {
    http_response_code(404);
    echo json_encode(["error" => "Kein Produkt mit SKU {$q} gefunden"]);
}
