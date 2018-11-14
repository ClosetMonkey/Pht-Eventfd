This fork of tpunts [pht](https://github.com/tpunt/pht) extension is an experiment to research pht's capabilities in providing async background tasks when coupled with eventloop services.

Only pht\Queue has been modified at this point. 

**To use**

The eventfd stream interface is activated by calling the `$queue->eventfd(bool nonblocking, bool auto_evfd)` method. It requires two bool arguments and will return the stream resource of an eventfd socket added to the internal queue object.

if `bool nonblocking` is set to true the underlying eventfd stream socket will be set to non blocking, otherwise the resulting stream will be a blocking eventfd socket

if `bool auto_evfd` is set true the queue's pop and first methods will automatically trigger the underlying event socket with a read(), and the queue's push method will automatically trigger the event socket with a write(). Setting `auto_evfd` to true is not intended for synchronization of the queue (although this is possible, the queues lock/unlock methods should still be used) - instead the intention is to automatically triggering an eventloop on the main thread.
if `bool auto_evfd` is set false none of the queues base methods will be effected but standard stream functions can still be used on the stream returned from `$queue->eventfd()` (such as fread/fwrite/etc). Note that doing so will only read/write from the underlying event socket not the queue itself and is only useful for manually triggering an event on the main loop for the associated queue.

If `$queue->eventfd()` is not called the queue will work as normal in both function and performance.

Can only be installed on OSs that support eventfd.

Modifications will be found in the eventfd branch.

**Basic React/Ratchet Websocket example**

PhtWSThread.php:
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
        if ($this->queu1->size() != 0) {
        $ev = $this->queu1->pop();
            return $ev;			
        }	
    }
	
    public function push($ev) {
        $this->queu2->push($ev);
        fwrite($this->evfd, "1");
    }	

    public function run() {
        while (1) {			
            if (($ev = $this->pop()) != "")
                $this->push($ev);
            else usleep(1000);
        }	
    }
}
```

PhtWSInterface.php
```php
<?php

namespace PhtEvfdQ;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class PhtWSInterface implements MessageComponentInterface {
    protected $clients;
    protected $queu1;
    protected $queu2;
    protected $evfd;

    public function __construct ($clients, $queu1, $queu2) {
        $this->clients = $clients;
        $this->queu1 = $queu1;
        $this->queu2 = $queu2;
        $this->evfd = $queu2->eventfd(true, false);
    }
    
    public function onOpen (ConnectionInterface $conn) {
        $this->clients->attach($conn);
    }

    public function onMessage (ConnectionInterface $from, $msg) {
        $ev = json_encode(['msg' => $msg, 'cid' => $from->resourceId]);		
        $this->queu1->push($ev);
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }
}
```
PhtWebsocket.php
```php
<?php

require 'vendor/autoload.php';

include 'PhtWSInterface.php';
include 'PhtWSThread.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use PhtEvfdQ\PhtWSInterface;

$websockClients = new \SplObjectStorage;

$thread = new pht\Thread();

$queu1 = new pht\Queue();
$queu2 = new pht\Queue();

$loop   = React\EventLoop\Factory::create();
$readq = new React\Stream\ReadableResourceStream($queu2->eventfd(true, false), $loop);

$readq->on('data', function ($data/*not used*/) use ($websockClients, $queu1, $queu2) {
    if ($queu2->size() != 0) {
        $ev = $queu2->pop();
       	$ev = json_decode($ev);
        foreach ($websockClients as $client) {
            if ($ev->cid == $client->resourceId) {
                $client->send($ev->msg);
            }
        }
    }
});

$phtWSInterface = new PhtWSInterface($websockClients, $queu1, $queu2);

$webSock = new React\Socket\Server('0.0.0.0:9000', $loop);
$webServer = new Ratchet\Server\IoServer(
    new Ratchet\Http\HttpServer(
        new Ratchet\WebSocket\WsServer(
            $phtWSInterface
        )
    ),
    $webSock
);

$thread->addClassTask(PhtWSThread::class, $queu1, $queu2);
$thread->start();

$loop->run();
```
wstest.html (from https://gist.github.com/miebach/3293565)
```html
<!DOCTYPE html>
<!-- http://www.websocket.org/echo.html -->
<meta charset="utf-8" />  
<title>WebSocket Test</title>
<script language="javascript" type="text/javascript">

  var wsUri = "ws://localhost:9000";
  var output;
  
  function init() {
    output = document.getElementById("output");
    testWebSocket();
  }

  function testWebSocket() {
    websocket = new WebSocket(wsUri);
    websocket.onopen = function(evt) { onOpen(evt) };
    websocket.onclose = function(evt) { onClose(evt) }; 
    websocket.onmessage = function(evt) { onMessage(evt) };
    websocket.onerror = function(evt) { onError(evt) }; 
  }

  function onOpen(evt) {
    writeToScreen("CONNECTED");
    doSend("WebSocket rocks");
  }  

  function onClose(evt) {
    writeToScreen("DISCONNECTED"); 
  } 

  function onMessage(evt) { 
    writeToScreen('<span style="color: blue;">RESPONSE: ' + evt.data+'</span>'); 
    websocket.close(); 
   } 

  function onError(evt) { 
    writeToScreen('<span style="color: red;">ERROR:</span> ' + evt.data);
  } 

  function doSend(message) {
    writeToScreen("SENT: " + message);  
    websocket.send(message);
  } 

  function writeToScreen(message) {
    var pre = document.createElement("p");
    pre.style.wordWrap = "break-word";
    pre.innerHTML = message;
    output.appendChild(pre);
  }

  window.addEventListener("load", init, false);

</script>

<h2>WebSocket Test</h2>

<div id="output"></div>  

</html>
```
