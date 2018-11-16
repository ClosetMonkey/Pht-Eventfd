<?php

use pht\{Thread, Runnable};

class Task implements Runnable
{
    public function run() {
        require __DIR__ . '/vendor/autoload.php';

        $loop = React\EventLoop\Factory::create();
        $connector = new Ratchet\Client\Connector($loop);

        for($i=0; $i < 200; $i++)
        {
            $connector('ws://localhost:9000')->then(function(Ratchet\Client\WebSocket $conn) use ($i) {
                $chanId = (rand(1, 5) - 1);
                $msg = "Websocket test (client ".$i.")\n";
                $ev = json_encode((object)["data" => $msg.' '.$chanId, "channel" => $chanId]);

                $conn->on('message', function(\Ratchet\RFC6455\Messaging\MessageInterface $msg) use ($conn, $ev, $i) {
                    $chanId = (rand(1, 5) - 1);
                    $msg = "Websocket test (client ".$i.")\n";
                    $ev = json_encode((object)["data" => $msg.' '.$chanId, "channel" => $chanId]);

                    $conn->send($ev);
                });

                $conn->on('close', function($code = null, $reason = null) {
                    echo "Connection closed ({$code} - {$reason})\n";
                });
				
                $conn->send($ev);

        }, function(\Exception $e) use ($loop) {
                echo "Could not connect: {$e->getMessage()}\n";
                $loop->stop();
            });
        }
	
        $loop->run();

    }
}

$threads = [];

for ($i = 0; $i < 5; $i++) {
    $threads[$i] = new Thread();
    $threads[$i]->addClassTask(Task::class);
    $threads[$i]->start();
    sleep(20);
}

$threads[0]->join();
