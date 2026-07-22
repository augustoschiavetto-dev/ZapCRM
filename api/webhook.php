<?php
// api/webhook.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/whatsapp_instance.php';

// Log webhook calls for debugging if needed
$raw_payload = file_get_contents('php://input');
$payload = json_decode($raw_payload, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload invalido']);
    exit();
}

// We only process messages.upsert from other numbers (customer messages)
if (isset($payload['event']) && $payload['event'] === 'messages.upsert') {
    $data = isset($payload['data']) ? $payload['data'] : [];
    
    // Check if the message is from me (sent by the business number itself)
    $from_me = isset($data['key']['fromMe']) ? $data['key']['fromMe'] : false;
    
    if (!$from_me) {
        $cliente_jid = isset($data['key']['remoteJid']) ? $data['key']['remoteJid'] : '';
        $cliente_nome = isset($data['pushName']) ? $data['pushName'] : 'Cliente WhatsApp';
        
        // Clean customer name to avoid SQL errors
        $cliente_nome = preg_replace('/[^\p{L}\p{N}\s_]/u', '', $cliente_nome);
        if (empty($cliente_nome)) {
            $cliente_nome = 'Cliente WhatsApp';
        }
        
        // Extract text message content
        $texto = '';
        if (isset($data['message'])) {
            $msg = $data['message'];
            if (isset($msg['conversation'])) {
                $texto = $msg['conversation'];
            } elseif (isset($msg['extendedTextMessage']['text'])) {
                $texto = $msg['extendedTextMessage']['text'];
            } elseif (isset($msg['imageMessage']['caption'])) {
                $texto = $msg['imageMessage']['caption'];
            } elseif (isset($msg['videoMessage']['caption'])) {
                $texto = $msg['videoMessage']['caption'];
            } elseif (isset($msg['documentMessage']['caption'])) {
                $texto = $msg['documentMessage']['caption'];
            } else {
                $texto = '[Mensagem de Mídia / Arquivo / Áudio]';
            }
        }
        
        if (!empty($cliente_jid)) {
            $conn = get_db_connection();
            if ($conn !== false) {
                // Check if customer already has a record in our database
                $sql_check = "SELECT status, id_atendente FROM ZAPCRM_ATENDIMENTOS WHERE cliente_jid = ?";
                $stmt_check = sqlsrv_query($conn, $sql_check, [$cliente_jid]);
                
                $chat_existe = false;
                $status_atual = '';
                $atendente_atual = '';
                
                if ($stmt_check !== false) {
                    if ($row = sqlsrv_fetch_array($stmt_check, SQLSRV_FETCH_ASSOC)) {
                        $chat_existe = true;
                        $status_atual = $row['status'];
                        $atendente_atual = $row['id_atendente'];
                    }
                    sqlsrv_free_stmt($stmt_check);
                }
                
                if (!$chat_existe) {
                    // 1. Create a new chat session in EsperandoAtendimento
                    $sql_insert = "INSERT INTO ZAPCRM_ATENDIMENTOS 
                                   (cliente_jid, cliente_nome, status, data_recebido, etiqueta, ultimo_texto) 
                                   VALUES (?, ?, 'EsperandoAtendimento', GETDATE(), 'EsperandoAtendimento', ?)";
                    sqlsrv_query($conn, $sql_insert, [$cliente_jid, $cliente_nome, $texto]);
                    
                    // Call API to set EsperandoAtendimento (Yellow) label
                    whatsapp_gerenciar_etiqueta($cliente_jid, 'EsperandoAtendimento', 'add');
                    
                } elseif ($status_atual === 'Finalizado') {
                    // 2. Customer sent a new message after previous finalization, put back to queue
                    $sql_reactivate = "UPDATE ZAPCRM_ATENDIMENTOS 
                                       SET status = 'EsperandoAtendimento', id_atendente = NULL, nome_atendente = NULL, 
                                           data_inicio = NULL, data_recebido = GETDATE(), etiqueta = 'EsperandoAtendimento', 
                                           ultimo_texto = ?, cliente_nome = ?
                                       WHERE cliente_jid = ?";
                    sqlsrv_query($conn, $sql_reactivate, [$texto, $cliente_nome, $cliente_jid]);
                    
                    // Manage labels on WhatsApp: remove old labels (if any final labels are known) and add Yellow
                    whatsapp_gerenciar_etiqueta($cliente_jid, 'FaltaResposta', 'remove');
                    whatsapp_gerenciar_etiqueta($cliente_jid, 'RupturaEstoque', 'remove');
                    whatsapp_gerenciar_etiqueta($cliente_jid, 'ProdutoEmcontradoParcial', 'remove');
                    whatsapp_gerenciar_etiqueta($cliente_jid, 'ProdutosEncontradosTotal', 'remove');
                    whatsapp_gerenciar_etiqueta($cliente_jid, 'InformaçõesGerais', 'remove');
                    whatsapp_gerenciar_etiqueta($cliente_jid, 'EmAtendimento', 'remove');
                    
                    whatsapp_gerenciar_etiqueta($cliente_jid, 'EsperandoAtendimento', 'add');
                    
                } else {
                    // 3. Chat is already open (EsperandoAtendimento or EmAtendimento). Just update metadata.
                    $sql_update = "UPDATE ZAPCRM_ATENDIMENTOS 
                                   SET ultimo_texto = ?, data_recebido = GETDATE(), cliente_nome = ? 
                                   WHERE cliente_jid = ?";
                    sqlsrv_query($conn, $sql_update, [$texto, $cliente_nome, $cliente_jid]);
                }
                
                sqlsrv_close($conn);
            }
        }
    }
}

// Return 200 OK to the webhook client
http_response_code(200);
echo json_encode(['status' => 'success']);
?>
