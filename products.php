<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * LöbbeShop Smartstore Proxy (Version 2.1)
 * ----------------------------------------
 * Unterstützt:
 *  - Suchbegriff (q)
 *  - Herstellernummer (num)
 *  - Kombination (z. B. q=Wartungsset&num=7513046)
 *
 * Rückgabe:
 *  - Produktdaten als JSON
 *  - Passende Loebbeshop-Links
 *
 * @author ChatGPT
 * @date 2025-12-10
 */

// === Smartstore API Keys ===
$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

// === Eingabeparameter ===
$q   = isset($_GET['q'])   ? trim($_GET['q'])   : null;   // Suchbegriff (z. B. Wartungsset)
$num = isset($_GET['num']) ? trim($_GET['num']) : null;   // Herstellernummer (z. B. 7513046)
$top = 200;

// === Basis-URL ===
$baseUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top={$top}";

// === Filterlogik ===
if ($num && $q) {
    // Korrekte Klammerung für OData: (A OR B) AND (C OR D OR E)
    $filter = "(contains(ShortDescription,'{$num}') or contains(MetaKeywords,'{$num}'))";
    $filter .= " and (contains(Name,'{$q}') or contains(ShortDescription,'{$q}') or contains(MetaDescription,'{$q}'))";
    $smartstoreUrl = "{$baseUrl}&\$filter=" . urlencode($filter);
} elseif ($num) {
    // Nur Herstellernummer
    $filter = "contains(ShortDescription,'{$num}') or contains(MetaKeywords,'{$num}')";
    $smartstoreUrl = "{$baseUrl}&\$filter=" . urlencode($filter);
} elseif ($q) {
    // Nur Suchbegriff
    $filter = "contains(Name,'{$q}') or contains(Manufacturer,'{$q}') or contains(ShortDescription,'{$q}')";
    $smartstoreUrl = "{$baseUrl}&\$filter=" . urlencode($filter);
} else {
    // Keine Filter → Standard
    $smartstoreUrl = "{$baseUrl}";
}

// === Authentifizierung vorbereiten ===
$authHeader = 'Basic ' . base64_encode("{$publicKey}:{$secretKey}");

// === Request vorbereiten ===
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $smartstoreUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: {$authHeader}",
        "Accept: application/json",
        "User-Agent: LoebbeshopProxy/2.1"
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 25
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// === Fehlerprüfung ===
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

// === Antwort auswerten ===
$data = json_decode($response, true);
if (!$data || !isset($data['value'])) {
    echo json_encode([
        "error" => "Smartstore-API Fehler oder keine Antwort",
        "status" => $httpCode,
        "url" => $smartstoreUrl
    ]);
    exit;
}

// === Ergebnis-Transformation ===
$results = array_map(function ($item) {
    return [
        "Id" => $item['Id'] ?? null,
        "Sku" => $item['Sku'] ?? null,
        "Name" => $item['Name'] ?? '(Kein Name)',
        "ShortDescription" => $item['ShortDescription'] ?? '',
        "Price" => isset($item['Price']) ? round($item['Price'], 2) : null,
        "ProductUrl" => "https://www.loebbeshop.de/search/?q=" . urlencode($item['Sku'])
    ];
}, $data['value']);

// === Ausgabe ===
echo json_encode([
    "query" => $q,
    "herstellnummer" => $num,
    "result_count" => count($results),
    "results" => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

?>
