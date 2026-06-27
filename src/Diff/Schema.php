<?php
// src/Diff/Schema.php

namespace MySqlSchemaSync\Diff;

use MySqlSchemaSync\Config\Connection;
use PDO;
use PDOException;

class Schema
{
    /** @var array<string, array> */
    public array $tables = [];
    /** @var array<string, array> */
    public array $indexes = [];
    /** @var array<string, array> */
    public array $foreignKeys = [];
    /** @var array<string, array> */
    public array $triggers = [];
    /** @var array<string, array> */
    public array $views = [];
    /** @var array<string, array> */
    public array $procedures = [];
    /** @var array<string, array> */
    public array $functions = [];
    /** @var array<string, array> */
    public array $events = [];

    public ?string $version = null;
    public ?string $error = null;

    public static function fromConnection(Connection $conn): self
    {
        $schema = new self();
        try {
            $dsn = "mysql:host={$conn->host};port={$conn->port};dbname={$conn->database};charset=utf8mb4";
            $pdo = new PDO($dsn, $conn->user, $conn->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
            $schema->version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $schema->fetchTables($pdo, $conn->database);
            $schema->fetchColumns($pdo, $conn->database);
            $schema->fetchIndexes($pdo, $conn->database);
            $schema->fetchForeignKeys($pdo, $conn->database);
            $schema->fetchTriggers($pdo, $conn->database);
            $schema->fetchViews($pdo, $conn->database);
            $schema->fetchProcedures($pdo, $conn->database);
            $schema->fetchFunctions($pdo, $conn->database);
            $schema->fetchEvents($pdo, $conn->database);
        } catch (PDOException $e) {
            $schema->error = $e->getMessage();
        }
        return $schema;
    }

    private function fetchTables(PDO $pdo, string $db): void
    {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_COMMENT
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'"
        );
        $stmt->execute([$db]);
        foreach ($stmt->fetchAll() as $row) {
            $name = $row['TABLE_NAME'];
            $this->tables[$name] = [
                'name'      => $name,
                'engine'    => $row['ENGINE'] ?? '',
                'collation' => $row['TABLE_COLLATION'] ?? '',
                'comment'   => $row['TABLE_COMMENT'] ?? '',
                'columns'   => [],
            ];
        }
    }

    private function fetchColumns(PDO $pdo, string $db): void
    {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT,
                     IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
                     NUMERIC_PRECISION, NUMERIC_SCALE, COLUMN_TYPE,
                     COLUMN_KEY, EXTRA, COLUMN_COMMENT, COLLATION_NAME
              FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = ?
              ORDER BY TABLE_NAME, ORDINAL_POSITION"
        );
        $stmt->execute([$db]);
        foreach ($stmt->fetchAll() as $row) {
            $table = $row['TABLE_NAME'];
            if (!isset($this->tables[$table])) continue;
            $this->tables[$table]['columns'][$row['COLUMN_NAME']] = [
                'name'         => $row['COLUMN_NAME'],
                'position'     => (int)$row['ORDINAL_POSITION'],
                'default'      => $row['COLUMN_DEFAULT'],
                'nullable'     => $row['IS_NULLABLE'] === 'YES',
                'dataType'     => $row['DATA_TYPE'],
                'charMax'      => $row['CHARACTER_MAXIMUM_LENGTH'],
                'numericPrec'  => $row['NUMERIC_PRECISION'],
                'numericScale' => $row['NUMERIC_SCALE'],
                'columnType'   => $row['COLUMN_TYPE'],
                'columnKey'    => $row['COLUMN_KEY'],
                'extra'        => $row['EXTRA'],
                'comment'      => $row['COLUMN_COMMENT'] ?? '',
                'collation'    => $row['COLLATION_NAME'] ?? '',
            ];
        }
    }

    private function fetchIndexes(PDO $pdo, string $db): void
    {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, INDEX_TYPE,
                     GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns
              FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = ?
              GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE, INDEX_TYPE"
        );
        $stmt->execute([$db]);
        foreach ($stmt->fetchAll() as $row) {
            $table = $row['TABLE_NAME'];
            $name  = $row['INDEX_NAME'];
            $this->indexes[$table . '.' . $name] = [
                'table'    => $table,
                'name'     => $name,
                'unique'   => !(bool)$row['NON_UNIQUE'],
                'type'     => $row['INDEX_TYPE'],
                'columns'  => $row['columns'],
            ];
        }
    }

    private function fetchForeignKeys(PDO $pdo, string $db): void
    {
        $stmt = $pdo->prepare(
            "SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                     kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                     rc.UPDATE_RULE, rc.DELETE_RULE
              FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
              LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
               AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
              WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_SCHEMA IS NOT NULL"
        );
        $stmt->execute([$db]);
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'];
            $this->foreignKeys[$key] = [
                'table'   => $row['TABLE_NAME'],
                'name'    => $row['CONSTRAINT_NAME'],
                'column'  => $row['COLUMN_NAME'],
                'refTable'=> $row['REFERENCED_TABLE_NAME'],
                'refColumn'=> $row['REFERENCED_COLUMN_NAME'],
                'update'  => $row['UPDATE_RULE'],
                'delete'  => $row['DELETE_RULE'],
            ];
        }
    }

    private function fetchTriggers(PDO $pdo, string $db): void
    {
        $stmt = $pdo->prepare(
            "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE,
                     ACTION_TIMING, ACTION_STATEMENT
              FROM INFORMATION_SCHEMA.TRIGGERS
              WHERE TRIGGER_SCHEMA = ?"
        );
        $stmt->execute([$db]);
        foreach ($stmt->fetchAll() as $row) {
            $this->triggers[$row['TRIGGER_NAME']] = [
                'name'      => $row['TRIGGER_NAME'],
                'event'     => $row['EVENT_MANIPULATION'],
                'table'     => $row['EVENT_OBJECT_TABLE'],
                'timing'    => $row['ACTION_TIMING'],
                'statement' => $row['ACTION_STATEMENT'],
            ];
        }
    }

    private function fetchViews(PDO $pdo, string $db): void
    {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, VIEW_DEFINITION
              FROM INFORMATION_SCHEMA.VIEWS
              WHERE TABLE_SCHEMA = ?"
        );
        $stmt->execute([$db]);
        foreach ($stmt->fetchAll() as $row) {
            $this->views[$row['TABLE_NAME']] = [
                'name'   => $row['TABLE_NAME'],
                'def'    => $row['VIEW_DEFINITION'],
            ];
        }
    }

    private function fetchProcedures(PDO $pdo, string $db): void
    {
        $stmt = $pdo->prepare(
            "SELECT ROUTINE_NAME, ROUTINE_DEFINITION
              FROM INFORMATION_SCHEMA.ROUTINES
              WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = 'PROCEDURE'"
        );
        $stmt->execute([$db]);
        foreach ($stmt->fetchAll() as $row) {
            $this->procedures[$row['ROUTINE_NAME']] = [
                'name' => $row['ROUTINE_NAME'],
                'def'  => $row['ROUTINE_DEFINITION'],
            ];
        }
    }

    private function fetchFunctions(PDO $pdo, string $db): void
    {
        $stmt = $pdo->prepare(
            "SELECT ROUTINE_NAME, ROUTINE_DEFINITION
              FROM INFORMATION_SCHEMA.ROUTINES
              WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = 'FUNCTION'"
        );
        $stmt->execute([$db]);
        foreach ($stmt->fetchAll() as $row) {
            $this->functions[$row['ROUTINE_NAME']] = [
                'name' => $row['ROUTINE_NAME'],
                'def'  => $row['ROUTINE_DEFINITION'],
            ];
        }
    }

    private function fetchEvents(PDO $pdo, string $db): void
    {
        $stmt = $pdo->prepare(
            "SELECT EVENT_NAME, EVENT_DEFINITION
              FROM INFORMATION_SCHEMA.EVENTS
              WHERE EVENT_SCHEMA = ?"
        );
        $stmt->execute([$db]);
        foreach ($stmt->fetchAll() as $row) {
            $this->events[$row['EVENT_NAME']] = [
                'name' => $row['EVENT_NAME'],
                'def'  => $row['EVENT_DEFINITION'],
            ];
        }
    }
}
