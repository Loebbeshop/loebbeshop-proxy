<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * LöbbeShop Smartstore Proxy (Version 2.5 – Hybrid-Suche)
 * -------------------------------------------------------
 * Lokale Filterung für kombinierte Abfragen (z. B. „Wartungsset 7513046“)
 * Liefert stabile Ergebnisse ohne Smartstore-Filterprobleme.
 */

$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

$q   = isset($_GET['q'])   ? trim($_GET['q'])   : '';
$num = isset($_GET['num']) ? trim($_GET['num']) : '';
$top = 200;

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
            "User-Agent: LoebbeshopProxy/2.5"
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return [];
    }

    $data = json_decode($response, true);
    return $data['value'] ?? [];
}

// 1️⃣ Hole einfache Produktliste (Top 200)
$url = $baseUrl;
$allProducts = callSmartstore($url, $authHeader);

// 2️⃣ Filterung lokal
$results = array_filter($allProducts, function ($item) use ($q, $num) {
    $text = strtolower(($item['Name'] ?? '') . ' ' . ($item['ShortDescription'] ?? '') . ' ' . ($item['MetaDescription'] ?? '') . ' ' . ($item['Sku'] ?? ''));
    
    // Wenn nur Herstellernummer angegeben
    if ($num && !$q) {
        return str_contains($text, strtolower($num));
    }

    // Wenn nur Suchwort angegeben
    if ($q && !$num) {
        return str_contains($text, strtolower($q));
    }

    // Wenn beides angegeben → beide Begriffe müssen vorkommen
    if ($q && $num) {
        return (str_contains($text, strtolower($q)) && str_contains($text, strtolower($num)));
    }

    return false;
});

// 3️⃣ Fallback bei keinen Treffern
if (empty($results)) {
    $fallback = "https://www.loebbeshop.de/search/?q=" . urlencode(trim("$q $num"));
    echo json_encode([
        "error" => "Keine passenden Produkte gefunden.",
        "query" => $q,
        "herstellnummer" => $num,
        "message" => "Ich konnte keine exakten Treffer finden. Du kannst direkt im Loebbeshop nachsehen:",
        "fallback" => $fallback
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 4️⃣ Ausgabe formatieren
$output = array_map(function ($item) {
    return [
        "Id" => $item['Id'] ?? null,
        "Sku" => $item['Sku'] ?? '',
        "Name" => $item['Name'] ?? '(Kein Name)',
        "ShortDescription" => $item['ShortDescription'] ?? '',
        "Price" => isset($item['Price']) ? round($item['Price'], 2) : null,
        "ProductUrl" => "https://www.loebbeshop.de/search/?q=" . urlencode($item['Sku'])
    ];
}, $results);

// 5️⃣ JSON zurückgeben
echo json_encode([
    "query" => $q,
    "herstellnummer" => $num,
    "result_count" => count($output),
    "results" => array_values($output)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
