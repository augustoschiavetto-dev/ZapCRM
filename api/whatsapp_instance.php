<?php
// api/whatsapp_instance.php
require_once __DIR__ . '/../config/config.php';

/**
 * Creates the WhatsApp instance dynamically on Evolution API
 * Returns boolean success
 */
function whatsapp_criar_instancia() {
    $endpoint = '/instance/create';
    $data = [
        'instanceName' => EVOLUTION_INSTANCE_NAME,
        'integration' => 'WHATSAPP-BAILEYS',
        'qrcode' => true
    ];
    $res = evolution_api_request($endpoint, 'POST', $data);
    if ($res['code'] === 200 || $res['code'] === 201) {
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
    
    // If instance is not found (404), create it dynamically
    if ($res['code'] === 404 || (isset($res['body']['status']) && $res['body']['status'] === 404)) {
        whatsapp_criar_instancia();
        return 'close';
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
        if (isset($res['body']['base64'])) {
            return [
                'success' => true,
                'qrcode' => $res['body']['base64'] // Base64 image string
            ];
        } elseif (isset($res['body']['code'])) {
            return [
                'success' => true,
                'code' => $res['body']['code']
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
 * Handles cleaning the phone number and finding the label ID by name dynamically
 */
function whatsapp_gerenciar_etiqueta($telefone, $nome_etiqueta, $acao = 'add') {
    // Clean phone number: remove @s.whatsapp.net, spaces, +, -
    $numero_limpo = preg_replace('/[^0-9]/', '', $telefone);
    
    // Fetch labels to find the ID corresponding to the label name
    $labels = whatsapp_obter_etiquetas();
    $label_id = null;
    
    foreach ($labels as $label) {
        // Match label name case-insensitively
        if (isset($label['name']) && strcasecmp($label['name'], $nome_etiqueta) === 0) {
            $label_id = $label['id'];
            break;
        }
    }
    
    // If label doesn't exist, we will log it. In WhatsApp Business, labels should be pre-created.
    // If not found, we cannot perform the action.
    if ($label_id === null) {
        error_log("Aviso: Etiqueta '$nome_etiqueta' nao encontrada no WhatsApp.");
        return false;
    }
    
    $endpoint = '/label/handleLabel/' . EVOLUTION_INSTANCE_NAME;
    $data = [
        'number' => $numero_limpo,
        'labelId' => $label_id,
        'action' => $acao // 'add' or 'remove'
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
