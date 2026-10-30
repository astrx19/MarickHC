# Security hardening notes

## Nginx equivalent of `.htaccess`
```nginx
location ~ ^/(database|includes|config|cron|bin)(/|$) { deny all; return 404; }
location ~ /\.(?!well-known) { deny all; }
location ~* \.(sql|bak|orig|old|swp|log|ini|sh|md|lock|env|ya?ml|zip|gz|tar|7z)$ { deny all; }
location ^~ /uploads/        { location ~ \.php$ { deny all; } }
location ^~ /assets/images/  { location ~ \.php$ { deny all; } }
```

## Production checklist
1. Rotate every key that ever lived in the old `config/*_settings.php` and `database/dev_notes.txt`
   (Semaphore, Android gateway, Gemini, MySQL root), then re-enter them in System Settings.
2. Set `MH_DB_USER`, `MH_DB_PASS`, `MH_DB_NAME` as environment variables; use a dedicated
   MySQL user (not `root`) limited to `marick_health_db`.
3. Ideally point the web server's document root at a `public/` folder, or keep this repo outside the web root
   and expose only `admin/`, `patient/`, `assets/`, `uploads/` and the top-level pages.
4. Cron (CLI only): `php /path/to/cron/send_daily_reminders.php`
   To allow an HTTP-triggered run, set `cron_run_key` (24+ random chars) in `system_settings`
   and ALSO remove `cron` from the deny rule - not recommended.
5. Provision the first super admin with `php bin/create_admin.php --generate`.
