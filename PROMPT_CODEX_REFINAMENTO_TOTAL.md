# Prompt único para o Codex — refinamento total do Impulso Hub Atendimento

## Contexto e prioridade

Você trabalhará no plugin Rise CRM `Chatwoot_plugin`, instalado em uma aplicação Rise CRM 3.9.6 / CodeIgniter 4.6.3.

O plugin já possui uma integração funcional com a Evolution API para:

- múltiplas instâncias;
- sincronização de conversas;
- carregamento de histórico;
- envio de mensagens de texto;
- recebimento por webhook;
- polling;
- deduplicação e persistência básica.

O front foi completamente refinado e agora contém a interface definitiva para conversas, contatos, campanhas, IA, automações, relatórios, configurações, mídia, notificações, respostas rápidas e ações operacionais.

**Sua tarefa é implementar todo o backend faltante e ligar integralmente esse front, preservando a integração Evolution que já funciona.**

Leia integralmente antes de alterar qualquer arquivo:

1. `BACKEND_CONTRACT_REFINED.md` — contrato obrigatório e fonte de verdade dos endpoints.
2. `EVOLUTION_INTEGRATION.md` — comportamento existente da integração Evolution.
3. `Assets/js/chatwoot.js` — runtime estável da caixa de entrada.
4. `Assets/js/hub-workspace.js` — todos os endpoints e contratos esperados pelo front refinado.
5. Todas as Views e modais atuais.
6. Migrations, models, services, controllers e testes existentes.
7. O código do Rise para convenções de controllers, models, permissões, CSRF, upload, paginação e migrations.
8. O repositório `chatwoot-main` apenas como referência comportamental; não o modifique e não tente portar o Chatwoot inteiro.

## Regra de execução

Não pare após analisar ou escrever um plano. Execute a implementação completa dentro desta tarefa.

Trabalhe internamente em fases, mas entregue o conjunto integrado ao final. Sempre mantenha o plugin instalável entre fases. Quando uma credencial externa não estiver disponível, implemente o client, use fixtures/mocks nos testes e documente o smoke test real; não abandone o módulo.

Não declare sucesso enquanto existirem:

- botões visíveis sem ação;
- buscas que só filtram mocks;
- rotas 404/405 esperadas pelo front;
- mensagens “Fora deste MVP”, “backend pendente” ou equivalente;
- campos que aparentam salvar mas são ignorados;
- dados fictícios apresentados como métricas reais;
- controles desabilitados sem justificativa de permissão/estado;
- chamadas diretas do browser para Evolution ou n8n.

## Regra visual absoluta

O front atual é a fonte de verdade visual.

Você NÃO deve:

- redesenhar páginas;
- remover módulos;
- substituir o CSS;
- trocar a estrutura de navegação;
- mudar os IDs usados pelo JavaScript;
- reintroduzir fundos brancos rígidos;
- forçar cores globais em `.form-control`, `.btn`, `.modal-content`, `.card` ou componentes do Rise;
- transformar a caixa de entrada em outra interface;
- excluir estados vazios, loaders, responsividade ou painéis.

Você pode alterar Views/JS somente quando houver um erro real de contrato, acessibilidade ou segurança que não possa ser resolvido no backend. Toda alteração visual deve ser mínima e documentada.

## Arquitetura final obrigatória

```text
Rise/Impulso Hub
├── Front operacional definitivo
├── Controllers autenticados
├── Services de domínio
├── Models e migrations incrementais
├── Evolution_client
├── N8n_client
├── Media_service
├── Campaign_service
├── Ai_service
├── Contact_service
├── Report_service
├── Notification_service
├── Audit_service
├── Webhook normalizer/processor
└── Banco local/cache/auditoria

Evolution API
├── WhatsApp e instâncias
├── mensagens/histórico
├── mídia
└── eventos

n8n
├── campanhas
├── IARA/IA
├── automações
├── memória/RAG
└── regras externas
```

O plugin não deve recriar os fluxos internos do n8n. Ele funciona como uma fachada segura e operacional, com cache/persistência local suficiente para consistência, auditoria e experiência rápida.

---

# PARTE 1 — preservar e endurecer o núcleo Evolution existente

## 1. Auditoria antes de alterar

Mapeie:

- rotas atuais;
- migrations e versão instalada;
- models existentes;
- formato de conversas e mensagens retornado ao front;
- envio otimista e reconciliação;
- webhook, locks, transações e deduplicação;
- permissões;
- settings/credenciais;
- testes atuais.

Crie uma lista interna de invariantes e não as quebre.

## 2. Compatibilidade de webhook obrigatória

Confirme e, se necessário, conclua o suporte simultâneo a:

```text
instance
instance_name
instanceName
instance_Name

remote_jid
remoteJid
body_remoteJid
number
chat_id
remoteJidAlt
remote_jid_alt

external_message_id
message_id
body_key_id
key.id
```

### Regra crítica de `@lid`

Quando o payload tiver:

```text
remoteJid = 123456789012345@lid
remoteJidAlt = 5511999999999@s.whatsapp.net
```

faça o seguinte:

1. preserve o LID como identificador alternativo;
2. use o JID alternativo/número real para `phone_number` e destino;
3. nunca use os dígitos do LID como telefone;
4. mantenha um mapeamento persistente LID ↔ JID/telefone;
5. deduplique mensagens independentemente de o evento seguinte usar LID ou JID normal.

Adicione testes com payloads reais da IARA e Evolution.

## 3. Status e concorrência

Mantenha status monotônico:

```text
pending < sent < delivered < read
```

Eventos fora de ordem não podem:

- regredir status;
- substituir mensagem mais nova por antiga;
- incrementar não lidas duas vezes;
- criar conversa duplicada;
- duplicar mídia;
- reabrir conversa resolvida sem evento/regra explícita.

Use transações e locks granulares onde necessário.

## 4. Configuração de endpoints

Todos os endpoints Evolution devem continuar centralizados e configuráveis:

```text
connectionState
findChats
findMessages
sendText
sendMedia
sendWhatsAppAudio
getBase64FromMediaMessage ou equivalente
```

Não espalhe paths em controllers.

---

# PARTE 2 — migrations e domínio local

## 5. Migrations incrementais

Não altere destrutivamente a migration V001 já instalada. Crie V002, V003 etc., idempotentes e compatíveis com banco existente.

Implemente, no mínimo:

```text
chat_contacts
chat_contact_identifiers
chat_tags
chat_contact_tags
chat_conversation_tags
chat_internal_notes
chat_quick_replies
chat_media
chat_campaigns
chat_campaign_runs
chat_campaign_recipients
chat_campaign_templates
chat_ai_agents
chat_automations
chat_ai_states
chat_ai_logs
chat_notifications
chat_audit_logs
chat_integration_jobs
```

Adicione a `chat_conversations`, quando ausentes:

```text
contact_id BIGINT NULL
priority VARCHAR/TINYINT
assignee_id BIGINT NULL
team_id BIGINT NULL
resolved_at DATETIME NULL
resolved_by BIGINT NULL
ai_status VARCHAR(32)
ai_summary LONGTEXT NULL
last_human_message_at DATETIME NULL
last_bot_message_at DATETIME NULL
first_response_at DATETIME NULL
first_response_seconds INT NULL
```

Adicione a `chat_messages`, quando necessários:

```text
sender_user_id
reply_to_external_message_id
caption
file_name
file_size
media_id
is_internal_note
delivery_error
failed_at
```

### Requisitos de banco

- prefixo do Rise via `prefixTable`;
- InnoDB;
- `utf8mb4_unicode_ci` ou padrão compatível da aplicação;
- índices para paginação, atividade, telefone, tags, status e idempotência;
- índices únicos externos sem impedir mensagens legítimas sem external ID;
- soft delete no padrão do projeto;
- migrations seguras em instalação nova e atualização;
- rollback apenas quando seguro;
- nenhum `DROP` destrutivo em upgrade normal.

## 6. Models

Crie models pequenos e coesos. Não coloque regra de negócio complexa no model.

Models esperados:

```text
Chat_contacts_model
Chat_contact_identifiers_model
Chat_tags_model
Chat_internal_notes_model
Chat_quick_replies_model
Chat_media_model
Chat_campaigns_model
Chat_campaign_runs_model
Chat_campaign_recipients_model
Chat_campaign_templates_model
Chat_ai_agents_model
Chat_automations_model
Chat_ai_states_model
Chat_ai_logs_model
Chat_notifications_model
Chat_audit_logs_model
Chat_integration_jobs_model
```

Todos devem:

- usar queries parametrizadas/query builder;
- oferecer paginação e filtros consistentes;
- não expor segredo;
- permitir testes isolados;
- normalizar datas no backend e apresentar timezone no boundary.

---

# PARTE 3 — contatos reais

## 7. Contact_service

Implemente um serviço central para:

- normalização E.164;
- associação a instâncias/conversas;
- múltiplos identificadores (`@s.whatsapp.net`, `@lid`, telefone);
- merge de duplicados;
- atualização de nome/foto sem destruir edição manual;
- tags;
- notas;
- origem;
- opt-out;
- importação/exportação;
- estatísticas de atividade.

## 8. Rotas de contatos

Implemente exatamente:

```text
GET    /chatwoot_plugin/api/contacts
POST   /chatwoot_plugin/api/contacts
GET    /chatwoot_plugin/api/contacts/{id}
PUT    /chatwoot_plugin/api/contacts/{id}
DELETE /chatwoot_plugin/api/contacts/{id}
POST   /chatwoot_plugin/api/contacts/{id}/opt-out
POST   /chatwoot_plugin/api/contacts/bulk-tags
POST   /chatwoot_plugin/api/contacts/import
GET    /chatwoot_plugin/api/contacts/export
```

### Lista

Aceitar:

```text
q
instance_id
status
tag
opt_out
page
limit
cursor
sort
```

Retornar `data` e `meta` com total/has_more.

### CRUD

Validar:

- nome;
- telefone;
- e-mail opcional;
- instância opcional;
- tags;
- cidade/empresa/origem/notas;
- opt-out.

Não permitir que dois contatos incompatíveis sejam unidos silenciosamente.

### Importação CSV

- aceitar somente CSV válido;
- limite configurável;
- detectar delimiter/encoding com segurança;
- mapear cabeçalhos comuns;
- normalizar telefone;
- dry-run no service;
- transação por lotes;
- retornar inseridos/atualizados/ignorados/erros;
- registrar auditoria;
- proteger contra CSV formula injection na exportação.

### Integração com conversas

Ao receber evento Evolution:

- localizar contato por identificadores;
- criar se não existir;
- vincular `conversation.contact_id`;
- atualizar última atividade;
- nunca remover opt-out por evento automático.

---

# PARTE 4 — chat operacional completo

## 9. Nova conversa

Implemente:

```text
POST /chatwoot_plugin/api/conversations
```

Request do front:

```json
{
  "instance_id": 1,
  "phone": "5511999999999",
  "name": "Maria",
  "message": "Olá"
}
```

Fluxo:

1. validar permissão e payload;
2. normalizar telefone/JID;
3. resolver instância ativa/conectada;
4. criar/atualizar contato;
5. criar/reusar conversa correta;
6. enviar mensagem pela Evolution;
7. persistir optimistic/client ID e external ID;
8. retornar conversa + mensagem;
9. auditar.

## 10. Anexos e mídia

Implemente:

```text
POST /chatwoot_plugin/api/conversations/{id}/attachments
```

Recebe `multipart/form-data` com:

```text
file
caption
client_message_id
```

### Tipos

- JPEG/PNG/WEBP;
- PDF;
- MP3/OGG/Opus/M4A/WAV/WEBM;
- MP4 quando suportado;
- outros apenas por allowlist configurável.

### Regras

- verificar MIME por conteúdo, não apenas extensão;
- limite configurável por tipo;
- nome aleatório/storage seguro;
- antivírus/hook opcional preparado;
- nunca executar arquivo;
- proteger contra path traversal e polyglot simples;
- escolher endpoint Evolution por tipo;
- converter áudio gravado em formato aceito quando necessário;
- persistir mídia e mensagem;
- usar client ID/idempotência;
- devolver objeto de mensagem normalizado;
- limpar arquivo temporário;
- retry seguro apenas quando idempotente.

## 11. Visualização de mídia recebida

Crie `Media_service` para:

- mídia com URL pública;
- base64;
- `directPath`/mídia criptografada;
- endpoint Evolution de base64;
- S3/MinIO se configurado;
- proxy server-side autenticado;
- URL temporária assinada quando apropriado.

Não envie `apikey` para o browser. Implemente proteção SSRF:

- schemes apenas HTTP/HTTPS;
- bloqueio de loopback, metadata IP, redes privadas, DNS rebinding e redirects inseguros, salvo hosts explicitamente allowlisted;
- timeout, limite de bytes e content type.

O front deve conseguir:

- abrir lightbox de imagem;
- tocar áudio;
- visualizar PDF;
- baixar documento autorizado.

## 12. Notas internas

Implemente:

```text
POST /chatwoot_plugin/api/conversations/{id}/notes
```

- persistir localmente;
- direction `internal` ou flag equivalente;
- mostrar no histórico apenas a usuários autorizados;
- não enviar ao WhatsApp;
- autor e timestamp obrigatórios;
- auditar.

## 13. Ações da conversa

Implemente:

```text
POST /conversations/{id}/priority
POST /conversations/{id}/resolve
POST /conversations/{id}/reopen
POST /conversations/{id}/tags
POST /conversations/{id}/assignment
```

Regras:

- prioridade persistida;
- resolução com autor/data;
- reabertura explícita;
- tags normalizadas sem duplicatas;
- `assign_to_me` usa usuário autenticado;
- `assignee_id` deve ser team member válido;
- ações auditadas;
- retorno inclui conversa atualizada.

## 14. Respostas rápidas

Implemente CRUD e listagem de respostas rápidas.

- atalhos únicos;
- ordem;
- ativo/inativo;
- escopo global/instância/equipe/usuário, ao menos preparado;
- front recebe `{id,title,text,shortcut,active}`.

## 15. Busca no histórico

O filtro local já funciona para mensagens carregadas. Acrescente endpoint/busca server-side opcional para históricos extensos, sem remover a busca local.

---

# PARTE 5 — campanhas conectadas ao n8n

## 16. N8n_client

Crie um client centralizado com:

- base URL segura;
- token criptografado;
- modos Bearer/header customizado;
- timeout;
- JSON e multipart quando necessário;
- retry/backoff apenas em operações idempotentes;
- correlation ID;
- logs sanitizados;
- normalização de erro;
- health check;
- proteção SSRF/origin binding semelhante à Evolution.

Nenhuma View ou JS chama n8n diretamente.

## 17. Adapter de campanhas

Use os fluxos existentes como fonte funcional. O adapter deve suportar:

```text
GET    /campanha
GET    /campanha/:id
POST   /campanha
PUT    /campanha/:id
DELETE /campanha/:id
POST   /campanha-stop/:id
```

Os paths precisam ser configuráveis porque o n8n pode usar prefixo `/webhook` ou `/webhook-test`.

Mapeie os nomes do front para o payload do fluxo real, incluindo quando aplicável:

```text
nome
descricao
account/inbox ou instância
lista_contato
dias_semana
horario_disparo
dt_inicio
dt_fim
fl_ativo
mensagem/mídia
```

Não acople controllers ao formato legado. Faça DTO/mapper versionado.

## 18. Rotas de campanhas

Implemente todas as rotas do contrato:

```text
GET/POST /api/campaigns
GET/PUT/DELETE /api/campaigns/{id}
POST /api/campaigns/{id}/duplicate
POST /api/campaigns/{id}/toggle
POST /api/campaigns/audience-preview
GET /api/campaigns/health
GET /api/campaign-templates
```

## 19. Público e opt-out

Antes de criar/iniciar campanha:

1. resolver contatos por tags/filtros/lista manual;
2. normalizar números;
3. remover duplicados;
4. remover opt-outs;
5. remover números inválidos;
6. validar instância;
7. produzir contagem/preview;
8. registrar snapshot/hashes suficientes para auditoria.

Nunca envie campanha para opt-out.

## 20. Persistência/caching

O n8n é o motor. O plugin mantém:

- configuração da campanha;
- external ID do n8n;
- status normalizado;
- última sincronização;
- métricas;
- correlation/idempotency key;
- erros sanitizados;
- destinatários/resultados quando disponível.

Implemente status:

```text
draft
scheduled
running
paused
completed
failed
cancelled
```

## 21. Duplicar, pausar e retomar

- duplicate cria novo draft sem reutilizar idempotency key;
- toggle chama stop/resume compatível com o fluxo;
- se o fluxo atual não possuir resume, documente e implemente criação/reativação segura, sem fingir sucesso;
- delete deve respeitar estado e regras do n8n.

## 22. Mídia de campanha

Prepare upload/storage e referência para o n8n. Não envie base64 gigante no browser. Use mídia pública temporária/armazenamento seguro conforme arquitetura instalada.

## 23. Templates

Implemente templates locais reutilizáveis com variáveis permitidas:

```text
{nome}
{telefone}
{empresa}
{cidade}
```

Variáveis desconhecidas devem ser rejeitadas ou preservadas com aviso configurável.

---

# PARTE 6 — IARA, agentes e automações

## 24. Princípio

A IA continua no n8n. O plugin é o painel operacional e a camada segura de controle.

Não replique LLM, memória ou RAG em PHP.

## 25. Estado da IA por conversa

Implemente:

```text
GET  /api/ai/state/{conversation_id}
POST /api/ai/state/{conversation_id}
POST /api/ai/state/{instance_id}/instance
GET  /api/ai/state/health
```

Estados:

```text
running
paused
human
handoff_pending
blocked
error
```

Request:

```json
{
  "status": "human",
  "reason": "human_takeover",
  "source": "rise_plugin"
}
```

### Compatibilidade operacional

- `@stop` pausa;
- `@start` retoma;
- envio humano tem prioridade;
- humano assumindo deve pausar/alterar o estado antes ou atomicamente com o envio;
- devolver para IA é explícito ou segue timeout configurado;
- registrar quem alterou;
- espelhar localmente o estado do n8n/Redis;
- não confiar em estado local antigo sem reconciliação.

## 26. Agentes

Implemente CRUD/toggle:

```text
GET/POST /api/ai/agents
GET/PUT/DELETE /api/ai/agents/{id}
POST /api/ai/agents/{id}/toggle
```

Campos lógicos:

- nome;
- descrição;
- instâncias;
- workflow ID/path;
- ativo;
- prioridade;
- política de handoff;
- horários;
- metadados não secretos;
- versionamento/config hash.

Segredos ficam em settings/credentials, não no registro exposto.

## 27. Automações

Implemente CRUD/toggle/test:

```text
GET/POST /api/automations
GET/PUT/DELETE /api/automations/{id}
POST /api/automations/{id}/toggle
POST /api/automations/{id}/test
```

Campos:

- nome;
- evento/gatilho;
- condições JSON validadas;
- ação/workflow;
- instância;
- delay;
- ativo;
- última execução/status.

O teste deve usar um payload seguro e nunca disparar campanha real sem confirmação/flag explícita.

## 28. Logs

Implemente `GET /api/ai/logs` com filtros e paginação:

```text
conversation_id
instance_id
agent_id
status
from/to
correlation_id
```

Retorne apenas dados sanitizados. Não exponha prompt secreto, API key ou cadeia interna desnecessária.

## 29. Resumo/contexto no contato lateral

O endpoint de estado deve fornecer, quando disponível:

```json
{
  "status": "running",
  "summary": "Lead perguntou sobre idade e localização.",
  "last_intent": "faq",
  "stage": "qualification",
  "handoff_required": false,
  "updated_at": "..."
}
```

---

# PARTE 7 — relatórios reais

## 30. Report_service

Calcule métricas locais com consultas eficientes:

- conversas abertas/resolvidas;
- mensagens recebidas/enviadas;
- não lidas;
- primeira resposta;
- tempo de resolução;
- atividade por instância;
- desempenho por agente;
- falha de envio;
- status de campanha;
- handoffs e resolução por IA, quando dados disponíveis;
- funil derivado de tags/status/configuração.

Não faça N+1.

## 31. Endpoints

```text
GET /api/reports
GET /api/reports/export
```

Filtros:

```text
period
instance_id
from
to
timezone
```

O JSON precisa preencher todos os elementos esperados por `hub-workspace.js`.

## 32. Exportação

- CSV UTF-8/BOM quando útil para Excel brasileiro;
- timezone correto;
- filtros respeitados;
- proteção contra formula injection (`=`, `+`, `-`, `@` no início);
- permissão separada;
- streaming para volume grande.

---

# PARTE 8 — notificações e auditoria

## 33. Notification_service

Crie notificações para:

- mensagem recebida em conversa não aberta;
- falha de envio;
- instância desconectada;
- campanha concluída/pausada/falha;
- handoff da IA;
- webhook com falhas repetidas.

Rotas:

```text
GET  /api/notifications
POST /api/notifications/{id}/read
POST /api/notifications/read-all
```

Notificações são por usuário quando aplicável. Contador precisa refletir não lidas.

## 34. Browser notifications

O front possui configuração. Backend deve fornecer preferências; o JS pode solicitar permissão somente após ação do usuário. Não disparar prompt automaticamente em carregamento.

## 35. Auditoria

Registre ações críticas:

- configuração/credencial alterada, sem valor secreto;
- instância criada/editada/desativada;
- mensagem enviada/retry/falha;
- contato alterado/merge/opt-out/import;
- conversa atribuída/resolvida/reaberta;
- campanha criada/iniciada/pausada/excluída;
- estado da IA alterado;
- exportação de dados.

Campos:

```text
actor_user_id
action
resource_type
resource_id
instance_id
correlation_id
ip/user_agent quando apropriado
before_json sanitizado
after_json sanitizado
created_at
```

---

# PARTE 9 — settings completos

## 36. Persistir todos os campos visíveis

O front envia todos os campos de `Views/partials/settings.php` e `Assets/js/chatwoot.js`.

Atualize validação, service e `public_settings()` para suportar:

### Geral

```text
module_name
timezone
polling_interval_ms
conversation_page_size
sound_enabled
browser_notifications_enabled
auto_mark_read
default_status
default_priority
sla_minutes
auto_resolve_hours
```

### Evolution

```text
evolution_base_url
global_api_key
request_timeout_seconds
evolution_retries
connection_status_path
find_chats_path
find_messages_path
send_text_path
send_media_path
send_audio_path
```

### n8n

```text
n8n_base_url
n8n_token
n8n_auth_mode
n8n_timeout_seconds
n8n_health_path
n8n_campaigns_path
n8n_ai_path
n8n_events_path
```

### Campanhas

```text
campaign_window_start
campaign_window_end
campaign_batch_size
campaign_min_interval_seconds
campaign_pause_after_errors
campaign_optout_text
quick_replies_json
```

### IA

```text
ai_default_state
ai_human_priority
ai_show_context
ai_stop_command
ai_start_command
ai_auto_return_minutes
```

### Segurança/retenção

```text
webhook_secret
log_sanitized_webhooks
webhook_retention_days
audit_enabled
audit_retention_days
conversation_retention_days
media_retention_days
secure_media
```

## 37. Segredos

- vazio = manter;
- remoção exige `clear_*` explícito;
- criptografados em repouso;
- máscara em `public_settings`;
- nunca devolver plain text;
- vincular credencial à origem para impedir vazamento em troca de host;
- rotação e teste seguros.

## 38. Teste n8n

Implemente:

```text
POST /api/integrations/n8n/test
```

Retorne conexão, latência e mensagem normalizada.

---

# PARTE 10 — permissões e segurança

## 39. Permissões

Expanda a integração com cargos do Rise:

```text
access
send_messages
manage_conversations
manage_contacts
manage_instances
manage_campaigns
manage_ai
view_reports
export_reports
manage_settings
view_audit_logs
```

Regras:

- admin possui tudo;
- menu/tabs respeitam permissão;
- endpoint sempre valida server-side;
- não confiar apenas em botão oculto;
- `send_messages` não implica settings/campaigns;
- exportação e auditoria separadas.

## 40. CSRF e autenticação

- todas as rotas autenticadas de escrita usam CSRF;
- GET não altera estado;
- sincronizações permanecem POST;
- webhook público fora do CSRF, com segredo/HMAC;
- status HTTP corretos: 400, 401, 403, 404, 409, 422, 429, 502/503;
- respostas não vazam stack trace.

## 41. Webhook

Aceitar:

- `X-Chatwoot-Webhook-Secret`/header configurado;
- Bearer compatível;
- HMAC-SHA256 sobre raw body.

Adicionar:

- rate limit;
- tamanho máximo;
- dedupe;
- retryable result claro;
- estratégia de reprocessamento de eventos pendentes via job/cron;
- logs sanitizados;
- HTTP adequado para o emissor configurado.

Não retornar HTTP 200 indiscriminadamente quando o emissor depende do status HTTP para retry, salvo modo de compatibilidade configurável.

## 42. Jobs/cron

Implemente jobs seguros para:

- reprocessar webhooks pendentes;
- sincronizar status de instâncias;
- reconciliar campanhas;
- limpar logs/mídia conforme retenção;
- reconciliar mensagens otimistas;
- gerar notificações de falha persistente.

Use lock para impedir execução concorrente. Documente comando/cron do Rise.

## 43. SSRF e uploads

Aplicar proteção a:

- Evolution base URL;
- n8n base URL;
- media proxy;
- redirects;
- DNS/IP privado/metadata;
- credential origin binding.

Upload com MIME real, allowlist e limites.

---

# PARTE 11 — controllers, services e organização

## 44. Controllers esperados

Crie controllers separados e finos, por exemplo:

```text
Contacts.php
Campaigns.php
Campaign_templates.php
Ai_agents.php
Ai_state.php
Ai_logs.php
Automations.php
Reports.php
Notifications.php
Quick_replies.php
Integrations.php
Media.php
```

Controllers:

- validam permissão;
- coletam/validam request;
- chamam service;
- retornam JSON;
- não contêm SQL nem HTTP externo.

## 45. Services esperados

```text
Contact_service
Conversation_action_service
Media_service
Campaign_service
Ai_service
Automation_service
Report_service
Notification_service
Audit_service
Integration_job_service
N8n_client/N8n_service
```

Reaproveite helpers existentes e evite classes gigantes. Refatore `Chat_service` somente quando necessário e mantendo testes.

## 46. Rotas

Registre todas as rotas do `BACKEND_CONTRACT_REFINED.md`.

Evite conflito de rotas dinâmicas, especialmente:

```text
/campaigns/health
/campaigns/audience-preview
/campaigns/{id}
/ai/state/health
/ai/state/{id}
```

Rotas específicas devem vir antes das dinâmicas.

---

# PARTE 12 — carregamento de dados nas páginas

## 47. Controller principal

Atualize `Controllers/Chatwoot.php` para carregar dados reais de cada aba:

- dashboard: resumo real;
- contacts: primeira página real;
- campaigns: primeira página/cache real;
- AI: agentes, automações e estado real;
- reports: indicadores iniciais reais;
- settings: todos os settings públicos;
- notificações: contador real.

Falha de um módulo externo não pode derrubar todas as abas. Use tratamento por módulo e estados de erro específicos.

## 48. Sem mocks

Quando não houver registros, retorne array vazio e use os empty states existentes.

Não invente:

- volume;
- taxa de entrega;
- agentes ativos;
- campanhas;
- contatos;
- relatórios;
- latência;
- status de conexão.

---

# PARTE 13 — testes obrigatórios e gates

## 49. Preserve e amplie a suíte

Os testes existentes devem continuar passando.

Adicione testes para cada módulo.

### Gate A — sintaxe

- `php -l` em todos os PHP;
- `node --check` nos dois JS.

### Gate B — migrations

- banco limpo;
- banco com V001;
- segunda execução idempotente;
- índices, engine e collation;
- dados preservados.

### Gate C — Evolution

- envio texto;
- nova conversa;
- mídia por tipo;
- status;
- webhook normalizado;
- payload IARA;
- LID/remoteJidAlt;
- grupos `@g.us`;
- evento atrasado;
- retry/idempotência.

### Gate D — contatos

- CRUD;
- dedupe;
- merge seguro;
- tags;
- opt-out;
- import/export;
- vínculo com conversa.

### Gate E — campanhas

- health n8n;
- adapter payload/response;
- CRUD;
- preview;
- opt-out;
- duplicate/toggle/delete;
- timeout/retry;
- idempotência;
- status reconciliation.

### Gate F — IA

- agentes CRUD/toggle;
- automações CRUD/toggle/test;
- state GET/POST;
- `@stop`/`@start`;
- humano;
- estado por instância;
- logs sanitizados.

### Gate G — reports/notifications

- filtros;
- métricas;
- export seguro;
- mark read/read all;
- geração de notificações.

### Gate H — segurança

- permissões por endpoint;
- CSRF;
- webhook auth/HMAC;
- segredo mascarado;
- SSRF;
- upload inválido;
- CSV formula injection;
- rate limit;
- logs sem segredo.

### Gate I — HTTP/integração

- 401 sem sessão;
- 403 sem permissão;
- 422 em payload inválido;
- 409 em conflito/idempotência;
- 502/503 em provedor externo;
- nenhuma rota requerida retorna 404/405.

## 50. Testes reais

Se Evolution/n8n reais não estiverem acessíveis:

- use fake clients injetáveis;
- fixtures representativas;
- teste HTTP local do plugin;
- forneça checklist de smoke test.

Não marque o smoke test externo como executado se não foi.

---

# PARTE 14 — documentação e entrega

## 51. Atualizar documentação

Atualize/crie:

```text
EVOLUTION_INTEGRATION.md
N8N_INTEGRATION.md
OPERATIONS.md
TESTING.md
CHANGELOG.md
```

Documente:

- instalação/upgrade;
- migrations;
- permissões;
- configurações;
- endpoints;
- payloads;
- webhook Evolution;
- campanhas n8n;
- estado da IARA;
- mídia;
- cron/jobs;
- troubleshooting;
- limitações reais;
- smoke test com duas instâncias.

Não inclua credenciais reais.

## 52. Relatório final obrigatório

Ao concluir, informe:

1. resumo por módulo;
2. arquivos criados/alterados;
3. migrations e schema;
4. rotas implementadas;
5. testes executados com números de aprovados/falhas;
6. o que foi testado apenas com fake;
7. o que exige credencial real;
8. passos exatos para configurar Evolution e n8n;
9. payload do webhook;
10. riscos/limitações restantes.

## 53. Pacote

Entregue o plugin completo, não apenas patches ou trechos.

Não inclua:

- `vendor` desnecessário;
- credenciais;
- dumps de banco;
- logs com PII;
- arquivos temporários;
- código do `chatwoot-main`.

---

# Critérios de aceite funcionais finais

A tarefa só pode ser considerada completa quando todos os itens abaixo forem verdadeiros:

## Conversas

- múltiplas instâncias filtram corretamente;
- busca funciona;
- paginação funciona;
- abrir conversa carrega histórico;
- polling não perde seleção/scroll;
- texto envia/recebe;
- emoji insere corretamente;
- resposta rápida funciona;
- imagem abre no viewer;
- áudio toca e abre no viewer;
- PDF/documento abre/baixa com segurança;
- anexo envia;
- áudio do navegador grava e envia;
- nota interna persiste;
- prioridade funciona;
- resolver/reabrir funciona;
- tags funcionam;
- atribuição funciona;
- nova conversa funciona;
- retry funciona;
- status sent/delivered/read funciona.

## Contatos

- busca e filtros reais;
- CRUD;
- tags;
- opt-out;
- importação;
- exportação;
- ações em massa;
- paginação;
- vínculo com conversas.

## Instâncias

- CRUD;
- teste/status;
- várias instâncias;
- chaves seguras;
- estados reais;
- sem vazamento ao trocar host.

## Campanhas

- lista real;
- busca/filtros;
- criar/editar;
- preview de público;
- agendar/iniciar;
- pausar/retomar;
- duplicar;
- excluir;
- templates;
- status/métricas reais;
- opt-out obrigatório;
- n8n testável.

## IA/automações

- agentes CRUD/toggle;
- automações CRUD/toggle/test;
- estado por conversa;
- estado por instância;
- humano/pausar/retomar;
- contexto/resumo;
- logs;
- health n8n.

## Relatórios

- filtros;
- dados reais;
- gráficos preenchidos;
- exportação;
- permissões.

## Configurações

- todos os campos persistem;
- segredos mascarados;
- teste Evolution;
- teste n8n;
- respostas rápidas;
- retenção/auditoria.

## Qualidade

- plugin instala e atualiza;
- nenhum mock operacional;
- nenhum botão morto;
- nenhuma rota esperada 404/405;
- nenhum segredo no browser/log;
- tema Rise preservado;
- responsividade preservada;
- testes passam;
- chat Evolution existente não regrediu.

Comece agora pela auditoria do estado real e prossiga até a entrega completa. Não responda apenas com um plano.
