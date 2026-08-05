# Campanhas e worker

## Tipos

- **Oficial**: Meta Cloud API, template aprovado e parâmetros estruturados.
- **Não oficial**: Evolution, texto/mídia conforme capacidades do canal.

## Fluxo

1. A campanha é validada e salva.
2. A agenda cria uma ocorrência em `chat_campaign_runs`.
3. O público é congelado em `chat_campaign_run_recipients`.
4. O worker seleciona registros disponíveis sob lock.
5. Antes de cada envio, confere opt-out, estado do canal, horário e limites.
6. O provedor envia e devolve ID externo.
7. Webhooks atualizam enviado, entregue, lido, respondido ou falha.
8. Falhas transitórias recebem retry com atraso; falhas definitivas encerram o destinatário.

## Idempotência

A chave inclui campanha, ocorrência e destinatário. Recorrências não reutilizam a mesma chave. Recibos tardios continuam associados à ocorrência correta.

## Agendamento

Execute por minuto:

```bash
php plugins/Chatwoot_plugin/cron.php 50
```

O limite pode variar de 1 a 200. Use um usuário de sistema com acesso somente ao diretório necessário. Não exponha `cron.php` via HTTP; o arquivo encerra fora de CLI.

## Operação

O histórico da campanha mostra ocorrências e destinatários, com tentativas, status, último evento e erro. Ao atualizar uma instalação antiga, campanhas antes controladas por n8n são pausadas e devem ser revisadas antes da reativação.
