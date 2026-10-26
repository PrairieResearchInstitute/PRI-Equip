<?php
/**
 * Migration/load-data.php: replaced by tools/pg-load.php (2026-10-10, PG5).
 *
 * The first loader read data/lab.sqlite inside the application folder and
 * .env only. The drive copy keeps its database in the configured data folder
 * and its PostgreSQL settings in pg.env beside it, so this file now hands
 * over to the loader that knows both, takes a VACUUM INTO snapshot, moves the
 * sequences itself and verifies by row, by checksum and by sequence:
 *
 *   php tools/pg-load.php --source <lab.sqlite>              into the test database
 *   php tools/pg-load.php --source <lab.sqlite> --target live --replace --owner-approved
 */

declare(strict_types=1);

fwrite(STDERR, "Migration/load-data.php is replaced by tools/pg-load.php. Run:\n"
    . "  php tools/pg-load.php --source <lab.sqlite>                 (into the test database)\n"
    . "  php tools/pg-load.php --source <lab.sqlite> --target live --replace --owner-approved\n");
exit(2);
