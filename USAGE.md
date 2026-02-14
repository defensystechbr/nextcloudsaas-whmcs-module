# Guia de Utilização: Módulo Nextcloud-SaaS para WHMCS

**Versão do Módulo:** 2.4.5
**Versão do manage.sh:** 10.0

Este documento detalha a função de cada um dos **Module Commands** disponíveis na área de administração do WHMCS para um serviço que utiliza o módulo Nextcloud-SaaS. Estes botões permitem ao administrador gerir o ciclo de vida completo e realizar operações de manutenção numa instância Nextcloud diretamente a partir do WHMCS.

---

## Arquitetura da Plataforma

Cada instância Nextcloud é composta por **10 containers Docker** geridos via `docker-compose`, atrás de um proxy reverso **Traefik** com SSL automático via Let's Encrypt. Não é utilizado Apache nem Nginx diretamente — toda a infraestrutura é baseada em containers.

| Container | Imagem | Função |
|---|---|---|
| **app** | Nextcloud (oficial) | Aplicação Nextcloud principal |
| **db** | MariaDB 10.11 | Base de dados relacional |
| **redis** | Redis Alpine | Cache e locking de ficheiros |
| **collabora** | Collabora Online | Edição colaborativa de documentos (Nextcloud Office) |
| **turn** | coturn | Servidor TURN/STUN para WebRTC (Talk) |
| **cron** | Nextcloud (cron) | Execução de tarefas agendadas em background |
| **harp** | HaRP | Daemon para AppAPI (substitui Docker Socket Proxy) |
| **nats** | NATS | Messaging para o High Performance Backend (HPB) |
| **janus** | Janus Gateway | Gateway WebRTC para o HPB (Talk) |
| **signaling** | Spreed Signaling | Servidor de sinalização para o HPB (Talk) |

Cada instância requer **3 registros DNS** (tipo A) apontando para o IP do servidor:

1. `dominio.com.br` — Nextcloud
2. `collabora-dominio.com.br` — Collabora Online
3. `signaling-dominio.com.br` — HPB Signaling

Os ficheiros de cada instância ficam em `/opt/nextcloud-customers/<nome-cliente>/`, incluindo o `docker-compose.yml`, `.env`, `.credentials` e os volumes dos containers.

---

## Visão Geral dos Comandos

Na página de gestão de um produto/serviço do cliente no WHMCS (secção **Module Commands**), estão disponíveis **15 botões** organizados em duas categorias:

- **Comandos do Ciclo de Vida Padrão** (7 botões): Create, Renew, Suspend, Unsuspend, Terminate, Change Package, Change Password
- **Comandos Personalizados de Gestão e Diagnóstico** (8 botões): Verificar Estado, Reiniciar Instância, Fazer Backup, Atualizar Instância, Testar Conexão SSH, Testar API Nextcloud, Ver Credenciais, Ver Logs

---

## 1. Comandos do Ciclo de Vida Padrão

Estes são os comandos padrão do WHMCS para automação de provisionamento. São acionados automaticamente com base nas ações do cliente e da faturação (por exemplo, pagamento de fatura ou pedido de cancelamento), mas também podem ser executados manualmente pelo administrador.

### Create

**Função PHP:** `nextcloudsaas_CreateAccount`
**Comando no servidor:** `manage.sh <cliente> <dominio> create`

Provisiona uma nova instância Nextcloud completa no servidor. O módulo conecta-se via SSH ao servidor e executa o `manage.sh` com o comando `create`, que realiza automaticamente as seguintes operações:

1. Verifica os 3 registros DNS necessários (Nextcloud, Collabora, Signaling)
2. Gera passwords aleatórias para todos os serviços (admin Nextcloud, Collabora, MariaDB, TURN, Signaling, HaRP)
3. Encontra uma porta TURN disponível (range 3478-3999)
4. Cria o diretório da instância em `/opt/nextcloud-customers/<cliente>/`
5. Gera os ficheiros `.env`, `.credentials`, `docker-compose.yml` e configurações HPB
6. Inicia os 10 containers via `docker-compose up -d`
7. Aguarda o Nextcloud ficar instalado (até 9 minutos)
8. Executa 16 passos de pós-instalação:
   - Configura background jobs via cron
   - Configura Redis como cache e locking
   - Configura trusted proxies e overwrite protocol (HTTPS)
   - Corrige índices da base de dados
   - Executa reparos e migração de mimetypes
   - Instala aplicativos essenciais (Nextcloud Office, Calendar, Contacts, Mail, Deck, Forms, Group Folders, Notes, Tasks, Photos, Activity, Talk, AppAPI, Client Push)
   - Configura Collabora Online (WOPI URL)
   - Configura Talk com TURN/STUN server
   - Configura Talk HPB (Signaling Server)
   - Configura AppAPI com HaRP daemon
   - Configura trusted domains
   - Configura Client Push (notify_push)
   - Configurações de segurança e reparo final
9. Executa verificação final de todos os componentes
10. Grava as credenciais no ficheiro `.credentials`

Após a execução bem-sucedida, o módulo WHMCS atualiza automaticamente os campos do serviço (username, password, domínio, IP) e os campos personalizados (Client Name, Nextcloud URL, Collabora URL, Signaling URL). Se configurada, aplica também a quota de armazenamento ao utilizador admin.

### Renew

**Função PHP:** `nextcloudsaas_Renew`
**Comando no servidor:** `manage.sh <cliente> _ status` (e `start` se necessário)

Verifica se a instância está ativa e, caso não esteja a correr, tenta iniciá-la automaticamente executando `manage.sh start`. A renovação do serviço em si é gerida pelo ciclo de faturação do WHMCS. Esta função garante que a instância está operacional no momento da renovação.

### Suspend

**Função PHP:** `nextcloudsaas_SuspendAccount`
**Comando no servidor:** `manage.sh <cliente> _ stop`

Suspende temporariamente uma instância Nextcloud, parando todos os 10 containers Docker via `docker-compose stop`. Os containers são parados mas não removidos, e todos os volumes de dados (base de dados, ficheiros do Nextcloud, configurações) permanecem intactos no servidor. O cliente deixa de conseguir aceder ao seu Nextcloud enquanto os containers estiverem parados.

Esta ação é tipicamente acionada automaticamente quando uma fatura não é paga dentro do prazo configurado no WHMCS.

### Unsuspend

**Função PHP:** `nextcloudsaas_UnsuspendAccount`
**Comando no servidor:** `manage.sh <cliente> _ start`

Reativa uma instância Nextcloud que foi previamente suspensa, reiniciando todos os 10 containers Docker via `docker-compose up -d`. O cliente volta a poder aceder ao seu Nextcloud normalmente, com todos os dados intactos.

Esta ação é tipicamente acionada automaticamente quando o cliente paga a fatura em atraso.

### Terminate

**Função PHP:** `nextcloudsaas_TerminateAccount`
**Comando no servidor:** `manage.sh <cliente> _ backup` seguido de `manage.sh <cliente> _ remove`

Termina e remove permanentemente uma instância Nextcloud. O módulo executa automaticamente um **backup de segurança** antes da remoção. Em seguida, o `manage.sh remove` realiza as seguintes operações:

1. Para e remove todos os containers e volumes via `docker-compose down -v --remove-orphans`
2. Remove manualmente quaisquer containers órfãos (todos os 10 sufixos)
3. Apaga o diretório completo da instância em `/opt/nextcloud-customers/<cliente>/`

> **Atenção:** Esta ação é **irreversível** após a remoção do diretório. O backup automático fica guardado em `/opt/nextcloud-customers/backups/` e pode ser usado para restaurar a instância se necessário.

### Change Package

**Função PHP:** `nextcloudsaas_ChangePackage`
**Comando no servidor:** `docker exec -u www-data <cliente>-app php occ user:setting <user> files quota <valor>`

Altera o pacote de alojamento de uma instância, atualizando a quota de armazenamento do utilizador admin. O módulo conecta-se via SSH e executa o comando `occ user:setting` diretamente no container `app` da instância para ajustar o limite de espaço em disco conforme o novo pacote selecionado no WHMCS.

### Change Password

**Função PHP:** `nextcloudsaas_ChangePassword`
**Comando no servidor:** `docker exec -u www-data -e OC_PASS <cliente>-app php occ user:resetpassword --password-from-env <user>`

Altera a password do utilizador admin do Nextcloud. O módulo tenta primeiro alterar via API OCS do Nextcloud (mais rápido). Se falhar, usa o fallback via SSH executando o comando `occ user:resetpassword` diretamente no container `app`. A nova password é a que está definida no campo "Password" do serviço no WHMCS.

---

## 2. Comandos Personalizados de Gestão e Diagnóstico

Estes botões foram adicionados especificamente para o módulo Nextcloud-SaaS, fornecendo funcionalidades de gestão e diagnóstico que facilitam a administração das instâncias sem necessidade de acesso direto ao servidor.

### Verificar Estado

**Função PHP:** `nextcloudsaas_checkStatus`
**Comando no servidor:** `manage.sh <cliente> _ status`

Verifica o estado detalhado da instância Nextcloud. O `manage.sh status` verifica o estado de cada um dos 10 containers Docker e apresenta:

- Estado de cada container (running/stopped/exited)
- URLs de acesso (Nextcloud, Collabora, Signaling)
- Porta TURN configurada
- Resultado do `occ status` para verificar se o Nextcloud está a responder

Útil para confirmar rapidamente se todos os componentes do serviço estão operacionais.

### Reiniciar Instância

**Função PHP:** `nextcloudsaas_restartInstance`
**Comando no servidor:** `manage.sh <cliente> _ stop` seguido de `manage.sh <cliente> _ start`

Reinicia todos os 10 containers da instância, executando um `docker-compose stop` seguido de `docker-compose up -d` com um intervalo de 3 segundos entre as operações. Este comando é útil nas seguintes situações:

- Após alterações de configuração
- Quando a instância apresenta lentidão ou erros intermitentes
- Para resolver problemas de conectividade entre containers
- Após atualizações de configuração do Nextcloud

### Fazer Backup

**Função PHP:** `nextcloudsaas_backupInstance`
**Comando no servidor:** `manage.sh <cliente> _ backup`

Cria um backup completo da instância Nextcloud. O `manage.sh backup` executa as seguintes operações:

1. Ativa o modo de manutenção do Nextcloud (`occ maintenance:mode --on`)
2. Exporta a base de dados MariaDB via `mysqldump` para `db_backup.sql`
3. Para todos os containers (`docker-compose stop`)
4. Compacta todo o diretório da instância num ficheiro `.tar.gz`
5. Reinicia todos os containers (`docker-compose up -d`)
6. Desativa o modo de manutenção (`occ maintenance:mode --off`)

O backup é guardado em `/opt/nextcloud-customers/backups/<cliente>-backup-<data_hora>.tar.gz`. Recomenda-se executar este comando antes de operações críticas como **Terminate** ou **Atualizar Instância**.

### Atualizar Instância

**Função PHP:** `nextcloudsaas_updateInstance`
**Comando no servidor:** `manage.sh <cliente> _ update`

Atualiza a instância Nextcloud para a versão mais recente disponível. O `manage.sh update` executa as seguintes operações:

1. Faz um **backup de segurança** automático antes de atualizar
2. Baixa as novas imagens Docker (`docker-compose pull`)
3. Recria os containers com as novas imagens (`docker-compose up -d`)
4. Aguarda 15 segundos para os containers estabilizarem
5. Executa o upgrade do Nextcloud (`occ upgrade`)
6. Corrige índices da base de dados (`occ db:add-missing-indices`)
7. Desativa o modo de manutenção (`occ maintenance:mode --off`)

> **Recomendação:** Embora o update já faça backup automático, é boa prática verificar o estado da instância após a atualização usando o botão **Verificar Estado**.

### Testar Conexão SSH

**Função PHP:** `nextcloudsaas_testSSH`
**Comando no servidor:** `echo "SSH_CONNECTION_OK" && hostname && uptime`

Testa a ligação SSH entre o WHMCS e o servidor da instância. Verifica se consegue estabelecer uma conexão SSH (via phpseclib3, ssh2 ou sshpass como fallback) e executar comandos simples. Retorna:

- **Sucesso** — A conexão SSH está a funcionar corretamente, com o hostname e uptime do servidor
- **Erro** — Com detalhes sobre o problema (timeout, autenticação falhada, host inacessível)

Este é o **primeiro comando de diagnóstico** a executar quando qualquer outro comando falha, pois todos os comandos do módulo dependem da conexão SSH para comunicar com o servidor.

### Testar API Nextcloud

**Função PHP:** `nextcloudsaas_testAPI`
**Comando no servidor:** Leitura do `.credentials` via SSH + chamada HTTP à API OCS

Testa a ligação com a API OCS do Nextcloud. Este comando realiza as seguintes operações:

1. Conecta-se via SSH ao servidor e lê o ficheiro `.credentials` da instância para obter a password real do admin
2. Faz uma chamada HTTP à API OCS (`GET /ocs/v1.php/cloud/capabilities`) com autenticação HTTP Basic
3. Verifica se a resposta é válida e se a autenticação foi aceite pelo Nextcloud

Retorna **Sucesso** se a API responder corretamente, ou uma mensagem de erro detalhada. Útil para verificar se o Nextcloud está a responder a pedidos HTTP e se as credenciais do admin estão corretas.

### Ver Credenciais

**Função PHP:** `nextcloudsaas_viewCredentials`
**Comando no servidor:** `manage.sh <cliente> _ credentials` (leitura do ficheiro `.credentials`)

Exibe as credenciais completas da instância. O ficheiro `.credentials` contém:

- **Nextcloud:** URL de acesso, utilizador admin e password
- **Collabora Online:** URL de acesso, admin e password
- **Base de Dados (MariaDB):** Host, database, utilizador, password e root password
- **TURN Server:** Secret, porta e endereço
- **Signaling Server:** URL e secret
- **HaRP (AppAPI):** Shared key
- **DNS necessários:** Os 3 registros A com o IP do servidor

### Ver Logs

**Função PHP:** `nextcloudsaas_viewLogs`
**Comando no servidor:** `docker logs --tail 100 <cliente>-app 2>&1`

Exibe as últimas **100 linhas** dos logs do container principal (`app`) da instância Nextcloud. Os logs são obtidos diretamente do Docker via `docker logs`, não de ficheiros de log do Apache (que não é utilizado nesta arquitetura).

Este comando é essencial para diagnosticar problemas na aplicação, tais como:

- Erros de PHP ou de módulos do Nextcloud
- Problemas de conectividade com a base de dados ou Redis
- Erros de configuração do Nextcloud
- Falhas em tarefas de background
- Problemas com apps instalados

---

## Resumo Rápido

| Botão | Categoria | Comando manage.sh | Ação Principal |
|---|---|---|---|
| **Create** | Ciclo de Vida | `create` | Provisiona 10 containers + 16 passos de pós-instalação |
| **Renew** | Ciclo de Vida | `status` / `start` | Verifica e reinicia se necessário |
| **Suspend** | Ciclo de Vida | `stop` | Para os 10 containers (dados intactos) |
| **Unsuspend** | Ciclo de Vida | `start` | Reinicia os 10 containers |
| **Terminate** | Ciclo de Vida | `backup` + `remove` | Backup automático + remoção permanente |
| **Change Package** | Ciclo de Vida | `occ` (via Docker) | Altera quota de armazenamento |
| **Change Password** | Ciclo de Vida | API OCS / `occ` | Altera password do admin |
| **Verificar Estado** | Gestão | `status` | Estado dos 10 containers + occ status |
| **Reiniciar Instância** | Gestão | `stop` + `start` | Para e reinicia todos os containers |
| **Fazer Backup** | Gestão | `backup` | mysqldump + tar.gz de toda a instância |
| **Atualizar Instância** | Gestão | `update` | Backup + pull + upgrade + reindex |
| **Testar Conexão SSH** | Diagnóstico | — | Testa conexão SSH com o servidor |
| **Testar API Nextcloud** | Diagnóstico | — | Testa API OCS com credenciais reais |
| **Ver Credenciais** | Diagnóstico | `credentials` | Exibe todas as credenciais da instância |
| **Ver Logs** | Diagnóstico | `docker logs` | Últimas 100 linhas do container app |

---

## Aplicativos Instalados Automaticamente

Cada instância Nextcloud é criada com os seguintes aplicativos pré-instalados e configurados:

| Aplicativo | Descrição |
|---|---|
| **Nextcloud Office** (richdocuments) | Edição colaborativa de documentos via Collabora Online |
| **Calendar** | Calendário com suporte a CalDAV |
| **Contacts** | Gestão de contactos com suporte a CardDAV |
| **Mail** | Cliente de email integrado |
| **Deck** | Quadros Kanban para gestão de projetos |
| **Forms** | Criação de formulários e inquéritos |
| **Group Folders** | Pastas partilhadas por grupos |
| **Notes** | Notas com suporte a Markdown |
| **Tasks** | Gestão de tarefas |
| **Photos** | Galeria de fotos com reconhecimento facial |
| **Activity** | Registo de atividades e notificações |
| **Talk** (spreed) | Videoconferência com HPB (TURN/STUN + Signaling) |
| **AppAPI** | API para aplicações externas via HaRP |
| **Client Push** (notify_push) | Notificações push em tempo real |

---

**Módulo Nextcloud-SaaS para WHMCS** — Desenvolvido por Defensys Tech
