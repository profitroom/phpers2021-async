<?php
require 'vendor/autoload.php';

\Swoole\Runtime::enableCoroutine();

Co\run(function(){
    echo "running\n";
    $io = new \Hyperf\Amqp\IO\SwooleIO('localhost', 5672, 30);
    $connection = new \Hyperf\Amqp\AMQPConnection('guest', 'guest', io: $io);
    $channel = $connection->channel($connection->get_free_channel_id());
    $channel->queue_declare('message_queue', false, true, false, false);
//    $channel->basic_qos(null, 1, null);
    echo "consuming\n";
    $channel->basic_consume('message_queue', callback: function($message){
        print_r($message->body);echo "\n";
        $message->ack();
    });
    echo "after consume\n";
    while ($channel->is_consuming()) {
        $channel->wait();
    }
    echo "after while\n";
});
echo "done\n";
