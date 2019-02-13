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
    private $idsMap = [];

    /**
     * ProcessCollection constructor.
     * @param int $limit max limit of elements
     */
    public function __construct(int $limit)
    {
        $this->idsMap = array_fill(0, $limit, 0);
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
        if ($this->isFreeId($element->id())) {
            $this->idsMap[$element->id()] = 1;
            $this->elements[$element->pid()] = $element;
            return true;
        }

        return null;
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
        foreach ($this->idsMap as $id => $mark) {
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
     * @param int $pid
     * @return Process
     */
    public function remove(int $pid) : Process
    {
        if (!isset($this->elements[$pid]) && !array_key_exists($pid, $this->elements)) {
            return null;
        }

        $removed = $this->elements[$pid];
        unset($this->elements[$pid]);
        $this->idsMap[$removed->id()] = 0;

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
     * @param int $id
     * @return bool
     */
    private function isFreeId(int $id) : bool
    {
        return (bool)$this->idsMap[$id];
    }
}
