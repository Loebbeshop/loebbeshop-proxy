<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Smartstore PHP-Proxy für den Loebbeshop
 * Unterstützt: Produktsuche & direkte SKU-Suche mit Produktinfos
 */

// === Smartstore API Keys ===
$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

// === Parameter ===
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$top = isset($_GET['top']) ? intval($_GET['top']) : 5;
if ($top <= 0 || $top > 200) $top = 5;

// === SKU-Erkennung (z. B. 7815884, 7830016 etc.) ===
if (preg_match('/^\d{6,8}$/', $q)) {
    $redirectUrl = "https://www.loebbeshop.de/search/?q=" . urlencode($q);

    // Versuch, Produktdetails direkt über SKU aus der API zu holen
    $smartstoreUrl = "https://www.loebbeshop.de/odata/v1/Products?\$filter=Sku eq '$q'";

    $credentials = trim($publicKey) . ':' . trim($secretKey);
    $authHeader = 'Basic ' . base64_encode($credentials);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $smartstoreUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: $authHeader",
            "Accept: application/json"
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        echo json_encode([
            "info" => "Direkte SKU-Suche erkannt",
            "redirect" => $redirectUrl,
            "message" => "Die Suche wurde direkt an den Loebbeshop weitergeleitet."
        ]);
        exit;
    }

    $data = json_decode($response, true);
    if (isset($data['value'][0])) {
        $p = $data['value'][0];
        echo json_encode([
            "query" => $q,
            "result" => [
                "Name" => $p["Name"] ?? null,
                "ShortDescription" => strip_tags($p["ShortDescription"] ?? ''),
                "Sku" => $p["Sku"] ?? $q,
                "Price" => isset($p["Price"]) ? round($p["Price"], 2) : null,
                "Id" => $p["Id"] ?? null
            ],
            "redirect" => $redirectUrl
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        "info" => "Direkte SKU-Suche erkannt",
        "redirect" => $redirectUrl,
        "message" => "Die Suche wurde direkt an den Loebbeshop weitergeleitet."
    ]);
    exit;
}

// === Allgemeine Produktsuche ===
$smartstoreUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top={$top}&\$filter=contains(Name,'$q') or contains(Sku,'$q')";

$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $smartstoreUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: $authHeader",
        "Accept: application/json"
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 20
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode !== 200) {
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
    $results[] = [
        "Id" => $p["Id"] ?? null,
        "Sku" => $p["Sku"] ?? null,
        "Name" => $p["Name"] ?? null,
        "Price" => isset($p["Price"]) ? round($p["Price"], 2) . " €" : null,
        "ShortDescription" => strip_tags($p["ShortDescription"] ?? ''),
        "ProductUrl" => "https://www.loebbeshop.de/search/?q=" . urlencode($p["Sku"] ?? $p["Name"]),
    ];
}

echo json_encode([
    "query" => $q,
    "result_count" => count($results),
    "results" => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
