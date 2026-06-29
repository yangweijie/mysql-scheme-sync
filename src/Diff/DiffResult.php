<?php
// src/Diff/DiffResult.php

namespace MySqlSchemaSync\Diff;

class DiffResult
{
    public const RISK_SAFE = 'SAFE';
    public const RISK_WARN = 'WARN';
    public const RISK_HIGH = 'HIGH';

    /** @var array<int, array> */
    public array $newTables = [];
    /** @var array<int, array> */
    public array $removedTables = [];
    /** @var array<int, array> */
    public array $changedTables = [];
    /** @var array<int, array> */
    public array $newIndexes = [];
    /** @var array<int, array> */
    public array $removedIndexes = [];
    /** @var array<int, array> */
    public array $newForeignKeys = [];
    /** @var array<int, array> */
    public array $removedForeignKeys = [];
    /** @var array<int, array> */
    public array $newTriggers = [];
    /** @var array<int, array> */
    public array $removedTriggers = [];
    /** @var array<int, array> */
    public array $newViews = [];
    /** @var array<int, array> */
    public array $removedViews = [];
    /** @var array<int, array> */
    public array $newProcedures = [];
    /** @var array<int, array> */
    public array $removedProcedures = [];
    /** @var array<int, array> */
    public array $newFunctions = [];
    /** @var array<int, array> */
    public array $removedFunctions = [];
    /** @var array<int, array> */
    public array $newEvents = [];
    /** @var array<int, array> */
    public array $removedEvents = [];

    public ?string $error = null;

    public function total(): int
    {
        return count($this->newTables) + count($this->removedTables) + count($this->changedTables)
            + count($this->newIndexes) + count($this->removedIndexes)
            + count($this->newForeignKeys) + count($this->removedForeignKeys)
            + count($this->newTriggers) + count($this->removedTriggers)
            + count($this->newViews) + count($this->removedViews)
            + count($this->newProcedures) + count($this->removedProcedures)
            + count($this->newFunctions) + count($this->removedFunctions)
            + count($this->newEvents) + count($this->removedEvents);
    }
}
