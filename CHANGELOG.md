# Changelog

Copied by the build from the <CHANGES> block of unraid-timetable.plg; edit the .plg, not this file.

## 2026.11.12f
- First release.
- Settings, Timetable shows every scheduled job on the server: Unraid's parity check, mover, TRIM and checks, User Scripts, Appdata Backup, auto updates, Fix Common Problems, Parity Check Tuning, Mover Tuning, S3 Sleep, and any other line in the crontab.
- Timeline: the next 7 days, one row per job, from the start to the expected end; the week at a glance; and the runs later in the month.
- List: the next 10 runs, and every job with the file and line it comes from, when it runs and how long it takes.
- Findings: jobs that overlap where it matters, such as the mover during a parity check or a backup that stops containers while the mover runs, each with the reason and an example.
- The length of a run comes from the server's own history where there is one (parity check history, the system log, the plugins' logs); otherwise the assumption is shown.
- Read-only: it changes no setting and runs nothing. Each job links to the page where it is set up.
