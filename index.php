<?php
// index.php
require_once __DIR__ . '/config/config.php';

// Redirect to login if session is empty
if (!isset($_SESSION['usuario_logado'])) {
    header("Location: login.php");
    exit();
}

$atendente_nome = $_SESSION['usuario_logado'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZapCRM - Console de Atendimento</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <!-- Main Header -->
    <header>
        <div class="logo-section">
            <div class="logo-img">
                <svg viewBox="0 0 24 24">
                    <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z"/>
                </svg>
            </div>
            <span class="logo-title">ZapCRM</span>
        </div>

        <div class="header-actions">
            <!-- Dashboard link for supervisors -->
            <a href="dashboard.php" class="btn-icon" title="Dashboard / Relatórios">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="20" x2="18" y2="10"></line>
                    <line x1="12" y1="20" x2="12" y2="4"></line>
                    <line x1="6" y1="20" x2="6" y2="14"></line>
                </svg>
            </a>
            
            <div class="user-badge" id="logged-user"><?php echo htmlspecialchars($atendente_nome); ?></div>
            
            <!-- Dynamic WhatsApp Instance Connection State Indicator -->
            <div class="instance-status disconnected" id="whatsapp-indicator" onclick="verificarConexaoClique()">
                <span id="indicator-dot">🔴</span>
                <span id="indicator-text">Verificando...</span>
            </div>
            
            <!-- Logout action -->
            <a href="logout.php" class="btn-icon btn-logout" title="Sair do Sistema">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
            </a>
        </div>
    </header>

    <!-- App Shell -->
    <div class="app-container">
        
        <!-- Left Sidebar: Fila / Em Atendimento tabs -->
        <div class="sidebar">
            <div class="sidebar-tabs">
                <button class="tab-btn active" id="tab-fila-btn" onclick="switchTab('fila')">
                    Fila <span class="tab-badge" id="badge-fila">0</span>
                </button>
                <button class="tab-btn" id="tab-meus-btn" onclick="switchTab('meus')">
                    Meus Chats <span class="tab-badge" id="badge-meus">0</span>
                </button>
            </div>
            
            <div class="chat-list" id="chat-list-container">
                <!-- Chats dynamically loaded here -->
                <div style="padding:20px; text-align:center; color:var(--text-muted);">Carregando fila...</div>
            </div>
        </div>

        <!-- Right Side: Chat Window -->
        <div class="chat-window" id="chat-window-panel">
            
            <!-- Default placeholder when no chat selected -->
            <div class="chat-window-empty" id="chat-empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 0 1-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8Z" />
                </svg>
                <p>Selecione um cliente ao lado para iniciar o atendimento</p>
            </div>

            <!-- Active Chat Interface -->
            <div id="chat-active-state" style="display: none; flex: 1; flex-direction: column;">
                
                <div class="chat-window-header">
                    <div class="chat-window-contact">
                        <span class="chat-window-name" id="active-client-name">Nome do Cliente</span>
                        <span class="chat-window-jid" id="active-client-jid">Telefone</span>
                    </div>
                    <div class="chat-actions">
                        <!-- Search product tool button -->
                        <button class="btn-action-tool" onclick="abrirModalProduto()">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            Buscar Produto
                        </button>
                        
                        <!-- End chat button -->
                        <button class="btn-action-tool btn-finish" id="btn-finalizar-atend" onclick="abrirModalFinalizar()" style="display:none;">
                            Finalizar Atendimento
                        </button>
                    </div>
                </div>

                <!-- Messages container -->
                <div class="messages-container" id="messages-log">
                    <!-- Message bubbles load here -->
                </div>

                <!-- Input area -->
                <div class="chat-input-bar">
                    <div class="notification-banner" id="banner-reivindicar" style="display:none;">
                        ⚠️ Esta conversa está aguardando atendimento. Envie uma mensagem para assumi-la.
                    </div>
                    
                    <form class="chat-input-form" onsubmit="enviarMensagemForm(event)">
                        <textarea class="input-message" id="input-message-text" placeholder="Digite uma mensagem..." onkeydown="checarTecladoEnviar(event)"></textarea>
                        <button type="submit" class="btn-send">
                            Enviar
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                        </button>
                    </form>
                </div>
                
            </div>
        </div>
    </div>

    <!-- MODAL 1: WHATSAPP QR CODE CONNECTION -->
    <div class="modal-overlay" id="modal-qrcode">
        <div class="modal-card" style="max-width: 400px;">
            <div class="modal-header">
                <span class="modal-title">Conectar WhatsApp</span>
                <button class="modal-close" onclick="fecharModalQrcode()">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center;">
                <p style="margin-bottom: 20px; color:var(--text-secondary);">Escaneie o QR Code abaixo com seu WhatsApp Business para conectar.</p>
                <div id="qrcode-wrapper" style="min-height: 250px; display: flex; align-items: center; justify-content: center;">
                    <!-- Loading or Image base64 -->
                    <div class="text-muted">Aguardando QR Code...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="fecharModalQrcode()">Fechar</button>
            </div>
        </div>
    </div>

    <!-- MODAL 2: BUSCAR PRODUTO (SICNET) -->
    <div class="modal-overlay" id="modal-produto">
        <div class="modal-card" style="max-width: 700px;">
            <div class="modal-header">
                <span class="modal-title">Consultar Estoque Sicnet</span>
                <button class="modal-close" onclick="fecharModalProduto()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="search-box">
                    <select id="busca-tipo">
                        <option value="produto">Descrição</option>
                        <option value="codigo">Código</option>
                        <option value="referencia">Referência</option>
                    </select>
                    <input type="text" id="busca-termo" placeholder="Digite sua pesquisa e pressione Enter..." onkeydown="checarTecladoBuscaProduto(event)">
                    <button class="btn-send" onclick="buscarProdutosSicnet()">Buscar</button>
                </div>
                
                <div style="max-height: 350px; overflow-y: auto;">
                    <table class="prod-table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Descrição</th>
                                <th>Preço</th>
                                <th>Foto?</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody id="produtos-resultado-body">
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding:20px;">Faça uma pesquisa para ver resultados.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="fecharModalProduto()">Fechar</button>
            </div>
        </div>
    </div>

    <!-- MODAL 3: FINALIZAR ATENDIMENTO (WORKFLOW ETIQUETAS) -->
    <div class="modal-overlay" id="modal-finalizar">
        <div class="modal-card" style="max-width: 450px;">
            <div class="modal-header">
                <span class="modal-title">Finalizar Chamado</span>
                <button class="modal-close" onclick="fecharModalFinalizar()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 20px; color:var(--text-secondary);">Selecione o desfecho deste atendimento para salvar o log e aplicar a etiqueta correspondente.</p>
                
                <div class="tag-grid">
                    <label class="tag-option" id="label-opt-FaltaResposta" onclick="selectFinalTag('FaltaResposta')">
                        <input type="radio" name="final_tag" value="FaltaResposta" id="radio-FaltaResposta">
                        <div class="tag-color-dot" style="background-color: #ef4444;"></div>
                        <span class="tag-text">Falta Resposta</span>
                    </label>
                    <label class="tag-option" id="label-opt-RupturaEstoque" onclick="selectFinalTag('RupturaEstoque')">
                        <input type="radio" name="final_tag" value="RupturaEstoque" id="radio-RupturaEstoque">
                        <div class="tag-color-dot" style="background-color: #f97316;"></div>
                        <span class="tag-text">Ruptura de Estoque</span>
                    </label>
                    <label class="tag-option" id="label-opt-ProdutoEmcontradoParcial" onclick="selectFinalTag('ProdutoEmcontradoParcial')">
                        <input type="radio" name="final_tag" value="ProdutoEmcontradoParcial" id="radio-ProdutoEmcontradoParcial">
                        <div class="tag-color-dot" style="background-color: #3b82f6;"></div>
                        <span class="tag-text">Produto Encontrado Parcial</span>
                    </label>
                    <label class="tag-option" id="label-opt-ProdutosEncontradosTotal" onclick="selectFinalTag('ProdutosEncontradosTotal')">
                        <input type="radio" name="final_tag" value="ProdutosEncontradosTotal" id="radio-ProdutosEncontradosTotal">
                        <div class="tag-color-dot" style="background-color: #10b981;"></div>
                        <span class="tag-text">Produtos Encontrados Total</span>
                    </label>
                    <label class="tag-option" id="label-opt-InformaçõesGerais" onclick="selectFinalTag('InformaçõesGerais')">
                        <input type="radio" name="final_tag" value="InformaçõesGerais" id="radio-InformaçõesGerais">
                        <div class="tag-color-dot" style="background-color: #a855f7;"></div>
                        <span class="tag-text">Informações Gerais</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="fecharModalFinalizar()">Cancelar</button>
                <button class="btn-send" style="background:var(--primary);" onclick="confirmarFinalizarAtendimento()">Confirmar</button>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        let currentTab = 'fila'; // 'fila' or 'meus'
        let activeChatJid = null;
        let activeChatName = '';
        let activeChatStatus = '';
        let activeChatAttendant = null;
        let pollTimer = null;
        let connectionStatus = 'disconnected';
        let selectedFinalTagValue = '';

        // Run on load
        window.addEventListener('load', () => {
            verificarConexao();
            carregarConversas();
            
            // Set periodic polling every 3 seconds
            setInterval(() => {
                verificarConexao();
                carregarConversas();
                if (activeChatJid) {
                    carregarMensagens(activeChatJid, false);
                }
            }, 3000);
        });

        // Tab selection switch
        function switchTab(tab) {
            currentTab = tab;
            document.getElementById('tab-fila-btn').classList.toggle('active', tab === 'fila');
            document.getElementById('tab-meus-btn').classList.toggle('active', tab === 'meus');
            carregarConversas();
        }

        // WhatsApp Connection State Verification
        let qrcodePollTimer = null;

        function verificarConexao() {
            fetch('api/whatsapp_instance.php?ajax_action=status')
                .then(res => res.json())
                .then(data => {
                    const indicator = document.getElementById('whatsapp-indicator');
                    const text = document.getElementById('indicator-text');
                    const dot = document.getElementById('indicator-dot');
                    
                    connectionStatus = data.status;
                    
                    if (data.status === 'open') {
                        indicator.className = 'instance-status connected';
                        dot.textContent = '🟢';
                        text.textContent = 'Conectado';
                        indicator.title = 'Conectado. Clique para Desconectar/Logout';
                        
                        // Auto-close QR Code modal and notify if it was open
                        const modal = document.getElementById('modal-qrcode');
                        if (modal.classList.contains('open')) {
                            fecharModalQrcode();
                            alert("WhatsApp conectado com sucesso!");
                            location.reload();
                        }
                    } else if (data.status === 'close' || data.status === 'connecting') {
                        indicator.className = 'instance-status disconnected';
                        dot.textContent = '🔴';
                        text.textContent = 'Desconectado';
                        indicator.title = 'Desconectado. Clique para gerar QR Code';
                    } else {
                        indicator.className = 'instance-status connecting';
                        dot.textContent = '🟡';
                        text.textContent = 'Carregando...';
                        indicator.title = 'Erro de verificação ou API inacessível';
                    }
                })
                .catch(err => {
                    console.error('Erro ao verificar conexao WhatsApp:', err);
                });
        }

        function verificarConexaoClique() {
            if (connectionStatus === 'open') {
                if (confirm('Deseja desconectar (fazer Logout) da instância do WhatsApp?')) {
                    fetch('api/whatsapp_instance.php?ajax_action=logout')
                        .then(res => res.json())
                        .then(data => {
                            verificarConexao();
                        });
                }
            } else {
                abrirModalQrcode();
            }
        }

        function abrirModalQrcode() {
            const modal = document.getElementById('modal-qrcode');
            modal.classList.add('open');
            carregarQrcode();
            
            // Start interval to refresh QR code every 20 seconds
            if (qrcodePollTimer) clearInterval(qrcodePollTimer);
            qrcodePollTimer = setInterval(carregarQrcode, 20000);
        }

        function carregarQrcode() {
            const wrapper = document.getElementById('qrcode-wrapper');
            wrapper.innerHTML = '<div class="text-muted">Obtendo QR Code...</div>';
            
            fetch('api/whatsapp_instance.php?ajax_action=qrcode')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.qrcode) {
                        // Display the Base64 image
                        wrapper.innerHTML = `
                            <img class="qr-code-img" src="${data.qrcode}" alt="Scan QR Code" width="220" height="220">
                        `;
                    } else {
                        wrapper.innerHTML = `
                            <div class="text-danger" style="color:var(--red-rose);">${data.message || 'Erro ao carregar QR Code.'}</div>
                        `;
                    }
                })
                .catch(err => {
                    wrapper.innerHTML = `
                        <div class="text-danger" style="color:var(--red-rose);">Erro de conexão com servidor local.</div>
                    `;
                });
        }

        function fecharModalQrcode() {
            document.getElementById('modal-qrcode').classList.remove('open');
            if (qrcodePollTimer) {
                clearInterval(qrcodePollTimer);
                qrcodePollTimer = null;
            }
        }

        // Fetch chats from queue database
        function carregarConversas() {
            fetch('api/atendimento.php?action=list_chats')
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        console.error(data.error);
                        return;
                    }
                    
                    // Update badges
                    document.getElementById('badge-fila').textContent = data.fila.length;
                    document.getElementById('badge-meus').textContent = data.em_atendimento.length;
                    
                    const container = document.getElementById('chat-list-container');
                    container.innerHTML = '';
                    
                    const targetList = (currentTab === 'fila') ? data.fila : data.em_atendimento;
                    
                    if (targetList.length === 0) {
                        container.innerHTML = `
                            <div style="padding:40px 20px; text-align:center; color:var(--text-muted); font-size:14px;">
                                Nenhuma conversa encontrada
                            </div>
                        `;
                        return;
                    }
                    
                    targetList.forEach(chat => {
                        const date = new Date(chat.data_recebido.date);
                        const displayTime = date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                        
                        const activeClass = (activeChatJid === chat.cliente_jid) ? 'active' : '';
                        
                        const itemHtml = `
                            <div class="chat-item ${activeClass}" onclick="selecionarChat('${chat.cliente_jid}', '${chat.cliente_nome}', '${currentTab}')">
                                <div class="chat-item-header">
                                    <span class="chat-item-name">${chat.cliente_nome}</span>
                                    <span class="chat-item-time">${displayTime}</span>
                                </div>
                                <div class="chat-item-preview">${chat.ultimo_texto || 'Sem mensagens...'}</div>
                            </div>
                        `;
                        container.insertAdjacentHTML('beforeend', itemHtml);
                    });
                    
                    // Concurrency check: If our active chat is in 'fila' tab, but is no longer present 
                    // in either fila list nor em_atendimento list (e.g. another agent claimed it), 
                    // or if it was claimed in the background, we must alert the user.
                    if (activeChatJid) {
                        let activeChatStillAvailable = false;
                        
                        // Check if in current list
                        data.fila.forEach(c => { if(c.cliente_jid === activeChatJid) activeChatStillAvailable = true; });
                        data.em_atendimento.forEach(c => { if(c.cliente_jid === activeChatJid) activeChatStillAvailable = true; });
                        
                        if (!activeChatStillAvailable) {
                            // The chat has been finalized or claimed by someone else!
                            alert("Este atendimento não está mais disponível (pode ter sido assumido por outro atendente ou finalizado).");
                            fecharAtendimentoUI();
                        }
                    }
                })
                .catch(err => {
                    console.error('Erro ao carregar lista de chats:', err);
                });
        }

        // Close Chat Screen (Reset UI)
        function fecharAtendimentoUI() {
            activeChatJid = null;
            document.getElementById('chat-active-state').style.display = 'none';
            document.getElementById('chat-empty-state').style.display = 'flex';
            carregarConversas();
        }

        // Select a client chat
        function selecionarChat(jid, nome, tab) {
            activeChatJid = jid;
            activeChatName = nome;
            activeChatStatus = (tab === 'fila') ? 'EsperandoAtendimento' : 'EmAtendimento';
            
            // Toggle visibility
            document.getElementById('chat-empty-state').style.display = 'none';
            document.getElementById('chat-active-state').style.display = 'flex';
            
            // Set header values
            document.getElementById('active-client-name').textContent = nome;
            
            // Format telephone string for visual comfort
            const plainNumber = jid.split('@')[0];
            document.getElementById('active-client-jid').textContent = '+' + plainNumber.substring(0,2) + ' (' + plainNumber.substring(2,4) + ') ' + plainNumber.substring(4);
            
            // Enable finish button if we are in active customer tab
            const btnFinalizar = document.getElementById('btn-finalizar-atend');
            if (tab === 'meus') {
                btnFinalizar.style.display = 'block';
                document.getElementById('banner-reivindicar').style.display = 'none';
            } else {
                btnFinalizar.style.display = 'none';
                document.getElementById('banner-reivindicar').style.display = 'block';
            }
            
            // Clear input and focus
            document.getElementById('input-message-text').value = '';
            
            // Load messages
            carregarMensagens(jid, true);
            
            // Refresh conversation item selection classes visually
            carregarConversas();
        }

        // Fetch messages for active chat
        function carregarMensagens(jid, forceScroll) {
            if (activeChatJid !== jid) return;
            
            const logContainer = document.getElementById('messages-log');
            
            fetch(`api/atendimento.php?action=get_messages&jid=${encodeURIComponent(jid)}`)
                .then(res => res.json())
                .then(data => {
                    // Safety check if customer was changed before response arrived
                    if (activeChatJid !== jid) return;
                    
                    if (data.error) {
                        console.error(data.error);
                        return;
                    }
                    
                    // Track previous height to see if we should scroll
                    const isAtBottom = logContainer.scrollHeight - logContainer.clientHeight <= logContainer.scrollTop + 50;
                    
                    // Build HTML
                    logContainer.innerHTML = '';
                    
                    if (!data.messages || data.messages.length === 0) {
                        logContainer.innerHTML = `<div style="text-align:center; padding: 40px; color:var(--text-muted);">Nenhuma mensagem registrada.</div>`;
                        return;
                    }
                    
                    data.messages.forEach(msg => {
                        const fromMe = msg.key.fromMe;
                        const wrapperClass = fromMe ? 'sent' : 'received';
                        
                        // Extract text content safely
                        let text = '';
                        let mediaHtml = '';
                        
                        if (msg.message) {
                            if (msg.message.conversation) {
                                text = msg.message.conversation;
                            } else if (msg.message.extendedTextMessage && msg.message.extendedTextMessage.text) {
                                text = msg.message.extendedTextMessage.text;
                            } else if (msg.message.imageMessage) {
                                text = msg.message.imageMessage.caption || '';
                                // If the message has an image and is already downloaded, we can show a placeholder or the actual media URL if available
                                mediaHtml = `<div style="padding:10px; background:rgba(0,0,0,0.1); border-radius:8px; margin-bottom:8px; font-size:12px; color:var(--text-muted); display:flex; align-items:center; gap:8px;">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    [Imagem Anexada]
                                </div>`;
                            } else {
                                text = '[Mídia ou Mensagem Especial]';
                            }
                        }
                        
                        // Strip agent name in bold tags from UI preview if needed, or render raw format
                        // Replace newlines with <br> for HTML rendering
                        const formattedText = text.replace(/\n/g, '<br>');
                        
                        const time = new Date(msg.messageTimestamp * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                        
                        const messageHtml = `
                            <div class="msg-wrapper ${wrapperClass}">
                                <div class="msg-bubble">
                                    ${mediaHtml}
                                    <div>${formattedText}</div>
                                </div>
                                <div class="msg-meta">${time}</div>
                            </div>
                        `;
                        logContainer.insertAdjacentHTML('beforeend', messageHtml);
                    });
                    
                    // Force scroll if requested, or if the user was already at the bottom of the log
                    if (forceScroll || isAtBottom) {
                        logContainer.scrollTop = logContainer.scrollHeight;
                    }
                })
                .catch(err => {
                    console.error('Erro ao carregar mensagens:', err);
                });
        }

        // Form submission for text messages
        function enviarMensagemForm(event) {
            if (event) event.preventDefault();
            
            const textarea = document.getElementById('input-message-text');
            const mensagem = textarea.value.trim();
            
            if (mensagem === '' || !activeChatJid) return;
            
            // Disable inputs during sending
            textarea.disabled = true;
            
            const formData = new FormData();
            formData.append('jid', activeChatJid);
            formData.append('mensagem', mensagem);
            
            fetch('api/atendimento.php?action=send_message', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                textarea.disabled = false;
                if (data.success) {
                    textarea.value = '';
                    
                    // Force redirect current attendant tab to 'meus' if they just claimed it!
                    if (activeChatStatus === 'EsperandoAtendimento') {
                        activeChatStatus = 'EmAtendimento';
                        switchTab('meus');
                        // Highlight same chat JID in new tab
                        setTimeout(() => {
                            selecionarChat(activeChatJid, activeChatName, 'meus');
                        }, 200);
                    } else {
                        carregarMensagens(activeChatJid, true);
                    }
                    textarea.focus();
                } else {
                    alert(data.error || 'Erro ao enviar mensagem.');
                }
            })
            .catch(err => {
                textarea.disabled = false;
                alert('Erro de conexão com o servidor local.');
            });
        }

        // Handle keyboard Enter (without Shift) as Submit
        function checarTecladoEnviar(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                enviarMensagemForm();
            }
        }

        // MODAL 2: SEARCH PRODUCTS
        function abrirModalProduto() {
            document.getElementById('modal-produto').classList.add('open');
            document.getElementById('busca-termo').focus();
        }

        function fecharModalProduto() {
            document.getElementById('modal-produto').classList.remove('open');
        }

        function checarTecladoBuscaProduto(event) {
            if (event.key === 'Enter') {
                buscarProdutosSicnet();
            }
        }

        // Call database search for products via AJAX
        function buscarProdutosSicnet() {
            const termo = document.getElementById('busca-termo').value.trim();
            const tipo = document.getElementById('busca-tipo').value;
            
            if (termo === '') return;
            
            const body = document.getElementById('produtos-resultado-body');
            body.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding:20px;">Pesquisando no Sicnet...</td></tr>`;
            
            fetch(`api/atendimento.php?action=buscar_produto&termo=${encodeURIComponent(termo)}&tipo_busca=${tipo}`)
                .then(res => res.json())
                .then(data => {
                    body.innerHTML = '';
                    
                    if (data.length === 0) {
                        body.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding:20px;">Nenhum produto encontrado.</td></tr>`;
                        return;
                    }
                    
                    data.forEach(prod => {
                        const preco = prod.precovenda.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                        const possuiFotoText = prod.possui_foto ? '🟢 Sim' : '🔴 Não';
                        
                        // Select actions
                        let acaoHtml = '';
                        if (prod.possui_foto) {
                            acaoHtml = `
                                <div style="display:flex; gap:6px;">
                                    <button class="btn-select-prod" onclick="enviarProdutoChat('${prod.codigo}', 'sim')">Enviar c/ Foto</button>
                                    <button class="btn-select-prod" style="background:rgba(255,255,255,0.05); color:var(--text-secondary); border-color:var(--border-color);" onclick="enviarProdutoChat('${prod.codigo}', 'nao')">Só Texto</button>
                                </div>
                            `;
                        } else {
                            acaoHtml = `
                                <button class="btn-select-prod" onclick="enviarProdutoChat('${prod.codigo}', 'nao')">Enviar Texto</button>
                            `;
                        }
                        
                        const rowHtml = `
                            <tr>
                                <td style="font-weight:700;">${prod.codigo}</td>
                                <td>${prod.produto}</td>
                                <td style="color:var(--primary-light); font-weight:600;">${preco}</td>
                                <td>${possuiFotoText}</td>
                                <td>${acaoHtml}</td>
                            </tr>
                        `;
                        body.insertAdjacentHTML('beforeend', rowHtml);
                    });
                })
                .catch(err => {
                    body.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--red-rose); padding:20px;">Erro ao conectar com banco.</td></tr>`;
                });
        }

        // Send selected product to customer chat
        function enviarProdutoChat(codigoProduto, enviarFoto) {
            if (!activeChatJid) return;
            
            fecharModalProduto();
            
            // Ask for additional custom text to send with the product (optional)
            const obsMsg = prompt("Quer anexar alguma mensagem personalizada ao produto? (Opcional)");
            
            const formData = new FormData();
            formData.append('jid', activeChatJid);
            formData.append('codigo_produto', codigoProduto);
            formData.append('enviar_foto', enviarFoto);
            formData.append('mensagem', obsMsg ? obsMsg.trim() : '');
            
            fetch('api/atendimento.php?action=send_message', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (activeChatStatus === 'EsperandoAtendimento') {
                        activeChatStatus = 'EmAtendimento';
                        switchTab('meus');
                        setTimeout(() => {
                            selecionarChat(activeChatJid, activeChatName, 'meus');
                        }, 200);
                    } else {
                        carregarMensagens(activeChatJid, true);
                    }
                } else {
                    alert(data.error || 'Erro ao enviar produto.');
                }
            })
            .catch(err => {
                alert('Erro de conexão ao enviar produto.');
            });
        }

        // MODAL 3: FINALIZE ATTENDANCE
        function abrirModalFinalizar() {
            document.getElementById('modal-finalizar').classList.add('open');
            
            // Reset previous selection
            selectedFinalTagValue = '';
            document.querySelectorAll('.tag-option').forEach(el => el.classList.remove('selected'));
            document.querySelectorAll('.tag-option input[type="radio"]').forEach(el => el.checked = false);
        }

        function fecharModalFinalizar() {
            document.getElementById('modal-finalizar').classList.remove('open');
        }

        function selectFinalTag(tag) {
            selectedFinalTagValue = tag;
            
            // Highlight option card
            document.querySelectorAll('.tag-option').forEach(el => el.classList.remove('selected'));
            document.getElementById('label-opt-' + tag).classList.add('selected');
            
            // Check radio button
            document.getElementById('radio-' + tag).checked = true;
        }

        // Confirm finalization through AJAX
        function confirmarFinalizarAtendimento() {
            if (!activeChatJid) return;
            
            if (selectedFinalTagValue === '') {
                alert("Por favor, selecione uma etiqueta de encerramento!");
                return;
            }
            
            const formData = new FormData();
            formData.append('jid', activeChatJid);
            formData.append('etiqueta_final', selectedFinalTagValue);
            
            fetch('api/atendimento.php?action=finalizar_atendimento', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    fecharModalFinalizar();
                    fecharAtendimentoUI();
                } else {
                    alert(data.error || 'Erro ao finalizar atendimento.');
                }
            })
            .catch(err => {
                alert('Erro de conexão ao tentar finalizar o atendimento.');
            });
        }
    </script>
</body>
</html>
