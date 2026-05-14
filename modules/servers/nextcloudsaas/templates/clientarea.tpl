<div class="nextcloud-saas-panel">
    <style>
        .nextcloud-saas-panel {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        .nc-header {
            background: linear-gradient(135deg, #0082c9 0%, #00639a 100%);
            color: #fff;
            padding: 25px 30px;
            border-radius: 10px 10px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .nc-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
        }
        .nc-header .nc-logo {
            font-size: 36px;
            opacity: 0.9;
        }
        .nc-status-badge {
            display: inline-block;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .nc-status-success {
            background: rgba(255,255,255,0.2);
            color: #a8ffa8;
            border: 1px solid rgba(168,255,168,0.3);
        }
        .nc-status-danger {
            background: rgba(255,255,255,0.2);
            color: #ffa8a8;
            border: 1px solid rgba(255,168,168,0.3);
        }
        .nc-status-warning {
            background: rgba(255,255,255,0.2);
            color: #ffe0a8;
            border: 1px solid rgba(255,224,168,0.3);
        }
        .nc-body {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-top: none;
            border-radius: 0 0 10px 10px;
            padding: 25px 30px;
        }
        .nc-section {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            transition: box-shadow 0.2s ease;
        }
        .nc-section:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .nc-section h3 {
            margin: 0 0 15px 0;
            color: #333;
            font-size: 16px;
            font-weight: 600;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f0f0;
        }
        .nc-section table {
            width: 100%;
            border-collapse: collapse;
        }
        .nc-section td {
            padding: 8px 0;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: top;
        }
        .nc-section tr:last-child td {
            border-bottom: none;
        }
        .nc-section td:first-child {
            font-weight: 600;
            color: #555;
            width: 200px;
            white-space: nowrap;
        }
        .nc-section td:last-child {
            color: #333;
            word-break: break-all;
        }
        .nc-section td a {
            color: #0082c9;
            text-decoration: none;
        }
        .nc-section td a:hover {
            text-decoration: underline;
        }
        .nc-secret {
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 13px;
            background: #f8f9fa;
            padding: 3px 8px;
            border-radius: 4px;
            border: 1px solid #e8e8e8;
            display: inline-block;
            max-width: 100%;
            word-break: break-all;
        }
        .nc-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .nc-info-card {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            padding: 20px;
            transition: box-shadow 0.2s ease;
        }
        .nc-info-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .nc-info-card h4 {
            margin: 0 0 12px 0;
            color: #555;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        .nc-info-card .nc-value {
            font-size: 16px;
            color: #333;
            font-weight: 500;
            word-break: break-all;
        }
        .nc-info-card .nc-value a {
            color: #0082c9;
            text-decoration: none;
        }
        .nc-info-card .nc-value a:hover {
            text-decoration: underline;
        }
        .nc-progress-bar {
            background: #e9ecef;
            border-radius: 10px;
            height: 24px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        .nc-progress-fill {
            height: 100%;
            border-radius: 10px;
            background: linear-gradient(90deg, #0082c9, #00a2e8);
            transition: width 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            min-width: 40px;
        }
        .nc-progress-fill.nc-warn {
            background: linear-gradient(90deg, #f0ad4e, #ec971f);
        }
        .nc-progress-fill.nc-danger {
            background: linear-gradient(90deg, #d9534f, #c9302c);
        }
        .nc-storage-details {
            display: flex;
            justify-content: space-between;
            color: #666;
            font-size: 14px;
        }
        .nc-dns-notice {
            background: #e8f4fd;
            border: 1px solid #b8daff;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            color: #004085;
            font-size: 14px;
        }
        .nc-dns-notice strong {
            display: block;
            margin-bottom: 8px;
        }
        .nc-dns-notice code {
            background: rgba(0,0,0,0.05);
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
        }
        .nc-components-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        .nc-component-item {
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            font-size: 13px;
            color: #555;
        }
        .nc-component-item .nc-comp-icon {
            margin-right: 6px;
        }
        .nc-containers-bar {
            display: inline-block;
            margin-left: 10px;
            font-size: 13px;
            opacity: 0.9;
        }
        .nc-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .nc-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .nc-btn-primary {
            background: #0082c9;
            color: #fff;
        }
        .nc-btn-primary:hover {
            background: #006ba1;
            color: #fff;
            text-decoration: none;
        }
        .nc-btn-success {
            background: #28a745;
            color: #fff;
        }
        .nc-btn-success:hover {
            background: #218838;
            color: #fff;
            text-decoration: none;
        }
        .nc-btn-info {
            background: #17a2b8;
            color: #fff;
        }
        .nc-btn-info:hover {
            background: #138496;
            color: #fff;
            text-decoration: none;
        }
        .nc-btn-secondary {
            background: #fff;
            color: #333;
            border: 1px solid #ddd;
        }
        .nc-btn-secondary:hover {
            background: #f5f5f5;
            text-decoration: none;
            color: #333;
        }
        .nc-btn-icon {
            font-size: 16px;
        }
    </style>

    {* ================================================================ *}
    {* CABECALHO COM ESTADO                                             *}
    {* ================================================================ *}
    <div class="nc-header">
        <div>
            <h2>&#9729; Nextcloud SaaS</h2>
            <span class="nc-status-badge nc-status-{$statusColor}">
                {$instanceStatus}
                {if $containersUp > 0}
                    <span class="nc-containers-bar">
                        ({$containersUp}/{$containersTotal} containers)
                    </span>
                {/if}
            </span>
        </div>
        <div class="nc-logo">&#9729;</div>
    </div>

    <div class="nc-body">

        {* ================================================================ *}
        {* AVISO DE DNS (v3.0.0 — arquitetura compartilhada: 1 registro)   *}
        {* ================================================================ *}
        <div class="nc-dns-notice">
            <strong>&#128204; Registro DNS Necessario (1 dominio)</strong>
            Para o correto funcionamento, o seguinte registro DNS tipo <strong>A</strong> deve apontar para o IP do servidor: <code>{$serverIp}</code>
            <table style="width:100%; border-collapse:collapse; margin-top:10px; font-size:13px;">
                <tr style="background:rgba(0,0,0,0.05);">
                    <th style="padding:6px 10px; text-align:left; border:1px solid rgba(0,0,0,0.1);">Tipo</th>
                    <th style="padding:6px 10px; text-align:left; border:1px solid rgba(0,0,0,0.1);">Host</th>
                    <th style="padding:6px 10px; text-align:left; border:1px solid rgba(0,0,0,0.1);">Valor</th>
                    <th style="padding:6px 10px; text-align:left; border:1px solid rgba(0,0,0,0.1);">Servico</th>
                </tr>
                <tr>
                    <td style="padding:6px 10px; border:1px solid rgba(0,0,0,0.1);"><strong>A</strong></td>
                    <td style="padding:6px 10px; border:1px solid rgba(0,0,0,0.1);"><code>{$domain}</code></td>
                    <td style="padding:6px 10px; border:1px solid rgba(0,0,0,0.1);"><code>{$serverIp}</code></td>
                    <td style="padding:6px 10px; border:1px solid rgba(0,0,0,0.1);">Nextcloud</td>
                </tr>
            </table>
            {if $instanceStatus == 'Desconhecido' || $instanceStatus == ''}
            <div style="margin-top:12px; padding:10px; background:rgba(255,193,7,0.15); border:1px solid rgba(255,193,7,0.3); border-radius:6px;">
                <strong>&#9888; Aguardando configuracao DNS</strong><br>
                O sistema verifica automaticamente o registro DNS a cada 5 minutos.
                Quando estiver correto, sua instancia sera criada automaticamente
                e voce recebera um email com as credenciais de acesso.
            </div>
            {/if}
        </div>

        {* ================================================================ *}
        {* CREDENCIAIS - NEXTCLOUD                                          *}
        {* ================================================================ *}
        <div class="nc-section">
            <h3>&#9729; Nextcloud</h3>
            <table>
                <tr>
                    <td>URL:</td>
                    <td>
                        {if $nextcloudUrl}
                            <a href="{$nextcloudUrl}" target="_blank">{$nextcloudUrl}</a>
                        {else}
                            N/A
                        {/if}
                    </td>
                </tr>
                <tr>
                    <td>Usuario:</td>
                    <td>{$ncUser}</td>
                </tr>
                <tr>
                    <td>Senha:</td>
                    <td>
                        {if $ncPass}
                            <span class="nc-secret">{$ncPass}</span>
                        {else}
                            <em>Nao disponivel. Consulte o administrador.</em>
                        {/if}
                    </td>
                </tr>
            </table>
        </div>

        {* HaRP (AppAPI) Shared Key removida do painel do cliente em v3.1.7
           — é credencial interna do AppAPI/HaRP daemon e não pertence ao
           assinante (mesmo critério aplicado a Collabora/MariaDB/TURN/
           Signaling em v3.1.3). O container `<cliente>-harp` continua
           dedicado e o serviço permanece operacional. *}

        {* ================================================================ *}
        {* ARMAZENAMENTO                                                    *}
        {* ================================================================ *}
        <div class="nc-section">
            <h3>&#128190; Armazenamento</h3>
            <div class="nc-progress-bar">
                {assign var="progressClass" value=""}
                {if $storagePercent > 90}
                    {assign var="progressClass" value="nc-danger"}
                {elseif $storagePercent > 70}
                    {assign var="progressClass" value="nc-warn"}
                {/if}
                <div class="nc-progress-fill {$progressClass}" style="width: {if $storagePercent > 0}{$storagePercent}{else}2{/if}%">
                    {if $storagePercent > 10}{$storagePercent}%{/if}
                </div>
            </div>
            <div class="nc-storage-details">
                <span>Usado: <strong>{$storageUsed}</strong></span>
                <span>Quota: <strong>{$storageQuota}</strong></span>
                <span>Utilizacao: <strong>{$storagePercent}%</strong></span>
            </div>
        </div>

        {* ================================================================ *}
        {* COMPONENTES DA INSTANCIA (v3.0.0 — 3 containers do cliente +    *}
        {* 8 servicos globais shared-*)                                     *}
        {* ================================================================ *}
        <div class="nc-section">
            <h3>&#128230; Componentes da Sua Instancia (3 containers dedicados)</h3>
            <div class="nc-components-grid">
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#9729;</span> Nextcloud (app)
                </div>
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#128339;</span> Cron (agendamento)
                </div>
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#128268;</span> HaRP (AppAPI proxy)
                </div>
            </div>
            <h3 style="margin-top:18px;">&#127760; Servicos Globais Compartilhados</h3>
            <div style="font-size:12px; color:#555; margin-bottom:8px;">
                <em>Servicos de alta disponibilidade operados pela Defensys e utilizados por todas as instancias.</em>
            </div>
            <div class="nc-components-grid">
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#128451;</span> MariaDB (shared-db)
                </div>
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#9889;</span> Redis (shared-redis)
                </div>
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#128196;</span> Collabora Online (shared-collabora)
                </div>
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#128222;</span> TURN/STUN (shared-turn)
                </div>
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#9993;</span> NATS (shared-nats)
                </div>
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#127909;</span> Janus Gateway (shared-janus)
                </div>
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#128225;</span> Spreed Signaling (shared-signaling)
                </div>
                <div class="nc-component-item">
                    <span class="nc-comp-icon">&#128249;</span> Talk Recording (shared-recording)
                </div>
            </div>
        </div>

        {* ================================================================ *}
        {* DATA DE CRIACAO                                                  *}
        {* ================================================================ *}
        {if $credsDate}
        <div class="nc-section" style="padding: 12px 20px;">
            <small style="color: #888;">Data de criacao da instancia: <strong>{$credsDate}</strong></small>
        </div>
        {/if}

        {* ================================================================ *}
        {* ACOES                                                            *}
        {* ================================================================ *}
        <div class="nc-actions">
            <a href="{$nextcloudUrl}" target="_blank" class="nc-btn nc-btn-primary">
                <span class="nc-btn-icon">&#9729;</span>
                Aceder ao Nextcloud
            </a>
            <a href="{$collaboraUrl}" target="_blank" class="nc-btn nc-btn-success">
                <span class="nc-btn-icon">&#128196;</span>
                Collabora Office
            </a>
            <a href="{$nextcloudUrl}/apps/spreed" target="_blank" class="nc-btn nc-btn-info">
                <span class="nc-btn-icon">&#128172;</span>
                Nextcloud Talk
            </a>
            <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=checkStatus"
               class="nc-btn nc-btn-secondary">
                <span class="nc-btn-icon">&#128260;</span>
                Verificar Estado
            </a>
            <a href="clientarea.php?action=productdetails&id={$serviceid}&modop=custom&a=restartInstance"
               class="nc-btn nc-btn-secondary"
               onclick="return confirm('Tem a certeza que deseja reiniciar a sua instancia Nextcloud? Os 3 containers dedicados (app, cron e harp) serao reiniciados. Os servicos globais compartilhados nao sao afetados.');">
                <span class="nc-btn-icon">&#128260;</span>
                Reiniciar Instancia
            </a>
        </div>
    </div>
</div>
