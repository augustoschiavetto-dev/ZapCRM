<?php
// dashboard.php
require_once __DIR__ . '/config/config.php';

// Check if user is logged in
if (!isset($_SESSION['usuario_logado'])) {
    header("Location: login.php");
    exit();
}

$atendente_nome = $_SESSION['usuario_logado'];

// Handle Month Selection
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$csv_filename = 'atendimentos_' . $selected_month . '.csv';
$csv_path = DIR_LOGS . DIRECTORY_SEPARATOR . $csv_filename;

// List all available CSV months in logs directory
$available_months = [];
if (file_exists(DIR_LOGS)) {
    $files = scandir(DIR_LOGS);
    foreach ($files as $file) {
        if (preg_match('/^atendimentos_(\d{4}-\d{2})\.csv$/', $file, $matches)) {
            $available_months[] = $matches[1];
        }
    }
}
if (!in_array($selected_month, $available_months)) {
    $available_months[] = $selected_month;
}
sort($available_months);

// Initialize stats
$total_atendimentos = 0;
$soma_tma = 0; // Tempo Médio de Atendimento
$soma_tme = 0; // Tempo Médio de Espera
$etiquetas_contagem = [
    'FaltaResposta' => 0,
    'RupturaEstoque' => 0,
    'ProdutoEmcontradoParcial' => 0,
    'ProdutosEncontradosTotal' => 0,
    'InformaçõesGerais' => 0
];
$atendentes_stats = [];

// Parse CSV file if it exists
if (file_exists($csv_path)) {
    $fp = fopen($csv_path, 'r');
    if ($fp !== false) {
        // Skip header row
        $headers = fgetcsv($fp);
        
        while (($row = fgetcsv($fp)) !== false) {
            // CSV columns mapping:
            // 0: ID_Atendimento, 1: Data, 2: Hora_Inicio, 3: Hora_Fim, 
            // 4: Tempo_Total_Segundos, 5: Telefone_Cliente, 6: Nome_Cliente, 
            // 7: ID_Atendente, 8: Nome_Atendente, 9: Etiqueta_Final, 10: Tempo_Espera_Segundos
            $total_atendimentos++;
            
            $tma = isset($row[4]) ? (int)$row[4] : 0;
            $soma_tma += $tma;
            
            $tme = isset($row[10]) ? (int)$row[10] : 0;
            $soma_tme += $tme;
            
            $etiqueta = isset($row[9]) ? $row[9] : '';
            if (array_key_exists($etiqueta, $etiquetas_contagem)) {
                $etiquetas_contagem[$etiqueta]++;
            } else if (!empty($etiqueta)) {
                // Support dynamic tag names in case they differ slightly
                $etiquetas_contagem[$etiqueta] = isset($etiquetas_contagem[$etiqueta]) ? $etiquetas_contagem[$etiqueta] + 1 : 1;
            }
            
            $atendente = isset($row[8]) ? $row[8] : 'Desconhecido';
            if (!isset($atendentes_stats[$atendente])) {
                $atendentes_stats[$atendente] = [
                    'qtd' => 0,
                    'tma_soma' => 0,
                    'tme_soma' => 0
                ];
            }
            $atendentes_stats[$atendente]['qtd']++;
            $atendentes_stats[$atendente]['tma_soma'] += $tma;
            $atendentes_stats[$atendente]['tme_soma'] += $tme;
        }
        fclose($fp);
    }
}

// Compute global averages
$media_tma = $total_atendimentos > 0 ? round($soma_tma / $total_atendimentos) : 0;
$media_tme = $total_atendimentos > 0 ? round($soma_tme / $total_atendimentos) : 0;

// Sort attendants by productivity (number of chats completed)
uasort($atendentes_stats, function($a, $b) {
    return $b['qtd'] <=> $a['qtd'];
});

// Helper function to format seconds into readable hours/minutes/seconds
function formatar_tempo($segundos) {
    if ($segundos < 60) {
        return $segundos . 's';
    }
    $minutos = floor($segundos / 60);
    $segundos_restantes = $segundos % 60;
    if ($minutos < 60) {
        return $minutos . 'm ' . $segundos_restantes . 's';
    }
    $horas = floor($minutos / 60);
    $minutos_restantes = $minutos % 60;
    return $horas . 'h ' . $minutos_restantes . 'm';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZapCRM - Dashboard de Indicadores</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            overflow-y: auto; /* Allow scrolling for dashboard reports */
            height: auto;
            min-height: 100vh;
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .dash-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 20px;
        }

        .dash-title {
            font-size: 24px;
            font-weight: 700;
        }

        .month-selector {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            padding: 10px 16px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            outline: none;
            cursor: pointer;
        }

        /* Metric Grid Cards */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .metric-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
            position: relative;
            overflow: hidden;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }

        .metric-card.green::before { background: var(--green-emerald); }
        .metric-card.yellow::before { background: var(--yellow-amber); }

        .metric-label {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .metric-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* Charts Layout Section */
        .dashboard-content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(480px, 1fr));
            gap: 24px;
        }

        @media (max-width: 600px) {
            .dashboard-content-grid {
                grid-template-columns: 1fr;
            }
        }

        .dash-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }

        .card-header {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Progress lists for labels and users */
        .progress-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .progress-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .progress-label-info {
            display: flex;
            justify-content: space-between;
            font-size: 13.5px;
            font-weight: 600;
        }

        .progress-bar-track {
            background: rgba(255, 255, 255, 0.04);
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            width: 100%;
        }

        .progress-bar-fill {
            height: 100%;
            border-radius: 4px;
            width: 0;
            transition: width 1s ease-out;
        }

        /* Attendant Table Styling */
        .leaderboard-table {
            width: 100%;
            border-collapse: collapse;
        }

        .leaderboard-table th, .leaderboard-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .leaderboard-table th {
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 500;
        }

        .leaderboard-table td {
            font-size: 14.5px;
        }

        .rank-badge {
            display: inline-flex;
            width: 24px;
            height: 24px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-weight: 700;
            font-size: 12px;
            background: rgba(255, 255, 255, 0.08);
        }

        tr:nth-child(1) .rank-badge { background: #ffd700; color: #0b0f19; } /* Gold */
        tr:nth-child(2) .rank-badge { background: #c0c0c0; color: #0b0f19; } /* Silver */
        tr:nth-child(3) .rank-badge { background: #cd7f32; color: #0b0f19; } /* Bronze */
    </style>
</head>
<body>

    <!-- Main Header -->
    <header style="margin-bottom:0;">
        <div class="logo-section">
            <div class="logo-img">
                <svg viewBox="0 0 24 24">
                    <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z"/>
                </svg>
            </div>
            <span class="logo-title">ZapCRM</span>
        </div>

        <div class="header-actions">
            <!-- Back to chat console button -->
            <a href="index.php" class="btn-icon" title="Voltar ao Chat" style="width: auto; padding: 0 16px; border-radius:12px; display:flex; gap:8px; font-weight:600; font-size:14px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Console do Chat
            </a>
            
            <div class="user-badge"><?php echo htmlspecialchars($atendente_nome); ?></div>
        </div>
    </header>

    <!-- Main Content Container -->
    <div class="dashboard-container">
        
        <!-- Header area with month filter selector -->
        <div class="dash-header">
            <div>
                <h1 class="dash-title">Relatórios e Indicadores Operacionais</h1>
                <p style="color:var(--text-secondary); margin-top:4px;">Resultados das finalizações de chamados de WhatsApp no período selecionado</p>
            </div>
            
            <form method="get" action="dashboard.php" id="month-form">
                <select name="month" class="month-selector" onchange="document.getElementById('month-form').submit()">
                    <?php foreach ($available_months as $m): 
                        $formatted_m = DateTime::createFromFormat('Y-m', $m)->format('m/Y');
                    ?>
                        <option value="<?php echo $m; ?>" <?php echo $m === $selected_month ? 'selected' : ''; ?>>
                            Mês: <?php echo $formatted_m; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <!-- Metric Cards -->
        <div class="metrics-grid">
            <div class="metric-card">
                <span class="metric-label">Total de Atendimentos</span>
                <span class="metric-value"><?php echo $total_atendimentos; ?></span>
            </div>
            
            <div class="metric-card green">
                <span class="metric-label">Tempo Médio de Atendimento (TMA)</span>
                <span class="metric-value"><?php echo formatar_tempo($media_tma); ?></span>
            </div>
            
            <div class="metric-card yellow">
                <span class="metric-label">Tempo Médio de Espera (TME)</span>
                <span class="metric-value"><?php echo formatar_tempo($media_tme); ?></span>
            </div>
        </div>

        <?php if ($total_atendimentos === 0): ?>
            <div class="dash-card" style="text-align:center; padding: 60px 20px; color:var(--text-muted);">
                <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom:16px; opacity:0.3;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"></path>
                </svg>
                <p style="font-size:16px; font-weight:600;">Nenhum atendimento registrado neste mês.</p>
                <p style="font-size:14px; margin-top:4px;">Finalize alguma conversa no console do chat para gerar os relatórios.</p>
            </div>
        <?php else: ?>

            <!-- Distribution and Leaderboard Section -->
            <div class="dashboard-content-grid">
                
                <!-- Final Tags Distribution -->
                <div class="dash-card">
                    <div class="card-header">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                            <line x1="7" y1="7" x2="7" y2="7"></line>
                        </svg>
                        Distribuição das Etiquetas Finais
                    </div>
                    
                    <div class="progress-list">
                        <?php 
                        $cores = [
                            'FaltaResposta' => '#ef4444',
                            'RupturaEstoque' => '#f97316',
                            'ProdutoEmcontradoParcial' => '#3b82f6',
                            'ProdutosEncontradosTotal' => '#10b981',
                            'InformaçõesGerais' => '#a855f7'
                        ];
                        
                        $titulos_etiquetas = [
                            'FaltaResposta' => 'Falta Resposta',
                            'RupturaEstoque' => 'Ruptura de Estoque',
                            'ProdutoEmcontradoParcial' => 'Produto Encontrado Parcial',
                            'ProdutosEncontradosTotal' => 'Produtos Encontrados Total',
                            'InformaçõesGerais' => 'Informações Gerais'
                        ];

                        foreach ($etiquetas_contagem as $tag => $count): 
                            $pct = $total_atendimentos > 0 ? round(($count / $total_atendimentos) * 100) : 0;
                            $cor = isset($cores[$tag]) ? $cores[$tag] : '#64748b';
                            $titulo = isset($titulos_etiquetas[$tag]) ? $titulos_etiquetas[$tag] : $tag;
                        ?>
                            <div class="progress-item">
                                <div class="progress-label-info">
                                    <span style="color: var(--text-primary);"><?php echo htmlspecialchars($titulo); ?></span>
                                    <span style="color: var(--text-secondary);"><?php echo $count; ?> chamados (<?php echo $pct; ?>%)</span>
                                </div>
                                <div class="progress-bar-track">
                                    <div class="progress-bar-fill" style="width: <?php echo $pct; ?>%; background-color: <?php echo $cor; ?>;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Attendant Ranking Leaderboard -->
                <div class="dash-card">
                    <div class="card-header">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path>
                        </svg>
                        Eficiência por Atendente (Ranking)
                    </div>
                    
                    <table class="leaderboard-table">
                        <thead>
                            <tr>
                                <th style="width: 50px; text-align: center;">Pos</th>
                                <th>Atendente</th>
                                <th>Atendimentos</th>
                                <th>TMA</th>
                                <th>TME</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            foreach ($atendentes_stats as $nome => $stats): 
                                $avg_tma = $stats['qtd'] > 0 ? round($stats['tma_soma'] / $stats['qtd']) : 0;
                                $avg_tme = $stats['qtd'] > 0 ? round($stats['tme_soma'] / $stats['qtd']) : 0;
                            ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <span class="rank-badge"><?php echo $rank++; ?></span>
                                    </td>
                                    <td style="font-weight:600; color:var(--text-primary);"><?php echo htmlspecialchars($nome); ?></td>
                                    <td style="font-weight:700; color:var(--primary-light);"><?php echo $stats['qtd']; ?></td>
                                    <td><?php echo formatar_tempo($avg_tma); ?></td>
                                    <td><?php echo formatar_tempo($avg_tme); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>

        <?php endif; ?>
    </div>

</body>
</html>
