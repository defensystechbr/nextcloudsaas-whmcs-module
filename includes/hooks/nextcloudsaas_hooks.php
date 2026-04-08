<?php
/**
 * Hooks do módulo Nextcloud-SaaS para WHMCS
 *
 * Define hooks que se integram com eventos do WHMCS para
 * executar ações adicionais durante o ciclo de vida do produto.
 * Integrado com o manage.sh v10.0 e a arquitetura de 10 containers.
 *
 * v2.6.1:
 *   - Corrigido: Hook alterado de DailyCronJob para AfterCronJob (executa a cada 5min)
 *   - Corrigido: Fatal Error por redeclaração de funções (hooks duplicados)
 *   - Corrigido: Status do serviço muda automaticamente de Pending para Active
 *
 * v2.6.0:
 *   - Adicionado hook AfterCronJob para verificação automática de DNS
 *     e provisionamento automático de instâncias pendentes.
 *   - IP do servidor obtido dinamicamente do Server configurado no WHMCS.
 *   - Timeout de 3 dias sem DNS: para verificação e notifica admin.
 *   - Email ao cliente com credenciais quando instância é criada.
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    2.6.1
 */

if (!defined("WHMCS")) {
    die("Este ficheiro não pode ser acedido diretamente.");
}

// Carregar bibliotecas do módulo (necessário para o cron hook)
$moduleLibPath = __DIR__ . '/../../modules/servers/nextcloudsaas/lib/';
if (file_exists($moduleLibPath . 'Helper.php')) {
    require_once $moduleLibPath . 'Helper.php';
}

// =============================================================================
// HOOKS DE CICLO DE VIDA DO MÓDULO
// =============================================================================

/**
 * Hook executado após a criação bem-sucedida de um serviço.
 */
add_hook('AfterModuleCreate', 1, function ($vars) {
    try {
        $serviceId = isset($vars['params']['serviceid']) ? $vars['params']['serviceid'] : '';
        $domain = isset($vars['params']['domain']) ? $vars['params']['domain'] : '';

        if (empty($domain)) {
            return;
        }

        $collaboraDomain = 'collabora-' . $domain;
        $signalingDomain = 'signaling-' . $domain;

        logActivity(
            "Nextcloud SaaS: Instância criada com sucesso (Serviço #{$serviceId})\n"
            . "  Domínio: {$domain}\n"
            . "  Collabora: {$collaboraDomain}\n"
            . "  Signaling: {$signalingDomain}\n"
            . "  Containers: 10 (app, db, redis, collabora, turn, cron, harp, nats, janus, signaling)"
        );

    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Hook Error (AfterModuleCreate): " . $e->getMessage());
    }
});

/**
 * Hook executado após a suspensão de um serviço.
 */
add_hook('AfterModuleSuspend', 1, function ($vars) {
    try {
        $domain = isset($vars['params']['domain']) ? $vars['params']['domain'] : '';
        $serviceId = isset($vars['params']['serviceid']) ? $vars['params']['serviceid'] : '';

        if (!empty($domain)) {
            logActivity("Nextcloud SaaS: Instância suspensa - {$domain} (Serviço #{$serviceId})");
        }
    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Hook Error (AfterModuleSuspend): " . $e->getMessage());
    }
});

/**
 * Hook executado após a reativação de um serviço.
 */
add_hook('AfterModuleUnsuspend', 1, function ($vars) {
    try {
        $domain = isset($vars['params']['domain']) ? $vars['params']['domain'] : '';
        $serviceId = isset($vars['params']['serviceid']) ? $vars['params']['serviceid'] : '';

        if (!empty($domain)) {
            logActivity("Nextcloud SaaS: Instância reativada - {$domain} (Serviço #{$serviceId})");
        }
    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Hook Error (AfterModuleUnsuspend): " . $e->getMessage());
    }
});

/**
 * Hook executado após a terminação de um serviço.
 */
add_hook('AfterModuleTerminate', 1, function ($vars) {
    try {
        $domain = isset($vars['params']['domain']) ? $vars['params']['domain'] : '';
        $serviceId = isset($vars['params']['serviceid']) ? $vars['params']['serviceid'] : '';

        if (!empty($domain)) {
            logActivity(
                "Nextcloud SaaS: Instância TERMINADA - {$domain} (Serviço #{$serviceId})\n"
                . "  Um backup foi realizado automaticamente antes da remoção."
            );
        }
    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Hook Error (AfterModuleTerminate): " . $e->getMessage());
    }
});

/**
 * Hook para adicionar informações DNS na página de detalhes do serviço
 * na área de cliente.
 */
add_hook('ClientAreaPageProductDetails', 1, function ($vars) {
    try {
        if (isset($vars['modulename']) && $vars['modulename'] === 'nextcloudsaas') {
            $domain = isset($vars['domain']) ? $vars['domain'] : '';
            if (!empty($domain)) {
                return [
                    'dns_records' => [
                        $domain,
                        'collabora-' . $domain,
                        'signaling-' . $domain,
                    ],
                ];
            }
        }
    } catch (\Exception $e) {
        // Silenciar
    }
    return [];
});

/**
 * Hook para registar alterações de password.
 */
add_hook('AfterModuleChangePassword', 1, function ($vars) {
    try {
        $domain = isset($vars['params']['domain']) ? $vars['params']['domain'] : '';
        $serviceId = isset($vars['params']['serviceid']) ? $vars['params']['serviceid'] : '';

        if (!empty($domain)) {
            logActivity("Nextcloud SaaS: Password alterada - {$domain} (Serviço #{$serviceId})");
        }
    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Hook Error (AfterModuleChangePassword): " . $e->getMessage());
    }
});

/**
 * Hook para registar alterações de pacote (upgrade/downgrade).
 */
add_hook('AfterModuleChangePackage', 1, function ($vars) {
    try {
        $domain = isset($vars['params']['domain']) ? $vars['params']['domain'] : '';
        $serviceId = isset($vars['params']['serviceid']) ? $vars['params']['serviceid'] : '';

        if (!empty($domain)) {
            $quota = isset($vars['params']['configoption1']) ? $vars['params']['configoption1'] : 'N/A';
            $maxUsers = isset($vars['params']['configoption2']) ? $vars['params']['configoption2'] : 'N/A';

            logActivity(
                "Nextcloud SaaS: Pacote alterado - {$domain} (Serviço #{$serviceId})\n"
                . "  Nova Quota: {$quota} GB | Max Utilizadores: {$maxUsers}"
            );
        }
    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Hook Error (AfterModuleChangePackage): " . $e->getMessage());
    }
});

// =============================================================================
// HOOKS DE PERSONALIZAÇÃO DO CARRINHO E CHECKOUT
// =============================================================================

/**
 * Hook: ClientAreaHeadOutput
 *
 * Injeta JavaScript na área de cliente para:
 * 1. Na página de configuração do produto (confproduct): mostrar instruções DNS
 *    junto ao Custom Field "Domínio da Instância" e validar o formato do domínio.
 * 2. Atualizar dinamicamente os exemplos DNS com o domínio digitado pelo cliente.
 *
 * v2.6.0:
 *   - IP do servidor obtido dinamicamente do Server configurado no WHMCS.
 *   - O produto usa "Require Domain = Do not allow" no WHMCS.
 *   - O domínio é capturado via Custom Field "Domínio da Instância".
 *   - Via hook AfterShoppingCartCheckout, o Custom Field é copiado para o campo Domain.
 *
 * Só afeta páginas com "nextcloud" na URL.
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {

    $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

    // Só injetar JS em páginas relevantes do carrinho/store
    $isRelevant = strpos($requestUri, '/store/') !== false ||
                  strpos($requestUri, '/cart') !== false ||
                  strpos($requestUri, 'confproduct') !== false;

    if (!$isRelevant) {
        return '';
    }

    // Obter o IP do servidor dinamicamente da tabela tblservers
    $serverIp = '0.0.0.0'; // fallback
    try {
        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            $server = \WHMCS\Database\Capsule::table('tblservers')
                ->where('type', 'nextcloudsaas')
                ->where('disabled', 0)
                ->first(['ipaddress', 'hostname']);
            if ($server) {
                $serverIp = !empty($server->ipaddress) ? $server->ipaddress : $server->hostname;
            }
        }
    } catch (\Exception $e) {
        try {
            $pdo = \WHMCS\Database\Capsule::connection()->getPdo();
            $stmt = $pdo->prepare("SELECT ipaddress, hostname FROM tblservers WHERE type = 'nextcloudsaas' AND disabled = 0 LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_OBJ);
            if ($row) {
                $serverIp = !empty($row->ipaddress) ? $row->ipaddress : $row->hostname;
            }
        } catch (\Exception $e2) {
            // Manter fallback
        }
    }

    return <<<HOOKHTML
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {

    var url = window.location.href.toLowerCase();
    if (url.indexOf('nextcloud') === -1) {
        return;
    }

    // Evitar execução duplicada
    if (document.getElementById('nextcloud-dns-info')) {
        return;
    }

    var serverIp = '{$serverIp}';

    // Encontrar o Custom Field "Domínio da Instância"
    var domainField = null;
    var domainLabel = null;
    var labels = document.querySelectorAll('label');
    for (var i = 0; i < labels.length; i++) {
        var labelText = labels[i].textContent.trim().toLowerCase();
        if (labelText.indexOf('dom') !== -1 && labelText.indexOf('inst') !== -1) {
            domainLabel = labels[i];
            var forId = labels[i].getAttribute('for');
            if (forId) {
                domainField = document.getElementById(forId);
            }
            if (!domainField) {
                var parent = labels[i].closest('.form-group, .row, .col-sm-8, .col-md-8');
                if (parent) {
                    domainField = parent.querySelector('input[type="text"]');
                }
            }
            if (!domainField) {
                var nextEl = labels[i].nextElementSibling;
                while (nextEl) {
                    if (nextEl.tagName === 'INPUT' && nextEl.type === 'text') {
                        domainField = nextEl;
                        break;
                    }
                    var innerInput = nextEl.querySelector('input[type="text"]');
                    if (innerInput) {
                        domainField = innerInput;
                        break;
                    }
                    nextEl = nextEl.nextElementSibling;
                }
            }
            break;
        }
    }

    if (!domainField) {
        var allInputs = document.querySelectorAll('input[type="text"]');
        for (var j = 0; j < allInputs.length; j++) {
            var ph = (allInputs[j].placeholder || '').toLowerCase();
            var nm = (allInputs[j].name || '').toLowerCase();
            if (ph.indexOf('dominio') !== -1 || ph.indexOf('instancia') !== -1 ||
                ph.indexOf('hostname') !== -1 || nm.indexOf('dominio') !== -1) {
                domainField = allInputs[j];
                break;
            }
        }
    }

    if (!domainField) {
        return;
    }

    // Melhorar o placeholder
    domainField.setAttribute('placeholder', 'ex: nextcloud.suaempresa.com.br');

    // Criar a secção de instruções DNS
    var dnsInfo = document.createElement('div');
    dnsInfo.id = 'nextcloud-dns-info';
    dnsInfo.style.cssText = 'margin-top: 15px; margin-bottom: 15px; padding: 12px 16px; background: #f0f7ff; border-left: 4px solid #0082c9; border-radius: 4px; font-size: 0.9em; line-height: 1.8;';
    dnsInfo.innerHTML = '<strong>Importante:</strong> Antes de prosseguir, crie 3 registros DNS apontando para o IP do servidor:<br><br>' +
        '<table style="width:100%; border-collapse:collapse; font-size:0.95em;">' +
        '<tr style="background:#e3f2fd;">' +
            '<th style="padding:6px 10px; text-align:left; border:1px solid #ccc;">Tipo</th>' +
            '<th style="padding:6px 10px; text-align:left; border:1px solid #ccc;">Nome (Host)</th>' +
            '<th style="padding:6px 10px; text-align:left; border:1px solid #ccc;">Valor (Destino)</th>' +
        '</tr>' +
        '<tr>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><strong>A</strong></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code id="dns-main">seudominio.com.br</code></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code>' + serverIp + '</code></td>' +
        '</tr>' +
        '<tr>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><strong>A</strong></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code id="dns-collabora">collabora-seudominio.com.br</code></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code>' + serverIp + '</code></td>' +
        '</tr>' +
        '<tr>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><strong>A</strong></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code id="dns-signaling">signaling-seudominio.com.br</code></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code>' + serverIp + '</code></td>' +
        '</tr>' +
        '</table>' +
        '<br><em>Ap\u00f3s a compra, o sistema verificar\u00e1 automaticamente os registros DNS a cada 5 minutos. ' +
        'Quando todos estiverem corretos, sua inst\u00e2ncia ser\u00e1 criada automaticamente e voc\u00ea receber\u00e1 um email com as credenciais de acesso.</em>' +
        '<div id="ncDomainError" style="display: none; color: #d32f2f; margin-top: 10px; font-weight: 500;"></div>';

    // Inserir após o campo de domínio
    var fieldContainer = domainField.closest('.form-group, .row');
    if (fieldContainer) {
        fieldContainer.parentNode.insertBefore(dnsInfo, fieldContainer.nextSibling);
    } else {
        domainField.parentNode.insertBefore(dnsInfo, domainField.nextSibling);
    }

    // Atualizar DNS dinamicamente quando o utilizador digita
    function updateDnsExamples() {
        var domain = domainField.value.trim().toLowerCase();
        domain = domain.replace(/^https?:\/\//, '').replace(/^www\./, '').replace(/\/+$/, '');

        var mainEl = document.getElementById('dns-main');
        var collabEl = document.getElementById('dns-collabora');
        var sigEl = document.getElementById('dns-signaling');

        if (domain && domain.indexOf('.') !== -1) {
            mainEl.textContent = domain;
            collabEl.textContent = 'collabora-' + domain;
            sigEl.textContent = 'signaling-' + domain;
        } else {
            mainEl.textContent = 'seudominio.com.br';
            collabEl.textContent = 'collabora-seudominio.com.br';
            sigEl.textContent = 'signaling-seudominio.com.br';
        }
    }

    domainField.addEventListener('input', updateDnsExamples);
    domainField.addEventListener('change', updateDnsExamples);
    domainField.addEventListener('blur', updateDnsExamples);

    // Se já tem valor preenchido, atualizar
    if (domainField.value) {
        updateDnsExamples();
    }

    // Validação no submit do formulário
    var form = domainField.closest('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            var domain = domainField.value.trim().toLowerCase();
            domain = domain.replace(/^https?:\/\//, '').replace(/^www\./, '').replace(/\/+$/, '');

            var errorEl = document.getElementById('ncDomainError');

            if (!domain) {
                e.preventDefault();
                errorEl.textContent = 'Por favor, informe o dom\u00ednio da inst\u00e2ncia Nextcloud.';
                errorEl.style.display = 'block';
                domainField.focus();
                return false;
            }

            var parts = domain.split('.');
            if (parts.length < 2) {
                e.preventDefault();
                errorEl.textContent = 'O dom\u00ednio deve ter pelo menos duas partes (ex: nextcloud.suaempresa.com.br).';
                errorEl.style.display = 'block';
                domainField.focus();
                return false;
            }

            for (var k = 0; k < parts.length; k++) {
                if (!/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/.test(parts[k])) {
                    e.preventDefault();
                    errorEl.textContent = 'O dom\u00ednio cont\u00e9m caracteres inv\u00e1lidos. Use apenas letras, n\u00fameros e h\u00edfens.';
                    errorEl.style.display = 'block';
                    domainField.focus();
                    return false;
                }
            }

            // Limpar o domínio antes de enviar
            domainField.value = domain;
            errorEl.style.display = 'none';
        });
    }
});
</script>
HOOKHTML;
});

// =============================================================================
// HOOKS DE CHECKOUT E PROVISIONAMENTO
// =============================================================================

/**
 * Hook: ShoppingCartValidateCheckout
 *
 * Valida o Custom Field "Domínio da Instância" durante o checkout.
 * Garante que o domínio tem formato válido antes de prosseguir.
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {

    $errors = [];

    // Verificar se há custom fields com domínio
    if (isset($_POST['customfield']) && is_array($_POST['customfield'])) {
        foreach ($_POST['customfield'] as $fieldId => $value) {
            $value = trim($value);
            if (empty($value)) {
                continue;
            }

            // Verificar se este campo é o "Domínio da Instância" de um produto nextcloudsaas
            try {
                if (class_exists('\\WHMCS\\Database\\Capsule')) {
                    $field = \WHMCS\Database\Capsule::table('tblcustomfields')
                        ->where('id', $fieldId)
                        ->where('fieldname', 'LIKE', '%Domínio%Instância%')
                        ->first();

                    if ($field) {
                        // Limpar o domínio
                        $domain = strtolower($value);
                        $domain = preg_replace('#^https?://#', '', $domain);
                        $domain = preg_replace('#^www\.#', '', $domain);
                        $domain = rtrim($domain, '/');

                        // Validar formato
                        $parts = explode('.', $domain);
                        if (count($parts) < 2) {
                            $errors[] = 'O domínio da instância deve ter pelo menos duas partes (ex: nextcloud.suaempresa.com.br).';
                            continue;
                        }

                        foreach ($parts as $part) {
                            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $part)) {
                                $errors[] = 'O domínio da instância contém caracteres inválidos. Use apenas letras, números e hífens.';
                                break;
                            }
                        }

                        // Atualizar o valor limpo
                        $_POST['customfield'][$fieldId] = $domain;
                    }
                }
            } catch (\Exception $e) {
                // Silenciar
            }
        }
    }

    return $errors;
});

/**
 * Hook: AfterShoppingCartCheckout
 *
 * Após o checkout, copia o valor do Custom Field "Domínio da Instância"
 * para o campo Domain (tblhosting.domain) do serviço.
 * Também define o username como 'admin' automaticamente.
 * Grava a data de criação do pedido para controle de timeout DNS (3 dias).
 *
 * Isto garante que $params['domain'] funciona corretamente em todas
 * as funções do módulo (CreateAccount, ChangePassword, etc.).
 */
add_hook('AfterShoppingCartCheckout', 1, function ($vars) {
    try {
        $orderId = isset($vars['OrderID']) ? $vars['OrderID'] : '';
        if (empty($orderId)) {
            return;
        }

        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            return;
        }

        // Obter os serviços associados a esta ordem
        $orderItems = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('orderid', $orderId)
            ->get();

        foreach ($orderItems as $item) {
            // Verificar se é um produto nextcloudsaas
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $item->packageid)
                ->where('servertype', 'nextcloudsaas')
                ->first();

            if (!$product) {
                continue;
            }

            // Procurar o Custom Field "Domínio da Instância"
            $customField = \WHMCS\Database\Capsule::table('tblcustomfields')
                ->where('relid', $product->id)
                ->where('type', 'product')
                ->where('fieldname', 'LIKE', '%Domínio%Instância%')
                ->first();

            if (!$customField) {
                // Tentar sem acentos
                $customField = \WHMCS\Database\Capsule::table('tblcustomfields')
                    ->where('relid', $product->id)
                    ->where('type', 'product')
                    ->where('fieldname', 'LIKE', '%Dominio%Instancia%')
                    ->first();
            }

            if (!$customField) {
                logActivity("Nextcloud SaaS Hook: Custom Field 'Domínio da Instância' não encontrado para produto #{$product->id}");
                continue;
            }

            // Obter o valor do Custom Field para este serviço
            $fieldValue = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $customField->id)
                ->where('relid', $item->id)
                ->first();

            if (!$fieldValue || empty($fieldValue->value)) {
                logActivity("Nextcloud SaaS Hook: Custom Field 'Domínio da Instância' vazio para serviço #{$item->id}");
                continue;
            }

            $domain = strtolower(trim($fieldValue->value));
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = preg_replace('#^www\.#', '', $domain);
            $domain = rtrim($domain, '/');

            // Atualizar o campo domain do serviço
            $updates = ['domain' => $domain];

            // Também definir o username como 'admin' automaticamente
            if (empty($item->username) || $item->username !== 'admin') {
                $updates['username'] = 'admin';
            }

            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $item->id)
                ->update($updates);

            // Gravar a data do pedido para controle de timeout DNS (3 dias)
            // Usa tblhosting.notes para armazenar metadados do módulo
            $notes = !empty($item->notes) ? $item->notes : '';
            $timestamp = date('Y-m-d H:i:s');
            $dnsNote = "\n[NextcloudSaaS] dns_check_start={$timestamp}";
            if (strpos($notes, '[NextcloudSaaS] dns_check_start=') === false) {
                \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('id', $item->id)
                    ->update(['notes' => $notes . $dnsNote]);
            }

            logActivity(
                "Nextcloud SaaS Hook: Domínio definido como '{$domain}' para serviço #{$item->id}\n"
                . "  Username definido como 'admin'\n"
                . "  Verificação DNS automática iniciada em {$timestamp}"
            );
        }

    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Hook Error (AfterShoppingCartCheckout): " . $e->getMessage());
    }
});

// =============================================================================
// HOOK DE CRON: VERIFICAÇÃO AUTOMÁTICA DE DNS E PROVISIONAMENTO
// =============================================================================

/**
 * Hook: AfterCronJob
 *
 * Este hook é executado a cada vez que o cron do WHMCS roda.
 * Se o cron estiver configurado para rodar a cada 5 minutos
 * (recomendado), este hook executará a cada 5 minutos.
 *
 * Fluxo:
 * 1. Busca todos os serviços "Pending" do módulo nextcloudsaas que têm domínio definido
 * 2. Para cada serviço, obtém o IP do servidor associado (tblservers)
 * 3. Verifica se os 3 registros DNS (principal, collabora, signaling) apontam para o IP correto
 * 4. Se DNS OK → executa Module Create (provisiona a instância) e envia email ao cliente
 * 5. Se DNS não OK e passaram mais de 3 dias → para de verificar e notifica o admin
 *
 * v2.6.0: Implementação inicial
 */
add_hook('AfterCronJob', 1, function ($vars) {
    try {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            return;
        }

        // Buscar todos os serviços Pending do módulo nextcloudsaas
        $pendingServices = \WHMCS\Database\Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
            ->where('p.servertype', 'nextcloudsaas')
            ->where('h.domainstatus', 'Pending')
            ->whereNotNull('h.domain')
            ->where('h.domain', '!=', '')
            ->select([
                'h.id as service_id',
                'h.domain',
                'h.server as server_id',
                'h.userid as client_id',
                'h.packageid as product_id',
                'h.notes',
                'h.orderid',
            ])
            ->get();

        if (empty($pendingServices) || count($pendingServices) === 0) {
            return;
        }

        logActivity("Nextcloud SaaS Cron: Verificando DNS de " . count($pendingServices) . " serviço(s) pendente(s)...");

        foreach ($pendingServices as $service) {
            try {
                nextcloudsaas_cronProcessPendingService($service);
            } catch (\Exception $e) {
                logActivity("Nextcloud SaaS Cron Error (Serviço #{$service->service_id}): " . $e->getMessage());
            }
        }

    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Cron Error (AfterCronJob): " . $e->getMessage());
    }
});

/**
 * Processar um serviço pendente: verificar DNS e provisionar se OK.
 *
 * @param object $service Dados do serviço (de tblhosting + tblproducts)
 */
function nextcloudsaas_cronProcessPendingService($service)
{
    $serviceId = $service->service_id;
    $domain    = $service->domain;
    $serverId  = $service->server_id;
    $clientId  = $service->client_id;
    $notes     = $service->notes;

    // ── 1. Verificar timeout de 3 dias ──────────────────────────────────
    $dnsCheckStart = null;
    if (preg_match('/\[NextcloudSaaS\] dns_check_start=(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $notes, $matches)) {
        $dnsCheckStart = $matches[1];
    }

    // Se não tem data de início, definir agora
    if (empty($dnsCheckStart)) {
        $dnsCheckStart = date('Y-m-d H:i:s');
        $updatedNotes = $notes . "\n[NextcloudSaaS] dns_check_start={$dnsCheckStart}";
        \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update(['notes' => $updatedNotes]);
        $notes = $updatedNotes;
    }

    // Verificar se já expirou (3 dias = 259200 segundos)
    $startTimestamp = strtotime($dnsCheckStart);
    $elapsed = time() - $startTimestamp;
    $timeoutSeconds = 3 * 24 * 60 * 60; // 3 dias

    // Verificar se já foi marcado como expirado
    if (strpos($notes, '[NextcloudSaaS] dns_expired=true') !== false) {
        return; // Já expirou, não verificar mais
    }

    if ($elapsed > $timeoutSeconds) {
        // Timeout! Marcar como expirado e notificar admin
        $updatedNotes = $notes . "\n[NextcloudSaaS] dns_expired=true";
        \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update(['notes' => $updatedNotes]);

        // Obter IP do servidor para a mensagem
        $serverIp = nextcloudsaas_getServerIpForService($serverId);

        logActivity(
            "Nextcloud SaaS Cron: TIMEOUT DNS - Serviço #{$serviceId} ({$domain})\n"
            . "  O cliente não configurou os registros DNS em 3 dias.\n"
            . "  Registros esperados apontando para {$serverIp}:\n"
            . "    - {$domain}\n"
            . "    - collabora-{$domain}\n"
            . "    - signaling-{$domain}\n"
            . "  Verificação automática ENCERRADA. Ação manual necessária."
        );

        // Enviar notificação ao admin
        nextcloudsaas_notifyAdminDnsTimeout($serviceId, $domain, $clientId, $serverIp);
        return;
    }

    // ── 2. Obter IP do servidor associado ao serviço ────────────────────
    $serverIp = nextcloudsaas_getServerIpForService($serverId);

    if (empty($serverIp) || $serverIp === '0.0.0.0') {
        logActivity("Nextcloud SaaS Cron: Serviço #{$serviceId} - Servidor não configurado ou sem IP.");
        return;
    }

    // ── 3. Verificar DNS ────────────────────────────────────────────────
    $dnsCheck = \NextcloudSaaS\Helper::checkDnsRecords($domain, $serverIp);

    if (!$dnsCheck['all_ok']) {
        // DNS ainda não está correto — logar e aguardar próxima execução
        $elapsedHours = round($elapsed / 3600, 1);
        logActivity(
            "Nextcloud SaaS Cron: DNS pendente - Serviço #{$serviceId} ({$domain}) - "
            . "{$elapsedHours}h desde o pedido - {$dnsCheck['message']}"
        );
        return;
    }

    // ── 4. DNS OK! Provisionar a instância automaticamente ──────────────
    logActivity(
        "Nextcloud SaaS Cron: DNS CONFIRMADO - Serviço #{$serviceId} ({$domain})\n"
        . "  Todos os 3 registros DNS apontam para {$serverIp}.\n"
        . "  Iniciando provisionamento automático..."
    );

    // Executar o Module Create via localAPI
    $createResult = localAPI('ModuleCreate', [
        'serviceid' => $serviceId,
    ]);

    if ($createResult['result'] === 'success') {
        logActivity(
            "Nextcloud SaaS Cron: PROVISIONAMENTO CONCLUÍDO - Serviço #{$serviceId} ({$domain})\n"
            . "  Instância criada com sucesso via provisionamento automático."
        );

        // Alterar status do serviço de Pending para Active
        \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update(['domainstatus' => 'Active']);

        logActivity("Nextcloud SaaS Cron: Status do Serviço #{$serviceId} alterado para Active.");

        // Marcar nas notas que foi provisionado automaticamente
        $updatedNotes = $notes . "\n[NextcloudSaaS] auto_provisioned=" . date('Y-m-d H:i:s');
        \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update(['notes' => $updatedNotes]);

        // Enviar email ao cliente com as credenciais
        nextcloudsaas_sendProvisioningEmail($serviceId, $domain, $clientId, $serverIp);

    } else {
        $errorMsg = isset($createResult['message']) ? $createResult['message'] : 'Erro desconhecido';
        logActivity(
            "Nextcloud SaaS Cron: ERRO NO PROVISIONAMENTO - Serviço #{$serviceId} ({$domain})\n"
            . "  Erro: {$errorMsg}\n"
            . "  O sistema tentará novamente na próxima execução do cron."
        );
    }
}

/**
 * Obter o IP do servidor associado a um serviço.
 *
 * O IP vem da tabela tblservers, vinculado ao serviço via tblhosting.server.
 * Se o serviço não tem servidor atribuído, busca o primeiro servidor
 * do tipo nextcloudsaas ativo.
 *
 * @param int $serverId ID do servidor (tblhosting.server)
 * @return string IP do servidor
 */
function nextcloudsaas_getServerIpForService($serverId)
{
    $serverIp = '';

    try {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            return '';
        }

        if (!empty($serverId) && $serverId > 0) {
            // Buscar pelo ID do servidor atribuído ao serviço
            $server = \WHMCS\Database\Capsule::table('tblservers')
                ->where('id', $serverId)
                ->first(['ipaddress', 'hostname']);

            if ($server) {
                $serverIp = !empty($server->ipaddress) ? $server->ipaddress : $server->hostname;
            }
        }

        // Fallback: buscar o primeiro servidor nextcloudsaas ativo
        if (empty($serverIp)) {
            $server = \WHMCS\Database\Capsule::table('tblservers')
                ->where('type', 'nextcloudsaas')
                ->where('disabled', 0)
                ->first(['ipaddress', 'hostname']);

            if ($server) {
                $serverIp = !empty($server->ipaddress) ? $server->ipaddress : $server->hostname;
            }
        }
    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS: Erro ao obter IP do servidor: " . $e->getMessage());
    }

    return $serverIp;
}

/**
 * Enviar email ao cliente com as credenciais da instância provisionada.
 *
 * Usa a API SendEmail do WHMCS para enviar um email personalizado.
 * Se existir um template "Nextcloud SaaS - Instância Pronta", usa-o.
 * Caso contrário, envia um email genérico via SendAdminEmail como fallback.
 *
 * @param int    $serviceId ID do serviço
 * @param string $domain    Domínio da instância
 * @param int    $clientId  ID do cliente
 * @param string $serverIp  IP do servidor
 */
function nextcloudsaas_sendProvisioningEmail($serviceId, $domain, $clientId, $serverIp)
{
    try {
        if (!function_exists('localAPI')) {
            return;
        }

        // Obter dados do serviço (password gerada pelo CreateAccount)
        $serviceData = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->first(['username', 'password']);

        $username = !empty($serviceData->username) ? $serviceData->username : 'admin';

        // Descriptografar a password armazenada no WHMCS
        $password = '';
        try {
            $decrypted = localAPI('DecryptPassword', ['password2' => $serviceData->password]);
            if (isset($decrypted['password'])) {
                $password = $decrypted['password'];
            }
        } catch (\Exception $e) {
            $password = '(consulte o painel WHMCS)';
        }

        $collaboraDomain = 'collabora-' . $domain;
        $signalingDomain = 'signaling-' . $domain;

        // Obter dados do cliente
        $client = \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', $clientId)
            ->first(['firstname', 'lastname', 'email']);

        if (!$client) {
            logActivity("Nextcloud SaaS Cron: Cliente #{$clientId} não encontrado para envio de email.");
            return;
        }

        $clientName = trim($client->firstname . ' ' . $client->lastname);

        // Construir o corpo do email em HTML
        $emailBody = nextcloudsaas_buildProvisioningEmailHtml(
            $clientName, $domain, $username, $password,
            $collaboraDomain, $signalingDomain, $serverIp
        );

        // Tentar enviar via template customizado do WHMCS
        $emailResult = localAPI('SendEmail', [
            'messagename' => 'Nextcloud SaaS - Instância Pronta',
            'id'          => $serviceId,
            'customtype'  => 'product',
            'customvars'  => base64_encode(serialize([
                'nextcloud_url'      => 'https://' . $domain,
                'nextcloud_user'     => $username,
                'nextcloud_pass'     => $password,
                'collabora_url'      => 'https://' . $collaboraDomain,
                'signaling_url'      => 'https://' . $signalingDomain,
                'server_ip'          => $serverIp,
                'client_name'        => $clientName,
            ])),
        ]);

        // Se o template não existe, enviar email direto
        if ($emailResult['result'] !== 'success') {
            logActivity("Nextcloud SaaS Cron: Template de email não encontrado, enviando email direto...");

            $subject = 'Sua instância Nextcloud está pronta! - ' . $domain;

            // Usar a API do WHMCS para enviar email direto ao cliente
            localAPI('SendEmail', [
                'customtype'    => 'product',
                'customsubject' => $subject,
                'custommessage' => $emailBody,
                'id'            => $serviceId,
            ]);
        }

        logActivity("Nextcloud SaaS Cron: Email de provisionamento enviado para {$client->email} (Serviço #{$serviceId})");

    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Cron Error (sendProvisioningEmail): " . $e->getMessage());
    }
}

/**
 * Construir o HTML do email de provisionamento.
 *
 * @param string $clientName      Nome do cliente
 * @param string $domain          Domínio principal
 * @param string $username        Usuário admin
 * @param string $password        Password do admin
 * @param string $collaboraDomain Domínio do Collabora
 * @param string $signalingDomain Domínio do Signaling
 * @param string $serverIp        IP do servidor
 * @return string HTML do email
 */
function nextcloudsaas_buildProvisioningEmailHtml($clientName, $domain, $username, $password, $collaboraDomain, $signalingDomain, $serverIp)
{
    $e = function($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); };

    return '
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;">
    <div style="background: #0082c9; color: #fff; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="margin: 0; font-size: 22px;">Sua Instância Nextcloud Está Pronta!</h1>
    </div>

    <div style="padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-top: none;">
        <p>Olá <strong>' . $e($clientName) . '</strong>,</p>

        <p>Sua instância Nextcloud foi criada com sucesso e já está disponível para uso.
        Abaixo estão as informações de acesso:</p>

        <div style="background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 15px; margin: 15px 0;">
            <h3 style="color: #0082c9; margin-top: 0; border-bottom: 2px solid #0082c9; padding-bottom: 8px;">
                Dados de Acesso
            </h3>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; width: 140px;">URL de Acesso:</td>
                    <td style="padding: 8px 0;"><a href="https://' . $e($domain) . '" style="color: #0082c9;">https://' . $e($domain) . '</a></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold;">Usuário:</td>
                    <td style="padding: 8px 0;"><code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">' . $e($username) . '</code></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold;">Senha:</td>
                    <td style="padding: 8px 0;"><code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px;">' . $e($password) . '</code></td>
                </tr>
            </table>
        </div>

        <div style="background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 15px; margin: 15px 0;">
            <h3 style="color: #1b6a37; margin-top: 0; border-bottom: 2px solid #1b6a37; padding-bottom: 8px;">
                Serviços Incluídos
            </h3>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; width: 140px;">Nextcloud:</td>
                    <td style="padding: 8px 0;"><a href="https://' . $e($domain) . '" style="color: #0082c9;">https://' . $e($domain) . '</a></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold;">Collabora Online:</td>
                    <td style="padding: 8px 0;"><a href="https://' . $e($collaboraDomain) . '" style="color: #0082c9;">https://' . $e($collaboraDomain) . '</a></td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold;">Talk (HPB):</td>
                    <td style="padding: 8px 0;"><a href="https://' . $e($signalingDomain) . '" style="color: #0082c9;">https://' . $e($signalingDomain) . '</a></td>
                </tr>
            </table>
        </div>

        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 12px; margin: 15px 0;">
            <strong>Importante:</strong> Recomendamos que você altere a senha padrão no primeiro acesso.
            Acesse <em>Configurações &gt; Segurança</em> no painel do Nextcloud.
        </div>

        <div style="background: #e8f5e9; border: 1px solid #4caf50; border-radius: 6px; padding: 12px; margin: 15px 0;">
            <strong>Registros DNS configurados:</strong><br>
            Os seguintes registros DNS estão apontando corretamente para o servidor (<code>' . $e($serverIp) . '</code>):<br>
            <code>' . $e($domain) . '</code><br>
            <code>' . $e($collaboraDomain) . '</code><br>
            <code>' . $e($signalingDomain) . '</code>
        </div>

        <p style="color: #666; font-size: 0.9em; margin-top: 20px;">
            Se precisar de ajuda, entre em contato com nosso suporte técnico.
        </p>
    </div>

    <div style="background: #333; color: #aaa; padding: 12px; text-align: center; font-size: 0.8em; border-radius: 0 0 8px 8px;">
        Nextcloud SaaS - Provisionamento Automático
    </div>
</div>';
}

/**
 * Notificar o administrador sobre timeout de DNS (3 dias sem configuração).
 *
 * @param int    $serviceId ID do serviço
 * @param string $domain    Domínio da instância
 * @param int    $clientId  ID do cliente
 * @param string $serverIp  IP do servidor
 */
function nextcloudsaas_notifyAdminDnsTimeout($serviceId, $domain, $clientId, $serverIp)
{
    try {
        if (!function_exists('localAPI')) {
            return;
        }

        // Obter dados do cliente
        $client = \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', $clientId)
            ->first(['firstname', 'lastname', 'email', 'companyname']);

        $clientName = '';
        $clientEmail = '';
        if ($client) {
            $clientName = trim($client->firstname . ' ' . $client->lastname);
            if (!empty($client->companyname)) {
                $clientName .= ' (' . $client->companyname . ')';
            }
            $clientEmail = $client->email;
        }

        $collaboraDomain = 'collabora-' . $domain;
        $signalingDomain = 'signaling-' . $domain;

        $subject = "[Nextcloud SaaS] Timeout DNS - Serviço #{$serviceId} ({$domain})";

        $message = '
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #e74c3c; color: #fff; padding: 15px; border-radius: 8px 8px 0 0;">
        <h2 style="margin: 0;">Timeout de Verificação DNS</h2>
    </div>
    <div style="padding: 20px; background: #fff; border: 1px solid #ddd; border-top: none; border-radius: 0 0 8px 8px;">
        <p>O cliente não configurou os registros DNS dentro do prazo de 3 dias.</p>

        <table style="width: 100%; border-collapse: collapse; margin: 15px 0;">
            <tr><td style="padding: 6px 0; font-weight: bold;">Serviço:</td><td>#' . $serviceId . '</td></tr>
            <tr><td style="padding: 6px 0; font-weight: bold;">Domínio:</td><td>' . htmlspecialchars($domain) . '</td></tr>
            <tr><td style="padding: 6px 0; font-weight: bold;">Cliente:</td><td>' . htmlspecialchars($clientName) . ' (' . htmlspecialchars($clientEmail) . ')</td></tr>
            <tr><td style="padding: 6px 0; font-weight: bold;">IP do Servidor:</td><td>' . htmlspecialchars($serverIp) . '</td></tr>
        </table>

        <p><strong>Registros DNS necessários:</strong></p>
        <table style="width: 100%; border-collapse: collapse; border: 1px solid #ddd;">
            <tr style="background: #f5f5f5;">
                <th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Tipo</th>
                <th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Host</th>
                <th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Valor</th>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;">A</td>
                <td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($domain) . '</td>
                <td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($serverIp) . '</td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;">A</td>
                <td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($collaboraDomain) . '</td>
                <td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($serverIp) . '</td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;">A</td>
                <td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($signalingDomain) . '</td>
                <td style="padding: 8px; border: 1px solid #ddd;">' . htmlspecialchars($serverIp) . '</td>
            </tr>
        </table>

        <p style="margin-top: 15px;"><strong>Ação necessária:</strong> Entre em contato com o cliente para verificar a configuração DNS,
        ou provisione manualmente a instância após confirmar os registros DNS.</p>
    </div>
</div>';

        // Enviar email para todos os admins
        $admins = \WHMCS\Database\Capsule::table('tbladmins')
            ->where('disabled', 0)
            ->get(['id', 'email']);

        foreach ($admins as $admin) {
            localAPI('SendAdminEmail', [
                'customsubject' => $subject,
                'custommessage' => $message,
                'type'          => 'system',
                'deptid'        => 0,
                'mergefields'   => [],
            ]);
            break; // Enviar apenas uma vez (SendAdminEmail envia para todos os admins)
        }

    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Cron Error (notifyAdminDnsTimeout): " . $e->getMessage());
    }
}
