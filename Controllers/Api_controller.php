<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Controllers;

use App\Controllers\Security_Controller;
use Chatwoot_plugin\Libraries\Chat_permissions;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

abstract class Api_controller extends Security_Controller
{
    public function __construct()
    {
        // API clients receive JSON errors instead of Rise's HTML sign-in redirect.
        parent::__construct(false);
    }

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);

        if (empty($this->login_user->id)) {
            $this->abortRequest(
                Chat_permissions::translate('chatwoot_authentication_required', 'Autenticacao obrigatoria.'),
                401
            );
        }

        if (($this->login_user->user_type ?? '') !== 'staff') {
            $this->abortRequest(
                Chat_permissions::translate('chatwoot_access_denied', 'Voce nao tem permissao para esta acao.'),
                403
            );
        }

        $this->requirePermission(Chat_permissions::ACCESS);
    }

    protected function requirePermission(string $permission): void
    {
        if (Chat_permissions::can($this->login_user, $permission)) {
            return;
        }

        $this->abortRequest(
            Chat_permissions::translate('chatwoot_access_denied', 'Voce nao tem permissao para esta acao.'),
            403
        );
    }

    protected function requireSendPermission(): void
    {
        $this->requirePermission(Chat_permissions::SEND);
    }

    protected function requireManageInstancesPermission(): void
    {
        $this->requirePermission(Chat_permissions::MANAGE_INSTANCES);
    }

    protected function requireManageConversationsPermission(): void
    {
        $this->requirePermission(Chat_permissions::MANAGE_CONVERSATIONS);
    }

    protected function requireManageContactsPermission(): void
    {
        $this->requirePermission(Chat_permissions::MANAGE_CONTACTS);
    }

    protected function requireManageCampaignsPermission(): void
    {
        $this->requirePermission(Chat_permissions::MANAGE_CAMPAIGNS);
    }

    protected function requireManageBotsPermission(): void
    {
        $this->requirePermission(Chat_permissions::MANAGE_BOTS);
    }

    protected function requireManageSettingsPermission(): void
    {
        $this->requirePermission(Chat_permissions::MANAGE_SETTINGS);
    }

    protected function requireViewAuditLogsPermission(): void
    {
        $this->requirePermission(Chat_permissions::VIEW_AUDIT_LOGS);
    }

    protected function success($data = null, array $extra = [], int $status_code = 200): ResponseInterface
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        foreach ($extra as $key => $value) {
            if ($key !== 'success' && $key !== 'data') {
                $payload[$key] = $value;
            }
        }
        $payload['csrf'] = $this->csrfPayload();

        return $this->response
            ->setStatusCode($status_code)
            ->setJSON($payload);
    }

    protected function error(string $message, int $status_code = 400, array $details = []): ResponseInterface
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }
        $payload['csrf'] = $this->csrfPayload();

        return $this->response
            ->setStatusCode($status_code)
            ->setJSON($payload);
    }

    protected function input(): array
    {
        $data = [];

        try {
            $raw_input = $this->request->getRawInput();
            if (is_array($raw_input)) {
                $data = $raw_input;
            }

            $post = $this->request->getPost();
            if (is_array($post)) {
                $data = array_replace($data, $post);
            }

            $json = $this->request->getJSON(true);
            if (is_array($json)) {
                $data = array_replace($data, $json);
            }
        } catch (Throwable $exception) {
            // Invalid or absent JSON should be handled by endpoint validation.
        }

        return $data;
    }

    protected function actorId(): int
    {
        return (int) ($this->login_user->id ?? 0);
    }

    /** @return array{csrf_header:string,csrf_token_name:string,csrf_hash:string} */
    private function csrfPayload(): array
    {
        return [
            'csrf_header' => csrf_header(),
            'csrf_token_name' => csrf_token(),
            'csrf_hash' => csrf_hash(),
        ];
    }

    private function abortRequest(string $message, int $status_code): void
    {
        $this->response
            ->setStatusCode($status_code)
            ->setJSON([
                'success' => false,
                'message' => $message,
            ])
            ->send();

        exit;
    }
}
