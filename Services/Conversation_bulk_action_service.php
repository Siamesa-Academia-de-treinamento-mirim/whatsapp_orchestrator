<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use InvalidArgumentException;
use Throwable;

class Conversation_bulk_action_service
{
    private const ACTIONS = ['status', 'priority', 'assignment', 'read_state', 'tags_add', 'tags_remove'];

    public function __construct(private ?Conversation_action_service $actions = null)
    {
        $this->actions ??= new Conversation_action_service();
    }

    public function execute(array $input, int $actorId): array
    {
        $rawIds = $input['conversation_ids'] ?? null;
        if (!is_array($rawIds)) throw new InvalidArgumentException('Informe entre 1 e 100 conversas unicas.');
        $ids = [];
        foreach ($rawIds as $rawId) {
            if (is_array($rawId) || is_object($rawId) || !preg_match('/^\d+$/', (string) $rawId) || (int) $rawId < 1) {
                throw new InvalidArgumentException('Identificador de conversa invalido.');
            }
            $ids[] = (int) $rawId;
        }
        $ids = array_values(array_unique($ids));
        if (!$ids || count($ids) > 100) throw new InvalidArgumentException('Informe entre 1 e 100 conversas unicas.');
        $action = trim((string) ($input['action'] ?? ''));
        if (!in_array($action, self::ACTIONS, true)) throw new InvalidArgumentException('Acao em massa nao permitida.');
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $this->validatePayload($action, $payload, $actorId);
        $results = [];
        foreach ($ids as $id) {
            try {
                $data = match ($action) {
                    'status' => $this->actions->set_status($id, (string) ($payload['status'] ?? ''), $actorId),
                    'priority' => $this->actions->set_priority($id, $payload['priority'] ?? '', $actorId),
                    'assignment' => $this->actions->assign($id, $payload, $actorId),
                    'read_state' => match ((string) $payload['state']) {
                        'read' => $this->actions->mark_read($id, $actorId),
                        'unread' => $this->actions->mark_unread($id, $actorId),
                    },
                    'tags_add' => $this->actions->add_tags($id, $this->tagNames($payload['tags'] ?? []), $actorId),
                    'tags_remove' => $this->actions->remove_tags($id, $this->tagNames($payload['tags'] ?? []), $actorId),
                };
                $results[] = ['conversation_id' => $id, 'ok' => true, 'data' => $data];
            } catch (Throwable $exception) {
                $results[] = ['conversation_id' => $id, 'ok' => false, 'error' => ['code' => $this->errorCode($exception), 'message' => 'Operacao nao aplicada para esta conversa.']];
            }
        }
        $succeeded = count(array_filter($results, static fn (array $item): bool => $item['ok']));
        return ['summary' => ['requested' => count($ids), 'succeeded' => $succeeded, 'failed' => count($ids) - $succeeded], 'results' => $results];
    }

    private function tagNames($value): array
    {
        if (!is_array($value)) throw new InvalidArgumentException('Tags da operacao em massa invalidas.');
        $names = array_values(array_unique(array_filter(array_map(static fn ($tag): string => trim((string) $tag), $value), static fn (string $tag): bool => $tag !== '')));
        if (count($names) > 50) throw new InvalidArgumentException('Muitas tags na operacao em massa.');
        foreach ($names as $name) {
            if (mb_strlen($name) > 100 || !preg_match('/^[\pL\pN _.-]+$/u', $name)) throw new InvalidArgumentException('Tag invalida na operacao em massa.');
        }
        return $names;
    }

    private function validatePayload(string $action, array $payload, int $actorId): void
    {
        switch ($action) {
            case 'read_state':
                if (!array_key_exists('state', $payload) || !in_array((string) $payload['state'], ['read', 'unread'], true)) {
                    throw new InvalidArgumentException('Estado de leitura invalido.');
                }
                return;
            case 'status':
                $status = Conversation_workflow_service::validateStatus((string) ($payload['status'] ?? ''));
                if ($status === 'snoozed') throw new InvalidArgumentException('Snooze nao esta disponivel em operacao em massa.');
                return;
            case 'priority':
                Conversation_workflow_service::validatePriority($payload['priority'] ?? '');
                return;
            case 'tags_add':
            case 'tags_remove':
                $this->tagNames($payload['tags'] ?? null);
                return;
            case 'assignment':
                $assigneeTouched = array_key_exists('assignee_id', $payload) || array_key_exists('assign_to_me', $payload);
                $teamTouched = array_key_exists('team_id', $payload);
                if (!$assigneeTouched && !$teamTouched) throw new InvalidArgumentException('Informe ao menos uma dimensao de atribuicao.');
                if (array_key_exists('assign_to_me', $payload) && !in_array($payload['assign_to_me'], [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                    throw new InvalidArgumentException('Indicador de atribuicao invalido.');
                }
                if (array_key_exists('assignee_id', $payload)) $this->validateId($payload['assignee_id'], 'Atendente invalido.', $actorId, false);
                if (array_key_exists('team_id', $payload)) $this->validateId($payload['team_id'], 'Equipe invalida.', $actorId, true);
                return;
        }
    }

    private function validateId($value, string $message, int $actorId, bool $team): void
    {
        if (is_array($value) || is_object($value) || !preg_match('/^\d+$/', (string) $value)) throw new InvalidArgumentException($message);
        $id = (int) $value;
        if ($id < 0) throw new InvalidArgumentException($message);
        if ($id === 0) return;
        $workflow = new Conversation_workflow_service();
        if ($team ? !$workflow->teamExists($id) : !$workflow->staffExists($id)) throw new InvalidArgumentException($message);
    }

    private function errorCode(\Throwable $exception): string
    {
        $code = (int) $exception->getCode();
        return $code === 404 ? 'CONVERSATION_NOT_FOUND' : ($code === 403 ? 'FORBIDDEN' : 'BULK_ACTION_FAILED');
    }
}
