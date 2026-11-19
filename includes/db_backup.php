<?php
if (!function_exists('mh_db_dump')) {

    function mh_db_dump(mysqli $conn, callable $emit)
    {
        $emit("-- Marick Health Center database backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = [];
        $res = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($res && ($r = $res->fetch_row())) { $tables[] = $r[0]; }
        $count = 0;
        foreach ($tables as $t) {
            $q = '`' . str_replace('`', '``', $t) . '`';
            $cr = $conn->query("SHOW CREATE TABLE $q");
            $row = $cr ? $cr->fetch_row() : null;
            if (!$row) { continue; }
            $emit("DROP TABLE IF EXISTS $q;\n" . $row[1] . ";\n\n");

            $rs = $conn->query("SELECT * FROM $q", MYSQLI_USE_RESULT);
            if (!$rs) { continue; }
            $fields = []; foreach ($rs->fetch_fields() as $f) { $fields[] = '`' . str_replace('`', '``', $f->name) . '`'; }
            $head = "INSERT INTO $q (" . implode(',', $fields) . ") VALUES\n";
            $buf = []; $n = 0;
            while ($data = $rs->fetch_row()) {
                $vals = [];
                foreach ($data as $v) { $vals[] = $v === null ? 'NULL' : "'" . $conn->real_escape_string((string) $v) . "'"; }
                $buf[] = '(' . implode(',', $vals) . ')';
                if (++$n % 200 === 0) { $emit($head . implode(",\n", $buf) . ";\n"); $buf = []; }
            }
            if ($buf) { $emit($head . implode(",\n", $buf) . ";\n"); }
            $rs->free();
            $emit("\n");
            $count++;
        }
        $emit("SET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n");
        return $count;
    }

    function mh_db_backup_to_file(mysqli $conn, $keepDays = 14)
    {
        $dir = __DIR__ . '/../storage/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) { throw new RuntimeException('Cannot create ' . $dir); }
        $gz   = function_exists('gzopen');
        $path = $dir . '/marick_backup_' . date('Ymd_His') . ($gz ? '.sql.gz' : '.sql');
        $fh   = $gz ? gzopen($path, 'wb9') : fopen($path, 'wb');
        if (!$fh) { throw new RuntimeException('Cannot write ' . $path); }
        $w = $gz ? 'gzwrite' : 'fwrite';
        mh_db_dump($conn, function ($s) use ($fh, $w) { $w($fh, $s); });
        $gz ? gzclose($fh) : fclose($fh);
        foreach (glob($dir . '/marick_backup_*') ?: [] as $f) {
            if (filemtime($f) < time() - $keepDays * 86400) { @unlink($f); }
        }
        return $path;
    }
}
