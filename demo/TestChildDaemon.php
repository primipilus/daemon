<?php

class TestChildDaemon extends \primipilus\daemon\BaseDaemon
{

    /**
     * @throws \primipilus\daemon\exceptions\FailureForkProcessException
     * @throws \primipilus\daemon\exceptions\InvalidOptionException
     * @throws Exception
     */
    protected function process() : void
    {
        if($this->isParent())
        {
            if(count($this->processes->getProcessDetails()) < $this->poolSize){
                $this->forkChild();
            }
            sleep(1);
        }else{
            for ($i = 0; $i < 3000; $i++) {
                sqrt($i * rand(1000, 10000) - rand(1000, 10000)  + count($this->processes->getProcessDetails()));
            }
            if(rand(1, 100) >= 99){
                throw new \Exception();
            }
        }
        $this->dispatch();
    }
}