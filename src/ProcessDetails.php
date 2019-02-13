<?php

namespace primipilus\daemon;

/**
 * Class ProcessDetails
 *
 * @package primipilus\daemon
 */
class ProcessDetails
{
    /** @var int */
    protected $id;
    /** @var int process id pid */
    protected $pid;

    /**
     * The constructor
     *
     * @param int $id
     * @param int $pid
     */
    public function __construct(int $id, int $pid)
    {
        $this->id = $id;
        $this->pid = $pid;
    }

    /**
     * Get the pid
     *
     * @return int
     */
    public function id()
    {
        return $this->id;
    }

    /**
     * Get the pid
     *
     * @return int
     */
    public function pid()
    {
        return $this->pid;
    }
}
