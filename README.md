# Módulo Nextcloud-SaaS para WHMCS v3.1.2

**Autor:** Defensys / Manus AI  
**Versão:** 3.1.2  
**Licença:** Proprietária  
**Compatível com:** Nextcloud SaaS Manager **v11.x** (`manage.sh` v11.3+)

---

## 1. Visão Geral

O Módulo Nextcloud-SaaS para WHMCS é uma solução completa para provisionar e gerir instâncias Nextcloud como um produto de Software as a Service (SaaS). A partir da **v3.0.0**, o módulo foi alinhado à nova **arquitetura compartilhada** introduzida pelo Nextcloud SaaS Manager v11.x (`manage.sh` v11.3+): em vez dos antigos 10 containers por cliente, cada instância passa a ter apenas **3 containers dedicados** (Nextcloud, Cron e HaRP) que se conectam a **8 serviços globais `shared-*`** rodando uma única vez no host. Tudo é publicado atrás do proxy reverso Traefik com provisionamento automático de SSL via Let's Encrypt.

Esta integração continua centralizando toda a lógica de gestão de instâncias no `manage.sh`, resultando em maior estabilidade, segurança, densidade e facilidade de manutenção.

### 1.1. Arquitetura da Instância (v3.0.0)

**Containers dedicados por cliente (3):**

| Container             | Descrição                                               |
|-----------------------|---------------------------------------------------------|
| `<cliente>-app`       | O próprio Nextcloud Hub                                 |
| `<cliente>-cron`      | Execução de tarefas agendadas em background             |
| `<cliente>-harp`      | HaRP (AppAPI) para integração de aplicações             |

**Serviços globais compartilhados (8):**

| Container             | Descrição                                                       |
|-----------------------|------------------------------------------------------------------|
| `shared-db`           | MariaDB compartilhado por todas as instâncias                    |
| `shared-redis`        | Cache Redis compartilhado                                        |
| `shared-collabora`    | Suite de escritório Collabora Online                             |
| `shared-turn`         | TURN/STUN global para o Nextcloud Talk                           |
| `shared-nats`         | Sistema de mensagens NATS para o HPB                             |
| `shared-janus`        | Gateway WebRTC Janus para o HPB                                  |
| `shared-signaling`    | Servidor de sinalização Spreed (HPB)                             |
| `shared-recording`    | Servidor de gravação de chamadas Talk (novidade v11.x)           |

### 1.2. Requisitos de DNS (v3.0.0)

Para que uma instância funcione corretamente é necessário apenas **um (1) registro DNS do tipo A** apontando para o endereço IP do servidor de hospedagem:

1.  `dominio.com.br` (para o Nextcloud)

Os serviços auxiliares (Collabora, Talk HPB, TURN) deixaram de exigir DNS por cliente: passam a ser publicados em **hostnames globais geridos pela Defensys** (configurados no servidor via `--collabora-domain`, `--signaling-domain` e `--turn-domain` do `manage.sh`).

O Traefik continua a detetar automaticamente o domínio do cliente e a provisionar o certificado SSL correspondente.

> **Novidade v3.0.0:** Provisionamento automático continua a funcionar: após o checkout, o sistema verifica automaticamente o registro DNS a cada 5 minutos. Quando estiver correto, a instância é criada automaticamente e o cliente recebe um email com as credenciais. Se após 3 dias o DNS não estiver configurado, o admin é notificado.

---

## 2. Instalação e Configuração

### 2.1. Pré-requisitos do Servidor

-   Um servidor Ubuntu Linux com o Nextcloud SaaS Manager **v11.x** (`manage.sh` v11.3+) já instalado em `/opt/nextcloud-customers` e com `setup-shared.sh` já executado (8 serviços `shared-*` UP).
-   Docker e Docker Compose instalados e a funcionar.
-   Traefik a correr como proxy reverso.
-   Acesso SSH ao servidor a partir do servidor WHMCS.
-   **Utilizador SSH com sudo NOPASSWD:** O utilizador SSH (ex: `defensys`) precisa de permissões de `sudo` sem password. Para configurar, adicione a seguinte linha ao ficheiro `/etc/sudoers` no servidor de hospedagem:
    ```
    defensys ALL=(ALL) NOPASSWD: ALL
    ```
-   **Autenticação SSH habilitada para o utilizador.** Imagens cloud do Ubuntu (24.04+) vêm, por padrão, com o ficheiro `/etc/ssh/sshd_config.d/60-cloudimg-settings.conf` definindo `PasswordAuthentication no`. Se o WHMCS for autenticar por senha, edite esse ficheiro e altere para `PasswordAuthentication yes`, ou configure autenticação por chave SSH (vide `configoption5`). Sem isso, o `Test Connection` falhará com **"Connection closed by server"** e o módulo a partir da v3.1.1 mostrará essa dica diretamente. Após alterar, executar:
    ```bash
    sudo systemctl reload ssh
    ```
-   **Porta SSH conhecida pelo WHMCS.** No WHMCS → `Server Details`, marque **Override with Custom Port** e preencha **22** (ou a porta usada pelo seu sshd). A v3.1.1 do módulo já assume `22` como fallback se o WHMCS enviar `0`/vazio.

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
    | **Quota de Armazenamento (GB)** | Define a quota de disco para o utilizador `admin` e a quota padrão para todos os novos utilizadores. Ex: `50`. |
    | **Máximo de Utilizadores**      | Define o número máximo de utilizadores que podem ser criados na instância.                            |
    | **Collabora Online**            | Sempre ativo na arquitetura atual. Pode deixar em `on`.                                               |
    | **Nextcloud Talk (HPB)**        | Ativa o High Performance Backend. Deixe em `on`.                                                      |
    | **Caminho da Chave SSH**        | **Opcional.** Se preferir usar autenticação por chave, insira o caminho absoluto para a chave privada no servidor WHMCS. Deixe em branco para usar a password do servidor. |
    | **Prefixo do Nome do Cliente**  | **Opcional.** Adiciona um prefixo ao nome do cliente usado pelo `manage.sh` (ex: `nc-`). Útil para organização. |

5.  Na aba **Custom Fields**, crie os 5 campos abaixo. Os nomes devem ser **exatos** (com acentos).

#### 2.4.1. Custom Fields obrigatórios

O módulo lê o domínio da instância a partir do Custom Field **"Domínio da Instância"**. Sem ele, `nextcloudsaas_CreateAccount` aborta com a mensagem `"Domínio não fornecido"`. O hook `AfterShoppingCartCheckout` (carrinho do cliente) e o hook `AcceptOrder` (pedido criado pelo admin em **Orders > Add New Order**) copiam automaticamente o valor desse campo para `tblhosting.domain` antes do provisionamento. **É obrigatório criar este Custom Field para que o módulo funcione.**

**Campo 0: Domínio da Instância (obrigatório)**
- **Field Name:** `Domínio da Instância`
- **Field Type:** Text Box
- **Description:** `Domínio onde o Nextcloud será publicado (ex.: nextcloud.suaempresa.com.br). Você precisa criar um registro DNS A apontando este domínio para o IP do nosso servidor.`
- **Validation:** `/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i`
- **Select Options:** (deixar vazio)
- **Display Order:** `0`
- **Admin Only:** Desmarcado
- **Required Field:** **Marcado**
- **Show on Order Form:** **Marcado**
- **Show on Invoice:** Desmarcado

> **Nota para pedidos criados pelo admin:** o WHMCS não exibe o Custom Field na tela `Orders > Add New Order`. Após criar o pedido, **antes** de clicar em **Accept Order**, abra o serviço em **Clients > View/Search Clients > [cliente] > Products/Services > Custom Fields** e preencha o **Domínio da Instância**. O hook `AcceptOrder` (v3.1.2+) copiará o valor para `tblhosting.domain` no momento em que você aceitar o pedido.

**Campo 1: Client Name**
- **Field Name:** `Client Name`
- **Field Type:** Text Box
- **Description:** `Identificador da instância no servidor (preenchido automaticamente)`
- **Validation:** (deixar vazio)
- **Select Options:** (deixar vazio)
- **Display Order:** `1`
- **Admin Only:** Marcado
- **Required Field:** Desmarcado
- **Show on Order Form:** Desmarcado
- **Show on Invoice:** Desmarcado

**Campo 2: Nextcloud URL**
- **Field Name:** `Nextcloud URL`
- **Field Type:** Text Box
- **Description:** `URL de acesso ao Nextcloud`
- **Validation:** (deixar vazio)
- **Select Options:** (deixar vazio)
- **Display Order:** `2`
- **Admin Only:** Desmarcado
- **Required Field:** Desmarcado
- **Show on Order Form:** Desmarcado
- **Show on Invoice:** Desmarcado

**Campo 3: Collabora URL**
- **Field Name:** `Collabora URL`
- **Field Type:** Text Box
- **Description:** `URL do Collabora Online (editor de documentos)`
- **Validation:** (deixar vazio)
- **Select Options:** (deixar vazio)
- **Display Order:** `3`
- **Admin Only:** Desmarcado
- **Required Field:** Desmarcado
- **Show on Order Form:** Desmarcado
- **Show on Invoice:** Desmarcado

**Campo 4: Signaling URL**
- **Field Name:** `Signaling URL`
- **Field Type:** Text Box
- **Description:** `URL do servidor HPB para Nextcloud Talk`
- **Validation:** (deixar vazio)
- **Select Options:** (deixar vazio)
- **Display Order:** `4`
- **Admin Only:** Desmarcado
- **Required Field:** Desmarcado
- **Show on Order Form:** Desmarcado
- **Show on Invoice:** Desmarcado

---

## 3. Funcionalidades do Módulo

### 3.1. Ciclo de Vida do Serviço

-   **CreateAccount:** Verifica primeiro se os 3 registros DNS estão corretos (apontando para o IP do servidor configurado no WHMCS). Se OK, executa `manage.sh <cliente> <dominio> create`. Cria a instância completa, lê o ficheiro `.credentials` gerado, guarda a password do admin no WHMCS, e define a quota padrão para todos os utilizadores. Se DNS não está OK, retorna mensagem informativa e o cron automático continuará verificando.
-   **SuspendAccount:** Executa `manage.sh <cliente> _ stop`. Para todos os 10 containers da instância.
-   **UnsuspendAccount:** Executa `manage.sh <cliente> _ start`. Inicia todos os 10 containers da instância.
-   **TerminateAccount:** Executa `manage.sh <cliente> _ backup` e depois `manage.sh <cliente> _ remove`. Faz um backup completo antes de apagar permanentemente a instância (containers, volumes e dados).
-   **Renew:** Verifica se a instância está ativa e reinicia se necessário.
-   **ChangePackage:** Altera a quota do utilizador `admin` e a quota padrão para novos utilizadores via `occ`.
-   **ChangePassword:** Altera a password do utilizador `admin` via API OCS (método principal) ou `docker exec occ` (fallback SSH).

### 3.2. Botões Personalizados (Admin)

Na área de administração do WHMCS, na página do serviço do cliente, estão disponíveis os seguintes botões:

-   **Verificar Estado:** Mostra o estado de cada um dos 10 containers.
-   **Verificar DNS:** Verifica se os 3 registros DNS (principal, collabora, signaling) apontam para o IP do servidor configurado no WHMCS. Exibe tabela detalhada com status de cada registro.
-   **Reiniciar Instância:** Executa `stop` e `start` (o manage.sh não tem comando restart).
-   **Fazer Backup:** Executa o comando de backup do `manage.sh`.
-   **Atualizar Instância:** Executa o comando de atualização do `manage.sh` (pull + upgrade).
-   **Testar Conexão SSH:** Valida a comunicação com o servidor.
-   **Testar API Nextcloud:** Valida a comunicação com a API OCS da instância.
-   **Ver Credenciais:** Mostra o conteúdo do ficheiro `.credentials` em painel HTML formatado.
-   **Ver Logs:** Mostra as últimas 50 linhas de logs do container `app`.

### 3.3. Botões Personalizados (Cliente)

-   **Verificar Estado:** Mostra o estado dos containers da instância.
-   **Reiniciar Instância:** Reinicia todos os containers.

### 3.4. Área de Cliente

O cliente tem acesso a um painel de controlo completo e moderno, que inclui:

-   **Estado da Instância:** Mostra se a instância está ativa, parada ou parcial, e o número de containers a correr.
-   **Links de Acesso Rápido:** Botões para aceder diretamente ao Nextcloud, Collabora e Talk.
-   **Informações de Armazenamento:** Barra de progresso e detalhes sobre o uso de disco (obtido via `du -sh` no servidor).
-   **Credenciais:** Informações de acesso completas, lidas diretamente do ficheiro `.credentials` da instância.
-   **Componentes da Instância:** Lista dos 3 containers dedicados (`app`, `cron`, `harp`) e dos 8 serviços globais `shared-*` aos quais a instância se conecta.
-   **Aviso de DNS:** Lembrete constante do registro DNS necessário (1 único, na arquitetura compartilhada v3.0.0).

---

## 4. Changelog

-   **v3.1.2 (2026-05-04):** Hotfix de UX para pedidos criados pelo admin.
    -   Resolve a falha **“Domínio inválido ou não fornecido”** que ocorria em pedidos criados via **Orders > Add New Order** (o WHMCS não dispara `AfterShoppingCartCheckout` nesse fluxo).
    -   Novo `Helper::getDomain($params)` resolve o domínio em qualquer fluxo: `$params['domain']` → `$params['customfields']` (com/sem acento, em PT/EN) → consulta direta a `tblcustomfieldsvalues` por `serviceid`/`pid`.
    -   Novo hook `AcceptOrder` espelha `AfterShoppingCartCheckout` e copia o Custom Field **“Domínio da Instância”** para `tblhosting.domain` quando o admin aceita o pedido.
    -   `nextcloudsaas_CreateAccount`: mensagens de erro específicas para Custom Field ausente, vazio ou domínio inválido, com instruções de onde corrigir.
    -   README §2.4.1: nova subseção **Custom Fields obrigatórios** detalhando o **Campo 0 “Domínio da Instância”** (regex de validação, Required, Show on Order Form) e o passo extra para pedidos do admin.
-   **v3.1.1 (2026-05-04):** Hotfix de UX no provisionamento inicial.
    -   `SSHManager`: porta `22` agora é fallback robusto sempre que o WHMCS passa `0`, vazio ou um valor fora do intervalo `1–65535` (mesmo se o admin esquecer de marcar **Override with Custom Port**).
    -   `SSHManager::testConnection()`: mensagem de erro enriquecida com **dicas específicas** baseadas no sintoma (`Connection closed by server` → sugere ajustar `PasswordAuthentication`; `authentication failed` → sugere checar credenciais; `timeout` → sugere checar firewall). Resolve a pegadinha típica do Ubuntu cloud (`60-cloudimg-settings.conf` definindo `PasswordAuthentication no`).
    -   README §2.1: novos pré-requisitos documentados (`PasswordAuthentication yes` ou chave SSH; **Override with Custom Port = 22**).
-   **v3.1.0 (2026-05-01):**
    -   Novo botão admin **“Listar Instâncias do Servidor”** (`listAllInstances`): dashboard HTML consolidado com todas as instâncias provisionadas no servidor, estado dos 3 containers dedicados (`app`, `cron`, `harp`) e uso de disco por instância.
    -   Novo botão admin **“Ver Logs Talk Recording”** (`viewRecordingLogs`): mostra `docker logs --tail 100 shared-recording` no painel admin.
    -   **CI/CD GitHub Actions**: workflow `ci.yml` faz `php -l` em todos os ficheiros PHP em PRs/pushes para `main`/`develop`; workflow `release.yml` empacota o ZIP e publica automáticamente no GitHub Release a cada tag `vX.Y.Z` (com validação de que `whmcs.json::version` bate com a tag).
    -   **Suite PHPUnit inicial** em `tests/` (PHPUnit 10.5) validando o contrato público do `Helper`: 1 DNS por cliente, 3 sufixos de container dedicado, 8 serviços globais `shared-*`, hostnames globais independentes do domínio do cliente. 6 testes / 17 assertions.
-   **v3.0.0 (2026-05-01):**
    -   **BREAKING:** Alinhado ao Nextcloud SaaS Manager v11.x (arquitetura compartilhada).
    -   Cada instância passa a ter **3 containers dedicados** (`app`, `cron`, `harp`) + **8 serviços globais `shared-*`**.
    -   `Helper::getRequiredDomains()` agora devolve **1 único DNS** por cliente (só o domínio principal).
    -   `Helper::checkDnsRecords()` valida apenas o registro principal; Collabora/Signaling/TURN passam a hostnames globais.
    -   Hooks de carrinho e e-mails refeitos para refletir 1 DNS único.
    -   Novo método `SSHManager::verifySharedServices()` para checar os 8 containers `shared-*`.
    -   Novo botão admin **“Serviços Compartilhados”** (`checkSharedServices`) com painel HTML.
    -   `clientarea.tpl` reformulado: aviso de DNS de 1 registro, lista de 3 containers dedicados + 8 serviços globais (incluindo Talk Recording), confirmação de restart atualizada.
    -   `whmcs.json`, README e USAGE atualizados para v3.0.0.
-   **v2.6.1 (2026-04-07):**
    -   **Correção crítica: Fatal Error por redeclaração de funções.** O WHMCS carregava ambos `includes/hooks/nextcloudsaas_hooks.php` e `modules/servers/nextcloudsaas/hooks.php`, causando `Cannot redeclare nextcloudsaas_cronProcessPendingService()`. O `hooks.php` do módulo agora é apenas um ficheiro de referência que evita duplicação.
    -   **Correção: Hook alterado de `DailyCronJob` para `AfterCronJob`.** O `DailyCronJob` executava apenas 1x por dia. O `AfterCronJob` executa a cada execução do cron WHMCS (recomendado: 5 minutos), garantindo verificação DNS frequente.
    -   **Correção: Status do serviço muda automaticamente de Pending para Active** após provisionamento bem-sucedido via cron. O `localAPI('ModuleCreate')` não altera o status automaticamente; agora o hook faz isso explicitamente via `Capsule::table('tblhosting')->update()`.
    -   **Correção: HTML escapado no botão "Verificar DNS".** O painel usava o tipo `status` que passava o conteúdo por `htmlspecialchars()`. Adicionado tipo `dns` com renderização HTML direta.
    -   **Correção: Estrutura do ZIP de release.** O ZIP agora não contém prefixo de pasta — basta descompactar na raiz do WHMCS para instalar.
-   **v2.6.0 (2026-04-07):**
    -   **Novo: Provisionamento automático via verificação DNS por cron.** O sistema verifica automaticamente os registros DNS de serviços pendentes a cada execução do cron WHMCS (recomendado: 5 minutos). Quando os 3 registros DNS estão corretos, a instância é criada automaticamente via `localAPI('ModuleCreate')`.
    -   **Novo: Email automático ao cliente** com credenciais de acesso (URL, usuário, senha) quando a instância é provisionada automaticamente. Email em HTML profissional com todas as informações de serviço.
    -   **Novo: Timeout de 3 dias** para verificação DNS. Se o cliente não configurar os registros em 3 dias, o sistema para de verificar e envia notificação ao administrador com detalhes do serviço e registros DNS necessários.
    -   **Novo: Botão "Verificar DNS" no admin** que mostra tabela detalhada com status de cada registro DNS (hostname, IP esperado, IP resolvido, status OK/FALHA).
    -   **Novo: Funções DNS no Helper.php** — `checkDnsRecords()`, `getRequiredDomains()`, `getServerConfig()` para verificação programática de registros DNS.
    -   **Melhoria: IP do servidor dinâmico** — O IP é sempre obtido do Server configurado no WHMCS (`tblservers`), nunca hardcoded. Funciona corretamente em ambientes multi-servidor.
    -   **Melhoria: Validação DNS no CreateAccount** — Antes de provisionar, verifica se o DNS está correto. Se não estiver, retorna mensagem informativa e o cron continuará verificando.
    -   **Melhoria: ClientArea atualizado** com tabela DNS formatada e mensagem de "Aguardando configuração DNS" para serviços pendentes.
    -   **Melhoria: Hook AfterShoppingCartCheckout** agora grava timestamp de início da verificação DNS nas notas do serviço para controle de timeout.
    -   **Melhoria: Instruções DNS no carrinho** atualizadas com mensagem sobre provisionamento automático.
-   **v2.5.4 (2026-03-27):**
    -   **Correção definitiva do formulário de domínio:** Removida a dependência do formulário SLD/TLD do WHMCS (que rejeitava subdomínios como `next-jaguar.defensys.seg.br`). Agora utiliza um Custom Field "Domínio da Instância" para capturar o hostname completo sem restrições.
    -   **Novo hook `AfterShoppingCartCheckout`:** Copia automaticamente o valor do Custom Field para o campo Domain do serviço, garantindo que `$params['domain']` funciona corretamente em todas as funções do módulo.
    -   **Novo hook `ServiceEdit`:** Sincroniza o campo Domain com o Custom Field quando o serviço é editado no admin.
    -   **JavaScript simplificado:** Mostra instruções DNS junto ao campo de domínio e adiciona validação básica de formato.
    -   **Configuração necessária:** Desativar "Require Domain" no produto e criar Custom Field "Domínio da Instância" (Text Box, obrigatório, Show on Order Form).
-   **v2.5.3 (2026-03-27):**
    -   Corrigida validação de domínio no formulário de order: adicionado `seg.br` e todos os TLDs brasileiros à lista de TLDs compostos
    -   Lista de TLDs compostos expandida para incluir TLDs internacionais comuns (PT, AR, MX, CO, etc.)
    -   Domínios como `next-jaguar.defensys.seg.br` agora são corretamente separados em SLD=`next-jaguar.defensys` e TLD=`seg.br`
-   **v2.5.2 (2026-02-14):**
    -   **Novo:** Atualização automática do ficheiro `.credentials` quando a password é alterada via ChangePassword. A password do Nextcloud no `.credentials` fica sempre sincronizada.
    -   Nova função `updateCredentialsPassword()` no SSHManager com escape correto de caracteres especiais (colchetes, barras, etc.).
-   **v2.5.1 (2026-02-14):**
    -   **Correção crítica:** Username do Nextcloud agora é sempre `admin` (hardcoded). O `$params['username']` do WHMCS pode estar truncado ou diferente (ex: `nextclou`), causando erro "User does not exist" no ChangePassword e outras funções.
    -   Corrigido em: ChangePassword, ChangePackage, ClientArea e AdminServicesTabFields.
-   **v2.5.0 (2026-02-14):**
    -   **Correção:** ChangePassword reestruturado — agora usa API OCS do Nextcloud como método principal (mais rápido e fiável), com fallback para SSH/docker exec occ.
    -   **Correção:** restartInstance no SSHManager corrigido — o manage.sh v10.0 não tem comando `restart`, agora faz `stop` + `start` corretamente.
    -   **Novo:** Quota padrão para todos os utilizadores — ao criar instância ou alterar pacote, a quota é aplicada tanto ao admin como definida como padrão para novos utilizadores via `config:app:set files default_quota`.
    -   **Novo:** Função `setDefaultQuota()` no SSHManager para definir quota padrão via `occ config:app:set`.
    -   **Melhoria:** Logging detalhado no ChangePassword com rastreio de cada etapa (API OCS, fallback SSH).
    -   **Melhoria:** Mensagens de erro mais informativas incluindo output e error do SSH.
-   **v2.4.5 (2026-02-13):**
    -   **Novo:** Hook de personalização da tela de domínio no carrinho — remove o prefixo "www." e o campo de TLD, substituindo por um campo único de domínio completo.
    -   **Novo:** Instruções de DNS exibidas automaticamente na tela de pedido (3 registros A necessários).
    -   **Novo:** Validação automática que remove "www." se o cliente o incluir no domínio.
    -   **Melhoria:** Botões "Ver Credenciais", "Ver Logs" e "Verificar Estado" agora exibem dados em painéis HTML formatados na aba de serviços do admin, em vez de mensagens de erro.
    -   **Correção:** Botão "Ver Credenciais" corrigido — usava método inexistente `credentialsInstance()`, agora usa `getCredentials()`.
    -   **Novo:** Painel de credenciais com layout em grid, organizado por serviço (Nextcloud, Collabora, MariaDB, TURN, Signaling, HaRP, DNS).
    -   **Novo:** Painel de logs com terminal escuro e scroll automático.
    -   **Novo:** Painel de estado com badge colorido (Ativo/Parcial/Parado) e lista detalhada de containers.
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
-   `lib/NextcloudAPI.php`: Cliente para a API OCS do Nextcloud, usado para testes de conectividade e alteração de passwords.
-   `templates/clientarea.tpl`: Template Smarty para a área de cliente, com um design moderno e informativo.
-   `hooks.php`: Referência para o ficheiro principal de hooks.
-   `includes/hooks/nextcloudsaas_hooks.php`: Hooks completos do módulo — ciclo de vida, personalização do carrinho, verificação DNS por cron, provisionamento automático, emails e notificações.
-   `whmcs.json`: Metadados do módulo.
-   `vendor/`: Contém a biblioteca `phpseclib3`.
