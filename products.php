<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Smartstore Proxy (stabile Version)
 * - Führt lokale Produktsuche durch
 * - Erkennt numerische SKU-Suchen und gibt Weiterleitungslink im Shop-Format zurück
 */

$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

// === Parameter ===
$q = isset($_GET['q']) ? trim($_GET['q']) : null;
$top = isset($_GET['top']) ? intval($_GET['top']) : 5;
if ($top <= 0 || $top > 50) $top = 5;

if (!$q) {
    http_response_code(400);
    echo json_encode(["error" => "Fehlender Suchbegriff"]);
    exit;
}

// === Wenn der Suchbegriff wie eine SKU aussieht (nur Zahlen, max. 10 Zeichen)
if (preg_match('/^[0-9]{4,10}$/', $q)) {
    echo json_encode([
        "info" => "Direkte SKU-Suche erkannt",
        "redirect" => "https://www.loebbeshop.de/search/?q=" . urlencode($q),
        "message" => "Die Suche wurde direkt an den LöbbeShop weitergeleitet."
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// === Auth vorbereiten ===
$credentials = trim($publicKey) . ':' . trim($secretKey);
$authHeader = 'Basic ' . base64_encode($credentials);

// === Smartstore-Endpunkt (ohne $filter)
$smartstoreUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top=200";

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

if ($curlError) {
    http_response_code(500);
    echo json_encode(["error" => "Proxy-Fehler: " . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode([
        "error" => "Smartstore-API Fehler oder keine Antwort",
        "status" => $httpCode,
        "url" => $smartstoreUrl
    ]);
    exit;
}

// === Lokale Filterung ===
$data = json_decode($response, true);
if (!isset($data['value']) || !is_array($data['value'])) {
    echo json_encode(["error" => "Keine gültigen Produktdaten erhalten"]);
    exit;
}

$qLower = mb_strtolower($q, 'UTF-8');
$results = [];

foreach ($data['value'] as $item) {
    $name = mb_strtolower($item['Name'] ?? '', 'UTF-8');
    $desc = mb_strtolower($item['ShortDescription'] ?? '', 'UTF-8');
    $sku = mb_strtolower($item['Sku'] ?? '', 'UTF-8');

    if (
        str_contains($name, $qLower) ||
        str_contains($desc, $qLower) ||
        str_contains($sku, $qLower)
    ) {
        $results[] = [
            "Id" => $item["Id"] ?? null,
            "Sku" => $item["Sku"] ?? null,
            "Name" => $item["Name"] ?? "",
            "Manufacturer" => $item["Manufacturer"] ?? "",
            "Price" => isset($item["Price"]) ? round($item["Price"], 2) . " €" : "",
            "ShortDescription" => $item["ShortDescription"] ?? "",
            "ProductUrl" => "https://www.loebbeshop.de/product/" . ($item["Id"] ?? ""),
            "ImageUrl" => $item["ImageUrl"] ?? null
        ];
        if (count($results) >= $top) break;
    }
}

// === Ausgabe ===
if (count($results) > 0) {
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        "message" => "Keine Produkte gefunden.",
        "query" => $q,
        "suggestion" => "Direkt im Shop suchen: https://www.loebbeshop.de/search/?q=" . urlencode($q)
    ]);
}
