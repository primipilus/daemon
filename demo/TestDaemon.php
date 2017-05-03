<?php

namespace test\test;

/**
 * @author primipilus 03.05.2017
 */
class TestDaemon extends \primipilus\daemon\BaseDaemon
{

    protected function process() : void
    {
        for ($i = 0; $i < 10 && !$this->isStopProcess(); $i++) {
            file_put_contents(__DIR__ . '/test.log', implode(' : ', [
                microtime(true),
                'daemonize=' . (int)$this->getDaemonize(),
                $i,
                PHP_EOL,
            ]), FILE_APPEND);
            sleep(1);
            $this->dispatch();
        }
    }
}