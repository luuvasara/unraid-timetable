<?php
/* Collector test against tests/fixtures/server, example data laid out like a server's files.
 * Run: php tests/collect-test.php  (exit 0 = all passed). */
date_default_timezone_set('Europe/Helsinki');
$P = dirname(__DIR__) . '/src/usr/local/emhttp/plugins/unraid-timetable/include';
require_once "$P/Collect.php";
require_once "$P/Analyse.php";

$fail = 0; $n = 0;
function ok(bool $c, string $what, $got = null): void
{
    global $fail, $n; $n++;
    if ($c) return;
    $fail++;
    echo "FAIL $what" . ($got === null ? '' : ' -> ' . json_encode($got, JSON_UNESCAPED_SLASHES)) . "\n";
}
$now = strtotime('2026-11-09 00:00');   // a Monday
$c = (new TtCollect(__DIR__ . '/fixtures/server', $now))->collect();
$jobs = $c['jobs'];
TtAnalyse::prepare($jobs);
$by = [];
foreach ($jobs as $j) $by[$j['title']] = $j;
$titles = array_keys($by);

foreach (['Parity check', 'Mover', 'TRIM', 'System monitor (notifications)', 'Unraid OS update check', 'User script: nightly-rsync',
          'User script: Note array start', 'User script: daily-report', 'Appdata backup', 'Docker auto update',
          'Parity check, increment (Parity Check Tuning)', 'Parity Check Tuning monitor', 'Log rotation', 'Fix Common Problems scan', 'S3 Sleep',
          'hand-added-backup.sh'] as $t) ok(isset($by[$t]), "collects \"$t\"", $titles);
ok(!isset($by['/usr/local/bin/my-own-thing --quiet']), 'a .cron file that is not in the live crontab is not a job (update_cron did not load it)');

$pc = $by['Parity check'];
ok($pc['source']['file'] === '/boot/config/plugins/dynamix/parity-check.cron' && $pc['source']['line'] === 2, 'parity attributed to its .cron file and line', $pc['source']);
ok(($pc['schedule']['guard']['kind'] ?? '') === 'week' && $pc['schedule']['text'] === 'on Sundays in the first week of the month at 01:00', 'parity first-Sunday guard read', $pc['schedule']['text']);
ok($pc['duration']['basis'] === 'history' && $pc['duration']['samples'] === 2, 'parity duration from 2 finished checks (the cancelled one skipped)', $pc['duration']);
ok($pc['duration']['sec'] === 23400 && str_contains($pc['duration']['note'], 'pauses at 07:30'), 'Parity Check Tuning pause at 07:30 caps a 01:00 check at 6 h 30 min', $pc['duration']);
$inc = $by['Parity check, increment (Parity Check Tuning)'];
ok($inc['duration']['sec'] === (7 * 60 + 15) * 60 && $inc['duration']['basis'] === 'config', 'increment window 00:15-07:30', $inc['duration']);
ok(!empty($inc['conditional']), 'increment window is conditional');
ok(!empty($by['Parity check: pause (Parity Check Tuning)']) === false, 'pause line is folded into the window, not a job of its own', $titles);

$mv = $by['Mover'];
ok($mv['duration']['basis'] === 'history' && $mv['duration']['sec'] === 1905, 'mover median of 42.5 and 21 min from syslog', $mv['duration']);
ok(in_array('needs_array', $mv['impact'], true), 'mover needs the array');
ok($mv['link'] === '/Settings/Scheduler' && $mv['tab'] === 'tab2', 'mover links to its Scheduler tab');

$ab = $by['Appdata backup'];
ok(in_array('stops_containers', $ab['impact'], true) && in_array('array_io', $ab['impact'], true), 'appdata backup stops containers and writes to the array', $ab['impact']);
ok(str_contains($ab['note'], 'Stops 1 of the 3 containers') && str_contains($ab['note'], '/mnt/user/backups/appdata'), 'appdata note', $ab['note']);
ok($ab['duration']['sec'] === 3130 && $ab['duration']['basis'] === 'history', 'appdata duration from ab.log', $ab['duration']);

$us = $by['User script: nightly-rsync'];
ok($us['duration']['sec'] === 1530 && $us['duration']['basis'] === 'history', 'user script duration from its log', $us['duration']);
ok($us['source']['file'] === '/boot/config/plugins/user.scripts/customSchedule.cron', 'user script source', $us['source']);
ok($by['User script: Note array start']['schedule']['type'] === 'event' && $by['User script: Note array start']['schedule']['event'] === 'start', 'array-start script is an event');
ok($by['User script: daily-report']['schedule']['expr'] === '40 4 * * *' && $by['User script: daily-report']['source']['via'] === '/etc/cron.daily/user.script.start.daily.sh', 'daily user script runs at run-parts daily time', $by['User script: daily-report']);
ok($by['Fix Common Problems scan']['schedule']['expr'] === '40 4 * * *', 'FCP daily scan at 04:40');
$other = $by['hand-added-backup.sh'];
ok($other['owner'] === 'Other (from /etc/cron.d/root)' && $other['source']['file'] === '/etc/cron.d/root', 'unknown line is Other with its file', $other);
$ex = array_values(array_filter($jobs, fn($j) => ($j['source']['file'] ?? '') === '/etc/cron.d/example.plugin'));
ok($ex && str_contains($ex[0]['note'] ?? '', 'no user field'), 'a user field in another /etc/cron.d file is pointed out', $ex);
ok($by['System monitor (notifications)']['frequent'] === true, 'monitor is background work');
ok($c['context']['startArray'] === 'no' && $c['context']['spindownDelay'] === '30' && $c['context']['timeZone'] === 'Europe/Helsinki', 'context', $c['context']);

$ws = TtAnalyse::warnings($jobs, $now, 35, $c['context']);
$keys = [];
foreach ($ws as $w) $keys[] = $w['rule'] . ':' . implode(',', $w['jobs']);
$has = fn(string $rule, string ...$ids) => (bool)array_filter($ws, fn($w) => $w['rule'] === $rule && !array_diff($ids, $w['jobs']));
ok($has('mover-parity', 'parity-check', 'mover'), 'mover runs inside the first-Sunday parity check', $keys);
ok($has('backup-parity', 'parity-check', 'appdata-backup'), 'backup inside the parity check', $keys);
ok($has('containers-twice', 'appdata-backup', 'docker-auto-update'), 'backup and docker update both stop containers at 03:00 Sunday', $keys);
ok($has('trim-parity', 'parity-check', 'trim'), 'TRIM at 04:00 Sunday inside the check', $keys);
ok($has('same-start', 'mover', 'user-script-nightly-rsync'), 'user script at the mover\'s minute on Wednesdays', $keys);
ok($has('array-manual', 'parity-check', 'mover'), 'array not started at boot', $keys);
ok($has('s3-sleep', 's3-sleep', 'mover') === false, 'mover at 03:40 is inside the excluded hours 02-04 of S3 Sleep', $keys);
ok($has('s3-sleep', 's3-sleep', 'parity-check'), 'parity at 01:00 is outside the excluded hours', $keys);
$mp = array_values(array_filter($ws, fn($w) => $w['rule'] === 'mover-parity'))[0];
ok(date('Y-m-d H:i', $mp['window'][0]) === '2026-12-06 03:40', 'first overlap is on the first Sunday of December', date('Y-m-d H:i', $mp['window'][0]));
$inc = array_values(array_filter($ws, fn($w) => in_array('parity-check-increment-parity-check-tuning', $w['jobs'], true) && $w['rule'] === 'mover-parity'));
ok($inc && $inc[0]['severity'] === 'info' && str_contains($inc[0]['why'], 'runs only while a parity check is unfinished'), 'overlaps with the conditional increment window are info, with the reason', $inc[0] ?? null);
$nl = array_values(array_filter($ws, fn($w) => $w['rule'] === 'not-loaded'));
ok(count($nl) === 1 && $nl[0]['file']['file'] === '/boot/config/plugins/ca.mover.tuning/stale.cron' && str_contains($nl[0]['why'], 'no installed plugin is called "ca.mover.tuning"'), 'a .cron file of a plugin that is not installed is pointed out', $nl);
ok(!$has('parity-parity', 'parity-check', 'parity-check-increment-parity-check-tuning'), 'a check and its own increment window are not two checks', $keys);
$bp = array_values(array_filter($ws, fn($w) => $w['rule'] === 'backup-parity' && $w['jobs'][0] === 'parity-check'));
ok($bp && str_starts_with($bp[0]['title'], 'Appdata backup stops containers during Parity check'), 'backup-parity title names the backup as the one that stops containers', $bp[0]['title'] ?? null);
foreach ($ws as $w) ok($w['why'] !== '' && str_starts_with($w['example'], 'Ex.') && $w['title'] !== '', "warning {$w['rule']} has title, reason, example");

echo ($fail ? "FAIL $fail of $n" : "ok   collect: $n checks") . "\n";
exit($fail ? 1 : 0);
