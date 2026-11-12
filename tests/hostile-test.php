<?php
/* Hostile input: files that other plugins and users write can hold anything. Renders tests/fixtures/hostile (markup and
 * quotes in a script name, description, container names and a pool path, control characters, wrong JSON types, a very
 * long name) plus an oversized log made at run time, and checks that the output is inert: escaped text, only fixed
 * internal links, no control characters, no PHP warnings, capped lengths and bounded memory.
 * Run: php tests/hostile-test.php */
date_default_timezone_set('Europe/Helsinki');
$P = dirname(__DIR__) . '/src/usr/local/emhttp/plugins/unraid-timetable';
require_once "$P/include/Render.php";
$fail = 0; $n = 0;
function ok(bool $c, string $what, $got = null): void
{
    global $fail, $n; $n++;
    if ($c) return;
    $fail++;
    echo "FAIL $what" . ($got === null ? '' : ' -> ' . substr(json_encode($got, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 600)) . "\n";
}
$warnings = [];
set_error_handler(function ($no, $str, $file, $line) use (&$warnings) { if (error_reporting() & $no) $warnings[] = "$str ($file:$line)"; return true; });   // @ marks an expected miss

// a copy of the fixture with an oversized Appdata Backup log and User Scripts log (12 MB each)
$root = sys_get_temp_dir() . '/tt-hostile-' . getmypid();
exec('cp -a ' . escapeshellarg(__DIR__ . '/fixtures/hostile') . ' ' . escapeshellarg($root));
@mkdir("$root/tmp/appdata.backup", 0755, true);
$h = fopen("$root/tmp/appdata.backup/ab.log", 'w');
fwrite($h, "[08.11.2026 03:00:00][info] WELCOME TO APPDATA.BACKUP!!\n");
$chunk = str_repeat("[08.11.2026 03:00:01] x\n", 4096);
for ($i = 0; $i < 12 * 1024 * 1024 / strlen($chunk); $i++) fwrite($h, $chunk);
fwrite($h, "[08.11.2026 03:40:00][info] DONE! Thanks for using this plugin and have a safe day\n");
fclose($h);
copy("$root/tmp/appdata.backup/ab.log", "$root/tmp/user.scripts/tmpScripts/evil/log.txt");

$doc = dirname(__DIR__) . '/tests/.work/docroot-hostile';
foreach (['user.scripts/Userscripts.page' => 'Menu="Utilities"', 'appdata.backup/AB.Main.page' => 'Menu="Utilities"'] as $f => $menu) {
    @mkdir(dirname("$doc/plugins/$f"), 0755, true);
    file_put_contents("$doc/plugins/$f", "$menu\nTitle=\"x\"\n---\n");
}
$before = memory_get_peak_usage();
$v = new TtView($doc, ['time' => '%R']);
$m = $v->model($root, strtotime('2026-11-09 00:30'));
$html = $v->timeline() . $v->offClock() . $v->listView();
$peak = memory_get_peak_usage() - $before;
exec('rm -rf ' . escapeshellarg($root));
restore_error_handler();

ok($warnings === [], 'no PHP warnings or notices from wrong types or odd text', $warnings);
ok($peak < 40 * 1024 * 1024, 'a 12 MB log is read in bounded memory (' . round($peak / 1048576) . ' MB)', $peak);
// markup from the files comes out as text
foreach (['<script', '<img', '<svg', 'onmouseover="'] as $bad) ok(!str_contains($html, $bad), "no raw \"$bad\" in the page");
ok(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;&quot; onmouseover=&quot;alert(2)'), 'the script name shows as escaped text');
ok(str_contains($html, '&lt;script&gt;alert(4)&lt;/script&gt;'), 'a container name shows as escaped text');
// every attribute value stays inside its quotes: parse the page and look at the attributes
$d = new DOMDocument();
libxml_use_internal_errors(true);
$d->loadHTML('<!doctype html><meta charset="utf-8"><body>' . $html);
libxml_clear_errors();
$attrs = [];
foreach ((new DOMXPath($d))->query('//@*') as $a) $attrs[$a->nodeName] = true;
ok(!array_filter(array_keys($attrs), fn($k) => str_starts_with($k, 'on')), 'no event-handler attribute in the page', array_keys($attrs));
// links: only fixed internal pages, query values encoded
$hrefs = [];
foreach ($d->getElementsByTagName('a') as $a) $hrefs[] = $a->getAttribute('href');
$badHref = array_filter($hrefs, fn($u) => !preg_match('#^/(Settings|Main|Utilities)(/[A-Za-z0-9._-]+)*(\?name=[A-Za-z0-9%._~-]+)?$#', $u));
ok($hrefs && !$badHref, 'every link is an allowed internal path', array_values($badHref));
ok(!preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html), 'no control characters in the output');
// lengths
$titles = array_column($m['jobs'], 'title');
ok(max(array_map('mb_strlen', $titles)) <= 160, 'titles are capped', array_map('mb_strlen', $titles));
foreach ($m['jobs'] as $j) if (isset($j['description'])) ok(mb_strlen($j['description']) <= 300, 'description capped');
// wrong JSON types are skipped, the rest still works
$ab = array_values(array_filter($m['jobs'], fn($j) => $j['category'] === 'backup'))[0] ?? null;
ok($ab && is_array($ab['stops'] ?? null) && in_array('<script>alert(4)</script>', $ab['stops'], true) && !in_array('bad', $ab['stops'], true), 'container entries of the wrong type are ignored, good ones kept', $ab['stops'] ?? null);
ok(count(array_filter($m['jobs'], fn($j) => ($j['schedule']['type'] ?? '') === 'event')) === 0, 'User Scripts entries of the wrong type are ignored');
echo ($fail ? "FAIL $fail of $n" : "ok   hostile: $n checks") . "\n";
exit($fail ? 1 : 0);
