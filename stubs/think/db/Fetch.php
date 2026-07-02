<?php
/**
 * Minimal stub for think\db\Fetch.
 * Required by yangweijie/think-orm-async's extractSql() method.
 * This project only uses raw queries, so this path is never executed.
 */
namespace think\db;

class Fetch
{
    public function __construct(mixed $query) {}

    public function find(): string
    {
        return '';
    }

    public function select(): string
    {
        return '';
    }
}
