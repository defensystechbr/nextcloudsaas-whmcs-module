<?php
/**
 * Classe utilitária para o módulo Nextcloud-SaaS
 *
 * Contém funções auxiliares para geração de passwords, validação
 * de domínios, formatação de quotas e outras utilidades comuns.
 * Alinhado com a arquitetura real do manage.sh v11.3+ (Nextcloud SaaS
 * Manager v11.x), que utiliza Traefik + 3 containers por instância
 * (app, cron, harp) + 8 serviços compartilhados globais (shared-db,
 * shared-redis, shared-collabora, shared-turn, shared-nats,
 * shared-janus, shared-signaling, shared-recording) e exige apenas
 * 1 (um) registro DNS A por instância de cliente, apontando para o IP
 * do servidor.
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    3.0.0
 */

namespace NextcloudSaaS;

class Helper
{
    /**
     * Diretório base das instâncias no servidor
     */
    const BASE_DIR = '/opt/nextcloud-customers';

    /**
     * Caminho do script manage.sh no servidor
     */
    const MANAGE_SCRIPT = '/opt/nextcloud-customers/manage.sh';

    /**
     * Lista de sufixos dos 3 containers por instância (arquitetura
     * compartilhada do Nextcloud SaaS Manager v11.0+).
     *
     * @see https://github.com/defensystechbr/nextcloud-saas-manager
     */
    const CONTAINER_SUFFIXES = [
        'app', 'cron', 'harp',
    ];

    /**
     * Lista de containers globais (shared-services) introduzidos na
     * arquitetura compartilhada (v11.0+). Não pertencem a nenhuma
     * instância individual e são pré-requisito para qualquer
     * `manage.sh create`.
     */
    const SHARED_CONTAINERS = [
        'shared-db',
        'shared-redis',
        'shared-collabora',
        'shared-turn',
        'shared-nats',
        'shared-janus',
        'shared-signaling',
        'shared-recording',
    ];

    /**
     * Gerar uma password segura aleatória
     * Usa o mesmo padrão do manage.sh (25 caracteres alfanuméricos)
     *
     * @param int $length Comprimento da password (default: 25, igual ao manage.sh)
     * @return string Password gerada
     */
    public static function generatePassword($length = 25)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    /**
     * Validar formato de domínio
     *
     * @param string $domain Domínio a validar
     * @return bool
     */
    public static function isValidDomain($domain)
    {
        return (bool) preg_match(
            '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
            $domain
        );
    }

    /**
     * Obter o domínio efetivo da instância para o serviço atual.
     *
     * Estratégia (v3.1.2):
     *  1. `$params['domain']` (campo padrão do WHMCS, populado pelo hook
     *     AfterShoppingCartCheckout no fluxo de carrinho).
     *  2. Fallback: percorre `$params['customfields']` procurando chaves
     *     comuns (com e sem acento, em PT/EN). Útil quando o pedido foi
     *     criado pelo admin via "Add New Order" — fluxo no qual o hook
     *     AfterShoppingCartCheckout não dispara, e portanto o domain
     *     não é copiado automaticamente para tblhosting.domain.
     *  3. Fallback final: consulta direta a tblcustomfields/tblcustomfieldsvalues
     *     pelo `serviceid`/`relid` quando disponível.
     *
     * Devolve string vazia caso nada seja encontrado, deixando que o
     * chamador (ex.: CreateAccount) emita uma mensagem de erro específica.
     *
     * @param array $params Pâros padrão do módulo WHMCS (services/nextcloudsaas)
     * @return string Domínio em minúsculas e sem prefixos http(s):// ou trailing slash
     */
    public static function getDomain(array $params)
    {
        $clean = function ($v) {
            $v = strtolower(trim((string)$v));
            $v = preg_replace('#^https?://#', '', $v);
            $v = preg_replace('#^www\.#', '', $v);
            $v = rtrim($v, '/');
            return $v;
        };

        // 1. Campo padrão do WHMCS
        if (!empty($params['domain'])) {
            return $clean($params['domain']);
        }

        // 2. Custom fields no array de params
        $candidateKeys = [
            'Domínio da Instância',
            'Dominio da Instancia',
            'Domínio',
            'Dominio',
            'Domain',
            'Hostname',
            'Instance Domain',
        ];
        if (!empty($params['customfields']) && is_array($params['customfields'])) {
            foreach ($candidateKeys as $key) {
                if (!empty($params['customfields'][$key])) {
                    return $clean($params['customfields'][$key]);
                }
            }
            // Fuzzy match: qualquer chave que contenha "dom" e "inst".
            foreach ($params['customfields'] as $name => $value) {
                if (empty($value)) {
                    continue;
                }
                $lc = strtolower((string)$name);
                if (strpos($lc, 'dom') !== false && strpos($lc, 'inst') !== false) {
                    return $clean($value);
                }
            }
        }

        // 3. Consulta direta ao banco (último recurso) — útil quando
        // o pedido foi criado pelo admin e o WHMCS não populou customfields.
        $serviceId = isset($params['serviceid']) ? (int)$params['serviceid'] : 0;
        $productId = isset($params['pid']) ? (int)$params['pid'] : 0;
        if ($serviceId > 0 && $productId > 0 && class_exists('\\WHMCS\\Database\\Capsule')) {
            try {
                $rows = \WHMCS\Database\Capsule::table('tblcustomfields')
                    ->where('relid', $productId)
                    ->where('type', 'product')
                    ->get(['id', 'fieldname']);
                foreach ($rows as $row) {
                    $fname = strtolower((string)$row->fieldname);
                    $matches = strpos($fname, 'dom') !== false
                        && (strpos($fname, 'inst') !== false || strpos($fname, 'host') !== false || $fname === 'domain' || $fname === 'domínio' || $fname === 'dominio');
                    if (!$matches) {
                        continue;
                    }
                    $val = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $row->id)
                        ->where('relid', $serviceId)
                        ->value('value');
                    if (!empty($val)) {
                        return $clean($val);
                    }
                }
            } catch (\Throwable $e) {
                // silencioso: estamos numa rota de fallback.
            }
        }

        return '';
    }

    /**
     * Hostnames globais dos serviços compartilhados expostos pelo
     * Traefik (v11.0+). São informativos para mostrar ao cliente em
     * "requisitos do servidor", mas o cliente NÃO precisa criar
     * registros DNS para eles — eles já existem no provedor da Defensys.
     *
     * Os valores reais são lidos do servidor via SSH/configuração; os
     * defaults aqui servem apenas como pista para o painel do admin.
     */
    const SHARED_HOSTNAMES_DEFAULT = [
        'collabora' => 'collabora-01.defensys.seg.br',
        'signaling' => 'signaling-01.defensys.seg.br',
        'turn'      => 'turn-01.defensys.seg.br',
    ];

    /**
     * Obter os hostnames globais dos serviços compartilhados.
     *
     * Permite override pelos parâmetros do produto WHMCS (configoption7..9)
     * caso o servidor seja deployado com domínios diferentes.
     *
     * @param array $params Parâmetros do módulo WHMCS (opcional)
     * @return array ['collabora'=>..., 'signaling'=>..., 'turn'=>...]
     */
    public static function getSharedHostnames(array $params = [])
    {
        $defaults = self::SHARED_HOSTNAMES_DEFAULT;
        $cfg = self::getProductConfig($params);
        if (!empty($cfg['collabora_hostname'])) {
            $defaults['collabora'] = $cfg['collabora_hostname'];
        }
        if (!empty($cfg['signaling_hostname'])) {
            $defaults['signaling'] = $cfg['signaling_hostname'];
        }
        if (!empty($cfg['turn_hostname'])) {
            $defaults['turn'] = $cfg['turn_hostname'];
        }
        return $defaults;
    }

    /**
     * @deprecated v3.0.0 — Removido pela arquitetura compartilhada.
     *             O Collabora Online agora é um único serviço global
     *             (`shared-collabora`) acessado via
     *             `collabora-01.defensys.seg.br` (configurável). Mantido
     *             apenas como stub para compatibilidade reversa de
     *             qualquer hook/template antigo; retorna o hostname
     *             global do Collabora.
     */
    public static function getCollaboraDomain($domain)
    {
        return self::SHARED_HOSTNAMES_DEFAULT['collabora'];
    }

    /**
     * @deprecated v3.0.0 — Removido pela arquitetura compartilhada.
     *             O Talk High-Performance Backend agora é um único
     *             serviço global (`shared-signaling`) acessado via
     *             `signaling-01.defensys.seg.br` (configurável).
     *             Mantido como stub; retorna o hostname global.
     */
    public static function getSignalingDomain($domain)
    {
        return self::SHARED_HOSTNAMES_DEFAULT['signaling'];
    }

    /**
     * Obter o(s) registro(s) DNS necessário(s) para uma instância de
     * cliente.
     *
     * A partir da v3.0.0 o módulo está alinhado ao Nextcloud SaaS
     * Manager v11.x, que exige apenas **1 (um) registro A** por cliente
     * apontando para o IP do servidor (o domínio principal do Nextcloud).
     * Os antigos `collabora-` e `signaling-` deixaram de existir por
     * cliente — agora são serviços globais compartilhados.
     *
     * @param string $domain Domínio principal do Nextcloud do cliente
     * @return array Lista associativa de domínios obrigatórios
     */
    public static function getRequiredDomains($domain)
    {
        return [
            'nextcloud' => $domain,
        ];
    }

    /**
     * Converter quota em formato legível
     *
     * @param int $bytes Quota em bytes
     * @return string Quota formatada (ex: "5.2 GB")
     */
    public static function formatQuota($bytes)
    {
        if ($bytes <= 0) {
            return 'Ilimitado';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 2) . ' ' . $units[$i];
    }

    /**
     * Converter quota de texto para formato Nextcloud
     *
     * @param string $quota Quota em formato legível (ex: "5", "10")
     * @return string Quota no formato Nextcloud (ex: "5 GB")
     */
    public static function formatQuotaForNextcloud($quota)
    {
        $quota = trim($quota);

        if (preg_match('/\d+\s*(GB|MB|TB|KB)/i', $quota)) {
            return $quota;
        }

        if (is_numeric($quota)) {
            return $quota . ' GB';
        }

        if (in_array(strtolower($quota), ['none', 'unlimited', 'ilimitado', '0'])) {
            return 'none';
        }

        return $quota;
    }

    /**
     * Registar uma mensagem no log do módulo WHMCS
     *
     * @param string $action  Ação realizada
     * @param mixed  $request Dados do pedido
     * @param mixed  $response Dados da resposta
     * @param mixed  $data    Dados adicionais
     */
    public static function log($action, $request = '', $response = '', $data = '')
    {
        if (function_exists('logModuleCall')) {
            logModuleCall(
                'nextcloudsaas',
                $action,
                is_array($request) ? json_encode($request) : $request,
                is_array($response) ? json_encode($response) : $response,
                is_array($data) ? json_encode($data) : $data
            );
        }
    }

    /**
     * Obter a configuração do servidor a partir dos parâmetros WHMCS
     *
     * @param array $params Parâmetros do módulo WHMCS
     * @return array Configuração do servidor
     */
    public static function getServerConfig($params)
    {
        return [
            'hostname'   => isset($params['serverhostname']) ? $params['serverhostname'] : '',
            'ip'         => isset($params['serverip']) ? $params['serverip'] : '',
            'username'   => isset($params['serverusername']) ? $params['serverusername'] : 'defensys',
            'password'   => isset($params['serverpassword']) ? $params['serverpassword'] : '',
            'accesshash' => isset($params['serveraccesshash']) ? $params['serveraccesshash'] : '',
            'secure'     => isset($params['serversecure']) ? $params['serversecure'] : false,
            'port'       => isset($params['serverport']) ? (int) $params['serverport'] : 22,
        ];
    }

    /**
     * Obter a configuração do produto a partir dos parâmetros WHMCS
     *
     * Alinhado com as ConfigOptions definidas em nextcloudsaas_ConfigOptions():
     *   configoption1 = Quota de Armazenamento (GB)
     *   configoption2 = Máximo de Utilizadores
     *   configoption3 = Collabora Online (yesno)
     *   configoption4 = Nextcloud Talk HPB (yesno)
     *   configoption5 = Caminho da Chave SSH
     *   configoption6 = Prefixo do Nome do Cliente
     *
     * @param array $params Parâmetros do módulo WHMCS
     * @return array Configuração do produto
     */
    public static function getProductConfig($params)
    {
        return [
            'disk_quota_gb'     => isset($params['configoption1']) ? $params['configoption1'] : '10',
            'max_users'         => isset($params['configoption2']) ? $params['configoption2'] : '5',
            'enable_collabora'  => isset($params['configoption3']) ? $params['configoption3'] : 'on',
            'enable_talk'       => isset($params['configoption4']) ? $params['configoption4'] : 'on',
            'ssh_key_path'      => isset($params['configoption5']) ? $params['configoption5'] : '',
            'client_prefix'     => isset($params['configoption6']) ? $params['configoption6'] : '',
            // v3.0.0 — overrides opcionais dos hostnames globais (caso
            // o servidor não use os defaults da Defensys).
            'collabora_hostname' => isset($params['configoption7']) ? $params['configoption7'] : '',
            'signaling_hostname' => isset($params['configoption8']) ? $params['configoption8'] : '',
            'turn_hostname'      => isset($params['configoption9']) ? $params['configoption9'] : '',
        ];
    }

    /**
     * Parsear a saída do manage.sh para extrair credenciais
     * O manage.sh gera um ficheiro .credentials com formato textual
     *
     * @param string $output Saída do script
     * @return array Dados parseados
     */
    public static function parseManageOutput($output)
    {
        $data = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0 || strpos($line, '[') === 0) {
                continue;
            }
            // Capturar linhas com formato "Senha:   VALOR" ou "URL: VALOR"
            if (preg_match('/^\s*(Senha|Usuário|URL|Porta|Secret):\s*(.+)$/i', $line, $matches)) {
                $key = strtolower(trim($matches[1]));
                $data[$key] = trim($matches[2]);
            }
            // Capturar linhas KEY=VALUE
            if (strpos($line, '=') !== false && !preg_match('/^\[/', $line)) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $data[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        return $data;
    }

    /**
     * Parsear o ficheiro .credentials de uma instância
     * O manage.sh gera este ficheiro com informações de todas as credenciais
     *
     * @param string $credentialsContent Conteúdo do ficheiro .credentials
     * @return array Credenciais parseadas
     */
    public static function parseCredentials($credentialsContent)
    {
        $creds = [
            'nextcloud_url'      => '',
            'nextcloud_user'     => '',
            'nextcloud_pass'     => '',
            'collabora_url'      => '',
            'collabora_pass'     => '',
            'db_host'            => '',
            'db_name'            => 'nextcloud',
            'db_user'            => 'nextcloud',
            'db_password'        => '',
            'db_root_password'   => '',
            'turn_secret'        => '',
            'turn_port'          => '',
            'turn_address'       => '',
            'signaling_url'      => '',
            'signaling_secret'   => '',
            'harp_shared_key'    => '',
        ];

        $lines = explode("\n", $credentialsContent);
        $section = '';

        foreach ($lines as $line) {
            $line = trim($line);

            // Detetar secções
            if (strpos($line, 'Nextcloud:') === 0) { $section = 'nextcloud'; continue; }
            if (strpos($line, 'Collabora') === 0) { $section = 'collabora'; continue; }
            if (strpos($line, 'Banco de Dados') === 0) { $section = 'db'; continue; }
            if (strpos($line, 'TURN') === 0) { $section = 'turn'; continue; }
            if (strpos($line, 'Signaling') === 0) { $section = 'signaling'; continue; }
            if (strpos($line, 'HaRP') === 0) { $section = 'harp'; continue; }

            // Parsear campos — usar strpos para evitar problemas com UTF-8/acentos
            // Extrair valor após ":" em cada linha
            $colonPos = strpos($line, ':');
            if ($colonPos === false) continue;

            $fieldName = trim(substr($line, 0, $colonPos));
            $fieldValue = trim(substr($line, $colonPos + 1));

            // Mapear campos por secção
            switch ($fieldName) {
                case 'URL':
                    if ($section === 'nextcloud') $creds['nextcloud_url'] = $fieldValue;
                    if ($section === 'collabora') $creds['collabora_url'] = $fieldValue;
                    if ($section === 'signaling') $creds['signaling_url'] = $fieldValue;
                    break;
                case 'Admin':
                    // Collabora admin user — ignorar (sempre 'admin')
                    break;
                case 'Host':
                    if ($section === 'db') $creds['db_host'] = $fieldValue;
                    break;
                case 'Database':
                    if ($section === 'db') $creds['db_name'] = $fieldValue;
                    break;
                case 'Root Password':
                    if ($section === 'db') $creds['db_root_password'] = $fieldValue;
                    break;
                case 'Secret':
                    if ($section === 'turn') $creds['turn_secret'] = $fieldValue;
                    if ($section === 'signaling') $creds['signaling_secret'] = $fieldValue;
                    break;
                case 'Porta':
                    if ($section === 'turn') $creds['turn_port'] = $fieldValue;
                    break;
                case 'Shared Key':
                    $creds['harp_shared_key'] = $fieldValue;
                    break;
                default:
                    // Campos com acentos UTF-8: Usuário, Senha, Endereço
                    // Usar mb_stripos se disponível, senão stripos como fallback
                    $striposFunc = function_exists('mb_stripos') ? 'mb_stripos' : 'stripos';
                    if ($striposFunc($fieldName, 'usu') === 0 || $fieldName === 'Usuario') {
                        if ($section === 'nextcloud') $creds['nextcloud_user'] = $fieldValue;
                        if ($section === 'db') $creds['db_user'] = $fieldValue;
                    } elseif ($striposFunc($fieldName, 'Senha') === 0 || $fieldName === 'Senha') {
                        if ($section === 'nextcloud') $creds['nextcloud_pass'] = $fieldValue;
                        if ($section === 'collabora') $creds['collabora_pass'] = $fieldValue;
                        if ($section === 'db') $creds['db_password'] = $fieldValue;
                    } elseif ($striposFunc($fieldName, 'Endere') === 0 || $fieldName === 'Endereco') {
                        if ($section === 'turn') $creds['turn_address'] = $fieldValue;
                    }
                    break;
            }
        }

        return $creds;
    }

    /**
     * Verificar se o(s) registro(s) DNS necessário(s) para uma
     * instância estão configurados corretamente.
     *
     * A partir da v3.0.0 valida apenas o domínio principal do
     * Nextcloud do cliente — os antigos `collabora-` e `signaling-`
     * por cliente foram eliminados pela arquitetura compartilhada
     * (manage.sh v11.x). O cliente só precisa apontar 1 registro A.
     *
     * @param string $domain   Domínio principal da instância (do cliente)
     * @param string $serverIp IP esperado do servidor (vem de `tblservers`)
     * @return array {
     *     @type bool   $success Sempre true (a função executou).
     *     @type array  $results Por tipo de domínio: hostname, expected,
     *                            resolved, correct.
     *     @type bool   $all_ok  Se todos os domínios obrigatórios estão OK.
     *     @type string $message Texto pronto para exibir/log.
     * }
     */
    public static function checkDnsRecords($domain, $serverIp)
    {
        $domains = self::getRequiredDomains($domain);
        $results = [];
        $allOk = true;
        $messages = [];

        foreach ($domains as $type => $hostname) {
            $resolved = @dns_get_record($hostname, DNS_A);
            $ips = [];

            if ($resolved && is_array($resolved)) {
                foreach ($resolved as $record) {
                    if (isset($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                }
            }

            // Fallback: tentar gethostbyname se dns_get_record falhar
            if (empty($ips)) {
                $ip = @gethostbyname($hostname);
                if ($ip !== $hostname) {
                    $ips[] = $ip;
                }
            }

            $isCorrect = in_array($serverIp, $ips);

            $results[$type] = [
                'hostname'  => $hostname,
                'expected'  => $serverIp,
                'resolved'  => $ips,
                'correct'   => $isCorrect,
            ];

            if (!$isCorrect) {
                $allOk = false;
                if (empty($ips)) {
                    $messages[] = "{$hostname}: sem registro DNS";
                } else {
                    $messages[] = "{$hostname}: aponta para " . implode(', ', $ips) . " (esperado: {$serverIp})";
                }
            }
        }

        $total = count($domains);
        return [
            'success' => true,
            'results' => $results,
            'all_ok'  => $allOk,
            'message' => $allOk
                ? sprintf('Registro DNS configurado corretamente (%d/%d).', $total, $total)
                : 'DNS incompleto: ' . implode(' | ', $messages),
        ];
    }

    /**
     * Obter os nomes dos containers de uma instância (arquitetura
     * compartilhada v11.x: 3 containers por cliente — `app`, `cron`,
     * `harp`).
     *
     * @param string $clientName Nome do cliente
     * @return array Lista de nomes de containers indexada pelo sufixo
     */
    public static function getContainerNames($clientName)
    {
        $containers = [];
        foreach (self::CONTAINER_SUFFIXES as $suffix) {
            $containers[$suffix] = $clientName . '-' . $suffix;
        }
        return $containers;
    }

    /**
     * Obter os nomes dos containers globais (shared-services) que
     * precisam estar `Up` no servidor para qualquer instância funcionar.
     *
     * @return array Lista plana de nomes de containers
     */
    public static function getSharedContainerNames()
    {
        return self::SHARED_CONTAINERS;
    }
}
