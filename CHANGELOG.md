# Changelog

Copied by the build from the <CHANGES> block of unraid-timetable.plg; edit the .plg, not this file.

## 2026.11.12h
- The mover finding now says that a running parity check does not stop the scheduled mover; only the Move button is greyed out.
- With Mover Tuning set not to move during a parity check, an overlap is shown as a skipped mover run (information), and a warning appears when a check that runs for more than a day, or a check paused between nightly parts on older Mover Tuning versions, keeps the mover from running for days.
- New warnings: a parity check set to start on days close together, so it starts again soon after it finishes; a pool or array-disk scrub or balance during a parity check; the same command in two crontabs, so it runs twice.
- New information: a scrub and a balance of the same pool at once; a job that is still running when S3 Sleep may put the server to sleep; crontab lines that are in no schedule file on the flash drive and are gone after the next rebuild.
- Appdata Backup names the containers it stops ("Stops 12 containers at 03:00: plex, sonarr, ... (+4 more)") in its row, its tooltip and its findings.
- Recognised: the QNAP Hardware temperature history sample, the FolderView Plus scheduled folder backup, and sysstat's system activity data and daily report.
- On today's timeline the "Now" label has its own strip above the hours, so it no longer covers an hour label.
- Text read from other plugins' files is checked and escaped everywhere it is shown; settings of the wrong type are skipped, and very large logs are read only in part.
## 2026.11.12g
- User scripts whose folder name has spaces showed as "scripts"; they now show their own name, also in tooltips. A script's description file is shown in its tooltip.
- Pool maintenance has names: "Balance, pool ssd (btrfs)", "Scrub, pool ssd (btrfs)", "Scrub, pool tank (zfs)", with the options in words and a link to the pool's page. A btrfs scrub takes its length from the server's last scrub record.
- Other unrecognised jobs show what they act on, not only the script name. NUT UPS statistics and the Appdata Cleanup Plus purge timer are recognised.
- Findings that repeat every hour or every day say so ("every hour, at :17") instead of a count in the hundreds, and a window that only runs while a parity check is unfinished says that. Heavy jobs that run every 30 minutes or less often keep their bars.
## 2026.11.12f
- First release.
- Settings, Timetable shows every scheduled job on the server: Unraid's parity check, mover, TRIM and checks, User Scripts, Appdata Backup, auto updates, Fix Common Problems, Parity Check Tuning, Mover Tuning, S3 Sleep, and any other line in the crontab.
- Timeline: the next 7 days, one row per job, from the start to the expected end; the week at a glance; and the runs later in the month.
- List: the next 10 runs, and every job with the file and line it comes from, when it runs and how long it takes.
- Findings: jobs that overlap where it matters, such as the mover during a parity check or a backup that stops containers while the mover runs, each with the reason and an example.
- The length of a run comes from the server's own history where there is one (parity check history, the system log, the plugins' logs); otherwise the assumption is shown.
- Read-only: it changes no setting and runs nothing. Each job links to the page where it is set up.
