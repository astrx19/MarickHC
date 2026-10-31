<?php
/**
 * PDO data layer (Step 2).
 *
 * Coexists with the existing mysqli $conn from config/database.php, so pages
 * can be migrated one module at a time. It reuses the SAME credentials
 * (env vars / config/database.local.php), so nothing new to configure.
 *
 *   $rows = mh_db_all('SELECT id, name FROM t WHERE status = ? AND d >= ?', [1, $from]);
 *   $row  = mh_db_one('SELECT * FROM t WHERE id = :id', ['id' => $id]);
 *   $n    = mh_db_value('SELECT COUNT(*) FROM t WHERE status = ?', [1]);
 *   $ok   = mh_db_exec('UPDATE t SET a = ? WHERE id = ?', [$a, $id]);
 *   [$in, $params] = mh_db_in([3, 5, 9]);   // "?,?,?"  for  WHERE id IN ($in)
 *
 * Every value is bound - never concatenate user input into $sql.
 * Compatible with PHP 7.2+.
 */

if (!function_exists('mh_pdo')) {
    /** Lazily creates the shared PDO connection. Returns null (and logs) if it cannot connect. */
    function mh_pdo()
    {
        static $pdo = false;
        if ($pdo !== false) {
            return $pdo;
        }
        $pdo = null;

        if (!extension_loaded('pdo_mysql')) {
            error_log('[MarickHealth] pdo_mysql extension is not enabled.');
            return $pdo;
        }

        // config/database.php defines $host/$user/$password/$database (and $conn). Load it once.
        global $host, $user, $password, $database;
        if (!isset($host, $user, $database)) {
            require_once __DIR__ . '/../config/database.php';
        }

        try {
            $pdo = new PDO(
                'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4',
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,   // real server-side prepared statements
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('[MarickHealth] PDO connection failed: ' . $e->getMessage());   // never shown to visitors
            $pdo = null;
        }
        return $pdo;
    }
}

if (!function_exists('mh_db_run')) {
    /** Prepare + execute. Returns the PDOStatement, or null on any failure (logged, never printed). */
    function mh_db_run($sql, array $params = [])
    {
        $pdo = mh_pdo();
        if (!$pdo) {
            return null;
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log('[MarickHealth] SQL error: ' . $e->getMessage() . ' | ' . preg_replace('/\s+/', ' ', $sql));
            return null;
        }
    }
}

if (!function_exists('mh_db_all')) {
    function mh_db_all($sql, array $params = [])
    {
        $stmt = mh_db_run($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }
}

if (!function_exists('mh_db_one')) {
    /** First row or null. */
    function mh_db_one($sql, array $params = [])
    {
        $stmt = mh_db_run($sql, $params);
        $row  = $stmt ? $stmt->fetch() : false;
        return $row === false ? null : $row;
    }
}

if (!function_exists('mh_db_value')) {
    /** First column of the first row (COUNT(*), SUM(...)); $default when there is no row / on error. */
    function mh_db_value($sql, array $params = [], $default = 0)
    {
        $stmt = mh_db_run($sql, $params);
        if (!$stmt) {
            return $default;
        }
        $v = $stmt->fetchColumn();
        return $v === false ? $default : $v;
    }
}

if (!function_exists('mh_db_exec')) {
    /** INSERT/UPDATE/DELETE. Returns affected rows (int) or false on failure. */
    function mh_db_exec($sql, array $params = [])
    {
        $stmt = mh_db_run($sql, $params);
        return $stmt ? $stmt->rowCount() : false;
    }
}

if (!function_exists('mh_db_in')) {
    /** Placeholders for an IN() list: returns ['?,?,?', [values...]]. An empty list yields 'NULL' (matches nothing). */
    function mh_db_in(array $values)
    {
        $values = array_values($values);
        if (!$values) {
            return ['NULL', []];
        }
        return [implode(',', array_fill(0, count($values), '?')), $values];
    }
}

if (!function_exists('mh_db_table_has_column')) {
    /**
     * Schema probe, cached for the whole request (the dashboard used to run the same
     * SHOW COLUMNS query once per widget on every page load).
     */
    function mh_db_table_has_column($table, $column)
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (!isset($cache[$key])) {
            $cache[$key] = (int) mh_db_value(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            ) > 0;
        }
        return $cache[$key];
    }
}

if (!function_exists('mh_db_table_exists')) {
    function mh_db_table_exists($table)
    {
        static $cache = [];
        $key = strtolower($table);
        if (!isset($cache[$key])) {
            $cache[$key] = (int) mh_db_value(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            ) > 0;
        }
        return $cache[$key];
    }
}
