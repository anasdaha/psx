<?php
// How often the data should refresh, in minutes. Use 5, 15 or 30 (and set your cron job to match).
// The page shows this number in its top and bottom notes.
$SYNC_MINUTES = 5;

// Secret used when sync.php is called from a web cron service (sync.php?key=...).
// Change it to any long random text.
$SYNC_KEY = 'change-me-to-a-long-random-string';
