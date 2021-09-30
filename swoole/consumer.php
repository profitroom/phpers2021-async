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

    $pool = new PDOPool(
        (new PDOConfig())
            ->withHost($_ENV['DB_HOST'])
            ->withDbName($_ENV['DB_NAME'])
            ->withCharset('utf8mb4')
            ->withUsername($_ENV['DB_USER'])
            ->withPassword($_ENV['DB_PASS'])
        , (int)$_ENV['DB_POOL']);

    $runScope = $tracer->startActiveSpan('consuming');

    go(function() use ($tracer, $pool) {
        $io = new \Hyperf\Amqp\IO\SwooleIO('localhost', 5672, 10);
        $connection = new \Hyperf\Amqp\AMQPConnection('guest', 'guest', io: $io);
        $channel = $connection->channel(1);
        $channel->queue_declare('message_queue', false, true, false, false);
        $channel->queue_declare('events_queue', false, true, false, false);

        $channel->basic_consume('message_queue', callback: function($message){
            print_r($message);
            return \Hyperf\Amqp\Result::ACK;
        });
    });

    $runScope->close();
});
$appScope->close();
$tracer->flush();
