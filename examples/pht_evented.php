<?php

require 'vendor/autoload.php';

class Task implements pht\Runnable
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
        while (1)
        {
            if (($ev = $this->pop()) != "")
                $this->push($ev);
            else usleep(100);
        }
		
    }
}


$thread = new pht\Thread();

$queu1 = new pht\Queue();
$queu2 = new pht\Queue();

$thread->addClassTask(Task::class, $queu1, $queu2);
$thread->start();

$loop = React\EventLoop\Factory::create();

$readq = new React\Stream\ReadableResourceStream($queu2->eventfd(true, false), $loop);

$readq->on('data', function ($chunk) use ($queu1, $queu2) {
    if ($queu2->size() != 0)
    {
        var_dump(($ev = $queu2->pop()));
        $queu1->push($ev);
    }
});


$queu1->push("test");

$loop->run();
$thread->join();
