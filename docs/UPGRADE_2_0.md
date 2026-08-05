# Upgrade para 2.0

## Antes

- faça backup do banco e do diretório do plugin;
- anote canais, credenciais, campanhas em execução e tarefa agendada;
- pare temporariamente qualquer workflow n8n que dispare WhatsApp pelo plugin.

## Migrações

- V004: canais, Meta, grupos, participantes, autoria e bots.
- V005: fila interna, tentativas e recibos de campanha.
- V006: versões publicadas de bot e ocorrências de campanha.
- V007: audiência imutável por ocorrência.
- V008: converte modo legado de campanha e pausa execuções antigas.
- V009: desativa módulos legados de IA/n8n e remove configurações operacionais antigas sem apagar histórico.

## Depois

1. revise permissões;
2. teste cada canal;
3. use a reparação de contatos para localizar nomes contaminados pelo proprietário da instância;
4. valide um grupo real e confirme autoria de participantes;
5. sincronize templates Meta;
6. simule e publique bots;
7. revise campanhas pausadas;
8. instale o worker por minuto;
9. acompanhe logs técnicos e histórico de campanha nas primeiras execuções.

## Rollback

O código pode ser restaurado pelo backup, mas as migrações adicionam estruturas usadas pela versão 2.0. Não remova colunas/tabelas manualmente. Para rollback de produção, restaure código e banco do mesmo ponto de backup.
