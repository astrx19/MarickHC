# Backup and recovery (Paper REQ78)

**Backing up**
- On demand: Health Head > Database Backup > "Download full backup". Store the file offline and encrypted; it holds patient data.
- Automatic: Hostinger hPanel > Advanced > Cron Jobs, daily: `php /home/USER/domains/YOURSITE/public_html/cron/backup_database.php`.
  Files go to `storage/backups/` (denied from the web by `.htaccess`); 14 days are kept. Copy them off the server weekly.
- Hostinger's own hPanel > Backups is a second, independent layer; keep it enabled.

**Restoring**
1. hPanel > Databases > phpMyAdmin, select the clinic database.
2. Import the `.sql` (or unzip the `.sql.gz` first). Tables are dropped and recreated.
3. Log in as Health Head and check recent patients, inventory counts and the activity log.
4. If the file is larger than the upload limit, use hPanel's import tool or split by table.

Test a restore on a copy of the database at least once per term; an untested backup is not a backup.
