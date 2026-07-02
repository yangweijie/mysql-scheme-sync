<?php
namespace MySqlSchemaSync\Diff;

use MySqlSchemaSync\Config\Connection;
use Yangweijie\ThinkOrmAsync\AsyncContext;

/**
 * 使用 yangweijie/think-orm-async 并发获取数据库结构
 * 替代 DDZH\MysqlStructSync::getStructure() 的串行查询
 */
class AsyncStructureFetcher
{
    private int $batchSize;

    public function __construct(int $batchSize = 50)
    {
        $this->batchSize = $batchSize;
    }

    /**
     * 并发获取单个数据库的结构
     * 返回与 DDZH\MysqlStructSync::getStructure() 相同格式的结构数组
     */
    public function fetchStructure(Connection $dbConfig): array
    {
        // 1. 获取表列表（同步，只有1次查询）
        $mysqli = $this->connect($dbConfig);
        $result = $mysqli->query("SHOW TABLE STATUS WHERE Comment!='VIEW'");
        $tables = [];
        while ($row = $result->fetch_assoc()) {
            $tables[] = $row['Name'];
        }
        $mysqli->close();

        if (empty($tables)) {
            return ['tables' => [], 'columns' => [], 'show_create' => [], 'constraints' => []];
        }

        $allResults = $this->fetchCreateTablesAsync($dbConfig, $tables);

        // 3. 解析结果，生成与 MysqlStructSync::getStructure() 相同格式
        return $this->parseStructureResults($tables, $allResults);
    }

    private function fetchCreateTablesAsync(Connection $dbConfig, array $tables): array
    {
        $config = $this->buildAsyncConfig($dbConfig);
        $allResults = [];

        $batches = array_chunk($tables, $this->batchSize);

        foreach ($batches as $batch) {
            $batchResults = $this->executeBatchWithAsyncContext($config, $batch);
            $allResults = array_merge($allResults, $batchResults);
        }

        return $allResults;
    }

    private function executeBatchWithAsyncContext(array $config, array $tables): array
    {
        AsyncContext::start(null, $config);

        foreach ($tables as $table) {
            $sql = "SHOW CREATE TABLE `{$table}`";
            AsyncContext::query($sql, $table);
        }

        $rawResults = AsyncContext::end();

        $results = [];
        foreach ($tables as $table) {
            $data = $rawResults[$table] ?? [];
            $results[$table] = $data[0]['Create Table'] ?? '';
        }

        return $results;
    }

    /**
     * 解析 SHOW CREATE TABLE 结果，生成结构数组
     */
    private function parseStructureResults(array $tables, array $createTableResults): array
    {
        $alert_columns = [];
        $constraints   = [];
        $show_create   = [];

        // MysqlStructSync 用的正则
        $patterns = [
            '(^[^`]\s*PRIMARY KEY .*[,]?$)',
            '(^[^`]\s*KEY\s+(`.*`) .*[,]?$)',
            '(^[^`]\s*CONSTRAINT\s+(`.*`) .*[,]?$)',
        ];
        $pattern = '/' . implode('|', $patterns) . '/m';

        foreach ($tables as $table) {
            $sql = $createTableResults[$table] ?? '';
            if (!$sql) continue;

            preg_match_all('/^\s+[`]([^`]*)`.*?$/m', $sql, $key_value);
            $alert_columns[$table] = array_combine(
                $key_value[1],
                array_map(fn($item) => trim(rtrim($item, ',')), $key_value[0])
            );

            preg_match_all($pattern, $sql, $matches);
            $constraints[$table] = array_map(fn($item) => trim(rtrim($item, ',')), $matches[0]);

            $show_create[$table] = $sql;
        }

        ksort($alert_columns);
        ksort($constraints);
        ksort($show_create);
        ksort($tables);

        return [
            'tables'       => $tables,
            'columns'      => $alert_columns,
            'show_create'  => $show_create,
            'constraints'  => $constraints,
        ];
    }

    private function connect(Connection $dbConfig): \mysqli
    {
        $conn = new \mysqli(
            $dbConfig->host,
            $dbConfig->user,
            $dbConfig->password,
            $dbConfig->database,
            $dbConfig->port
        );
        if ($conn->connect_error) {
            throw new \RuntimeException("DB connection failed: " . $conn->connect_error);
        }
        $conn->set_charset('utf8mb4');
        return $conn;
    }

    public static function buildAsyncConfig(Connection $dbConfig): array
    {
        return [
            'hostname' => $dbConfig->host,
            'username' => $dbConfig->user,
            'password' => $dbConfig->password,
            'database' => $dbConfig->database,
            'hostport' => $dbConfig->port,
            'charset'  => 'utf8mb4',
        ];
    }
}
