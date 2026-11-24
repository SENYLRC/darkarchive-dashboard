-- ============================================================
-- Dark Archive — Full Database Schema
-- SENYLRC XML Package Manager
--
-- Fresh install:
--   mysql -h <rds-endpoint> \
--     --ssl-ca=/etc/mysql-certs/global-bundle.pem \
--     -u <admin_user> -p darkarchive < schema.sql
--
-- All statements use IF NOT EXISTS / IGNORE so this file is
-- safe to re-run against an existing database.
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ── Organizations ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS organizations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    loc_code    VARCHAR(50)  NOT NULL UNIQUE,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regional resource councils that member organizations belong to (six today —
-- see INSERT IGNORE below). Reports filter/group by council via this table
-- rather than by a text column on organizations, so a council can be renamed
-- in one place.
CREATE TABLE IF NOT EXISTS library_councils (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    loc_code    VARCHAR(50)  NOT NULL UNIQUE,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO library_councils (name, loc_code) VALUES
    ('Long Island Library Resources Council',           'NBELL'),
    ('Western New York Library Resources Council',      'NBULRC'),
    ('Southeastern New York Library Resources Council',  'NHIGS'),
    ('South Central Library Regional Council',           'NISCR'),
    ('Rochester Regional Library Council',                'NRRR'),
    ('Central New York Library Resources Council',       'NSYCNL');

-- Each organization optionally belongs to one council, assigned by an admin
-- on the Organizations page. NULL means "not yet assigned" — same convention
-- as packages.file_count below. Added after the initial round of orgs, so
-- existing rows start unassigned; re-running this whole file against an
-- already-migrated database errors on this ALTER (column already exists) —
-- skip it the same way as the file_count/size_gb ALTERs above it in that case.
ALTER TABLE organizations ADD COLUMN council_id INT DEFAULT NULL;
ALTER TABLE organizations ADD FOREIGN KEY (council_id) REFERENCES library_councils(id) ON DELETE SET NULL;


-- ── Packages ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS packages (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    org_id           INT NOT NULL,
    filename         VARCHAR(255) NOT NULL,
    original_name    VARCHAR(255) NOT NULL,
    file_size        INT NOT NULL DEFAULT 0,
    size_gb          DECIMAL(10,4) DEFAULT NULL,
    glacier_location VARCHAR(500) DEFAULT NULL,
    processed_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes            TEXT,
    FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Number of <mets:file> entries described in the package's METS. Added after
-- the initial round of ingest, so existing rows are backfilled separately —
-- see backfill_file_counts.php. NULL means "not yet counted", not zero.
-- Re-running this whole file against an already-migrated database adds just
-- this column (CREATE TABLE above is a no-op since the table already exists).
ALTER TABLE packages ADD COLUMN file_count INT DEFAULT NULL;

-- Widened from DECIMAL(10,3) to DECIMAL(10,4) so a size entered in MB and
-- divided by 1000 on the Edit Package screen doesn't lose its 4th decimal
-- digit on save (e.g. 825.7 MB -> 0.8257 GB). Safe to re-run: MODIFY COLUMN
-- on an already-widened column is a no-op.
ALTER TABLE packages MODIFY COLUMN size_gb DECIMAL(10,4) DEFAULT NULL;

-- report.php's file-list export matches each System:FileName row to its
-- following System:FileSize row via a correlated subquery
-- (package_id = ?, element = 'System:FileSize', id > ?, ORDER BY id LIMIT 1).
-- Without a composite index, MySQL can only narrow by package_id, then has
-- to scan and sort the rest per invocation -- for a package with hundreds
-- of thousands of file_metadata rows, run once per filename, that's what
-- turned a report into a multi-day-long query that locked the whole site
-- (2026-09-03 incident).
-- NOT safe to blindly re-run like the ALTERs above -- ADD INDEX errors with
-- "Duplicate key name" if idx_package_element_id already exists. Check first:
--   SHOW INDEX FROM file_metadata WHERE Key_name = 'idx_package_element_id';
-- Skip this statement if that returns a row.
ALTER TABLE file_metadata ADD INDEX idx_package_element_id (package_id, element, id);

-- Prevent duplicate packages (same filename for same org)
ALTER TABLE packages ADD UNIQUE KEY uq_org_filename (org_id, original_name);


-- ── File Metadata ─────────────────────────────────────────────────────────────
-- One row per leaf XML element extracted from each package.
-- Handles ExifTool, PREMIS, and any other namespaced XML formats.

CREATE TABLE IF NOT EXISTS file_metadata (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    package_id  INT NOT NULL,
    element     VARCHAR(255) NOT NULL,
    value       TEXT,
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
    INDEX idx_element (element),
    INDEX idx_element_value (element, value(255)),
    INDEX idx_package (package_id),
    INDEX idx_package_element_id (package_id, element, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Users ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(100) NOT NULL UNIQUE,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','readonly') NOT NULL DEFAULT 'readonly',
    active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── User ↔ Organization assignments ──────────────────────────────────────────
-- Readonly users are tied to one or more orgs.
-- Admin users have implicit access to all orgs (no rows needed here).
-- Assigning a user to a council's own organization row (matched to
-- library_councils by loc_code, or by name) also grants access to every org
-- whose council_id is that council — computed at request time in
-- includes/auth.php (council_member_org_ids / council_ids_for_orgs), so no
-- extra table is needed.

CREATE TABLE IF NOT EXISTS user_organizations (
    user_id INT NOT NULL,
    org_id  INT NOT NULL,
    PRIMARY KEY (user_id, org_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (org_id)  REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Password Reset Tokens ─────────────────────────────────────────────────────
-- Tokens expire after 1 hour and are single-use.
-- Cleaned up automatically by the cron job (process_uploads.php).

CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used       TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Seed Data ─────────────────────────────────────────────────────────────────

-- Default organizations (edit as needed before first run)
INSERT IGNORE INTO organizations (name, loc_code) VALUES
    ('Southeastern New York Library Resources Council', 'NHIGS'),
    ('Bard College',                                    'NANB'),
    ('Vassar College Libraries',                        'NPV'),
    ('Cary Institute of Ecosystem Studies',             'NMBRIES');

-- Default admin account
-- Username: admin
-- Password: password
-- !! CHANGE THIS IMMEDIATELY after first login via the Users page !!
INSERT IGNORE INTO users (username, email, password_hash, role)
VALUES (
    'admin',
    'admin@yourdomain.org',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin'
);


-- ── Verify ────────────────────────────────────────────────────────────────────
SELECT 'organizations'   AS table_name, COUNT(*) AS rows FROM organizations
UNION ALL
SELECT 'library_councils', COUNT(*) FROM library_councils
UNION ALL
SELECT 'packages',        COUNT(*) FROM packages
UNION ALL
SELECT 'file_metadata',   COUNT(*) FROM file_metadata
UNION ALL
SELECT 'users',           COUNT(*) FROM users
UNION ALL
SELECT 'user_organizations', COUNT(*) FROM user_organizations
UNION ALL
SELECT 'password_resets', COUNT(*) FROM password_resets;
