<?php
/**
 * Módulo Nextcloud-SaaS para WHMCS
 *
 * Módulo de provisionamento que permite gerir instâncias Nextcloud
 * como produto SaaS dentro do WHMCS. Integra-se diretamente com o
 * Nextcloud SaaS Manager v11.x (`manage.sh` v11.3+) existente no
 * servidor de destino, que opera arquitetura compartilhada:
 *   - 3 containers por cliente: `<cliente>-app`, `<cliente>-cron`,
 *     `<cliente>-harp`.
 *   - 8 serviços globais `shared-*` (db, redis, collabora, turn,
 *     nats, janus, signaling, recording) compartilhados entre
 *     todas as instâncias, atrás do proxy Traefik com SSL
 *     automático via Let's Encrypt.
 *
 * Cada instância requer apenas 1 registro DNS A apontando para o
 * IP do servidor: `dominio.com.br`. Collabora, Talk HPB e TURN
 * passam a ser publicados em hostnames globais geridos pela
 * Defensys e não exigem DNS por cliente.
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    3.1.7
 * @license    Proprietary
 *
 * @see https://developers.whmcs.com/provisioning-modules/
 * @see https://docs.nextcloud.com/server/stable/admin_manual/
 */

if (!defined("WHMCS")) {
    die("Este ficheiro não pode ser acedido diretamente.");
}

// Carregar as bibliotecas do módulo
require_once __DIR__ . '/lib/NextcloudAPI.php';
require_once __DIR__ . '/lib/SSHManager.php';
require_once __DIR__ . '/lib/Helper.php';

use NextcloudSaaS\NextcloudAPI;
use NextcloudSaaS\SSHManager;
use NextcloudSaaS\Helper;

// =============================================================================
// METADADOS DO MÓDULO
// =============================================================================

/**
 * Definir metadados do módulo.
 *
 * @return array
 */
function nextcloudsaas_MetaData()
{
    return [
        'DisplayName'              => 'Nextcloud SaaS',
        'APIVersion'               => '1.1',
        'RequiresServer'           => true,
        'DefaultNonSSLPort'        => '80',
        'DefaultSSLPort'           => '443',
        'ServiceSingleSignOnLabel' => 'Aceder ao Nextcloud',
        'AdminSingleSignOnLabel'   => 'Aceder como Admin',
    ];
}

// =============================================================================
// OPÇÕES DE CONFIGURAÇÃO DO PRODUTO
// =============================================================================

/**
 * Definir opções de configuração do produto.
 *
 * Estas opções são apresentadas ao administrador ao configurar um produto
 * que utiliza este módulo. Os valores ficam disponíveis em todas as funções
 * do módulo como configoption1, configoption2, etc.
 *
 * @return array
 */
function nextcloudsaas_ConfigOptions()
{
    return [
        // configoption1
        'Quota de Armazenamento (GB)' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '10',
            'Description' => 'Quota de disco em GB para o utilizador admin da instância',
        ],
        // configoption2
        'Máximo de Utilizadores' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '5',
            'Description' => 'Número máximo de utilizadores permitidos na instância',
        ],
        // configoption3
        'Collabora Online' => [
            'Type'        => 'yesno',
            'Default'     => 'on',
            'Description' => 'Ativar Collabora Online (sempre ativo na arquitetura atual)',
        ],
        // configoption4
        'Nextcloud Talk (HPB)' => [
            'Type'        => 'yesno',
            'Default'     => 'on',
            'Description' => 'Ativar Talk com HPB (NATS + Janus + Signaling)',
        ],
        // configoption5
        'Caminho da Chave SSH' => [
            'Type'        => 'text',
            'Size'        => '60',
            'Default'     => '',
            'Description' => 'Caminho para chave SSH privada no servidor WHMCS (vazio = usar password do servidor)',
        ],
        // configoption6
        'Prefixo do Nome do Cliente' => [
            'Type'        => 'text',
            'Size'        => '20',
            'Default'     => '',
            'Description' => 'Prefixo opcional para o nome do cliente no manage.sh (ex: "nc-")',
        ],
        // configoption7
        'URL Pública do WHMCS (Webhook)' => [
            'Type'        => 'text',
            'Size'        => '60',
            'Default'     => '',
            'Description' => 'Override opcional da URL pública HTTPS do WHMCS para receber callbacks do manager v12+. '
                . 'Deixe vazio para autodetectar a partir das Settings do WHMCS. '
                . 'Manager rejeita IPs RFC 1918 (10.x/172.16-31.x/192.168.x).',
        ],
    ];
}

// =============================================================================
// FUNÇÕES AUXILIARES INTERNAS
// =============================================================================

/**
 * Criar instância do SSHManager a partir dos parâmetros WHMCS
 *
 * @param array $params Parâmetros do módulo
 * @return SSHManager
 */
function nextcloudsaas_getSSHManager($params)
{
    return SSHManager::fromWhmcsParams($params);
}

/**
 * Criar instância do NextcloudAPI a partir dos parâmetros WHMCS
 *
 * Tenta obter a password real do admin a partir do ficheiro .credentials
 * via SSH. Se não conseguir, usa o $params['password'] como fallback.
 *
 * @param array  $params Parâmetros do módulo
 * @param string $domain Domínio da instância Nextcloud (opcional)
 * @return NextcloudAPI
 */
function nextcloudsaas_getNextcloudAPI($params, $domain = '')
{
    if (empty($domain)) {
        $domain = isset($params['domain']) ? $params['domain'] : '';
    }

    $baseUrl = 'https://' . $domain;

    // Utilizador admin é sempre 'admin'
    $adminUser = 'admin';
    $adminPass = isset($params['password']) ? $params['password'] : '';

    // Tentar obter a password real do .credentials via SSH
    try {
        $clientName = nextcloudsaas_getClientName($params);
        if (!empty($clientName)) {
            $ssh = nextcloudsaas_getSSHManager($params);
            $credsResult = $ssh->getCredentials($clientName);
            if ($credsResult['success'] && !empty($credsResult['credentials']['nextcloud_pass'])) {
                $adminPass = $credsResult['credentials']['nextcloud_pass'];
            }
        }
    } catch (\Exception $e) {
        // Silenciar — usar fallback $params['password']
    }

    return new NextcloudAPI($baseUrl, $adminUser, $adminPass);
}

/**
 * Derivar o nome do cliente para o manage.sh a partir dos parâmetros WHMCS.
 * O manage.sh usa o nome do cliente como identificador de diretório.
 *
 * @param array $params Parâmetros do módulo
 * @return string Nome do cliente sanitizado
 */
function nextcloudsaas_getClientName($params)
{
    $prefix = isset($params['configoption6']) ? trim($params['configoption6']) : '';
    $domain = isset($params['domain']) ? $params['domain'] : '';

    // Usar o campo customfield 'Client Name' se existir
    if (!empty($params['customfields']['Client Name'])) {
        return $params['customfields']['Client Name'];
    }

    // Derivar do domínio: ex. nextcloud.cliente.com.br → cliente
    // Ou usar o serviceid como fallback
    if (!empty($domain)) {
        // Remover TLD e subdomínios comuns
        $parts = explode('.', $domain);
        if (count($parts) >= 2) {
            // Pegar a parte mais significativa
            $name = $parts[0];
            if (in_array($name, ['nextcloud', 'cloud', 'nc', 'nuvem', 'www'])) {
                $name = isset($parts[1]) ? $parts[1] : $name;
            }
        } else {
            $name = $parts[0];
        }
        // Sanitizar: apenas letras minúsculas, números e hífens
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name));
        if (!empty($name)) {
            return $prefix . $name;
        }
    }

    // Fallback: usar service ID
    return $prefix . 'whmcs-' . $params['serviceid'];
}

// =============================================================================
// FUNÇÕES PRINCIPAIS DO CICLO DE VIDA
// =============================================================================

/**
 * Provisionar uma nova instância Nextcloud.
 *
 * Executa o manage.sh com o comando 'create' no servidor de destino.
 * O manage.sh cria automaticamente os 3 containers dedicados do
 * cliente (`app`, `cron`, `harp`) e os conecta aos 8 serviços
 * globais `shared-*` já existentes no servidor, configura SSL via
 * Traefik/Let's Encrypt, instala apps essenciais e executa todos os
 * passos de pós-instalação do manager v11.x.
 *
 * Pré-requisitos (devem ser comunicados ao cliente):
 *   - 1 registro DNS A apontando para o IP do servidor:
 *     1. dominio.com.br
 *   (Collabora, Signaling HPB e TURN agora rodam em hostnames globais
 *    e não exigem DNS por cliente — v3.0.0/manager v11.x.)
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_CreateAccount(array $params)
{
    try {
        // v3.1.2: usar Helper::getDomain para suportar também pedidos
        // criados pelo admin ("Add New Order"), nos quais $params['domain']
        // pode estar vazio porque o hook AfterShoppingCartCheckout não
        // disparou. O Helper lê $params['customfields'] e, em último
        // recurso, consulta tblcustomfieldsvalues diretamente.
        $domain = Helper::getDomain($params);
        $productConfig = Helper::getProductConfig($params);

        // Validar domínio com mensagens específicas (v3.1.2)
        if (empty($domain)) {
            $hasCustomFields = !empty($params['customfields']) && is_array($params['customfields']);
            $hint = $hasCustomFields
                ? "O serviço não tem o Custom Field 'Domínio da Instância' preenchido. "
                . "Edite o serviço em Products/Services > Custom Fields e informe o domínio (ex.: nextcloud.cliente.com.br)."
                : "O produto WHMCS não tem o Custom Field 'Domínio da Instância' configurado. "
                . "Crie-o em Setup > Products/Services > <produto> > Custom Fields (Field Type: Text Box, Required: yes). "
                . "Veja o README §2.4.1.";
            return "Domínio não fornecido. " . $hint;
        }
        if (!Helper::isValidDomain($domain)) {
            return "Domínio inválido: '{$domain}'. Formato esperado: nome.exemplo.com (apenas a-z, 0-9, hífens e pontos).";
        }

        // Garantir que tblhosting.domain tenha o valor (importante para
        // ChangePassword/ChangePackage/SSO posteriores). Esta sincronização
        // é idempotente — só atualiza se o domain estiver vazio na tabela.
        if (empty($params['domain'])
            && !empty($params['serviceid'])
            && class_exists('\\WHMCS\\Database\\Capsule')) {
            try {
                \WHMCS\Database\Capsule::table('tblhosting')
                    ->where('id', (int)$params['serviceid'])
                    ->where(function ($q) {
                        $q->whereNull('domain')->orWhere('domain', '');
                    })
                    ->update(['domain' => $domain]);
            } catch (\Throwable $e) {
                // não-fatal
            }
        }

        // Obter configuração do servidor (necessário para fast-path e
        // verificação DNS)
        $serverConfig = Helper::getServerConfig($params);
        $serverIp = !empty($serverConfig['ip']) ? $serverConfig['ip'] : $serverConfig['hostname'];

        // Derivar nome do cliente para o manage.sh
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return "Não foi possível derivar o nome do cliente.";
        }

        // Conectar ao servidor via SSH (necessário tanto para a verificação
        // de idempotência quanto para a criação da instância)
        $ssh = nextcloudsaas_getSSHManager($params);

        // -------------------------------------------------------------------
        // FAST-PATH IDEMPOTENTE (v3.1.5)
        // -------------------------------------------------------------------
        // Se a instância já existe no servidor (criada por execução
        // anterior do cron `AfterCronJob` ou por intervenção manual via
        // manage.sh), reutilizamos as credenciais existentes em vez
        // de tentar `manage.sh create` de novo — o que falharia.
        //
        // Critério: existe diretório /opt/nextcloud-customers/<cliente>/
        // E pelo menos um dos ficheiros .credentials ou .env está presente.
        $existsCheck = $ssh->instanceExists($clientName);
        $reuseExisting = !empty($existsCheck['exists']);

        if ($reuseExisting) {
            Helper::log('CreateAccount_reuse', [
                'clientName' => $clientName,
                'domain'     => $domain,
                'reason'     => 'instance already provisioned on server',
            ], $existsCheck);
        } else {
            // Verificar DNS apenas quando vamos REALMENTE criar do zero —
            // se a instância já existe, o DNS já estava OK no momento da
            // criação e não precisa ser revalidado aqui.
            if (!empty($serverIp) && $serverIp !== '0.0.0.0') {
                $dnsCheck = Helper::checkDnsRecords($domain, $serverIp);
                if (!$dnsCheck['all_ok']) {
                    return "DNS não configurado corretamente. {$dnsCheck['message']}\n"
                         . "O registro DNS A do domínio deve apontar para {$serverIp}.\n"
                         . "O sistema verificará automaticamente a cada 5 minutos e provisionará quando o DNS estiver correto.";
                }
            }
        }

        Helper::log('CreateAccount', [
            'clientName'    => $clientName,
            'domain'        => $domain,
            'quota'         => $productConfig['disk_quota_gb'],
            'maxUsers'      => $productConfig['max_users'],
            'reuseExisting' => $reuseExisting,
        ]);

        if (!$reuseExisting) {
            // Verificar se o manage.sh existe
            $verify = $ssh->verifyManageScript();
            if (!$verify['exists']) {
                return "O script manage.sh não foi encontrado no servidor em {$verify['path']}. "
                     . "Verifique se o Nextcloud SaaS está instalado corretamente.";
            }

            // Criar a instância
            $result = $ssh->createInstance($clientName, $domain);

            Helper::log('CreateAccount_result', [
                'clientName' => $clientName,
                'domain'     => $domain,
            ], $result);

            if (!$result['success']) {
                // Algumas versões do manage.sh saem com código de erro mesmo
                // depois de já ter criado a instância parcialmente. Antes de
                // dar como falha, reconfirmamos no servidor:
                $postCheck = $ssh->instanceExists($clientName);
                if (empty($postCheck['exists'])) {
                    return "Erro ao criar instância Nextcloud: " . $result['error'];
                }
                Helper::log('CreateAccount_recovered_after_error', [
                    'clientName' => $clientName,
                    'domain'     => $domain,
                ], $postCheck);
            }
        }

        // Ler as credenciais geradas pelo manage.sh
        $credsResult = $ssh->getCredentials($clientName);
        $envResult = $ssh->getEnv($clientName);

        $adminUser = 'admin';
        $adminPass = '';
        $collaboraDomain = Helper::getCollaboraDomain($domain);
        $signalingDomain = Helper::getSignalingDomain($domain);

        if (isset($credsResult['credentials'])) {
            $creds = $credsResult['credentials'];
            $adminUser = !empty($creds['nextcloud_user']) ? $creds['nextcloud_user'] : 'admin';
            $adminPass = !empty($creds['nextcloud_pass']) ? $creds['nextcloud_pass'] : '';
        }

        if (isset($envResult['env'])) {
            $env = $envResult['env'];
            if (!empty($env['NEXTCLOUD_ADMIN_PASSWORD'])) {
                $adminPass = $env['NEXTCLOUD_ADMIN_PASSWORD'];
            }
        }

        // Atualizar os campos do serviço no WHMCS com as credenciais
        if (function_exists('localAPI')) {
            $serverConfig = Helper::getServerConfig($params);
            $serverIp = !empty($serverConfig['ip']) ? $serverConfig['ip'] : $serverConfig['hostname'];

            localAPI('UpdateClientProduct', [
                'serviceid'       => $params['serviceid'],
                'serviceusername' => $adminUser,
                'servicepassword' => $adminPass,
                'domain'          => $domain,
                'dedicatedip'     => $serverIp,
            ]);
        }

        // Guardar campos personalizados
        nextcloudsaas_saveCustomField($params['serviceid'], 'Client Name', $clientName);
        nextcloudsaas_saveCustomField($params['serviceid'], 'Nextcloud URL', 'https://' . $domain);
        nextcloudsaas_saveCustomField($params['serviceid'], 'Collabora URL', 'https://' . $collaboraDomain);
        nextcloudsaas_saveCustomField($params['serviceid'], 'Signaling URL', 'https://' . $signalingDomain);

        // Aplicar quota ao utilizador admin e definir quota padrão.
        // No fast-path idempotente (reuseExisting), ignorar erros silenciosamente:
        // a instância já estava operacional e não queremos quebrar a ativação.
        if (!empty($productConfig['disk_quota_gb']) && $productConfig['disk_quota_gb'] !== 'none') {
            try {
                $quota = Helper::formatQuotaForNextcloud($productConfig['disk_quota_gb']);
                // Aplicar quota ao admin
                $ssh->setUserQuota($clientName, $adminUser, $quota);
                // Definir quota padrão para todos os novos utilizadores
                $ssh->setDefaultQuota($clientName, $quota);
            } catch (\Throwable $eq) {
                Helper::log('CreateAccount_quota_warning', [
                    'clientName'    => $clientName,
                    'reuseExisting' => $reuseExisting,
                ], $eq->getMessage());
                if (!$reuseExisting) {
                    // Em provisionamento novo, propagar a falha de quota
                    throw $eq;
                }
                // Em fast-path, não-fatal
            }
        }

        // -------------------------------------------------------------------
        // Ativar o Order do WHMCS (v3.1.5)
        // -------------------------------------------------------------------
        // O `CreateAccount` retornar 'success' faz o WHMCS marcar o serviço
        // (`tblhosting.domainstatus`) como Active, mas o pedido em si
        // (`tblorders.status`) permanece em Pending para produtos com
        // `autosetup` desligado ou faturas em $0. Chamamos `AcceptOrder`
        // explicitamente para fechar o ciclo — sem reenvio de e-mail nem
        // re-execução do módulo (autosetup=false).
        nextcloudsaas_acceptOrderForService((int) $params['serviceid']);

    } catch (\Exception $e) {
        Helper::log('CreateAccount', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Aceitar (ativar) o Order WHMCS associado a um serviço, se ainda
 * estiver em Pending (v3.1.5).
 *
 * Quando `CreateAccount` conclui com sucesso o WHMCS marca o serviço
 * como Active, mas não aceita automaticamente o Order (`tblorders`).
 * Esta função:
 *   1. Localiza o `orderid` ligado ao serviço em tblhosting.
 *   2. Lê o status atual em tblorders.
 *   3. Se estiver Pending, chama `localAPI('AcceptOrder', ...)` com
 *      `autosetup=false` (não reexecutar módulo) e `sendemail=false`.
 *   4. Loga a transição para diagnóstico.
 *
 * Idempotente: se o Order já estiver Active, não faz nada.
 *
 * @param int $serviceId tblhosting.id
 * @return bool true se ativou (ou já estava ativo); false se erro
 */
function nextcloudsaas_acceptOrderForService($serviceId)
{
    if ($serviceId <= 0 || !function_exists('localAPI')) {
        return false;
    }

    try {
        if (!class_exists('\\WHMCS\\Database\\Capsule')) {
            return false;
        }

        $service = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->first(['orderid']);

        if (empty($service) || empty($service->orderid)) {
            Helper::log('AcceptOrder_skip', ['serviceId' => $serviceId, 'reason' => 'no orderid']);
            return false;
        }

        $order = \WHMCS\Database\Capsule::table('tblorders')
            ->where('id', (int) $service->orderid)
            ->first(['status']);

        if (empty($order)) {
            Helper::log('AcceptOrder_skip', ['serviceId' => $serviceId, 'orderId' => $service->orderid, 'reason' => 'order not found']);
            return false;
        }

        if ($order->status === 'Active') {
            return true;
        }

        if ($order->status !== 'Pending') {
            // Não tocar em Cancelled, Fraud, etc.
            Helper::log('AcceptOrder_skip', [
                'serviceId' => $serviceId,
                'orderId'   => $service->orderid,
                'status'    => $order->status,
            ]);
            return false;
        }

        $resp = localAPI('AcceptOrder', [
            'orderid'           => (int) $service->orderid,
            'autosetup'         => false,
            'sendemail'         => false,
            'registrar'         => '',
            'serverid'          => '',
            'serviceusername'   => '',
            'servicepassword'   => '',
        ]);

        $ok = isset($resp['result']) && $resp['result'] === 'success';

        Helper::log('AcceptOrder', [
            'serviceId' => $serviceId,
            'orderId'   => $service->orderid,
            'status_before' => $order->status,
        ], $resp);

        if ($ok && function_exists('logActivity')) {
            logActivity(sprintf(
                'Nextcloud SaaS: Order #%d aceito automaticamente (serviço #%d) após provisionamento bem-sucedido.',
                (int) $service->orderid,
                $serviceId
            ));
        }

        return (bool) $ok;
    } catch (\Throwable $e) {
        Helper::log('AcceptOrder_exception', ['serviceId' => $serviceId], $e->getMessage());
        return false;
    }
}

/**
 * Suspender uma instância Nextcloud.
 *
 * Executa manage.sh stop para parar os 3 containers dedicados da
 * instância (`<cliente>-app`, `<cliente>-cron`, `<cliente>-harp`).
 * Os serviços globais `shared-*` não são afetados. Os dados são
 * preservados nos volumes Docker.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_SuspendAccount(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return "Nome do cliente não encontrado para este serviço.";
        }

        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->stopInstance($clientName);

        Helper::log('SuspendAccount', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao suspender instância: " . $result['error'];
        }

    } catch (\Exception $e) {
        Helper::log('SuspendAccount', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Reativar uma instância Nextcloud previamente suspensa.
 *
 * Executa manage.sh start para reiniciar os 3 containers dedicados
 * da instância (`app`, `cron`, `harp`). Os serviços globais
 * `shared-*` permanecem intactos.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_UnsuspendAccount(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return "Nome do cliente não encontrado para este serviço.";
        }

        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->startInstance($clientName);

        Helper::log('UnsuspendAccount', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao reativar instância: " . $result['error'];
        }

    } catch (\Exception $e) {
        Helper::log('UnsuspendAccount', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Terminar uma instância Nextcloud.
 *
 * Executa manage.sh remove para remover todos os containers, volumes
 * e dados da instância. Esta ação é IRREVERSÍVEL.
 * Recomenda-se fazer backup antes via manage.sh backup.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_TerminateAccount(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return "Nome do cliente não encontrado para este serviço.";
        }

        $ssh = nextcloudsaas_getSSHManager($params);

        // Fazer backup antes de remover
        Helper::log('TerminateAccount', ['clientName' => $clientName], 'Fazendo backup antes de remover...');
        $ssh->backupInstance($clientName);

        // Remover a instância
        $result = $ssh->removeInstance($clientName);

        Helper::log('TerminateAccount', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao terminar instância: " . $result['error'];
        }

    } catch (\Exception $e) {
        Helper::log('TerminateAccount', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Renovar uma instância Nextcloud.
 *
 * Verifica se a instância está ativa e reinicia se necessário.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_Renew(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return 'success'; // Não é erro crítico
        }

        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->statusInstance($clientName);

        Helper::log('Renew', ['clientName' => $clientName], $result);

        // Se a instância não está a correr, tentar iniciar
        if ($result['success'] && strpos($result['output'], 'running') === false) {
            $ssh->startInstance($clientName);
        }

    } catch (\Exception $e) {
        Helper::log('Renew', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// =============================================================================
// GESTÃO DE PASSWORDS E PACOTES
// =============================================================================

/**
 * Alterar a password do utilizador admin da instância Nextcloud.
 *
 * Método principal: API OCS do Nextcloud (mais rápido e fiável).
 * Fallback: comando occ via docker exec por SSH.
 *
 * A API OCS autentica-se com a password atual obtida do ficheiro
 * .credentials no servidor, e depois altera para a nova password
 * enviada pelo WHMCS.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_ChangePassword(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        // O username do Nextcloud é sempre 'admin' — o $params['username']
        // do WHMCS pode estar truncado ou ser diferente (ex: 'nextclou')
        $username    = 'admin';
        $newPassword = isset($params['password']) ? $params['password'] : '';
        $domain      = isset($params['domain']) ? $params['domain'] : '';

        Helper::log('ChangePassword-START', [
            'clientName'  => $clientName,
            'username'    => $username,
            'hasPassword' => !empty($newPassword),
            'passLength'  => strlen($newPassword),
            'domain'      => !empty($domain) ? $domain : '(empty)',
        ], 'Início');

        if (empty($clientName) || empty($newPassword)) {
            return "Nome do cliente ou nova password não fornecidos. "
                 . "(clientName='{$clientName}', passLen=" . strlen($newPassword) . ")";
        }

        // ── Método 1: API OCS (preferido) ──────────────────────────────
        if (!empty($domain)) {
            try {
                // getNextcloudAPI obtém a password ATUAL do .credentials
                // para autenticar na API, independente de $params['password']
                $ncApi  = nextcloudsaas_getNextcloudAPI($params);
                $result = $ncApi->changeUserPassword($username, $newPassword);

                Helper::log('ChangePassword-API', [
                    'clientName' => $clientName,
                    'username'   => $username,
                    'method'     => 'API OCS',
                ], $result);

                if ($result['success']) {
                    // Atualizar o .credentials com a nova password
                    try {
                        $ssh = nextcloudsaas_getSSHManager($params);
                        $ssh->updateCredentialsPassword($clientName, $newPassword);
                    } catch (\Exception $e) {
                        Helper::log('ChangePassword-CRED-UPDATE-FAIL', [
                            'error' => $e->getMessage(),
                        ], 'Password alterada mas .credentials não atualizado');
                    }
                    return 'success';
                }

                // Se a API falhou, logar o motivo e tentar SSH
                Helper::log('ChangePassword-API-FAIL', [
                    'message' => isset($result['message']) ? $result['message'] : 'Sem mensagem',
                ], 'Fallback para SSH/occ');

            } catch (\Exception $e) {
                Helper::log('ChangePassword-API-EXCEPTION', [
                    'error' => $e->getMessage(),
                ], 'Fallback para SSH/occ');
            }
        }

        // ── Método 2: SSH + docker exec occ (fallback) ─────────────────
        $ssh    = nextcloudsaas_getSSHManager($params);
        $result = $ssh->changeUserPassword($clientName, $username, $newPassword);

        Helper::log('ChangePassword-SSH', [
            'clientName' => $clientName,
            'username'   => $username,
            'method'     => 'SSH/occ',
        ], $result);

        if (!$result['success']) {
            return "Erro ao alterar password: " . $result['error']
                 . " (output: " . $result['output'] . ")";
        }

        // Atualizar o .credentials com a nova password
        try {
            $ssh->updateCredentialsPassword($clientName, $newPassword);
        } catch (\Exception $e2) {
            Helper::log('ChangePassword-CRED-UPDATE-FAIL', [
                'error' => $e2->getMessage(),
            ], 'Password alterada via SSH mas .credentials não atualizado');
        }

    } catch (\Exception $e) {
        Helper::log('ChangePassword-ERROR', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Alterar o pacote/plano da instância (upgrade/downgrade).
 *
 * Ajusta a quota de armazenamento do utilizador admin via occ.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_ChangePackage(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $productConfig = Helper::getProductConfig($params);
        // O username do Nextcloud é sempre 'admin'
        $username = 'admin';

        if (empty($clientName)) {
            return "Nome do cliente não encontrado para este serviço.";
        }

        $quota = Helper::formatQuotaForNextcloud($productConfig['disk_quota_gb']);

        $ssh = nextcloudsaas_getSSHManager($params);

        // Alterar quota do admin
        $result = $ssh->setUserQuota($clientName, $username, $quota);

        Helper::log('ChangePackage', [
            'clientName' => $clientName,
            'quota'      => $quota,
            'maxUsers'   => $productConfig['max_users'],
        ], $result);

        if (!$result['success']) {
            return "Erro ao alterar pacote: " . $result['error'];
        }

        // Atualizar também a quota padrão para novos utilizadores
        $ssh->setDefaultQuota($clientName, $quota);

    } catch (\Exception $e) {
        Helper::log('ChangePackage', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// =============================================================================
// TESTE DE CONEXÃO
// =============================================================================

/**
 * Testar a conexão com o servidor.
 *
 * Invocada quando o administrador clica em "Test Connection" na
 * página de configuração do servidor no WHMCS.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return array ['success' => bool, 'error' => string]
 */
function nextcloudsaas_TestConnection(array $params)
{
    try {
        $ssh = nextcloudsaas_getSSHManager($params);

        // Testar conexão SSH
        $sshTest = $ssh->testConnection();
        if (!$sshTest['success']) {
            return [
                'success' => false,
                'error'   => $sshTest['message'],
            ];
        }

        // Verificar se o manage.sh existe
        $manageTest = $ssh->verifyManageScript();
        if (!$manageTest['exists']) {
            return [
                'success' => false,
                'error'   => "Conexão SSH OK, mas manage.sh não encontrado em {$manageTest['path']}",
            ];
        }

        // Verificar Docker
        $dockerTest = $ssh->verifyDocker();
        if (!$dockerTest['running']) {
            return [
                'success' => false,
                'error'   => 'Conexão SSH OK, mas Docker não está a correr no servidor.',
            ];
        }

        // Verificar Traefik
        $traefikTest = $ssh->verifyTraefik();
        if (!$traefikTest['running']) {
            return [
                'success' => false,
                'error'   => 'Conexão SSH OK, Docker OK, mas Traefik não está a correr.',
            ];
        }

        return ['success' => true, 'error' => ''];

    } catch (\Exception $e) {
        return [
            'success' => false,
            'error'   => $e->getMessage(),
        ];
    }
}

// =============================================================================
// SINGLE SIGN-ON (SSO)
// =============================================================================

/**
 * SSO para o painel do utilizador (cliente).
 *
 * @param array $params Parâmetros comuns do módulo
 * @return array
 */
function nextcloudsaas_ServiceSingleSignOn(array $params)
{
    try {
        $domain = isset($params['domain']) ? $params['domain'] : '';

        if (empty($domain)) {
            return [
                'success'  => false,
                'errorMsg' => 'Domínio não encontrado para este serviço.',
            ];
        }

        return [
            'success'    => true,
            'redirectTo' => 'https://' . $domain . '/login',
        ];

    } catch (\Exception $e) {
        return [
            'success'  => false,
            'errorMsg' => $e->getMessage(),
        ];
    }
}

/**
 * SSO para o painel de administração.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return array
 */
function nextcloudsaas_AdminSingleSignOn(array $params)
{
    try {
        $domain = isset($params['domain']) ? $params['domain'] : '';

        if (empty($domain)) {
            return [
                'success'  => false,
                'errorMsg' => 'Domínio não encontrado para este serviço.',
            ];
        }

        return [
            'success'    => true,
            'redirectTo' => 'https://' . $domain . '/settings/admin',
        ];

    } catch (\Exception $e) {
        return [
            'success'  => false,
            'errorMsg' => $e->getMessage(),
        ];
    }
}

// =============================================================================
// BOTÕES PERSONALIZADOS (ADMIN E CLIENTE)
// =============================================================================

/**
 * Definir botões personalizados para a área de administração.
 *
 * @return array
 */
function nextcloudsaas_AdminCustomButtonArray()
{
    return [
        'Provisionar Agora'             => 'provisionNow',
        'Verificar Estado'              => 'checkStatus',
        'Verificar DNS'                 => 'checkDns',
        'Serviços Compartilhados'       => 'checkSharedServices',
        'Listar Instâncias do Servidor' => 'listAllInstances',
        'Reiniciar Instância'           => 'restartInstance',
        'Fazer Backup'                  => 'backupInstance',
        'Atualizar Instância'           => 'updateInstance',
        'Testar Conexão SSH'            => 'testSSH',
        'Testar API Nextcloud'          => 'testAPI',
        'Ver Credenciais'               => 'viewCredentials',
        'Ver Logs'                      => 'viewLogs',
        'Ver Logs Talk Recording'       => 'viewRecordingLogs',
    ];
}

/**
 * Definir botões personalizados para a área de cliente.
 *
 * @return array
 */
function nextcloudsaas_ClientAreaCustomButtonArray()
{
    return [
        'Verificar Estado'    => 'checkStatus',
        'Reiniciar Instância' => 'restartInstance',
    ];
}

/**
 * Botão admin 'Provisionar Agora' (v3.1.5).
 *
 * Re-executa o `CreateAccount` deste serviço de forma idempotente,
 * sem esperar pelo cron de 5 minutos. Útil para destravar Orders
 * pendentes quando o DNS já foi corrigido (ou quando a instância
 * já existe no servidor mas o Order continua Pending).
 *
 * Comportamento:
 *   1. Chama `nextcloudsaas_CreateAccount($params)` diretamente.
 *   2. Se retornar 'success', força `domainstatus = Active` em
 *      tblhosting e tenta aceitar o Order via
 *      `nextcloudsaas_acceptOrderForService`.
 *   3. Em qualquer caso, registra um painel na sessão
 *      (`nextcloudsaas_panel`) para o admin ver o resultado na aba
 *      `Module` do serviço.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro (string)
 */
function nextcloudsaas_provisionNow(array $params)
{
    $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
    $startedAt = date('Y-m-d H:i:s');

    Helper::log('provisionNow_start', [
        'serviceId' => $serviceId,
        'domain'    => isset($params['domain']) ? $params['domain'] : '',
    ]);

    $createResult = '';
    try {
        $createResult = nextcloudsaas_CreateAccount($params);
    } catch (\Throwable $e) {
        $createResult = 'Exceção em CreateAccount: ' . $e->getMessage();
    }

    $success = ($createResult === 'success');
    $orderAccepted = false;

    if ($success && $serviceId > 0 && class_exists('\\WHMCS\\Database\\Capsule')) {
        try {
            // Forçar status Active no serviço (defesa em profundidade —
            // o WHMCS já deveria ter feito isso ao receber 'success').
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->update(['domainstatus' => 'Active']);
        } catch (\Throwable $e) {
            // não-fatal
        }
        $orderAccepted = nextcloudsaas_acceptOrderForService($serviceId);
    }

    Helper::log('provisionNow_result', [
        'serviceId'     => $serviceId,
        'success'       => $success,
        'orderAccepted' => $orderAccepted,
    ], $createResult);

    if (function_exists('logActivity')) {
        if ($success) {
            logActivity(sprintf(
                'Nextcloud SaaS: Botão "Provisionar Agora" executado para Serviço #%d - sucesso. Order ativado: %s.',
                $serviceId,
                $orderAccepted ? 'sim' : 'não'
            ));
        } else {
            logActivity(sprintf(
                'Nextcloud SaaS: Botão "Provisionar Agora" executado para Serviço #%d - erro: %s',
                $serviceId,
                is_string($createResult) ? $createResult : 'desconhecido'
            ));
        }
    }

    // Painel para a aba Module
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $panelTitle = $success
        ? 'Provisionamento manual concluído com sucesso'
        : 'Provisionamento manual retornou erro';
    $panelLines = [];
    $panelLines[] = 'Início: ' . $startedAt;
    $panelLines[] = 'Fim:    ' . date('Y-m-d H:i:s');
    $panelLines[] = 'CreateAccount: ' . ($success ? 'success' : (string) $createResult);
    if ($success) {
        $panelLines[] = 'Order WHMCS:  ' . ($orderAccepted ? 'aceito (Active)' : 'sem alteração (já estava Active ou sem orderid)');
    }
    $_SESSION['nextcloudsaas_panel'] = [
        'type'      => 'provision_now',
        'title'     => $panelTitle,
        'content'   => implode("\n", $panelLines),
        'serviceid' => $serviceId,
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    if ($success) {
        return 'success';
    }
    return is_string($createResult) ? $createResult : 'Erro desconhecido em CreateAccount.';
}

/**
 * Verificar o estado da instância Nextcloud.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_checkStatus(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return 'Nome do cliente não encontrado para este serviço.';
        }

        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->statusInstance($clientName);

        Helper::log('checkStatus', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return 'Erro ao verificar estado: ' . $result['error'];
        }

        // Gravar na sessão para exibir no AdminServicesTabFields
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['nextcloudsaas_panel'] = [
            'type'      => 'status',
            'title'     => 'Estado da Instância: ' . $clientName,
            'content'   => $result['output'],
            'serviceid' => $params['serviceid'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

    } catch (\Exception $e) {
        return 'Exceção ao verificar estado: ' . $e->getMessage();
    }

    return 'success';
}

/**
 * Verificar o estado do registro DNS da instância.
 *
 * Verifica se o registro DNS A do domínio principal aponta para o
 * IP do servidor configurado no WHMCS. Na arquitetura compartilhada
 * v3.0.0 (manager v11.x), apenas 1 registro é exigido por cliente.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_checkDns(array $params)
{
    try {
        $domain = isset($params['domain']) ? $params['domain'] : '';

        if (empty($domain)) {
            return 'Domínio não encontrado para este serviço.';
        }

        // Obter IP do servidor configurado no WHMCS
        $serverConfig = Helper::getServerConfig($params);
        $serverIp = !empty($serverConfig['ip']) ? $serverConfig['ip'] : $serverConfig['hostname'];

        if (empty($serverIp) || $serverIp === '0.0.0.0') {
            return 'IP do servidor não configurado. Verifique as configurações do servidor no WHMCS.';
        }

        $dnsCheck = Helper::checkDnsRecords($domain, $serverIp);

        Helper::log('checkDns', ['domain' => $domain, 'serverIp' => $serverIp], $dnsCheck);

        // Construir HTML do resultado
        $domains = Helper::getRequiredDomains($domain);
        $html = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin:8px 0;">';
        $html .= '<tr style="background:#f5f5f5;">';
        $html .= '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Tipo</th>';
        $html .= '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Hostname</th>';
        $html .= '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Esperado</th>';
        $html .= '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Resolvido</th>';
        $html .= '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Status</th>';
        $html .= '</tr>';

        foreach ($dnsCheck['results'] as $type => $result) {
            $resolvedIps = !empty($result['resolved']) ? implode(', ', $result['resolved']) : '(sem registro)';
            $statusIcon = $result['correct']
                ? '<span style="color:green;font-weight:bold;">OK</span>'
                : '<span style="color:red;font-weight:bold;">FALHA</span>';

            $html .= '<tr>';
            $html .= '<td style="padding:6px 10px;border:1px solid #ddd;">' . ucfirst($type) . '</td>';
            $html .= '<td style="padding:6px 10px;border:1px solid #ddd;"><code>' . htmlspecialchars($result['hostname']) . '</code></td>';
            $html .= '<td style="padding:6px 10px;border:1px solid #ddd;"><code>' . htmlspecialchars($serverIp) . '</code></td>';
            $html .= '<td style="padding:6px 10px;border:1px solid #ddd;"><code>' . htmlspecialchars($resolvedIps) . '</code></td>';
            $html .= '<td style="padding:6px 10px;border:1px solid #ddd;">' . $statusIcon . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        $overallStatus = $dnsCheck['all_ok']
            ? '<span style="background:#27ae60;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;">TODOS OS DNS CORRETOS</span>'
            : '<span style="background:#e74c3c;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;">DNS INCOMPLETO</span>';

        // Gravar na sessão para exibir no AdminServicesTabFields
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['nextcloudsaas_panel'] = [
            'type'      => 'dns',
            'title'     => 'Verificação DNS: ' . $domain . ' (IP do Servidor: ' . $serverIp . ')',
            'content'   => $overallStatus . $html,
            'serviceid' => $params['serviceid'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

    } catch (\Exception $e) {
        return 'Exceção ao verificar DNS: ' . $e->getMessage();
    }

    return 'success';
}

/**
 * Verificar o estado dos serviços globais compartilhados (v3.0.0).
 *
 * Na arquitetura compartilhada do manager v11.x, todas as instâncias
 * dependem dos 8 containers globais `shared-*` rodando no host:
 * shared-db, shared-redis, shared-collabora, shared-turn, shared-nats,
 * shared-janus, shared-signaling e shared-recording.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_checkSharedServices(array $params)
{
    try {
        $ssh = nextcloudsaas_getSSHManager($params);
        $check = $ssh->verifySharedServices();

        Helper::log('checkSharedServices', [], $check);

        $html = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin:8px 0;">';
        $html .= '<tr style="background:#f5f5f5;">';
        $html .= '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Serviço</th>';
        $html .= '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Status</th>';
        $html .= '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Resultado</th>';
        $html .= '</tr>';
        foreach ($check['services'] as $name => $svc) {
            $icon = $svc['running']
                ? '<span style="color:green;font-weight:bold;">UP</span>'
                : '<span style="color:red;font-weight:bold;">DOWN</span>';
            $html .= '<tr>'
                  . '<td style="padding:6px 10px;border:1px solid #ddd;"><code>' . htmlspecialchars($name) . '</code></td>'
                  . '<td style="padding:6px 10px;border:1px solid #ddd;">' . htmlspecialchars($svc['status']) . '</td>'
                  . '<td style="padding:6px 10px;border:1px solid #ddd;">' . $icon . '</td>'
                  . '</tr>';
        }
        $html .= '</table>';

        $overall = $check['all_ok']
            ? '<span style="background:#27ae60;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;">TODOS OS SERVIÇOS COMPARTILHADOS UP (' . $check['up'] . '/' . $check['total'] . ')</span>'
            : '<span style="background:#e74c3c;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;">SERVIÇOS COMPARTILHADOS COM PROBLEMAS (' . $check['up'] . '/' . $check['total'] . ' UP)</span>';

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['nextcloudsaas_panel'] = [
            'type'      => 'shared_services',
            'title'     => 'Serviços Globais Compartilhados (manager v11.x)',
            'content'   => $overall . $html,
            'serviceid' => $params['serviceid'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

    } catch (\Exception $e) {
        return 'Exceção ao verificar serviços compartilhados: ' . $e->getMessage();
    }

    return 'success';
}

/**
 * Reiniciar a instância Nextcloud (stop + start).
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_restartInstance(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $ssh = nextcloudsaas_getSSHManager($params);

        // Parar
        $ssh->stopInstance($clientName);
        sleep(3);
        // Iniciar
        $result = $ssh->startInstance($clientName);

        Helper::log('restartInstance', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao reiniciar: " . $result['error'];
        }

    } catch (\Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Fazer backup da instância via manage.sh.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_backupInstance(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->backupInstance($clientName);

        Helper::log('backupInstance', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao fazer backup: " . $result['error'];
        }

    } catch (\Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Atualizar a instância (pull + upgrade) via manage.sh.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_updateInstance(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->updateInstance($clientName);

        Helper::log('updateInstance', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return "Erro ao atualizar: " . $result['error'];
        }

    } catch (\Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Testar a conexão SSH com o servidor.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_testSSH(array $params)
{
    try {
        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->testConnection();

        Helper::log('testSSH', [], $result);

        if (!$result['success']) {
            return $result['message'];
        }

    } catch (\Exception $e) {
        return $e->getMessage();
    }

    return 'success';
}

/**
 * Testar a conexão com a API do Nextcloud.
 *
 * Obtém a password real do admin a partir do .credentials via SSH
 * e testa a comunicação com a API OCS da instância Nextcloud.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_testAPI(array $params)
{
    try {
        $domain = isset($params['domain']) ? $params['domain'] : '';
        if (empty($domain)) {
            return 'Domínio não configurado para este serviço.';
        }

        $ncApi = nextcloudsaas_getNextcloudAPI($params);
        $result = $ncApi->testConnection();

        Helper::log('testAPI', ['domain' => $domain], $result);

        if (!$result['success']) {
            return 'Erro API Nextcloud (' . $domain . '): ' . $result['message'];
        }

    } catch (\Exception $e) {
        return 'Exceção ao testar API: ' . $e->getMessage();
    }

    return 'success';
}

/**
 * Ver credenciais da instância.
 *
 * Obtém as credenciais do ficheiro .credentials via SSH e exibe-as
 * ao administrador. No WHMCS, botões personalizados só exibem output
 * quando retornam uma string diferente de "success", por isso os dados
 * são retornados como string formatada.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string Credenciais formatadas ou mensagem de erro
 */
function nextcloudsaas_viewCredentials(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);
        $domain = isset($params['domain']) ? $params['domain'] : '';

        if (empty($clientName)) {
            return 'Nome do cliente não encontrado para este serviço.';
        }

        $ssh = nextcloudsaas_getSSHManager($params);

        // Obter credenciais do .credentials via SSH
        $credsResult = $ssh->getCredentials($clientName);

        Helper::log('viewCredentials', ['clientName' => $clientName], $credsResult);

        if (!$credsResult['success']) {
            return 'Erro ao obter credenciais: ' . (isset($credsResult['raw']) ? $credsResult['raw'] : 'Ficheiro .credentials não encontrado');
        }

        $creds = $credsResult['credentials'];
        $serverConfig = Helper::getServerConfig($params);
        $serverIp = !empty($serverConfig['ip']) ? $serverConfig['ip'] : $serverConfig['hostname'];
        $collaboraDomain = Helper::getCollaboraDomain($domain);
        $signalingDomain = Helper::getSignalingDomain($domain);

        // v3.1.6: o manage.sh v11.x não escreve mais HP_SHARED_KEY no
        // .credentials. Buscar diretamente no docker-compose.yml/container
        // quando o parser não encontrar.
        if (empty($creds['harp_shared_key']) && method_exists($ssh, 'getHarpSharedKey')) {
            $harp = $ssh->getHarpSharedKey($clientName);
            if (!empty($harp['key'])) {
                $creds['harp_shared_key'] = $harp['key'];
                Helper::log('viewCredentials_harp_fallback', [
                    'clientName' => $clientName,
                    'source'     => $harp['source'],
                ]);
            }
        }

        // Construir HTML formatado para exibição no painel
        $html = nextcloudsaas_buildCredentialsHtml($clientName, $domain, $creds, $serverIp, $collaboraDomain, $signalingDomain);

        // Gravar na sessão para exibir no AdminServicesTabFields
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['nextcloudsaas_panel'] = [
            'type'      => 'credentials',
            'title'     => 'Credenciais da Instância: ' . $clientName,
            'content'   => $html,
            'serviceid' => $params['serviceid'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

    } catch (\Exception $e) {
        return 'Exceção ao obter credenciais: ' . $e->getMessage();
    }

    return 'success';
}

/**
 * Ver logs do container Docker principal (app).
 *
 * Obtém as últimas 50 linhas de logs do container principal
 * da instância Nextcloud via docker logs e exibe-as ao administrador.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string Logs formatados ou mensagem de erro
 */
function nextcloudsaas_viewLogs(array $params)
{
    try {
        $clientName = nextcloudsaas_getClientName($params);

        if (empty($clientName)) {
            return 'Nome do cliente não encontrado para este serviço.';
        }

        $ssh = nextcloudsaas_getSSHManager($params);
        $containerName = $clientName . '-app';
        $result = $ssh->executeCommand(
            "docker logs --tail 50 " . escapeshellarg($containerName) . " 2>&1"
        );

        Helper::log('viewLogs', ['clientName' => $clientName], $result);

        if (!$result['success']) {
            return 'Erro ao obter logs: ' . $result['error'];
        }

        $logs = trim($result['output']);

        // Gravar na sessão para exibir no AdminServicesTabFields
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['nextcloudsaas_panel'] = [
            'type'      => 'logs',
            'title'     => 'Logs: ' . $containerName . ' (últimas 50 linhas)',
            'content'   => !empty($logs) ? $logs : '(Sem logs disponíveis)',
            'serviceid' => $params['serviceid'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

    } catch (\Exception $e) {
        return 'Exceção ao obter logs: ' . $e->getMessage();
    }

    return 'success';
}

/**
 * Ver logs do serviço global de gravação de chamadas Talk (v3.1.0).
 *
 * Executa `docker logs --tail 100 shared-recording` no servidor e
 * apresenta o resultado no painel admin.
 *
 * @param array $params
 * @return string
 */
function nextcloudsaas_viewRecordingLogs(array $params)
{
    try {
        $ssh = nextcloudsaas_getSSHManager($params);
        $result = $ssh->executeCommand('docker logs --tail 100 shared-recording 2>&1');

        Helper::log('viewRecordingLogs', [], $result);

        if (!$result['success']) {
            return 'Erro ao obter logs do shared-recording: ' . $result['error'];
        }

        $logs = trim($result['output']);

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['nextcloudsaas_panel'] = [
            'type'      => 'logs',
            'title'     => 'Logs: shared-recording (últimas 100 linhas) — gravação de chamadas Talk',
            'content'   => !empty($logs) ? $logs : '(Sem logs disponíveis. Container shared-recording pode estar parado.)',
            'serviceid' => $params['serviceid'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

    } catch (\Exception $e) {
        return 'Exceção ao obter logs Talk Recording: ' . $e->getMessage();
    }

    return 'success';
}

/**
 * Listar todas as instâncias provisionadas no servidor (v3.1.0).
 *
 * Inspeciona `/opt/nextcloud-customers/` no servidor e gera um
 * dashboard HTML consolidado com:
 *   - Nome de cada instância.
 *   - Estado dos 3 containers dedicados (`<cliente>-app|cron|harp`).
 *   - Uso de disco (`du -sh`).
 *   - Total de instâncias e total UP.
 *
 * Ótil para visualizar todo o servidor a partir de qualquer serviço
 * WHMCS sem precisar abrir SSH.
 *
 * @param array $params
 * @return string
 */
function nextcloudsaas_listAllInstances(array $params)
{
    try {
        $ssh = nextcloudsaas_getSSHManager($params);

        $basePath = !empty($params['serverhttpprefix']) || !empty($params['configoption1'])
            ? '/opt/nextcloud-customers'
            : '/opt/nextcloud-customers';

        $listCmd = 'ls -1 ' . escapeshellarg($basePath) . ' 2>/dev/null';
        $resList = $ssh->executeCommand($listCmd, 15);
        if (!$resList['success']) {
            return 'Erro ao listar instâncias em ' . $basePath . ': ' . ($resList['error'] ?? '');
        }
        $names = array_filter(
            array_map('trim', preg_split('/\r?\n/', trim($resList['output']))),
            function ($n) { return $n !== '' && strpos($n, '.') !== 0 && $n !== 'shared'; }
        );

        $psCmd = "docker ps -a --format '{{.Names}}|{{.Status}}' 2>/dev/null";
        $resPs = $ssh->executeCommand($psCmd, 20);
        $byName = [];
        if ($resPs['success'] && !empty($resPs['output'])) {
            foreach (preg_split('/\r?\n/', trim($resPs['output'])) as $line) {
                if (strpos($line, '|') === false) { continue; }
                list($n, $s) = explode('|', $line, 2);
                $byName[trim($n)] = trim($s);
            }
        }

        $rows = [];
        $totalUp = 0;
        foreach ($names as $name) {
            $expected = ["$name-app", "$name-cron", "$name-harp"];
            $up = 0;
            $detail = [];
            foreach ($expected as $cn) {
                $st = $byName[$cn] ?? 'absent';
                $isUp = (strpos($st, 'Up') === 0);
                if ($isUp) { $up++; }
                $detail[] = sprintf('%s=%s', str_replace($name . '-', '', $cn), $isUp ? 'UP' : 'DOWN');
            }
            $duCmd = 'du -sh ' . escapeshellarg($basePath . '/' . $name) . ' 2>/dev/null | cut -f1';
            $duRes = $ssh->executeCommand($duCmd, 30);
            $disk  = $duRes['success'] ? trim($duRes['output']) : 'N/A';

            if ($up === 3) { $totalUp++; }
            $rows[] = [
                'name'   => $name,
                'up'     => $up,
                'total'  => 3,
                'detail' => implode(' | ', $detail),
                'disk'   => $disk,
            ];
        }

        $totalInstances = count($rows);
        $headerColor = ($totalInstances > 0 && $totalUp === $totalInstances) ? '#27ae60' : '#e67e22';
        $html  = '<div style="margin:6px 0 10px;">'
               . '<span style="background:' . $headerColor . ';color:#fff;padding:4px 12px;border-radius:3px;font-size:12px;">'
               . 'TOTAL: ' . $totalInstances . ' instâncias · ' . $totalUp . ' totalmente UP'
               . '</span></div>';

        $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
               . '<tr style="background:#f5f5f5;">'
               . '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Instância</th>'
               . '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Containers</th>'
               . '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Detalhe</th>'
               . '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">Disco</th>'
               . '</tr>';
        foreach ($rows as $r) {
            $color = ($r['up'] === $r['total']) ? '#27ae60' : ($r['up'] > 0 ? '#e67e22' : '#e74c3c');
            $html .= '<tr>'
                  . '<td style="padding:6px 10px;border:1px solid #ddd;"><code>' . htmlspecialchars($r['name']) . '</code></td>'
                  . '<td style="padding:6px 10px;border:1px solid #ddd;color:' . $color . ';font-weight:bold;">' . $r['up'] . '/' . $r['total'] . '</td>'
                  . '<td style="padding:6px 10px;border:1px solid #ddd;">' . htmlspecialchars($r['detail']) . '</td>'
                  . '<td style="padding:6px 10px;border:1px solid #ddd;">' . htmlspecialchars($r['disk']) . '</td>'
                  . '</tr>';
        }
        $html .= '</table>';

        Helper::log('listAllInstances', ['count' => $totalInstances]);

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['nextcloudsaas_panel'] = [
            'type'      => 'instances_dashboard',
            'title'     => 'Instâncias Nextcloud no Servidor',
            'content'   => $html,
            'serviceid' => $params['serviceid'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];

    } catch (\Exception $e) {
        return 'Exceção ao listar instâncias: ' . $e->getMessage();
    }

    return 'success';
}

// =============================================================================
// FUNÇÕES AUXILIARES DE RENDERIZAÇÃO HTML
// =============================================================================

/**
 * Constrói HTML formatado com as credenciais da instância.
 *
 * @param string $clientName      Nome do cliente
 * @param string $domain          Domínio principal
 * @param array  $creds           Credenciais parseadas
 * @param string $serverIp        IP do servidor
 * @param string $collaboraDomain Domínio do Collabora
 * @param string $signalingDomain Domínio do Signaling
 * @return string HTML formatado
 */
function nextcloudsaas_buildCredentialsHtml($clientName, $domain, $creds, $serverIp, $collaboraDomain, $signalingDomain)
{
    $e = function($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); };

    $sections = [
        [
            'icon'  => '☁',
            'title' => 'Nextcloud',
            'color' => '#0082c9',
            'rows'  => [
                ['URL', '<a href="https://' . $e($domain) . '" target="_blank">https://' . $e($domain) . '</a>'],
                ['Utilizador', !empty($creds['nextcloud_user']) ? $e($creds['nextcloud_user']) : 'admin'],
                ['Password', !empty($creds['nextcloud_pass']) ? '<code>' . $e($creds['nextcloud_pass']) . '</code>' : '<em>(não disponível)</em>'],
            ],
        ],
        [
            'icon'  => '📝',
            'title' => 'Collabora Online',
            'color' => '#1b6a37',
            'rows'  => [
                ['URL', '<a href="https://' . $e($collaboraDomain) . '" target="_blank">https://' . $e($collaboraDomain) . '</a>'],
                ['Admin', 'admin'],
                ['Password', !empty($creds['collabora_pass']) ? '<code>' . $e($creds['collabora_pass']) . '</code>' : '<em>(não disponível)</em>'],
            ],
        ],
        [
            'icon'  => '🗄',
            'title' => 'Base de Dados (MariaDB)',
            'color' => '#c0392b',
            'rows'  => [
                ['Host', !empty($creds['db_host']) ? $e($creds['db_host']) : $e($clientName . '-db')],
                ['Database', !empty($creds['db_name']) ? $e($creds['db_name']) : 'nextcloud'],
                ['Utilizador', !empty($creds['db_user']) ? $e($creds['db_user']) : 'nextcloud'],
                ['Password', !empty($creds['db_password']) ? '<code>' . $e($creds['db_password']) . '</code>' : '<em>(não disponível)</em>'],
                ['Root Password', !empty($creds['db_root_password']) ? '<code>' . $e($creds['db_root_password']) . '</code>' : '<em>(não disponível)</em>'],
            ],
        ],
        [
            'icon'  => '📞',
            'title' => 'TURN Server',
            'color' => '#8e44ad',
            'rows'  => [
                ['Secret', !empty($creds['turn_secret']) ? '<code style="font-size:11px;">' . $e($creds['turn_secret']) . '</code>' : '<em>(não disponível)</em>'],
                ['Porta', !empty($creds['turn_port']) ? $e($creds['turn_port']) : '<em>(não disponível)</em>'],
                ['Endereço', 'turn:' . $e($serverIp) . ':' . (!empty($creds['turn_port']) ? $e($creds['turn_port']) : '?')],
            ],
        ],
        [
            'icon'  => '📡',
            'title' => 'Signaling Server (HPB)',
            'color' => '#2980b9',
            'rows'  => [
                ['URL', '<a href="https://' . $e($signalingDomain) . '" target="_blank">https://' . $e($signalingDomain) . '</a>'],
                ['Secret', !empty($creds['signaling_secret']) ? '<code style="font-size:11px;">' . $e($creds['signaling_secret']) . '</code>' : '<em>(não disponível)</em>'],
            ],
        ],
        [
            'icon'  => '🔗',
            'title' => 'HaRP (AppAPI)',
            'color' => '#e67e22',
            'rows'  => [
                ['Shared Key', !empty($creds['harp_shared_key']) ? '<code style="font-size:11px;">' . $e($creds['harp_shared_key']) . '</code>' : '<em>(não disponível)</em>'],
            ],
        ],
        [
            'icon'  => '🌐',
            'title' => 'DNS Necessários (Registro A)',
            'color' => '#34495e',
            'rows'  => [
                [$e($domain), $e($serverIp)],
                [$e($collaboraDomain), $e($serverIp)],
                [$e($signalingDomain), $e($serverIp)],
            ],
        ],
    ];

    $html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:12px;margin:10px 0;">';

    foreach ($sections as $sec) {
        $html .= '<div style="border:1px solid #ddd;border-radius:6px;overflow:hidden;background:#fff;">';
        $html .= '<div style="background:' . $sec['color'] . ';color:#fff;padding:8px 12px;font-weight:bold;font-size:13px;">';
        $html .= $sec['icon'] . ' ' . $sec['title'] . '</div>';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        foreach ($sec['rows'] as $row) {
            $html .= '<tr>';
            $html .= '<td style="padding:5px 10px;border-bottom:1px solid #f0f0f0;font-weight:bold;width:35%;color:#555;">' . $row[0] . '</td>';
            $html .= '<td style="padding:5px 10px;border-bottom:1px solid #f0f0f0;word-break:break-all;">' . $row[1] . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table></div>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Renderiza o painel de informações gravado na sessão PHP.
 *
 * Verifica se existe um painel pendente na sessão (credenciais, logs ou estado)
 * e retorna o HTML formatado para exibição no AdminServicesTabFields.
 *
 * @param int $serviceId ID do serviço atual
 * @return string|null HTML do painel ou null se não houver
 */
function nextcloudsaas_renderSessionPanel($serviceId)
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (empty($_SESSION['nextcloudsaas_panel'])) {
        return null;
    }

    $panel = $_SESSION['nextcloudsaas_panel'];

    // Verificar se é para este serviço
    if (isset($panel['serviceid']) && $panel['serviceid'] != $serviceId) {
        return null;
    }

    // Limpar a sessão (exibir apenas uma vez)
    unset($_SESSION['nextcloudsaas_panel']);

    $type = $panel['type'];
    $title = htmlspecialchars($panel['title'], ENT_QUOTES, 'UTF-8');
    $timestamp = htmlspecialchars($panel['timestamp'], ENT_QUOTES, 'UTF-8');

    if ($type === 'credentials') {
        // Conteúdo já é HTML formatado
        $bodyHtml = $panel['content'];
        $borderColor = '#0082c9';
        $headerBg = '#0082c9';
    } elseif ($type === 'logs') {
        $bodyHtml = '<pre style="background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;'
            . 'font-size:11px;line-height:1.4;max-height:400px;overflow:auto;white-space:pre-wrap;word-wrap:break-word;">'
            . htmlspecialchars($panel['content'], ENT_QUOTES, 'UTF-8') . '</pre>';
        $borderColor = '#e67e22';
        $headerBg = '#e67e22';
    } elseif ($type === 'status') {
        $content = $panel['content'];
        $runningCount = substr_count(strtolower($content), 'running');
        $totalContainers = 10;

        if ($runningCount >= $totalContainers) {
            $statusBadge = '<span style="background:#27ae60;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">'
                . 'ATIVO (' . $runningCount . '/' . $totalContainers . ')</span>';
            $borderColor = '#27ae60';
            $headerBg = '#27ae60';
        } elseif ($runningCount > 0) {
            $statusBadge = '<span style="background:#f39c12;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">'
                . 'PARCIAL (' . $runningCount . '/' . $totalContainers . ')</span>';
            $borderColor = '#f39c12';
            $headerBg = '#f39c12';
        } else {
            $statusBadge = '<span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">'
                . 'PARADO (0/' . $totalContainers . ')</span>';
            $borderColor = '#e74c3c';
            $headerBg = '#e74c3c';
        }

        $bodyHtml = '<div style="margin-bottom:8px;">' . $statusBadge . '</div>'
            . '<pre style="background:#f8f9fa;padding:10px;border-radius:4px;font-size:11px;'
            . 'line-height:1.4;max-height:300px;overflow:auto;white-space:pre-wrap;word-wrap:break-word;">'
            . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '</pre>';
    } elseif ($type === 'dns') {
        // Conteúdo DNS já é HTML formatado (tabela)
        $bodyHtml = $panel['content'];
        $borderColor = '#3498db';
        $headerBg = '#3498db';
    } else {
        return null;
    }

    $html = '<div style="border:2px solid ' . $borderColor . ';border-radius:8px;overflow:hidden;margin:10px 0;">';
    $html .= '<div style="background:' . $headerBg . ';color:#fff;padding:10px 15px;font-weight:bold;font-size:14px;">';
    $html .= $title;
    $html .= '<span style="float:right;font-size:11px;font-weight:normal;opacity:0.8;">' . $timestamp . '</span>';
    $html .= '</div>';
    $html .= '<div style="padding:15px;background:#fff;">' . $bodyHtml . '</div>';
    $html .= '</div>';

    return $html;
}

// =============================================================================
// CAMPOS EXTRA NO ADMIN (SERVICES TAB)
// =============================================================================

/**
 * Campos adicionais na aba de serviços do admin.
 *
 * Exibe informações detalhadas sobre a instância Nextcloud na página
 * de gestão do serviço no painel de administração do WHMCS.
 * Também renderiza painéis de credenciais, logs e estado quando
 * solicitados via botões personalizados.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return array
 */
function nextcloudsaas_AdminServicesTabFields(array $params)
{
    $fields = [];

    // Verificar se há um painel pendente na sessão (credenciais, logs ou estado)
    $panelHtml = nextcloudsaas_renderSessionPanel($params['serviceid']);
    if ($panelHtml !== null) {
        $fields['&nbsp;'] = $panelHtml;
    }

    try {
        $domain = isset($params['domain']) ? $params['domain'] : '';
        $clientName = nextcloudsaas_getClientName($params);

        if (!empty($domain)) {
            // v3.1.4 — Arquitetura compartilhada (manager v11.x):
            // Collabora/Signaling/TURN agora rodam como serviços globais
            // (`shared-*`) em hostnames próprios da Defensys e não exigem
            // DNS por cliente. Os campos URL do Collabora / URL do Signaling
            // / linhas extras de DNS Necessários deixaram de fazer sentido
            // na Admin Service Tab e foram removidos.

            $fields['Nome da Instância'] = '<strong>' . htmlspecialchars($clientName) . '</strong>';

            $fields['URL do Nextcloud'] = '<a href="https://' . htmlspecialchars($domain)
                . '" target="_blank">https://' . htmlspecialchars($domain) . '</a>';

            $fields['DNS Necessário (Registro A)'] = '<code>' . htmlspecialchars($domain) . '</code>';

            // Tentar obter estado dos containers (3 containers dedicados por
            // cliente na arquitetura v3.0.0+: <cliente>-app, <cliente>-cron,
            // <cliente>-harp). Os 8 serviços globais shared-* não entram
            // nessa contagem porque são compartilhados.
            try {
                $ssh = nextcloudsaas_getSSHManager($params);
                $statusResult = $ssh->statusInstance($clientName);

                if ($statusResult['success']) {
                    $output = $statusResult['output'];
                    // Contar containers running
                    $runningCount = substr_count(strtolower($output), 'running');
                    $totalContainers = 3;

                    if ($runningCount >= $totalContainers) {
                        $statusHtml = '<span style="color:green;font-weight:bold;">Ativo ('
                            . $runningCount . '/' . $totalContainers . ' containers dedicados)</span>';
                    } elseif ($runningCount > 0) {
                        $statusHtml = '<span style="color:orange;font-weight:bold;">Parcial ('
                            . $runningCount . '/' . $totalContainers . ' containers dedicados)</span>';
                    } else {
                        $statusHtml = '<span style="color:red;font-weight:bold;">Parado</span>';
                    }
                    $fields['Estado da Instância'] = $statusHtml;
                }

                // Obter uso de disco
                $diskResult = $ssh->getDiskUsage($clientName);
                if ($diskResult['success']) {
                    $fields['Uso de Disco'] = htmlspecialchars($diskResult['usage']);
                }
            } catch (\Exception $e) {
                $fields['Estado da Instância'] = '<span style="color:orange;">Não disponível</span>';
            }

            // Tentar obter informações via API
            try {
                $ncApi = nextcloudsaas_getNextcloudAPI($params);
                // O username do Nextcloud é sempre 'admin'
                $username = 'admin';
                if (!empty($username)) {
                    $storageInfo = $ncApi->getUserStorageInfo($username);
                    if ($storageInfo['success']) {
                        $fields['Armazenamento Usado'] = Helper::formatQuota($storageInfo['data']['used']);
                        $fields['Quota Total'] = Helper::formatQuota($storageInfo['data']['quota']);
                        $fields['Uso (%)'] = $storageInfo['data']['relative'] . '%';
                    }
                }
            } catch (\Exception $e) {
                // Silenciar
            }
        }

    } catch (\Exception $e) {
        $fields['Erro'] = htmlspecialchars($e->getMessage());
    }

    return $fields;
}

// =============================================================================
// ATUALIZAÇÃO DE USO (CRON)
// =============================================================================

/**
 * Atualização diária de uso de disco.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string "success" ou mensagem de erro
 */
function nextcloudsaas_UsageUpdate(array $params)
{
    try {
        if (!function_exists('localAPI')) {
            return 'success';
        }

        $services = localAPI('GetClientsProducts', [
            'serverid' => $params['serverid'],
            'status'   => 'Active',
        ]);

        if (!isset($services['products']['product'])) {
            return 'success';
        }

        foreach ($services['products']['product'] as $service) {
            try {
                $domain = $service['domain'];
                $username = $service['username'];

                if (empty($domain) || empty($username)) {
                    continue;
                }

                $ncApi = nextcloudsaas_getNextcloudAPI($params, $domain);
                $storageInfo = $ncApi->getUserStorageInfo($username);

                if ($storageInfo['success']) {
                    $usedMB = round($storageInfo['data']['used'] / (1024 * 1024));
                    $quotaMB = round($storageInfo['data']['quota'] / (1024 * 1024));

                    localAPI('UpdateClientProduct', [
                        'serviceid' => $service['id'],
                        'diskusage' => $usedMB,
                        'disklimit' => $quotaMB,
                    ]);
                }
            } catch (\Exception $e) {
                Helper::log('UsageUpdate', ['domain' => $domain], $e->getMessage());
                continue;
            }
        }

    } catch (\Exception $e) {
        Helper::log('UsageUpdate', $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// =============================================================================
// ÁREA DE CLIENTE
// =============================================================================

/**
 * Output da área de cliente.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return array
 */
function nextcloudsaas_ClientArea(array $params)
{
    $domain = isset($params['domain']) ? $params['domain'] : '';
    // O username do Nextcloud é sempre 'admin'
    $username = 'admin';
    $clientName = nextcloudsaas_getClientName($params);

    $collaboraDomain = Helper::getCollaboraDomain($domain);
    $signalingDomain = Helper::getSignalingDomain($domain);

    // Obter IP do servidor
    $serverConfig = Helper::getServerConfig($params);
    $serverIp = !empty($serverConfig['ip']) ? $serverConfig['ip'] : $serverConfig['hostname'];

    // Dados base para o template
    $templateVars = [
        'domain'           => $domain,
        'username'         => $username,
        'clientName'       => $clientName,
        'nextcloudUrl'     => !empty($domain) ? 'https://' . $domain : '',
        'collaboraUrl'     => !empty($domain) ? 'https://' . $collaboraDomain : '',
        'signalingUrl'     => !empty($domain) ? 'https://' . $signalingDomain : '',
        'collaboraDomain'  => $collaboraDomain,
        'signalingDomain'  => $signalingDomain,
        'serverIp'         => $serverIp,
        'serviceid'        => $params['serviceid'],
        'storageUsed'      => 'N/A',
        'storageQuota'     => 'N/A',
        'storagePercent'   => 0,
        'instanceStatus'   => 'Desconhecido',
        'statusColor'      => 'warning',
        'containersTotal'  => 10,
        'containersUp'     => 0,
        // Credenciais do .credentials (preenchidas via SSH)
        'credsRaw'              => '',
        'credsDate'             => '',
        'ncUser'                => 'admin',
        'ncPass'                => '',
        'collaboraAdmin'        => '',
        'collaboraPass'         => '',
        'dbHost'                => '',
        'dbName'                => 'nextcloud',
        'dbUser'                => 'nextcloud',
        'dbPass'                => '',
        'dbRootPass'            => '',
        'turnSecret'            => '',
        'turnPort'              => '',
        'turnAddress'           => '',
        'signalingSecret'       => '',
    ];

    // Tentar obter credenciais completas do .credentials via SSH
    if (!empty($clientName)) {
        try {
            $ssh = nextcloudsaas_getSSHManager($params);

            // Buscar ficheiro .credentials completo
            $credsResult = $ssh->getCredentials($clientName);
            if ($credsResult['success']) {
                $creds = $credsResult['credentials'];
                $templateVars['credsRaw']        = $credsResult['raw'];
                $templateVars['ncUser']          = !empty($creds['nextcloud_user']) ? $creds['nextcloud_user'] : 'admin';
                $templateVars['ncPass']          = !empty($creds['nextcloud_pass']) ? $creds['nextcloud_pass'] : '';
                $templateVars['collaboraAdmin']   = 'admin';
                $templateVars['collaboraPass']    = !empty($creds['collabora_pass']) ? $creds['collabora_pass'] : '';
                $templateVars['dbHost']           = !empty($creds['db_host']) ? $creds['db_host'] : '';
                $templateVars['dbPass']           = !empty($creds['db_password']) ? $creds['db_password'] : '';
                $templateVars['dbRootPass']       = !empty($creds['db_root_password']) ? $creds['db_root_password'] : '';
                $templateVars['turnSecret']       = !empty($creds['turn_secret']) ? $creds['turn_secret'] : '';
                $templateVars['turnPort']         = !empty($creds['turn_port']) ? $creds['turn_port'] : '';
                $templateVars['turnAddress']      = !empty($creds['turn_address']) ? $creds['turn_address'] : (!empty($creds['turn_port']) ? 'turn:' . $serverIp . ':' . $creds['turn_port'] : '');
                $templateVars['signalingSecret']  = !empty($creds['signaling_secret']) ? $creds['signaling_secret'] : '';
                // v3.1.7: HaRP Shared Key removida do painel do cliente
                // (credencial interna do AppAPI; o container dedicado
                // <cliente>-harp continua existindo e operacional).

                // Extrair data de criação do raw (usar strpos para evitar problemas UTF-8)
                $striposFunc = function_exists('mb_stripos') ? 'mb_stripos' : 'stripos';
                foreach (explode("\n", $credsResult['raw']) as $rawLine) {
                    if ($striposFunc($rawLine, 'Data de cria') !== false && strpos($rawLine, ':') !== false) {
                        $colonPos = strpos($rawLine, ':');
                        $templateVars['credsDate'] = trim(substr($rawLine, $colonPos + 1));
                        break;
                    }
                }

                // Usar db_name e db_user do .credentials se disponíveis
                $templateVars['dbName']           = !empty($creds['db_name']) ? $creds['db_name'] : 'nextcloud';
                $templateVars['dbUser']           = !empty($creds['db_user']) ? $creds['db_user'] : 'nextcloud';
            }

            // Obter estado dos containers
            $statusResult = $ssh->statusInstance($clientName);
            if ($statusResult['success']) {
                $output = strtolower($statusResult['output']);
                $runningCount = substr_count($output, 'running');
                $templateVars['containersUp'] = $runningCount;

                if ($runningCount >= 10) {
                    $templateVars['instanceStatus'] = 'Ativo';
                    $templateVars['statusColor'] = 'success';
                } elseif ($runningCount > 0) {
                    $templateVars['instanceStatus'] = 'Parcial';
                    $templateVars['statusColor'] = 'warning';
                } else {
                    $templateVars['instanceStatus'] = 'Parado';
                    $templateVars['statusColor'] = 'danger';
                }
            }

            // Obter uso de disco via SSH (du -sh)
            $diskResult = $ssh->getDiskUsage($clientName);
            if ($diskResult['success'] && $diskResult['usage'] !== 'N/A') {
                $templateVars['storageUsed'] = $diskResult['usage'];
            }
        } catch (\Exception $e) {
            // Silenciar — as credenciais ficam vazias
        }
    }

    return [
        'tabOverviewReplacementTemplate' => 'templates/clientarea.tpl',
        'templateVariables'              => $templateVars,
    ];
}

// =============================================================================
// FUNÇÕES AUXILIARES
// =============================================================================

/**
 * Guardar um valor num campo personalizado do serviço.
 *
 * @param int    $serviceId ID do serviço
 * @param string $fieldName Nome do campo
 * @param string $value     Valor a guardar
 */
function nextcloudsaas_saveCustomField($serviceId, $fieldName, $value)
{
    if (!function_exists('localAPI')) {
        return;
    }

    try {
        $result = localAPI('GetClientsProducts', [
            'serviceid' => $serviceId,
        ]);

        if (isset($result['products']['product'][0]['customfields']['customfield'])) {
            foreach ($result['products']['product'][0]['customfields']['customfield'] as $field) {
                if ($field['name'] === $fieldName) {
                    localAPI('UpdateClientProduct', [
                        'serviceid'    => $serviceId,
                        'customfields' => base64_encode(serialize([$field['id'] => $value])),
                    ]);
                    return;
                }
            }
        }
    } catch (\Exception $e) {
        Helper::log('saveCustomField', [
            'serviceId' => $serviceId,
            'fieldName' => $fieldName,
        ], $e->getMessage());
    }
}

// =============================================================================
// LINKS DE ADMINISTRAÇÃO
// =============================================================================

/**
 * Link de administração do servidor.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string HTML do link
 */
function nextcloudsaas_AdminLink(array $params)
{
    $serverConfig = Helper::getServerConfig($params);
    $host = !empty($serverConfig['hostname']) ? $serverConfig['hostname'] : $serverConfig['ip'];

    return '<a href="https://' . htmlspecialchars($host) . '" target="_blank">'
        . 'Aceder ao Servidor Nextcloud SaaS</a>';
}

/**
 * Link de login do serviço.
 *
 * @param array $params Parâmetros comuns do módulo
 * @return string HTML do link
 */
function nextcloudsaas_LoginLink(array $params)
{
    $domain = isset($params['domain']) ? $params['domain'] : '';

    if (empty($domain)) {
        return '';
    }

    return '<a href="https://' . htmlspecialchars($domain) . '/login" target="_blank">'
        . 'Aceder ao Nextcloud do Cliente</a>';
}
