# Guia de Utilização: Módulo Nextcloud-SaaS para WHMCS

**Versão do Módulo:** 2.3.2

Este documento detalha a função de cada um dos **Module Commands** disponíveis na área de administração do WHMCS para um serviço que utiliza o módulo Nextcloud-SaaS. Estes botões permitem ao administrador gerir o ciclo de vida completo e realizar operações de manutenção numa instância Nextcloud diretamente a partir do WHMCS.

---

## Visão Geral

Na página de gestão de um produto/serviço do cliente no WHMCS (separador **Module Commands**), estão disponíveis **15 botões** organizados em duas categorias:

- **Comandos do Ciclo de Vida Padrão** (7 botões): Create, Renew, Suspend, Unsuspend, Terminate, Change Package, Change Password
- **Comandos Personalizados de Gestão** (8 botões): Verificar Estado, Reiniciar Instância, Fazer Backup, Atualizar Instância, Testar Conexão SSH, Testar API Nextcloud, Ver Credenciais, Ver Logs

---

## 1. Comandos do Ciclo de Vida Padrão

Estes são os comandos padrão do WHMCS para automação de provisionamento. São acionados automaticamente com base nas ações do cliente e da faturação (por exemplo, pagamento de fatura ou pedido de cancelamento), mas também podem ser executados manualmente pelo administrador.

### Create

**Função:** `nextcloudsaas_CreateAccount`

Provisiona uma nova instância Nextcloud no servidor. Executa o script `manage.sh --new` via SSH, que realiza as seguintes operações:

1. Cria um novo subdomínio no formato `cliente.dominio.com`
2. Gera um certificado SSL Let's Encrypt para o subdomínio
3. Instala o Nextcloud com a configuração padrão
4. Cria um utilizador administrador (`admin`) com uma password aleatória
5. Grava as credenciais no ficheiro `.credentials` na pasta da instância

Após a execução bem-sucedida, o serviço fica imediatamente acessível via HTTPS.

### Renew

**Função:** `nextcloudsaas_Renew`

Esta função existe por compatibilidade com o WHMCS, mas não tem um efeito prático direto no Nextcloud-SaaS. A renovação do serviço é gerida automaticamente pelo ciclo de faturação do WHMCS. Ao ser executada, verifica o estado da instância e confirma que está ativa.

### Suspend

**Função:** `nextcloudsaas_SuspendAccount`

Suspende temporariamente uma instância Nextcloud. Executa o script `manage.sh --suspend` via SSH, que desativa o vhost Apache da instância, bloqueando o acesso web. O Nextcloud, a base de dados e todos os ficheiros do utilizador permanecem intactos no servidor. O cliente verá uma página de erro ao tentar aceder ao seu Nextcloud.

Esta ação é tipicamente acionada automaticamente quando uma fatura não é paga dentro do prazo configurado.

### Unsuspend

**Função:** `nextcloudsaas_UnsuspendAccount`

Reativa uma instância Nextcloud que foi previamente suspensa. Executa o script `manage.sh --unsuspend` via SSH, que reativa o vhost Apache, restaurando o acesso web. O cliente volta a poder aceder ao seu Nextcloud normalmente, com todos os dados intactos.

Esta ação é tipicamente acionada automaticamente quando o cliente paga a fatura em atraso.

### Terminate

**Função:** `nextcloudsaas_TerminateAccount`

Termina e remove permanentemente uma instância Nextcloud. Executa o script `manage.sh --delete` via SSH, que realiza as seguintes operações:

1. Remove a base de dados MySQL/MariaDB da instância
2. Apaga todos os ficheiros do Nextcloud e dados do utilizador
3. Remove o vhost Apache e o certificado SSL

> **Atenção:** Esta ação é **irreversível**. Todos os dados do cliente serão permanentemente apagados. Recomenda-se fazer um backup antes de executar este comando.

### Change Package

**Função:** `nextcloudsaas_ChangePackage`

Altera o pacote de alojamento de uma instância, atualizando a quota de armazenamento do utilizador. Executa o comando `occ user:setting [user] files quota [nova_quota]` via SSH para ajustar o limite de espaço em disco conforme o novo pacote selecionado no WHMCS.

Esta ação é acionada quando o administrador altera o produto/pacote associado ao serviço do cliente.

### Change Password

**Função:** `nextcloudsaas_ChangePassword`

Altera a password do utilizador administrador do Nextcloud. Executa o comando `occ user:resetpassword [user] --password-from-stdin` via SSH para definir a nova password. A nova password é a que está definida no campo "Password" do serviço no WHMCS.

---

## 2. Comandos Personalizados de Gestão

Estes botões foram adicionados especificamente para o módulo Nextcloud-SaaS, fornecendo funcionalidades de gestão e diagnóstico que facilitam a administração das instâncias.

### Verificar Estado

**Função:** `nextcloudsaas_checkStatus`

Verifica o estado atual da instância Nextcloud. Executa o script `manage.sh --status` via SSH, que verifica se o processo do Apache está a correr e se o site está acessível. Retorna uma das seguintes mensagens:

- **Ativo** — A instância está a funcionar normalmente
- **Inativo** — A instância está parada ou inacessível

Útil para confirmar rapidamente se o serviço do cliente está operacional.

### Reiniciar Instância

**Função:** `nextcloudsaas_restartInstance`

Reinicia o serviço Apache da instância Nextcloud. Executa o comando `sudo systemctl restart apache2` via SSH. Este comando é útil nas seguintes situações:

- Após alterações de configuração do Apache ou do Nextcloud
- Quando a instância apresenta lentidão ou erros intermitentes
- Para aplicar atualizações de módulos PHP

### Fazer Backup

**Função:** `nextcloudsaas_makeBackup`

Cria um backup completo da instância Nextcloud. Executa o script `manage.sh --backup` via SSH, que gera um ficheiro `.tar.gz` contendo:

- A base de dados completa (dump MySQL/MariaDB)
- Todos os ficheiros do Nextcloud (incluindo dados dos utilizadores)
- Os ficheiros de configuração

O backup é guardado na pasta da instância no servidor. Recomenda-se executar este comando antes de operações críticas como **Terminate** ou **Atualizar Instância**.

### Atualizar Instância

**Função:** `nextcloudsaas_updateInstance`

Atualiza a versão do Nextcloud para a mais recente disponível. Executa o script `manage.sh --update` via SSH, que utiliza o atualizador integrado (`occ upgrade`) do Nextcloud para realizar o upgrade de forma segura.

> **Recomendação:** Execute sempre o comando **Fazer Backup** antes de atualizar a instância, para garantir que é possível reverter em caso de problemas.

### Testar Conexão SSH

**Função:** `nextcloudsaas_testSSH`

Testa a ligação SSH entre o WHMCS e o servidor da instância. Verifica se consegue estabelecer uma conexão SSH e executar um comando simples (`whoami`). Retorna uma das seguintes mensagens:

- **Sucesso** — A conexão SSH está a funcionar corretamente
- **Erro** — Com detalhes sobre o problema (ex: timeout, autenticação falhada, host inacessível)

Este é o primeiro comando de diagnóstico a executar quando qualquer outro comando falha, pois todos dependem da conexão SSH.

### Testar API Nextcloud

**Função:** `nextcloudsaas_testAPI`

Testa a ligação com a API OCS do Nextcloud. Este comando realiza as seguintes operações:

1. Obtém as credenciais reais do administrador a partir do ficheiro `.credentials` via SSH
2. Faz uma chamada HTTP à API OCS (`/ocs/v1.php/cloud/capabilities`) com autenticação HTTP Basic
3. Verifica se a resposta é válida e se a autenticação foi aceite

Retorna **Sucesso** com a versão do Nextcloud instalada, ou uma mensagem de erro detalhada. Útil para verificar se a API está acessível e se as credenciais estão corretas.

### Ver Credenciais

**Função:** `nextcloudsaas_getCredentials`

Exibe as credenciais de acesso da instância Nextcloud numa janela modal. Lê o conteúdo do ficheiro `.credentials` via SSH e apresenta as seguintes informações:

- **URL** de acesso ao Nextcloud (ex: `https://cliente.dominio.com`)
- **Utilizador** administrador (tipicamente `admin`)
- **Password** do administrador

Útil para consultar rapidamente as credenciais sem precisar de aceder ao servidor via SSH.

### Ver Logs

**Função:** `nextcloudsaas_viewLogs`

Exibe os logs de erro do Apache para a instância Nextcloud. Lê as últimas **50 linhas** do ficheiro de log de erros do vhost (`/var/log/apache2/[subdomain]-error.log`) via SSH e apresenta-as numa janela modal.

Este comando é essencial para diagnosticar problemas na aplicação, tais como:

- Erros de PHP ou de módulos do Nextcloud
- Problemas de permissões de ficheiros
- Erros de configuração do Apache
- Falhas de ligação à base de dados

---

## Resumo Rápido

| Botão | Categoria | Ação Principal |
|---|---|---|
| **Create** | Ciclo de Vida | Provisiona nova instância Nextcloud |
| **Renew** | Ciclo de Vida | Verifica estado (compatibilidade) |
| **Suspend** | Ciclo de Vida | Desativa o acesso web (dados intactos) |
| **Unsuspend** | Ciclo de Vida | Reativa o acesso web |
| **Terminate** | Ciclo de Vida | Remove permanentemente a instância |
| **Change Package** | Ciclo de Vida | Altera quota de armazenamento |
| **Change Password** | Ciclo de Vida | Altera password do admin Nextcloud |
| **Verificar Estado** | Gestão | Verifica se a instância está ativa |
| **Reiniciar Instância** | Gestão | Reinicia o Apache da instância |
| **Fazer Backup** | Gestão | Cria backup completo (.tar.gz) |
| **Atualizar Instância** | Gestão | Atualiza o Nextcloud para a última versão |
| **Testar Conexão SSH** | Diagnóstico | Testa a ligação SSH com o servidor |
| **Testar API Nextcloud** | Diagnóstico | Testa a API OCS com credenciais reais |
| **Ver Credenciais** | Diagnóstico | Exibe URL, utilizador e password |
| **Ver Logs** | Diagnóstico | Exibe últimas 50 linhas do log de erros |

---

**Módulo Nextcloud-SaaS para WHMCS** — Desenvolvido por Defensys Tech
