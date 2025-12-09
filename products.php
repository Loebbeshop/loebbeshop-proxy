<?php
/**
 * Loebbeshop Proxy API – Lokaler SKU-Fallback
 * --------------------------------------------
 * - Sucht Name + Hersteller über Smartstore
 * - Fallback: lädt Produkte & filtert SKU lokal (kein Smartstore-500 mehr)
 * - GPT-kompatibel (CORS aktiv)
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// === API-Keys ===
$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

// === Parameter ===
$top = isset($_GET['top']) ? intval($_GET['top']) : 5;
if ($top <= 0 || $top > 100) $top = 5;
$q = isset($_GET['q']) ? trim($_GET['q']) : null;

$baseUrl = "https://www.loebbeshop.de/odata/v1/Products";
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

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
        CURLOPT_TIMEOUT => 25
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$response, $code, $error];
}

// === Wenn kein Suchbegriff ===
if (empty($q)) {
    $url = "{$baseUrl}?\$top={$top}";
    list($response, $code, $error) = callSmartstore($url, $authHeader);
} else {
    // 1️⃣ Suche nach Name/Manufacturer
    $encodedQ = str_replace("'", "''", $q);
    $filter = "contains(Name,'{$encodedQ}') or contains(Manufacturer,'{$encodedQ}')";
    $url = "{$baseUrl}?\$top={$top}&" . '$filter=' . urlencode($filter);

    list($response, $code, $error) = callSmartstore($url, $authHeader);

    // 2️⃣ Fallback: lokale SKU-Suche (wenn kein Treffer)
    if ($code === 200 && !empty($response)) {
        $data = json_decode($response, true);
        if (empty($data['value'])) {
            // Hole bis zu 200 Produkte & suche lokal
            $skuUrl = "{$baseUrl}?\$top=200";
            list($allResponse, $allCode, $allError) = callSmartstore($skuUrl, $authHeader);
            if ($allCode === 200 && !empty($allResponse)) {
                $allData = json_decode($allResponse, true);
                $matches = array_filter($allData['value'], function ($item) use ($q) {
                    return isset($item['Sku']) && strcasecmp(trim($item['Sku']), $q) === 0;
                });
                $data['value'] = array_values($matches);
                $response = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
        }
    }
}

// === Fehlerbehandlung ===
if (!empty($error)) {
    http_response_code(500);
    echo json_encode(["error" => "Proxy-Fehler: $error"]);
    exit;
}

if (empty($response)) {
    http_response_code(404);
    echo json_encode(["error" => "Keine Daten gefunden"]);
    exit;
}

echo $response;
