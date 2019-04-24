<?php

namespace primipilus\daemon;

/**
 * Class ProcessDetails
 *
 * @package primipilus\daemon
 */
final class Process
{
    /** @var int */
    protected $serialNumber;
    /** @var int process pid */
    protected $pid;

    /**
     * The constructor
     *
     * @param int $serialNumber
     * @param int $pid
     */
    public function __construct(int $serialNumber, int $pid)
    {
        $this->serialNumber = $serialNumber;
        $this->pid = $pid;
    }

    /**
     * Get the pid
     *
     * @return int
     */
    public function serialNumber() : int
    {
        return $this->serialNumber;
    }

    /**
     * Get the pid
     *
     * @return int
     */
    public function pid() : int
    {
        return $this->pid;
    }
}
