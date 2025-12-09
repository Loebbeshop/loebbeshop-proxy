<?php
/**
 * Loebbeshop Proxy API – stabile "No-filter"-Version
 * --------------------------------------------------
 * - Holt Produktdaten ohne $filter von Smartstore
 * - Filtert Name, Hersteller, SKU lokal in PHP
 * - Kein Smartstore-OData-Fehler mehr möglich
 * - GPT-kompatibel (CORS aktiviert)
 * 
 * Autor: Loebbeshop
 * Stand: 2025-12
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
// Wir holen z. B. die ersten 300 Produkte (kannst du anpassen)
$baseUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top=300";

// === Auth vorbereiten ===
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

// === cURL-Request ===
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

if ($httpCode !== 200 || empty($response)) {
    http_response_code($httpCode ?: 500);
    echo json_encode(["error" => "Smartstore-API Fehler oder keine Antwort"]);
    exit;
}

// === Daten verarbeiten ===
$data = json_decode($response, true);

// === Lokales Filtern ===
if (!empty($q) && isset($data['value'])) {
    $qLower = mb_strtolower($q);

    $filtered = array_filter($data['value'], function ($item) use ($qLower) {
        $inName = isset($item['Name']) && mb_stripos($item['Name'], $qLower) !== false;
        $inManufacturer = isset($item['Manufacturer']) && mb_stripos($item['Manufacturer'], $qLower) !== false;
        $inSku = isset($item['Sku']) && mb_stripos($item['Sku'], $qLower) !== false;
        return $inName || $inManufacturer || $inSku;
    });

    // Begrenze auf $top Ergebnisse
    $data['value'] = array_slice(array_values($filtered), 0, $top);
} else {
    // Wenn keine Suche, zeige einfach die ersten $top Produkte
    $data['value'] = array_slice($data['value'], 0, $top);
}

// === Antwort zurückgeben ===
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
