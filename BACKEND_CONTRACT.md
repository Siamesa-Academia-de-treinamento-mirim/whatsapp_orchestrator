# Contrato de backend — Impulso Hub Atendimento

> **Documento histórico.** O MVP implementado em julho de 2026 segue o escopo funcional mais recente e o contrato executável descrito em [`EVOLUTION_INTEGRATION.md`](EVOLUTION_INTEGRATION.md). Rotas e tabelas previstas abaixo que não aparecem nesse documento atual permanecem fora do escopo.

Este documento orienta a implementação do backend nativo em PHP/CodeIgniter 4 dentro do plugin Rise.

## Regra central

O front já está concluído como referência. O backend deve substituir dados simulados e ações locais sem alterar o layout, exceto quando uma necessidade técnica real exigir ajuste pontual. O tema visual é controlado pelo Rise; o backend não deve adicionar CSS global, detectar tema por JavaScript ou fixar fundos e cores de controles nativos.

## Módulos previstos

### Conversas

- `GET /chatwoot_plugin/api/conversations?instance_id={id}&status={status}&search={texto}`
- `GET /chatwoot_plugin/api/conversations/{id}`
- `GET /chatwoot_plugin/api/conversations/{id}/messages`
- `POST /chatwoot_plugin/api/conversations`
- `POST /chatwoot_plugin/api/conversations/{id}/messages`
- `POST /chatwoot_plugin/api/conversations/{id}/notes`
- `POST /chatwoot_plugin/api/conversations/{id}/assign`
- `POST /chatwoot_plugin/api/conversations/{id}/priority`
- `POST /chatwoot_plugin/api/conversations/{id}/resolve`
- `POST /chatwoot_plugin/api/conversations/{id}/reopen`
- `POST /chatwoot_plugin/api/conversations/{id}/tags`
- `POST /chatwoot_plugin/api/conversations/{id}/attachments`

Resposta esperada de listagem:

```json
{
  "success": true,
  "data": [
    {
      "id": 1048,
      "contact": {
        "id": 31,
        "name": "Mariana Souza",
        "phone": "+5511988142290",
        "avatar_url": null
      },
      "instance": {"id": 1, "name": "SIAMESA SBC"},
      "status": "open",
      "priority": "high",
      "unread_count": 3,
      "last_message": "Mensagem",
      "last_activity_at": "2026-07-15T10:42:00-03:00",
      "assignee": {"id": 9, "name": "Camila"},
      "team": {"id": 2, "name": "Comercial"},
      "tags": ["Novo lead", "SBC"]
    }
  ],
  "meta": {"page": 1, "total": 38, "has_more": false}
}
```

Mensagem esperada:

```json
{
  "id": 9876,
  "conversation_id": 1048,
  "direction": "outgoing",
  "content_type": "text",
  "content": "Olá!",
  "status": "sent",
  "external_id": "evolution-message-id",
  "sender": {"type": "agent", "id": 9, "name": "Camila"},
  "created_at": "2026-07-15T10:43:00-03:00",
  "attachments": []
}
```

### Contatos

- `GET /chatwoot_plugin/api/contacts`
- `GET /chatwoot_plugin/api/contacts/{id}`
- `POST /chatwoot_plugin/api/contacts`
- `PUT /chatwoot_plugin/api/contacts/{id}`
- `POST /chatwoot_plugin/api/contacts/import`
- `POST /chatwoot_plugin/api/contacts/{id}/opt-out`

### Operação multi-instância

A caixa de entrada possui um trilho lateral de canais. Cada canal representa uma instância Evolution e deve ser tratado como filtro operacional real, não apenas como etiqueta visual.

Requisitos mínimos:

- Uma conversa pertence obrigatoriamente a uma instância.
- O endpoint de conversas deve aceitar `instance_id`, além dos filtros de status, responsável e busca.
- Contadores por instância devem incluir total de conversas abertas e total não lido.
- O envio de uma mensagem deve usar a instância vinculada à conversa; o front nunca deverá escolher uma instância diferente silenciosamente.
- Ao iniciar nova conversa, `instance_id` é obrigatório.
- Instâncias desconectadas aparecem no trilho, mas não podem enviar mensagens.
- O backend deve retornar estado de conexão: `connected`, `attention` ou `disconnected`.
- Usuários podem ter permissão para visualizar apenas determinadas instâncias.

Resposta resumida esperada para os canais:

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "SIAMESA SBC",
      "phone": "+5511944441208",
      "status": "connected",
      "open_conversations": 18,
      "unread_count": 6
    }
  ]
}
```

### Instâncias Evolution

- `GET /chatwoot_plugin/api/instances`
- `POST /chatwoot_plugin/api/instances`
- `GET /chatwoot_plugin/api/instances/{id}`
- `PUT /chatwoot_plugin/api/instances/{id}`
- `POST /chatwoot_plugin/api/instances/{id}/connect`
- `GET /chatwoot_plugin/api/instances/{id}/qr-code`
- `POST /chatwoot_plugin/api/instances/{id}/restart`
- `POST /chatwoot_plugin/api/instances/{id}/disconnect`
- `POST /chatwoot_plugin/api/instances/{id}/test-message`
- `GET /chatwoot_plugin/api/instances/{id}/health`

### Webhooks Evolution

- `POST /chatwoot_plugin/webhooks/evolution`

Eventos mínimos:

- `messages.upsert`
- `messages.update`
- `connection.update`
- `contacts.update`
- `qrcode.updated`
- `send.message`

Requisitos:

- Validar assinatura ou segredo.
- Persistir `external_event_id` para deduplicação.
- Responder rapidamente e delegar processamento pesado para fila.
- Registrar payload mascarado e resultado.
- Nunca criar duas mensagens para o mesmo `external_message_id`.

### Campanhas

- `GET /chatwoot_plugin/api/campaigns`
- `POST /chatwoot_plugin/api/campaigns`
- `GET /chatwoot_plugin/api/campaigns/{id}`
- `PUT /chatwoot_plugin/api/campaigns/{id}`
- `POST /chatwoot_plugin/api/campaigns/{id}/schedule`
- `POST /chatwoot_plugin/api/campaigns/{id}/pause`
- `POST /chatwoot_plugin/api/campaigns/{id}/resume`
- `POST /chatwoot_plugin/api/campaigns/{id}/cancel`
- `GET /chatwoot_plugin/api/campaigns/{id}/recipients`
- `GET /chatwoot_plugin/api/campaigns/{id}/report`

Requisitos:

- Opt-in e opt-out.
- Janela de envio.
- Limite por instância.
- Intervalo variável configurável.
- Pausa por falha sistêmica.
- Idempotência por campanha e destinatário.
- Estados: `draft`, `scheduled`, `queued`, `running`, `paused`, `completed`, `cancelled`, `failed`.

### IA e automações

- `GET /chatwoot_plugin/api/ai-agents`
- `POST /chatwoot_plugin/api/ai-agents`
- `PUT /chatwoot_plugin/api/ai-agents/{id}`
- `POST /chatwoot_plugin/api/ai-agents/{id}/activate`
- `POST /chatwoot_plugin/api/ai-agents/{id}/pause`
- `POST /chatwoot_plugin/api/ai-agents/{id}/test`
- `GET /chatwoot_plugin/api/automations`
- `POST /chatwoot_plugin/api/automations`
- `PUT /chatwoot_plugin/api/automations/{id}`

Requisitos:

- Prompt por agente.
- Modelo e provedor configuráveis.
- Base de conhecimento.
- Contexto limitado e resumido.
- Confiança mínima.
- Passagem para humano.
- Horários de atuação.
- Limite de custo.
- Auditoria das decisões e ferramentas executadas.

### Relatórios

- `GET /chatwoot_plugin/api/reports/overview`
- `GET /chatwoot_plugin/api/reports/agents`
- `GET /chatwoot_plugin/api/reports/instances`
- `GET /chatwoot_plugin/api/reports/campaigns`
- `GET /chatwoot_plugin/api/reports/ai`

### Configurações

- `GET /chatwoot_plugin/api/settings`
- `PUT /chatwoot_plugin/api/settings`
- `POST /chatwoot_plugin/api/settings/test-evolution`
- `POST /chatwoot_plugin/api/settings/test-ai`

Credenciais devem ser criptografadas em repouso e nunca devolvidas integralmente ao front.

## Tabelas sugeridas

```text
rise_chatwoot_settings
rise_chatwoot_instances
rise_chatwoot_teams
rise_chatwoot_contacts
rise_chatwoot_contact_tags
rise_chatwoot_conversations
rise_chatwoot_conversation_tags
rise_chatwoot_messages
rise_chatwoot_message_attachments
rise_chatwoot_assignments
rise_chatwoot_webhook_events
rise_chatwoot_campaigns
rise_chatwoot_campaign_recipients
rise_chatwoot_campaign_jobs
rise_chatwoot_ai_agents
rise_chatwoot_ai_runs
rise_chatwoot_automations
rise_chatwoot_audit_logs
```

## Ordem recomendada ao Codex

1. Migrations, permissões e configurações.
2. Instâncias Evolution e teste de conexão.
3. Webhook de mensagens com deduplicação.
4. Conversas, mensagens e envio de texto.
5. Atualização em tempo real ou polling.
6. Anexos, áudio e status de entrega/leitura.
7. Contatos, etiquetas, equipes e atribuição.
8. Campanhas, filas e cron.
9. IA e passagem para humano.
10. Relatórios, testes e estabilização.
