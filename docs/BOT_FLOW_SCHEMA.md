# Bots determinísticos

## Princípio

O bot responde somente ao que foi definido em um fluxo publicado. Não utiliza IA generativa e não cria respostas fora do cadastro.

## Estrutura lógica

Um fluxo possui:

- nome, prioridade, canal opcional e estado ativo;
- mensagem inicial opcional;
- mensagem de fallback e de encaminhamento;
- limite de fallbacks;
- expiração de sessão;
- comportamento em grupos, desativado por padrão;
- estados, regras de entrada, resposta e próximo estado.

Operadores aceitos:

- `exact`: igualdade após normalização;
- `contains`: contém texto cadastrado;
- `starts_with`: começa com texto cadastrado;
- `any_word`: qualquer palavra cadastrada;
- regra de captura universal apenas quando explicitamente permitida pelo validador.

## Guardrails

- regras ambíguas são recusadas na publicação;
- fluxo sem saída válida é recusado;
- entrada desconhecida usa fallback;
- ao exceder o limite, envia handoff e pausa a sessão;
- resposta humana enviada com sucesso pausa o bot;
- sessão pausada não volta a responder até retomada explícita;
- nenhum campo executa PHP, shell, JavaScript, regex arbitrária ou prompt de IA;
- grupos são ignorados, salvo ativação explícita no fluxo.

## Publicação e versões

Editar um fluxo publicado cria uma nova versão de trabalho. `Publicar` congela a configuração. Sessões existentes guardam a versão publicada usada no início; novas sessões recebem a versão mais recente.

## Simulador

O simulador executa uma lista de mensagens sem enviar ao WhatsApp e retorna estado, resposta e eventual handoff. Use-o antes de publicar cada alteração.
