<?php

require 'vendor/autoload.php';

include 'PhtWSInterface.php';
include 'PhtWSThread.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use PhtEvfdQ\PhtWSInterface;

$channelCount = 5;

$threads = [];
$queu_arr = [];
$queu_ret = new pht\Queue();

for ($i = 0; $i < $channelCount; $i++) {
    $queu_arr[$i] = new pht\Queue();
 
    $threads[$i] = new pht\Thread();
    $threads[$i]->addClassTask(PhtWSThread::class, $queu_arr[$i], $queu_ret);
    $threads[$i]->start();	
}

$loop = React\EventLoop\Factory::create();
$websockClients = new \SplObjectStorage;
$readq = new React\Stream\ReadableResourceStream($queu_ret->eventfd(true, false), $loop);

$readq->on('data', function ($data/*not used*/) use ($websockClients, $queu_ret) {
    while ($queu_ret->size()) {
        $queu_ret->lock();
          $ev = $queu_ret->pop();
       	$queu_ret->unlock();
       	$ev = json_decode($ev);
        foreach ($websockClients as $client) {
            if ($ev->cid == $client->resourceId) {
                $client->send($ev->msg);
            }
        }
    }
});

$phtWSInterface = new PhtWSInterface($websockClients, $queu_arr);

$webSock = new React\Socket\Server('0.0.0.0:9000', $loop);
$webServer = new Ratchet\Server\IoServer(
    new Ratchet\Http\HttpServer(
        new Ratchet\WebSocket\WsServer(
            $phtWSInterface
        )
    ),
    $webSock
);

$loop->run();
