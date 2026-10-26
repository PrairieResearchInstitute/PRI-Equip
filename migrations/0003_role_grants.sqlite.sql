-- 0003: role grants for NetID identity (owner, 2026-10-12; D-015). The SQLite
-- twin of 0003_role_grants.pgsql.sql, and the same table includes/schema.php
-- builds. Rows are never deleted; a revoked grant keeps revoked_at.
CREATE TABLE role_grants (
            grant_id       INTEGER PRIMARY KEY AUTOINCREMENT,
            principal_kind TEXT NOT NULL CHECK (principal_kind IN ('netid', 'group')),
            principal      TEXT NOT NULL CHECK (principal = lower(principal) AND principal <> ''),
            role           TEXT NOT NULL CHECK (role IN ('member', 'admin', 'code_editor')),
            lab_id         INTEGER REFERENCES labs(lab_id),
            note           TEXT NOT NULL DEFAULT '',
            granted_by     TEXT NOT NULL DEFAULT '',
            granted_at     TEXT NOT NULL,
            revoked_by     TEXT,
            revoked_at     TEXT
        );

CREATE INDEX idx_role_grants_principal ON role_grants (principal_kind, principal);
