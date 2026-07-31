<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__, 2);
define('FCPATH', $root . DIRECTORY_SEPARATOR);
$_SERVER = array_replace($_SERVER, [
    'CI_ENVIRONMENT' => $_SERVER['CI_ENVIRONMENT'] ?? 'production',
    'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'localhost',
    'SCRIPT_NAME' => '/index.php',
    'REQUEST_URI' => '/',
    'REQUEST_METHOD' => 'CLI',
    'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? '80',
]);
defined('ENVIRONMENT') || define('ENVIRONMENT', (string) $_SERVER['CI_ENVIRONMENT']);
defined('CI_DEBUG') || define('CI_DEBUG', ENVIRONMENT !== 'production');

require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
Config\Services::autoloader()->addNamespace('Chatwoot_plugin', __DIR__);

try {
    (new Chatwoot_plugin\Libraries\Migration_runner())->migrate();
    $limit = isset($argv[1]) ? min(200, max(1, (int) $argv[1])) : 50;
    $result = (new Chatwoot_plugin\Services\Integration_job_service())->run('cron-' . getmypid(), $limit);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Impulso Hub job runner: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
