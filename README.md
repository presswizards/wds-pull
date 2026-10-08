# wds-pull

One-shot WordPress migration helper. Paste `wds-pull-snippet.php` into
ManageWP > Tools > Execute PHP and Run — it self-installs `wds-pull.php`
into `mu-plugins`, no configuration, no admin context needed.

After install, the target server drives everything via authenticated
`?wds_action=` requests (token auto-derives from the site hostname):

- `ping` — liveness, no auth
- `sizes` — wp-content subdir sizes, DB size, capability flags
- `zip&path=<rel>` — zip one path under wp-content via `zip` binary
- `db` — mysqldump (host/socket/port aware)
- `cleanup` — deletes the wdsnap dir

Delete `wp-content/mu-plugins/wds-pull.php` after cutover.
