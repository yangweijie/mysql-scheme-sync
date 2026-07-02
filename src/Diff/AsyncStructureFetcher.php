<?php
namespace MySqlSchemaSync\Diff;

use MySqlSchemaSync\Config\Connection;

/**
 * 使用 think-orm-async 并发获取数据库结构
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

        // 2. 并发获取所有表的 SHOW CREATE TABLE
        $allResults = $this->fetchCreateTablesAsync($dbConfig, $tables);

        // 3. 解析结果，生成与 MysqlStructSync::getStructure() 相同格式
        return $this->parseStructureResults($tables, $allResults);
    }

    /**
     * 并发获取两张数据库的结构（两个数据库同时获取）
     */
    public function fetchStructuresInParallel(Connection $srcConfig, Connection $tgtConfig): array
    {
        // 用两个进程/协程并发获取，这里用简单的顺序获取但每个DB内部是并发的
        // 真正的双DB并发需要用 pthreads 或 Swoole，这里先优化单DB内部的并发
        $srcStruct = $this->fetchStructure($srcConfig);
        $tgtStruct = $this->fetchStructure($tgtConfig);
        return [$srcStruct, $tgtStruct];
    }

    /**
     * 分批并发执行 SHOW CREATE TABLE
     */
    private function fetchCreateTablesAsync(Connection $dbConfig, array $tables): array
    {
        $config = $this->buildMysqliConfig($dbConfig);
        $allResults = [];

        // 分批，每批并发执行
        $batches = array_chunk($tables, $this->batchSize);

        foreach ($batches as $batch) {
            $batchResults = $this->executeBatchAsync($config, $batch);
            $allResults = array_merge($allResults, $batchResults);
        }

        return $allResults;
    }

    /**
     * 对一批表并发执行 SHOW CREATE TABLE
     */
    private function executeBatchAsync(array $config, array $tables): array
    {
        $connections = [];
        $connMap     = [];   // key => mysqli connection
        $results     = [];

        // 为每个表创建一个异步连接并发送查询
        foreach ($tables as $table) {
            $conn = $this->createMysqliConnection($config);
            $sql  = "SHOW CREATE TABLE `{$table}`";
            $conn->query($sql, MYSQLI_ASYNC);
            $connections[$table] = $conn;
            $connMap[$table]     = $conn;
        }

        // 用 mysqli_poll 等待所有查询完成
        $pending = $connections;
        $timeout = 30;
        $startTime = time();

        while (count($pending) > 0 && (time() - $startTime) < $timeout) {
            $read   = $pending;
            $error  = $reject = [];

            $ready = mysqli_poll($read, $error, $reject, 0, 100000);

            if ($ready > 0) {
                foreach ($read as $conn) {
                    $key = array_search($conn, $connMap, true);
                    if ($key !== false) {
                        $result = $conn->reap_async_query();
                        if ($result) {
                            $row = $result->fetch_assoc();
                            $results[$key] = $row['Create Table'] ?? '';
                            $result->free();
                        } else {
                            $results[$key] = '';
                        }
                        unset($pending[$key]);
                    }
                }
            }

            foreach ($error as $conn) {
                $key = array_search($conn, $connMap, true);
                if ($key !== false) {
                    $results[$key] = '';
                    unset($pending[$key]);
                }
            }
        }

        // 关闭所有连接
        foreach ($connections as $conn) {
            $conn->close();
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

            // 解析列定义
            preg_match_all('/^\s+[`]([^`]*)`.*?$/m', $sql, $key_value);
            $alert_columns[$table] = array_combine(
                $key_value[1],
                array_map(fn($item) => trim(rtrim($item, ',')), $key_value[0])
            );

            // 解析约束（索引/主键）
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

    private function createMysqliConnection(array $config): \mysqli
    {
        $conn = new \mysqli(
            $config['hostname'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['hostport']
        );
        if ($conn->connect_error) {
            throw new \RuntimeException("Async DB connection failed: " . $conn->connect_error);
        }
        $conn->set_charset($config['charset']);
        return $conn;
    }

    private function buildMysqliConfig(Connection $dbConfig): array
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
