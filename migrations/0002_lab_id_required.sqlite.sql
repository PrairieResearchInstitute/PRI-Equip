-- 0002: lab_id required (owner, 2026-10-11, Q-07) applies to PostgreSQL only.
-- On SQLite the change needs a table rebuild of the live file, which the owner
-- chose to leave alone while PaCELab runs on PostgreSQL. This twin records the
-- number on SQLite and changes nothing; tests/run-tests.php keeps listing the
-- SQLite nullability difference as known drift.
SELECT 1;
