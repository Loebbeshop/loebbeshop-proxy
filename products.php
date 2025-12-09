<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Smartstore PHP-Proxy – Kompakte & formatierte Ausgabe
 * Standort: Alfahosting (Linux)
 * Ziel: Ruft Produktdaten vom Smartstore (Windows-Server) ab und formatiert sie für GPT
 */

// === Smartstore API Keys ===
$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

// === Parameter ===
$top = isset($_GET['top']) ? intval($_GET['top']) : 5;
if ($top <= 0 || $top > 50) $top = 5;
$q = isset($_GET['q']) ? urlencode($_GET['q']) : null;

// === Smartstore-Endpunkt ===
$smartstoreUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top={$top}";
if ($q) {
    $smartstoreUrl .= "&\$filter=contains(Name,'{$q}') or contains(Manufacturer,'{$q}')";
}

// === Auth vorbereiten ===
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

// === cURL-Request ===
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $smartstoreUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: $authHeader",
        "Accept: application/json",
        "User-Agent: SmartstoreProxy/1.0 (+https://api.online-shop.services)"
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 20
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// === Debug-Log schreiben ===
$debugData = [
    "timestamp" => date('Y-m-d H:i:s'),
    "smartstoreUrl" => $smartstoreUrl,
    "statusCode" => $httpCode,
    "curlError" => $curlError,
];
file_put_contents(__DIR__ . "/debug_log.json", json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// === Fehlerbehandlung ===
if ($curlError) {
    http_response_code(500);
    echo json_encode(["error" => "Proxy-Fehler: " . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode(["error" => "Smartstore-API antwortete mit HTTP $httpCode"]);
    exit;
}

// === Antwort formatieren ===
$data = json_decode($response, true);
if (!isset($data['value'])) {
    echo json_encode(["error" => "Unerwartetes API-Format"]);
    exit;
}

// === Kompakte Produktliste erstellen ===
$result = [];
foreach ($data['value'] as $p) {
    $result[] = [
        "Id" => $p['Id'],
        "Sku" => $p['Sku'],
        "Name" => $p['Name'],
        "Manufacturer" => $p['Manufacturer'] ?? "",
        "Price" => isset($p['Price']) ? round($p['Price'], 2) . " €" : "",
        "ShortDescription" => strip_tags($p['ShortDescription']),
        "ProductUrl" => $p['ProductUrl'] ?? "https://www.loebbeshop.de/product/{$p['Id']}",
        "ImageUrl" => $p['ImageUrl'] ?? null
    ];
}

// === Formatiertes JSON ausgeben ===
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
