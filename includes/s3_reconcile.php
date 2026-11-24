<?php
/**
 * S3 <-> packages reconciliation helpers.
 *
 * The bucket stores the actual archived assets (images, audio, etc.) under
 * keys like:
 *   digital-archive-senylrc/digital-archive-senylrc-<loc>/<YYYY>/<MM>/<name>-<uuid>.<ext>
 * e.g.
 *   digital-archive-senylrc/digital-archive-senylrc-nanb/2025/05/
 *     WWI_Memorial_Banner_and_Gymnasium-44e48045-07ed-4554-95f3-36e9f9ed0f8d.7z
 *
 * darkarchive itself only stores the descriptive METS XML (as a .tar.gz in
 * uploads/), not this asset — so "matching" a packages row to an S3 object
 * means matching identity (organization + cleaned name), not byte-for-byte
 * content. clean_original_name() (xml_metadata.php) already strips the LOC
 * prefix, Archivematica UUIDs, dates, and boilerplate from a package's
 * original_name for exactly this reason — it's reused here unchanged so a
 * package and its S3 object reduce to the same identity string.
 */

/** Recognized archive/asset extensions to strip before cleaning a key's filename segment. */
const S3_ASSET_EXTENSIONS = ['.tar.gz', '.tar.bz2', '.tar.xz', '.tgz', '.tbz2', '.txz', '.tar',
                              '.zip', '.7z', '.xml', '.pdf', '.tif', '.tiff', '.jpg', '.jpeg', '.wav', '.mp3', '.mp4'];

/**
 * Parse an "s3://bucket/key" URI. Returns null if $uri isn't one — used to
 * check glacier_location values, most of which are NOT S3 URIs (many are
 * empty, some are literal Glacier vault ARNs, some are free text).
 */
function parse_s3_uri(string $uri): ?array {
    if (!preg_match('#^s3://([^/]+)/(.+)$#', trim($uri), $m)) return null;
    return ['bucket' => $m[1], 'key' => $m[2]];
}

/**
 * Org-folder segments that appear in the bucket but share no recognizable
 * relationship with the app's own loc_code — confirmed from real bucket
 * contents (2026-09), not guessed. Extend this as new mismatches surface.
 *   FOHK  -> NYKIFH  (Friends of Historic Kingston)
 *   LARC  -> NYNECLA (Library Association of Rockland County — the
 *                      consortium's own umbrella folder for several small
 *                      member libraries, each with its own micro-code
 *                      embedded in the filename rather than the folder)
 *   NNGFL -> NNEBG   (Newburgh Free Library — confirmed by the object's own
 *                      filename inside that folder: "...newburgh-free-library...")
 */
const S3_ORG_SEGMENT_ALIASES = [
    'FOHK'  => 'NYKIFH',
    'LARC'  => 'NYNECLA',
    'NNGFL' => 'NNEBG',
];

/**
 * Resolve one S3 org-folder segment (e.g. "nanb", "nevpl-epl", "FOHK") to a
 * known packages.loc_code, or null if it matches nothing.
 *
 * Real bucket contents use three shapes for this segment, discovered against
 * the live bucket (2026-09) — the first sample this was designed against was
 * only the simplest of the three:
 *   1. the bare loc_code                    e.g. "nanb", "nchal"
 *   2. loc_code + a free-text suffix         e.g. "nevpl-epl", "nhudc-CGCC"
 *   3. an unrelated abbreviation             e.g. "FOHK", "larc" — see the
 *      alias table above; also covers alias+suffix, e.g. "nngfl-newburgh-public"
 *
 * $known_loc_codes: every loc_code actually present in the packages table
 * (uppercase) — passed in rather than queried here, so this stays a pure,
 * unit-testable function with no DB access of its own.
 */
function resolve_s3_org_segment(string $segment, array $known_loc_codes): ?string {
    $upper = strtoupper($segment);

    if (in_array($upper, $known_loc_codes, true)) return $upper;
    if (isset(S3_ORG_SEGMENT_ALIASES[$upper])) return S3_ORG_SEGMENT_ALIASES[$upper];

    foreach ($known_loc_codes as $code) {
        if (str_starts_with($upper, $code . '-')) return $code;
    }
    foreach (S3_ORG_SEGMENT_ALIASES as $alias => $code) {
        if (str_starts_with($upper, $alias . '-')) return $code;
    }

    return null;
}

/**
 * Parse one S3 key against the digital-archive-senylrc/<org-segment>/.../<name>
 * convention. Returns null for anything that doesn't resolve — folder-marker
 * keys (trailing "/"), an org segment resolve_s3_org_segment() can't place,
 * or a filename with no recognized asset extension (seen in the live bucket:
 * long extensionless token strings that look like multipart-upload debris,
 * not real archived content — reported separately as "unrecognized" rather
 * than guessed at as a package).
 *
 * The path between the org segment and the filename is NOT required to have
 * any particular depth — real keys use org/YYYY/MM/file, org/YYYY/file, and
 * org/file all three (this was the second gap the first version had: it
 * required exactly two numeric levels). Only the final path segment matters
 * for matching; year/month are informational only in this app and are not
 * used by reconcile_s3(), so they are not extracted here at all.
 */
function parse_archive_s3_key(string $key, array $known_loc_codes): ?array {
    if (str_ends_with($key, '/')) return null; // folder marker, no content

    if (!preg_match('#^digital-archive-senylrc/digital-archive-senylrc-([a-z0-9][a-z0-9-]*)/(.+)$#i', $key, $m)) {
        return null;
    }
    [, $org_segment, $rest] = $m;

    $loc_code = resolve_s3_org_segment($org_segment, $known_loc_codes);
    if ($loc_code === null) return null;

    $parts    = explode('/', $rest);
    $filename = array_pop($parts);
    if ($filename === '') return null;

    $stem = $filename;
    $matched_ext = false;
    foreach (S3_ASSET_EXTENSIONS as $ext) {
        if (strlen($stem) > strlen($ext) && str_ends_with(strtolower($stem), $ext)) {
            $stem = substr($stem, 0, -strlen($ext));
            $matched_ext = true;
            break;
        }
    }
    if (!$matched_ext) return null; // no recognized extension — likely not real content

    return [
        'loc_code'   => $loc_code,
        'clean_name' => clean_original_name($stem, $loc_code),
    ];
}

/** The identity string used to match a parsed S3 key against a packages row. */
function package_identity(string $loc_code, string $clean_name): string {
    return strtoupper($loc_code) . '::' . $clean_name;
}

/**
 * Same identity, computed from a packages.original_name value.
 *
 * packages.original_name is always the ingested METS XML's own filename
 * (e.g. "nanb_WWI_Memorial_Banner_and_Gymnasium_mets.xml") — clean_original_name()
 * does not strip file extensions on its own (build_stored_basename() does that
 * separately before calling it), so the .xml suffix is stripped here first.
 * Skipping this step would leave every DB-side identity carrying ".xml" while
 * S3-side identities never do, making every package look falsely unmatched.
 */
function package_identity_from_original_name(string $original_name, string $loc_code): string {
    $stem = preg_replace('/\.xml$/i', '', $original_name);
    return package_identity($loc_code, clean_original_name($stem, $loc_code));
}

/**
 * List every object key under $prefix in $bucket, paginated automatically.
 * The only function in this file that touches the AWS SDK for listing —
 * everything else here works on plain arrays so it can be tested without
 * real AWS credentials or network access.
 */
function s3_list_all_keys(\Aws\S3\S3Client $s3, string $bucket, string $prefix): array {
    $keys = [];
    $paginator = $s3->getPaginator('ListObjectsV2', [
        'Bucket' => $bucket,
        'Prefix' => $prefix,
    ]);
    foreach ($paginator as $page) {
        foreach ($page['Contents'] ?? [] as $obj) {
            $keys[] = $obj['Key'];
        }
    }
    return $keys;
}

/**
 * Does this exact key exist in the bucket? Used for packages with a
 * recorded s3:// location.
 *
 * Returns true/false only when actually determined by a confirmed 404;
 * returns null when the check itself couldn't be completed — most commonly
 * a 403 Access Denied because the key lives outside whatever prefix this
 * app's IAM policy currently grants GetObject on (e.g. a legacy
 * digital_archive_<COUNCIL>/ key predating the digital-archive-senylrc/
 * naming convention). A 403 says nothing about whether the object exists —
 * callers must NOT treat null the same as false, or a permissions gap gets
 * reported as data loss.
 */
function s3_object_exists(\Aws\S3\S3Client $s3, string $bucket, string $key): ?bool {
    try {
        $s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
        return true;
    } catch (\Aws\S3\Exception\S3Exception $e) {
        if ((int)$e->getStatusCode() === 404) return false;
        error_log("s3_object_exists: $bucket/$key — " . $e->getAwsErrorCode() . ' ' . $e->getAwsErrorMessage());
        return null;
    } catch (\Aws\Exception\AwsException $e) {
        error_log("s3_object_exists: $bucket/$key — " . $e->getAwsErrorMessage());
        return null;
    }
}

/**
 * Core reconciliation, operating entirely on plain arrays — no AWS or DB
 * objects — so it can be unit tested without either.
 *
 * $packages: rows from the packages table (id, loc_code, org_name,
 *   original_name, glacier_location, processed_at).
 * $bucket: the configured bucket name — a glacier_location pointing at a
 *   DIFFERENT bucket is flagged separately rather than silently ignored.
 * $exists_checker: callable(string $key): ?bool — checks one exact S3 key.
 *   true = confirmed exists, false = confirmed 404, null = could not be
 *   determined (e.g. 403 — the key is outside whatever prefix this app's
 *   IAM policy currently grants GetObject on). null must never be treated
 *   as false by the caller.
 * $all_keys: every key actually listed in the bucket (for the fallback
 *   fuzzy match and for finding S3-only orphans).
 *
 * Returns:
 *   'db_missing_confirmed'  — recorded an exact s3:// key, confirmed 404
 *   'db_unverified'         — recorded an exact s3:// key, but the existence
 *                             check itself failed (403 etc.) — NOT the same
 *                             claim as "missing"; usually means the IAM
 *                             policy doesn't cover this key's prefix
 *   'db_missing_probable'   — no usable location recorded, and no S3 object
 *                             matches this package's identity either
 *   'db_found_unrecorded'   — no usable location recorded, but a matching S3
 *                             object DOES exist — glacier_location should be filled in
 *   'wrong_bucket'          — glacier_location points at a different bucket than configured
 *   's3_orphans'            — S3 objects (matching the archive convention) with no
 *                             corresponding packages row at all
 *   'unrecognized_keys'     — S3 keys under the prefix that don't match the
 *                             archive convention at all, listed for visibility
 */
function reconcile_s3(array $packages, string $bucket, callable $exists_checker, array $all_keys): array {
    // loc_codes actually in use, so resolve_s3_org_segment() adapts to
    // whatever organizations currently exist rather than a hardcoded list.
    $known_loc_codes = array_values(array_unique(array_map(
        fn($p) => strtoupper($p['loc_code']), $packages
    )));

    // Index every listed key by parsed identity, and collect what didn't
    // parse. Folder-marker keys (trailing "/", zero-byte S3 console
    // artifacts) carry no information and are dropped silently rather than
    // padding out the unrecognized-keys report.
    $s3_by_identity     = [];
    $unrecognized_keys  = [];
    foreach ($all_keys as $key) {
        if (str_ends_with($key, '/')) continue;

        $parsed = parse_archive_s3_key($key, $known_loc_codes);
        if ($parsed === null) {
            $unrecognized_keys[] = $key;
            continue;
        }
        $s3_by_identity[package_identity($parsed['loc_code'], $parsed['clean_name'])][] = $key;
    }

    $db_missing_confirmed = [];
    $db_unverified        = [];
    $db_missing_probable  = [];
    $db_found_unrecorded  = [];
    $wrong_bucket         = [];
    $matched_identities   = []; // every DB identity successfully accounted for, so leftovers = orphans
    // Exact S3 keys confirmed via a package's own recorded glacier_location.
    // Tracked separately from $matched_identities: a package's original_name
    // and the real asset's S3 filename don't always clean down to the same
    // identity string (e.g. METS "hrmm_wav_part_a" vs. the uploaded asset
    // "hrmm_wav_a" — a real naming drift seen in production, not a parser
    // bug), so identity alone would falsely orphan a key we've already
    // confirmed by exact match.
    $claimed_keys = [];

    foreach ($packages as $p) {
        $identity = package_identity_from_original_name($p['original_name'], $p['loc_code']);
        $loc      = trim((string)($p['glacier_location'] ?? ''));
        $parsed_uri = $loc !== '' ? parse_s3_uri($loc) : null;

        if ($parsed_uri !== null && $parsed_uri['bucket'] !== $bucket) {
            $wrong_bucket[] = $p + ['recorded_bucket' => $parsed_uri['bucket']];
            $matched_identities[$identity] = true; // has SOME location on file — not a "missing" case
            continue;
        }

        if ($parsed_uri !== null) {
            // Exact, authoritative check against the recorded key.
            $matched_identities[$identity] = true;
            $exists = $exists_checker($parsed_uri['key']);
            if ($exists === true) {
                $claimed_keys[$parsed_uri['key']] = true;
            } elseif ($exists === false) {
                $db_missing_confirmed[] = $p + ['expected_key' => $parsed_uri['key']];
            } else {
                // Could not verify (403 etc.) — report separately rather than
                // asserting the object is missing.
                $db_unverified[] = $p + ['expected_key' => $parsed_uri['key']];
            }
            continue;
        }

        // No usable recorded location — fall back to identity matching against
        // the full listing. This is best-effort: a package whose original_name
        // doesn't clean down to the same string as its S3 filename will show as
        // "probable", not "confirmed", missing.
        if (isset($s3_by_identity[$identity])) {
            $matched_identities[$identity] = true;
            $db_found_unrecorded[] = $p + ['found_keys' => $s3_by_identity[$identity]];
        } else {
            $db_missing_probable[] = $p;
        }
    }

    $s3_orphans = [];
    foreach ($s3_by_identity as $identity => $keys) {
        if (isset($matched_identities[$identity])) continue;

        // Even when the identity as a whole isn't matched, drop any individual
        // key already claimed by a package's exact recorded glacier_location.
        $unclaimed = array_values(array_filter($keys, fn($k) => !isset($claimed_keys[$k])));
        if ($unclaimed !== []) {
            $s3_orphans[] = ['identity' => $identity, 'keys' => $unclaimed];
        }
    }

    return compact(
        'db_missing_confirmed', 'db_unverified', 'db_missing_probable', 'db_found_unrecorded',
        'wrong_bucket', 's3_orphans', 'unrecognized_keys'
    );
}
