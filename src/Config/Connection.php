<?php
// src/Config/Connection.php

namespace MySqlSchemaSync\Config;

class Connection
{
    public function __construct(
        public string $id,
        public string $name,
        public string $host,
        public int    $port,
        public string $user,
        public string $password,
        public string $database,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            id:       $a['id'] ?? '',
            name:     $a['name'] ?? '',
            host:     $a['host'] ?? '',
            port:     (int)($a['port'] ?? 3306),
            user:     $a['user'] ?? '',
            password: $a['password'] ?? '',
            database: $a['database'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'host'     => $this->host,
            'port'     => $this->port,
            'user'     => $this->user,
            'password' => $this->password,
            'database' => $this->database,
        ];
    }
}
