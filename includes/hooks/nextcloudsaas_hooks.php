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
 * @version    2.4.2
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
// HOOKS DE PERSONALIZAÇÃO DA TELA DE DOMÍNIO NO CARRINHO
// =============================================================================

/**
 * Hook: ClientAreaHeadOutput
 *
 * Injeta JavaScript na área de cliente para personalizar a tela de domínio
 * quando o produto no carrinho usa o módulo nextcloudsaas.
 *
 * Correções v2.4.2:
 *   - Campo TLD completamente escondido (incluindo wrappers)
 *   - Mensagem DNS aparece apenas uma vez (ID único para evitar duplicação)
 *   - Mensagem DNS inclui tipo de registro (A) e IP do servidor em tabela
 *
 * Só afeta produtos com "nextcloud" na URL do carrinho.
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {

    $allowedPages = ['cart', 'store', 'configureproduct'];
    $filename = isset($vars['filename']) ? $vars['filename'] : '';
    $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    
    $isCartPage = in_array($filename, $allowedPages) || 
                  strpos($requestUri, '/store/') !== false || 
                  strpos($requestUri, '/cart/') !== false;
    
    if (!$isCartPage) {
        return '';
    }

    $serverIp = '200.50.151.21';

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

    // Procurar o campo de domínio com prefixo "www."
    var allAddons = document.querySelectorAll('.input-group-addon, .input-group-text');
    var wwwAddon = null;
    var domainGroup = null;

    for (var i = 0; i < allAddons.length; i++) {
        if (allAddons[i].textContent.trim() === 'www.') {
            wwwAddon = allAddons[i];
            domainGroup = allAddons[i].closest('.input-group');
            break;
        }
    }

    if (!domainGroup) {
        return;
    }

    // Encontrar o campo de input do domínio (SLD)
    var sldInput = document.getElementById('owndomainsld') || domainGroup.querySelector('input[type="text"]');

    // 1. Esconder TUDO dentro do input-group, exceto o campo de input principal
    var children = domainGroup.querySelectorAll('*');
    for (var j = 0; j < children.length; j++) {
        var child = children[j];
        if (child === sldInput) continue;
        if (child.contains && child.contains(sldInput)) continue;
        
        // Esconder addons, selects, e wrappers de TLD
        if (child.classList.contains('input-group-addon') ||
            child.classList.contains('input-group-text') ||
            child.classList.contains('input-group-prepend') ||
            child.classList.contains('input-group-append') ||
            child.tagName === 'SELECT') {
            child.style.display = 'none';
        }
    }

    // 2. Ajustar o campo de input
    if (sldInput) {
        sldInput.setAttribute('placeholder', 'ex: nextcloud.suaempresa.com.br');
        sldInput.style.borderRadius = '4px';
        sldInput.style.width = '100%';
    }

    // 3. Alterar o título da secção
    var headings = document.querySelectorAll('h2, h3, .header-lined, .sub-heading');
    for (var k = 0; k < headings.length; k++) {
        var txt = headings[k].textContent.toLowerCase();
        if (txt.indexOf('dom') !== -1) {
            headings[k].textContent = 'Informe o Domínio da Instância Nextcloud';
            break;
        }
    }

    // 4. Adicionar instruções sobre DNS (apenas uma vez, com ID único)
    var parentContainer = domainGroup.parentElement;
    if (parentContainer) {
        var dnsInfo = document.createElement('div');
        dnsInfo.id = 'nextcloud-dns-info';
        dnsInfo.style.cssText = 'margin-top: 15px; padding: 12px 16px; background: #f0f7ff; border-left: 4px solid #0082c9; border-radius: 4px; font-size: 0.9em; line-height: 1.8;';
        dnsInfo.innerHTML = '<strong>Importante:</strong> Antes de prosseguir, crie 3 registros DNS apontando para o IP do servidor:<br><br>' +
            '<table style="width:100%; border-collapse:collapse; font-size:0.95em;">' +
            '<tr style="background:#e3f2fd;">' +
            '<th style="padding:6px 10px; text-align:left; border:1px solid #ccc;">Tipo</th>' +
            '<th style="padding:6px 10px; text-align:left; border:1px solid #ccc;">Nome (Host)</th>' +
            '<th style="padding:6px 10px; text-align:left; border:1px solid #ccc;">Valor (Destino)</th>' +
            '</tr>' +
            '<tr>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><strong>A</strong></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code>seudominio.com.br</code></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code>' + serverIp + '</code></td>' +
            '</tr>' +
            '<tr>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><strong>A</strong></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code>collabora-seudominio.com.br</code></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code>' + serverIp + '</code></td>' +
            '</tr>' +
            '<tr>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><strong>A</strong></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code>signaling-seudominio.com.br</code></td>' +
            '<td style="padding:6px 10px; border:1px solid #ccc;"><code>' + serverIp + '</code></td>' +
            '</tr>' +
            '</table>';
        parentContainer.appendChild(dnsInfo);
    }
});
</script>
HOOKHTML;
});

/**
 * Hook: ShoppingCartValidateDomain
 *
 * Remove automaticamente o prefixo "www." se o cliente o incluir
 * no campo de domínio ao encomendar um produto Nextcloud.
 * Também limpa o TLD caso o cliente tenha digitado o domínio completo no SLD.
 */
add_hook('ShoppingCartValidateDomain', 1, function ($vars) {

    // Remover www. se presente
    if (isset($_POST['sld']) && strpos($_POST['sld'], 'www.') === 0) {
        $_POST['sld'] = substr($_POST['sld'], 4);
    }

    // Se o cliente digitou o domínio completo no campo SLD (ex: nextcloud.empresa.com.br)
    // e o TLD ficou com valor padrão, limpar o TLD para não duplicar
    if (isset($_POST['sld']) && substr_count($_POST['sld'], '.') >= 2) {
        $_POST['tld'] = '';
    }

    return [];
});
