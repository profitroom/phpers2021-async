<?php declare(strict_types=1);

use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Runtime;

require 'vendor/autoload.php';

$process = 'consumer';
require 'boot.php';

// Enable the hook for MySQL: PDO/MySQLi
Co::set(['hook_flags' => SWOOLE_HOOK_TCP]);

Runtime::enableCoroutine();
Co\run(function () use ($tracer) {

    $pool = new PDOPool(
        (new PDOConfig())
            ->withHost($_ENV['DB_HOST'])
            ->withDbName($_ENV['DB_NAME'])
            ->withCharset('utf8mb4')
            ->withUsername($_ENV['DB_USER'])
            ->withPassword($_ENV['DB_PASS'])
        , (int)$_ENV['DB_POOL']);

    $runScope = $tracer->startActiveSpan('consuming');

    $io = new \Hyperf\Amqp\IO\SwooleIO('localhost', 5672, 10);
    $connection = new \Hyperf\Amqp\AMQPConnection('guest', 'guest', io: $io);
    $channel = $connection->channel($connection->get_free_channel_id());
    $channel->queue_declare('message_queue', false, true, false, false);
    $channel->queue_declare('events_queue', false, true, false, false);

    $channel->basic_consume('message_queue', callback: function ($message) use ($pool, $tracer, $runScope) {
        go(function () use ($message, $tracer, $pool, $runScope) {
            // startActiveSpan() is not working with co-routines
            $span = $tracer->startSpan('coroutine', ['child_of' => $runScope->getSpan()]);

            $msg = json_decode($message->body, true);
            $id = $msg['id'];
            $value = $msg['value'];

            $pdo = $pool->get();

            $selectQuery = $pdo->prepare('SELECT value FROM data WHERE id=:id');
            $updateQuery = $pdo->prepare('UPDATE data SET value=:value WHERE id=:id');

            $pdo->beginTransaction();
            $selectQuery->execute([':id' => $id]);
            $currentValue = $selectQuery->fetchColumn();
            echo "$id = $currentValue + $value\n";
            $updateQuery->execute([':id' => $id, ':value' => $currentValue + $value]);
            $pdo->commit();

            $pool->put($pdo);

            $message->ack();
            $span->finish();

        });
    });
    while ($channel->is_consuming()) {
        $channel->wait();
    }
    $runScope->close();
});
$appScope->close();
$tracer->flush();
