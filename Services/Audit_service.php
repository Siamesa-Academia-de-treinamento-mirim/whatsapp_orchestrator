<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Services;

use Chatwoot_plugin\Models\Chat_audit_logs_model;
use Chatwoot_plugin\Models\Chat_settings_model;
use Throwable;

class Audit_service
{
    public function __construct(
        private ?Chat_audit_logs_model $logs = null,
        private ?Chat_settings_model $settings = null,
        private ?Payload_sanitizer $sanitizer = null
    ) {
        $this->logs ??= new Chat_audit_logs_model();
        $this->settings ??= new Chat_settings_model();
        $this->sanitizer ??= new Payload_sanitizer();
    }

    public function record(
        ?int $actorId,
        string $action,
        string $resourceType,
        $resourceId = null,
        ?int $instanceId = null,
        array $before = [],
        array $after = [],
        ?string $correlationId = null
    ): void {
        if ((int) $this->settings->get_value('audit_enabled', 1) !== 1) {
            return;
        }
        try {
            $request = service('request');
            $ipAddress = null;
            $userAgent = null;
            if ($request) {
                $ipAddress = substr((string) $request->getIPAddress(), 0, 64) ?: null;
                if (method_exists($request, 'getUserAgent')) {
                    $agent = $request->getUserAgent();
                    if (is_object($agent) && method_exists($agent, 'getAgentString')) {
                        $userAgent = substr((string) $agent->getAgentString(), 0, 500) ?: null;
                    } elseif (is_scalar($agent) || $agent instanceof \Stringable) {
                        $userAgent = substr((string) $agent, 0, 500) ?: null;
                    }
                }
            }
            $this->logs->create_record([
                'actor_user_id' => $actorId ?: null,
                'action' => substr($action, 0, 120),
                'resource_type' => substr($resourceType, 0, 64),
                'resource_id' => $resourceId === null ? null : substr((string) $resourceId, 0, 191),
                'instance_id' => $instanceId,
                'correlation_id' => $correlationId ? substr($correlationId, 0, 191) : null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'before_json' => $before === [] ? null : $this->sanitizer->sanitize_to_json($before),
                'after_json' => $after === [] ? null : $this->sanitizer->sanitize_to_json($after),
            ]);
        } catch (Throwable $exception) {
            log_message('error', 'Chatwoot_plugin audit write failed ({exception_type}).', ['exception_type' => get_class($exception)]);
        }
    }

    /** @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>} */
    public function list(array $filters, int $page = 1, int $limit = 50): array
    {
        $allowed = array_intersect_key($filters, array_flip(['actor_user_id', 'action', 'resource_type', 'resource_id', 'instance_id']));
        $result = $this->logs->paginate_records($allowed, $page, min(100, max(1, $limit)));
        $result['data'] = array_map(static function (array $row): array {
            foreach (['before_json' => 'before', 'after_json' => 'after'] as $source => $target) {
                $decoded = json_decode((string) ($row[$source] ?? ''), true);
                $row[$target] = is_array($decoded) ? $decoded : [];
                unset($row[$source]);
            }
            unset($row['deleted'], $row['updated_at']);
            return $row;
        }, $result['data']);
        return $result;
    }
}
