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
 * @version    2.0.0
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
