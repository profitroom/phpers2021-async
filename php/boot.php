<?php

use Bunny\Client;
use Jaeger\Config;
use OpenTracing\GlobalTracer;

if (!isset($process)) die('$process not set');

$logger = new \Analog\Logger();
$logger->handler(\Analog\Handler\Stderr::init());
$logger->info("starting $process #".getmypid());

register_shutdown_function(function() use ($logger, $process) {
    $logger->info("shutdown $process #".getmypid());
});

$config = new Config(
    [
        'dispatch_mode' => Config::JAEGER_OVER_BINARY_HTTP,
        'sampler' => [
            'type' => Jaeger\SAMPLER_TYPE_CONST,
            'param' => true,
        ],
//        'logging' => true,
        'local_agent' => [
            'reporting_host' => 'localhost',
//            'reporting_port' => 6831
        ],
    ],
    $process,
    $logger
);
$config->initializeTracer();
$tracer = GlobalTracer::get();

$appScope = $tracer->startActiveSpan('process');

pcntl_signal(SIGINT, function() use ($tracer) {
    $tracer->flush();
});

$scope = $tracer->startActiveSpan('dotenv');
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->load();
$scope->close();

$scope = $tracer->startActiveSpan('rabbitmq-connect');
    $bunny = new Client();
    $bunny->connect();
    $channel = $bunny->channel();
    $channel->qos(0, 5, false);
    $channel->queueDeclare('message_queue', false, true);
    $channel->queueDeclare('events_queue', false, true);
$scope->close();
