<?php
/**
 * Loebbeshop Proxy API
 * ---------------------
 * - Ruft Produktdaten aus Smartstore OData ab
 * - Unterstützt Suche nach Name, Hersteller, SKU (Fallback)
 * - GPT-kompatible JSON-Ausgabe
 * - Sichere URL-Kodierung
 * - CORS aktiviert
 * 
 * Autor: Loebbeshop
 * Stand: 2025-12
 */

// --- CORS erlauben (für GPT/OpenAI & externe Tools) ---
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
$top = isset($_GET['top']) ? intval($_GET['top']) : 5;
if ($top <= 0 || $top > 50) $top = 5;
$q = isset($_GET['q']) ? trim($_GET['q']) : null;

// === Smartstore-Endpunkt ===
$smartstoreUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top={$top}";

// === Suchfunktion (Name/Manufacturer) ===
if (!empty($q)) {
    $encodedQ = str_replace("'", "''", $q);
    $filter = "contains(Name,'{$encodedQ}') or contains(Manufacturer,'{$encodedQ}')";
    $smartstoreUrl .= "&" . '$filter=' . urlencode($filter);
}

// === Auth vorbereiten ===
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

// === Funktion: API-Aufruf durchführen ===
function callSmartstore($url, $authHeader) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: $authHeader",
            "Accept: application/json",
            "User-Agent: LoebbeshopProxy/1.0 (+https://www.loebbeshop.de)"
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [$response, $httpCode, $curlError];
}

// === Erster Request (Name/Manufacturer) ===
list($response, $httpCode, $curlError) = callSmartstore($smartstoreUrl, $authHeader);

// === Fehler bei cURL? ===
if ($curlError) {
    http_response_code(500);
    echo json_encode(["error" => "Proxy-Fehler: " . $curlError]);
    exit;
}

// === Wenn kein Treffer, Fallback: exakte SKU-Suche ===
if ($httpCode === 200 && !empty($q)) {
    $data = json_decode($response, true);
    if (empty($data['value'])) {
        $encodedQ = str_replace("'", "''", $q);
        $skuFilter = "Sku eq '{$encodedQ}'";
        $skuUrl = "https://www.loebbeshop.de/odata/v1/Products?\$filter=" . urlencode($skuFilter);

        list($skuResponse, $skuCode, $skuError) = callSmartstore($skuUrl, $authHeader);

        if ($skuCode === 200 && !empty($skuResponse)) {
            $response = $skuResponse;
        }
    }
}

// === Debug (optional aktivieren) ===
// file_put_contents(__DIR__ . "/debug_log.json", json_encode([
//     "timestamp" => date('Y-m-d H:i:s'),
//     "url" => $smartstoreUrl,
//     "status" => $httpCode,
//     "responseSnippet" => substr($response, 0, 500)
// ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// === HTTP-Fehler weitergeben ===
if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode([
        "error" => "Smartstore-API antwortete mit HTTP $httpCode",
        "url" => $smartstoreUrl
    ]);
    exit;
}

// === Erfolgreiche Antwort ===
echo $response;
