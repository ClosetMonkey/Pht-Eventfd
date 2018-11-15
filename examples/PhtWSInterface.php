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
