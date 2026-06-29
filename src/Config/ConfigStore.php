<?php
// src/Config/ConfigStore.php
// Connection configuration management with AES-256-GCM encryption

namespace MySqlSchemaSync\Config;

use Exception;

class ConfigStore
{
    private string $configDir;
    private string $configFile;
    private string $keyFile;
    /** @var array<string, Connection> */
    public array $connections = [];
    /** @var array<string, mixed> */
    public array $settings = [];

    public function __construct()
    {
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? getenv('HOME') ?: getenv('USERPROFILE');
        $this->configDir  = $home . '/.mysql-schema-sync';
        $this->configFile = $this->configDir . '/config.json';
        $this->keyFile    = $this->configDir . '/.key';
        if (!is_dir($this->configDir)) {
            mkdir($this->configDir, 0700, true);
        }
        $this->load();
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $this->settings[$key] = $value;
        $this->save();
    }

    public function add(Connection $conn): void
    {
        $this->connections[$conn->id] = $conn;
        $this->save();
    }

    public function remove(string $id): void
    {
        unset($this->connections[$id]);
        $this->save();
    }

    public function get(string $id): ?Connection
    {
        return $this->connections[$id] ?? null;
    }

    public function list(): array
    {
        return array_values($this->connections);
    }

    public function test(Connection $conn): array
    {
        try {
            $dsn = "mysql:host={$conn->host};port={$conn->port};dbname={$conn->database};charset=utf8mb4";
            $pdo = new \PDO($dsn, $conn->user, $conn->password, [
                \PDO::ATTR_TIMEOUT        => 5,
                \PDO::ATTR_ERRMODE        => \PDO::ERRMODE_EXCEPTION,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            return ['ok' => true, 'version' => $version];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Persistence ──────────────────────────────────────────────

    private function load(): void
    {
        if (!file_exists($this->configFile)) {
            return;
        }
        $raw = file_get_contents($this->configFile);
        $enc = json_decode($raw, true);
        if (!is_array($enc)) return;

        $key = $this->getKey();
        foreach ($enc['connections'] ?? [] as $item) {
            $conn = Connection::fromArray($item);
            $conn->password = $this->decrypt($conn->password, $key);
            $this->connections[$conn->id] = $conn;
        }
        $this->settings = $enc['settings'] ?? [];
    }

    private function save(): void
    {
        $key = $this->getKey();
        $data = ['connections' => [], 'settings' => $this->settings];
        foreach ($this->connections as $conn) {
            $arr = $conn->toArray();
            $arr['password'] = $this->encrypt($conn->password, $key);
            $data['connections'][] = $arr;
        }
        file_put_contents($this->configFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        chmod($this->configFile, 0600);
    }

    // ── AES-256-GCM ─────────────────────────────────────────────

    private function getKey(): string
    {
        if (file_exists($this->keyFile)) {
            $key = file_get_contents($this->keyFile);
            if ($key !== false && strlen($key) === 32) {
                return $key;
            }
        }
        $key = random_bytes(32);
        file_put_contents($this->keyFile, $key);
        chmod($this->keyFile, 0600);
        return $key;
    }

    private function encrypt(string $plaintext, string $key): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    private function decrypt(string $encoded, string $key): string
    {
        $data = base64_decode($encoded);
        $iv     = substr($data, 0, 12);
        $tag    = substr($data, 12, 16);
        $cipher = substr($data, 28);
        $result = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $result !== false ? $result : '';
    }

    // ── Import / Export ──────────────────────────────────────────

    public function exportJson(): string
    {
        $key = $this->getKey();
        $data = ['connections' => []];
        foreach ($this->connections as $conn) {
            $arr = $conn->toArray();
            $arr['password'] = $this->encrypt($conn->password, $key);
            $data['connections'][] = $arr;
        }
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function importJson(string $json): int
    {
        $enc = json_decode($json, true);
        if (!is_array($enc)) return 0;
        $key = $this->getKey();
        $count = 0;
        foreach ($enc['connections'] ?? [] as $item) {
            $conn = Connection::fromArray($item);
            $conn->password = $this->decrypt($conn->password, $key);
            $this->connections[$conn->id] = $conn;
            $count++;
        }
        $this->save();
        return $count;
    }
}
