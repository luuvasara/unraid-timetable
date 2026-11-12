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
// folder names with spaces (Nightly photo sync) and a quoted path; a `name` file wins over the folder name
ok(isset($by['User script: Nightly photo sync']) && !isset($by['User script: scripts']), 'user script folder with spaces keeps its full name', $titles);
ok(($by['User script: Nightly photo sync']['description'] ?? '') === 'Copies new photos to the archive', 'description file read', $by['User script: Nightly photo sync'] ?? null);
ok(isset($by['User script: Pretty name']) && ($by['User script: Pretty name']['script'] ?? '') === '/boot/config/plugins/user.scripts/scripts/named-folder/script', 'quoted path parsed, name file used', $titles);
// pool and disk maintenance written by the Device page
$bal = $by['Balance, pool ssd (btrfs)'] ?? null; $scr = $by['Scrub, pool ssd (btrfs)'] ?? null;
ok($bal && $scr && isset($by['Scrub, pool tank (zfs)'], $by['Scrub, array disk disk3 (btrfs)']), 'balance and scrubs are named after their pool or disk', $titles);
ok($bal && str_contains($bal['description'], 'only data blocks under 50% full') && $bal['link'] === '/Main/Device?name=ssd' && in_array('pool_io', $bal['impact'], true), 'balance options in words, link to the pool page', $bal);
ok($scr && str_contains($scr['description'], 'read-only: reports errors without fixing them'), 'scrub -r in words', $scr['description'] ?? null);
ok($scr && $scr['duration']['sec'] === 6300 && $scr['duration']['basis'] === 'history', 'scrub length from /var/lib/btrfs/scrub.status', $scr['duration'] ?? null);
ok($bal && $bal['duration']['basis'] === 'default' && str_contains($bal['duration']['note'], 'assumed'), 'balance length is an assumption, said so', $bal['duration'] ?? null);
ok(in_array('array_io', $by['Scrub, array disk disk3 (btrfs)']['impact'] ?? [], true), 'an array disk scrub touches the array');
ok(($by['NUT UPS statistics (NUT plugin)']['category'] ?? '') === 'checks', 'NUT statistics named', $titles);
ok(isset($by['Appdata Cleanup Plus: purge of quarantined folders']), 'scheduled-purge.php is Appdata Cleanup Plus', $titles);
ok(isset($by['sync-media.sh photos']), 'unknown job names what it acts on', $titles);
ok(!isset($by['btrfs_balance']) && !isset($by['btrfs_scrub']) && !isset($by['zfs_scrub']), 'no bare script names for known scripts', $titles);
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
ok(str_contains($ab['note'], '(of the 14 it lists), one after the other') && str_contains($ab['note'], '/mnt/user/backups/appdata'), 'appdata note', $ab['note']);
ok(($ab['stops'] ?? null) === ['plex', 'sonarr', 'radarr', 'prowlarr', 'bazarr', 'homeassistant', 'nextcloud', 'photos', 'jellyfin', 'downloads', 'paperless', 'passwords'], 'appdata backup names the containers it stops, in its own order, without skipped and dontStop ones', $ab['stops'] ?? null);
ok(str_contains($ab['note'], 'Stops 12 containers at 03:00: plex, sonarr, radarr, prowlarr, bazarr, homeassistant, nextcloud, photos (+4 more)'), 'appdata note lists the first names and the rest as a count', $ab['note']);
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
ok($has('pool-mover', 'mover', 'balance-pool-ssd-btrfs'), 'balance overlaps the mover, finding names the pool', $keys);
$pm = array_values(array_filter($ws, fn($w) => $w['rule'] === 'pool-mover'));
ok($pm && str_contains(implode(' ', array_column($pm, 'title')), 'pool ssd'), 'finding title carries the pool name', array_column($pm, 'title'));
ok(TtAnalyse::pattern(array_map(fn($i) => strtotime('2026-11-09 00:17') + $i * 3600, range(0, 24 * 35 - 1)), 35) === 'every hour, at :17', 'hourly starts become a pattern');
ok(TtAnalyse::pattern(array_map(fn($i) => strtotime('2026-11-09 03:40') + $i * 86400, range(0, 34)), 35) === 'every day at 03:40', 'daily starts become a pattern');
ok($has('array-manual', 'parity-check', 'mover'), 'array not started at boot', $keys);
ok($has('s3-sleep', 's3-sleep', 'mover') === false, 'mover at 03:40 is inside the excluded hours 02-04 of S3 Sleep', $keys);
ok($has('s3-sleep', 's3-sleep', 'parity-check'), 'parity at 01:00 is outside the excluded hours', $keys);
// new in 2026.11.12h
$q = $by['QNAP Hardware: temperature history sample'] ?? null;
ok($q && $q['owner'] === 'QNAP Hardware' && !empty($q['light']) && $q['link'] === '/Settings/QnapHW', 'qnaphw-sample is the QNAP Hardware history sampler', $titles);
$fv = $by['FolderView Plus: scheduled folder backup'] ?? null;
ok($fv && $fv['owner'] === 'FolderView Plus' && !empty($fv['light']) && str_contains($fv['description'] ?? '', 'flash drive'), 'scheduled_backup.php is FolderView Plus, says what it touches', $titles);
ok(isset($by['System activity data (sysstat)']) && !empty($by['System activity data (sysstat)']['light']) && isset($by['System activity daily report (sysstat)']), 'sa1 and sa2 are sysstat', $titles);
ok(($by['Scrub, pool ssd (btrfs)']['poolOp'] ?? '') === 'scrub' && ($by['Balance, pool ssd (btrfs)']['poolOp'] ?? '') === 'balance', 'pool jobs know whether they scrub or balance');
ok($has('scrub-parity', 'parity-check', 'scrub-array-disk-disk3-btrfs'), 'disk3 scrub on Sunday 05:20 runs inside the first-Sunday check', $keys);
$sp = array_values(array_filter($ws, fn($w) => $w['rule'] === 'scrub-parity' && $w['jobs'][1] === 'scrub-array-disk-disk3-btrfs'));
ok($sp && $sp[0]['severity'] === 'warning', 'scrub during a parity check is a warning', $sp[0] ?? null);
ok($has('s3-sleep-during-run', 's3-sleep', 'balance-pool-ssd-btrfs'), 'balance starts at 04:00 (sleep excluded) and still runs at 05:00 (sleep allowed)', $keys);
ok(!$has('s3-sleep-during-run', 's3-sleep', 'mover'), 'mover 03:40-04:12 stays inside the excluded hours', $keys);
$st = array_values(array_filter($ws, fn($w) => $w['rule'] === 'stale-live'));
ok(count($st) === 1 && $st[0]['count'] === 2 && str_contains($st[0]['why'], 'hand-added-backup.sh') && str_contains($st[0]['why'], 'sync-media.sh'), 'two live lines with no schedule file on the flash drive, in one finding', $st);
ok(!str_contains($st[0]['why'] ?? '', 'nutstats') && !str_contains($st[0]['why'] ?? '', 'qnaphw'), 'lines that have their .cron file are not stale', $st[0]['why'] ?? null);
$du = array_values(array_filter($ws, fn($w) => $w['rule'] === 'duplicate'));
ok(count($du) === 1 && str_contains($du[0]['title'], 'Unraid OS update check') && $du[0]['severity'] === 'info' && str_contains($du[0]['why'], '/var/spool/cron/crontabs/root'), 'the same light command in two crontabs is pointed out (info)', $du);
$bpw = array_values(array_filter($ws, fn($w) => $w['rule'] === 'backup-parity'))[0] ?? null;
ok($bpw && str_contains($bpw['why'], 'Stops 12 containers: plex, sonarr'), 'backup findings name the containers it stops', $bpw['why'] ?? null);
$mpw = array_values(array_filter($ws, fn($w) => $w['rule'] === 'mover-parity' && $w['jobs'][0] === 'parity-check'))[0] ?? null;
ok($mpw && str_contains($mpw['why'], 'not blocked') && str_contains($mpw['why'], 'Move button'), 'mover-parity says the scheduled mover is not blocked by a running check', $mpw['why'] ?? null);

// Mover Tuning set not to move during a check (its default), with a check longer than a day on two days in a row
$c2 = (new TtCollect(__DIR__ . '/fixtures/movertuning', $now))->collect();
$j2 = $c2['jobs']; TtAnalyse::prepare($j2);
$b2 = []; foreach ($j2 as $j) $b2[$j['id']] = $j;
ok(($c2['context']['moverTuning']['parity'] ?? null) === 'no' && ($c2['context']['moverTuning']['version'] ?? null) === '2026.11.07', 'Mover Tuning: parity key from default.cfg when the saved cfg has none, version from its .plg', $c2['context']['moverTuning'] ?? null);
ok(($b2['appdata-backup-2']['source']['file'] ?? '') === '/var/spool/cron/crontabs/root', 'a copy in the user crontab is attributed to that crontab, not to the plugin\'s .cron file', $b2['appdata-backup-2']['source'] ?? null);
ok(!empty($b2['mover-mover-tuning']['skipsDuringCheck']), 'the Mover Tuning schedule skips during a check', $b2['mover-mover-tuning'] ?? array_keys($b2));
$w2 = TtAnalyse::warnings($j2, $now, 35, $c2['context']);
$k2 = array_map(fn($w) => $w['rule'] . ':' . implode(',', $w['jobs']), $w2);
$r2 = []; foreach ($w2 as $w) $r2[$w['rule']][] = $w;
ok(isset($r2['mover-parity']) && $r2['mover-parity'][0]['severity'] === 'info' && str_contains($r2['mover-parity'][0]['why'], 'Mover Tuning'), 'mover-parity is info when Mover Tuning skips the run, and says why', $r2['mover-parity'] ?? $k2);
ok(isset($r2['mover-blocked-by-check']) && $r2['mover-blocked-by-check'][0]['severity'] === 'warning' && str_contains($r2['mover-blocked-by-check'][0]['title'], 'Mover (Mover Tuning)'), 'a check of over a day keeps the Mover Tuning mover from running', $k2);
ok(isset($r2['parity-repeat']) && $r2['parity-repeat'][0]['severity'] === 'warning' && !isset($r2['self']), 'Sunday and Monday ticked with a 26 h check: parity-repeat, not self', $k2);
ok(isset($r2['duplicate']) && $r2['duplicate'][0]['severity'] === 'warning' && !isset($r2['containers-twice']), 'the backup line in two crontabs is a warning, and its copy is not also a containers-twice pair', $k2);
foreach ($w2 as $w) ok($w['why'] !== '' && str_starts_with($w['example'], 'Ex.') && $w['title'] !== '', "warning {$w['rule']} has title, reason, example (Mover Tuning example)");
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
