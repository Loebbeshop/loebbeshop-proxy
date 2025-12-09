<?php
/**
 * Loebbeshop Proxy API – Hybrid-Version (SKU-optimiert)
 * ------------------------------------------------------------------------
 * - Erkennt SKU-Suchen automatisch (z. B. "7815884" oder "Z12345")
 * - Für SKUs → direkter OData-Filter, kein Massenabruf
 * - Für Textsuche → begrenzter Cache (Top 50 Produkte)
 * - Keine Änderungen im Smartstore erforderlich
 * 
 * Autor: Loebbeshop
 * Stand: 2025-12
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

// === Smartstore API Keys ===
$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

// === Parameter ===
$q = isset($_GET['q']) ? trim($_GET['q']) : null;
$top = isset($_GET['top']) ? intval($_GET['top']) : 5;
if ($top <= 0 || $top > 50) $top = 5;

// === Basis-URL ===
$baseUrl = "https://www.loebbeshop.de/odata/v1/Products";

// === Authentifizierung ===
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

// === Funktion für Smartstore-Aufruf ===
function fetchSmartstore($url, $authHeader)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: $authHeader",
            "Accept: application/json",
            "User-Agent: LoebbeshopProxy/2.0 (+https://www.loebbeshop.de)"
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
    if ($httpCode !== 200 || empty($response)) {
        http_response_code($httpCode ?: 500);
        echo json_encode(["error" => "Smartstore-API Fehler oder keine Antwort", "status" => $httpCode]);
        exit;
    }
    return json_decode($response, true);
}

// === Prüfen, ob es sich um eine SKU handelt ===
$isSkuSearch = false;
if (!empty($q)) {
    // Wenn der Suchbegriff überwiegend aus Ziffern, Buchstaben und Bindestrichen besteht
    if (preg_match('/^[A-Za-z0-9\-]+$/', $q)) {
        $isSkuSearch = true;
    }
    // Wenn der Begriff im Stil "Artikelnummer 7815884" ist, Zahl extrahieren
    elseif (preg_match('/\b\d{4,}\b/', $q, $matches)) {
        $q = $matches[0];
        $isSkuSearch = true;
    }
}

// === 1️⃣ SKU-Suche: Direkter OData-Filter (kein Cache nötig) ===
if ($isSkuSearch) {
    $url = $baseUrl . "?\$filter=Sku eq '" . urlencode($q) . "'";
    $data = fetchSmartstore($url, $authHeader);

    echo json_encode([
        "mode" => "sku",
        "query" => $q,
        "results" => $data['value'] ?? []
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// === 2️⃣ Allgemeine Suche: Nur Top X Produkte laden + lokal filtern ===
$cacheDir = __DIR__ . "/cache";
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
$cacheFile = $cacheDir . "/products_cache.json";
$cacheTime = 600; // 10 Minuten

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    $data = json_decode(file_get_contents($cacheFile), true);
    $source = "cache";
} else {
    $url = $baseUrl . "?\$top=50";
    $data = fetchSmartstore($url, $authHeader);
    file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $source = "smartstore";
}

// === Lokale Textsuche ===
if (!empty($q) && isset($data['value'])) {
    $qLower = mb_strtolower($q);
    $filtered = array_filter($data['value'], function ($item) use ($qLower) {
        return (isset($item['Name']) && mb_stripos($item['Name'], $qLower) !== false)
            || (isset($item['Manufacturer']) && mb_stripos($item['Manufacturer'], $qLower) !== false)
            || (isset($item['Sku']) && mb_stripos($item['Sku'], $qLower) !== false);
    });
    $data['value'] = array_slice(array_values($filtered), 0, $top);
}

echo json_encode([
    "mode" => "text",
    "query" => $q,
    "source" => $source,
    "result_count" => count($data['value']),
    "results" => $data['value']
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
