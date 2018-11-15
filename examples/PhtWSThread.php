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
            else usleep(1000);
        }
    }
}
