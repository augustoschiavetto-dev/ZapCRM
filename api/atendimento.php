<?php
// api/atendimento.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/whatsapp_instance.php';
require_once __DIR__ . '/sicnet.php';

// Check if user is logged in
if (!isset($_SESSION['usuario_logado'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit();
}

$atendente_atual = $_SESSION['usuario_logado'];

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'list_chats':
        // Retrieve chats from our database
        $conn = get_db_connection();
        if ($conn === false) {
            echo json_encode(['error' => 'Erro ao conectar ao banco de dados']);
            exit();
        }
        
        $fila = [];
        $em_atendimento = [];
        
        // 1. Fetch queue (EsperandoAtendimento) - only chats with NO assigned attendant
        $sql_fila = "SELECT cliente_jid, cliente_nome, data_recebido, ultimo_texto 
                     FROM ZAPCRM_ATENDIMENTOS 
                     WHERE status = 'EsperandoAtendimento' AND (id_atendente IS NULL OR id_atendente = '')
                     ORDER BY data_recebido DESC";
        $stmt_fila = sqlsrv_query($conn, $sql_fila);
        if ($stmt_fila !== false) {
            while ($row = sqlsrv_fetch_array($stmt_fila, SQLSRV_FETCH_ASSOC)) {
                $fila[] = $row;
            }
            sqlsrv_free_stmt($stmt_fila);
        }
        
        // 2. Fetch active chats for the CURRENT attendant
        $sql_ativos = "SELECT cliente_jid, cliente_nome, data_recebido, data_inicio, ultimo_texto 
                       FROM ZAPCRM_ATENDIMENTOS 
                       WHERE status = 'EmAtendimento' AND id_atendente = ?
                       ORDER BY data_recebido DESC";
        $stmt_ativos = sqlsrv_query($conn, $sql_ativos, [$atendente_atual]);
        if ($stmt_ativos !== false) {
            while ($row = sqlsrv_fetch_array($stmt_ativos, SQLSRV_FETCH_ASSOC)) {
                $em_atendimento[] = $row;
            }
            sqlsrv_free_stmt($stmt_ativos);
        }
        
        sqlsrv_close($conn);
        
        echo json_encode([
            'fila' => $fila,
            'em_atendimento' => $em_atendimento
        ]);
        break;
        
    case 'get_messages':
        $jid = isset($_GET['jid']) ? $_GET['jid'] : '';
        if (empty($jid)) {
            echo json_encode(['error' => 'JID do cliente é obrigatório']);
            exit();
        }
        
        // Format JID to phone number or JID depending on input
        $number = preg_replace('/[^0-9]/', '', $jid);
        
        // Fetch message history from Evolution API
        $endpoint = '/chat/findMessages/' . EVOLUTION_INSTANCE_NAME;
        $data = [
            'where' => [
                'key' => [
                    'remoteJid' => $jid
                ]
            ],
            'page' => 1,
            'offset' => 50 // Load last 50 messages
        ];
        
        $res = evolution_api_request($endpoint, 'POST', $data);
        
        if ($res['code'] === 200 && isset($res['body']['messages'])) {
            // Sort messages chronologically (Evolution API usually returns newest first)
            $messages = $res['body']['messages'];
            usort($messages, function($a, $b) {
                $timeA = isset($a['messageTimestamp']) ? $a['messageTimestamp'] : 0;
                $timeB = isset($b['messageTimestamp']) ? $b['messageTimestamp'] : 0;
                return $timeA <=> $timeB;
            });
            echo json_encode(['messages' => $messages]);
        } else {
            echo json_encode(['messages' => [], 'debug' => $res]);
        }
        break;
        
    case 'send_message':
        $jid = isset($_POST['jid']) ? $_POST['jid'] : '';
        $texto = isset($_POST['mensagem']) ? $_POST['mensagem'] : '';
        $codigo_produto = isset($_POST['codigo_produto']) ? $_POST['codigo_produto'] : '';
        $enviar_foto = isset($_POST['enviar_foto']) ? $_POST['enviar_foto'] : 'nao';
        
        if (empty($jid)) {
            echo json_encode(['error' => 'JID do cliente é obrigatório']);
            exit();
        }
        
        $conn = get_db_connection();
        if ($conn === false) {
            echo json_encode(['error' => 'Erro de conexão com o banco']);
            exit();
        }
        
        // Clean phone number for WhatsApp sending
        $number = preg_replace('/[^0-9]/', '', $jid);
        
        // Check current status in DB
        $sql_check = "SELECT id_atendente, status FROM ZAPCRM_ATENDIMENTOS WHERE cliente_jid = ?";
        $stmt_check = sqlsrv_query($conn, $sql_check, [$jid]);
        $current_chat = null;
        if ($stmt_check !== false) {
            $current_chat = sqlsrv_fetch_array($stmt_check, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt_check);
        }
        
        // Check if chat is already claimed by someone else
        if ($current_chat && !empty($current_chat['id_atendente']) && $current_chat['id_atendente'] !== $atendente_atual) {
            echo json_encode([
                'success' => false,
                'error' => 'Este cliente já está em atendimento por outro funcionário: ' . $current_chat['id_atendente']
            ]);
            sqlsrv_close($conn);
            exit();
        }
        
        // Claim the chat transactionally
        $claimed_now = false;
        if (!$current_chat || empty($current_chat['id_atendente']) || $current_chat['status'] === 'EsperandoAtendimento') {
            // First time replying - claim it!
            $sql_claim = "UPDATE ZAPCRM_ATENDIMENTOS 
                          SET id_atendente = ?, nome_atendente = ?, status = 'EmAtendimento', data_inicio = GETDATE()
                          WHERE cliente_jid = ? AND (id_atendente IS NULL OR id_atendente = '' OR status = 'EsperandoAtendimento')";
            $stmt_claim = sqlsrv_query($conn, $sql_claim, [$atendente_atual, $atendente_atual, $jid]);
            
            if ($stmt_claim !== false) {
                $rows_affected = sqlsrv_rows_affected($stmt_claim);
                if ($rows_affected > 0) {
                    $claimed_now = true;
                } else {
                    // Claim failed due to concurrency race condition
                    echo json_encode([
                        'success' => false,
                        'error' => 'Concorrência: Outro atendente acabou de assumir essa conversa.'
                    ]);
                    sqlsrv_close($conn);
                    exit();
                }
                sqlsrv_free_stmt($stmt_claim);
            }
        }
        
        // Prepare the message text prepending the agent name in bold
        // In WhatsApp, *Text* makes it bold
        $bold_agent = "*" . $atendente_atual . "*\n\n";
        
        $response_api = null;
        
        // Check if we are sending a product
        if (!empty($codigo_produto)) {
            // Get product from DB
            $sql_prod = "SELECT produto, precovenda FROM TABEST1 WHERE codigo = ?";
            $stmt_prod = sqlsrv_query($conn, $sql_prod, [$codigo_produto]);
            $prod_info = null;
            if ($stmt_prod !== false) {
                $prod_info = sqlsrv_fetch_array($stmt_prod, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($stmt_prod);
            }
            
            if ($prod_info) {
                $desc_prod = $prod_info['produto'];
                $preco_prod = "R$ " . number_format($prod_info['precovenda'], 2, ',', '.');
                $texto_produto = "Produto: " . $desc_prod . "\nPreço: " . $preco_prod;
                
                // Final text combines agent name + product details + any custom message
                $caption = $bold_agent . $texto_produto . (!empty($texto) ? "\n\n" . $texto : "");
                
                $base64_foto = null;
                if ($enviar_foto === 'sim') {
                    $base64_foto = sicnet_obter_foto_base64($codigo_produto);
                }
                
                if ($base64_foto !== null) {
                    // Send as media (image)
                    $endpoint = '/message/sendMedia/' . EVOLUTION_INSTANCE_NAME;
                    $data_media = [
                        'number' => $number,
                        'mediatype' => 'image',
                        'mimetype' => 'image/jpeg',
                        'media' => $base64_foto,
                        'caption' => $caption
                    ];
                    $response_api = evolution_api_request($endpoint, 'POST', $data_media);
                } else {
                    // Fallback to text message if photo doesn't exist or not requested
                    $endpoint = '/message/sendText/' . EVOLUTION_INSTANCE_NAME;
                    $data_text = [
                        'number' => $number,
                        'text' => $caption
                    ];
                    $response_api = evolution_api_request($endpoint, 'POST', $data_text);
                }
            }
        } else {
            // Regular text message
            $texto_final = $bold_agent . $texto;
            $endpoint = '/message/sendText/' . EVOLUTION_INSTANCE_NAME;
            $data_text = [
                'number' => $number,
                'text' => $texto_final
            ];
            $response_api = evolution_api_request($endpoint, 'POST', $data_text);
        }
        
        // Update labels on Evolution API if claimed just now
        if ($claimed_now) {
            whatsapp_gerenciar_etiqueta($jid, 'EsperandoAtendimento', 'remove');
            whatsapp_gerenciar_etiqueta($jid, 'EmAtendimento', 'add');
            
            // Update local database label
            $sql_update_label = "UPDATE ZAPCRM_ATENDIMENTOS SET etiqueta = 'EmAtendimento' WHERE cliente_jid = ?";
            sqlsrv_query($conn, $sql_update_label, [$jid]);
        }
        
        // Update the last message text and receive date in our local session table
        $preview_texto = !empty($texto) ? $texto : (isset($texto_produto) ? $texto_produto : "");
        $sql_meta = "UPDATE ZAPCRM_ATENDIMENTOS SET ultimo_texto = ?, data_recebido = GETDATE() WHERE cliente_jid = ?";
        sqlsrv_query($conn, $sql_meta, [$preview_texto, $jid]);
        
        sqlsrv_close($conn);
        
        echo json_encode([
            'success' => true,
            'api_response' => $response_api
        ]);
        break;
        
    case 'finalizar_atendimento':
        $jid = isset($_POST['jid']) ? $_POST['jid'] : '';
        $etiqueta_final = isset($_POST['etiqueta_final']) ? $_POST['etiqueta_final'] : '';
        
        if (empty($jid) || empty($etiqueta_final)) {
            echo json_encode(['error' => 'JID e Etiqueta Final são obrigatórios']);
            exit();
        }
        
        $conn = get_db_connection();
        if ($conn === false) {
            echo json_encode(['error' => 'Erro ao conectar ao banco de dados']);
            exit();
        }
        
        // Fetch current chat details for logging
        $sql_chat = "SELECT cliente_nome, id_atendente, nome_atendente, data_inicio, data_recebido FROM ZAPCRM_ATENDIMENTOS WHERE cliente_jid = ?";
        $stmt_chat = sqlsrv_query($conn, $sql_chat, [$jid]);
        $chat = null;
        if ($stmt_chat !== false) {
            $chat = sqlsrv_fetch_array($stmt_chat, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt_chat);
        }
        
        if (!$chat) {
            echo json_encode(['error' => 'Atendimento não encontrado']);
            sqlsrv_close($conn);
            exit();
        }
        
        if ($chat['id_atendente'] !== $atendente_atual) {
            echo json_encode(['error' => 'Você não é o responsável por este atendimento']);
            sqlsrv_close($conn);
            exit();
        }
        
        // Perform local db status update to 'Finalizado' and reset attendant
        $sql_finish = "UPDATE ZAPCRM_ATENDIMENTOS 
                       SET status = 'Finalizado', id_atendente = NULL, nome_atendente = NULL, etiqueta = ?
                       WHERE cliente_jid = ?";
        $stmt_finish = sqlsrv_query($conn, $sql_finish, [$etiqueta_final, $jid]);
        
        if ($stmt_finish === false) {
            echo json_encode(['error' => 'Erro ao atualizar atendimento no banco: ' . print_r(sqlsrv_errors(), true)]);
            sqlsrv_close($conn);
            exit();
        }
        
        // Call Evolution API to update labels
        whatsapp_gerenciar_etiqueta($jid, 'EmAtendimento', 'remove');
        whatsapp_gerenciar_etiqueta($jid, $etiqueta_final, 'add');
        
        // Calculate timing variables
        $data_inicio_obj = $chat['data_inicio'];
        $data_recebido_obj = $chat['data_recebido'];
        $data_fim_obj = new DateTime();
        
        $hora_inicio = $data_inicio_obj ? $data_inicio_obj->format('H:i:s') : date('H:i:s');
        $hora_fim = $data_fim_obj->format('H:i:s');
        $data_hoje = date('Y-m-d');
        
        $tempo_total_segundos = 0;
        if ($data_inicio_obj) {
            $diff = $data_fim_obj->getTimestamp() - $data_inicio_obj->getTimestamp();
            $tempo_total_segundos = max(0, $diff);
        }
        
        $tempo_espera_segundos = 0;
        if ($data_inicio_obj && $data_recebido_obj) {
            $diff_espera = $data_inicio_obj->getTimestamp() - $data_recebido_obj->getTimestamp();
            $tempo_espera_segundos = max(0, $diff_espera);
        }
        
        // Clean customer telephone
        $telefone_cliente = preg_replace('/[^0-9]/', '', $jid);
        $nome_cliente = $chat['cliente_nome'] ?: 'Cliente WhatsApp';
        
        // Write details to CSV file safely with flock (Exclusive Lock)
        $csv_filename = 'atendimentos_' . date('Y-m') . '.csv';
        $csv_path = DIR_LOGS . DIRECTORY_SEPARATOR . $csv_filename;
        
        // Create file header if it doesn't exist
        $escrever_cabecalho = !file_exists($csv_path);
        
        $fp = fopen($csv_path, 'a+');
        if ($fp !== false) {
            // Wait for exclusive lock
            if (flock($fp, LOCK_EX)) {
                if ($escrever_cabecalho) {
                    fputcsv($fp, [
                        'ID_Atendimento', 'Data', 'Hora_Inicio', 'Hora_Fim', 
                        'Tempo_Total_Segundos', 'Telefone_Cliente', 'Nome_Cliente', 
                        'ID_Atendente', 'Nome_Atendente', 'Etiqueta_Final', 'Tempo_Espera_Segundos'
                    ]);
                }
                
                $id_atendimento = uniqid('at_');
                
                fputcsv($fp, [
                    $id_atendimento,
                    $data_hoje,
                    $hora_inicio,
                    $hora_fim,
                    $tempo_total_segundos,
                    $telefone_cliente,
                    $nome_cliente,
                    $atendente_atual,
                    $atendente_atual,
                    $etiqueta_final,
                    $tempo_espera_segundos
                ]);
                
                // Release lock and close
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
        
                sqlsrv_close($conn);
        
        echo json_encode(['success' => true]);
        break;
        
    case 'buscar_produto':
        $termo = isset($_GET['termo']) ? $_GET['termo'] : '';
        $tipo_busca = isset($_GET['tipo_busca']) ? $_GET['tipo_busca'] : 'produto';
        $produtos = sicnet_buscar_produtos($termo, $tipo_busca);
        echo json_encode($produtos);
        break;
        
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Ação inválida']);
        break;
}
?>
