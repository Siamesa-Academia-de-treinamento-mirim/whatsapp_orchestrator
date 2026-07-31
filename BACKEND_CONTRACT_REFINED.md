# Impulso Hub — contrato de backend do refinamento total

## 1. Objetivo

Este documento é o contrato entre o front refinado do plugin `Chatwoot_plugin` e o backend a ser concluído no Rise CRM.

O chat de texto conectado à Evolution API já funciona e é a base estável. O backend novo deve completar as funções operacionais sem reescrever o front, sem substituir a integração existente e sem transformar o Rise em uma cópia integral do Chatwoot.

Responsabilidades:

- **Evolution API:** conexão dos números, histórico WhatsApp, envio e recebimento de mensagens e mídia.
- **n8n:** campanhas, IARA/IA, automações, memória e regras de negócio externas.
- **Plugin Rise:** front operacional, permissões, persistência local, cache, contatos, estados humanos, auditoria, proxy seguro e integração com Evolution/n8n.

## 2. Regras invariáveis

1. Preservar os IDs, classes, formulários, modais e contratos de `Views/` e `Assets/js/`.
2. Não quebrar envio e recebimento de texto já existentes.
3. Não expor API keys, tokens, segredos ou payloads não sanitizados ao navegador.
4. Toda chamada externa passa por clients/services server-side.
5. Toda escrita autenticada usa permissões do Rise e CSRF.
6. O webhook público usa segredo/HMAC e continua excluído do CSRF.
7. Operações repetíveis devem ser idempotentes.
8. Nenhum botão visível pode terminar em placeholder, `404`, `405`, mock ou mensagem “fora do MVP”.
9. Falhas da Evolution ou do n8n não podem quebrar a página inteira.
10. O front deve receber JSON previsível no formato `{success, data, meta?, message?}`.

## 3. Rotas existentes que devem permanecer

```text
GET    /chatwoot_plugin/api/session/csrf

GET    /chatwoot_plugin/api/instances
POST   /chatwoot_plugin/api/instances
GET    /chatwoot_plugin/api/instances/{id}
POST   /chatwoot_plugin/api/instances/{id}
DELETE /chatwoot_plugin/api/instances/{id}
POST   /chatwoot_plugin/api/instances/{id}/status
POST   /chatwoot_plugin/api/instances/refresh-status

GET    /chatwoot_plugin/api/conversations
POST   /chatwoot_plugin/api/conversations/sync
GET    /chatwoot_plugin/api/conversations/{id}/messages
POST   /chatwoot_plugin/api/conversations/{id}/messages/sync
POST   /chatwoot_plugin/api/conversations/{id}/messages
POST   /chatwoot_plugin/api/conversations/{id}/read

GET    /chatwoot_plugin/api/settings
POST   /chatwoot_plugin/api/settings
POST   /chatwoot_plugin/api/settings/test

POST   /chatwoot_plugin/webhooks/evolution
```

## 4. Rotas novas exigidas pelo front

### 4.1 Conversas e composer

```text
POST /chatwoot_plugin/api/conversations
POST /chatwoot_plugin/api/conversations/{id}/attachments
POST /chatwoot_plugin/api/conversations/{id}/notes
POST /chatwoot_plugin/api/conversations/{id}/priority
POST /chatwoot_plugin/api/conversations/{id}/resolve
POST /chatwoot_plugin/api/conversations/{id}/reopen
POST /chatwoot_plugin/api/conversations/{id}/tags
POST /chatwoot_plugin/api/conversations/{id}/assignment
```

#### Nova conversa

Request:

```json
{
  "instance_id": 1,
  "phone": "5511999999999",
  "name": "Contato opcional",
  "message": "Olá"
}
```

Regras:

- normalizar telefone;
- resolver o JID correto;
- criar/atualizar contato e conversa;
- enviar a primeira mensagem pela Evolution;
- usar `client_message_id` e deduplicação;
- retornar conversa e mensagem criadas.

#### Anexo

`multipart/form-data`:

```text
file=<arquivo>
caption=<texto opcional>
client_message_id=<uuid/string>
```

Aceitar inicialmente:

- imagem: JPEG, PNG, WEBP;
- áudio: OGG/Opus, MP3, M4A, WAV, WEBM;
- documento: PDF e formatos explicitamente permitidos;
- vídeo: MP4, quando suportado pela versão da Evolution.

Limites e tipos devem ser configuráveis. Arquivos não podem ser executáveis. O backend deve selecionar `sendMedia`, `sendWhatsAppAudio` ou endpoint equivalente centralizado no `Evolution_client`.

#### Nota interna

Request:

```json
{"content":"Texto visível apenas no Rise"}
```

A nota não é enviada à Evolution/n8n como mensagem WhatsApp. Retorna uma mensagem local com `message_type=note` e `direction=internal`.

#### Prioridade/status/tags/atribuição

- `priority`: `{ "priority": true }` ou nível configurável;
- `resolve` e `reopen`: atualizar status local e auditoria;
- `tags`: `{ "tags": ["lead", "urgente"] }`;
- `assignment`: `{ "assignee_id": 123 }` ou `{ "assign_to_me": true }`.

### 4.2 Contatos

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

Lista aceita:

```text
q, instance_id, status, tag, page, limit, cursor
```

Contato normalizado:

```json
{
  "id": 10,
  "name": "Maria",
  "phone": "5511999999999",
  "email": "",
  "company": "",
  "city": "",
  "source": "whatsapp",
  "instance_id": 1,
  "tags": ["lead"],
  "notes": "",
  "opt_out": false,
  "last_activity_at": "2026-07-16T12:00:00Z",
  "conversation_count": 2
}
```

Importação CSV:

- validar encoding, tamanho e cabeçalho;
- oferecer dry-run/preview no service, mesmo que a primeira UI use processamento direto;
- deduplicar por telefone normalizado + escopo definido;
- produzir contagem de inseridos, atualizados, ignorados e erros;
- não sobrescrever opt-out silenciosamente.

### 4.3 Respostas rápidas

```text
GET    /chatwoot_plugin/api/quick-replies
POST   /chatwoot_plugin/api/quick-replies
PUT    /chatwoot_plugin/api/quick-replies/{id}
DELETE /chatwoot_plugin/api/quick-replies/{id}
```

Resposta mínima:

```json
[
  {"id":1,"title":"Saudação","text":"Olá! Tudo bem?","shortcut":"/ola","active":true}
]
```

### 4.4 Campanhas — fachada do n8n

```text
GET    /chatwoot_plugin/api/campaigns
POST   /chatwoot_plugin/api/campaigns
GET    /chatwoot_plugin/api/campaigns/{id}
PUT    /chatwoot_plugin/api/campaigns/{id}
DELETE /chatwoot_plugin/api/campaigns/{id}
POST   /chatwoot_plugin/api/campaigns/{id}/duplicate
POST   /chatwoot_plugin/api/campaigns/{id}/toggle
POST   /chatwoot_plugin/api/campaigns/audience-preview
GET    /chatwoot_plugin/api/campaigns/health
GET    /chatwoot_plugin/api/campaign-templates
```

O adapter n8n deve suportar os endpoints existentes do fluxo da SIAMESA:

```text
GET    /campanha
GET    /campanha/:id
POST   /campanha
PUT    /campanha/:id
DELETE /campanha/:id
POST   /campanha-stop/:id
```

O browser nunca chama o n8n diretamente. O plugin:

1. valida o request;
2. resolve instância/público;
3. remove opt-outs e duplicados;
4. gera correlation/idempotency key;
5. chama o n8n server-side;
6. normaliza a resposta;
7. grava cache/log local;
8. retorna resposta estável ao front.

Payload lógico do front:

```json
{
  "id": null,
  "name": "Repique de julho",
  "description": "",
  "instance_id": 1,
  "audience_source": "contacts",
  "include_tags": ["lead"],
  "exclude_tags": ["matriculado"],
  "manual_numbers": [],
  "message": "Olá, {nome}!",
  "schedule_type": "scheduled",
  "schedule_at": "2026-07-20T14:00:00-03:00",
  "days_of_week": [1,2,3,4,5],
  "start_immediately": false,
  "media_id": null
}
```

Status normalizados:

```text
draft, scheduled, running, paused, completed, failed, cancelled
```

### 4.5 IA e automações — fachada do n8n

```text
GET    /chatwoot_plugin/api/ai/agents
POST   /chatwoot_plugin/api/ai/agents
GET    /chatwoot_plugin/api/ai/agents/{id}
PUT    /chatwoot_plugin/api/ai/agents/{id}
DELETE /chatwoot_plugin/api/ai/agents/{id}
POST   /chatwoot_plugin/api/ai/agents/{id}/toggle

GET    /chatwoot_plugin/api/automations
POST   /chatwoot_plugin/api/automations
GET    /chatwoot_plugin/api/automations/{id}
PUT    /chatwoot_plugin/api/automations/{id}
DELETE /chatwoot_plugin/api/automations/{id}
POST   /chatwoot_plugin/api/automations/{id}/toggle
POST   /chatwoot_plugin/api/automations/{id}/test

GET    /chatwoot_plugin/api/ai/state/{conversation_id}
POST   /chatwoot_plugin/api/ai/state/{conversation_id}
POST   /chatwoot_plugin/api/ai/state/{instance_id}/instance
GET    /chatwoot_plugin/api/ai/state/health
GET    /chatwoot_plugin/api/ai/logs
```

Estados normalizados:

```text
running, paused, human, handoff_pending, blocked, error
```

Compatibilidade obrigatória com os fluxos atuais:

- `@stop` pausa o bot;
- `@start` retoma;
- mensagem enviada por humano tem prioridade;
- estado pode viver no Redis/n8n e ser espelhado localmente;
- o front não chama Redis diretamente;
- toda alteração registra autor, origem, correlation ID e resultado.

Request de estado:

```json
{
  "status": "paused",
  "reason": "human_takeover",
  "source": "rise_plugin"
}
```

### 4.6 n8n

```text
POST /chatwoot_plugin/api/integrations/n8n/test
```

Resposta:

```json
{
  "connected": true,
  "latency_ms": 82,
  "version": "opcional",
  "message": "Conexão confirmada"
}
```

Configurações server-side:

- base URL;
- modo de autenticação: Bearer, header customizado, basic auth somente se explicitamente habilitado;
- token criptografado;
- timeout;
- health path;
- campaigns path;
- AI path;
- events path;
- retry com backoff para operações seguras.

### 4.7 Relatórios

```text
GET /chatwoot_plugin/api/reports
GET /chatwoot_plugin/api/reports/export
```

Filtros:

```text
period=24h|7d|30d|custom
instance_id=all|ID
from, to, timezone
```

Resposta deve preencher:

```json
{
  "summary": {
    "conversations": 0,
    "messages_in": 0,
    "messages_out": 0,
    "avg_first_response_seconds": 0,
    "resolution_rate": 0,
    "ai_resolution_rate": 0
  },
  "volume": [{"label":"10/07","value":0}],
  "channels": [],
  "agents": [],
  "ai": {},
  "funnel": {}
}
```

CSV exportado deve respeitar permissões, timezone, filtros e sanitização contra formula injection.

### 4.8 Notificações

```text
GET  /chatwoot_plugin/api/notifications
POST /chatwoot_plugin/api/notifications/{id}/read
POST /chatwoot_plugin/api/notifications/read-all
```

Eventos mínimos:

- nova mensagem em conversa não aberta;
- falha de envio;
- instância desconectada;
- campanha pausada/falha/concluída;
- handoff da IA;
- falha recorrente de webhook.

### 4.9 Configurações ampliadas

O endpoint existente de settings deve aceitar, validar e persistir os campos enviados por `Assets/js/chatwoot.js`, incluindo:

- experiência e paginação;
- Evolution e paths de mídia/áudio;
- n8n e credencial criptografada;
- regras de campanha;
- respostas rápidas em JSON ou tabela dedicada;
- política da IA;
- webhook/auditoria/retenção;
- mídia segura.

Campos secretos vazios significam **manter valor atual**, nunca apagar. Limpeza de segredo exige flag explícita.

## 5. Persistência nova

Criar migrations incrementais, sem editar destrutivamente a V001 já instalada.

Tabelas recomendadas:

```text
chat_contacts
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

Alterações recomendadas em `chat_conversations`:

```text
contact_id, priority, assignee_id, team_id,
resolved_at, resolved_by, ai_status, ai_summary,
last_human_message_at, last_bot_message_at
```

Regras:

- usar prefixo do Rise;
- InnoDB, utf8mb4 e índices seletivos;
- soft delete conforme padrão Rise;
- migrations idempotentes;
- foreign keys apenas se compatíveis com o padrão do projeto; caso contrário, integridade por service + índices;
- índices únicos para idempotência externa.

## 6. Normalização Evolution e n8n

O webhook deve aceitar tanto o formato normalizado quanto os campos reais da IARA/Evolution:

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

Regra `@lid`:

- preservar o JID LID como identificador alternativo quando necessário;
- usar `remoteJidAlt`, `remote_jid_alt`, `body_remoteJid` ou `number` para o telefone real;
- nunca tratar os dígitos do `@lid` como número de destino;
- manter mapeamento LID ↔ telefone/JID estável.

Eventos:

```text
messages.upsert
messages.update
connection.update
chats.upsert
contacts.upsert
send.message
```

Status de mensagem devem avançar monotonicamente:

```text
pending < sent < delivered < read
```

Eventos atrasados não podem regredir status ou última atividade.

## 7. Mídia

O front precisa:

- exibir imagem em lightbox;
- tocar áudio;
- abrir PDF/documento;
- gravar áudio no navegador;
- anexar arquivo.

O backend precisa:

- resolver URL pública, base64 ou `directPath`;
- usar proxy autenticado quando a URL exigir credencial;
- impedir SSRF;
- limitar tamanho/tempo;
- verificar MIME real;
- sanitizar nome;
- suportar retenção;
- não expor header `apikey` em URL do browser;
- preferir S3/MinIO quando configurado;
- documentar formatos não suportados na versão instalada.

## 8. Permissões

Permissões mínimas:

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

Admin tem todas. Demais cargos recebem somente o que foi marcado no Rise.

## 9. Segurança

- CSRF em todas as rotas autenticadas de escrita;
- webhook excluído do CSRF, mas autenticado;
- rate limit por segredo/IP/instância para webhook;
- segredo com comparação constante;
- HMAC sobre raw body;
- consultas parametrizadas;
- escape de saída;
- upload seguro;
- proteção SSRF em base URLs e mídia;
- logs sem segredos, base64 integral ou PII desnecessária;
- criptografia em repouso para tokens;
- auditoria de ações críticas;
- idempotency keys em envio/campanha/webhook;
- lock por conversa/campanha quando houver corrida.

## 10. Testes obrigatórios

1. PHP lint de todos os arquivos.
2. JavaScript syntax check.
3. Migrations em banco limpo e banco com V001.
4. Models e paginação.
5. Normalização dos payloads IARA, Evolution e `@lid`.
6. Envio texto e mídia com clients simulados.
7. Nova conversa.
8. Nota interna.
9. CRUD e importação de contatos.
10. Campanha CRUD/adapter/idempotência/opt-out.
11. IA `@stop`, `@start`, humano e estado por instância.
12. Webhook HMAC/Bearer/segredo/deduplicação/eventos fora de ordem.
13. Permissões e CSRF.
14. Relatórios e exportação segura.
15. Falhas externas, timeout, retry e circuit breaker.
16. Rotas sem placeholders.

## 11. Critério final

A entrega só está concluída quando:

- todos os botões visíveis têm comportamento funcional;
- todas as buscas e filtros funcionam;
- chat mantém texto e adiciona mídia, nota, emoji, áudio e viewer;
- contatos são reais;
- campanhas chamam o n8n real por adapter;
- IA/automações chamam o n8n real por adapter;
- relatórios usam dados reais;
- não há mocks operacionais, controles desabilitados sem motivo ou mensagens de “backend pendente”;
- Evolution send/receive continua funcionando;
- testes e documentação foram atualizados.
