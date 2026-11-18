<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

ini_set('display_errors', ENVIRONMENT === 'production' ? '0' : '1');
set_exception_handler(static function (\Throwable $e): void {
    log_message('error', $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ENVIRONMENT === 'production' ? 'Something went wrong.' : $e->getMessage()]);
});

App\Core\Kernel::run();
