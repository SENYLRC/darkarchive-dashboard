-- Dark Archive — organization roster sync
-- Idempotent: safe to re-run. Nothing is updated or deleted, only inserted.

-- ── 1. Report which of the expected organizations are absent ─────────────────
SELECT w.name AS missing_org
FROM (
    SELECT 'Bard College'                                  AS name UNION ALL
    SELECT 'Cary Institute of Ecosystem Studies'            UNION ALL
    SELECT 'Central New York Library Resources Council'     UNION ALL
    SELECT 'Chatham Public Library'                         UNION ALL
    SELECT 'Columbia-Greene Community College'              UNION ALL
    SELECT 'Ellenville Public Library & Museum'             UNION ALL
    SELECT 'Elting Memorial Library'                        UNION ALL
    SELECT 'Friends of Historic Kingston'                   UNION ALL
    SELECT 'Historic Huguenot Street'                       UNION ALL
    SELECT 'Hudson River Maritime Museum'                   UNION ALL
    SELECT 'Irvington Historical Society'                   UNION ALL
    SELECT 'Library Association of Rockland County'         UNION ALL
    SELECT 'Long Island Library Resources Council'          UNION ALL
    SELECT 'Moffat Library of Washingtonville'              UNION ALL
    SELECT 'Newburgh Free Library'                          UNION ALL
    SELECT 'Rochester Regional Library Council'             UNION ALL
    SELECT 'South Central Library Regional Council'         UNION ALL
    SELECT 'Sullivan BOCES School Library System'           UNION ALL
    SELECT 'Town of New Paltz'                              UNION ALL
    SELECT 'Westchester County Historical Society'          UNION ALL
    SELECT 'Western New York Library Resources Council'     UNION ALL
    SELECT 'Woodstock Public Library District'
) AS w
LEFT JOIN organizations o ON o.name = w.name
WHERE o.id IS NULL
ORDER BY w.name;

-- ── 2. Insert the missing rows ───────────────────────────────────────────────
-- These codes were read off the dropbox filenames themselves
-- (nchal_postcards_mets.zip, nwkpld_maverick_festival_photographs_mets.xml),
-- so they match what the ingest will derive. Confirm against your NUC records
-- before running if you want to be certain.

INSERT IGNORE INTO organizations (name, loc_code) VALUES
    ('Chatham Public Library',            'NCHAL'),
    ('Woodstock Public Library District', 'NWKPLD');

-- ── 3. Verify ────────────────────────────────────────────────────────────────
SELECT id, name, loc_code, created_at FROM organizations ORDER BY name;
