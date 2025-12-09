<?php
/**
 * Loebbeshop Proxy API – finale Produktionsversion mit Cache
 * -----------------------------------------------------------
 * - Holt Produktdaten ohne $filter von Smartstore
 * - Filtert lokal nach Name, Hersteller, SKU
 * - Cacht die Smartstore-Antwort für 10 Minuten (reduziert Serverlast)
 * - GPT-kompatibel (CORS aktiviert)
 * 
 * Autor: Loebbeshop / 2025-12
 */

// --- CORS erlauben ---
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
if ($top <= 0 || $top > 100) $top = 5;

// === Smartstore-Endpunkt ===
$baseUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top=100";

// === Cache-Datei ===
$cacheDir = __DIR__ . "/cache";
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
$cacheFile = $cacheDir . "/products_cache.json";
$cacheTime = 600; // 10 Minuten (600 Sekunden)

// === Prüfen, ob Cache gültig ist ===
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    $data = json_decode(file_get_contents($cacheFile), true);
    $source = "cache";
} else {
    // === Smartstore-Daten neu abrufen ===
    $credentials = trim($publicKey) . ':' . trim($secretKey);
    $authHeader = 'Basic ' . base64_encode($credentials);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $baseUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: $authHeader",
            "Accept: application/json",
            "User-Agent: LoebbeshopProxy/1.0 (+https://www.loebbeshop.de)"
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 60
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

    // Cache speichern
    file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $source = "smartstore";
}

// === Lokales Filtern ===
if (!empty($q) && isset($data['value'])) {
    $qLower = mb_strtolower($q);
    $filtered = array_filter($data['value'], function ($item) use ($qLower) {
        return (isset($item['Name']) && mb_stripos($item['Name'], $qLower) !== false)
            || (isset($item['Manufacturer']) && mb_stripos($item['Manufacturer'], $qLower) !== false)
            || (isset($item['Sku']) && mb_stripos($item['Sku'], $qLower) !== false);
    });
    $data['value'] = array_slice(array_values($filtered), 0, $top);
} else {
    $data['value'] = array_slice($data['value'], 0, $top);
}

// === Ausgabe ===
echo json_encode([
    "source" => $source,
    "result_count" => count($data['value']),
    "results" => $data['value']
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
