<?php

class TestChildDaemon extends \primipilus\daemon\BaseDaemon
{
    protected function process() : void
    {
        $this->forkChild();

        for ($i = 0; $i < 1000; $i++) {
            sqrt($i);
        }

        $this->dispatch();
        $this->stopProcess();
    }
}
