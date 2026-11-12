<?php
/* Timetable: collects every scheduled job on the server, read-only. Nothing here writes a file, runs a job or
 * changes a setting; it reads crontabs, plugin settings and logs, and returns plain arrays.
 *
 * Where each fact comes from (Unraid webGUI source, master 2026-11):
 *  - sbin/update_cron:6-16 joins /boot/config/plugins/dynamix/*.cron and the *.cron of each installed plugin
 *    (/var/log/plugins/*.plg) into the crontab /etc/cron.d/root; include/Wrappers.php parse_cron_cfg() writes them.
 *  - include/update.parity.php:65-78 writes parity-check.cron (with the shell tests for "last day" and "week N",
 *    and the pause/resume lines for a cumulative check); include/update.trim.php:22 writes ssd-trim.cron;
 *    scripts/notify:187-198 writes status-check, unraid-check, plugin-check, monitor, docker-update, language-check.
 *  - emhttpd writes mover.cron from share.cfg shareMoverSchedule (the "# Generated mover schedule:" text is in the
 *    emhttpd binary; MoverSettings.page:30-36 reads the same key).
 *  - The per-user crontab /var/spool/cron/crontabs/root holds the stock run-parts lines (hourly, daily, weekly,
 *    monthly); the stubs in /etc/cron.<period>/ run at those times.
 *  - /boot/config/parity-checks.log: date|duration (s)|speed|status|errors|action|size (scripts/parity_history).
 *  - sbin/mover:501 and :585 print "mover: started" and "mover: finished"; the cron line sends them to syslog
 *    with the tag "move".
 * Third-party plugins (their own repositories): see the per-plugin functions below. */

require_once __DIR__ . '/Cron.php';
require_once __DIR__ . '/Analyse.php';   // TtAnalyse::stopsText, the same words in the list and the findings

final class TtCollect
{
    /** Test runs point this at a directory of example data laid out like the server. */
    private string $root;
    private int $now;
    private array $jobs = [];
    private array $notes = [];
    private array $flashLines = [];   // normalised cron text => [file, line, plugin]
    private ?array $syslog = null;
    private array $live = [];
    private string $curFile = 'crontab';         // normalised lines of the live crontabs
    private string $curExpr = '';
    private array $staleLive = [];               // lines of /etc/cron.d/root that no .cron file on the flash drive has

    public function __construct(string $root = '', ?int $now = null)
    {
        $this->root = rtrim($root, '/');
        $this->now = $now ?? time();
    }

    private function p(string $path): string
    {
        return $this->root . $path;
    }

    /** Largest settings or crontab file read whole; a bigger one is skipped with a note. Logs are read from their end. */
    public const MAX_FILE = 1 << 20;
    public const MAX_LOG = 2 << 20;

    private function read(string $path, bool $log = false): ?string
    {
        return $this->readFile($this->p($path), $path, $log);
    }

    /** A file's text, bounded: a log gives its last MAX_LOG bytes, any other file over MAX_FILE gives null and a note,
     *  so a huge or runaway file written by another plugin cannot use up the page's memory. */
    private function readFile(string $f, string $shown, bool $log = false): ?string
    {
        if (!is_file($f) || !is_readable($f)) return null;
        $size = (int)@filesize($f);
        $max = $log ? self::MAX_LOG : self::MAX_FILE;
        if ($size <= $max) { $t = @file_get_contents($f); return $t === false ? null : $t; }
        if (!$log) { $this->notes[] = "Skipped $shown: " . round($size / 1048576, 1) . ' MB is more than a schedule or settings file should be.'; return null; }
        $t = @file_get_contents($f, false, null, $size - $max, $max);
        if ($t === false) return null;
        $nl = strpos($t, "\n");   // drop the cut first line
        return $nl === false ? '' : substr($t, $nl + 1);
    }

    /** Text from another plugin's or a user's file, made safe to keep: control characters out, one line, capped.
     *  Escaping for HTML is done where it is printed (Render.php h()). */
    public static function clean($v, int $max = 300): string
    {
        if (!is_string($v) && !is_int($v) && !is_float($v)) return '';
        $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v) ?? preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$v);
        if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        $v = trim(preg_replace('/ {2,}/', ' ', $v));
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 3) . '...' : $v;
    }

    /** INI as Unraid writes it (key="value", optional [sections]); null when missing. */
    private function ini(string $path, bool $sections = false): ?array
    {
        $t = $this->read($path);
        if ($t === null) return null;
        $r = @parse_ini_string($t, $sections, INI_SCANNER_RAW);
        if ($r === false) { $this->notes[] = "Could not read $path (not valid INI)."; return null; }
        return $r;
    }

    private function json(string $path): ?array
    {
        $t = $this->read($path);
        if ($t === null) return null;
        $r = json_decode($t, true);
        if (!is_array($r)) { $this->notes[] = "Could not read $path (not valid JSON)."; return null; }
        return $r;
    }

    private function installed(string $plugin): bool
    {
        return is_file($this->p("/var/log/plugins/$plugin.plg")) || is_link($this->p("/var/log/plugins/$plugin.plg"));
    }

    /** Everything: jobs, server context and notes. */
    public function collect(): array
    {
        $this->indexFlashCron();
        $this->cronTables();
        $this->userScriptEvents();
        $this->daemons();
        $ctx = $this->context();
        $this->durations($ctx);
        $ctx['unloaded'] = $this->unloaded($this->live);
        $ctx['staleLive'] = $this->staleLive;
        $ctx['duplicates'] = $this->duplicates();
        $ctx['moverTuning'] = $this->moverTuning();
        return ['jobs' => array_values($this->jobs), 'context' => $ctx, 'notes' => $this->notes];
    }

    /* ---------- cron ---------- */

    private static function norm(string $s): string
    {
        return preg_replace('/\s+/', ' ', trim($s));
    }

    /** Lines of the .cron files update_cron joins, so a live crontab line can name the file it came from. */
    private function indexFlashCron(): void
    {
        $files = glob($this->p('/boot/config/plugins/dynamix/*.cron')) ?: [];
        foreach (glob($this->p('/var/log/plugins/*.plg')) ?: [] as $plg) {
            $name = basename($plg, '.plg');
            foreach (glob($this->p("/boot/config/plugins/$name/*.cron")) ?: [] as $f) $files[] = $f;
        }
        foreach ($files as $f) {
            $rel = substr($f, strlen($this->root));
            $plugin = basename(dirname($f));
            foreach (preg_split('/\R/', (string)$this->readFile($f, substr($f, strlen($this->root)))) as $i => $line) {
                if (TtCron::splitLine($line) === null) continue;
                $this->flashLines[self::norm($line)] ??= ['file' => $rel, 'line' => $i + 1, 'plugin' => $plugin];
            }
        }
    }

    /** .cron files on the flash drive whose lines are not in the live crontab: update_cron reads dynamix/ and the
     *  folders of installed plugins only (sbin/update_cron:6-12), and only when something calls it. */
    private function unloaded(array $live): array
    {
        $out = [];
        foreach (glob($this->p('/boot/config/plugins/*/*.cron')) ?: [] as $f) {
            $dir = basename(dirname($f));
            $rel = substr($f, strlen($this->root));
            $installed = $dir === 'dynamix' || $this->installed($dir);
            foreach (preg_split('/\R/', (string)$this->readFile($f, $rel)) as $i => $line) {
                if (TtCron::splitLine($line) === null || isset($live[self::norm($line)])) continue;
                $out[] = ['file' => $rel, 'line' => $i + 1, 'text' => self::clean($line, 300),
                    'why' => $installed ? 'the plugin is installed, but the crontab has not been rebuilt since this line was written' : "no installed plugin is called \"$dir\", so update_cron skips this folder"];
            }
        }
        return $out;
    }

    /** The crontabs crond reads: system crontabs in /etc/cron.d (Unraid's own is "root") and the per-user ones. */
    private function cronTables(): void
    {
        $tables = [];
        foreach (glob($this->p('/etc/cron.d/*')) ?: [] as $f) {
            $b = basename($f);
            if ($b === 'cron.update' || str_starts_with($b, '.')) continue;
            $tables[] = $f;
        }
        foreach (glob($this->p('/var/spool/cron/crontabs/*')) ?: [] as $f) $tables[] = $f;
        if (!$tables) $this->notes[] = 'No crontab was found to read (/etc/cron.d, /var/spool/cron/crontabs).';
        foreach ($tables as $f) {
            $rel = substr($f, strlen($this->root));
            $text = $this->readFile($f, $rel);
            if ($text === null) { $this->notes[] = "Could not read $rel."; continue; }
            foreach (preg_split('/\R/', $text) as $i => $line) {
                $l = TtCron::splitLine($line);
                if ($l === null) continue;
                $this->live[self::norm($line)] = true;
                // /etc/cron.d/root is rebuilt from the .cron files alone (sbin/update_cron:6-16, "crontab -c /etc/cron.d -"),
                // so a line there that no such file has goes away at the next rebuild
                if ($rel === '/etc/cron.d/root' && !isset($this->flashLines[self::norm($line)])) $this->staleLive[] = ['file' => $rel, 'line' => $i + 1, 'text' => self::clean($line, 300)];
                $this->cronLine($l, $line, $rel, $i + 1);
            }
        }
    }

    private function cronLine(array $l, string $raw, string $file, int $lineNo): void
    {
        // only Unraid's own crontab is built from the .cron files; the same text in another crontab is a copy, not that file
        $src = $file === '/etc/cron.d/root' ? ($this->flashLines[self::norm($raw)] ?? null) : null;
        $source = ['file' => $src['file'] ?? $file, 'line' => $src['line'] ?? $lineNo, 'live' => "$file:$lineNo"];
        $cmd = $l['command'];
        if ($l['expr'] === '@reboot') {
            $this->add(['title' => 'At boot: ' . self::shortCmd($cmd), 'owner' => $src['plugin'] ?? 'Other', 'category' => 'other',
                'schedule' => ['type' => 'event', 'event' => 'boot', 'text' => 'once at boot (@reboot)'], 'command' => $cmd, 'source' => $source, 'impact' => ['unknown']]);
            return;
        }
        $sched = ['type' => 'cron', 'expr' => $l['expr'], 'macro' => $l['macro'], 'guard' => TtCron::guard($cmd)];
        if (isset($l['error'])) $sched['badLine'] = $l['error'];
        // run-parts: one job per script in the folder, at the folder's time
        if (preg_match('#run-parts\s+(/etc/cron\.(hourly|daily|weekly|monthly))#', $cmd, $m)) {
            $this->runParts($m[1], $m[2], $sched, $source);
            return;
        }
        $this->curFile = $file;
        $this->curExpr = $l['expr'];
        $job = $this->classify($cmd, $src['plugin'] ?? null);
        $job += ['command' => $cmd, 'source' => $source, 'schedule' => $sched];
        if (!empty($job['fold'])) return;   // a pause line: the end of the window its resume line draws
        if (isset($job['parityWindowTo'])) {
            $this->parityWindow($job);
            return;
        }
        if (str_starts_with($file, '/etc/cron.d/') && $file !== '/etc/cron.d/root' && preg_match('/^(root|nobody)\s+\//', $cmd, $u)) {
            $job['note'] = "The command starts with \"$u[1]\". Unraid's own crontab in the same folder has no user field; if \"$u[1]\" is meant as one, check that this job really runs.";
        }
        $this->add($job);
    }

    /** What a crontab command is: title, owner, category, impact, link. Unknown commands are "Other". */
    private function classify(string $cmd, ?string $plugin): array
    {
        $sch = '/Settings/Scheduler';
        $rules = [
            // Unraid
            ['#/mdcmd check#', ['title' => 'Parity check', 'owner' => 'Unraid', 'category' => 'parity', 'impact' => ['array_io', 'wakes_disks', 'needs_array'], 'link' => $sch, 'tab' => 'tab1']],
            ['#parity_control resume#', ['title' => 'Parity check: resume (cumulative)', 'owner' => 'Unraid', 'category' => 'parity', 'impact' => ['array_io', 'wakes_disks', 'needs_array'], 'link' => $sch, 'tab' => 'tab1', 'parityWindowTo' => 'parity_control pause']],
            ['#parity_control pause#', ['title' => 'Parity check: pause (cumulative)', 'owner' => 'Unraid', 'category' => 'checks', 'impact' => [], 'link' => $sch, 'tab' => 'tab1', 'light' => true, 'fold' => true]],
            ['#^/usr/local/sbin/mover( start)?\b#', ['title' => 'Mover', 'owner' => 'Unraid', 'category' => 'mover', 'impact' => ['array_io', 'pool_io', 'wakes_disks', 'needs_array'], 'link' => $sch, 'tab' => 'tab2']],
            ['#dynamix/scripts/ssd_trim#', ['title' => 'TRIM', 'owner' => 'Unraid', 'category' => 'trim', 'impact' => ['pool_io'], 'link' => $sch, 'tab' => 'tab3']],
            ['#dynamix/scripts/monitor#', ['title' => 'System monitor (notifications)', 'owner' => 'Unraid', 'category' => 'checks', 'impact' => [], 'link' => '/Settings/Notifications', 'light' => true]],
            ['#dynamix/scripts/statuscheck#', ['title' => 'Array status report', 'owner' => 'Unraid', 'category' => 'checks', 'impact' => [], 'link' => '/Settings/Notifications', 'light' => true]],
            ['#plugin.manager/scripts/unraidcheck#', ['title' => 'Unraid OS update check', 'owner' => 'Unraid', 'category' => 'checks', 'impact' => [], 'link' => '/Settings/Notifications', 'light' => true]],
            ['#plugin.manager/scripts/plugincheck#', ['title' => 'Plugin update check', 'owner' => 'Unraid', 'category' => 'checks', 'impact' => [], 'link' => '/Settings/Notifications', 'light' => true]],
            ['#plugin.manager/scripts/languagecheck#', ['title' => 'Language update check', 'owner' => 'Unraid', 'category' => 'checks', 'impact' => [], 'link' => '/Settings/Notifications', 'light' => true]],
            ['#docker.manager/scripts/dockerupdate#', ['title' => 'Docker update check', 'owner' => 'Unraid', 'category' => 'checks', 'impact' => [], 'link' => '/Settings/Notifications', 'light' => true]],
            // Parity Check Tuning (itimpi/parity.check.tuning, parity.check.tuning.php:1960-1989)
            ['#parity\.check\.tuning\.php"? *"?resume#', ['title' => 'Parity check: resume (Parity Check Tuning)', 'owner' => 'Parity Check Tuning', 'category' => 'parity', 'impact' => ['array_io', 'wakes_disks', 'needs_array'], 'link' => $sch, 'tab' => 'tab2', 'parityWindowTo' => 'parity.check.tuning.php "pause"']],
            ['#parity\.check\.tuning\.php"? *"?pause#', ['title' => 'Parity check: pause (Parity Check Tuning)', 'owner' => 'Parity Check Tuning', 'category' => 'checks', 'impact' => [], 'link' => $sch, 'light' => true, 'fold' => true]],
            ['#parity\.check\.tuning\.php"? *"?monitor#', ['title' => 'Parity Check Tuning monitor', 'owner' => 'Parity Check Tuning', 'category' => 'checks', 'impact' => [], 'link' => $sch, 'light' => true]],
            // Mover Tuning (ca.mover.tuning, updateCron.php:112-164)
            ['#ca\.mover\.tuning/mover\.php force start#', ['title' => 'Mover, forced move (Mover Tuning)', 'owner' => 'Mover Tuning', 'category' => 'mover', 'impact' => ['array_io', 'pool_io', 'wakes_disks', 'needs_array'], 'link' => $sch]],
            ['#ca\.mover\.tuning/mover start#', ['title' => 'Mover (Mover Tuning)', 'owner' => 'Mover Tuning', 'category' => 'mover', 'impact' => ['array_io', 'pool_io', 'wakes_disks', 'needs_array'], 'link' => $sch]],
            ['#ca\.mover\.tuning/age_mover watchdog#', ['title' => 'Mover Tuning cache watchdog', 'owner' => 'Mover Tuning', 'category' => 'checks', 'impact' => [], 'link' => $sch, 'light' => true]],
            // Pool and disk maintenance written by the Device page (unraid/webgui DeviceInfo.page:1192 balance, :1308 btrfs scrub, :1530 zfs scrub)
            ['#dynamix/scripts/(btrfs_balance|btrfs_scrub|zfs_scrub)\s+start\s+(\S+)([^&>|]*)#', ['title' => 'Pool maintenance', 'owner' => 'Unraid', 'category' => 'pool', 'impact' => ['pool_io', 'needs_array'], 'link' => null, 'poolJob' => true]],
            // Appdata Cleanup Plus (alexphillips-dev/Appdata-Cleanup-Plus): its timer runs scheduled-purge.php, which purges quarantined appdata folders
            ['#scheduled-purge\.php#', ['title' => 'Appdata Cleanup Plus: purge of quarantined folders', 'owner' => 'Appdata Cleanup Plus', 'category' => 'checks', 'impact' => [], 'light' => true, 'link' => null,
                'description' => 'Deletes the appdata folders that Appdata Cleanup Plus put in quarantine, once their retention time has passed.']],
            // NUT UPS statistics (NUT plugin, nutstats.cron)
            ['#nutstats#', ['title' => 'NUT UPS statistics (NUT plugin)', 'owner' => 'NUT plugin', 'category' => 'checks', 'impact' => [], 'link' => null, 'light' => true]],
            // User Scripts (Squidly271/user.scripts, exec.php:152-159)
            ['#user\.scripts/startCustom\.php\s+(?:"([^"]+)"|\'([^\']+)\'|(.+?/script)(?=\s+(?:\d?>|&>|\|)|\s*$)|(\S+))#', ['title' => 'User script', 'owner' => 'User Scripts', 'category' => 'scripts', 'impact' => ['unknown'], 'link' => '/Settings/Userscripts', 'userScript' => true]],
            // Appdata Backup (Commifreak/unraid-appdata.backup, ABSettings.php:284-304)
            ['#appdata\.backup(\.beta)?/scripts/backup\.php#', ['title' => 'Appdata backup', 'owner' => 'Appdata Backup', 'category' => 'backup', 'impact' => ['stops_containers', 'pool_io', 'needs_array'], 'link' => '/Settings/AB.Main', 'appdata' => true]],
            // CA Auto Update Applications (exec.php:79-122)
            ['#ca\.update\.applications/scripts/updateDocker\.php#', ['title' => 'Docker auto update', 'owner' => 'Auto Update Applications', 'category' => 'update', 'impact' => ['stops_containers'], 'link' => '/Settings/AutoUpdateApps']],
            ['#ca\.update\.applications/scripts/updateApplications\.php#', ['title' => 'Plugin auto update', 'owner' => 'Auto Update Applications', 'category' => 'update', 'impact' => [], 'link' => '/Settings/AutoUpdateApps']],
            // Dynamix SSD Trim (unraid/dynamix ssd-trim, update.trim.php:21-25)
            ['#^/sbin/fstrim -a#', ['title' => 'TRIM (Dynamix SSD Trim)', 'owner' => 'Dynamix SSD Trim', 'category' => 'trim', 'impact' => ['pool_io'], 'link' => $sch]],
            // QNAP Hardware (luuvasara/unraid-qnap-hardware, scripts/qnaphw-apply writes the line while HISTORY=yes)
            ['#qnap-hardware/scripts/qnaphw-sample#', ['title' => 'QNAP Hardware: temperature history sample', 'owner' => 'QNAP Hardware', 'category' => 'checks', 'impact' => [], 'light' => true, 'link' => '/Settings/QnapHW',
                'description' => 'Records the temperature of each drive in the enclosure bays, as Unraid last read it (no drive is woken), and keeps the last 24 hours in RAM.']],
            // FolderView Plus (alexphillips-dev/FolderView-Plus, scripts/scheduled_backup.php -> runScheduledBackups())
            ['#folderview\.plus/scripts/scheduled_backup\.php#', ['title' => 'FolderView Plus: scheduled folder backup', 'owner' => 'FolderView Plus', 'category' => 'checks', 'impact' => [], 'light' => true, 'link' => null,
                'description' => 'Checks whether a scheduled backup of the Docker and VM folder layout is due; when it is and the folders or preferences changed, saves a backup file on the flash drive.']],
            // sysstat: sa1 collects system activity data for sar, sa2 writes the daily report
            ['#(^|/)sa1(\s|$)#', ['title' => 'System activity data (sysstat)', 'owner' => 'sysstat', 'category' => 'checks', 'impact' => [], 'light' => true, 'link' => null,
                'description' => 'Records CPU, memory, disk and network counters for the sar command; a short run that only reads counters.']],
            ['#(^|/)sa2(\s|$)#', ['title' => 'System activity daily report (sysstat)', 'owner' => 'sysstat', 'category' => 'checks', 'impact' => [], 'light' => true, 'link' => null,
                'description' => 'Writes the daily sar summary from the data sa1 recorded.']],
            // Multiple arrays plugin
            ['#multiarray/scripts/multiarray mover (\d+)#', ['title' => 'Mover, array %1$s', 'owner' => 'Multiple arrays', 'category' => 'mover', 'impact' => ['array_io', 'pool_io', 'wakes_disks', 'needs_array'], 'link' => '/Main']],
            ['#multiarray/scripts/multiarray monitor#', ['title' => 'Monitor, extra arrays', 'owner' => 'Multiple arrays', 'category' => 'checks', 'impact' => [], 'link' => '/Main', 'light' => true]],
        ];
        foreach ($rules as [$re, $job]) {
            if (!preg_match($re, $cmd, $m)) continue;
            if (str_contains($job['title'], '%1$s')) $job['title'] = sprintf($job['title'], $m[1]);
            if (!empty($job['userScript'])) $job = $this->userScriptJob($job, (string)(($m[1] ?? '') ?: ($m[2] ?? '') ?: ($m[3] ?? '') ?: ($m[4] ?? '')));
            if (!empty($job['poolJob'])) $job = $this->poolJob($job, $m[1], trim($m[2], '"\''), trim($m[3]));
            if (!empty($job['appdata'])) $job = $this->appdataJob($job, $cmd);
            unset($job['userScript'], $job['appdata'], $job['poolJob']);
            return $job;
        }
        return ['title' => self::cmdName($cmd), 'owner' => 'Other (from ' . ($plugin !== null ? "plugin folder $plugin" : $this->curFile) . ')', 'category' => 'other', 'impact' => ['unknown'], 'link' => null];
    }

    /** A cumulative check: the resume line starts a window that the matching pause line ends. */
    private function parityWindow(array $job): void
    {
        $pause = null;
        foreach ($this->flashLines as $text => $_) {
            if (str_contains($text, $job['parityWindowTo'])) { $pause = TtCron::splitLine($text); break; }
        }
        $to = $job['parityWindowTo'];
        unset($job['parityWindowTo']);
        $job['conditional'] = 'Runs only while a parity check is unfinished.';
        $job['duration'] = ['sec' => 3600, 'basis' => 'default', 'note' => 'pause time not found; assumed 1 hour'];
        if ($pause !== null && !isset($pause['error'])) {
            try {
                $r = TtCron::parse($job['schedule']['expr']); $p = TtCron::parse($pause['expr']);
                $rs = array_key_first($r['hour']) * 60 + array_key_first($r['min']);
                $ps = array_key_first($p['hour']) * 60 + array_key_first($p['min']);
                $d = ($ps - $rs + 1440) % 1440 ?: 1440;
                $job['duration'] = ['sec' => $d * 60, 'basis' => 'config', 'note' => sprintf('until the pause at %02d:%02d', intdiv($ps, 60), $ps % 60)];
            } catch (InvalidArgumentException $e) {
                // keep the default; the schedule's own error shows in the list
            }
        }
        $job['title'] = str_replace([': resume (cumulative)', ': resume (Parity Check Tuning)'], [', cumulative part', ', increment (Parity Check Tuning)'], $job['title']);
        $this->add($job);
    }

    private function runParts(string $dir, string $period, array $sched, array $source): void
    {
        $scripts = glob($this->p("$dir/*")) ?: [];
        $us = $this->json('/boot/config/plugins/user.scripts/schedule.json') ?? [];
        foreach ($scripts as $f) {
            $b = basename($f);
            $base = ['schedule' => $sched, 'command' => "$dir/$b", 'source' => $source + ['via' => "$dir/$b"]];
            if (preg_match('/^user\.script\.start\.(hourly|daily|weekly|monthly)\.sh$/', $b, $m)) {
                // User Scripts: startSchedule.php runs every script whose frequency matches (startSchedule.php:19-20)
                foreach ($us as $path => $e) {
                    if (!is_array($e) || ($e['frequency'] ?? '') !== $m[1]) continue;
                    $this->add($this->userScriptJob(['title' => 'User script', 'owner' => 'User Scripts', 'category' => 'scripts', 'impact' => ['unknown'], 'link' => '/Settings/Userscripts'], (string)$path) + $base);
                }
                continue;
            }
            if ($b === 'fix.common.problems.sh') {
                $this->add(['title' => 'Fix Common Problems scan', 'owner' => 'Fix Common Problems', 'category' => 'checks', 'impact' => [], 'link' => '/Settings/FixProblems'] + $base);
                continue;
            }
            if ($b === 'logrotate') {
                $this->add(['title' => 'Log rotation', 'owner' => 'Unraid', 'category' => 'checks', 'impact' => [], 'link' => null, 'light' => true] + $base);
                continue;
            }
            $this->add(['title' => $b, 'owner' => "Other (from $dir)", 'category' => 'other', 'impact' => ['unknown'], 'link' => null] + $base);
        }
    }

    /** A btrfs balance, btrfs scrub or zfs scrub the Device page scheduled: names the pool or disk and spells out its options.
     *  The scripts take "start <mountpoint or pool> [options]" (scripts/btrfs_balance, btrfs_scrub, zfs_scrub). */
    private function poolJob(array $job, string $script, string $target, string $opts): array
    {
        $zfs = $script === 'zfs_scrub';
        $name = $zfs ? $target : basename($target);   // /mnt/ssd -> ssd
        $disk = (bool)preg_match('/^disk\d+$/', $name);
        $what = $script === 'btrfs_balance' ? 'Balance' : 'Scrub';
        $job['title'] = $what . ', ' . ($disk ? "array disk $name" : "pool $name") . ' (' . ($zfs ? 'zfs' : 'btrfs') . ')';
        $job['pool'] = $name;
        $job['poolOp'] = $script === 'btrfs_balance' ? 'balance' : 'scrub';
        $job['link'] = '/Main/Device?name=' . rawurlencode($name);
        if ($disk) $job['impact'] = ['array_io', 'wakes_disks', 'needs_array'];
        $words = [];
        foreach (preg_split('/\s+/', $opts, -1, PREG_SPLIT_NO_EMPTY) as $o) {
            if (preg_match('/^-(d|m|s)usage=(\d+)$/', $o, $m)) $words[] = 'only ' . ['d' => 'data', 'm' => 'metadata', 's' => 'system'][$m[1]] . " blocks under $m[2]% full";
            elseif ($o === '--full-balance') $words[] = 'all blocks (a full balance)';
            elseif ($o === '-r') $words[] = 'read-only: reports errors without fixing them';
            elseif ($o === '-B') $words[] = 'runs in the foreground';
            elseif ($o === '-d') $words[] = 'statistics per device';
            else $words[] = $o;
        }
        if ($script === 'btrfs_balance' && !$words) $words[] = 'all blocks (a full balance)';
        $lead = ['btrfs_balance' => "Rewrites the blocks of $target to even out the space", 'btrfs_scrub' => "Reads all data and metadata of $target and checks it against its checksums",
            'zfs_scrub' => "Reads every block of the pool and checks it against its checksums, repairing from redundant copies"][$script];
        $job['description'] = $lead . ($words ? ': ' . implode(', ', $words) : '') . '.';
        if ($script === 'btrfs_scrub') $job['mount'] = $target;
        return $job;
    }

    /** The last btrfs scrub of a filesystem, from /var/lib/btrfs/scrub.status.<fsid> (btrfs-progs cmds/scrub.c: one line
     *  "<fsid>:<devid>|key:value|..." per device with duration, canceled, finished). The file is matched to the mount
     *  through /proc/mounts and the devices in /sys/fs/btrfs/<fsid>/devices; nothing is run. */
    private function btrfsScrubSeconds(string $mount): ?int
    {
        $dev = null;
        foreach (preg_split('/\R/', (string)$this->read('/proc/mounts')) as $l) {
            $f = preg_split('/\s+/', $l);
            if (($f[1] ?? '') === $mount && ($f[2] ?? '') === 'btrfs') { $dev = $f[0]; break; }
        }
        if ($dev === null) return null;
        $real = @realpath($this->p($dev));
        $names = array_unique([basename($dev), basename($real !== false ? $real : $dev)]);
        foreach (glob($this->p('/var/lib/btrfs/scrub.status.*')) ?: [] as $f) {
            $uuid = substr(basename($f), strlen('scrub.status.'));
            $members = @scandir($this->p("/sys/fs/btrfs/$uuid/devices")) ?: [];
            if (!array_intersect($names, $members)) continue;
            $sec = 0; $ok = false;
            foreach (preg_split('/\R/', (string)$this->readFile($f, basename($f))) as $l) {
                if (!str_contains($l, '|duration:')) continue;
                $kv = [];
                foreach (explode('|', $l) as $part) if (str_contains($part, ':') && !str_contains(explode(':', $part)[0], '-')) { [$k, $v] = explode(':', $part, 2); $kv[$k] = $v; }
                if (($kv['finished'] ?? '0') !== '1' || ($kv['canceled'] ?? '0') !== '0') return null;
                $sec = max($sec, (int)($kv['duration'] ?? 0)); $ok = true;
            }
            return $ok ? $sec : null;
        }
        return null;
    }

    private function userScriptJob(array $job, string $path): array
    {
        $dir = dirname($path);
        $name = self::clean($this->read("$dir/name"), 120) ?: self::clean(basename($dir), 120);
        $job['title'] = "User script: $name";
        $desc = self::clean($this->read("$dir/description"), 300);
        if ($desc !== '') $job['description'] = $desc;
        $job['script'] = $path;
        $job['logFolder'] = basename($dir);
        return $job;
    }

    /** Appdata Backup: which containers it stops and where it writes (ABSettings.php:46-67, ABHelper.php:176). */
    private function appdataJob(array $job, string $cmd): array
    {
        $beta = str_contains($cmd, 'appdata.backup.beta');
        // the released plugin keeps config.json in appdata.backup/; a beta build may keep its own in appdata.backup.beta/
        $cfg = ($beta ? $this->json('/boot/config/plugins/appdata.backup.beta/config.json') : null) ?? $this->json('/boot/config/plugins/appdata.backup/config.json');
        if ($beta) { $job['link'] = '/Settings/AB.Beta.Main'; $job['owner'] = 'Appdata Backup (beta)'; }
        if (str_contains($cmd, ' extra')) $job['title'] = 'Appdata backup, extra schedule';
        if ($cfg === null) return $job;
        // a container's own setting, or when it is empty the "defaults" block (ABSettings.php getContainerSpecificSettings);
        // a value of the wrong type counts as not set
        $def = is_array($cfg['defaults'] ?? null) ? $cfg['defaults'] : [];
        $str = fn($x) => is_string($x) ? $x : '';
        $val = fn(array $c, string $k) => $str($c[$k] ?? '') !== '' ? $str($c[$k]) : ($str($def[$k] ?? '') ?: 'no');
        $settings = array_filter(is_array($cfg['containerSettings'] ?? null) ? $cfg['containerSettings'] : [], 'is_array');
        // in the backup's own order (containerOrder), then the rest
        $order = array_values(array_filter(is_array($cfg['containerOrder'] ?? null) ? $cfg['containerOrder'] : [], fn($n) => is_string($n) && isset($settings[$n])));
        foreach (array_keys($settings) as $n) if (!in_array((string)$n, $order, true)) $order[] = (string)$n;
        $all = count($settings); $stops = [];
        foreach ($order as $n) {
            if ($val($settings[$n], 'skip') === 'yes' || $val($settings[$n], 'dontStop') === 'yes') continue;
            $name = self::clean($n, 64);
            if ($name !== '' && count($stops) < 500) $stops[] = $name;
        }
        $job['stopsAt'] = self::startTime($this->curExpr);
        $job['stops'] = $stops;
        $dest = self::clean($cfg['destination'] ?? '', 200);
        if (preg_match('#^/mnt/(user|disk\d+|user0)(/|$)#', $dest)) { $job['impact'][] = 'array_io'; $job['impact'][] = 'wakes_disks'; }
        $method = ($cfg['backupMethod'] ?? null) === 'stopAll' ? 'all at once' : 'one after the other';
        $down = $method === 'all at once' ? 'they stay down until all of them are backed up' : 'each is down while it is backed up';
        $job['note'] = $stops
            ? TtAnalyse::stopsText($stops, $job['stopsAt']) . " (of the $all it lists), $method; $down, so a container that restarts at this time is usually this job."
            : ($all ? "Stops none of the $all containers it lists." : 'Stops the containers it backs up, one after the other or all at once.');
        if ($dest !== '') $job['note'] .= " Writes to $dest.";
        return $job;
    }

    /** HH:MM when a cron schedule starts at one time of day, else null. */
    private static function startTime(string $expr): ?string
    {
        try { $p = TtCron::parse($expr); } catch (InvalidArgumentException $e) { return null; }
        if (count($p['hour']) !== 1 || count($p['min']) !== 1) return null;
        return sprintf('%02d:%02d', array_key_first($p['hour']), array_key_first($p['min']));
    }

    /** User Scripts at array start, array stop and first array start (event/disks_mounted, event/stopping_svcs). */
    private function userScriptEvents(): void
    {
        if (!$this->installed('user.scripts')) return;   // its event hooks come with the plugin
        $us = $this->json('/boot/config/plugins/user.scripts/schedule.json');
        if ($us === null) return;
        $words = ['start' => 'when the array starts', 'stop' => 'when the array stops', 'boot' => 'at the first array start after boot'];
        foreach ($us as $path => $e) {
            $f = is_array($e) ? ($e['frequency'] ?? '') : '';
            if (!is_string($f) || !isset($words[$f])) continue;
            $job = $this->userScriptJob(['title' => 'User script', 'owner' => 'User Scripts', 'category' => 'scripts', 'impact' => ['unknown'], 'link' => '/Settings/Userscripts'], (string)$path);
            $job['schedule'] = ['type' => 'event', 'event' => $f, 'text' => $words[$f]];
            $job['source'] = ['file' => '/boot/config/plugins/user.scripts/schedule.json', 'line' => null];
            $job['command'] = (string)$path;
            $this->add($job);
        }
    }

    /** Programs that run all the time and act on their own timer. */
    private function daemons(): void
    {
        // Dynamix S3 Sleep (unraid/dynamix s3-sleep: default.cfg, SleepSettings.page:111)
        $s3 = $this->ini('/boot/config/plugins/dynamix.s3.sleep/dynamix.s3.sleep.cfg');
        if ($s3 !== null && $this->installed('dynamix.s3.sleep') && ($s3['service'] ?? '0') !== '0') {
            $hours = array_filter(array_map('trim', explode(',', (string)($s3['stopHour'] ?? ''))), 'strlen');
            $days = array_filter(array_map('trim', explode(',', (string)($s3['stopDay'] ?? ''))), 'strlen');
            $what = ($s3['service'] ?? '') === '2' ? 'shuts the server down' : 'puts the server to sleep';
            $this->add(['title' => 'S3 Sleep', 'owner' => 'Dynamix S3 Sleep', 'category' => 'sleep', 'impact' => [], 'link' => '/Settings/SleepSettings',
                'schedule' => ['type' => 'daemon', 'text' => "$what when the drives it watches have been idle; not on excluded hours or days"],
                'sleep' => ['service' => (string)($s3['service'] ?? ''), 'stopHour' => array_map('intval', $hours), 'stopDay' => array_map('intval', $days)],
                'source' => ['file' => '/boot/config/plugins/dynamix.s3.sleep/dynamix.s3.sleep.cfg', 'line' => null], 'command' => 's3_sleep']);
        }
        // Dynamix System Autofan (autofan:294, sleep $INTERVAL*60)
        foreach (glob($this->p('/boot/config/plugins/dynamix.system.autofan/dynamix.system.autofan*.cfg')) ?: [] as $f) {
            if (!$this->installed('dynamix.system.autofan')) break;
            $c = $this->ini(substr($f, strlen($this->root))) ?? [];
            if (($c['service'] ?? '0') !== '1') continue;
            $iv = max(1, (int)($c['interval'] ?? 5));
            $this->add(['title' => 'Auto fan control' . (basename($f) === 'dynamix.system.autofan.cfg' ? '' : ' (' . basename($f, '.cfg') . ')'), 'owner' => 'Dynamix System Autofan', 'category' => 'checks', 'impact' => [], 'light' => true,
                'link' => '/Settings/FanSettings', 'schedule' => ['type' => 'daemon', 'text' => "checks the disk temperatures every $iv minutes"],
                'source' => ['file' => substr($f, strlen($this->root)), 'line' => null], 'command' => 'autofan']);
        }
    }

    /** Pairs of jobs that run the same command from two different crontab files or .cron files (ex. a plugin's own
     *  line and a copy in the user crontab): crond starts each line on its own, so the job runs twice. */
    private function duplicates(): array
    {
        $seen = []; $out = [];
        foreach ($this->jobs as $id => $j) {
            if (($j['schedule']['type'] ?? '') !== 'cron' || isset($j['source']['via']) || !isset($j['source']['live'])) continue;
            $k = self::norm(preg_replace('/^(root|nobody)\s+/', '', (string)$j['command']));
            $where = preg_replace('/:\d+$/', '', (string)$j['source']['live']);   // the crontab it is in, not the .cron file it matches
            foreach ($seen[$k] ?? [] as [$id2, $where2]) if ($where2 !== $where) { $out[] = [$id2, $id]; continue 2; }
            $seen[$k][] = [$id, $where];
        }
        return $out;
    }

    /** Mover Tuning's "Let scheduled mover run during a parity check / rebuild" (key parity) and the forced move's
     *  forceParity, read as its parse_plugin_cfg() does: the saved cfg over the plugin's default.cfg (default "no").
     *  ca.mover.tuning mover.php:344 skips a scheduled run while var.ini mdResync > 0 (:110-115); before 2026.11.05a it
     *  tested mdResyncPos, which stays set while a check is paused (commit e4f9034). Below Unraid 7.2.1 the plugin puts its
     *  own script in place of /usr/local/sbin/mover (keeping mover.old, ca.mover.tuning.plg:782-799), so Unraid's mover
     *  line obeys the same setting. Nothing is run. */
    private function moverTuning(): ?array
    {
        if (!$this->installed('ca.mover.tuning')) return null;
        $def = $this->ini('/usr/local/emhttp/plugins/ca.mover.tuning/default.cfg') ?? [];
        $cfg = ($this->ini('/boot/config/plugins/ca.mover.tuning/ca.mover.tuning.cfg') ?? []) + $def;
        $plg = $this->read('/var/log/plugins/ca.mover.tuning.plg') ?: $this->read('/boot/config/plugins/ca.mover.tuning.plg');
        $ver = $plg !== null && preg_match('/<!ENTITY\s+version\s+"([^"]+)"/', $plg, $m) ? $m[1] : null;
        $mt = ['parity' => isset($cfg['parity']) ? (string)$cfg['parity'] : null, 'forceParity' => isset($cfg['forceParity']) ? (string)$cfg['forceParity'] : null,
            'version' => $ver, 'pausedCounts' => $ver !== null ? strcmp($ver, '2026.11.05a') < 0 : null,
            'replacesMover' => is_file($this->p('/usr/local/sbin/mover.old'))];
        foreach ($this->jobs as &$j) {
            if ($j['category'] !== 'mover') continue;
            $key = null;
            if ($j['owner'] === 'Mover Tuning') $key = str_contains($j['title'], 'forced') ? 'forceParity' : 'parity';
            elseif ($j['owner'] === 'Unraid' && $mt['replacesMover']) $key = 'parity';
            if ($key === null || $mt[$key] !== 'no') continue;
            $j['skipsDuringCheck'] = ['setting' => $key === 'parity' ? 'Let scheduled mover run during a parity check / rebuild' : 'Allow force mover schedule to run during a parity check/rebuild',
                'pausedCounts' => $mt['pausedCounts']];
        }
        unset($j);
        return $mt;
    }

    /* ---------- context ---------- */

    private function context(): array
    {
        $var = $this->ini('/var/local/emhttp/var.ini') ?? [];
        $disk = $this->ini('/boot/config/disk.cfg') ?? [];
        $tz = @readlink($this->p('/etc/localtime'));
        return [
            'startArray' => (string)($var['startArray'] ?? $disk['startArray'] ?? 'yes'),
            'spindownDelay' => (string)($var['spindownDelay'] ?? $disk['spindownDelay'] ?? '0'),
            'mdState' => (string)($var['mdState'] ?? ''),
            'sbSynced' => (int)($var['sbSynced'] ?? 0),
            'timeZone' => (string)($var['timeZone'] ?? ($tz ? substr($tz, strlen('/usr/share/zoneinfo/')) : date_default_timezone_get())),
        ];
    }

    /* ---------- durations ---------- */

    /** How long each job takes: from the server's own history where it has one, else a stated default. */
    private function durations(array $ctx): void
    {
        $parity = $this->parityHistory();
        $mover = $this->syslogPairs('/ move: mover: started$/', '/ move: mover: finished$/');
        $docker = $this->syslogPairs('/Docker Auto Update: Community Applications Docker Autoupdate running/', '/Docker Auto Update: Community Applications Docker Autoupdate finished/');
        foreach ($this->jobs as &$j) {
            if (($j['schedule']['guard']['kind'] ?? '') === 'every7') $j['schedule']['every7Base'] = $ctx['sbSynced'] ?: null;
            if (isset($j['duration'])) continue;
            if (!empty($j['light'])) { $j['duration'] = ['sec' => 60, 'basis' => 'default', 'note' => 'a short check; counted as 1 minute']; continue; }
            switch ($j['category']) {
                case 'parity':
                    $j['duration'] = $parity ?? $this->paritySizeEstimate();
                    break;
                case 'mover':
                    $j['duration'] = $mover !== null && $j['owner'] === 'Unraid'
                        ? $mover
                        : ['sec' => 3600, 'basis' => 'default', 'note' => ($j['owner'] === 'Unraid' ? 'no finished mover run in the system log' : 'no run history is read for this mover') . '; assumed 1 hour (a mover run takes as long as there is data on the cache to move)'];
                    break;
                case 'backup':
                    $j['duration'] = $this->appdataDuration() ?? ['sec' => 3600, 'basis' => 'default', 'note' => 'no finished backup in its log; assumed 1 hour'];
                    break;
                case 'update':
                    $j['duration'] = ($j['title'] === 'Docker auto update' ? $docker : null) ?? ['sec' => 900, 'basis' => 'default', 'note' => 'no run in the system log; assumed 15 minutes'];
                    break;
                case 'pool':
                    $m = !empty($j['mount']) ? $this->btrfsScrubSeconds($j['mount']) : null;
                    $j['duration'] = $m !== null
                        ? ['sec' => max(60, $m), 'basis' => 'history', 'samples' => 1, 'note' => 'the last finished scrub of this pool (/var/lib/btrfs/scrub.status)']
                        : ['sec' => 7200, 'basis' => 'default', 'note' => (str_starts_with($j['title'], 'Balance') ? 'a balance leaves no run time on the server' : 'no finished scrub on record for this pool') . '; assumed 2 hours (it takes as long as there is data to read or move)'];
                    break;
                case 'trim':
                    $j['duration'] = ['sec' => 600, 'basis' => 'default', 'note' => 'TRIM leaves no start and end in the log; assumed 10 minutes'];
                    break;
                case 'scripts':
                    $j['duration'] = $this->userScriptDuration($j) ?? ['sec' => 900, 'basis' => 'default', 'note' => 'no finished run in its log; assumed 15 minutes'];
                    break;
                case 'checks':
                    $j['duration'] = ['sec' => 300, 'basis' => 'default', 'note' => 'assumed 5 minutes'];
                    break;
                default:
                    $j['duration'] = ['sec' => 900, 'basis' => 'unknown', 'note' => 'what this job does is not known here; assumed 15 minutes'];
            }
        }
        unset($j);
        $this->capParity();
    }

    /** A check that pauses (cumulative, or Parity Check Tuning increments) only runs until the pause time. */
    private function capParity(): void
    {
        $pauseAt = null;
        foreach ($this->jobs as $j) {
            if (empty($j['conditional']) || $j['category'] !== 'parity' || ($j['duration']['basis'] ?? '') !== 'config') continue;
            try { $r = TtCron::parse($j['schedule']['expr']); } catch (InvalidArgumentException $e) { continue; }
            $pauseAt = (array_key_first($r['hour']) * 60 + array_key_first($r['min']) + intdiv($j['duration']['sec'], 60)) % 1440;
        }
        if ($pauseAt === null) return;
        foreach ($this->jobs as &$j) {
            if ($j['category'] !== 'parity' || !empty($j['conditional']) || ($j['schedule']['type'] ?? '') !== 'cron') continue;
            try { $r = TtCron::parse($j['schedule']['expr']); } catch (InvalidArgumentException $e) { continue; }
            $start = array_key_first($r['hour']) * 60 + array_key_first($r['min']);
            $until = (($pauseAt - $start + 1440) % 1440 ?: 1440) * 60;
            if ($until < $j['duration']['sec']) {
                $j['duration']['note'] .= sprintf('; pauses at %02d:%02d and goes on in the next windows', intdiv($pauseAt, 60), $pauseAt % 60);
                $j['duration']['sec'] = $until;
            }
        }
        unset($j);
    }

    /** Median of the last 5 finished parity checks in /boot/config/parity-checks.log (status 0, action "check"). */
    private function parityHistory(): ?array
    {
        $t = $this->read('/boot/config/parity-checks.log', true);
        if ($t === null) return null;
        $d = [];
        foreach (preg_split('/\R/', trim($t)) as $row) {
            $f = explode('|', $row);
            if (count($f) < 6) continue;
            if ((string)$f[3] !== '0' || !str_starts_with(trim($f[5]), 'check')) continue;
            if ((int)$f[1] > 0) $d[] = (int)$f[1];
        }
        if (!$d) return null;
        $d = array_slice($d, -5);
        return ['sec' => self::median($d), 'basis' => 'history', 'samples' => count($d),
            'note' => 'median of the last ' . count($d) . ' finished check' . (count($d) > 1 ? 's' : '') . ' in parity-checks.log'];
    }

    /** No history: the largest parity drive read at an assumed 150 MB/s. */
    private function paritySizeEstimate(): array
    {
        $disks = $this->ini('/var/local/emhttp/disks.ini', true) ?? [];
        $size = 0;
        foreach ($disks as $name => $d) {
            if (preg_match('/^parity\d*$/', (string)$name) && !empty($d['size'])) $size = max($size, (int)$d['size']);
        }
        if ($size > 0) {
            $sec = (int)round($size * 1024 / 150e6);
            return ['sec' => max(3600, $sec), 'basis' => 'estimate', 'note' => sprintf('no finished check in the history; %.1f TB parity drive at an assumed 150 MB/s', $size * 1024 / 1e12)];
        }
        return ['sec' => 12 * 3600, 'basis' => 'default', 'note' => 'no history and no parity drive size; assumed 12 hours'];
    }

    /** Durations between a start line and the next end line in the system log; median of the last 5. */
    private function syslogPairs(string $start, string $end): ?array
    {
        $lines = $this->syslogLines();
        $d = []; $s = null;
        foreach ($lines as $l) {
            if (preg_match($start, $l)) { $s = self::syslogTime($l); continue; }
            if ($s !== null && preg_match($end, $l)) {
                $e = self::syslogTime($l);
                if ($e !== null && $e >= $s) $d[] = $e - $s;
                $s = null;
            }
        }
        if (!$d) return null;
        $d = array_slice($d, -5);
        return ['sec' => max(60, self::median($d)), 'basis' => 'history', 'samples' => count($d),
            'note' => 'median of the last ' . count($d) . ' run' . (count($d) > 1 ? 's' : '') . ' in the system log'];
    }

    private function syslogLines(): array
    {
        if ($this->syslog !== null) return $this->syslog;
        $this->syslog = [];
        foreach (['/var/log/syslog.1', '/var/log/syslog'] as $f) {
            $p = $this->p($f);
            if (!is_file($p) || !is_readable($p)) continue;
            $size = filesize($p);
            $h = fopen($p, 'r');
            if ($h === false) continue;
            if ($size > 8 << 20) fseek($h, $size - (8 << 20));   // the last 8 MB is plenty for run times
            while (($l = fgets($h, 8192)) !== false) {   // a line longer than this is not a mover or update line
                if (str_contains($l, 'move: mover: ') || str_contains($l, 'Docker Auto')) $this->syslog[] = rtrim($l);
            }
            fclose($h);
        }
        return $this->syslog;
    }

    /** "Nov 12 01:21:51 host ..." -> unix time, in the year that keeps it in the past. */
    private static function syslogTime(string $l): ?int
    {
        if (!preg_match('/^([A-Z][a-z]{2})\s+(\d+) (\d\d:\d\d:\d\d)/', $l, $m)) return null;
        $t = strtotime("$m[1] $m[2] " . date('Y') . " $m[3]");
        if ($t === false) return null;
        if ($t > time() + 86400) $t = strtotime("$m[1] $m[2] " . (date('Y') - 1) . " $m[3]");
        return $t ?: null;
    }

    /** Appdata Backup's log of its last run: "[d.m.Y H:i:s]" lines from "WELCOME" to "DONE!" (backup.php:36, :371). */
    private function appdataDuration(): ?array
    {
        $t = $this->read('/tmp/appdata.backup/ab.log', true);
        if ($t === null) return null;
        $s = $e = null;
        foreach (preg_split('/\R/', $t) as $l) {
            if (!preg_match('/^\[(\d\d)\.(\d\d)\.(\d{4}) (\d\d:\d\d:\d\d)\]/', $l, $m)) continue;
            $ts = strtotime("$m[3]-$m[2]-$m[1] $m[4]");
            if (str_contains($l, 'WELCOME TO APPDATA.BACKUP')) { $s = $ts; $e = null; }
            if (str_contains($l, 'DONE! Thanks for using this plugin') && $s !== null) $e = $ts;
        }
        if ($s === null || $e === null) return null;
        return ['sec' => max(60, $e - $s), 'basis' => 'history', 'samples' => 1, 'note' => 'the last run in /tmp/appdata.backup/ab.log'];
    }

    /** User Scripts' run log: "Script Starting <M d, Y  H:i.s>" ... "Script Finished ..." (startBackground.php:17, :26). */
    private function userScriptDuration(array $j): ?array
    {
        if (empty($j['logFolder'])) return null;
        $t = $this->read('/tmp/user.scripts/tmpScripts/' . $j['logFolder'] . '/log.txt', true);
        if ($t === null) return null;
        $d = []; $s = null;
        foreach (preg_split('/\R/', $t) as $l) {
            if (preg_match('/^Script (Starting|Finished) ([A-Z][a-z]{2} \d\d, \d{4})\s+(\d\d:\d\d)\.(\d\d)/', $l, $m)) {
                $ts = strtotime("$m[2] $m[3]:$m[4]");
                if ($m[1] === 'Starting') $s = $ts;
                elseif ($s !== null && $ts >= $s) { $d[] = $ts - $s; $s = null; }
            }
        }
        if (!$d) return null;
        $d = array_slice($d, -5);
        return ['sec' => max(60, self::median($d)), 'basis' => 'history', 'samples' => count($d), 'note' => 'from the script\'s run log since the last boot'];
    }

    /** A short name for an unknown command: the script it runs, not the interpreter or a user field.
     *  ex. "root /usr/local/bin/php /x/scripts/backup.php >/dev/null" -> "backup.php". */
    private static function cmdName(string $cmd): string
    {
        $c = preg_replace('/\s*(\d?>|&>|\|).*$/s', '', $cmd);
        $w = preg_split('/\s+/', trim((string)$c), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($w as $i => $x) {
            if (in_array($x, ['root', 'nobody', 'nice', 'ionice', 'sudo'], true) || preg_match('/^-/', $x)) continue;
            if (preg_match('#(^|/)(php|bash|sh|python3?|perl|env)$#', $x)) continue;
            $name = str_contains($x, '/') ? basename($x) : mb_strimwidth($x, 0, 60, '...');
            // what it acts on: the first path (its last part), else the first word that is not an option
            $arg = null; $word = null;
            foreach (array_slice($w, $i + 1) as $a) {
                $a = trim($a, '"\'');
                if ($a === '' || $a[0] === '-') continue;
                if (str_contains($a, '/')) { $arg = basename(rtrim($a, '/')); break; }
                $word ??= $a;
            }
            $arg ??= $word;
            return $arg !== null && $arg !== '' ? mb_strimwidth("$name $arg", 0, 80, '...') : $name;
        }
        return self::shortCmd($cmd);
    }

    private static function median(array $v): int
    {
        sort($v);
        $n = count($v);
        return (int)($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2);
    }

    private static function shortCmd(string $cmd): string
    {
        $c = preg_replace('/\s*(\d?>|&>|\|)\s*.*$/', '', $cmd);
        return mb_strimwidth(trim($c) !== '' ? trim($c) : trim($cmd), 0, 80, '...');
    }

    private function add(array $job): void
    {
        $job['impact'] = array_values(array_unique($job['impact'] ?? []));
        // every text that can come from a file: no control characters, bounded length
        foreach (['title' => 160, 'owner' => 160, 'description' => 300, 'note' => 1200, 'command' => 500, 'pool' => 64, 'mount' => 200, 'script' => 300] as $k => $max) {
            if (isset($job[$k])) $job[$k] = self::clean($job[$k], $max);
        }
        foreach (['file', 'via', 'live'] as $k) if (isset($job['source'][$k])) $job['source'][$k] = self::clean($job['source'][$k], 300);
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($job['title']));
        $id = trim($base, '-');
        for ($i = 2; isset($this->jobs[$id]); $i++) $id = trim($base, '-') . "-$i";
        $job['id'] = $id;
        $this->jobs[$id] = $job;
    }
}
