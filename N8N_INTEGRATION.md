# Integração com n8n

O n8n é o executor externo de campanhas, IARA/IA e automações. O Rise mantém permissões, contatos, opt-out, auditoria, estado operacional e o snapshot local; nenhuma credencial é enviada ao navegador.

## Configuração

Em **Impulso Hub > Configurações > n8n**, informe:

- URL base, sem caminho final, por exemplo `https://n8n.exemplo.com`;
- token; deixar vazio ao salvar preserva o token atual;
- autenticação `bearer`, `hmac` ou `header`;
- nome do header quando o modo for `header` (padrão `X-API-Key`);
- timeout de 3 a 120 segundos;
- caminhos relativos de saúde, campanhas, IA e eventos;
- **Permitir rede privada** apenas quando o n8n estiver deliberadamente em uma rede interna confiável.

Padrões da versão 1.1.0:

| Finalidade | Caminho |
| --- | --- |
| Saúde | `/healthz` |
| Campanhas | `/webhook/campanha` |
| Controle de IA | `/webhook/iara/control` |
| Eventos/automações | `/webhook/impulso/events` |

O client recusa redirecionamentos, credenciais embutidas na URL, fragmentos e destinos privados por padrão. O DNS validado é fixado na conexão cURL para reduzir risco de DNS rebinding. Em instalações internas, a liberação de rede privada deve ser explícita.

## Autenticação de saída

- `bearer`: `Authorization: Bearer <token>`
- `header`: `<nome-configurado>: <token>`
- `hmac`: `X-Impulso-Signature: sha256=<HMAC-SHA256-do-corpo>`

Todas as requisições incluem `X-Correlation-Id`. Operações idempotentes podem incluir `Idempotency-Key` e são repetidas somente para falhas transitórias (`408`, `425`, `429` e `5xx`).

## Contrato de campanhas

Criação e atualização usam o caminho de campanhas. O DTO mantém compatibilidade com os fluxos existentes e inclui, entre outros, `lista_contato`, mensagem, agendamento, janela, intervalo, mídia e `contract_version: 1.1.0`.

Resposta mínima de criação:

```json
{
  "id": "identificador-no-n8n",
  "status": "running"
}
```

Para prévia de público gerada no n8n, o plugin chama:

```text
POST {n8n_campaigns_path}/audience-preview
```

Resposta aceita:

```json
{
  "recipients": [
    {"phone": "5511999999999"}
  ]
}
```

O plugin normaliza telefones, remove duplicados e reaplica o opt-out local. O n8n nunca deve ignorar a lista final recebida do Rise.

O reconciliador periódico consulta `GET {n8n_campaigns_path}` e aceita uma lista com `id`/`campaign_id`, `status`, `metrics` e `error`. Estados externos são normalizados para `draft`, `scheduled`, `running`, `paused`, `completed`, `failed` ou `cancelled`.

## Contrato de IA e automações

Agentes e estado da conversa são enviados ao caminho de IA com uma propriedade `action`, por exemplo `agent.upsert`, `agent.toggle`, `agent.delete` e alterações de estado. O identificador de correlação é gravado em logs locais sanitizados.

Automações usam o webhook configurado em cada regra. O botão **Testar** executa `dry_run`; ele não deve produzir efeitos reais no workflow.

## Falhas

Falhas do n8n ficam isoladas do envio/recebimento de texto da Evolution. Campanhas com erro são marcadas como `failed`, a mensagem sanitizada é persistida e uma notificação operacional é criada. Tokens e valores sensíveis são removidos das respostas antes de qualquer uso fora do client.
