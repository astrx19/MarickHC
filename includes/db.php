<?php

if (!function_exists('mh_pdo')) {
    function mh_pdo()
    {
        static $pdo = false;
        if ($pdo !== false) {
            return $pdo;
        }
        $pdo = null;
        $GLOBALS['mh_db_driver'] = 'mysqli (PDO unavailable)';

        if (!extension_loaded('pdo_mysql')) {
            error_log('[MarickHealth] pdo_mysql extension is not enabled - using the mysqli fallback.');
            $GLOBALS['mh_db_driver_error'] = 'pdo_mysql extension is not enabled';
            return $pdo;
        }

        global $host, $user, $password, $database;
        if (!isset($host, $user, $database)) {
            require_once __DIR__ . '/../config/database.php';
        }

        $dsns = [];
        $socket = (string) (ini_get('mysqli.default_socket') ?: ini_get('pdo_mysql.default_socket'));
        if (($host === 'localhost' || $host === '') && $socket !== '' && @file_exists($socket)) {
            $dsns['socket ' . $socket] = 'mysql:unix_socket=' . $socket . ';dbname=' . $database . ';charset=utf8mb4';
        }
        $dsns['host ' . $host] = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4';
        if ($host === 'localhost') {
            $dsns['tcp 127.0.0.1'] = 'mysql:host=127.0.0.1;port=3306;dbname=' . $database . ';charset=utf8mb4';
        }

        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];
        if (!empty($GLOBALS['mh_db_utc_offset']) && defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET time_zone = '" . $GLOBALS['mh_db_utc_offset'] . "'";
        }
        $errors = [];
        foreach ($dsns as $label => $dsn) {
            try {
                $pdo = new PDO($dsn, $user, $password, $opts);
                $GLOBALS['mh_db_driver'] = 'PDO (' . $label . ')';
                unset($GLOBALS['mh_db_driver_error']);
                return $pdo;
            } catch (PDOException $e) {
                $errors[] = $label . ': ' . $e->getMessage();
            }
        }
        $pdo = null;
        $GLOBALS['mh_db_driver_error'] = implode(' | ', $errors);
        error_log('[MarickHealth] PDO connection failed (' . $GLOBALS['mh_db_driver_error'] . ') - using the mysqli fallback.');
        return $pdo;
    }
}

if (!class_exists('MhDbResult')) {
    class MhDbResult
    {
        private $rows;
        private $affected;
        private $pos = 0;

        public function __construct(array $rows, $affected)
        {
            $this->rows = $rows;
            $this->affected = (int) $affected;
        }
        public function fetchAll() { return $this->rows; }
        public function fetch()
        {
            return isset($this->rows[$this->pos]) ? $this->rows[$this->pos++] : false;
        }
        public function fetchColumn()
        {
            $row = $this->fetch();
            return $row === false ? false : reset($row);
        }
        public function rowCount() { return $this->affected; }
    }
}

if (!function_exists('mh_db_run_mysqli')) {
    function mh_db_run_mysqli($sql, array $params = [])
    {
        global $conn;
        if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
            return null;
        }
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log('[MarickHealth] SQL prepare error: ' . $conn->error . ' | ' . preg_replace('/\s+/', ' ', $sql));
                return null;
            }
            if ($params) {
                $params = array_values($params);
                $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            }
            $stmt->execute();
            $res  = $stmt->get_result();
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $out  = new MhDbResult($rows, $stmt->affected_rows);
            $stmt->close();
            return $out;
        } catch (Throwable $e) {
            error_log('[MarickHealth] SQL error (mysqli fallback): ' . $e->getMessage() . ' | ' . preg_replace('/\s+/', ' ', $sql));
            return null;
        }
    }
}

if (!function_exists('mh_db_run')) {
    function mh_db_run($sql, array $params = [])
    {
        $pdo = mh_pdo();
        if (!$pdo) {
            return mh_db_run_mysqli($sql, $params);
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $GLOBALS['mh_db_last_error'] = $e->getMessage();
            error_log('[MarickHealth] SQL error: ' . $e->getMessage() . ' | ' . preg_replace('/\s+/', ' ', $sql));
            return null;
        }
    }
}

if (!function_exists('mh_db_driver')) {
    function mh_db_driver()
    {
        mh_pdo();
        return [
            'driver' => $GLOBALS['mh_db_driver'] ?? 'unknown',
            'error'  => $GLOBALS['mh_db_driver_error'] ?? null,
            'last_sql_error' => $GLOBALS['mh_db_last_error'] ?? null,
        ];
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
    function mh_db_one($sql, array $params = [])
    {
        $stmt = mh_db_run($sql, $params);
        $row  = $stmt ? $stmt->fetch() : false;
        return $row === false ? null : $row;
    }
}

if (!function_exists('mh_db_value')) {
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
    function mh_db_exec($sql, array $params = [])
    {
        $stmt = mh_db_run($sql, $params);
        return $stmt ? $stmt->rowCount() : false;
    }
}

if (!function_exists('mh_db_in')) {
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
