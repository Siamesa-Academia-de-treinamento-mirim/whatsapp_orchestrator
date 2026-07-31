<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Commands;

use Chatwoot_plugin\Services\Integration_job_service;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class Chatwoot_jobs extends BaseCommand
{
    protected $group = 'Impulso Hub';
    protected $name = 'impulso:chat-jobs';
    protected $description = 'Processa jobs, reconciliacoes e retencao do Impulso Hub.';
    protected $arguments = ['limit' => 'Quantidade maxima de jobs por execucao (padrao 50).'];

    public function run(array $params): int
    {
        $limit = isset($params[0]) ? min(200, max(1, (int) $params[0])) : 50;
        try {
            $result = (new Integration_job_service())->run('cli-' . getmypid(), $limit);
            CLI::write((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'green');
            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
            return EXIT_ERROR;
        }
    }
}
