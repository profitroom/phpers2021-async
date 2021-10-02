<?php

use Bunny\Channel;
use Bunny\Client;
use Bunny\Exception\ClientException;
use Bunny\Message;
use OpenTracing\Reference;
use OpenTracing\Scope;
use OpenTracing\SpanContext;
use OpenTracing\Tracer;

require 'vendor/autoload.php';

$process = 'consumer';
require 'boot.php';

$scope = $tracer->startActiveSpan('mysql-connect');
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$scope->close();

$scope = $tracer->startActiveSpan('consuming');
try {
    $channel->consume(
        function (Message $message, Channel $channel, Client $bunny) use ($pdo, $tracer, $logger) {
            static $counter = 0;
            $counter++;

            $context = $tracer->extract(OpenTracing\Formats\HTTP_HEADERS, $message->headers);
            $scope = $tracer->startActiveSpan($message->routingKey, $context ? ['references' => new Reference(Reference::CHILD_OF, $context)] : []);

            processMessage($message, $pdo);

            publishEvent($message, $channel, $tracer, $scope);

            $channel->ack($message);

            $scope->close();

            if ($counter == $_ENV['BENCHMARK_SIZE']) {
                throw new ClientException('Processing completed');
            }
        },
        'message_queue'
    );
    $bunny->run(10);
} catch (ClientException $e) {
    $logger->info($e->getMessage());
}

$bunny->disconnect();
$scope->close();
$appScope->close();

$tracer->flush();

function processMessage(Message $message, PDO $pdo)
{
    $msg = json_decode($message->content, true);
    $selectQuery = $pdo->prepare('SELECT value FROM data WHERE id=:id');
    $updateQuery = $pdo->prepare('UPDATE data SET value=:value WHERE id=:id');
    $pdo->beginTransaction();
    $selectQuery->execute([':id' => $msg['id']]);
    $currentValue = $selectQuery->fetchColumn();
    $updateQuery->execute([':id' => $msg['id'], ':value' => $currentValue + $msg['value']]);
    $pdo->commit();
}

function publishEvent(Message $message, Channel $channel, Tracer $tracer, Scope $scope)
{
    $headers = [];
    $tracer->inject($scope->getSpan()->getContext(), OpenTracing\Formats\HTTP_HEADERS, $headers);
    $channel->publish($message->content, $headers, '', 'events_queue');
}