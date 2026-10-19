# PSX Top 10 Movers — files for your own hosting

What's here
- `index.html` — the page. It reads `data.json` and checks for new data every minute.
- `sync.php` — fetches prices from PSX (dps.psx.com.pk only) and writes `data.json`.
- `predict.php` — the Predictions score, its daily back-test and the prediction history (used by sync.php).
- `history/` — created by sync.php: one saved prediction per trading day (kept 90 days) plus `index.json`.
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

## How the predictions work (detail)
The page keeps this short for visitors; the full method is here and in the comments at the top of `predict.php`.

- **Research.** Twenty factors were tested on 13 months of PSX prices (Sep 2025 to Oct 2026). Momentum (buying recent winners) and trend-following did not work on PSX. Five factors held up and are used: low 60-day volatility, small daily high-low range, a 5-day fall compared with the stock's sector, no big one-day spike in the last 20 days, and closeness to the 52-week high.
- **Score.** Each factor is ranked within the stock's group (Sharia or non-Sharia). The score is the weighted average of those ranks, shown from 0 to 100. Weights differ by period (tomorrow / week / month); see `MODELS` in `predict.php`. Stocks averaging under 50,000 shares a day are skipped.
- **Columns.** Swing is the average gap between a day's high and low, as a share of the price, over the last 20 days. "From high" is how far the price is below its highest point of the past year.
- **Back-test.** Recalculated every day on the past year of data: the share of picks that beat the median stock of their group over the period, plus their average return compared with the group. The edge is small: picks beat the typical stock a little more often than chance (about 54%), but their average return is close to the group's.
- **Survivorship bias.** The back-test uses today's KSE-100 members, so it leaves out companies that dropped out of the index. That makes past results look a little better than they really were.
- **History and live record.** Every sync saves the day's prediction to `history/YYYY-MM-DD.json` (every scored stock's score, price and factor values, not only the top 10). The last save after the close is the final one. Once its period has passed (1, 5 or 21 trading days), each pick is graded on closing prices with the same test as the back-test, and `history/index.json` keeps the running live record. Files older than 90 days are deleted. Pull the folder with rsync to use it for research.
- **Prices.** Returns use a dividend-safe index (each day's close divided by PSX's previous close, chained), so ex-dividend drops don't count as losses. The movers tables use plain prices, which is why they carry the "XD" tag.
