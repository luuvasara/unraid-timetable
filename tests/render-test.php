<?php
/* Renders both tabs from the example data and checks the HTML: well-formed, every finding has a reason and an
 * example, links are relative Unraid pages, nothing unescaped from the files. Writes tests/.work/render-*.html for a
 * look in a browser. Run: php tests/render-test.php */
date_default_timezone_set('Europe/Helsinki');
$P = dirname(__DIR__) . '/src/usr/local/emhttp/plugins/unraid-timetable';
require_once "$P/include/Render.php";
$fail = 0; $n = 0;
function ok(bool $c, string $what, $got = null): void
{
    global $fail, $n; $n++;
    if ($c) return;
    $fail++;
    echo "FAIL $what" . ($got === null ? '' : ' -> ' . json_encode($got, JSON_UNESCAPED_SLASHES)) . "\n";
}
// a docroot with the plugin pages the links look for
$doc = dirname(__DIR__) . '/tests/.work/docroot';
foreach (['dynamix/ParityCheck.page' => 'Menu="Scheduler:1"', 'dynamix/MoverSettings.page' => 'Menu="Scheduler:2"', 'dynamix/TrimSettings.page' => 'Menu="Scheduler"',
          'parity.check.tuning/Parity.check.tuning.page' => 'Menu="Scheduler:1b"', 'user.scripts/Userscripts.page' => 'Menu="Utilities"', 'appdata.backup/AB.Main.page' => 'Menu="Utilities"'] as $f => $menu) {
    @mkdir(dirname("$doc/plugins/$f"), 0755, true);
    file_put_contents("$doc/plugins/$f", "$menu\nTitle=\"x\"\n---\n");
}
$v = new TtView($doc, ['time' => '%R']);
$m = $v->model(dirname(__DIR__) . '/tests/fixtures/server', strtotime('2026-12-06 02:30'));   // the first Sunday of December
$html = ['timeline' => '<div class="tt" id="tt-timeline">' . $v->timeline() . $v->offClock() . '</div>', 'list' => '<div class="tt" id="tt-list">' . $v->listView() . '</div>'];
foreach ($html as $k => $h) {
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $okParse = $d->loadHTML('<!doctype html><meta charset="utf-8"><body>' . $h);
    $errs = array_filter(libxml_get_errors(), fn($e) => $e->level >= LIBXML_ERR_ERROR && !str_contains($e->message, 'Tag section invalid'));
    libxml_clear_errors();
    ok($okParse && !$errs, "$k renders as valid HTML", array_map(fn($e) => trim($e->message), $errs));
    file_put_contents(dirname(__DIR__) . "/tests/.work/render-$k.html", $h);
}
$t = $html['timeline'];
ok(substr_count($t, 'class="tt-day"') === 7, 'seven day sections');
ok(str_contains($t, 'Parity check and Mover overlap'), 'overlap band named in the chart', null);
ok(str_contains($t, 'tt-now'), 'now line on today');
ok(preg_match('/data-tab="tab3"[^>]*>Mover/', $t) === 1 || str_contains($t, 'data-tab="tab3"'), 'mover link opens the mover tab (third: Parity Check Tuning adds one before it)');
ok(str_contains($t, 'User script: Nightly photo sync, ') && !str_contains($t, 'User script: scripts'), 'bar tooltip names the script with spaces');
ok(str_contains($t, 'User script: Nightly photo sync (Copies new photos to the archive)') && str_contains($t, 'title="User script: Nightly photo sync: Copies new photos to the archive"'), 'description in bar and row tooltips');
ok(str_contains($t, 'Quoted &lt;b&gt;path&lt;/b&gt; job') && !str_contains($t, 'Quoted <b>'), 'description is escaped');
ok(str_contains($html['list'], 'User script: Nightly photo sync') && str_contains($html['list'], 'Copies new photos to the archive'), 'list tab shows name and description');
ok(str_contains($t, 'User script: Nightly photo sync, ') , 'folded strip / grid names');
ok(str_contains($t, 'User script: Nightly photo sync') , 'name present');
ok(str_contains($html['list'], 'Balance, pool ssd (btrfs)') && str_contains($html['list'], 'only data blocks under 50% full') && str_contains($html['list'], 'href="/Main/Device?name=ssd"'), 'pool maintenance named, described and linked');
ok(str_contains($html['list'], 'Pool maintenance') || str_contains($t, 'Pool maintenance'), 'pool maintenance legend/category');
ok(str_contains($t, 'href="/Settings/Userscripts"'), 'User Scripts link when the plugin is there');
ok(!str_contains($t, '/Settings/AutoUpdateApps'), 'no link to a plugin page that is not installed');
ok(str_contains($t, 'Later this month'), 'later runs listed');
ok(preg_match_all('/class="tt-f tt-f-/', $t) >= 5, 'findings listed', preg_match_all('/class="tt-f tt-f-/', $t));
$l = $html['list'];
ok(str_contains($l, '/boot/config/plugins/dynamix/parity-check.cron:2'), 'source file and line in the list');
ok(str_contains($l, 'Next 10 runs'), 'next runs');
ok(substr_count($l, '<tr class="tt-row-') >= 15, 'every job has a row', substr_count($l, '<tr class="tt-row-'));
// escaping: a hostile script name in the example data must come out as text
$v2 = new TtView($doc, []);
$r = new ReflectionProperty(TtView::class, 'model'); $r->setValue(null, null);
$bad = sys_get_temp_dir() . '/tt-bad'; @mkdir("$bad/etc/cron.d", 0755, true);
file_put_contents("$bad/etc/cron.d/root", "0 1 * * * /opt/x\"<img\x20src=x\x20onerror=1>.sh\n0 2 * * * /opt/a&b\"<i>.sh\n");
$v2->model($bad, strtotime('2026-12-06 00:30'));
$x = $v2->timeline() . $v2->listView();
ok(!str_contains($x, '<img') && !str_contains($x, '<i>.sh') && str_contains($x, 'a&amp;b&quot;&lt;i'), 'text from files is escaped', substr($x, 0, 0));
echo ($fail ? "FAIL $fail of $n" : "ok   render: $n checks") . "\n";
exit($fail ? 1 : 0);
