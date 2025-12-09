<?php
/**
 * Loebbeshop Proxy API (fehlerfreie SKU-Fallback-Version)
 * -------------------------------------------------------
 * - Durchsucht Name + Manufacturer (sicher)
 * - Wenn keine Treffer → prüft exakte SKU
 * - Verhindert Smartstore-500-Fehler
 * - GPT-kompatibel (CORS aktiviert)
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

// === Basis-URL ===
$baseUrl = "https://www.loebbeshop.de/odata/v1/Products";

// === Auth vorbereiten ===
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

// === Funktion für API-Aufrufe ===
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

// === Wenn kein Suchbegriff ===
if (empty($q)) {
    $url = "{$baseUrl}?\$top={$top}";
    list($response, $httpCode, $curlError) = callSmartstore($url, $authHeader);
} else {
    // 1️⃣ Suche Name/Manufacturer (sicher)
    $encodedQ = str_replace("'", "''", $q);
    $filter = "contains(Name,'{$encodedQ}') or contains(Manufacturer,'{$encodedQ}')";
    $url = "{$baseUrl}?\$top={$top}&" . '$filter=' . urlencode($filter);

    list($response, $httpCode, $curlError) = callSmartstore($url, $authHeader);

    // 2️⃣ Wenn keine Treffer → exakte SKU-Suche
    if ($httpCode === 200 && !empty($response)) {
        $data = json_decode($response, true);
        if (empty($data['value'])) {
            $skuFilter = "Sku eq '{$encodedQ}'";
            $skuUrl = "{$baseUrl}?\$filter=" . urlencode($skuFilter);
            list($skuResponse, $skuCode, $skuError) = callSmartstore($skuUrl, $authHeader);

            if ($skuCode === 200 && !empty($skuResponse)) {
                $response = $skuResponse;
            }
        }
    }
}

// === Fehlerbehandlung ===
if (!empty($curlError)) {
    http_response_code(500);
    echo json_encode(["error" => "Proxy-Fehler: " . $curlError]);
    exit;
}

if (empty($response)) {
    http_response_code(404);
    echo json_encode(["error" => "Keine Daten gefunden"]);
    exit;
}

// === Erfolgreiche Antwort ===
echo $response;
