<?php
/**
 * Database wrapper using PDO with prepared statements
 */
class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );

            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // The shared host's MySQL runs on US Pacific time; the sync
                // protocol is UTC end-to-end. Without this, auto-stamped
                // updated_at values lag UTC cursors by 7-8 hours and
                // incremental pulls go blind to recent changes.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        }

        return self::$instance;
    }

    /**
     * Execute a query and return all results
     */
    public static function query(string $sql, array $params = []): array {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a query and return first result
     */
    public static function queryOne(string $sql, array $params = []): ?array {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Execute an insert/update/delete and return affected rows
     */
    public static function execute(string $sql, array $params = []): int {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Insert a row and return the last insert ID
     */
    public static function insert(string $table, array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute(array_values($data));

        return (int) self::getInstance()->lastInsertId();
    }

    /**
     * Update rows matching conditions
     */
    public static function update(string $table, array $data, array $where): int {
        $setClauses = [];
        $params = [];

        foreach ($data as $column => $value) {
            $setClauses[] = "{$column} = ?";
            $params[] = $value;
        }

        $whereClauses = [];
        foreach ($where as $column => $value) {
            $whereClauses[] = "{$column} = ?";
            $params[] = $value;
        }

        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses)
        );

        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Begin a transaction
     */
    public static function beginTransaction(): bool {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public static function commit(): bool {
        return self::getInstance()->commit();
    }

    /**
     * Rollback a transaction
     */
    public static function rollback(): bool {
        return self::getInstance()->rollBack();
    }
}
