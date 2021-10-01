<?php

use Bunny\Client;
use Hyperf\Amqp\ConnectionFactory;
use Hyperf\Amqp\IO\SwooleIO;
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
        // socket-based dispatching not working with Swoole
        'dispatch_mode' => Config::JAEGER_OVER_BINARY_HTTP,
        'sampler' => [
            'type' => Jaeger\SAMPLER_TYPE_CONST,
            'param' => true,
        ],
//        'logging' => true,
    ],
    $process,
    $logger
);
$config->initializeTracer();
$tracer = GlobalTracer::get();

$appScope = $tracer->startActiveSpan('process', ['tags' => ['impl' => basename(__DIR__)]]);

$scope = $tracer->startActiveSpan('dotenv');
    $dotenv = Dotenv\Dotenv::createImmutable('../');
    $dotenv->load();
$scope->close();
