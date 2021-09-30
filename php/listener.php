<?php

use Bunny\Channel;
use Bunny\Client;
use Bunny\Message;
use OpenTracing\Reference;

require 'vendor/autoload.php';

$process = 'listener';
require 'boot.php';

$channel = $bunny->channel();
$channel->queueDeclare('events_queue');

$scope = $tracer->startActiveSpan('listening');
try {
    $channel->run(function (Message $message, Channel $channel, Client $bunny) use ($tracer) {
        static $counter = 0;
        $counter++;

        $context = $tracer->extract(OpenTracing\Formats\HTTP_HEADERS, $message->headers);
        $scope = $tracer->startActiveSpan($message->routingKey, $context ? ['references' => new Reference(Reference::CHILD_OF, $context)] : []);

        $channel->ack($message);

        $scope->close();

        $tracer->flush();

        if ($counter == 10000) {
            throw new \Bunny\Exception\ClientException('Listening finished');
        }
    }, 'events_queue');
} catch (\Bunny\Exception\ClientException $e) {
    $logger->error($e->getMessage());
}
$channel->close();
$bunny->disconnect();
$scope->close();
$appScope->close();

$tracer->flush();
