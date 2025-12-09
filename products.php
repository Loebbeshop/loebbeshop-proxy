<?php
/**
 * Loebbeshop Proxy API – Vollständig lokale Filterung (stabil)
 * ------------------------------------------------------------
 * - Holt Produkte ohne Filter von Smartstore
 * - Filtert Name, Manufacturer, SKU lokal in PHP
 * - Keine 500-Fehler, kompatibel mit allen Smartstore-Versionen
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
if ($top <= 0 || $top > 100) $top = 5;

// === Smartstore-Endpunkt ===
$baseUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top=200";

// === Auth vorbereiten ===
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

// === Daten abrufen ===
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
    CURLOPT_TIMEOUT => 25
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

if ($httpCode !== 200 || empty($response)) {
    http_response_code($httpCode ?: 500);
    echo json_encode(["error" => "Smartstore-API Fehler oder keine Antwort"]);
    exit;
}

// === Daten verarbeiten ===
$data = json_decode($response, true);

// === Lokales Filtern (Name, Manufacturer, SKU) ===
if (!empty($q) && isset($data['value'])) {
    $qLower = mb_strtolower($q);
    $filtered = array_filter($data['value'], function ($item) use ($qLower) {
        return (isset($item['Name']) && mb_stripos($item['Name'], $qLower) !== false)
            || (isset($item['Manufacturer']) && mb_stripos($item['Manufacturer'], $qLower) !== false)
            || (isset($item['Sku']) && mb_stripos($item['Sku'], $qLower) !== false);
    });
    $data['value'] = array_slice(array_values($filtered), 0, $top);
}

// === Antwort zurückgeben ===
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
