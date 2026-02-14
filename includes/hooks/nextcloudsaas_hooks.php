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
 * @version    2.4.5
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
 * v2.4.5:
 *   - IP do servidor obtido dinamicamente da tabela tblservers (módulo nextcloudsaas)
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
        // Se falhar, tenta via PDO direto
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

    // Encontrar os campos de domínio pelo ID
    var sldInput = document.getElementById('owndomainsld');
    var tldInput = document.getElementById('owndomaintld');

    if (!sldInput) {
        return;
    }

    // 1. Esconder o prefixo "www." — procurar addon dentro do input-group do SLD
    var sldGroup = sldInput.closest('.input-group');
    if (sldGroup) {
        var addons = sldGroup.querySelectorAll('.input-group-addon, .input-group-text, .input-group-prepend');
        for (var i = 0; i < addons.length; i++) {
            if (addons[i].textContent.trim() === 'www.' || addons[i].querySelector && addons[i].querySelector('.input-group-text')) {
                addons[i].style.display = 'none';
            }
        }
    }

    // 2. Esconder o campo TLD completamente (input + div pai)
    if (tldInput) {
        var tldContainer = tldInput.closest('.col-xs-3, .col-3, .col-sm-3, .col-md-3');
        if (tldContainer) {
            tldContainer.style.display = 'none';
        } else {
            tldInput.parentElement.style.display = 'none';
        }
    }

    // 3. Expandir o campo SLD para ocupar o espaço do TLD
    var sldContainer = sldInput.closest('.col-xs-5, .col-5, .col-sm-5, .col-md-5, .col-xs-6, .col-6');
    if (sldContainer) {
        sldContainer.className = sldContainer.className
            .replace(/col-(xs-|sm-|md-|lg-|xl-)?[0-9]+/g, '')
            .trim();
        sldContainer.classList.add('col-8');
    }
    sldInput.setAttribute('placeholder', 'ex: nextcloud.suaempresa.com.br');
    sldInput.style.borderRadius = '4px';

    // 4. Alterar o título da secção
    var headings = document.querySelectorAll('h2, h3, .header-lined, .sub-heading');
    for (var k = 0; k < headings.length; k++) {
        var txt = headings[k].textContent.toLowerCase();
        if (txt.indexOf('dom') !== -1) {
            headings[k].textContent = 'Informe o Domínio da Instância Nextcloud';
            break;
        }
    }

    // 5. Interceptar o submit para separar SLD e TLD automaticamente
    var form = document.getElementById('frmProductDomain');
    var submitBtn = document.getElementById('useOwnDomain');
    if (submitBtn && tldInput) {
        submitBtn.addEventListener('click', function(e) {
            var fullDomain = sldInput.value.trim().toLowerCase();

            // Remover www. se presente
            if (fullDomain.indexOf('www.') === 0) {
                fullDomain = fullDomain.substring(4);
            }

            // Remover protocolo se presente
            fullDomain = fullDomain.replace(/^https?:\/\//, '');

            // Remover barra final
            fullDomain = fullDomain.replace(/\/+$/, '');

            // Separar em SLD e TLD
            // Ex: nextcloud.empresa.com.br -> SLD=nextcloud.empresa, TLD=com.br
            // Ex: cloud.empresa.com -> SLD=cloud.empresa, TLD=com
            var parts = fullDomain.split('.');
            if (parts.length >= 3) {
                // Verificar TLDs compostos (.com.br, .org.br, .net.br, etc.)
                var lastTwo = parts[parts.length - 2] + '.' + parts[parts.length - 1];
                var compositeTlds = ['com.br','org.br','net.br','gov.br','edu.br','mil.br','art.br','blog.br','dev.br','app.br','co.uk','org.uk','co.za'];
                if (compositeTlds.indexOf(lastTwo) !== -1 && parts.length >= 3) {
                    sldInput.value = parts.slice(0, parts.length - 2).join('.');
                    tldInput.value = lastTwo;
                } else {
                    sldInput.value = parts.slice(0, parts.length - 1).join('.');
                    tldInput.value = parts[parts.length - 1];
                }
            } else if (parts.length === 2) {
                sldInput.value = parts[0];
                tldInput.value = parts[1];
            } else {
                sldInput.value = fullDomain;
                tldInput.value = 'com';
            }
        });
    }

    // 6. Adicionar instruções sobre DNS (apenas uma vez, com ID único)
    var formContainer = sldInput.closest('.domain-selection-options') || sldInput.closest('form') || sldInput.parentElement.parentElement;
    if (formContainer) {
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
        formContainer.appendChild(dnsInfo);
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
