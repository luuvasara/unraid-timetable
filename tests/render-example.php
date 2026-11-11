<?php
/* Prints the Timeline and List HTML for the example data in tests/fixtures/server as JSON, for README screenshots.
 * Run: php tests/render-example.php [Y-m-d H:i]  (default: the first Sunday of December 2026, 02:30) */
date_default_timezone_set('Europe/Helsinki');
require_once dirname(__DIR__) . '/src/usr/local/emhttp/plugins/unraid-timetable/include/Render.php';
$doc = dirname(__DIR__) . '/tests/.work/docroot-example';
foreach (['dynamix/ParityCheck.page' => 'Menu="Scheduler:1"', 'dynamix/MoverSettings.page' => 'Menu="Scheduler:2"', 'dynamix/TrimSettings.page' => 'Menu="Scheduler"',
          'parity.check.tuning/Parity.check.tuning.page' => 'Menu="Scheduler:1b"', 'user.scripts/Userscripts.page' => 'Menu="Utilities"',
          'appdata.backup/AB.Main.page' => 'Menu="Utilities"', 'ca.update.applications/AutoUpdateApps.page' => 'Menu="Utilities"',
          'fix.common.problems/FixProblems.page' => 'Menu="Utilities"', 'dynamix.s3.sleep/SleepSettings.page' => 'Menu="Utilities"'] as $f => $menu) {
    @mkdir(dirname("$doc/plugins/$f"), 0755, true);
    file_put_contents("$doc/plugins/$f", "$menu\nTitle=\"x\"\n---\n");
}
$v = new TtView($doc, ['time' => '%R']);
$v->model(dirname(__DIR__) . '/tests/fixtures/server', strtotime($argv[1] ?? '2026-12-06 02:30'));
echo json_encode(['timeline' => $v->timeline() . $v->offClock(), 'list' => $v->listView()]);
