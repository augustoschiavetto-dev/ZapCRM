<?php
require_once __DIR__ . '/config/config.php';

$instanceName = 'ZapCRM';

$data = [
    'instanceName' => $instanceName,
    'token' => '123456',
    'qrcode' => true,
    'integration' => 'WHATSAPP-BAILEYS' // Campo obrigatório na Evolution API v2.x
];

$ch = curl_init(EVOLUTION_API_URL . '/instance/create');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'apikey: ' . EVOLUTION_API_KEY
]);

$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "<b>Erro cURL:</b> " . $error;
} else {
    echo "<b>Resposta da Evolution API:</b><br>";
    echo "<pre>" . print_r(json_decode($response, true), true) . "</pre>";
}