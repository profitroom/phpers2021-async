<?php

use Bunny\Channel;
use Bunny\Client;
use Bunny\Message;
use OpenTracing\Reference;

require 'vendor/autoload.php';

$process = 'consumer';
require 'boot.php';

$scope = $tracer->startActiveSpan('mysql-connect');
    $pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'));
    $selectQuery = $pdo->prepare('SELECT value FROM data WHERE id=:id');
    $updateQuery = $pdo->prepare('UPDATE data SET value=:value WHERE id=:id');
$scope->close();

$messageChannel = $bunny->channel();
$messageChannel->queueDeclare('message_queue');

$eventsChannel = $bunny->channel();
$eventsChannel->queueDeclare('events_queue');

$scope = $tracer->startActiveSpan('consuming');
try {
    $messageChannel->run(
        function (Message $message, Channel $channel, Client $bunny) use ($pdo, $selectQuery, $updateQuery, $tracer, $eventsChannel, $logger) {
            static $counter = 0;
            $counter++;

            $context = $tracer->extract(OpenTracing\Formats\HTTP_HEADERS, $message->headers);
            $scope = $tracer->startActiveSpan($message->routingKey, $context ? ['references' => new Reference(Reference::CHILD_OF, $context)] : []);

            $msg = json_decode($message->content, true);
            $pdo->beginTransaction();
            $selectQuery->execute([':id' => $msg['id']]);
            $currentValue = $selectQuery->fetchColumn();
            $updateQuery->execute([':id' => $msg['id'], ':value' => $currentValue + $msg['value']]);
            $pdo->commit();
            $headers = [];
            $tracer->inject($scope->getSpan()->getContext(), OpenTracing\Formats\HTTP_HEADERS, $headers);
            $eventsChannel->publish(json_encode(['id' => $msg['id'], 'last_value' => $currentValue, 'counter' => $counter]), $headers, '', 'events_queue');
            $channel->ack($message);

            $scope->close();

            $tracer->flush();

            if ($counter == 10000) {
                throw new \Bunny\Exception\ClientException('Processing completed');
            }
        },
        'message_queue'
    );
} catch (\Bunny\Exception\ClientException $e) {
    $logger->error($e->getMessage());
}
$eventsChannel->close();

$bunny->disconnect();
$scope->close();
$appScope->close();

$tracer->flush();
