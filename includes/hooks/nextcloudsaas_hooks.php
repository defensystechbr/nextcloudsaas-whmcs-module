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
 * @version    2.4.1
 */

if (!defined("WHMCS")) {
    die("Este ficheiro não pode ser acedido diretamente.");
}

/**
 * Hook executado após a criação bem-sucedida de um serviço.
 *
 * Regista no log de atividades do WHMCS a criação da instância
 * com os 3 domínios DNS configurados.
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
 * O JavaScript:
 *   1. Detecta se estamos na página de configuração de domínio do carrinho
 *   2. Verifica se o produto é Nextcloud (via slug na URL)
 *   3. Remove o prefixo "www." e o campo de TLD
 *   4. Substitui por um campo único com placeholder informativo
 *   5. Adiciona instruções sobre os registros DNS necessários
 *
 * Só afeta produtos com "nextcloud" na URL do carrinho.
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {

    // Só executar na página do carrinho
    if ($vars['filename'] !== 'cart') {
        return '';
    }

    return <<<'HOOKHTML'
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {

    // Verificar se estamos na página de um produto Nextcloud
    var url = window.location.href.toLowerCase();
    if (url.indexOf('nextcloud') === -1) {
        return;
    }

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

    // Encontrar os elementos do formulário
    var sldInput = domainGroup.querySelector('input[type="text"]');
    var tldElements = domainGroup.querySelectorAll('select, .input-group-addon:not(:first-child), .input-group-text:not(:first-child)');

    // Esconder o "www."
    if (wwwAddon) {
        wwwAddon.style.display = 'none';
    }

    // Esconder o campo de TLD e addons extras
    for (var j = 0; j < tldElements.length; j++) {
        var el = tldElements[j];
        if (el !== wwwAddon && el !== sldInput) {
            el.style.display = 'none';
            // Esconder também o parent se for input-group-append
            if (el.parentElement && el.parentElement.classList.contains('input-group-append')) {
                el.parentElement.style.display = 'none';
            }
        }
    }

    // Ajustar o campo de input
    if (sldInput) {
        sldInput.setAttribute('placeholder', 'ex: nextcloud.suaempresa.com.br');
        sldInput.style.borderRadius = '4px';
    }

    // Alterar o título da secção
    var headings = document.querySelectorAll('h2, h3, .header-lined, .sub-heading');
    for (var k = 0; k < headings.length; k++) {
        var txt = headings[k].textContent.toLowerCase();
        if (txt.indexOf('domínio') !== -1 || txt.indexOf('dominio') !== -1 || txt.indexOf('domain') !== -1) {
            headings[k].textContent = 'Informe o Domínio da Instância Nextcloud';
            break;
        }
    }

    // Adicionar instruções sobre DNS
    var parentContainer = domainGroup.parentElement;
    if (parentContainer) {
        var dnsInfo = document.createElement('div');
        dnsInfo.style.cssText = 'margin-top: 15px; padding: 12px 16px; background: #f0f7ff; border-left: 4px solid #0082c9; border-radius: 4px; font-size: 0.9em; line-height: 1.6;';
        dnsInfo.innerHTML = '<strong>Importante:</strong> Antes de prosseguir, crie 3 registros DNS do tipo A apontando para o IP do servidor:' +
            '<br><code style="background:#e8e8e8; padding:2px 6px; border-radius:3px; margin:2px 0; display:inline-block;">seudominio.com.br</code>' +
            '<br><code style="background:#e8e8e8; padding:2px 6px; border-radius:3px; margin:2px 0; display:inline-block;">collabora-seudominio.com.br</code>' +
            '<br><code style="background:#e8e8e8; padding:2px 6px; border-radius:3px; margin:2px 0; display:inline-block;">signaling-seudominio.com.br</code>';
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
 */
add_hook('ShoppingCartValidateDomain', 1, function ($vars) {

    if (isset($_POST['sld']) && strpos($_POST['sld'], 'www.') === 0) {
        $_POST['sld'] = substr($_POST['sld'], 4);
    }

    return [];
});
