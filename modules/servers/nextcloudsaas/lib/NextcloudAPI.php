<?php
/**
 * Nextcloud OCS Provisioning API Client
 *
 * Esta classe encapsula todas as chamadas à API OCS do Nextcloud,
 * permitindo a gestão de utilizadores, grupos e quotas de forma
 * programática a partir do módulo WHMCS.
 *
 * @package    NextcloudSaaS
 * @author     Manus AI
 * @copyright  2026
 * @version    1.0.0
 */

namespace NextcloudSaaS;

class NextcloudAPI
{
    /**
     * @var string URL base do servidor Nextcloud (ex: https://cloud.example.com)
     */
    private $baseUrl;

    /**
     * @var string Nome de utilizador admin do Nextcloud
     */
    private $adminUser;

    /**
     * @var string Password do admin do Nextcloud
     */
    private $adminPassword;

    /**
     * @var bool Verificar certificado SSL
     */
    private $verifySSL;

    /**
     * @var int Timeout para requisições HTTP em segundos
     */
    private $timeout;

    /**
     * Construtor da classe NextcloudAPI
     *
     * @param string $baseUrl       URL base do Nextcloud (sem barra final)
     * @param string $adminUser     Utilizador admin
     * @param string $adminPassword Password do admin
     * @param bool   $verifySSL     Verificar SSL (default: true)
     * @param int    $timeout       Timeout em segundos (default: 30)
     */
    public function __construct($baseUrl, $adminUser, $adminPassword, $verifySSL = true, $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->adminUser = $adminUser;
        $this->adminPassword = $adminPassword;
        $this->verifySSL = $verifySSL;
        $this->timeout = $timeout;
    }

    /**
     * Executa uma requisição HTTP à API OCS do Nextcloud
     *
     * @param string $method  Método HTTP (GET, POST, PUT, DELETE)
     * @param string $endpoint Endpoint da API (ex: /cloud/users)
     * @param array  $data    Dados a enviar (para POST/PUT)
     *
     * @return array Resposta parseada da API
     * @throws \Exception Em caso de erro de comunicação
     */
    private function request($method, $endpoint, $data = [])
    {
        $url = $this->baseUrl . '/ocs/v1.php' . $endpoint;

        // Adicionar formato JSON à URL
        $separator = (strpos($url, '?') !== false) ? '&' : '?';
        $url .= $separator . 'format=json';

        $ch = curl_init();

        $headers = [
            'OCS-APIRequest: true',
            'Content-Type: application/x-www-form-urlencoded',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERPWD        => $this->adminUser . ':' . $this->adminPassword,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
        ]);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                }
                break;
            case 'GET':
            default:
                if (!empty($data)) {
                    $url .= '&' . http_build_query($data);
                    curl_setopt($ch, CURLOPT_URL, $url);
                }
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Erro cURL ao comunicar com Nextcloud: {$error}");
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Resposta inválida do Nextcloud (HTTP {$httpCode}): " . substr($response, 0, 500));
        }

        return $decoded;
    }

    /**
     * Verifica se uma resposta OCS indica sucesso
     *
     * @param array $response Resposta da API
     * @return bool
     */
    private function isSuccess($response)
    {
        return isset($response['ocs']['meta']['statuscode'])
            && $response['ocs']['meta']['statuscode'] == 100;
    }

    /**
     * Extrai a mensagem de erro de uma resposta OCS
     *
     * @param array $response Resposta da API
     * @return string
     */
    private function getErrorMessage($response)
    {
        if (isset($response['ocs']['meta']['message']) && !empty($response['ocs']['meta']['message'])) {
            return $response['ocs']['meta']['message'];
        }
        $code = isset($response['ocs']['meta']['statuscode']) ? $response['ocs']['meta']['statuscode'] : 'desconhecido';
        return "Erro OCS com código: {$code}";
    }

    // =========================================================================
    // GESTÃO DE UTILIZADORES
    // =========================================================================

    /**
     * Criar um novo utilizador no Nextcloud
     *
     * @param string $userId      ID do utilizador (login)
     * @param string $password    Password inicial
     * @param string $displayName Nome de exibição
     * @param string $email       E-mail do utilizador
     * @param string $quota       Quota de armazenamento (ex: "5 GB", "10 GB")
     * @param array  $groups      Grupos a que o utilizador pertencerá
     *
     * @return array Resultado da operação ['success' => bool, 'message' => string]
     */
    public function createUser($userId, $password, $displayName = '', $email = '', $quota = '', $groups = [])
    {
        $data = [
            'userid'   => $userId,
            'password' => $password,
        ];

        if (!empty($displayName)) {
            $data['displayName'] = $displayName;
        }
        if (!empty($email)) {
            $data['email'] = $email;
        }
        if (!empty($quota)) {
            $data['quota'] = $quota;
        }
        if (!empty($groups)) {
            $data['groups'] = $groups;
        }

        try {
            $response = $this->request('POST', '/cloud/users', $data);

            if ($this->isSuccess($response)) {
                return ['success' => true, 'message' => 'Utilizador criado com sucesso'];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obter dados de um utilizador
     *
     * @param string $userId ID do utilizador
     * @return array Dados do utilizador ou erro
     */
    public function getUser($userId)
    {
        try {
            $response = $this->request('GET', '/cloud/users/' . urlencode($userId));

            if ($this->isSuccess($response)) {
                return ['success' => true, 'data' => $response['ocs']['data']];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Listar utilizadores
     *
     * @param string $search Termo de pesquisa (opcional)
     * @param int    $limit  Limite de resultados (opcional)
     * @param int    $offset Offset para paginação (opcional)
     * @return array Lista de utilizadores ou erro
     */
    public function listUsers($search = '', $limit = 0, $offset = 0)
    {
        $data = [];
        if (!empty($search)) {
            $data['search'] = $search;
        }
        if ($limit > 0) {
            $data['limit'] = $limit;
        }
        if ($offset > 0) {
            $data['offset'] = $offset;
        }

        try {
            $response = $this->request('GET', '/cloud/users', $data);

            if ($this->isSuccess($response)) {
                return ['success' => true, 'data' => $response['ocs']['data']];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Editar um campo de um utilizador
     *
     * @param string $userId ID do utilizador
     * @param string $key    Campo a editar (email, quota, displayname, password, phone, address, website, twitter)
     * @param string $value  Novo valor
     * @return array Resultado da operação
     */
    public function editUser($userId, $key, $value)
    {
        try {
            $response = $this->request('PUT', '/cloud/users/' . urlencode($userId), [
                'key'   => $key,
                'value' => $value,
            ]);

            if ($this->isSuccess($response)) {
                return ['success' => true, 'message' => 'Utilizador atualizado com sucesso'];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Definir a quota de armazenamento de um utilizador
     *
     * @param string $userId ID do utilizador
     * @param string $quota  Nova quota (ex: "5 GB", "10 GB", "none" para ilimitado)
     * @return array Resultado da operação
     */
    public function setUserQuota($userId, $quota)
    {
        return $this->editUser($userId, 'quota', $quota);
    }

    /**
     * Alterar a password de um utilizador
     *
     * @param string $userId      ID do utilizador
     * @param string $newPassword Nova password
     * @return array Resultado da operação
     */
    public function changeUserPassword($userId, $newPassword)
    {
        return $this->editUser($userId, 'password', $newPassword);
    }

    /**
     * Desabilitar um utilizador
     *
     * @param string $userId ID do utilizador
     * @return array Resultado da operação
     */
    public function disableUser($userId)
    {
        try {
            $response = $this->request('PUT', '/cloud/users/' . urlencode($userId) . '/disable');

            if ($this->isSuccess($response)) {
                return ['success' => true, 'message' => 'Utilizador desabilitado com sucesso'];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Habilitar um utilizador
     *
     * @param string $userId ID do utilizador
     * @return array Resultado da operação
     */
    public function enableUser($userId)
    {
        try {
            $response = $this->request('PUT', '/cloud/users/' . urlencode($userId) . '/enable');

            if ($this->isSuccess($response)) {
                return ['success' => true, 'message' => 'Utilizador habilitado com sucesso'];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Eliminar um utilizador
     *
     * @param string $userId ID do utilizador
     * @return array Resultado da operação
     */
    public function deleteUser($userId)
    {
        try {
            $response = $this->request('DELETE', '/cloud/users/' . urlencode($userId));

            if ($this->isSuccess($response)) {
                return ['success' => true, 'message' => 'Utilizador eliminado com sucesso'];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // =========================================================================
    // GESTÃO DE GRUPOS
    // =========================================================================

    /**
     * Adicionar utilizador a um grupo
     *
     * @param string $userId  ID do utilizador
     * @param string $groupId ID do grupo
     * @return array Resultado da operação
     */
    public function addUserToGroup($userId, $groupId)
    {
        try {
            $response = $this->request('POST', '/cloud/users/' . urlencode($userId) . '/groups', [
                'groupid' => $groupId,
            ]);

            if ($this->isSuccess($response)) {
                return ['success' => true, 'message' => 'Utilizador adicionado ao grupo com sucesso'];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Remover utilizador de um grupo
     *
     * @param string $userId  ID do utilizador
     * @param string $groupId ID do grupo
     * @return array Resultado da operação
     */
    public function removeUserFromGroup($userId, $groupId)
    {
        try {
            $response = $this->request('DELETE', '/cloud/users/' . urlencode($userId) . '/groups', [
                'groupid' => $groupId,
            ]);

            if ($this->isSuccess($response)) {
                return ['success' => true, 'message' => 'Utilizador removido do grupo com sucesso'];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Criar um grupo
     *
     * @param string $groupId ID do grupo
     * @return array Resultado da operação
     */
    public function createGroup($groupId)
    {
        try {
            $response = $this->request('POST', '/cloud/groups', [
                'groupid' => $groupId,
            ]);

            if ($this->isSuccess($response)) {
                return ['success' => true, 'message' => 'Grupo criado com sucesso'];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Listar grupos
     *
     * @param string $search Termo de pesquisa (opcional)
     * @return array Lista de grupos ou erro
     */
    public function listGroups($search = '')
    {
        $data = [];
        if (!empty($search)) {
            $data['search'] = $search;
        }

        try {
            $response = $this->request('GET', '/cloud/groups', $data);

            if ($this->isSuccess($response)) {
                return ['success' => true, 'data' => $response['ocs']['data']];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // =========================================================================
    // UTILITÁRIOS
    // =========================================================================

    /**
     * Testar a conexão com o servidor Nextcloud
     *
     * @return array Resultado do teste
     */
    public function testConnection()
    {
        try {
            $response = $this->request('GET', '/cloud/capabilities');

            if ($this->isSuccess($response)) {
                $version = isset($response['ocs']['data']['version']['string'])
                    ? $response['ocs']['data']['version']['string']
                    : 'desconhecida';
                return [
                    'success' => true,
                    'message' => "Conexão bem-sucedida. Nextcloud versão: {$version}",
                    'version' => $version,
                ];
            }

            return ['success' => false, 'message' => $this->getErrorMessage($response)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Obter informações de uso de armazenamento de um utilizador
     *
     * @param string $userId ID do utilizador
     * @return array Informações de uso ou erro
     */
    public function getUserStorageInfo($userId)
    {
        $result = $this->getUser($userId);

        if (!$result['success']) {
            return $result;
        }

        $data = $result['data'];

        return [
            'success' => true,
            'data'    => [
                'quota'    => isset($data['quota']['quota']) ? $data['quota']['quota'] : 0,
                'used'     => isset($data['quota']['used']) ? $data['quota']['used'] : 0,
                'free'     => isset($data['quota']['free']) ? $data['quota']['free'] : 0,
                'relative' => isset($data['quota']['relative']) ? $data['quota']['relative'] : 0,
            ],
        ];
    }
}
