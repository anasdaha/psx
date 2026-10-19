# PSX Top 10 Movers — files for your own hosting

What's here
- `index.html` — the page. It reads `data.json` and checks for new data every minute.
- `sync.php` — fetches prices from PSX (dps.psx.com.pk only) and writes `data.json`.
- `predict.php` — the Predictions rules and their daily back-test (used by sync.php).
- `config.php` — the sync interval (`$SYNC_MINUTES`, 5 by default) and a secret key.
- `.htaccess` — keeps `config.php` and the `cache/` folder private.
- `cache/` — PSX daily closing files (one year, about 6 MB). The zip already includes them, so predictions work on the first sync.

Needs: any host with PHP 7.4+ and the curl and zip extensions (standard on cPanel / Hostinger / Namecheap shared hosting).

## Deploy (cPanel-style shared hosting)
1. Unzip `psx-top10-hosting.zip` and upload the folder into `public_html`, e.g. `public_html/psx/`.
2. Edit `config.php`: set `$SYNC_MINUTES` to 5, 15 or 30, and change `$SYNC_KEY` to a long random text.
3. Make sure the folder is writable by PHP (usually it already is; otherwise set `psx/` and `psx/cache/` to 755).
4. Add a cron job (cPanel > Cron Jobs). For every 30 minutes:
   `*/30 * * * * php /home/YOUR_CPANEL_USER/public_html/psx/sync.php >/dev/null 2>&1`
   For every 15 minutes use `*/15` instead. Keep it the same as `$SYNC_MINUTES`.
5. Run it once by hand to create `data.json`: open `https://YOUR-DOMAIN/psx/sync.php?key=YOUR_SYNC_KEY` (it takes 10–60 seconds the first time).
6. Open `https://YOUR-DOMAIN/psx/`.

No cron on your plan? The page asks `sync.php?auto=1` to refresh whenever the data is older than the interval, so it still updates while someone has it open. A free web-cron service (e.g. cron-job.org) calling `sync.php?key=YOUR_SYNC_KEY` also works.

## Notes
- During market hours prices are PSX's latest traded prices at the time of each sync; after the close they are the closing prices.
- If PSX can't be reached, the last good `data.json` stays in place and the page shows the sync as overdue.
- The top and bottom notes on the page show the interval from `config.php` and the time of the last sync automatically.

## Hostinger (hPanel)
1. hPanel > Websites > Manage (akhtardaha.com) > Advanced > Cron Jobs.
2. Choose "Custom" and paste this command (one line):
   `curl -s "https://akhtardaha.com/psx/sync.php?key=YOUR_SYNC_KEY" > /dev/null`
3. Set it to run every 5 minutes (minute field `*/5`, all others `*`), then Save.
4. Keep `$SYNC_MINUTES` in config.php the same as the cron interval.
