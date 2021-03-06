This fork of tpunts [pht](https://github.com/tpunt/pht) extension is an experiment to research pht's capabilities in providing async background tasks when coupled with event driven PHP services.

Only pht\Queue has been modified at this point. 

**Basic React/Ratchet Websocket with Pht threads**
These tests were mainly performed to study benifits of Pht threads when used with a React PHP service as well as the effects of such a combination on PHP's garbage collection. The following server was able to handle 5,000,000 messages from 200 concurrent websocket clients. The test was done by opening 20 chrome tabs with each tab maintaining 10 websocket clients. It experienced no memory growth within PHP process over duration of test and was stopped once satisfied that PHP was able to manage memory without error (no memory leaks or other pitfalls within PHP's GC). Test was done on an Intel Core 2 Duo (2 cores @ 3.00GHz) with 4 gigs of ram. Was able to handle over 10,000 messages per second on this minimal system (roughly 50 messages per second each client) although the test was not performed to gauge performance. 

[examples/PhtWSThread.php](https://github.com/ClosetMonkey/Pht-with-Eventfd/blob/eventfd/examples/PhtWSThread.php):
```php
<?php

class PhtWSThread implements pht\Runnable
{
    private $evfd;
    private $queu1;
    private $queu2;

    public function __construct($queu1, $queu2) {
        $this->evfd = $queu2->eventfd(true, false);
        $this->queu1 = $queu1;
        $this->queu2 = $queu2;
    }

    public function pop() {
        $ev = null;
        $this->queu1->lock();
          if ($this->queu1->size() > 0) {
              $ev = $this->queu1->pop();
          }
        $this->queu1->unlock();        
        return $ev;
    }
	
    public function push($ev) {
        $this->queu2->lock();
          $this->queu2->push($ev);
        $this->queu2->unlock();
        fwrite($this->evfd, "1");
    }	
	
    public function run() {
        while (1) {			
            if (($ev = $this->pop()) != "")
                $this->push($ev);
            else usleep(100);
        }
    }
}
```


[examples/PhtWSInterface.php](https://github.com/ClosetMonkey/Pht-with-Eventfd/blob/eventfd/examples/PhtWSInterface.php)
```php
<?php

namespace PhtEvfdQ;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class PhtWSInterface implements MessageComponentInterface {
    protected $clients;
    protected $total_recv = 0;
    protected $total_resp = 0;
    protected $queu_arr;

    public function __construct ($clients, $queu_arr) {
        $this->clients = $clients;
        $this->queu_arr = $queu_arr;
    }
    	
    public function onOpen (ConnectionInterface $conn) {
        $this->clients->attach($conn);
    }

    public function onMessage (ConnectionInterface $from, $msg) {
        $msg = json_decode($msg);
        $ev = json_encode(['msg' => $msg->data, 'cid' => $from->resourceId]);
        $this->queu_arr[$msg->channel]->lock();
          $this->queu_arr[$msg->channel]->push($ev);
        $this->queu_arr[$msg->channel]->unlock();
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }
}
```


[examples/pht_websocket.php](https://github.com/ClosetMonkey/Pht-with-Eventfd/blob/eventfd/examples/pht_websocket.php)
```php
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
```


[examples/wstest.html](https://github.com/ClosetMonkey/Pht-with-Eventfd/blob/eventfd/examples/wstest.html) (modified from https://gist.github.com/miebach/3293565)
```html
<!DOCTYPE html>
<!-- http://www.websocket.org/echo.html -->
<meta charset="utf-8" />  
<title>WebSocket Test</title>
<script language="javascript" type="text/javascript">

  var wsUri = "ws://localhost:9000";
  var clients = [];
  var client_count = 10;
  var out = null;
  
  function init() {
      out = document.getElementById("output");
      for(var i = 0; i < client_count; i++) {
          testWebSocket(i);
      }
  }

  function testWebSocket(i) {
      clients[i] = new WebSocket(wsUri);
      clients[i].onopen = function(evt) { onOpen(evt, i) };
      clients[i].onclose = function(evt) { onClose(evt) }; 
      clients[i].onmessage = function(evt) { onMessage(evt, i) };
      clients[i].onerror = function(evt) { onError(evt) }; 
  }

  function onOpen(evt, i) {
      doSend(i, "WebSocket rocks");
  }  

  function onClose(evt) {
  } 

  function onMessage(evt, i) { 
      doSend(i, "WebSocket rocks");
   } 

  function onError(evt) { 
  } 

  function doSend(i, message) {
      var channel = (Math.floor((Math.random() * 5) + 1) - 1);
      message += " on channel " + channel
      clients[i].send(JSON.stringify({data: message, channel: channel}));
  } 

  window.addEventListener("load", init, false);

</script>

<h2>WebSocket Test</h2>
<div id="output"></div>  
</html>
```

The following script is being developed to fully benchmark Pht with Ratchet once ready.

[examples/async_client.php](https://github.com/ClosetMonkey/Pht-with-Eventfd/blob/eventfd/examples/async_client.php)
```php
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
```
