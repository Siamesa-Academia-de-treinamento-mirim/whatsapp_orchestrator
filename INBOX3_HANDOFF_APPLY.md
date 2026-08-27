# Applying the Inbox 3 Codex Handoff

This handoff is intentionally documentation/tests only. It does not overwrite production PHP/JS behavior before Codex starts Phase 1.

## Add to the Orchestrator repository

Copy these paths into the repository root preserving directories:

```text
AGENTS.md
CODEX_START_HERE.md
INBOX3_HANDOFF_APPLY.md
docs/inbox3/
Tests/run_inbox3_handoff.php
```

Do not replace the repository with the supplied analysis ZIP just to add these files; add the handoff files to the current authoritative working tree so unrelated line-ending/local changes are not introduced.

## Validate after copying

From plugin root:

```bash
php Tests/run_inbox3_handoff.php
php Tests/run_unit.php
php Tests/run_product_static.php
```

Then commit/push the handoff files.

## First Codex instruction

After the repository contains these files, give Codex this instruction:

```text
Leia AGENTS.md e CODEX_START_HERE.md e execute exatamente a Fase 1 do Inbox 3. Não avance para fases posteriores. Inspecione o código real antes de alterar, adicione os testes exigidos pela fase, rode a regressão definida no AGENTS.md e ao final reporte arquivos alterados, decisões de contrato, migrations, comandos/resultados de teste e riscos remanescentes.
```

Do not paste the whole architecture into the Codex chat. The repository documents are the persistent source of truth.

## Review loop

After Codex finishes a phase, review its diff and test report against that phase's gate in `docs/inbox3/IMPLEMENTATION_ROADMAP.md`. Only then start the next phase.

## Current release state

The original first-phase instruction above is retained as the historical handoff procedure. The current repository state is recorded in `CODEX_START_HERE.md` and `CODEX_HANDOFF.md`: Inbox 3 roadmap status = COMPLETE, Phase 1-8 are complete, V015 is the last migration, V016 is reserved, and no post-roadmap phase is authorized by this handoff.
