<?php declare(strict_types=1);

use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Runtime;

require 'vendor/autoload.php';

$process = 'consumer';
require 'boot.php';

// Enable the hook for MySQL: PDO/MySQLi
Co::set(['hook_flags' => SWOOLE_HOOK_TCP]);

const N = 10000;

Runtime::enableCoroutine();
Co\run(function () use ($tracer) {
    $runScope = $tracer->startActiveSpan('consuming');

    $pool = new PDOPool(
        (new PDOConfig())
            ->withHost($_ENV['DB_HOST'])
            ->withDbName($_ENV['DB_NAME'])
            ->withCharset('utf8mb4')
            ->withUsername($_ENV['DB_USER'])
            ->withPassword($_ENV['DB_PASS'])
    , (int)$_ENV['DB_POOL']);

    for ($n = N; $n--;) {
        go(function () use ($pool, $tracer, $n, $runScope) {
            echo "coroutine #$n\n";
            // startActiveSpan() is not working with co-routines
            $span = $tracer->startSpan('coroutine #'.$n, ['child_of' => $runScope->getSpan()]);

            $id = mt_rand(1,1000);
            $value = mt_rand(1,10);

            $pdo = $pool->get();

            $selectQuery = $pdo->prepare('SELECT value FROM data WHERE id=:id');
            $updateQuery = $pdo->prepare('UPDATE data SET value=:value WHERE id=:id');

            $pdo->beginTransaction();
            $selectQuery->execute([':id' => $id]);
            $currentValue = $selectQuery->fetchColumn();
            $updateQuery->execute([':id' => $id, ':value' => $currentValue + $value]);
            $pdo->commit();

            $pool->put($pdo);

            $span->finish();
        });
    }
    $runScope->close();
});
$appScope->close();
$tracer->flush();
