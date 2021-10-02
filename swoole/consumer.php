<?php declare(strict_types=1);

use OpenTracing\SpanContext;
use OpenTracing\Tracer;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Runtime;

$process = 'consumer';
require 'vendor/autoload.php';
require 'boot.php';

// Enable the hook for MySQL: PDO/MySQLi
Co::set(['hook_flags' => SWOOLE_HOOK_TCP]);

Runtime::enableCoroutine();

Co\run(function () use ($tracer) {
    $stopAfter = $_ENV['BENCHMARK_SIZE'];

    $pool = initializeConnectionPool();
    [$channel, $eventsChannel] = createAmqpChannel($tracer);
    $wg = new \Swoole\Coroutine\WaitGroup(); // to count/wait coroutines

    $runScope = $tracer->startActiveSpan('consuming');
    $channel->basic_consume('message_queue', callback: function (AMQPMessage $message) use ($pool, $tracer, $runScope, $wg, $eventsChannel) {
        go(function () use ($message, $tracer, $pool, $runScope, $wg, $eventsChannel) {
            $wg->add();
            $span = startTraceableSpan($message, $tracer);

            processMessage($message, $pool);

            publishEvent($message, $eventsChannel, $tracer, $span->getContext());

            $message->ack();

            $span->finish();
            $wg->done();
        });
    });

    // process messages until requested limit
    while ($channel->is_consuming()) {
        if ($wg->count() == $stopAfter) {
            echo "stop\n";
            break;
        }
        $channel->wait(); // wait for next message to process
    }

    $wg->wait(); // wait for all coroutines to finish
    $pool->close();
    $channel->getConnection()->close();
    $runScope->close();
});
$appScope->close();
$tracer->flush();
$logger->info("$process finished, traces flushed");

function initializeConnectionPool()
{
    return new PDOPool(
        (new PDOConfig())
            ->withHost($_ENV['DB_HOST'])
            ->withDbName($_ENV['DB_NAME'])
            ->withCharset('utf8mb4')
            ->withUsername($_ENV['DB_USER'])
            ->withPassword($_ENV['DB_PASS'])
        , (int)$_ENV['DB_POOL']);
}

function createAmqpChannel(Tracer $tracer)
{
    $scope = $tracer->startActiveSpan('rabbitmq-connect');
    $io = new \Hyperf\Amqp\IO\SwooleIO('localhost', 5672, 10);
    $connection = new \Hyperf\Amqp\AMQPConnection('guest', 'guest', io: $io);
    $channel = $connection->channel($connection->get_free_channel_id());
    $eventsChannel = $connection->channel($connection->get_free_channel_id());
    $channel->queue_declare('message_queue', false, true, false, false);
    $channel->queue_declare('events_queue', false, true, false, false);
//    $channel->basic_qos(0, 20, true);
    $scope->close();

    return [$channel, $eventsChannel];
}

function startTraceableSpan(AMQPMessage $message, Tracer $tracer)
{
    $headers = $message->get('application_headers')->getNativeData();
    $context = $tracer->extract(OpenTracing\Formats\HTTP_HEADERS, $headers);
    // startActiveSpan() is not working with co-routines
    return $tracer->startSpan($message->getRoutingKey(), ['child_of' => $context]);
}

function processMessage(AMQPMessage $message, PDOPool $pool)
{
    $msg = json_decode($message->body, true);
    $id = $msg['id'];
    $value = $msg['value'];

    $pdo = $pool->get();

    $selectQuery = $pdo->prepare('SELECT value FROM data WHERE id=:id');
    $updateQuery = $pdo->prepare('UPDATE data SET value=:value WHERE id=:id');

    $pdo->beginTransaction();
    $selectQuery->execute([':id' => $id]);
    $currentValue = $selectQuery->fetchColumn();
    $result = $updateQuery->execute([':id' => $id, ':value' => $currentValue + $value]);
    $pdo->commit();

    $pool->put($pdo);
}

function publishEvent(AMQPMessage $message, AMQPChannel $channel, Tracer $tracer, SpanContext $context)
{
    $headers = [];
    $tracer->inject($context, OpenTracing\Formats\HTTP_HEADERS, $headers);
    $event = new AMQPMessage($message->body, ['application_headers' => new AMQPTable($headers)]);
    $channel->basic_publish($event, routing_key: 'events_queue');
}