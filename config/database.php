<?php
/**
 * WealthDash — PDO Database Connection (Singleton)
 */
declare(strict_types=1);

class DB {
    private static ?PDO $instance = null;

    public static function conn(): PDO {
        if (self::$instance === null) {
            $host    = env('DB_HOST', 'localhost');
            $port    = env('DB_PORT', '3306');
            $dbname  = env('DB_NAME', 'wealthdash');
            $user    = env('DB_USER', 'root');
            $pass    = env('DB_PASS', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone='+05:30'",
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                if (IS_LOCAL) {
                    die('Database connection failed: ' . $e->getMessage());
                } else {
                    error_log('DB connection error: ' . $e->getMessage());
                    die('Database unavailable. Please try again later.');
                }
            }
        }
        return self::$instance;
    }

    /**
     * Execute a prepared statement and return the statement
     */
    public static function run(string $sql, array $params = []): PDOStatement {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * Fetch single row
     */
    public static function fetchOne(string $sql, array $params = []): array|false {
        return self::run($sql, $params)->fetch();
    }

    /**
     * Fetch single column value
     */
    public static function fetchVal(string $sql, array $params = []): mixed {
        return self::run($sql, $params)->fetchColumn();
    }

    /**
     * Insert and return last insert ID
     */
    public static function insert(string $sql, array $params = []): string {
        self::run($sql, $params);
        return self::conn()->lastInsertId();
    }

    /**
     * Transaction helpers
     */
    public static function beginTransaction(): void {
        self::conn()->beginTransaction();
    }

    public static function commit(): void {
        self::conn()->commit();
    }

    public static function rollback(): void {
        if (self::conn()->inTransaction()) {
            self::conn()->rollBack();
        }
    }
}

