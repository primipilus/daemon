<?php

namespace primipilus\daemon;

/**
 * Class ProcessDetailsCollection
 *
 * @package primipilus\daemon
 */
class ProcessDetailsCollection
{

    /** @var ProcessDetails[] the details */
    public $processDetails = [];

    /**
     * @param ProcessDetails $processDetails
     */
    public function addProcess(ProcessDetails $processDetails) : void
    {
        $this->processDetails[] = $processDetails;
    }

    public function getNextId() : int
    {
        $i = 0;
        foreach ($this->getProcessDetails() as $processDetail) {
            if ((++$i) != $processDetail->id()) {
                return $i;
            } else {
                continue;
            }
        }
        return $i + 1;
    }

    /**
     * @return ProcessDetails[]
     */
    public function getProcessDetails() : array
    {
        return $this->processDetails;
    }

    /**
     * @param int $pid
     */
    public function remove(int $pid) : void
    {
        foreach ($this->processDetails as $key => $processDetail) {
            if ($processDetail->pid() === $pid) {
                unset($this->processDetails[$key]);
            }
        }
    }

    public function getPids() : array
    {
        $pids = [];
        foreach ($this->getProcessDetails() as $processDetail) {
            $pids[] = $processDetail->pid();
        }
        return $pids;
    }
}
