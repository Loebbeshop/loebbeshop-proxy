<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * LöbbeShop Smartstore Proxy (Version 2.3 - stabil)
 * -------------------------------------------------
 * Unterstützt:
 *  - ?q=Wartungsset
 *  - ?num=7513046
 *  - ?q=Wartungsset&num=7513046
 * 
 * Vermeidet Smartstore-OData-Fehler (400) durch getrennte Abfragen
 */

$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

$q   = isset($_GET['q'])   ? trim($_GET['q'])   : null;
$num = isset($_GET['num']) ? trim($_GET['num']) : null;
$top = 100;

$authHeader = 'Basic ' . base64_encode("{$publicKey}:{$secretKey}");
$baseUrl = "https://www.loebbeshop.de/odata/v1/Products?\$top={$top}";

function callSmartstore($url, $authHeader) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$authHeader}",
            "Accept: application/json",
            "User-Agent: LoebbeshopProxy/2.3"
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ["error" => "cURL: $error"];
    }
    if ($httpCode !== 200) {
        return ["error" => "Smartstore HTTP $httpCode"];
    }

    $data = json_decode($response, true);
    return $data['value'] ?? [];
}

$results = [];

// 1️⃣ Nur q → einfache Suche
if ($q && !$num) {
    $url = $baseUrl . "&\$filter=" . urlencode("contains(Name,'{$q}')");
    $results = callSmartstore($url, $authHeader);

// 2️⃣ Nur num → Suche nach Herstellernummer
} elseif ($num && !$q) {
    $url = $baseUrl . "&\$filter=" . urlencode("contains(ShortDescription,'{$num}') or contains(Name,'{$num}') or contains(Sku,'{$num}')");
    $results = callSmartstore($url, $authHeader);

// 3️⃣ Kombi → getrennte Suchen + Filterung in PHP
} elseif ($q && $num) {
    $url1 = $baseUrl . "&\$filter=" . urlencode("contains(Name,'{$num}') or contains(ShortDescription,'{$num}') or contains(Sku,'{$num}')");
    $url2 = $baseUrl . "&\$filter=" . urlencode("contains(Name,'{$q}') or contains(ShortDescription,'{$q}')");
    $dataNum = callSmartstore($url1, $authHeader);
    $dataQ = callSmartstore($url2, $authHeader);

    // PHP-Filterung nach beiden Kriterien
    $results = array_filter($dataNum, function($item) use ($q) {
        return stripos($item['Name'] ?? '', $q) !== false ||
               stripos($item['ShortDescription'] ?? '', $q) !== false;
    });
}

if (empty($results)) {
    echo json_encode([
        "error" => "Keine Ergebnisse gefunden oder Smartstore-Fehler.",
        "query" => $q,
        "herstellnummer" => $num,
        "fallback" => "https://www.loebbeshop.de/search/?q=" . urlencode(trim("$q $num"))
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Ergebnis-Transformation
$output = array_map(function ($item) {
    return [
        "Id" => $item['Id'] ?? null,
        "Sku" => $item['Sku'] ?? null,
        "Name" => $item['Name'] ?? '(Kein Name)',
        "ShortDescription" => $item['ShortDescription'] ?? '',
        "Price" => isset($item['Price']) ? round($item['Price'], 2) : null,
        "ProductUrl" => "https://www.loebbeshop.de/search/?q=" . urlencode($item['Sku'])
    ];
}, $results);

// Ausgabe
echo json_encode([
    "query" => $q,
    "herstellnummer" => $num,
    "result_count" => count($output),
    "results" => $output
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
