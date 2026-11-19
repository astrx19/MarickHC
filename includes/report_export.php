<?php
if (!function_exists('mh_report_csv_export')) {
    function mh_report_csv_export($filename, $title, $subtitle = '')
    {
        $level = ob_get_level() + 1;
        ob_start();
        register_shutdown_function(function () use ($level, $filename, $title, $subtitle) {
            $html = '';
            while (ob_get_level() >= $level) { $html = (string) ob_get_clean() . $html; }

            $in = "[ancestor::*[contains(@class,'unified-panel') or contains(@class,'ds-stats') or contains(@class,'mr-report')]]";
            $query = "//h2$in | //h3$in | //h4$in | //table$in | //div[contains(@class,'mr-kpi')]$in | //div[contains(@class,'ds-bar')]$in | //div[contains(@class,'ds-stat')]$in";

            $clean = function ($s) { return trim(preg_replace('/\s+/u', ' ', (string) $s)); };
            $rows = [];
            $prev = libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $xp = new DOMXPath($dom);
            foreach ($xp->query($query) as $n) {
                $tag = strtolower($n->nodeName);
                $cls = $n instanceof DOMElement ? $n->getAttribute('class') : '';
                if (in_array($tag, ['h2', 'h3', 'h4'], true)) {
                    if ($xp->query("ancestor::table | ancestor::*[contains(@class,'ds-ai-card')]", $n)->length) { continue; }
                    $t = $clean($n->textContent);
                    if ($t !== '') { $rows[] = []; $rows[] = [$t]; }
                } elseif ($tag === 'table') {
                    foreach ($xp->query('.//tr', $n) as $tr) {
                        $cells = [];
                        foreach ($xp->query('./th|./td', $tr) as $c) { $cells[] = $clean($c->textContent); }
                        if (count($cells) === 1 && stripos($cells[0], 'No records') === 0) { $cells = ['(no records for this period)']; }
                        $rows[] = $cells;
                    }
                } else {
                    $cells = [];
                    foreach ($n->childNodes as $c) {
                        if ($c instanceof DOMElement && $c->getAttribute('class') !== '' && stripos($c->getAttribute('class'), 'bar') !== false && strpos($cls, 'ds-bar') !== false) { continue; }
                        if ($c instanceof DOMElement) { $t = $clean($c->textContent); if ($t !== '') { $cells[] = $t; } }
                    }
                    if (!$cells) { $t = $clean($n->textContent); if ($t !== '') { $cells[] = $t; } }
                    if ($cells) { $rows[] = $cells; }
                }
            }

            while (ob_get_level() > 0) { @ob_end_clean(); }
            if (!headers_sent()) {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
                header('Cache-Control: no-store');
            }
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$title]);
            if ($subtitle !== '') { fputcsv($out, [$subtitle]); }
            fputcsv($out, ['Generated: ' . date('Y-m-d H:i')]);
            foreach ($rows as $r) { fputcsv($out, $r); }
            fclose($out);
        });
    }
}
