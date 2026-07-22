<?php
// api/whatsapp_instance.php
require_once __DIR__ . '/../config/config.php';

/**
 * Configures the Webhook for this instance on Evolution API v2.x
 * Returns boolean success
 */
function whatsapp_configurar_webhook($webhookUrl) {
    $endpoint = '/webhook/set/' . EVOLUTION_INSTANCE_NAME;
    $data = [
        'webhook' => [
            'enabled' => true,
            'url'     => $webhookUrl,
            'events'  => [
                'MESSAGES_UPSERT',
                'MESSAGES_UPDATE',
                'CONNECTION_UPDATE',
                'QRCODE_UPDATED'
            ]
        ]
    ];
    $res = evolution_api_request($endpoint, 'POST', $data);
    if ($res['code'] === 200 || $res['code'] === 201) {
        return true;
    }
    return false;
}

/**
 * Obtém a URL do Webhook a ser registrada na Evolution API
 * Prioriza a constante WEBHOOK_OVERRIDE_URL caso esteja definida no config
 */
function whatsapp_obter_webhook_url() {
    if (defined('WEBHOOK_OVERRIDE_URL') && !empty(WEBHOOK_OVERRIDE_URL)) {
        return WEBHOOK_OVERRIDE_URL;
    }
    
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    $project_path = str_replace('/api/whatsapp_instance.php', '', $script_name);
    return $protocol . "://" . $host . $project_path . '/api/webhook.php';
}

/**
 * Creates the WhatsApp instance dynamically on Evolution API v2.x
 * Returns boolean success
 */
function whatsapp_criar_instancia() {
    $endpoint = '/instance/create';
    $data = [
        'instanceName' => EVOLUTION_INSTANCE_NAME,
        'integration'  => 'WHATSAPP-BAILEYS',
        'qrcode'       => true
    ];
    
    $res = evolution_api_request($endpoint, 'POST', $data);
    
    if ($res['code'] === 200 || $res['code'] === 201) {
        // Automatically register webhook right after creation
        $webhookUrl = whatsapp_obter_webhook_url();
        whatsapp_configurar_webhook($webhookUrl);
        return true;
    }
    return false;
}

/**
 * Checks WhatsApp instance connection status
 * Returns: 'open' (connected), 'close' (disconnected), 'connecting', or 'error'
 */
function whatsapp_obter_status() {
    $endpoint = '/instance/connectionState/' . EVOLUTION_INSTANCE_NAME;
    $res = evolution_api_request($endpoint, 'GET');
    
    if ($res['code'] === 200 && isset($res['body']['instance']['state'])) {
        return $res['body']['instance']['state'];
    }
    
    // If instance is not found (404), create it dynamically and inform status as connecting
    if ($res['code'] === 404 || (isset($res['body']['status']) && $res['body']['status'] === 404)) {
        whatsapp_criar_instancia();
        return 'connecting';
    }
    
    return 'error';
}

/**
 * Requests the QR Code from the Evolution API for scanning
 * Returns array containing status and image base64 if disconnected, or message
 */
function whatsapp_gerar_qrcode() {
    $endpoint = '/instance/connect/' . EVOLUTION_INSTANCE_NAME;
    $res = evolution_api_request($endpoint, 'GET');
    
    // If instance is not found (404), create it dynamically and retry connect
    if ($res['code'] === 404 || (isset($res['body']['status']) && $res['body']['status'] === 404)) {
        whatsapp_criar_instancia();
        // Retry connection request
        $res = evolution_api_request($endpoint, 'GET');
    }
    
    if ($res['code'] === 200 || $res['code'] === 201) {
        $body = $res['body'];
        
        // Automatically configure webhook to ensure it is pointing to the current server
        $webhookUrl = whatsapp_obter_webhook_url();
        whatsapp_configurar_webhook($webhookUrl);
        
        // Trata a estrutura do QR Code Base64 direta ou aninhada na v2.x
        $base64 = null;
        if (isset($body['base64'])) {
            $base64 = $body['base64'];
        } elseif (isset($body['qrcode']['base64'])) {
            $base64 = $body['qrcode']['base64'];
        }
        
        if ($base64 !== null) {
            return [
                'success' => true,
                'qrcode'  => $base64
            ];
        }
        
        // Caso a API retorne apenas o código alfanumérico ou estagnação de pairing
        if (isset($body['code']) || isset($body['qrcode']['code'])) {
            return [
                'success' => true,
                'code'    => $body['code'] ?? $body['qrcode']['code']
            ];
        }
    }
    
    // Format error description cleanly
    $err_desc = is_array($res['body']) ? json_encode($res['body']) : trim((string)$res['body']);
    if (empty($err_desc)) {
        $err_desc = "Sem resposta do servidor (Código HTTP: " . $res['code'] . ")";
    }
    
    return [
        'success' => false,
        'message' => 'Não foi possível gerar o QR Code. Resposta da API: ' . $err_desc
    ];
}

/**
 * Disconnects (logout) the WhatsApp instance
 * Returns boolean
 */
function whatsapp_desconectar() {
    $endpoint = '/instance/logout/' . EVOLUTION_INSTANCE_NAME;
    $res = evolution_api_request($endpoint, 'DELETE');
    
    if ($res['code'] === 200 || $res['code'] === 201) {
        return true;
    }
    return false;
}

/**
 * Retrieves the list of tags/labels from the instance
 * Returns array of labels
 */
function whatsapp_obter_etiquetas() {
    $endpoint = '/label/findLabels/' . EVOLUTION_INSTANCE_NAME;
    $res = evolution_api_request($endpoint, 'GET');
    
    if ($res['code'] === 200 && is_array($res['body'])) {
        return $res['body'];
    }
    return [];
}

/**
 * Adds or removes a label for a specific customer phone number
 */
function whatsapp_gerenciar_etiqueta($telefone, $nome_etiqueta, $acao = 'add') {
    $numero_limpo = preg_replace('/[^0-9]/', '', $telefone);
    
    $labels = whatsapp_obter_etiquetas();
    $label_id = null;
    
    foreach ($labels as $label) {
        if (isset($label['name']) && strcasecmp($label['name'], $nome_etiqueta) === 0) {
            $label_id = $label['id'];
            break;
        }
    }
    
    if ($label_id === null) {
        error_log("Aviso: Etiqueta '$nome_etiqueta' nao encontrada no WhatsApp.");
        return false;
    }
    
    $endpoint = '/label/handleLabel/' . EVOLUTION_INSTANCE_NAME;
    $data = [
        'number'  => $numero_limpo,
        'labelId' => $label_id,
        'action'  => $acao
    ];
    
    $res = evolution_api_request($endpoint, 'POST', $data);
    
    if ($res['code'] === 200 || $res['code'] === 201) {
        return true;
    }
    
    error_log("Erro ao gerenciar etiqueta ($nome_etiqueta / $acao) para o numero $numero_limpo: " . json_encode($res['body']));
    return false;
}

// Controller routing for AJAX requests
if (isset($_GET['ajax_action'])) {
    if (!isset($_SESSION['usuario_logado'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autorizado']);
        exit();
    }
    
    header('Content-Type: application/json');
    $ajax_action = $_GET['ajax_action'];
    
    switch ($ajax_action) {
        case 'status':
            $status = whatsapp_obter_status();
            echo json_encode(['status' => $status]);
            break;
            
        case 'qrcode':
            $qrcode_data = whatsapp_gerar_qrcode();
            echo json_encode($qrcode_data);
            break;
            
        case 'logout':
            $success = whatsapp_desconectar();
            echo json_encode(['success' => $success]);
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Ação AJAX inválida']);
            break;
    }
    exit();
}
?>