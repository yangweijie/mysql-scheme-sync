<?php
/**
 * Minimal stub for think\Collection.
 * Required by yangweijie/think-orm-async's AsyncResultPlaceholder which extends it.
 * This is a standalone project (non-ThinkPHP), so we provide only what's needed.
 *
 * Type declarations match the vendor child class (AsyncResultPlaceholder)
 * which has typed signatures (e.g. ?callable, :void, :bool, :int).
 */
namespace think;

class Collection
{
    protected $items = [];

    public function __construct($items = [])
    {
        $this->items = $items;
    }

    public function first(?callable $callback = null, $default = null)
    {
        if ($callback) {
            foreach ($this->items as $key => $value) {
                if ($callback($value, $key)) {
                    return $value;
                }
            }
            return $default;
        }
        return !empty($this->items) ? reset($this->items) : $default;
    }

    public function last($callback = null, $default = null)
    {
        if ($callback) {
            $filtered = array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH);
            return !empty($filtered) ? end($filtered) : $default;
        }
        return !empty($this->items) ? end($this->items) : $default;
    }

    public function count()
    {
        return count($this->items);
    }

    public function offsetExists($offset)
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet($offset)
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset($offset)
    {
        unset($this->items[$offset]);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->items);
    }

    public function isEmpty()
    {
        return empty($this->items);
    }

    public function toArray()
    {
        return $this->items;
    }

    public function all()
    {
        return $this->items;
    }

    public function __debugInfo()
    {
        return $this->items;
    }
}
