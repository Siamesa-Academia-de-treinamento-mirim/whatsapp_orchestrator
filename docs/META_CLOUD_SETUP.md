# WhatsApp Cloud API

## Cadastro do canal

Em **Instâncias**, selecione `Meta Cloud API` e informe:

- nome de exibição e identificador interno estável;
- Phone Number ID;
- WhatsApp Business Account ID;
- Access Token;
- App Secret;
- Verify Token;
- versão da Graph API configurada para o aplicativo.

Os campos secretos são armazenados protegidos e não são devolvidos integralmente pela API do plugin.

## Webhook

Use a URL:

```text
https://SEU-RISE/chatwoot_plugin/webhooks/meta/IDENTIFICADOR_INTERNO
```

Configure o mesmo Verify Token cadastrado no canal. Assine os eventos com o App Secret. O plugin rejeita assinatura inválida e eventos cujo `phone_number_id` não pertence ao canal.

Assine pelo menos eventos de mensagens e status do WhatsApp Business Account.

## Templates

Use **Sincronizar templates** no canal. Apenas templates retornados pelo provedor e em situação utilizável devem ser oferecidos para campanhas oficiais. Componentes e parâmetros são persistidos de forma estruturada.

## Janela de atendimento

- Mensagem recebida abre/renova a janela de atendimento da conversa.
- Texto ou mídia livre só é enviado enquanto a janela estiver aberta.
- Fora da janela, o serviço exige um template oficial.
- O plugin não tenta converter uma mensagem comercial em mensagem de serviço nem contornar classificação da Meta.

## Mídia

A Meta recebe URL HTTPS acessível. O plugin gera URL assinada para arquivos locais permitidos, com prazo de expiração. Não use URL HTTP, caminho local ou host privado.
