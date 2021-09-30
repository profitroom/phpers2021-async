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

$config = new Config(
    [
        // socket-based dispatching not working with Swoole
        'dispatch_mode' => Config::JAEGER_OVER_BINARY_HTTP,
        'sampler' => [
            'type' => Jaeger\SAMPLER_TYPE_CONST,
            'param' => true,
        ],
//        'logging' => true,
        'local_agent' => [
            'reporting_host' => 'localhost',
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
    $dotenv = Dotenv\Dotenv::createImmutable('../');
    $dotenv->load();
$scope->close();
