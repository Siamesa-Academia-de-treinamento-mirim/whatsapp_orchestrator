# Integração Evolution API

Este documento descreve a integração WhatsApp multi-instância da versão 1.1.0 do plugin `Chatwoot_plugin`. A Evolution API continua responsável pela conexão, histórico e transporte de mensagens; o plugin Rise é o front operacional e o n8n executa campanhas, IA e automações.

## Pré-requisitos

- Rise instalado e com o plugin `Chatwoot_plugin` ativado.
- Evolution API acessível pelo servidor PHP do Rise.
- Uma instância Evolution já criada e autenticada no WhatsApp.
- Extensão PHP cURL ativa.
- HTTPS válido em produção.
- Usuário Rise com as permissões do plugin adequadas para visualizar, enviar mensagens, administrar instâncias ou administrar configurações.

Ao instalar/ativar o plugin, as migrations V001/V002 criam o domínio `chat_*` de instâncias, conversas, mensagens, contatos, tags, mídia, campanhas, IA, automações, notificações, auditoria e jobs (o prefixo real acompanha a configuração do Rise).

## Configuração no Rise

Abra **Chatwoot plugin > Configurações > Evolution API** e informe:

1. **URL base**: origem da Evolution, por exemplo `https://evolution.exemplo.com`, sem endpoint no final.
2. **API key global**: credencial usada quando uma instância não possui chave própria.
3. **Timeout**: entre 3 e 120 segundos.
4. **Intervalo do polling**: entre 3.000 e 60.000 ms.
5. **Templates de endpoints**: mantenha `{instance}` no ponto em que o nome da instância deve ser inserido.
6. **Segredo do webhook**: use um valor aleatório longo e exclusivo. Deixar o campo secreto vazio ao salvar mantém o valor existente.

Depois, em **Instâncias**, cadastre cada canal com:

- nome de exibição;
- `evolution_instance_name`, exatamente igual ao nome na Evolution;
- identificação interna estável;
- URL base e API key próprias, quando a instância não usar os valores globais; alterar a URL específica exige a permissão de configurações;
- número conectado em formato internacional, somente dígitos, por exemplo `5511999999999`;
- estado ativo/inativo.

Uma instância inativa fica fora da sincronização, do envio e da ingestão de novos webhooks. Reative-a antes de repetir um evento descartado enquanto ela estava inativa.

A configuração específica da instância tem precedência sobre a configuração global. A chave global só pode ser herdada quando a URL específica possui a mesma origem (esquema, host e porta) da URL global; outra origem exige chave própria. Essa vinculação impede que uma URL alternativa receba a credencial global. Credenciais são armazenadas protegidas e a interface retorna apenas indicação de existência ou valor mascarado; a API key completa não deve ser copiada para nome, URL, logs ou campos públicos.

Ao trocar a origem global, informe novamente a chave global e primeiro revise instâncias que possuam chave própria enquanto herdam a URL global. Ao trocar a origem de uma instância, informe novamente sua chave ou marque **Remover a chave específica atual**. Isso evita reutilizar silenciosamente uma credencial armazenada em outro servidor.

Use **Testar conexão** ou **Atualizar status** depois de cadastrar a instância. Os estados da Evolution `open`, `connected` e `online` são exibidos como `connected`; `connecting` é exibido como atenção; demais estados são tratados como desconectados. Falha HTTP ou de transporte é apresentada como erro controlado.

## Cliente centralizado e endpoints utilizados

Todas as chamadas externas passam por `Libraries/Evolution_client.php`. Views e JavaScript nunca chamam a Evolution diretamente. O cliente centraliza URL, autenticação pelo header `apikey`, timeout, TLS, serialização JSON, normalização de respostas e logs sanitizados.

| Operação | Método | Template padrão | Corpo principal |
| --- | --- | --- | --- |
| Estado da conexão | `GET` | `/instance/connectionState/{instance}` | sem corpo |
| Listar chats | `POST` | `/chat/findChats/{instance}` | filtros aceitos pela versão instalada; por padrão `{}` |
| Buscar histórico | `POST` | `/chat/findMessages/{instance}` | `where.key.remoteJid` e filtros opcionais |
| Enviar texto | `POST` | `/message/sendText/{instance}` | `number` e `text` |
| Enviar mídia | `POST` | `/message/sendMedia/{instance}` | `number`, `mediatype`, `mimetype`, `media`, `caption` |
| Enviar áudio | `POST` | `/message/sendWhatsAppAudio/{instance}` | `number`, `audio` |
| Recuperar mídia | `POST` | `/chat/getBase64FromMediaMessage/{instance}` | mensagem nativa recebida da Evolution |

Em todas as chamadas, `{instance}` é substituído por `evolution_instance_name` com codificação segura para URL. Exemplo de headers:

```http
Accept: application/json
Content-Type: application/json
apikey: SUA_CHAVE_DA_INSTANCIA_OU_GLOBAL
```

Os templates ficam centralizados nas chaves:

- `evolution_endpoint_connection_state`
- `evolution_endpoint_find_chats`
- `evolution_endpoint_find_messages`
- `evolution_endpoint_send_text`
- `evolution_endpoint_send_media`
- `evolution_endpoint_send_audio`
- `evolution_endpoint_media_base64`

Não altere controllers ou views para acomodar uma diferença de rota. Ajuste o template correspondente na tela de configurações. A URL deve continuar sendo relativa à URL base e deve conter `{instance}`.

### Estado da conexão

Requisição:

```http
GET https://evolution.exemplo.com/instance/connectionState/vendas-sp
apikey: SUA_CHAVE
```

Formato comum de resposta Evolution v2:

```json
{
  "instance": {
    "instanceName": "vendas-sp",
    "state": "open"
  }
}
```

O cliente também reconhece pequenas variações, como `state`, `connectionStatus`, `connection_status` ou esses campos sob `data`.

### Listagem de chats

```http
POST https://evolution.exemplo.com/chat/findChats/vendas-sp
Content-Type: application/json
apikey: SUA_CHAVE

{}
```

Filtros adicionais podem variar entre versões. O banco local é a fonte da lista operacional exibida pelo Rise; a consulta à Evolution é usada para sincronização quando aplicável.

### Histórico de mensagens

```http
POST https://evolution.exemplo.com/chat/findMessages/vendas-sp
Content-Type: application/json
apikey: SUA_CHAVE

{
  "where": {
    "key": {
      "remoteJid": "5511999999999@s.whatsapp.net"
    }
  }
}
```

O plugin normaliza mensagens recebidas e enviadas, texto, imagem, áudio, vídeo e documento. O envio usa os endpoints centralizados acima. Mídia recebida pode ser resolvida por URL da mesma origem, `directPath` ou base64 retornado pela Evolution e é entregue ao navegador por proxy controlado.

### Envio de texto

```http
POST https://evolution.exemplo.com/message/sendText/vendas-sp
Content-Type: application/json
apikey: SUA_CHAVE

{
  "number": "5511999999999",
  "text": "Olá! Como podemos ajudar?"
}
```

O cliente procura o ID real da mensagem principalmente em `key.id`, mas aceita pequenas variações como `message_id`, `messageId`, `id` ou valores equivalentes sob `data`, `message` e `response`.

Conversas de grupo preservam o `remoteJid` terminado em `@g.us` no campo `number`. Como o aceite desse formato pode variar entre builds da Evolution, valide um envio real de grupo na versão instalada; conversas individuais continuam usando o número internacional normalizado.

## Webhook de entrada

Cadastre na Evolution API ou no n8n a URL pública:

```text
POST https://SEU-RISE/chatwoot_plugin/webhooks/evolution
Content-Type: application/json
```

Ative pelo menos os eventos `messages.upsert`, `messages.update`, `send.message` e `connection.update`. O nome da tela/rota usada para configurar webhooks na Evolution varia por versão; use a administração ou documentação correspondente à instalação, sem alterar a rota do plugin. Enquanto uma conversa estiver aberta, o polling também sincroniza diretamente o histórico dela na Evolution e percorre uma instância conectada por ciclo para atualizar a lista sem disparar todas ao mesmo tempo. O webhook continua recomendado para entrega imediata em todos os canais e para atualizações de entrega/leitura.

### Autenticação do webhook

Cada chamada deve usar **um** dos modos abaixo. O segredo é o mesmo configurado no Rise.

1. Segredo direto:

   ```http
   X-Chatwoot-Webhook-Secret: SEU_SEGREDO
   ```

2. Bearer token:

   ```http
   Authorization: Bearer SEU_SEGREDO
   ```

3. Assinatura HMAC-SHA256 do corpo HTTP bruto:

   ```http
   X-Chatwoot-Webhook-Signature: sha256=HEX_DA_ASSINATURA
   ```

A assinatura é o hexadecimal minúsculo de:

```text
HMAC-SHA256(raw_request_body, webhook_secret)
```

Calcule o HMAC sobre os bytes exatos enviados. Não reserialize, formate ou altere o JSON entre o cálculo e o envio. Não envie o segredo em query string, no payload ou em logs. Requisições sem uma credencial válida devem ser rejeitadas.

### Payload normalizado exato para n8n

Este é o formato preferido para o n8n chamar o plugin:

```json
{
  "event": "messages.upsert",
  "instance": "vendas-sp",
  "external_event_id": "evt_01JZ8J0K7M8N9P",
  "external_message_id": "3EB0A1B2C3D4E5F6",
  "remote_jid": "5511999999999@s.whatsapp.net",
  "from_me": false,
  "contact_name": "Maria Silva",
  "timestamp": 1784123456,
  "message_type": "text",
  "text": "Olá, preciso de ajuda.",
  "media_url": null,
  "mime_type": null,
  "file_name": null,
  "status": "received"
}
```

Regras dos campos:

| Campo | Obrigatório | Descrição |
| --- | --- | --- |
| `event` | sim | `messages.upsert`, `messages.update`, `send.message` ou `connection.update` |
| `instance` | sim | nome Evolution ou identificação interna cadastrada no Rise |
| `external_event_id` | recomendado | ID único do evento para idempotência do webhook |
| `external_message_id` | sim para mensagem | ID da mensagem na Evolution/WhatsApp |
| `remote_jid` | sim para mensagem | contato, normalmente `numero@s.whatsapp.net` |
| `from_me` | sim para mensagem | `false` para recebida; `true` para enviada |
| `contact_name` | não | nome conhecido/push name |
| `timestamp` | recomendado | Unix em segundos ou milissegundos; também é aceita data textual válida |
| `message_type` | recomendado | `text`, `image`, `audio` ou `document` |
| `text` | não | texto ou legenda da mídia |
| `media_url` | não | URL `http`/`https` da mídia para exibição |
| `mime_type` | não | por exemplo `image/jpeg`, `audio/ogg` ou `application/pdf` |
| `file_name` | não | nome seguro para documento |
| `status` | não | estado original, como `sent`, `delivered`, `read` ou `failed` |

Exemplo de imagem no mesmo formato:

```json
{
  "event": "messages.upsert",
  "instance": "vendas-sp",
  "external_message_id": "IMG_A1B2C3",
  "remote_jid": "5511999999999@s.whatsapp.net",
  "from_me": false,
  "timestamp": 1784123490,
  "message_type": "image",
  "text": "Comprovante",
  "media_url": "https://midia.exemplo.com/arquivo/IMG_A1B2C3",
  "mime_type": "image/jpeg",
  "file_name": "comprovante.jpg"
}
```

### Payload nativo comum da Evolution v2

O normalizador também aceita o envelope comum da Evolution:

```json
{
  "event": "messages.upsert",
  "instance": "vendas-sp",
  "data": {
    "key": {
      "remoteJid": "5511999999999@s.whatsapp.net",
      "fromMe": false,
      "id": "3EB0A1B2C3D4E5F6"
    },
    "pushName": "Maria Silva",
    "messageTimestamp": 1784123456,
    "message": {
      "conversation": "Olá, preciso de ajuda."
    }
  }
}
```

Também são reconhecidas formas usuais de `imageMessage`, `audioMessage`, `documentMessage`, `extendedTextMessage`, `messageId`, `remoteJid`, `fromMe` e status sob `data.update.status`. Nomes de evento com ponto, hífen ou sublinhado são normalizados. Como o formato nativo pode mudar, prefira o payload normalizado ao integrar pelo n8n.

### Resposta e idempotência

Depois de autenticar e validar o evento, o endpoint responde rapidamente com HTTP 200 e um JSON de confirmação. O remetente não deve depender de campos adicionais da resposta para considerar o evento entregue.

O plugin evita duplicação principalmente por:

1. instância + `external_message_id`;
2. `external_event_id`/chave idempotente do evento;
3. fallback derivado de instância, `remote_jid`, direção, timestamp, tipo e conteúdo quando não existe ID externo.

Envios repetidos do mesmo evento podem receber HTTP 200 sem criar uma nova mensagem. Use sempre IDs externos estáveis; o fallback existe apenas para compatibilidade.

## Fluxo de envio

1. O atendente abre uma conversa vinculada a uma instância.
2. O navegador envia texto e um `client_message_id` único ao controller autenticado do plugin.
3. O backend valida permissão, CSRF, conversa, instância ativa, conexão, destinatário e conteúdo.
4. O plugin grava/exibe a mensagem em estado `sending`, bloqueando duplo envio pelo mesmo identificador.
5. `Evolution_client` envia `number` e `text` à instância correta.
6. Em sucesso, o registro recebe o ID real da Evolution e o estado passa para enviado.
7. Em falha, a interface mostra erro controlado e permite tentar novamente sem duplicar a mensagem.
8. Webhooks posteriores podem promover o estado para entregue ou lida.

A instância e o número de destino são derivados da conversa armazenada; parâmetros enviados pelo navegador não podem redirecionar a mensagem para outro canal.

## Fluxo de recebimento

1. Evolution ou n8n envia um evento autenticado ao webhook.
2. O plugin valida segredo/assinatura e JSON.
3. O evento é normalizado e a instância é localizada no servidor.
4. A conversa é criada ou atualizada pela chave única `instance_id + remote_jid`.
5. A mensagem é persistida pela chave externa/idempotente e o payload de diagnóstico é sanitizado.
6. A última mensagem e atividade são atualizadas; mensagens recebidas incrementam não lidas.
7. Eventos de status atualizam mensagens existentes e `connection.update` atualiza o canal.
8. O polling do navegador busca conversas, contadores e mensagens novas. Ele pausa com a aba invisível, evita chamadas concorrentes e descarta timers ao sair da página.

Atualizações de entrega são monotônicas (`sent` → `delivered` → `read`). Se um `messages.update` chegar antes da mensagem correspondente, ele permanece pendente e pode ser reenviado com o mesmo identificador depois que `messages.upsert` for persistido. Em integrações via n8n, inspecione `results[].retryable` mesmo quando o envelope HTTP for `200` e repita o evento pendente; tentativas sob contenção ficam registradas nos logs do plugin.

A sincronização remota sob demanda usa `POST /chatwoot_plugin/api/conversations/sync` e `POST /chatwoot_plugin/api/conversations/{id}/messages/sync`, com sessão Rise e CSRF. Os `GET` de conversas e mensagens leem somente o banco local. Locks nomeados impedem duas sincronizações simultâneas da mesma instância ou conversa.

## Diferenças entre versões da Evolution

Esta implementação toma a API Evolution v2 como referência, mas instalações e builds podem divergir em rotas e envelopes.

- Se uma rota retornar 404, compare a documentação da versão instalada e altere somente o template correspondente nas configurações.
- Se a versão usar outro nome/caminho para estado, chats, histórico ou envio, mantenha a adaptação centralizada em `Evolution_client`.
- O estado conectado pode aparecer como `open`, `connected` ou `online`.
- O ID de envio pode aparecer em `key.id`, `id`, `messageId`, `message_id` ou em objetos internos.
- O histórico pode retornar lista direta ou coleções sob `data`, `messages` ou `records`.
- Quando `remoteJid` terminar em `@lid`, o plugin preserva esse identificador na conversa e usa `remoteJidAlt` (`@s.whatsapp.net`) para resolver o telefone de envio. Sem um alternativo válido, o envio é bloqueado até uma nova sincronização, evitando tratar o LID como número telefônico.
- Webhooks podem usar `MESSAGES_UPSERT`, `messages.upsert`, `messages-upsert` ou envelopes intermediados pelo n8n.
- URLs de mídia da Evolution podem ser temporárias ou exigir autenticação. O plugin usa proxy server-side para URLs da mesma origem e tenta recuperar `directPath`/mensagem nativa pelo endpoint de base64. URLs externas arbitrárias não são seguidas.
- Grupos usam o JID completo `...@g.us` no envio; confirme esse contrato na build instalada da Evolution.

Não invente uma rota para contornar uma incompatibilidade. Registre a versão (`docker image`, tag ou endpoint de versão), confirme o contrato real e então ajuste o template ou normalizador.

## Procedimento de teste

### 1. Testes locais sem Evolution

Execute o teste unitário com transportes e payloads simulados:

```powershell
php plugins/Chatwoot_plugin/Tests/run_unit.php
php plugins/Chatwoot_plugin/Tests/run_migration_smoke.php
php plugins/Chatwoot_plugin/Tests/run_service_integration.php
php plugins/Chatwoot_plugin/Tests/run_webhook_http.php
```

Esses testes não enviam mensagens reais à Evolution. Eles validam o cliente com transporte simulado, o schema e seus índices, persistência/deduplicação, progressão de status, retries, locks, rollback, ordem fora de sequência, instância inativa, JID de grupo, reconciliação otimista e as três formas de autenticação do webhook via HTTP local. O teste HTTP pressupõe Apache/XAMPP disponível em `http://localhost/rise/index.php`; ajuste `$url` no script se a instalação usar outro endereço.

### 2. Configuração e status

1. Salve URL base, chave global, timeout e polling.
2. Cadastre duas instâncias com identificações diferentes.
3. Use **Atualizar status** e confirme que cada canal mostra seu próprio estado.
4. Se uma instância usa chave própria, deixe a outra sem chave e sem URL alternativa para validar o fallback global. Uma URL de outra origem deve usar chave própria.
5. Confirme que nenhuma resposta do navegador ou log contém a chave completa.

### 3. Webhook normalizado com segredo direto

No PowerShell, salve o JSON em `webhook.json` e execute:

```powershell
curl.exe -i -X POST "https://SEU-RISE/chatwoot_plugin/webhooks/evolution" `
  -H "Content-Type: application/json" `
  -H "X-Chatwoot-Webhook-Secret: SEU_SEGREDO" `
  --data-binary "@webhook.json"
```

Espere HTTP 200. Reenvie exatamente o mesmo arquivo e confirme que a conversa/mensagem não foi duplicada. Troque `external_message_id` para confirmar a entrada de uma nova mensagem e o incremento de não lidas.

### 4. Webhook com HMAC

```powershell
$secret = 'SEU_SEGREDO'
$body = [System.IO.File]::ReadAllText((Resolve-Path '.\webhook.json'))
$hmac = [System.Security.Cryptography.HMACSHA256]::new([Text.Encoding]::UTF8.GetBytes($secret))
$signature = ([BitConverter]::ToString($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes($body)))).Replace('-', '').ToLowerInvariant()
curl.exe -i -X POST "https://SEU-RISE/chatwoot_plugin/webhooks/evolution" `
  -H "Content-Type: application/json" `
  -H "X-Chatwoot-Webhook-Signature: sha256=$signature" `
  --data-binary "@webhook.json"
```

### 5. Histórico, envio e polling

1. Abra a conversa criada pelo webhook e confirme ordem cronológica e direção.
2. Envie um texto curto pela interface.
3. Confirme o estado otimista, o ID externo após sucesso e a entrega no WhatsApp.
4. Clique rapidamente duas vezes e confirme que há apenas um envio.
5. Interrompa temporariamente o acesso à Evolution, tente enviar, confirme o estado de erro e use **Tentar novamente** depois de restaurar o serviço.
6. Com a conversa aberta, envie uma mensagem pelo WhatsApp e confirme sua aparição dentro do intervalo configurado.
7. Oculte a aba por mais de um intervalo e confirme que o polling pausa e volta ao retornar.

## Troubleshooting

| Sintoma | Verificação | Ação |
| --- | --- | --- |
| `configuration_error` de URL | URL global e URL da instância | Use URL absoluta `http://` ou `https://`, sem credenciais embutidas e sem endpoint final |
| Credencial não configurada | Chave da instância e chave global | Informe uma delas; campo secreto vazio ao editar preserva a existente |
| HTTP 401/403 da Evolution | Header `apikey` e escopo da chave | Confirme a chave correspondente à instância; não coloque `Bearer` nas chamadas da Evolution |
| HTTP 404 em endpoint Evolution | Versão e template configurado | Ajuste o template centralizado mantendo `{instance}` |
| Timeout/erro de transporte | DNS, firewall, proxy e certificado | Teste a URL a partir do servidor Rise; corrija TLS em vez de desativar sua validação |
| Canal sempre desconectado | Resposta de `connectionState` | Confirme se a instância está autenticada e qual campo/estado a versão retorna |
| Webhook retorna 401 | Header, segredo e HMAC | Compare o segredo; no HMAC use o corpo bruto exato e prefixo `sha256=` |
| Webhook retorna 400 | JSON e tamanho do corpo | Use JSON válido dentro do limite documentado |
| Webhook responde 200 com `processed: false` | `instance`, `remote_jid` e IDs | Use o payload normalizado desta documentação e uma instância cadastrada |
| Webhook retorna 200, mas nada aparece | Evento duplicado ou canal/filtro ativo | Troque o ID para teste, confira logs de webhook e selecione o canal correto |
| Mensagem duplicada | IDs ausentes ou instáveis | Preserve `external_message_id` e `external_event_id` em todas as tentativas |
| Mídia não abre | endpoint de base64 incompatível, MIME não permitido ou arquivo expirado | Confira `/chat/getBase64FromMediaMessage/{instance}`, o acesso do PHP à Evolution e os logs sanitizados |
| Envio fica em erro | Status da instância e resposta Evolution | Atualize o estado, confira número internacional e logs sanitizados; tente novamente após corrigir |
| Polling não atualiza | Aba invisível, permissão ou erro de sessão | Volte à aba, confirme login/permissão e inspecione a resposta JSON do endpoint interno |

## Limites de segurança e operação

- Não exponha Rise ou Evolution em HTTP público; use HTTPS.
- Restrinja o endpoint de administração da Evolution por rede sempre que possível.
- Use um segredo de webhook exclusivo e rotacione-o em caso de suspeita. Durante a troca, atualize o remetente imediatamente.
- Nunca registre API keys, segredo do webhook, header `Authorization` ou assinaturas completas.
- Não inclua credenciais na URL; proxies costumam registrar query strings.
- Mantenha CSRF nas rotas autenticadas do plugin. O webhook fica fora do CSRF porque usa autenticação própria.
- Permissões do Rise separam leitura, envio, gestão de instâncias e gestão de configurações.
- O backend determina a instância pela conversa ou pelo cadastro; não confie em `instance_id`, URL ou chave enviados pelo navegador.
- Consultas usam os models e o Query Builder; não concatene payloads em SQL.
- Conteúdo é escapado no front. URLs de mídia devem ser HTTP/HTTPS e são tratadas como dados não confiáveis.
- Redirecionamentos HTTP externos são desativados e a verificação TLS permanece ativa no cliente.
- Logs de webhook são sanitizados, mas ainda podem conter dados pessoais de conversas; aplique acesso restrito e política de retenção apropriada.
- O endpoint responde `200` após processamento válido ou duplicado. Falhas temporárias retornam `503` com `Retry-After`, erros permanentes retornam `422` e nenhuma resposta expõe stack trace ou credenciais.
- Polling abaixo de 3 segundos é bloqueado para reduzir carga e chamadas concorrentes.

## Fora do escopo da versão 1.1.0

- implementação interna dos workflows, memória longa ou RAG executados pelo n8n;
- RabbitMQ e filas complexas;
- equipes avançadas e presença online em tempo real;
- migração integral do Chatwoot;
- Facebook, Instagram, Telegram e e-mail;
- WebSocket próprio;
- criação/reconexão de instância e QR Code na Evolution;
- alteração dos fluxos existentes do n8n.

O n8n continua sendo o executor das automações e deve atender aos contratos documentados em `N8N_INTEGRATION.md`.
