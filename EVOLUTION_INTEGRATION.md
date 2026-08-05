# Evolution API

## Canal

Cadastre o provedor `Evolution`, o nome exato da instância, URL base, API key e número conectado. Configuração específica do canal tem precedência sobre a global. A chave global não é encaminhada para uma origem diferente.

## Endpoints configuráveis

O cliente centraliza conexão, chats, mensagens, texto, mídia, áudio e recuperação de base64. Ajuste templates de endpoint em **Configurações > Canais** caso a versão instalada use rotas diferentes; não altere views ou controllers.

## Webhook

```text
POST https://SEU-RISE/chatwoot_plugin/webhooks/evolution
```

Autentique com:

```http
X-Chatwoot-Webhook-Secret: SEGREDO
```

ou Bearer, ou `X-Chatwoot-Webhook-Signature: sha256=HMAC_DO_CORPO`.

Eventos recomendados: mensagens novas/atualizadas, conexão, chats, contatos e grupos. O normalizador aceita envelopes comuns da Evolution v2 e o formato normalizado legado.

## Grupos

O JID do grupo identifica a conversa. O JID/telefone do participante identifica o remetente de cada mensagem. `pushName` de mensagens enviadas pela própria instância nunca atualiza o contato do destinatário. A disponibilidade de envio, reação ou histórico de grupo depende da versão da Evolution; valide um fluxo real após atualização do provedor.
