<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Smartstore Dialog Proxy (ohne Produkt-API)
 * - Erkennt numerische SKUs und erstellt direkten Weiterleitungslink
 * - Erkennt allgemeine Suchbegriffe und liefert Dialog-Antworten
 * - Keine API-Aufrufe mehr → komplett unabhängig vom Smartstore-System
 */

$q = isset($_GET['q']) ? trim($_GET['q']) : null;

if (!$q) {
    http_response_code(400);
    echo json_encode([
        "error" => "Fehlender Suchbegriff",
        "hint" => "Bitte gib an, wonach du suchst (z. B. Wartungsset, Zündelektrode, Umwälzpumpe oder eine Artikelnummer)."
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// === Prüfen, ob eine SKU (nur Zahlen, 4–10 Zeichen)
if (preg_match('/^[0-9]{4,10}$/', $q)) {
    echo json_encode([
        "info" => "Direkte SKU-Suche erkannt",
        "redirect" => "https://www.loebbeshop.de/search/?q=" . urlencode($q),
        "message" => "Die Suche wurde direkt an den LöbbeShop weitergeleitet."
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// === Für Textsuchen – Dialogvorschläge
$qLower = mb_strtolower($q, 'UTF-8');

$dialog = "Ich helfe dir gern beim Finden des passenden Ersatzteils.\n";
$dialog .= "Für welche Heizung oder welches Modell suchst du ein {$q}?";

$suggestions = [];

if (str_contains($qLower, 'wartungs')) {
    $suggestions = [
        "Viessmann Vitodens 200",
        "Buderus Logamax plus GB172",
        "Wolf CGB-2",
        "Vaillant ecoTEC plus"
    ];
    $dialog = "Für welche Heizung wird das Wartungsset benötigt? Zum Beispiel:";
}

if (str_contains($qLower, 'zünd') || str_contains($qLower, 'elektrode')) {
    $suggestions = [
        "Viessmann Vitola",
        "Buderus G125",
        "Weishaupt WL5/1-A",
        "Wolf CGB-K"
    ];
    $dialog = "Für welchen Brenner oder Heizkessel wird die Zündelektrode gesucht?";
}

if (str_contains($qLower, 'pumpe') || str_contains($qLower, 'umwälz')) {
    $suggestions = [
        "Wilo Star RS25/6",
        "Grundfos Alpha2 25-40",
        "Buderus GB162",
        "Vaillant VC 206"
    ];
    $dialog = "Für welche Heizung soll die Pumpe passen?";
}

// === Antwort erzeugen
$response = [
    "query" => $q,
    "type" => (count($suggestions) > 0) ? "dialog" : "generic",
    "message" => $dialog,
    "suggestions" => $suggestions,
    "shop_link" => "https://www.loebbeshop.de/search/?q=" . urlencode($q)
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
