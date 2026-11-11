<?php

if (!function_exists('mh_pg_search')) {

    function mh_pg_search(array $columns, $term)
    {
        $term = trim((string) $term);
        if ($term === '' || !$columns) { return ['sql' => '', 'types' => '', 'params' => []]; }
        $like = '%' . addcslashes(mb_substr($term, 0, 80), '%_\\') . '%';
        $parts = [];
        foreach ($columns as $col) { $parts[] = $col . ' LIKE ?'; }
        return ['sql' => '(' . implode(' OR ', $parts) . ')', 'types' => str_repeat('s', count($columns)), 'params' => array_fill(0, count($columns), $like)];
    }
}

if (!function_exists('mh_pg_state')) {

    function mh_pg_state($total, array $opts = [])
    {
        $allowed = $opts['sizes'] ?? [10, 25, 50, 100];
        $default = (int) ($opts['per_page'] ?? 25);
        $perPage = (int) ($_GET[$opts['size_param'] ?? 'per_page'] ?? $default);
        if (!in_array($perPage, $allowed, true)) { $perPage = in_array($default, $allowed, true) ? $default : $allowed[0]; }
        $total = max(0, (int) $total);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($pages, max(1, (int) ($_GET[$opts['param'] ?? 'page'] ?? 1)));
        $offset = ($page - 1) * $perPage;
        return [
            'page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'total' => $total, 'offset' => $offset,
            'from' => $total === 0 ? 0 : $offset + 1, 'to' => min($total, $offset + $perPage),
            'param' => $opts['param'] ?? 'page', 'size_param' => $opts['size_param'] ?? 'per_page', 'sizes' => $allowed,
        ];
    }
}

if (!function_exists('mh_pg_fetch')) {

    function mh_pg_fetch(mysqli $conn, $select, $from, $orderBy, $types = '', array $params = [], array $opts = [])
    {
        $st = $conn->prepare('SELECT COUNT(*) AS n FROM ' . $from);
        if (!$st) { return ['rows' => [], 'pg' => mh_pg_state(0, $opts)]; }
        if ($types !== '') { $st->bind_param($types, ...$params); }
        $st->execute();
        $total = (int) ($st->get_result()->fetch_assoc()['n'] ?? 0);
        $st->close();

        $pg = mh_pg_state($total, $opts);
        $sql = 'SELECT ' . $select . ' FROM ' . $from . ' ORDER BY ' . $orderBy . ' LIMIT ' . (int) $pg['per_page'] . ' OFFSET ' . (int) $pg['offset'];
        $st = $conn->prepare($sql);
        if (!$st) { return ['rows' => [], 'pg' => $pg]; }
        if ($types !== '') { $st->bind_param($types, ...$params); }
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return ['rows' => $rows, 'pg' => $pg];
    }
}

if (!function_exists('mh_pg_url')) {

    function mh_pg_url(array $pg, $page, array $set = [])
    {
        $q = $_GET;
        unset($q['walkin_id'], $q['apt_id']);
        foreach ($set as $k => $v) { $q[$k] = $v; }
        if ($page > 1) { $q[$pg['param']] = $page; } else { unset($q[$pg['param']]); }
        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        return $path . ($q ? '?' . http_build_query($q) : '');
    }
}

if (!function_exists('mh_pg_window')) {

    function mh_pg_window($page, $pages, $span = 1)
    {
        $keep = [1, $pages];
        for ($i = $page - $span; $i <= $page + $span; $i++) { if ($i >= 1 && $i <= $pages) { $keep[] = $i; } }
        $keep = array_values(array_unique($keep));
        sort($keep);
        $out = [];
        $prev = 0;
        foreach ($keep as $n) {
            if ($n - $prev > 1) { $out[] = null; }
            $out[] = $n;
            $prev = $n;
        }
        return $out;
    }
}

if (!function_exists('mh_pg_render')) {

    function mh_pg_render(array $pg, array $opts = [])
    {
        static $assets = false;
        if (!$assets) {
            $assets = true;
            echo '<link rel="stylesheet" href="../assets/css/pagination.css?v=' . (int) @filemtime(__DIR__ . '/../assets/css/pagination.css') . '">' . "\n";
        }
        $noun = $opts['noun'] ?? 'records';
        $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        if ($pg['total'] === 0) { return; }
        ?>
        <nav class="mh-pg" aria-label="Pagination">
            <div class="mh-pg-info">Showing <strong><?php echo (int) $pg['from']; ?></strong>-<strong><?php echo (int) $pg['to']; ?></strong> of <strong><?php echo (int) $pg['total']; ?></strong> <?php echo $e($noun); ?></div>
            <?php if ($pg['pages'] > 1): ?>
            <ul class="mh-pg-list">
                <li><?php if ($pg['page'] > 1): ?><a class="mh-pg-link mh-pg-step" href="<?php echo $e(mh_pg_url($pg, $pg['page'] - 1)); ?>" rel="prev">Previous</a><?php else: ?><span class="mh-pg-link mh-pg-step is-disabled">Previous</span><?php endif; ?></li>
                <?php foreach (mh_pg_window($pg['page'], $pg['pages']) as $n): ?>
                    <?php if ($n === null): ?>
                    <li class="mh-pg-gap"><span class="mh-pg-link is-gap">&hellip;</span></li>
                    <?php elseif ($n === $pg['page']): ?>
                    <li class="mh-pg-num"><span class="mh-pg-link is-current" aria-current="page"><?php echo (int) $n; ?></span></li>
                    <?php else: ?>
                    <li class="mh-pg-num"><a class="mh-pg-link" href="<?php echo $e(mh_pg_url($pg, $n)); ?>"><?php echo (int) $n; ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="mh-pg-pos"><span class="mh-pg-link is-current">Page <?php echo (int) $pg['page']; ?> / <?php echo (int) $pg['pages']; ?></span></li>
                <li><?php if ($pg['page'] < $pg['pages']): ?><a class="mh-pg-link mh-pg-step" href="<?php echo $e(mh_pg_url($pg, $pg['page'] + 1)); ?>" rel="next">Next</a><?php else: ?><span class="mh-pg-link mh-pg-step is-disabled">Next</span><?php endif; ?></li>
            </ul>
            <?php endif; ?>
            <form class="mh-pg-size" method="get">
                <?php foreach ($_GET as $k => $v): if (in_array($k, [$pg['param'], $pg['size_param'], 'walkin_id', 'apt_id'], true) || !is_scalar($v)) { continue; } ?>
                <input type="hidden" name="<?php echo $e($k); ?>" value="<?php echo $e($v); ?>">
                <?php endforeach; ?>
                <label>Rows
                    <select name="<?php echo $e($pg['size_param']); ?>" onchange="this.form.submit()">
                        <?php foreach ($pg['sizes'] as $s): ?>
                        <option value="<?php echo (int) $s; ?>"<?php echo $s === $pg['per_page'] ? ' selected' : ''; ?>><?php echo (int) $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <noscript><button type="submit">Go</button></noscript>
            </form>
        </nav>
        <?php
    }
}
