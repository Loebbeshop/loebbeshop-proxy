<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Loebbeshop PHP-Proxy (stabile Version)
 * - Holt Produktdaten aus Smartstore über OData
 * - Unterstützt Suche & SKU-Erkennung
 * - Vermeidet OData-Fehler durch sichere Fallbacks
 */

$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$top = isset($_GET['top']) ? intval($_GET['top']) : 5;
if ($top <= 0 || $top > 200) $top = 5;

// === Wenn es sich um eine SKU handelt (nur Ziffern, 6–8 Stellen)
if (preg_match('/^\d{6,8}$/', $q)) {
    $redirect = "https://www.loebbeshop.de/search/?q=" . urlencode($q);

    // Versuche, Produktinfos aus den ersten 200 Produkten zu finden
    $smartstoreUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top=200";
    $credentials = trim($publicKey) . ':' . trim($secretKey);
    $authHeader = 'Basic ' . base64_encode($credentials);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $smartstoreUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: $authHeader",
            "Accept: application/json",
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        echo json_encode([
            "info" => "Direkte SKU-Suche erkannt",
            "redirect" => $redirect,
            "message" => "Die Suche wurde direkt an den Loebbeshop weitergeleitet."
        ]);
        exit;
    }

    $data = json_decode($response, true);
    $found = null;

    if (!empty($data['value'])) {
        foreach ($data['value'] as $p) {
            if (isset($p['Sku']) && $p['Sku'] == $q) {
                $found = $p;
                break;
            }
        }
    }

    if ($found) {
        echo json_encode([
            "query" => $q,
            "result" => [
                "Name" => $found['Name'] ?? '',
                "ShortDescription" => strip_tags($found['ShortDescription'] ?? ''),
                "Sku" => $found['Sku'] ?? $q,
                "Price" => isset($found['Price']) ? round($found['Price'], 2) : null,
                "Id" => $found['Id'] ?? null
            ],
            "redirect" => $redirect
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Wenn Produkt nicht im Top-200 Cache enthalten ist
    echo json_encode([
        "info" => "Direkte SKU-Suche erkannt",
        "redirect" => $redirect,
        "message" => "Die Suche wurde direkt an den Loebbeshop weitergeleitet."
    ]);
    exit;
}

// === Allgemeine Suche ===
$smartstoreUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top={$top}&\$count=false";
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $smartstoreUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: $authHeader",
        "Accept: application/json",
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 20
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode([
        "error" => "Smartstore-API Fehler oder keine Antwort",
        "status" => $httpCode,
        "url" => $smartstoreUrl
    ]);
    exit;
}

$data = json_decode($response, true);
if (empty($data['value'])) {
    echo json_encode([
        "message" => "Keine Produkte gefunden.",
        "query" => $q,
        "suggestion" => "Direkt im Loebbeshop suchen: https://www.loebbeshop.de/search/?q=" . urlencode($q)
    ]);
    exit;
}

$results = [];
foreach ($data['value'] as $p) {
    if (stripos($p['Name'], $q) !== false || stripos($p['ShortDescription'] ?? '', $q) !== false) {
        $results[] = [
            "Id" => $p['Id'] ?? null,
            "Sku" => $p['Sku'] ?? null,
            "Name" => $p['Name'] ?? null,
            "Price" => isset($p['Price']) ? round($p['Price'], 2) . " €" : null,
            "ShortDescription" => strip_tags($p['ShortDescription'] ?? ''),
            "ProductUrl" => "https://www.loebbeshop.de/search/?q=" . urlencode($p['Sku'] ?? $p['Name']),
        ];
    }
}

if (empty($results)) {
    echo json_encode([
        "message" => "Keine Produkte gefunden.",
        "query" => $q,
        "suggestion" => "Direkt im Loebbeshop suchen: https://www.loebbeshop.de/search/?q=" . urlencode($q)
    ]);
    exit;
}

echo json_encode([
    "query" => $q,
    "result_count" => count($results),
    "results" => array_slice($results, 0, $top)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
