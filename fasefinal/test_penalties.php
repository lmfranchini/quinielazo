<?php
header('Content-Type: application/json');

$url = "http://127.0.0.1:8011/openapi.json";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    echo $response;
} else {
    // Try /docs
    $urlDocs = "http://127.0.0.1:8011/docs";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $urlDocs);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $responseDocs = curl_exec($ch);
    echo json_encode([
        'error' => 'OpenAPI JSON returned ' . $httpCode,
        'docs_preview' => substr($responseDocs ?? '', 0, 500)
    ]);
}
