<?php

namespace primipilus\daemon;

/**
 * Class ProcessDetailsCollection
 *
 * @package primipilus\daemon
 */
final class ProcessCollection
{

    /** @var Process[] the details */
    public $elements = [];
    /** @var int max limit of elements */
    private $serialNumbers = [];

    /**
     * ProcessCollection constructor.
     * @param int $limit max limit of elements
     */
    public function __construct(int $limit)
    {
        $this->serialNumbers = array_fill(0, $limit, 0);
    }

    /**
     * Adds an element at the end of the collection.
     *
     * @param Process $element The element to add.
     *
     * @return bool.
     */
    public function add(Process $element) : bool
    {
        if ($this->isFreeSerialNumber($element->serialNumber())) {
            $this->serialNumbers[$element->serialNumber()] = 1;
            $this->elements[$element->pid()] = $element;
            return true;
        }

        return false;
    }

    /**
     * Checks whether the collection is empty (contains no elements).
     *
     * @return bool TRUE if the collection is empty, FALSE otherwise.
     */
    public function isEmpty()
    {
        return empty($this->elements);
    }

    public function getNextId() : ?int
    {
        foreach ($this->serialNumbers as $id => $mark) {
            if (!$mark) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Gets a native PHP array representation of the collection.
     *
     * @return Process[]
     */
    public function getElements() : array
    {
        return $this->elements;
    }

    /**
     * @return int
     */
    public function count() : int
    {
        return \count($this->elements);
    }

    /**
     * @param int $pid
     * @return Process
     */
    public function remove(int $pid) : ?Process
    {
        if (!isset($this->elements[$pid]) && !array_key_exists($pid, $this->elements)) {
            return null;
        }

        $removed = $this->elements[$pid];
        unset($this->elements[$pid]);
        $this->serialNumbers[$removed->serialNumber()] = 0;

        return $removed;
    }

    /**
     * @return int[]
     */
    public function getPids() : array
    {
        return array_keys($this->elements);
    }

    /**
     * @param int $serialNumber
     * @return bool
     */
    public function isFreeSerialNumber(int $serialNumber) : bool
    {
        return !(bool)$this->serialNumbers[$serialNumber];
    }
}
