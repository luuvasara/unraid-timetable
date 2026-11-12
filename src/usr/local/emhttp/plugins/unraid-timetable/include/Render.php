<?php
/* Timetable: the HTML of the Timeline and List tabs, rendered on the server from the same model, so the picture and
 * the text list always agree and both work without JavaScript. Every value from a file goes through h().
 * Read-only: there is no form, no POST and no button that changes anything; links lead to the page where a job is
 * set up. */

require_once __DIR__ . '/Collect.php';
require_once __DIR__ . '/Analyse.php';

final class TtView
{
    public const CATS = [
        'parity' => ['Parity check', 1], 'mover' => ['Mover', 2], 'backup' => ['Backup', 3], 'update' => ['Updates', 4],
        'scripts' => ['User Scripts', 5], 'trim' => ['TRIM', 6], 'pool' => ['Pool maintenance', 7], 'checks' => ['Checks and reports', 8],
        'other' => ['Other', 0], 'sleep' => ['Sleep', 0],
    ];
    /** Settings pages of other plugins, shown as links only when that plugin is installed. */
    private const NEEDS = ['/Settings/Userscripts' => 'user.scripts', '/Settings/AB.Main' => 'appdata.backup', '/Settings/AB.Beta.Main' => 'appdata.backup.beta',
        '/Settings/AutoUpdateApps' => 'ca.update.applications', '/Settings/FixProblems' => 'fix.common.problems',
        '/Settings/SleepSettings' => 'dynamix.s3.sleep', '/Settings/FanSettings' => 'dynamix.system.autofan', '/Main' => null];
    public const HORIZON_DAYS = 35;

    private static ?array $model = null;
    private string $docroot;
    private bool $h12;

    public function __construct(string $docroot = '/usr/local/emhttp', array $display = [])
    {
        $this->docroot = $docroot;
        $this->h12 = ($display['time'] ?? '%R') === '%I:%M %p';
    }

    public static function h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    /** Collect once per request; the Timeline and List tabs share it. */
    public function model(string $root = '', ?int $now = null): array
    {
        if (self::$model !== null) return self::$model;
        $now ??= time();
        $c = (new TtCollect($root, $now))->collect();
        $jobs = $c['jobs'];
        TtAnalyse::prepare($jobs);
        $byId = [];
        foreach ($jobs as $j) $byId[$j['id']] = $j;
        $today = mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $s = mktime(0, 0, 0, (int)date('n', $today), (int)date('j', $today) + $i, (int)date('Y', $today));
            $e = mktime(0, 0, 0, (int)date('n', $today), (int)date('j', $today) + $i + 1, (int)date('Y', $today));
            $days[] = [$s, $e];   // a day with a clock change is 23 or 25 hours long
        }
        $win = TtAnalyse::windows($jobs, $days[0][0], $days[6][1]);
        return self::$model = [
            'now' => $now, 'jobs' => $jobs, 'byId' => $byId, 'days' => $days, 'windows' => $win,
            'overlaps' => TtAnalyse::overlaps($win, $byId),
            'warnings' => TtAnalyse::warnings($jobs, $now, self::HORIZON_DAYS, $c['context']),
            'context' => $c['context'], 'notes' => $c['notes'],
        ];
    }

    /* ---------- small formatters ---------- */

    public function time(int $t): string
    {
        return date($this->h12 ? 'g:i A' : 'H:i', $t);
    }

    public function dayTime(int $t): string
    {
        return date('D j M', $t) . ' ' . $this->time($t);
    }

    /** Row-label tooltip: the title, plus a User Script's own description when it has one. */
    private static function tip(array $j): string
    {
        return $j['title'] . (!empty($j['description']) ? ': ' . $j['description'] : '')
            . (!empty($j['stops']) ? ': ' . TtAnalyse::stopsText($j['stops'], $j['stopsAt'] ?? null) : '');
    }

    public static function dur(int $sec): string
    {
        $m = (int)round($sec / 60);
        if ($m < 60) return max(1, $m) . ' min';
        $h = intdiv($m, 60); $m %= 60;
        return $h . ' h' . ($m ? " $m min" : '');
    }

    private static function catOf(array $j): string
    {
        return isset(self::CATS[$j['category']]) ? $j['category'] : 'other';
    }

    /** Link to where a job is set up, or plain text when that page is not there. */
    public function link(array $j, string $text = ''): string
    {
        $url = $j['link'] ?? null;
        $text = $text !== '' ? $text : 'Settings';
        // only the fixed internal pages this plugin builds; a pool name is the one query value, already URL-encoded
        if (!$url || !preg_match('#^/(Settings|Main|Utilities)(/[A-Za-z0-9._-]+)*(\?name=[A-Za-z0-9%._~-]+)?$#', $url)) return '';
        $need = self::NEEDS[$url] ?? null;
        if ($need !== null && !is_dir("$this->docroot/plugins/$need")) return '';
        $tab = $this->tabFor($j);
        return '<a class="tt-go" href="' . self::h($url) . '"' . ($tab ? ' data-tab="' . self::h($tab) . '"' : '') . '>' . self::h($text) . '</a>';
    }

    /** Settings > Scheduler opens the tab last used (cookie "one"); the link sets it to the job's own tab. The tab
     *  number is the page's place among the Scheduler pages, which other plugins can add to, so it is counted. */
    private function tabFor(array $j): ?string
    {
        if (($j['link'] ?? '') !== '/Settings/Scheduler') return null;
        $want = ['parity' => 'ParityCheck', 'mover' => 'MoverSettings', 'trim' => 'TrimSettings'][$j['category']] ?? null;
        if ($j['owner'] === 'Parity Check Tuning') $want = 'Parity.check.tuning';
        if ($j['owner'] === 'Mover Tuning') $want = 'Mover.tuning';
        if ($want === null) return null;
        static $order = null;
        if ($order === null) {
            // as PageBuilder.php find_pages(): key "<rank><page name>", ksort SORT_NATURAL (a page's Cond is not evaluated here)
            $keys = [];
            foreach (glob("$this->docroot/plugins/*/*.page") ?: [] as $f) {
                $head = @file_get_contents($f, false, null, 0, 600);
                if (!$head || !preg_match('/^Menu="([^"]*)"/m', $head, $m)) continue;
                foreach (explode(' ', $m[1]) as $menu) {
                    $p = explode(':', $menu, 2);
                    if ($p[0] === 'Scheduler') $keys[($p[1] ?? '') . basename($f, '.page')] = basename($f, '.page');
                }
            }
            ksort($keys, SORT_NATURAL);
            $order = array_values($keys);
        }
        $i = array_search($want, $order, true);
        return $i === false ? null : 'tab' . ($i + 1);
    }

    /* ---------- Timeline tab ---------- */

    public function timeline(): string
    {
        $m = $this->model();
        $o = '<div class="tt-findings">' . $this->findings($m) . '</div>';
        $o .= $this->dayCharts($m);
        $o .= $this->weekGrid($m);
        $o .= $this->later($m);
        return $o;
    }

    private function findings(array $m): string
    {
        $ws = $m['warnings'];
        $nw = count(array_filter($ws, fn($w) => $w['severity'] === 'warning'));
        $ni = count($ws) - $nw;
        $o = '<h3 class="tt-h">Findings <span class="tt-count">' . self::h($nw ? "$nw to look at" : 'nothing to look at') . ($ni ? self::h(", $ni for information") : '') . '</span></h3>';
        $o .= '<p class="tt-muted">Looks ' . self::HORIZON_DAYS . ' days ahead. Each finding says why it matters; whether to change anything is your call, on the page where the job is set up.</p>';
        if (!$ws) return $o . '<p class="tt-ok">No job overlaps another in a way that matters, and nothing else stands out.</p>';
        $o .= '<ul class="tt-flist">';
        foreach ($ws as $w) {
            $sev = $w['severity'];
            $o .= '<li class="tt-f tt-f-' . $sev . '"><span class="tt-sev"><i class="fa fa-' . ($sev === 'warning' ? 'exclamation-triangle' : 'info-circle') . '" aria-hidden="true"></i> ' . ($sev === 'warning' ? 'Warning' : 'Info') . '</span>';
            $o .= '<div class="tt-fbody"><p class="tt-ftitle">' . self::h($w['title']) . '</p>';
            if ($w['window']) {
                $more = !empty($w['pattern']) ? ', then ' . $w['pattern'] : ($w['count'] > 1 ? sprintf(', and %d more time%s in the next %d days', $w['count'] - 1, $w['count'] > 2 ? 's' : '', self::HORIZON_DAYS) : '');
                if ($w['rule'] === 'cluster') $o .= '<p class="tt-when">From ' . self::h($this->dayTime($w['window'][0])) . self::h($more) . '</p>';
                else $o .= '<p class="tt-when">Next: ' . self::h($this->dayTime($w['window'][0])) . '&ndash;' . self::h($this->time($w['window'][1])) . ' (' . self::h(self::dur($w['window'][1] - $w['window'][0])) . ')' . self::h($more) . '</p>';
            }
            $o .= '<p>' . self::h($w['why']) . '</p><p class="tt-muted">' . self::h($w['example']) . '</p>';
            $links = [];
            foreach (array_unique($w['jobs']) as $id) {
                $j = $m['byId'][$id] ?? null;
                if (!$j) continue;
                $l = $this->link($j, $j['title']);
                $links[] = $l !== '' ? $l : self::h($j['title']);
            }
            if (isset($w['file'])) $o .= '<p><code class="tt-path">' . self::h($w['file']['text']) . '</code></p>';
            $o .= ($links ? '<p class="tt-flinks">Set up in: ' . implode(', ', $links) . '</p>' : '') . '</div></li>';
        }
        return $o . '</ul>';
    }

    private function dayCharts(array $m): string
    {
        $o = '<h3 class="tt-h">Day by day</h3>';
        $o .= '<p class="tt-muted">Server time (' . self::h($m['context']['timeZone']) . '), the next 7 days from today. A bar runs from the start to the expected end; '
            . 'a hatched end is an estimate, a dashed outline means the length is not known. ex. a mover run that took 40 minutes last night is drawn 40 minutes long.</p>';
        $o .= '<div class="tt-daypick" role="tablist" aria-label="Day" hidden>';
        foreach ($m['days'] as $i => [$s, $e]) {
            $o .= '<button type="button" role="tab" class="tt-dp" data-day="' . $i . '" aria-selected="' . ($i ? 'false' : 'true') . '">' . self::h($i === 0 ? 'Today' : date('D j', $s)) . '</button>';
        }
        $o .= '</div>';
        foreach ($m['days'] as $i => [$s, $e]) $o .= $this->dayChart($m, $i, $s, $e);
        $o .= $this->legend();
        return $o;
    }

    private function dayChart(array $m, int $i, int $ds, int $de): string
    {
        $len = $de - $ds;
        $pct = fn(int $t) => round(max(0, min($len, $t - $ds)) / $len * 100, 3);
        $rows = []; // category => job id => windows
        foreach ($m['windows'] as [$id, $s, $e]) {
            if ($e <= $ds || $s >= $de) continue;
            $j = $m['byId'][$id];
            $rows[self::catOf($j)][$id][] = [$s, $e];
        }
        $freq = [];
        foreach ($m['jobs'] as $j) {
            if (!empty($j['frequent']) && empty($j['schedule']['error']) && TtCron::dayMatches($j['schedule']['parsed'], (int)date('Y', $ds), (int)date('n', $ds), (int)date('j', $ds), $j['schedule']['guard'] ?? null)) $freq[] = $j;
        }
        $label = date('l j F', $ds);
        $o = '<section class="tt-day" data-day="' . $i . '" aria-label="' . self::h($label) . '"><h4 class="tt-dayh">' . self::h($label) . '</h4>';
        if (!$rows && !$freq) return $o . '<p class="tt-muted">Nothing is scheduled on this day.</p></section>';
        $today = $m['now'] >= $ds && $m['now'] < $de;
        $o .= '<div class="tt-scroll" tabindex="0" role="region" aria-label="' . self::h("Jobs on $label, scrolls sideways") . '"><div class="tt-chart' . ($today ? ' tt-today' : '') . '">';
        // the now label has a strip of its own above the hour axis, so it never covers an hour; near the right end it
        // points left
        if ($today) {
            $np = $pct($m['now']);
            $o .= '<div class="tt-lab tt-nowlab" aria-hidden="true"></div><div class="tt-nowrow" aria-hidden="true"><span class="tt-nowflag' . ($np > 88 ? ' tt-flip' : '') . '" style="left:' . $np . '%">Now ' . self::h($this->time($m['now'])) . '</span></div>';
        }
        // axis
        $o .= '<div class="tt-lab tt-axl">' . self::h(date('T', $ds)) . '</div><div class="tt-axis" aria-hidden="true">';
        for ($h = 0; $h <= 24; $h += 3) {
            $t = mktime($h, 0, 0, (int)date('n', $ds), (int)date('j', $ds), (int)date('Y', $ds));
            if ($h === 24) $t = $de;
            $o .= '<span class="tt-tick' . ($h % 6 ? ' tt-minor' : '') . '" style="left:' . $pct($t) . '%">' . sprintf('%02d', $h) . '</span>';
        }
        $o .= '</div>';
        // overlap bands and the now line, behind and over the rows
        $o .= '<div class="tt-over" aria-hidden="true">';
        $conflictIds = [];
        foreach ($m['overlaps'] as [$rule, $a, $b, $s, $e]) {
            if ($e <= $ds || $s >= $de) continue;
            $sev = TtAnalyse::severity($rule, $m['byId'][$a], $m['byId'][$b]);
            $conflictIds[$a][] = [$s, $e, $sev]; $conflictIds[$b][] = [$s, $e, $sev];
            $o .= '<span class="tt-band tt-band-' . $sev . '" style="left:' . $pct($s) . '%;width:' . max(0.3, $pct($e) - $pct($s)) . '%" title="' . self::h($m['byId'][$a]['title'] . ' and ' . $m['byId'][$b]['title'] . ' overlap ' . $this->time($s) . '-' . $this->time($e)) . '"></span>';
        }
        if ($today) $o .= '<span class="tt-now" style="left:' . $pct($m['now']) . '%"></span>';
        $o .= '</div>';
        foreach (array_keys(self::CATS) as $cat) {
            if (empty($rows[$cat])) continue;
            $o .= '<div class="tt-grp"><span class="tt-sw tt-c-' . $cat . '"></span>' . self::h(self::CATS[$cat][0]) . '</div>';
            foreach ($rows[$cat] as $id => $ws) {
                $j = $m['byId'][$id];
                $o .= '<div class="tt-lab" title="' . self::h(self::tip($j)) . '">' . self::h($j['title']) . '</div><div class="tt-track" role="list" aria-label="' . self::h($j['title']) . '">';
                foreach ($ws as [$s, $e]) {
                    $basis = $j['duration']['basis'] ?? 'default';
                    $kind = $basis === 'unknown' ? 'tt-unk' : (in_array($basis, ['history', 'config'], true) ? 'tt-known' : 'tt-est');
                    $sev = null;
                    foreach ($conflictIds[$id] ?? [] as [$cs, $ce, $cv]) if ($cs < $e && $ce > $s) $sev = ($sev === 'warning' || $cv === 'warning') ? 'warning' : 'info';
                    $cut = ($s < $ds ? ' tt-cutl' : '') . ($e > $de ? ' tt-cutr' : '');
                    $aria = $j['title'] . (!empty($j['description']) ? ' (' . $j['description'] . ')' : '') . ', ' . $this->time($s) . ' to ' . $this->time($e) . ' (' . self::dur($e - $s) . ', ' . ($kind === 'tt-known' ? 'measured' : ($kind === 'tt-est' ? 'estimated' : 'length unknown')) . ')' . ($sev ? ", overlaps another job, $sev" : '');
                    $o .= '<span role="listitem" class="tt-bar tt-c-' . self::catOf($j) . " $kind$cut" . ($sev ? " tt-hit tt-hit-$sev" : '') . '" style="left:' . $pct($s) . '%;width:' . max(0.45, $pct($e) - $pct($s)) . '%" title="' . self::h($aria) . '" aria-label="' . self::h($aria) . '">'
                        . ($sev ? '<i class="fa fa-' . ($sev === 'warning' ? 'exclamation-triangle' : 'info-circle') . '" aria-hidden="true"></i>' : '') . '</span>';
                }
                $o .= '</div>';
            }
        }
        if ($freq) $o .= '<div class="tt-grp">Runs all day</div>';
        foreach ($freq as $j) {
            $n = $j['schedule']['perDay'];
            $o .= '<div class="tt-lab" title="' . self::h(self::tip($j)) . '"><span class="tt-sw tt-c-' . self::catOf($j) . '"></span>' . self::h($j['title']) . '</div>';
            $o .= '<div class="tt-track"><span class="tt-dense tt-c-' . self::catOf($j) . '" role="img" aria-label="' . self::h(self::tip($j) . ', ' . $j['schedule']['text'] . ", $n runs a day") . '"></span><span class="tt-densel">' . self::h($j['schedule']['text'] . ", $n a day") . '</span></div>';
        }
        return $o . '</div></div></section>';
    }

    private function legend(): string
    {
        $o = '<div class="tt-legend" aria-label="Legend">';
        foreach (self::CATS as $cat => [$name, $slot]) {
            if ($cat === 'sleep') continue;
            $o .= '<span><span class="tt-sw tt-c-' . $cat . '"></span>' . self::h($name) . '</span>';
        }
        $o .= '<span><span class="tt-key tt-known"></span>measured length</span><span><span class="tt-key tt-est"></span>estimated length</span><span><span class="tt-key tt-unk"></span>length not known</span>';
        $o .= '<span><span class="tt-key tt-kband-warning"></span><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> overlap, warning</span><span><span class="tt-key tt-kband-info"></span><i class="fa fa-info-circle" aria-hidden="true"></i> overlap, information</span>';
        return $o . '</div>';
    }

    /** 7 x 24: how many jobs start in each hour, and the hours with an overlap. */
    private function weekGrid(array $m): string
    {
        $o = '<h3 class="tt-h">The week at a glance</h3><p class="tt-muted">Each square is one hour; the darker, the more jobs start in it. '
            . 'A hatched square holds an overlap. ex. a dark square at 04:00 on Sunday is where several weekly jobs start together. Jobs that run every few minutes are left out, they would fill every square.</p>';
        $cells = []; $over = [];
        foreach ($m['windows'] as [$id, $s, $e]) {
            foreach ($m['days'] as $i => [$ds, $de]) if ($s >= $ds && $s < $de) $cells[$i][(int)date('G', $s)][] = $m['byId'][$id]['title'];
        }
        foreach ($m['overlaps'] as [$rule, $a, $b, $s, $e]) {
            foreach ($m['days'] as $i => [$ds, $de]) {
                for ($t = max($s, $ds); $t < min($e, $de); $t = (intdiv($t, 3600) + 1) * 3600) {
                    $over[$i][(int)date('G', $t)][] = $m['byId'][$a]['title'] . ' + ' . $m['byId'][$b]['title'];
                }
            }
        }
        $max = 1;
        foreach ($cells as $d) foreach ($d as $l) $max = max($max, count($l));
        $o .= '<div class="tt-scroll" tabindex="0" role="region" aria-label="Week grid, scrolls sideways"><table class="tt-week"><thead><tr><th scope="col"><span class="tt-sr">Day</span></th>';
        for ($h = 0; $h < 24; $h++) $o .= '<th scope="col">' . ($h % 3 ? '<span class="tt-sr">' . sprintf('%02d', $h) . '</span>' : sprintf('%02d', $h)) . '</th>';
        $o .= '</tr></thead><tbody>';
        foreach ($m['days'] as $i => [$ds, $de]) {
            $o .= '<tr><th scope="row">' . self::h(date('D j', $ds)) . '</th>';
            for ($h = 0; $h < 24; $h++) {
                $l = $cells[$i][$h] ?? []; $ov = array_unique($over[$i][$h] ?? []);
                $lvl = $l ? (int)ceil(count($l) / $max * 4) : 0;
                $tip = sprintf('%s %02d:00', date('D j', $ds), $h) . ': ' . ($l ? count($l) . ' start' . (count($l) > 1 ? 's' : '') . ' (' . implode(', ', array_unique($l)) . ')' : 'no starts') . ($ov ? '; overlap: ' . implode('; ', $ov) : '');
                $o .= '<td class="tt-l' . $lvl . ($ov ? ' tt-ov' : '') . '" title="' . self::h($tip) . '"><span class="tt-sr">' . self::h($tip) . '</span>' . ($ov ? '<span aria-hidden="true">!</span>' : '') . '</td>';
            }
            $o .= '</tr>';
        }
        return $o . '</tbody></table></div>';
    }

    /** Jobs that do not run every week: their runs from day 8 to day 35. */
    private function later(array $m): string
    {
        $from = $m['days'][6][1]; $to = $m['days'][0][0] + self::HORIZON_DAYS * 86400;
        $runs = [];
        foreach ($m['jobs'] as $j) {
            if (($j['schedule']['type'] ?? '') !== 'cron' || !empty($j['frequent']) || !empty($j['schedule']['error'])) continue;
            $week = TtCron::expand($j['schedule']['parsed'], $m['days'][0][0], $from, 50, $j['schedule']['guard'] ?? null, $j['schedule']['every7Base'] ?? null);
            $after = TtCron::expand($j['schedule']['parsed'], $from, $to, 10, $j['schedule']['guard'] ?? null, $j['schedule']['every7Base'] ?? null);
            if (count($after) >= 4 && count($week) > 0) continue;   // weekly or more often: the 7 days above show it
            foreach ($after as $t) $runs[] = [$t, $j];
        }
        if (!$runs) return '';
        usort($runs, fn($a, $b) => $a[0] <=> $b[0]);
        $o = '<h3 class="tt-h">Later this month</h3><p class="tt-muted">Jobs that do not run every week, such as a monthly parity check, in the ' . self::HORIZON_DAYS . ' days from today.</p><ul class="tt-later">';
        foreach ($runs as [$t, $j]) $o .= '<li><span class="tt-sw tt-c-' . self::catOf($j) . '"></span><span class="tt-tnum">' . self::h($this->dayTime($t)) . '</span> ' . self::h($j['title']) . ' <span class="tt-muted">(' . self::h(self::dur($j['duration']['sec'])) . ')</span></li>';
        return $o . '</ul>';
    }

    /* ---------- List tab ---------- */

    public function listView(): string
    {
        $m = $this->model();
        $o = $this->nextRuns($m);
        $o .= '<h3 class="tt-h">Every scheduled job</h3><p class="tt-muted">Where each job comes from (file and line on the server), when it runs and how long it is expected to take. '
            . 'The length comes from the server\'s own history where there is one; otherwise the stated assumption is used for the overlap check.</p>';
        $o .= '<div class="tt-tablewrap"><table class="tt-jobs"><thead><tr><th scope="col">Job</th><th scope="col">When</th><th scope="col">Next run</th><th scope="col">Length</th><th scope="col">Defined in</th><th scope="col"><span class="tt-sr">Settings</span></th></tr></thead><tbody>';
        $jobs = $m['jobs'];
        usort($jobs, fn($a, $b) => (self::CATS[self::catOf($a)][1] ?: 99) <=> (self::CATS[self::catOf($b)][1] ?: 99) ?: strcmp($a['title'], $b['title']));
        foreach ($jobs as $j) {
            $s = $j['schedule'];
            $next = $s['type'] === 'cron' ? TtAnalyse::nextRun($j, $m['now']) : null;
            $when = $s['text'] ?? '';
            if (!empty($s['error'])) $when = 'cannot be read: ' . $s['error'];
            $len = $s['type'] === 'cron' && !empty($j['frequent']) ? 'short, runs ' . $s['perDay'] . ' times a day' : (isset($j['duration']) && in_array($s['type'], ['cron'], true) ? self::dur($j['duration']['sec']) . ' (' . ($j['duration']['basis'] === 'history' ? 'measured' : ($j['duration']['basis'] === 'config' ? 'from its settings' : 'assumed')) . ')' : '');
            $src = $j['source']['file'] . ($j['source']['line'] ? ':' . $j['source']['line'] : '');
            $o .= '<tr class="tt-row-' . self::catOf($j) . '"><td data-h="Job"><span class="tt-sw tt-c-' . self::catOf($j) . '"></span><strong>' . self::h($j['title']) . '</strong><br><span class="tt-muted">' . self::h($j['owner']) . '</span>'
                . (!empty($j['description']) ? '<br><span class="tt-muted">' . self::h($j['description']) . '</span>' : '')
                . (!empty($j['note']) ? '<br><span class="tt-muted">' . self::h($j['note']) . '</span>' : '')
                . (!empty($j['conditional']) ? '<br><span class="tt-muted">' . self::h($j['conditional']) . '</span>' : '') . '</td>'
                . '<td data-h="When">' . self::h($when) . (!empty($s['expr']) && $s['type'] === 'cron' ? '<br><code>' . self::h($s['expr']) . '</code>' : '') . '</td>'
                . '<td data-h="Next run" class="tt-tnum">' . ($next ? self::h($this->dayTime($next)) : '<span class="tt-muted">' . self::h($s['type'] === 'cron' ? 'not in the next year' : 'not on the clock') . '</span>') . '</td>'
                . '<td data-h="Length">' . self::h($len) . (isset($j['duration']['note']) && $len !== '' && empty($j['frequent']) ? '<br><span class="tt-muted">' . self::h($j['duration']['note']) . '</span>' : '') . '</td>'
                . '<td data-h="Defined in"><code class="tt-path">' . self::h($src) . '</code>' . (isset($j['source']['via']) ? '<br><span class="tt-muted">run by ' . self::h($j['source']['via']) . '</span>' : '') . '</td>'
                . '<td data-h="Settings">' . $this->link($j, 'Settings') . '</td></tr>';
        }
        $o .= '</tbody></table></div>';
        $ctx = $m['context'];
        $o .= '<h3 class="tt-h">What the schedules depend on</h3><ul class="tt-ctx">';
        $o .= '<li>Array started at boot: <strong>' . self::h($ctx['startArray'] === 'yes' ? 'yes' : 'no') . '</strong>. <span class="tt-muted">If not, jobs that need the array do nothing after a reboot until it is started. ex. a power cut at night.</span> <a class="tt-go" href="/Settings/DiskSettings">Disk Settings</a></li>';
        $o .= '<li>Default spin down delay: <strong>' . self::h($ctx['spindownDelay'] === '0' ? 'never' : ((int)$ctx['spindownDelay'] >= 15 ? $ctx['spindownDelay'] . ' minutes' : $ctx['spindownDelay'] . ' hour(s)')) . '</strong>. <span class="tt-muted">Jobs that touch the array wake the disks; ex. an hourly job keeps them spinning with a short delay.</span> <a class="tt-go" href="/Settings/DiskSettings">Disk Settings</a></li>';
        $o .= '<li>Time zone: <strong>' . self::h($ctx['timeZone']) . '</strong>. <span class="tt-muted">Cron runs every job in server time, which is the time shown here.</span> <a class="tt-go" href="/Settings/DateTime">Date and Time</a></li>';
        $o .= '</ul>';
        foreach ($m['notes'] as $n) $o .= '<p class="tt-note"><i class="fa fa-info-circle" aria-hidden="true"></i> ' . self::h($n) . '</p>';
        return $o;
    }

    private function nextRuns(array $m): string
    {
        $runs = [];
        foreach ($m['jobs'] as $j) {
            if (($j['schedule']['type'] ?? '') !== 'cron' || !empty($j['frequent']) || !empty($j['schedule']['error'])) continue;
            foreach (TtCron::expand($j['schedule']['parsed'], $m['now'], $m['now'] + self::HORIZON_DAYS * 86400, 10, $j['schedule']['guard'] ?? null, $j['schedule']['every7Base'] ?? null) as $t) $runs[] = [$t, $j];
        }
        usort($runs, fn($a, $b) => $a[0] <=> $b[0] ?: strcmp($a[1]['title'], $b[1]['title']));
        $runs = array_slice($runs, 0, 10);
        $o = '<h3 class="tt-h">Next 10 runs</h3><ol class="tt-next">';
        foreach ($runs as [$t, $j]) {
            $in = $t - $m['now'];
            $rel = $in < 3600 ? 'in ' . max(1, (int)round($in / 60)) . ' min' : ($in < 86400 ? 'in ' . self::dur($in) : 'in ' . (int)round($in / 86400) . ' day' . ((int)round($in / 86400) > 1 ? 's' : ''));
            $o .= '<li><span class="tt-sw tt-c-' . self::catOf($j) . '"></span><span class="tt-tnum">' . self::h($this->dayTime($t)) . '</span> <span class="tt-muted">(' . self::h($rel) . ')</span> ' . self::h($j['title']) . '</li>';
        }
        return $o . '</ol>';
    }

    /** Jobs that are not on the clock: array start/stop and boot events, and programs with their own timer. */
    public function offClock(): string
    {
        $m = $this->model();
        $ev = array_filter($m['jobs'], fn($j) => in_array($j['schedule']['type'] ?? '', ['event', 'daemon'], true));
        if (!$ev) return '';
        $o = '<h3 class="tt-h">Not on the clock</h3><p class="tt-muted">These run on an event or on their own timer, so they have no place in the timeline. ex. a user script set to "At Startup of Array".</p><ul class="tt-later">';
        foreach ($ev as $j) $o .= '<li><span class="tt-sw tt-c-' . self::catOf($j) . '"></span><strong>' . self::h($j['title']) . '</strong>: ' . self::h($j['schedule']['text']) . ' ' . $this->link($j) . '</li>';
        return $o . '</ul>';
    }
}
