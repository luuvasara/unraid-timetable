<?php
/* Timetable: cron expressions, as Unraid's cron daemon (dcron 4.5) reads them, turned into run times.
 *
 * Fields: minute hour day-of-month month day-of-week. Lists (1,15), ranges (1-5), steps (*\/10, 0-30/5) and names
 * (jan, mon) are accepted; day-of-week 7 is Sunday like 0. When both day fields are restricted, a day matches when
 * either one does (the common cron rule, which dcron follows). @hourly, @daily, @weekly, @monthly and @yearly are taken
 * at the start of their period; @reboot is an event, not a time.
 *
 * Unraid's own parity check schedule puts a shell test in front of the command for "last day of the month" and
 * "week N of the month" (update.parity.php); those tests are read here as guards, so the run days shown are the
 * days the check really starts. Nothing in this file reads or writes anything on the server. */

final class TtCron
{
    private const NAMES_MON = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    private const NAMES_DOW = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
    private const MACROS = ['@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *'];

    /** Split one crontab line. Returns null for blank lines, comments and variable assignments; otherwise
     *  ['expr' => '5 fields' | '@reboot', 'macro' => '@daily'|null, 'command' => string, 'user' => string|null]. */
    public static function splitLine(string $line, bool $userField = false): ?array
    {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') return null;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $t)) return null;   // MAILTO=, PATH=, ...
        $user = null;
        if ($t[0] === '@') {
            $p = preg_split('/\s+/', $t, 2);
            $macro = strtolower($p[0]);
            $rest = $p[1] ?? '';
            if ($userField && preg_match('/^(\S+)\s+(.*)$/s', $rest, $m)) { $user = $m[1]; $rest = $m[2]; }
            if ($macro === '@reboot') return ['expr' => '@reboot', 'macro' => '@reboot', 'command' => $rest, 'user' => $user];
            if (!isset(self::MACROS[$macro])) return ['expr' => $p[0], 'macro' => $macro, 'command' => $rest, 'user' => $user, 'error' => 'unknown keyword ' . $p[0]];
            return ['expr' => self::MACROS[$macro], 'macro' => $macro, 'command' => $rest, 'user' => $user];
        }
        $p = preg_split('/\s+/', $t, $userField ? 7 : 6);
        if (count($p) < 6) return ['expr' => $t, 'macro' => null, 'command' => '', 'user' => null, 'error' => 'fewer than 5 time fields and a command'];
        if ($userField && count($p) === 7) { $user = $p[5]; $cmd = $p[6]; } else { $cmd = $p[5]; }
        return ['expr' => implode(' ', array_slice($p, 0, 5)), 'macro' => null, 'command' => $cmd, 'user' => $user];
    }

    /** Parse 5 fields into sets. Throws InvalidArgumentException with a short reason on a bad field. */
    public static function parse(string $expr): array
    {
        $f = preg_split('/\s+/', trim($expr));
        if (count($f) !== 5) throw new InvalidArgumentException('needs 5 time fields, has ' . count($f));
        $min = self::field($f[0], 0, 59, [], 'minute');
        $hour = self::field($f[1], 0, 23, [], 'hour');
        $dom = self::field($f[2], 1, 31, [], 'day of month');
        $mon = self::field($f[3], 1, 12, self::NAMES_MON, 'month');
        $dow = self::field($f[4], 0, 7, self::NAMES_DOW, 'day of week');
        if (isset($dow[7])) { $dow[0] = true; unset($dow[7]); }
        return ['min' => $min, 'hour' => $hour, 'dom' => $dom, 'mon' => $mon, 'dow' => $dow,
            'domStar' => self::isStar($f[2]), 'dowStar' => self::isStar($f[4])];
    }

    private static function isStar(string $s): bool
    {
        return $s === '*' || $s === '?';
    }

    private static function field(string $s, int $lo, int $hi, array $names, string $what): array
    {
        $set = [];
        foreach (explode(',', strtolower($s)) as $part) {
            if ($part === '') throw new InvalidArgumentException("empty item in the $what field");
            $step = 1;
            if (strpos($part, '/') !== false) {
                [$part, $st] = explode('/', $part, 2);
                if (!ctype_digit($st) || (int)$st < 1) throw new InvalidArgumentException("bad step \"/$st\" in the $what field");
                $step = (int)$st;
            }
            if ($part === '*' || $part === '?') { $a = $lo; $b = $hi; }
            elseif (strpos($part, '-') !== false) {
                [$x, $y] = explode('-', $part, 2);
                $a = self::value($x, $lo, $hi, $names, $what); $b = self::value($y, $lo, $hi, $names, $what);
                if ($a > $b) throw new InvalidArgumentException("range $x-$y runs backwards in the $what field");
            } else {
                $a = self::value($part, $lo, $hi, $names, $what);
                $b = $step > 1 ? $hi : $a;      // "5/10" means from 5 to the end, every 10
            }
            for ($i = $a; $i <= $b; $i += $step) $set[$i] = true;
        }
        ksort($set);
        return $set;
    }

    private static function value(string $x, int $lo, int $hi, array $names, string $what): int
    {
        if (isset($names[$x])) return $names[$x];
        if (!ctype_digit($x)) throw new InvalidArgumentException("\"$x\" is not a number in the $what field");
        $v = (int)$x;
        if ($v < $lo || $v > $hi) throw new InvalidArgumentException("$v is outside $lo-$hi in the $what field");
        return $v;
    }

    /** The guard Unraid's parity schedule puts before the command, if any: ['kind' => ..., 'label' => ...]. */
    public static function guard(string $command): ?array
    {
        $c = preg_replace('/\s+/', ' ', $command);
        if (strpos($c, '[[ $(date +%e -d +1day) -eq 1 ]]') !== false) return ['kind' => 'lastday', 'label' => 'last day of the month'];
        if (strpos($c, '[[ $(date +%e) -le 7 ]]') !== false) return ['kind' => 'week', 'from' => 1, 'label' => 'first week of the month'];
        foreach ([7 => 2, 14 => 3, 21 => 4] as $back => $n) {
            if (strpos($c, "[[ \$(date +%e -d -{$back}days) -le 7 ]]") !== false)
                return ['kind' => 'week', 'from' => 1 + $back, 'label' => ['', '', 'second', 'third', 'fourth'][$n] . ' week of the month'];
        }
        if (strpos($c, '[[ $(date +%e -d +7days) -le 7 ]]') !== false) return ['kind' => 'lastweek', 'label' => 'last week of the month'];
        if (strpos($c, '86400)%7)) -eq 0 ]]') !== false) return ['kind' => 'every7', 'label' => 'every 7th day, counted from the start of the check'];
        return null;
    }

    private static function guardOk(?array $g, int $y, int $m, int $d, ?int $every7Base): bool
    {
        if ($g === null) return true;
        $dim = tt_days_in_month($m, $y);
        switch ($g['kind']) {
            case 'lastday': return $d === $dim;
            case 'week': return $d >= $g['from'] && $d <= $g['from'] + 6;
            case 'lastweek': return $d > $dim - 7;
            case 'every7':
                if ($every7Base === null) return true;   // base unknown: shown daily, the list says so
                $days = intdiv(mktime(12, 0, 0, $m, $d, $y) - $every7Base, 86400);
                return $days % 7 === 0;
        }
        return true;
    }

    /** True when the day matches the day fields (and month). */
    public static function dayMatches(array $p, int $y, int $m, int $d, ?array $guard = null, ?int $every7Base = null): bool
    {
        if (!isset($p['mon'][$m])) return false;
        $dow = (int)date('w', mktime(12, 0, 0, $m, $d, $y));
        $domHit = isset($p['dom'][$d]);
        $dowHit = isset($p['dow'][$dow]);
        if ($p['domStar'] && $p['dowStar']) $ok = true;
        elseif ($p['domStar']) $ok = $dowHit;
        elseif ($p['dowStar']) $ok = $domHit;
        else $ok = $domHit || $dowHit;
        return $ok && self::guardOk($guard, $y, $m, $d, $every7Base);
    }

    /** Start times (unix, server local time) in [$from, $to). Stops after $cap results. */
    public static function expand(array $p, int $from, int $to, int $cap = 100000, ?array $guard = null, ?int $every7Base = null): array
    {
        $out = [];
        $day = mktime(0, 0, 0, (int)date('n', $from), (int)date('j', $from), (int)date('Y', $from));
        for ($guardDays = 0; $day < $to && $guardDays < 4000; $guardDays++) {
            $y = (int)date('Y', $day); $m = (int)date('n', $day); $d = (int)date('j', $day);
            if (self::dayMatches($p, $y, $m, $d, $guard, $every7Base)) {
                foreach ($p['hour'] as $h => $_) {
                    foreach ($p['min'] as $mi => $_2) {
                        $t = mktime($h, $mi, 0, $m, $d, $y);
                        if ((int)date('G', $t) !== $h) continue;   // a time skipped by a clock change does not run
                        if ($t >= $from && $t < $to) {
                            $out[] = $t;
                            if (count($out) >= $cap) return $out;
                        }
                    }
                }
            }
            $day = mktime(0, 0, 0, $m, $d + 1, $y);
        }
        return $out;
    }

    /** Runs on a day the job runs at all: keeps minute-by-minute jobs out of the week grid and the overlap check. */
    public static function runsPerRunDay(array $p): int
    {
        return count($p['hour']) * count($p['min']);
    }

    /** Plain words for an expression, ex. "every day at 03:40", "Mondays at 00:11", "every 5 minutes". */
    public static function describe(array $p, ?array $guard = null, ?string $macro = null): string
    {
        $mins = array_keys($p['min']); $hours = array_keys($p['hour']);
        $time = self::timeWords($mins, $hours);
        $dayNames = ['Sundays', 'Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays'];
        $monNames = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $days = '';
        if ($guard !== null && $guard['kind'] !== 'every7') {
            $days = ($p['dowStar'] ? 'on the ' . $guard['label'] : 'on ' . implode(', ', array_map(fn($d) => $dayNames[$d], array_keys($p['dow']))) . ' in the ' . $guard['label']);
        } elseif (!$p['domStar'] && !$p['dowStar']) {
            $days = 'on day ' . self::listWords(array_keys($p['dom'])) . ' of the month and on ' . implode(', ', array_map(fn($d) => $dayNames[$d], array_keys($p['dow'])));
        } elseif (!$p['domStar']) {
            $days = 'on day ' . self::listWords(array_keys($p['dom'])) . ' of the month';
        } elseif (!$p['dowStar']) {
            $days = 'on ' . implode(', ', array_map(fn($d) => $dayNames[$d], array_keys($p['dow'])));
        }
        $months = count($p['mon']) === 12 ? '' : ' in ' . implode(', ', array_map(fn($m) => $monNames[$m], array_keys($p['mon'])));
        $s = trim(($days === '' ? (str_starts_with($time, 'every') ? '' : 'every day ') : $days . ' ') . $time) . $months;
        if ($guard !== null && $guard['kind'] === 'every7') $s .= ', ' . $guard['label'];
        if ($macro !== null && $macro !== '@reboot') $s .= " ($macro)";
        return $s;
    }

    private static function timeWords(array $mins, array $hours): string
    {
        // an even step from the start of the range: "*/7" is 0, 7, ... 56 (the last gap to the next hour is shorter)
        $step = fn(array $v, int $n) => count($v) > 1 && $v[0] < $v[1] - $v[0] && count(array_unique(array_map(fn($i) => $v[$i + 1] - $v[$i], range(0, count($v) - 2)))) === 1 && end($v) + ($v[1] - $v[0]) >= $n;
        $allH = count($hours) === 24;
        if (count($mins) === 60 && $allH) return 'every minute';
        if ($allH && $step($mins, 60)) return 'every ' . ($mins[1] - $mins[0]) . ' minutes';
        if ($allH && count($mins) === 1) return 'every hour at minute ' . $mins[0];
        if (count($mins) === 1 && count($hours) <= 4)
            return 'at ' . implode(', ', array_map(fn($h) => sprintf('%02d:%02d', $h, $mins[0]), $hours));
        if (count($mins) === 1 && $step($hours, 24)) return sprintf('every %d hours at minute %d', $hours[1] - $hours[0], $mins[0]);
        if (count($mins) === 1) return 'at minute ' . $mins[0] . ' of hours ' . self::listWords($hours);
        return 'at minutes ' . self::listWords($mins) . ' of ' . ($allH ? 'every hour' : 'hours ' . self::listWords($hours));
    }

    private static function listWords(array $v): string
    {
        if (count($v) > 2) {
            $run = true;
            for ($i = 1; $i < count($v); $i++) if ($v[$i] !== $v[$i - 1] + 1) $run = false;
            if ($run) return $v[0] . '-' . end($v);
        }
        return implode(', ', $v);
    }
}

function tt_days_in_month(int $m, int $y): int
{
    return (int)date('t', mktime(12, 0, 0, $m, 1, $y));
}
