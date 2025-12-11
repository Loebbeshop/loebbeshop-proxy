<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * LöbbeShop Smartstore Proxy (Version 2.4 - präzise Filterung)
 * -------------------------------------------------------------
 * - Unterstützt kombinierte Suche nach Herstellernummer + Suchwort
 * - Filtert Ergebnisse serverseitig (keine Smartstore-Fehler mehr)
 */

$publicKey = '0884bd1c9bdb7e2f17a3e1429b1c5021';
$secretKey = '33b5b3892603471204755cd4f015bc97';

$q   = isset($_GET['q'])   ? trim($_GET['q'])   : null;
$num = isset($_GET['num']) ? trim($_GET['num']) : null;
$top = 150;

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
            "User-Agent: LoebbeshopProxy/2.4"
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ["error" => "Proxy-Fehler: $error"];
    }
    if ($httpCode !== 200) {
        return ["error" => "Smartstore HTTP $httpCode"];
    }

    $data = json_decode($response, true);
    return $data['value'] ?? [];
}

$results = [];

// 1️⃣ Nur q (z. B. Wartungsset)
if ($q && !$num) {
    $url = $baseUrl . "&\$filter=" . urlencode("contains(Name,'{$q}') or contains(ShortDescription,'{$q}')");
    $results = callSmartstore($url, $authHeader);

// 2️⃣ Nur num (z. B. 7513046)
} elseif ($num && !$q) {
    $url = $baseUrl . "&\$filter=" . urlencode("contains(Name,'{$num}') or contains(ShortDescription,'{$num}') or contains(Sku,'{$num}')");
    $results = callSmartstore($url, $authHeader);

// 3️⃣ Kombiniert (z. B. Wartungsset + 7513046)
} elseif ($q && $num) {
    // Erst alle Produkte zum Gerät holen
    $urlNum = $baseUrl . "&\$filter=" . urlencode("contains(Name,'{$num}') or contains(ShortDescription,'{$num}') or contains(Sku,'{$num}')");
    $dataNum = callSmartstore($urlNum, $authHeader);

    // Dann lokal nach „Wartungsset“ filtern
    $results = array_filter($dataNum, function($item) use ($q) {
        $fields = ($item['Name'] ?? '') . ' ' . ($item['ShortDescription'] ?? '');
        return stripos($fields, $q) !== false;
    });
}

// 🔁 Fallback bei leeren Treffern
if (empty($results)) {
    echo json_encode([
        "error" => "Keine passenden Produkte gefunden.",
        "query" => $q,
        "herstellnummer" => $num,
        "message" => "Ich konnte keine exakten Treffer finden. Du kannst direkt im Loebbeshop nachsehen:",
        "fallback" => "https://www.loebbeshop.de/search/?q=" . urlencode($num)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Ergebnisse vereinheitlichen
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

// JSON ausgeben
echo json_encode([
    "query" => $q,
    "herstellnummer" => $num,
    "result_count" => count($output),
    "results" => array_values($output)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
