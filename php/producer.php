<?php

require 'vendor/autoload.php';

$process = 'producer';
require 'boot.php';

$channel = $bunny->channel();
$channel->queueDeclare('message_queue');

$scope = $tracer->startActiveSpan('producing');
    $headers = [];
    $tracer->inject($scope->getSpan()->getContext(), OpenTracing\Formats\HTTP_HEADERS, $headers);
    for ($i = 0; $i < 10000; $i++) {
        $message = json_encode([
            'id' => rand(1, 1000),
            'value' => rand(10, 99),
        ]);
        $channel->publish(
            $message,
            $headers,
            '',
            'message_queue'
        );
    }
$scope->close();
$appScope->close();

$tracer->flush();