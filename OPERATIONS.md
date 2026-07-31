# Operação do Impulso Hub Atendimento

## Instalação e atualização

Ative ou atualize o plugin pelo Rise. O hook executa migrations idempotentes; abrir a página do plugin também garante que a versão pendente seja aplicada. A V003 vincula conversas legadas a contatos normalizados, preservando cadastros editados manualmente. Não edite tabelas manualmente.

Depois da atualização para 1.1.0:

1. confira as permissões dos papéis do Rise;
2. teste a Evolution em **Configurações** e atualize o estado das instâncias;
3. configure o segredo do webhook com pelo menos 16 caracteres;
4. configure e teste o n8n, se campanhas/IA/automações forem usadas;
5. instale o job periódico abaixo.

## Job periódico

Esta distribuição do Rise não contém o arquivo raiz `spark`. Use o runner CLI do próprio plugin:

```powershell
cd C:\xampp\htdocs\rise
C:\xampp\php\php.exe plugins\Chatwoot_plugin\cron.php 50
```

Agende a cada minuto no Agendador de Tarefas do Windows. O parâmetro opcional é o limite de jobs, de 1 a 200. Em uma distribuição CodeIgniter com `spark`, a classe também registra o comando `impulso:chat-jobs [limit]`.

O runner usa um lock global no MariaDB, portanto duas execuções simultâneas não processam o mesmo lote. Ele executa:

- retry com backoff de webhooks pendentes;
- atualização periódica das instâncias;
- reconciliação de campanhas quando o n8n está configurado;
- expiração de mensagens otimistas sem confirmação;
- retenção de webhooks, auditoria, mídia e conversas resolvidas;
- notificação de falhas persistentes.

O runner pode chamar Evolution e n8n reais. Não o execute em produção apenas como teste se as integrações ainda não estiverem prontas.

## Webhook Evolution

Endpoint:

```text
POST /chatwoot_plugin/webhooks/evolution
```

Autentique com um dos headers:

```http
X-Chatwoot-Webhook-Secret: <segredo>
Authorization: Bearer <segredo>
X-Chatwoot-Webhook-Signature: sha256=<hmac-do-corpo-bruto>
```

Limites operacionais: corpo máximo de 2 MiB e 120 chamadas por minuto por IP. Sucesso/idempotência retorna `200`; erro permanente retorna `422`; falha temporária retorna `503` com `Retry-After`; excesso retorna `429`.

## Retenção e arquivos

Mídias são armazenadas somente sob `writable/uploads/chatwoot_plugin`. Downloads usam proxy autenticado ou URL pública assinada e expiram. A limpeza resolve o caminho real antes de excluir e não remove arquivos fora da raiz de uploads.

Retenções padrão:

- webhook técnico: 30 dias;
- auditoria: 180 dias;
- mídia em cache: 30 dias;
- conversas resolvidas: desativada (`0`).

## Diagnóstico rápido

- texto não envia: verifique conexão da instância, nome Evolution, URL/chave e o retorno de `/message/sendText/{instance}`;
- mensagens fora de ordem: confira `provider_timestamp`, `external_message_id` e se `messages.upsert/messages.update` chegam ao webhook;
- mídia não abre: confirme `/chat/getBase64FromMediaMessage/{instance}`, MIME permitido e acesso do servidor PHP à Evolution;
- campanha falha: use **Testar n8n**, confira o `correlation_id`, os jobs e o contrato em `N8N_INTEGRATION.md`;
- `503` no webhook: preserve o retry do provedor e confirme que o runner CLI está agendado.

Nunca grave chaves ou tokens em logs, prints, nomes de instância, payloads de teste ou documentação.
