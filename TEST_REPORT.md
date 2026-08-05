# Relatório de validação — 2.0.0

Data: 2026-07-30

## Executado neste ambiente

- `Tests/run_unit.php`: **17 aprovados, 0 falhas**.
- `Tests/run_product_static.php`: **12 aprovados, 0 falhas**.
- lint PHP de todos os arquivos: **sem erros de sintaxe**.
- `node --check Assets/js/chatwoot.js`: **aprovado**.
- `node --check Assets/js/hub-workspace.js`: **aprovado**.
- `git diff --check`: **sem whitespace errors**.
- integridade do ZIP (`unzip -t`): **aprovada**.
- suíte executada a partir do ZIP exportado: **29 aprovações, 0 falhas**.
- patch aplicado sobre o commit baseline `ff0fe51`: **aprovado**.
- suíte executada após aplicação do patch: **29 aprovações, 0 falhas**.
- checksums SHA-256 do ZIP e patch: **aprovados**.

## Cobertura relevante

- evento outgoing não renomeia cliente;
- identidade separada de grupo e participante;
- assinatura e normalização Meta;
- matriz de capacidades por provedor;
- bot determinístico, ambiguidade, fallback e handoff;
- pausa do bot somente depois do envio humano bem-sucedido;
- rotas atuais e ausência de rotas legadas;
- migrações V004–V009 registradas;
- fila interna, snapshots por ocorrência e lock;
- worker e histórico operacional presentes.

## Não executado neste ambiente

Os testes de migração completa, serviço e HTTP precisam do bootstrap do Rise CRM e de MySQL/MariaDB. O arquivo esperado `app/Config/Paths.php` não existe no ambiente isolado usado para edição. Não foram feitas chamadas reais à Evolution ou à Meta e nenhum disparo real foi realizado.
