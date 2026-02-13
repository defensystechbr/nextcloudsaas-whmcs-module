# Módulo Nextcloud-SaaS para WHMCS v2.3.2

**Autor:** Defensys / Manus AI  
**Versão:** 2.3.2  
**Licença:** Proprietária

---

## 1. Visão Geral

O Módulo Nextcloud-SaaS para WHMCS é uma solução completa para provisionar e gerir instâncias Nextcloud como um produto de Software as a Service (SaaS). Esta versão foi completamente reescrita para se integrar diretamente com o script `manage.sh` v10.0, que gere uma arquitetura robusta de 10 containers por instância, orquestrada por um proxy reverso Traefik com provisionamento automático de SSL via Let's Encrypt.

Esta integração elimina a necessidade de scripts auxiliares no módulo, centralizando toda a lógica de gestão de instâncias no `manage.sh` do servidor, resultando em maior estabilidade, segurança e facilidade de manutenção.

### 1.1. Arquitetura da Instância

Cada instância provisionada pelo módulo consiste em 10 containers Docker interligados, oferecendo uma solução completa e de alto desempenho:

| Container       | Descrição                                               |
|-----------------|---------------------------------------------------------|
| `app`           | O próprio Nextcloud Hub                                 |
| `db`            | Banco de dados MariaDB 10.11                            |
| `redis`         | Cache de memória para transações e bloqueio de ficheiros|
| `collabora`     | Suite de escritório online Collabora Online             |
| `turn`          | Servidor TURN/STUN para o Nextcloud Talk                |
| `cron`          | Execução de tarefas agendadas em background             |
| `harp`          | HaRP (AppAPI) para integração de aplicações             |
| `nats`          | Sistema de mensagens para o High Performance Backend    |
| `janus`         | Gateway WebRTC para o High Performance Backend          |
| `signaling`     | Servidor de sinalização para o High Performance Backend |

### 1.2. Requisitos de DNS

Para que uma instância funcione corretamente, **três (3) registros DNS do tipo A** devem ser criados e apontados para o endereço IP do servidor de hospedagem **antes** da criação do serviço no WHMCS:

1.  `dominio.com.br` (para o Nextcloud)
2.  `collabora-dominio.com.br` (para o Collabora Online)
3.  `signaling-dominio.com.br` (para o Nextcloud Talk HPB)

O Traefik irá detetar automaticamente estes domínios e provisionar os certificados SSL correspondentes.

---

## 2. Instalação e Configuração

### 2.1. Pré-requisitos do Servidor

-   Um servidor Ubuntu Linux com o Nextcloud-SaaS (baseado no `manage.sh` v10.0) já instalado em `/opt/nextcloud-customers`.
-   Docker e Docker Compose instalados e a funcionar.
-   Traefik a correr como proxy reverso.
-   Acesso SSH ao servidor a partir do servidor WHMCS.
-   **Utilizador SSH com sudo NOPASSWD:** O utilizador SSH (ex: `defensys`) precisa de permissões de `sudo` sem password. Para configurar, adicione a seguinte linha ao ficheiro `/etc/sudoers` no servidor de hospedagem:
    ```
    defensys ALL=(ALL) NOPASSWD: ALL
    ```

### 2.2. Instalação do Módulo

1.  **Copiar os ficheiros:** Transfira o diretório `nextcloudsaas` para a pasta de módulos do seu WHMCS:
    ```bash
    /caminho/para/whmcs/modules/servers/
    ```

2.  **Verificar a estrutura:** A estrutura final deve ser:
    ```
    /modules/servers/nextcloudsaas/
    ├── hooks.php
    ├── nextcloudsaas.php
    ├── whmcs.json
    ├── lib/
    │   ├── Helper.php
    │   ├── NextcloudAPI.php
    │   └── SSHManager.php
    ├── templates/
    │   ├── clientarea.tpl
    │   └── error.tpl
    └── vendor/  (contém phpseclib3)
    ```

### 2.3. Configuração do Servidor no WHMCS

1.  Vá para **Setup > Products/Services > Servers**.
2.  Crie um novo servidor ou edite um existente.
3.  No campo **Hostname or IP Address**, insira o IP do seu servidor Nextcloud SaaS (ex: `200.50.151.21`).
4.  Em **Server Details**, selecione `Nextcloud SaaS` como o **Module**.
5.  Insira o **Username** (`defensys` ou outro utilizador com acesso sudo) e a **Password** para o acesso SSH.
6.  **Importante:** Desmarque a opção **Secure** (`Check to use SSL Mode for Connections`), pois a comunicação é via SSH, não HTTPS.
7.  **Importante:** Marque a opção **Override with Custom Port** e defina a porta como **22**.
8.  Clique em **Test Connection**. Deverá ver uma mensagem de sucesso.

### 2.4. Configuração do Produto no WHMCS

1.  Vá para **Setup > Products/Services > Products/Services**.
2.  Crie um novo produto.
3.  Na aba **Module Settings**, selecione `Nextcloud SaaS` para o **Module Name** e o servidor que acabou de configurar.
4.  Configure as **Opções do Módulo**:

    | Opção                         | Descrição                                                                                             |
    |-------------------------------|-------------------------------------------------------------------------------------------------------|
    | **Quota de Armazenamento (GB)** | Define a quota de disco para o utilizador `admin` da instância. Ex: `50`.                             |
    | **Máximo de Utilizadores**      | Define o número máximo de utilizadores que podem ser criados na instância.                            |
    | **Collabora Online**            | Sempre ativo na arquitetura atual. Pode deixar em `on`.                                               |
    | **Nextcloud Talk (HPB)**        | Ativa o High Performance Backend. Deixe em `on`.                                                      |
    | **Caminho da Chave SSH**        | **Opcional.** Se preferir usar autenticação por chave, insira o caminho absoluto para a chave privada no servidor WHMCS. Deixe em branco para usar a password do servidor. |
    | **Prefixo do Nome do Cliente**  | **Opcional.** Adiciona um prefixo ao nome do cliente usado pelo `manage.sh` (ex: `nc-`). Útil para organização. |

5.  Na aba **Custom Fields**, crie os 4 campos abaixo. Os nomes devem ser **exatos**.

**Campo 1: Client Name**
- **Field Name:** `Client Name`
- **Field Type:** Text Box
- **Description:** `Identificador da instância no servidor (preenchido automaticamente)`
- **Validation:** (deixar vazio)
- **Select Options:** (deixar vazio)
- **Display Order:** `1`
- **Admin Only:** ✅ Marcado
- **Required Field:** ❌ Desmarcado
- **Show on Order Form:** ❌ Desmarcado
- **Show on Invoice:** ❌ Desmarcado

**Campo 2: Nextcloud URL**
- **Field Name:** `Nextcloud URL`
- **Field Type:** Text Box
- **Description:** `URL de acesso ao Nextcloud`
- **Validation:** (deixar vazio)
- **Select Options:** (deixar vazio)
- **Display Order:** `2`
- **Admin Only:** ❌ Desmarcado
- **Required Field:** ❌ Desmarcado
- **Show on Order Form:** ❌ Desmarcado
- **Show on Invoice:** ❌ Desmarcado

**Campo 3: Collabora URL**
- **Field Name:** `Collabora URL`
- **Field Type:** Text Box
- **Description:** `URL do Collabora Online (editor de documentos)`
- **Validation:** (deixar vazio)
- **Select Options:** (deixar vazio)
- **Display Order:** `3`
- **Admin Only:** ❌ Desmarcado
- **Required Field:** ❌ Desmarcado
- **Show on Order Form:** ❌ Desmarcado
- **Show on Invoice:** ❌ Desmarcado

**Campo 4: Signaling URL**
- **Field Name:** `Signaling URL`
- **Field Type:** Text Box
- **Description:** `URL do servidor HPB para Nextcloud Talk`
- **Validation:** (deixar vazio)
- **Select Options:** (deixar vazio)
- **Display Order:** `4`
- **Admin Only:** ❌ Desmarcado
- **Required Field:** ❌ Desmarcado
- **Show on Order Form:** ❌ Desmarcado
- **Show on Invoice:** ❌ Desmarcado

---

## 3. Funcionalidades do Módulo

### 3.1. Ciclo de Vida do Serviço

-   **CreateAccount:** Executa `manage.sh <cliente> <dominio> create`. Cria a instância completa, lê o ficheiro `.credentials` gerado, e guarda a password do admin no WHMCS.
-   **SuspendAccount:** Executa `manage.sh <cliente> _ stop`. Para todos os 10 containers da instância.
-   **UnsuspendAccount:** Executa `manage.sh <cliente> _ start`. Inicia todos os 10 containers da instância.
-   **TerminateAccount:** Executa `manage.sh <cliente> _ backup` e depois `manage.sh <cliente> _ remove`. Faz um backup completo antes de apagar permanentemente a instância (containers, volumes e dados).
-   **ChangePackage:** Altera a quota do utilizador `admin` da instância via `occ`.
-   **ChangePassword:** Altera a password do utilizador `admin` da instância via `occ`.

### 3.2. Botões Personalizados (Admin)

Na área de administração do WHMCS, na página do serviço do cliente, estão disponíveis os seguintes botões:

-   **Verificar Estado:** Mostra o estado de cada um dos 10 containers.
-   **Reiniciar Instância:** Executa `stop` e `start`.
-   **Fazer Backup:** Executa o comando de backup do `manage.sh`.
-   **Atualizar Instância:** Executa o comando de atualização do `manage.sh`.
-   **Testar Conexão SSH:** Valida a comunicação com o servidor.
-   **Testar API Nextcloud:** Valida a comunicação com a API OCS da instância.
-   **Ver Credenciais:** Mostra o conteúdo do ficheiro `.credentials`.
-   **Ver Logs:** Mostra os últimos 100 logs do container `app`.

### 3.3. Área de Cliente

O cliente tem acesso a um painel de controlo completo e moderno, que inclui:

-   **Estado da Instância:** Mostra se a instância está ativa, parada ou parcial, e o número de containers a correr.
-   **Links de Acesso Rápido:** Botões para aceder diretamente ao Nextcloud, Collabora e Talk.
-   **Informações de Armazenamento:** Barra de progresso e detalhes sobre o uso de disco (obtido via `du -sh` no servidor).
-   **Credenciais:** Informações de acesso completas, lidas diretamente do ficheiro `.credentials` da instância.
-   **Componentes da Instância:** Lista dos 10 containers que compõem a sua instância.
-   **Aviso de DNS:** Lembrete constante dos 3 domínios DNS necessários.

---

## 4. Changelog

-   **v2.3.2 (2026-02-13):**
    -   **Correção:** Botão "Testar API Nextcloud" agora obtém a password real do admin a partir do ficheiro `.credentials` via SSH, corrigindo o erro "Unauthorised".
    -   **Melhoria:** Mensagens de erro mais descritivas no teste de API.
-   **v2.3.1 (2026-02-12):**
    -   **Correção:** Armazenamento na área de cliente agora usa SSH (`du -sh`) em vez da API OCS, alinhando com o painel admin.
-   **v2.3.0 (2026-02-12):**
    -   **Correção:** Utilizador padrão na área de cliente agora é "admin".
    -   **Melhoria:** `parseCredentials()` reescrito para usar `strpos` em vez de regex, corrigindo problemas com UTF-8.
-   **v2.2.0 (2026-02-12):**
    -   **Correção:** Comandos SSH agora usam `sudo -n` (NOPASSWD) em vez de `echo password | sudo -S`.
-   **v2.1.0 (2026-02-12):**
    -   **Melhoria:** Adicionado `phpseclib3` para comunicação SSH em PHP puro, eliminando dependências externas.
-   **v2.0.0 (2026-02-11):**
    -   Versão inicial reescrita para integrar com `manage.sh` v10.0.

---

## 5. Ficheiros do Módulo

-   `nextcloudsaas.php`: Ficheiro principal com toda a lógica do ciclo de vida, botões e integração com o WHMCS.
-   `lib/SSHManager.php`: Classe robusta para gerir a comunicação SSH, com métodos que são wrappers diretos dos comandos do `manage.sh`.
-   `lib/Helper.php`: Funções utilitárias para formatação, validação e extração de configurações.
-   `lib/NextcloudAPI.php`: Cliente para a API OCS do Nextcloud, usado para obter estatísticas de uso.
-   `templates/clientarea.tpl`: Template Smarty para a área de cliente, com um design moderno e informativo.
-   `hooks.php`: Adiciona logs de atividade detalhados para cada ação do ciclo de vida.
-   `whmcs.json`: Metadados do módulo.
-   `vendor/`: Contém a biblioteca `phpseclib3`.
