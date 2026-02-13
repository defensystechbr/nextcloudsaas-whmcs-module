<?php
/**
 * SSH Manager para comunicação com o servidor de destino
 *
 * Esta classe gere a conexão SSH ao servidor onde as instâncias
 * Nextcloud são hospedadas. Integra-se diretamente com o manage.sh
 * v10.0 existente no servidor, que é o script principal de gestão
 * de instâncias Nextcloud SaaS.
 *
 * Utiliza a biblioteca phpseclib3 (PHP puro) como método principal
 * de conexão SSH, sem necessidade de extensões nativas como php-ssh2.
 * Mantém fallback para ssh2 e sshpass como alternativas.
 *
 * @package    NextcloudSaaS
 * @author     Manus AI / Defensys
 * @copyright  2026
 * @version    2.2.0
 */

namespace NextcloudSaaS;

// Carregar autoloader do composer (phpseclib3)
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class SSHManager
{
    /**
     * @var string Endereço IP ou hostname do servidor
     */
    private $host;

    /**
     * @var int Porta SSH
     */
    private $port;

    /**
     * @var string Utilizador SSH
     */
    private $username;

    /**
     * @var string Password SSH (se aplicável)
     */
    private $password;

    /**
     * @var string Caminho para a chave SSH privada (se aplicável)
     */
    private $privateKeyPath;

    /**
     * @var string Password da chave SSH (se aplicável)
     */
    private $keyPassphrase;

    /**
     * @var int Timeout da conexão em segundos
     */
    private $timeout;

    /**
     * @var string Caminho base das instâncias no servidor remoto
     */
    private $basePath;

    /**
     * @var string Caminho do manage.sh no servidor remoto
     */
    private $manageScript;

    /**
     * Construtor da classe SSHManager
     *
     * @param string $host           Endereço do servidor
     * @param string $username       Utilizador SSH
     * @param string $password       Password SSH (ou vazio para usar chave)
     * @param string $privateKeyPath Caminho para a chave privada SSH (ou vazio para usar password)
     * @param int    $port           Porta SSH (default: 22)
     * @param string $keyPassphrase  Passphrase da chave (default: vazio)
     * @param int    $timeout        Timeout em segundos (default: 300 para operações longas)
     * @param string $basePath       Caminho base no servidor (default: /opt/nextcloud-customers)
     */
    public function __construct(
        $host,
        $username,
        $password = '',
        $privateKeyPath = '',
        $port = 22,
        $keyPassphrase = '',
        $timeout = 300,
        $basePath = '/opt/nextcloud-customers'
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->privateKeyPath = $privateKeyPath;
        $this->keyPassphrase = $keyPassphrase;
        $this->timeout = $timeout;
        $this->basePath = rtrim($basePath, '/');
        $this->manageScript = $this->basePath . '/manage.sh';
    }

    /**
     * Criar instância de SSHManager a partir dos parâmetros WHMCS
     *
     * @param array $params Parâmetros do módulo WHMCS
     * @return self
     */
    public static function fromWhmcsParams($params)
    {
        $server = Helper::getServerConfig($params);
        $product = Helper::getProductConfig($params);

        $host = !empty($server['ip']) ? $server['ip'] : $server['hostname'];

        return new self(
            $host,
            $server['username'],
            $server['password'],
            !empty($product['ssh_key_path']) ? $product['ssh_key_path'] : $server['accesshash'],
            $server['port'] > 0 ? $server['port'] : 22,
            '',
            300,
            '/opt/nextcloud-customers'
        );
    }

    /**
     * Encapsular um comando com sudo para execução não-interativa.
     *
     * Requer que o utilizador SSH tenha NOPASSWD configurado no sudoers
     * do servidor remoto (ficheiro /etc/sudoers.d/defensys-whmcs).
     *
     * Usa sudo -n (non-interactive) que nunca pede password — se o
     * NOPASSWD não estiver configurado, o comando falha imediatamente
     * em vez de ficar à espera de input (o que causaria timeout no phpseclib).
     *
     * @param string $command Comando a executar com sudo
     * @return string Comando encapsulado com sudo -n
     */
    private function wrapWithSudo($command)
    {
        // Se o utilizador é root, não precisa de sudo
        if ($this->username === 'root') {
            return $command;
        }

        // Usar sudo -n (non-interactive) — requer NOPASSWD no sudoers
        // Redirecionar stderr do sudo para /dev/null para evitar mensagens
        // como "[sudo] password for ..." no output
        return 'sudo -n ' . $command . ' 2>&1';
    }

    /**
     * Executa um comando remoto via SSH
     *
     * Tenta os seguintes métodos em ordem:
     * 1. phpseclib3 (PHP puro - funciona em qualquer servidor)
     * 2. Extensão ssh2 do PHP (se disponível)
     * 3. Comando ssh do sistema com sshpass (último recurso)
     *
     * @param string $command Comando a executar
     * @param int    $timeout Timeout específico para este comando (0 = usar default)
     * @return array ['success' => bool, 'output' => string, 'error' => string, 'exit_code' => int]
     */
    public function executeCommand($command, $timeout = 0)
    {
        $effectiveTimeout = $timeout > 0 ? $timeout : $this->timeout;

        // Método 1: phpseclib3 (preferido - PHP puro, sem dependências)
        if (class_exists('\\phpseclib3\\Net\\SSH2')) {
            return $this->executeViaPhpseclib($command, $effectiveTimeout);
        }

        // Método 2: Extensão ssh2 nativa do PHP
        if (function_exists('ssh2_connect')) {
            return $this->executeViaSsh2($command, $effectiveTimeout);
        }

        // Método 3: Comando ssh do sistema (último recurso)
        return $this->executeViaSystemSsh($command, $effectiveTimeout);
    }

    /**
     * Executa comando via phpseclib3 (PHP puro)
     *
     * @param string $command
     * @param int    $timeout
     * @return array
     */
    private function executeViaPhpseclib($command, $timeout)
    {
        try {
            $ssh = new SSH2($this->host, $this->port);
            $ssh->setTimeout($timeout);

            // Autenticar
            $authenticated = false;

            if (!empty($this->privateKeyPath) && file_exists($this->privateKeyPath)) {
                // Autenticação por chave
                try {
                    $keyContent = file_get_contents($this->privateKeyPath);
                    if (!empty($this->keyPassphrase)) {
                        $key = PublicKeyLoader::load($keyContent, $this->keyPassphrase);
                    } else {
                        $key = PublicKeyLoader::load($keyContent);
                    }
                    $authenticated = $ssh->login($this->username, $key);
                } catch (\Exception $e) {
                    // Se falhar com chave, tentar com password
                    if (!empty($this->password)) {
                        $authenticated = $ssh->login($this->username, $this->password);
                    }
                }
            } elseif (!empty($this->password)) {
                // Autenticação por password
                $authenticated = $ssh->login($this->username, $this->password);
            }

            if (!$authenticated) {
                return [
                    'success'   => false,
                    'output'    => '',
                    'error'     => "Falha na autenticação SSH com {$this->host}:{$this->port} (utilizador: {$this->username}). "
                                 . "Verifique as credenciais no WHMCS.",
                    'exit_code' => -1,
                ];
            }

            // Executar o comando e capturar o exit code
            $wrappedCommand = $command . '; echo "___EXITCODE___$?"';
            $output = $ssh->exec($wrappedCommand);
            $stderrOutput = $ssh->getStdError();

            // Extrair exit code
            $exitCode = -1;
            if (preg_match('/___EXITCODE___(\d+)/', $output, $matches)) {
                $exitCode = (int) $matches[1];
                $output = preg_replace('/___EXITCODE___\d+\s*$/', '', $output);
            }

            // Limpar a password do output (pode aparecer no stderr do sudo -S)
            $output = $this->cleanSudoOutput($output);
            $stderrOutput = $this->cleanSudoOutput($stderrOutput);

            $ssh->disconnect();

            return [
                'success'   => ($exitCode === 0),
                'output'    => trim($output),
                'error'     => trim($stderrOutput),
                'exit_code' => $exitCode,
            ];

        } catch (\Exception $e) {
            return [
                'success'   => false,
                'output'    => '',
                'error'     => "Erro phpseclib SSH ({$this->host}): " . $e->getMessage(),
                'exit_code' => -1,
            ];
        }
    }

    /**
     * Limpar o output de mensagens do sudo -S (como "[sudo] password for user:")
     *
     * @param string $output
     * @return string
     */
    private function cleanSudoOutput($output)
    {
        // Remover linhas "[sudo] password for ..."
        $output = preg_replace('/^\[sudo\] password for \S+:\s*$/m', '', $output);
        // Remover linhas vazias consecutivas resultantes
        $output = preg_replace('/\n{3,}/', "\n\n", $output);
        return $output;
    }

    /**
     * Executa comando via extensão PHP ssh2
     *
     * @param string $command
     * @param int    $timeout
     * @return array
     */
    private function executeViaSsh2($command, $timeout)
    {
        $connection = @ssh2_connect($this->host, $this->port);
        if (!$connection) {
            return [
                'success'   => false,
                'output'    => '',
                'error'     => "Não foi possível conectar ao servidor {$this->host}:{$this->port}",
                'exit_code' => -1,
            ];
        }

        // Autenticar por password ou chave
        $authResult = false;
        if (!empty($this->password)) {
            $authResult = @ssh2_auth_password($connection, $this->username, $this->password);
        } elseif (!empty($this->privateKeyPath) && file_exists($this->privateKeyPath)) {
            $pubKeyPath = $this->privateKeyPath . '.pub';
            if (!file_exists($pubKeyPath)) {
                $pubKeyPath = null;
            }
            $authResult = @ssh2_auth_pubkey_file(
                $connection,
                $this->username,
                $pubKeyPath,
                $this->privateKeyPath,
                $this->keyPassphrase
            );
        }

        if (!$authResult) {
            return [
                'success'   => false,
                'output'    => '',
                'error'     => "Falha na autenticação SSH com o servidor {$this->host}",
                'exit_code' => -1,
            ];
        }

        // Encapsular o comando para capturar exit code
        $wrappedCommand = $command . '; echo "___EXITCODE___$?"';
        $stream = ssh2_exec($connection, $wrappedCommand);
        if (!$stream) {
            return [
                'success'   => false,
                'output'    => '',
                'error'     => "Falha ao executar o comando no servidor {$this->host}",
                'exit_code' => -1,
            ];
        }

        $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);

        stream_set_blocking($stream, true);
        stream_set_blocking($errorStream, true);
        stream_set_timeout($stream, $timeout);

        $output = stream_get_contents($stream);
        $error = stream_get_contents($errorStream);

        fclose($stream);
        fclose($errorStream);

        // Extrair exit code do output
        $exitCode = -1;
        if (preg_match('/___EXITCODE___(\d+)/', $output, $matches)) {
            $exitCode = (int) $matches[1];
            $output = preg_replace('/___EXITCODE___\d+\s*$/', '', $output);
        }

        // Limpar output do sudo
        $output = $this->cleanSudoOutput($output);
        $error = $this->cleanSudoOutput($error);

        return [
            'success'   => ($exitCode === 0),
            'output'    => trim($output),
            'error'     => trim($error),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Executa comando via ssh do sistema (fallback)
     * Suporta autenticação por password (via sshpass) ou por chave
     *
     * @param string $command
     * @param int    $timeout
     * @return array
     */
    private function executeViaSystemSsh($command, $timeout)
    {
        if (!empty($this->password)) {
            // Verificar se sshpass está disponível
            $sshpassCheck = '';
            exec('which sshpass 2>/dev/null', $sshpassOutput);
            if (empty($sshpassOutput)) {
                return [
                    'success'   => false,
                    'output'    => '',
                    'error'     => 'Nenhum método SSH disponível no servidor WHMCS. '
                                 . 'Instale phpseclib (incluído no módulo), a extensão php-ssh2, '
                                 . 'ou o pacote sshpass.',
                    'exit_code' => -1,
                ];
            }

            // Usar sshpass para autenticação por password
            $sshCommand = sprintf(
                'sshpass -p %s ssh -o StrictHostKeyChecking=no -o ConnectTimeout=%d -p %d %s@%s %s 2>&1',
                escapeshellarg($this->password),
                min($timeout, 30),
                $this->port,
                escapeshellarg($this->username),
                escapeshellarg($this->host),
                escapeshellarg($command)
            );
        } elseif (!empty($this->privateKeyPath)) {
            $sshCommand = sprintf(
                'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=%d -o BatchMode=yes -i %s -p %d %s@%s %s 2>&1',
                min($timeout, 30),
                escapeshellarg($this->privateKeyPath),
                $this->port,
                escapeshellarg($this->username),
                escapeshellarg($this->host),
                escapeshellarg($command)
            );
        } else {
            return [
                'success'   => false,
                'output'    => '',
                'error'     => 'Nenhum método de autenticação SSH configurado (password ou chave)',
                'exit_code' => -1,
            ];
        }

        $output = '';
        $exitCode = -1;
        exec($sshCommand, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        return [
            'success'   => ($exitCode === 0),
            'output'    => trim($output),
            'error'     => ($exitCode !== 0) ? $output : '',
            'exit_code' => $exitCode,
        ];
    }

    // ================================================================
    // MÉTODOS DE INTEGRAÇÃO COM O MANAGE.SH
    // ================================================================

    /**
     * Executar o manage.sh com os argumentos fornecidos.
     *
     * Usa sudo -S para permitir execução com privilégios elevados
     * em sessões SSH não-interativas.
     *
     * @param string $clientName  Nome do cliente
     * @param string $domain      Domínio (ou '_' para comandos que não precisam)
     * @param string $command     Comando do manage.sh (create, stop, start, etc.)
     * @return array Resultado da execução
     */
    public function runManage($clientName, $domain, $command)
    {
        $innerCmd = sprintf(
            'bash %s %s %s %s',
            escapeshellarg($this->manageScript),
            escapeshellarg($clientName),
            escapeshellarg($domain),
            escapeshellarg($command)
        );

        $cmd = $this->wrapWithSudo($innerCmd);

        Helper::log('SSHManager::runManage', [
            'client' => $clientName,
            'domain' => $domain,
            'command' => $command,
        ]);

        $result = $this->executeCommand($cmd);

        Helper::log('SSHManager::runManage result', $result);

        return $result;
    }

    /**
     * Criar uma nova instância Nextcloud via manage.sh
     *
     * @param string $clientName Nome do cliente (identificador único)
     * @param string $domain     Domínio principal do Nextcloud
     * @return array
     */
    public function createInstance($clientName, $domain)
    {
        return $this->runManage($clientName, $domain, 'create');
    }

    /**
     * Parar (suspender) uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function stopInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'stop');
    }

    /**
     * Iniciar (reativar) uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function startInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'start');
    }

    /**
     * Reiniciar uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function restartInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'restart');
    }

    /**
     * Obter o estado de uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function statusInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'status');
    }

    /**
     * Fazer backup de uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function backupInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'backup');
    }

    /**
     * Remover uma instância (com backup automático)
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function removeInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'remove');
    }

    /**
     * Atualizar uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function updateInstance($clientName)
    {
        return $this->runManage($clientName, '_', 'update');
    }

    /**
     * Obter os logs de uma instância
     *
     * @param string $clientName Nome do cliente
     * @param int    $lines      Número de linhas (default: 100)
     * @return array
     */
    public function getInstanceLogs($clientName, $lines = 100)
    {
        $innerCmd = sprintf(
            'docker logs --tail %d %s 2>&1',
            (int) $lines,
            escapeshellarg($clientName . '-app')
        );
        $cmd = $this->wrapWithSudo($innerCmd);
        return $this->executeCommand($cmd, 30);
    }

    /**
     * Ler o ficheiro .credentials de uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function getCredentials($clientName)
    {
        $credFile = $this->basePath . '/' . $clientName . '/.credentials';
        $cmd = $this->wrapWithSudo('cat ' . escapeshellarg($credFile));
        $result = $this->executeCommand($cmd, 10);

        if ($result['success']) {
            return [
                'success'     => true,
                'credentials' => Helper::parseCredentials($result['output']),
                'raw'         => $result['output'],
            ];
        }

        return [
            'success'     => false,
            'credentials' => [],
            'raw'         => $result['error'],
        ];
    }

    /**
     * Ler o ficheiro .env de uma instância
     *
     * @param string $clientName Nome do cliente
     * @return array
     */
    public function getEnv($clientName)
    {
        $envFile = $this->basePath . '/' . $clientName . '/.env';
        $cmd = $this->wrapWithSudo('cat ' . escapeshellarg($envFile));
        $result = $this->executeCommand($cmd, 10);

        if ($result['success']) {
            $env = [];
            foreach (explode("\n", $result['output']) as $line) {
                $line = trim($line);
                if (!empty($line) && strpos($line, '#') !== 0 && strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $env[trim($key)] = trim($value);
                }
            }
            return ['success' => true, 'env' => $env];
        }

        return ['success' => false, 'env' => []];
    }

    // ================================================================
    // MÉTODOS DE GESTÃO DE UTILIZADORES (via occ)
    // ================================================================

    /**
     * Executar um comando occ no container Nextcloud.
     *
     * Usa sudo -S para aceder ao Docker em sessões não-interativas.
     *
     * @param string $clientName Nome da instância
     * @param string $occCommand Comando occ (sem o prefixo 'php occ')
     * @return array
     */
    public function runOcc($clientName, $occCommand)
    {
        $innerCmd = sprintf(
            'docker exec -u www-data %s php occ %s',
            escapeshellarg($clientName . '-app'),
            $occCommand
        );
        $cmd = $this->wrapWithSudo($innerCmd);
        return $this->executeCommand($cmd, 60);
    }

    /**
     * Criar um novo utilizador no Nextcloud
     *
     * @param string $clientName  Nome da instância
     * @param string $ncUsername  Username
     * @param string $ncPassword  Password
     * @param string $email       Email (opcional)
     * @param string $displayName Nome de exibição (opcional)
     * @param string $quota       Quota (opcional, ex: "5 GB")
     * @return array
     */
    public function createUser($clientName, $ncUsername, $ncPassword, $email = '', $displayName = '', $quota = '')
    {
        $innerCmd = sprintf(
            'export OC_PASS=%s && docker exec -u www-data -e OC_PASS %s php occ user:add --password-from-env %s',
            escapeshellarg($ncPassword),
            escapeshellarg($clientName . '-app'),
            escapeshellarg($ncUsername)
        );

        if (!empty($displayName)) {
            $innerCmd .= ' --display-name=' . escapeshellarg($displayName);
        }

        $cmd = $this->wrapWithSudo($innerCmd);
        $result = $this->executeCommand($cmd);

        if (!$result['success']) {
            return $result;
        }

        // Definir email
        if (!empty($email)) {
            $this->runOcc($clientName, sprintf(
                'user:setting %s settings email %s',
                escapeshellarg($ncUsername),
                escapeshellarg($email)
            ));
        }

        // Definir quota
        if (!empty($quota)) {
            $this->runOcc($clientName, sprintf(
                'user:setting %s files quota %s',
                escapeshellarg($ncUsername),
                escapeshellarg(Helper::formatQuotaForNextcloud($quota))
            ));
        }

        return $result;
    }

    /**
     * Alterar a password de um utilizador no Nextcloud
     *
     * @param string $clientName  Nome da instância
     * @param string $ncUsername  Username
     * @param string $newPassword Nova password
     * @return array
     */
    public function changeUserPassword($clientName, $ncUsername, $newPassword)
    {
        $innerCmd = sprintf(
            'export OC_PASS=%s && docker exec -u www-data -e OC_PASS %s php occ user:resetpassword --password-from-env %s',
            escapeshellarg($newPassword),
            escapeshellarg($clientName . '-app'),
            escapeshellarg($ncUsername)
        );

        $cmd = $this->wrapWithSudo($innerCmd);
        return $this->executeCommand($cmd);
    }

    /**
     * Desativar um utilizador no Nextcloud
     *
     * @param string $clientName Nome da instância
     * @param string $ncUsername Username
     * @return array
     */
    public function disableUser($clientName, $ncUsername)
    {
        return $this->runOcc($clientName, 'user:disable ' . escapeshellarg($ncUsername));
    }

    /**
     * Ativar um utilizador no Nextcloud
     *
     * @param string $clientName Nome da instância
     * @param string $ncUsername Username
     * @return array
     */
    public function enableUser($clientName, $ncUsername)
    {
        return $this->runOcc($clientName, 'user:enable ' . escapeshellarg($ncUsername));
    }

    /**
     * Alterar a quota de um utilizador
     *
     * @param string $clientName Nome da instância
     * @param string $ncUsername Username
     * @param string $quota      Nova quota (ex: "10 GB", "none")
     * @return array
     */
    public function setUserQuota($clientName, $ncUsername, $quota)
    {
        return $this->runOcc($clientName, sprintf(
            'user:setting %s files quota %s',
            escapeshellarg($ncUsername),
            escapeshellarg(Helper::formatQuotaForNextcloud($quota))
        ));
    }

    /**
     * Listar utilizadores de uma instância
     *
     * @param string $clientName Nome da instância
     * @return array
     */
    public function listUsers($clientName)
    {
        return $this->runOcc($clientName, 'user:list --output=json');
    }

    /**
     * Obter informações de um utilizador
     *
     * @param string $clientName Nome da instância
     * @param string $ncUsername Username
     * @return array
     */
    public function getUserInfo($clientName, $ncUsername)
    {
        return $this->runOcc($clientName, 'user:info ' . escapeshellarg($ncUsername) . ' --output=json');
    }

    // ================================================================
    // MÉTODOS DE DIAGNÓSTICO
    // ================================================================

    /**
     * Testar a conexão SSH com o servidor
     *
     * @return array Resultado do teste
     */
    public function testConnection()
    {
        $result = $this->executeCommand('echo "SSH_CONNECTION_OK" && hostname && uptime', 15);

        if ($result['success'] && strpos($result['output'], 'SSH_CONNECTION_OK') !== false) {
            return [
                'success' => true,
                'message' => "Conexão SSH bem-sucedida com {$this->host}",
                'output'  => $result['output'],
            ];
        }

        return [
            'success' => false,
            'message' => "Falha na conexão SSH com {$this->host}: " . $result['error'],
        ];
    }

    /**
     * Verificar se o manage.sh existe no servidor
     *
     * @return array
     */
    public function verifyManageScript()
    {
        $result = $this->executeCommand(
            'test -f ' . escapeshellarg($this->manageScript) . ' && echo "EXISTS" || echo "MISSING"',
            10
        );

        return [
            'exists'  => (strpos($result['output'], 'EXISTS') !== false),
            'path'    => $this->manageScript,
            'output'  => $result['output'],
        ];
    }

    /**
     * Verificar se o Docker está a correr no servidor.
     * Usa sudo -S para aceder ao Docker.
     *
     * @return array
     */
    public function verifyDocker()
    {
        $innerCmd = 'docker info --format "{{.ServerVersion}}" 2>/dev/null';
        $cmd = $this->wrapWithSudo($innerCmd);
        $result = $this->executeCommand($cmd, 10);

        return [
            'running' => $result['success'],
            'version' => $result['success'] ? trim($result['output']) : 'N/A',
        ];
    }

    /**
     * Verificar se o Traefik está a correr.
     * Usa sudo -S para aceder ao Docker.
     *
     * @return array
     */
    public function verifyTraefik()
    {
        $innerCmd = 'docker ps --filter "name=traefik" --format "{{.Status}}" 2>/dev/null';
        $cmd = $this->wrapWithSudo($innerCmd);
        $result = $this->executeCommand($cmd, 10);

        return [
            'running' => !empty($result['output']) && strpos($result['output'], 'Up') !== false,
            'status'  => trim($result['output']),
        ];
    }

    /**
     * Obter uso de disco de uma instância
     *
     * @param string $clientName Nome da instância
     * @return array
     */
    public function getDiskUsage($clientName)
    {
        $instancePath = $this->basePath . '/' . $clientName;
        $innerCmd = 'du -sh ' . escapeshellarg($instancePath) . ' 2>/dev/null';
        $cmd = $this->wrapWithSudo($innerCmd);
        $result = $this->executeCommand($cmd, 30);

        $usage = 'N/A';
        if ($result['success'] && preg_match('/^([\d.]+[KMGT]?)/', $result['output'], $m)) {
            $usage = $m[1];
        }

        return [
            'success' => $result['success'],
            'usage'   => $usage,
            'raw'     => $result['output'],
        ];
    }
}
