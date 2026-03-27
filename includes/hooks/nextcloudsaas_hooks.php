<?php
/**
 * Hooks do módulo Nextcloud-SaaS para WHMCS
 *
 * Define hooks que se integram com eventos do WHMCS para
 * executar ações adicionais durante o ciclo de vida do produto.
 * Integrado com o manage.sh v10.0 e a arquitetura de 10 containers.
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    2.5.4
 */

if (!defined("WHMCS")) {
    die("Este ficheiro não pode ser acedido diretamente.");
}

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
 * v2.5.4:
 *   - Removida toda a manipulação do formulário de domínio SLD/TLD do WHMCS
 *   - O produto usa "Require Domain = Do not allow" no WHMCS
 *   - O domínio é capturado via Custom Field "Domínio da Instância"
 *   - Via hook AfterShoppingCartCheckout, o Custom Field é copiado para o campo Domain
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
    // O WHMCS renderiza custom fields com labels que contêm o nome do campo
    var domainField = null;
    var domainLabel = null;
    var labels = document.querySelectorAll('label');
    for (var i = 0; i < labels.length; i++) {
        var labelText = labels[i].textContent.trim().toLowerCase();
        if (labelText.indexOf('dom') !== -1 && labelText.indexOf('inst') !== -1) {
            domainLabel = labels[i];
            // O input está associado via 'for' ou é o próximo input no mesmo form-group
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
                // Tentar o próximo input após o label
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
        // Fallback: procurar por qualquer input com placeholder ou name que contenha "dominio" ou "instancia"
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
                errorEl.textContent = 'Por favor, informe o domínio da instância Nextcloud.';
                errorEl.style.display = 'block';
                domainField.focus();
                return false;
            }

            var parts = domain.split('.');
            if (parts.length < 2) {
                e.preventDefault();
                errorEl.textContent = 'O domínio deve ter pelo menos duas partes (ex: nextcloud.suaempresa.com.br).';
                errorEl.style.display = 'block';
                domainField.focus();
                return false;
            }

            for (var k = 0; k < parts.length; k++) {
                if (!/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/.test(parts[k])) {
                    e.preventDefault();
                    errorEl.textContent = 'O domínio contém caracteres inválidos. Use apenas letras, números e hífens.';
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

            logActivity(
                "Nextcloud SaaS Hook: Domínio definido como '{$domain}' para serviço #{$item->id}\n"
                . "  Username definido como 'admin'"
            );
        }

    } catch (\Exception $e) {
        logActivity("Nextcloud SaaS Hook Error (AfterShoppingCartCheckout): " . $e->getMessage());
    }
});
