<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * LöbbeShop Smartstore Proxy mit 2-stufigem Filter
 * Schritt 1: Suche nach Herstellernummer (num)
 * Schritt 2: Optional Filter nach Produkttyp (q)
 */

// === Auth-Daten ===
$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

// === Parameter auslesen ===
$q   = isset($_GET['q'])   ? trim($_GET['q'])   : null;   // Suchbegriff (z. B. Wartungsset)
$num = isset($_GET['num']) ? trim($_GET['num']) : null;   // Herstellernummer (z. B. 7513046)
$top = 200;

// === Basis-URL ===
$baseUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top={$top}";

// === Filter aufbauen ===
if ($num && $q) {
    // Doppelfilter: erst nach Herstellnummer, dann Produkttyp
    $filter = "contains(ShortDescription,'{$num}') or contains(MetaKeywords,'{$num}')";
    $filter .= " and (contains(Name,'{$q}') or contains(ShortDescription,'{$q}') or contains(MetaDescription,'{$q}'))";
    $smartstoreUrl = "{$baseUrl}&\$filter=" . urlencode($filter);
} elseif ($num) {
    // Nur Herstellnummer
    $filter = "contains(ShortDescription,'{$num}') or contains(MetaKeywords,'{$num}')";
    $smartstoreUrl = "{$baseUrl}&\$filter=" . urlencode($filter);
} elseif ($q) {
    // Nur allgemeine Suche
    $filter = "contains(Name,'{$q}') or contains(Manufacturer,'{$q}')";
    $smartstoreUrl = "{$baseUrl}&\$filter=" . urlencode($filter);
} else {
    // Standard – keine Filter
    $smartstoreUrl = "{$baseUrl}";
}

// === Auth vorbereiten ===
$authHeader = 'Basic ' . base64_encode("{$publicKey}:{$secretKey}");

// === Request ===
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $smartstoreUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: {$authHeader}",
        "Accept: application/json",
        "User-Agent: LoebbeShopProxy/2.0"
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 20
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// === Fehler prüfen ===
if ($curlError) {
    echo json_encode(["error" => "Proxy-Fehler: {$curlError}"]);
    exit;
}
if ($httpCode !== 200) {
    echo json_encode([
        "error" => "Smartstore-API antwortete mit HTTP {$httpCode}",
        "status" => $httpCode,
        "url" => $smartstoreUrl
    ]);
    exit;
}

// === JSON ausgeben ===
$data = json_decode($response, true);
if (!$data || !isset($data['value'])) {
    echo json_encode([
        "error" => "Smartstore-API Fehler oder keine Antwort",
        "status" => $httpCode,
        "url" => $smartstoreUrl
    ]);
    exit;
}

$results = array_map(function($item) {
    return [
        "Id" => $item['Id'] ?? null,
        "Sku" => $item['Sku'] ?? null,
        "Name" => $item['Name'] ?? null,
        "ShortDescription" => $item['ShortDescription'] ?? '',
        "Price" => $item['Price'] ?? null,
        "ProductUrl" => "https://www.loebbeshop.de/search/?q=" . urlencode($item['Sku'])
    ];
}, $data['value']);

echo json_encode([
    "query" => $q,
    "herstellnummer" => $num,
    "result_count" => count($results),
    "results" => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
