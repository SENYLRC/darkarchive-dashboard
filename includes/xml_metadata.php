<?php
require_once __DIR__ . '/archive.php';
/**
 * Walk an XML file and extract every leaf element as element => value pairs.
 * Handles namespaced tags (e.g. <System:FileName>...) by using DOM's
 * tagName, which preserves the prefix (System:FileName, File:FileSize, etc).
 * Transparently handles .xml and .xml.gz files.
 *
 * Returns an array of ['element' => string, 'value' => string]
 */
function extract_xml_elements(string $xml_path): array {
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;

    // Works for .tar.gz packages, legacy .xml.gz, and plain .xml alike.
    $stream = stored_xml_stream_path($xml_path);
    if ($stream === null || !@$dom->load($stream)) {
        return [];
    }

    $results = [];
    $walk = function (DOMNode $node) use (&$walk, &$results) {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;

            // Does this element have any element children?
            $hasElementChildren = false;
            foreach ($child->childNodes as $grandchild) {
                if ($grandchild->nodeType === XML_ELEMENT_NODE) {
                    $hasElementChildren = true;
                    break;
                }
            }

            if ($hasElementChildren) {
                $walk($child);
            } else {
                $value = trim($child->textContent);
                if ($value !== '') {
                    $results[] = [
                        'element' => $child->tagName, // includes prefix e.g. System:FileName
                        'value'   => $value,
                    ];
                }
            }
        }
    };

    $walk($dom);
    return $results;
}

/**
 * Store extracted elements for a package into file_metadata.
 */
function store_xml_metadata(PDO $db, int $package_id, string $xml_path): void {
    $elements = extract_xml_elements($xml_path);
    if (!$elements) return;

    $stmt = $db->prepare("INSERT INTO file_metadata (package_id, element, value) VALUES (?, ?, ?)");
    foreach ($elements as $e) {
        // Cap value length defensively; TEXT column handles large but keep sane
        $val = mb_substr($e['value'], 0, 5000);
        $stmt->execute([$package_id, $e['element'], $val]);
    }
}


/**
 * Read the raw METS creation timestamp using XMLReader (streams, stops at the
 * first match, so cost is independent of file size).
 */
function mets_created_via_reader(string $xml_path): ?string {
    $reader = new XMLReader();
    $source = str_ends_with($xml_path, '.gz') ? 'compress.zlib://' . $xml_path : $xml_path;
    if (!@$reader->open($source)) return null;

    $created = null;   // dmdSec/@CREATED   — preferred
    $hdr     = null;   // metsHdr/@CREATEDATE — fallback
    while (@$reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) continue;
        // localName ignores the prefix, so mets:metsHdr and a default-namespace
        // metsHdr both match.
        if ($reader->localName === 'metsHdr' && $hdr === null) {
            $hdr = $reader->getAttribute('CREATEDATE');
        }
        if ($reader->localName === 'dmdSec') {
            $created = $reader->getAttribute('CREATED');
            if ($created !== null && $created !== '') break;
        }
    }
    $raw = ($created !== null && $created !== '') ? $created : $hdr;
    $reader->close();
    return ($raw === null || $raw === '') ? null : $raw;
}

/**
 * Fallback for hosts without ext-xml: the METS header sits in the first few
 * hundred bytes, so scanning the head of the file is sufficient and needs no
 * XML extension at all.
 */
function mets_created_via_scan(string $xml_path): ?string {
    $gz = str_ends_with($xml_path, '.gz');
    $h  = $gz ? @gzopen($xml_path, 'rb') : @fopen($xml_path, 'rb');
    if (!$h) return null;
    $head = $gz ? gzread($h, 65536) : fread($h, 65536);
    $gz ? gzclose($h) : fclose($h);
    if ($head === false || $head === '') return null;

    if (preg_match('/<[^>]*\bdmdSec\b[^>]*\bCREATED="([^"]+)"/i',    $head, $m)) return $m[1];
    if (preg_match('/<[^>]*\bmetsHdr\b[^>]*\bCREATEDATE="([^"]+)"/i', $head, $m)) return $m[1];
    return null;
}

/**
 * Read the METS creation timestamp from an XML package.
 *
 * Precedence:
 *   1. first <mets:dmdSec CREATED="..."/>  — the descriptive-metadata creation
 *      time; this is the value the archive treats as authoritative.
 *   2. <mets:metsHdr CREATEDATE="..."/>   — fallback when no dmdSec carries one.
 *
 * The two differ by a couple of seconds (metsHdr is written last), so the
 * choice is deliberate rather than incidental.
 *
 * Handles .xml and .xml.gz. Returns 'Y-m-d H:i:s' for MySQL DATETIME, or null
 * if absent/unparseable so the caller can fall back to the ingest time.
 */
function extract_mets_created(string $xml_path): ?string {
    $raw = class_exists('XMLReader')
        ? mets_created_via_reader($xml_path)
        : mets_created_via_scan($xml_path);

    if ($raw === null || $raw === '') return null;

    try {
        $dt = new DateTimeImmutable($raw);
    } catch (Throwable $e) {
        error_log("METS: unparseable creation date '$raw' in $xml_path");
        return null;
    }

    // Only shift to UTC when the source actually carried an offset; a naive
    // timestamp is stored exactly as written.
    if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $raw)) {
        $dt = $dt->setTimezone(new DateTimeZone('UTC'));
    }

    return $dt->format('Y-m-d H:i:s');
}

/**
 * Count the files a package was built from: the <mets:file> entries in the
 * fileSec's USE="original" group.
 *
 * Counting every <mets:file> double-counts: Archivematica lists each
 * original AND its normalized preservation copy (USE="preservation"), plus
 * a couple of non-content entries (metadata, submissionDocumentation). A
 * package built from 428 files therefore showed 858. Only the original
 * group matches what was actually submitted.
 *
 * Falls back to counting every <mets:file> when the METS has no
 * USE="original" group at all (hand-built or non-Archivematica METS), so
 * those don't drop to zero.
 *
 * Streams with XMLReader so cost is independent of file size. Works on a
 * plain .xml path (ingest time) or an already-stored .tar.gz/.xml.gz
 * (backfill time) via stored_xml_stream_path().
 */
function count_mets_files(string $xml_path): ?int {
    if (!class_exists('XMLReader')) return null;

    $stream = stored_xml_stream_path($xml_path);
    if ($stream === null) return null;

    $reader = new XMLReader();
    if (!@$reader->open($stream)) return null;

    $all = 0;
    $original = 0;
    $has_original_group = false;
    $in_original = false;

    while (@$reader->read()) {
        if ($reader->prefix !== 'mets') continue;

        if ($reader->localName === 'fileGrp') {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                $in_original = ($reader->getAttribute('USE') === 'original');
                if ($in_original) $has_original_group = true;
                if ($reader->isEmptyElement) $in_original = false;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                $in_original = false;
            }
        } elseif ($reader->localName === 'file' && $reader->nodeType === XMLReader::ELEMENT) {
            $all++;
            if ($in_original) $original++;
        }
    }
    $reader->close();
    return $has_original_group ? $original : $all;
}

/**
 * List the files a package was built from — one row per <mets:file> in the
 * fileSec's USE="original" group, the same set count_mets_files() counts, so
 * the View page's file list always agrees with the package's file count.
 *
 * The older file list (view.php's fallback) was built from ExifTool
 * <...:FileName> elements in file_metadata, so it only showed files that
 * happened to have ExifTool output. A SUNY Morrisville package of 123 files
 * (122 PDFs, 1 PNG) listed just the PNG, because Archivematica ran ExifTool
 * on the image but recorded only PREMIS details for the PDFs.
 *
 * Each row is filled from the file's amdSec (linked by the mets:file ADMID):
 *   - PREMIS, present for every file: originalName, size, PRONOM format
 *     name/version/key, dateCreatedByApplication, the first message digest
 *   - ExifTool, only where it ran: FileType, MIMEType, CreateDate,
 *     FileModifyDate, FileAccessDate, MajorBrand, WriterName, ReaderName
 *
 * Returns null when there is no USE="original" group (hand-built or
 * non-Archivematica METS) or the file can't be read, so callers can fall back
 * to the file_metadata grouping.
 *
 * Streams with XMLReader, expanding one amdSec / mets:file at a time, so
 * memory is bounded by the per-file summary rows, not the size of the XML.
 */
function list_mets_original_files(string $xml_path): ?array {
    if (!class_exists('XMLReader')) return null;

    $stream = stored_xml_stream_path($xml_path);
    if ($stream === null) return null;

    $reader = new XMLReader();
    if (!@$reader->open($stream)) return null;

    $amd         = [];    // amdSec ID => summary fields
    $originals   = [];    // [href, [ADMID, ...]] in fileSec order
    $has_group   = false;
    $in_original = false;

    $ok = @$reader->read();
    while ($ok) {
        $is_mets = $reader->prefix === 'mets';
        $type    = $reader->nodeType;

        if ($is_mets && $type === XMLReader::ELEMENT && $reader->localName === 'amdSec') {
            $id   = (string)$reader->getAttribute('ID');
            $node = $reader->expand();
            if ($id !== '' && $node) $amd[$id] = mets_amdsec_summary($node);
            $ok = @$reader->next(); // skip the subtree just expanded
            continue;
        }

        if ($is_mets && $reader->localName === 'fileGrp') {
            if ($type === XMLReader::ELEMENT) {
                $in_original = ($reader->getAttribute('USE') === 'original');
                if ($in_original) $has_group = true;
                if ($reader->isEmptyElement) $in_original = false;
            } elseif ($type === XMLReader::END_ELEMENT) {
                $in_original = false;
            }
        } elseif ($in_original && $is_mets && $type === XMLReader::ELEMENT && $reader->localName === 'file') {
            $admids = preg_split('/\s+/', trim((string)$reader->getAttribute('ADMID')), -1, PREG_SPLIT_NO_EMPTY);
            $href   = '';
            $node   = $reader->expand();
            if ($node) {
                foreach ($node->getElementsByTagName('*') as $el) {
                    if ($el->localName === 'FLocat') {
                        $href = (string)($el->getAttributeNS('http://www.w3.org/1999/xlink', 'href')
                                         ?: $el->getAttribute('xlink:href'));
                        break;
                    }
                }
            }
            $originals[] = [$href, $admids];
            $ok = @$reader->next();
            continue;
        }

        $ok = @$reader->read();
    }
    $reader->close();

    if (!$has_group) return null;

    $files = [];
    foreach ($originals as [$href, $admids]) {
        // Merge every amdSec this file points at; first value found wins.
        $info = [];
        foreach ($admids as $aid) $info += $amd[$aid] ?? [];

        // Prefer the FLocat path (keeps sub-folders, e.g. "letters/1921.pdf");
        // fall back to PREMIS originalName with its %transferDirectory% prefix
        // removed.
        $name = $href !== '' ? $href : preg_replace('/^%[^%]+%/', '', $info['originalName'] ?? '');
        $name = preg_replace('#^objects/#', '', $name);

        $ext   = strtoupper(pathinfo($name, PATHINFO_EXTENSION));
        $bytes = isset($info['size']) && ctype_digit($info['size']) ? (int)$info['size'] : null;
        $format = trim(($info['formatName'] ?? '') . ' ' . ($info['formatVersion'] ?? ''));

        $files[] = [
            'name'        => $name,
            'size'        => $bytes,
            'created'     => $info['CreateDate'] ?? $info['DateTimeOriginal'] ?? $info['dateCreatedByApplication'] ?? null,
            'modified'    => $info['FileModifyDate'] ?? null,
            'accessed'    => $info['FileAccessDate'] ?? null,
            'file_type'   => $info['FileType'] ?? ($ext !== '' ? $ext : null),
            'file_ext'    => $info['FileTypeExtension'] ?? ($ext !== '' ? strtolower($ext) : null),
            'mime'        => $info['MIMEType'] ?? null,
            'major_brand' => $info['MajorBrand'] ?? null,
            'writer'      => $info['WriterName'] ?? null,
            'reader'      => $info['ReaderName'] ?? null,
            'pkg_date'    => null,
            'format'      => $format !== '' ? $format : null,
            'puid'        => $info['formatRegistryKey'] ?? null,
            'checksum'    => isset($info['messageDigest'])
                             ? trim(($info['messageDigestAlgorithm'] ?? '') . ':' . $info['messageDigest'], ':')
                             : null,
        ];
    }
    return $files;
}

/**
 * list_mets_original_files() with a per-package cache on disk.
 *
 * Parsing is proportional to XML size: ~0.1s for a small package but ~7s for
 * the largest (Newburgh, 4,876 files), and the whole archive takes minutes —
 * fine once on the View page, far too slow for a Reports file list spanning
 * hundreds of packages. Stored packages never change after ingest, so each
 * result is cached as gzipped JSON in UPLOAD_DIR/.filelists/<stored name>.json.gz
 * and reused until the package file is newer than the cache.
 *
 * A null result (no USE="original" group) is cached too, so fallback packages
 * aren't re-parsed every time. The cache is best-effort: if it can't be read
 * or written (permissions, disk full) the list is just computed directly.
 * Bump FILELIST_CACHE_VERSION whenever the row format changes so stale
 * entries are ignored. backfill_file_lists.php pre-builds the whole cache.
 */
const FILELIST_CACHE_VERSION = 1;

function cached_mets_original_files(string $stored_path): ?array {
    if (!is_file($stored_path)) return null; // also guards an empty filename (UPLOAD_DIR itself)
    $mtime = filemtime($stored_path);

    $cache = dirname($stored_path) . '/.filelists/' . basename($stored_path) . '.json.gz';
    if (@filemtime($cache) >= $mtime) {
        $data = json_decode((string)@gzdecode((string)@file_get_contents($cache)), true);
        if (is_array($data) && ($data['v'] ?? null) === FILELIST_CACHE_VERSION && array_key_exists('files', $data)) {
            return $data['files'];
        }
    }

    $files = list_mets_original_files($stored_path);

    // Write to a temp file then rename, so a concurrent reader never sees a
    // half-written cache entry.
    $dir = dirname($cache);
    if (is_dir($dir) || @mkdir($dir, 0775, true)) {
        $tmp  = $cache . '.' . getmypid() . '.tmp';
        $json = json_encode(['v' => FILELIST_CACHE_VERSION, 'files' => $files], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false && @file_put_contents($tmp, gzencode($json, 6)) !== false) {
            @chmod($tmp, 0664);
            if (!@rename($tmp, $cache)) @unlink($tmp);
        }
    }
    return $files;
}

/**
 * Pull the per-file summary fields out of one expanded <mets:amdSec>.
 * PREMIS fields are taken only from PREMIS-namespaced elements (so a tool's
 * own "size" element can't shadow premis:size); ExifTool fields match on
 * local name whatever their group prefix (System:, File:, Composite:, ...).
 * The first occurrence of each field wins.
 */
function mets_amdsec_summary(DOMNode $amdsec): array {
    static $premis = ['originalName', 'size', 'formatName', 'formatVersion', 'formatRegistryKey',
                      'dateCreatedByApplication', 'messageDigestAlgorithm', 'messageDigest'];
    static $exif   = ['FileType', 'FileTypeExtension', 'MIMEType', 'CreateDate', 'DateTimeOriginal',
                      'FileModifyDate', 'FileAccessDate', 'MajorBrand', 'WriterName', 'ReaderName'];

    $out = [];
    if (!$amdsec instanceof DOMElement) return $out;
    foreach ($amdsec->getElementsByTagName('*') as $el) {
        $local = $el->localName;
        if (isset($out[$local])) continue;
        $is_premis = str_starts_with((string)$el->namespaceURI, 'http://www.loc.gov/premis');
        if (($is_premis && in_array($local, $premis, true)) || (!$is_premis && in_array($local, $exif, true))) {
            $v = trim($el->textContent);
            if ($v !== '') $out[$local] = $v;
        }
    }
    return $out;
}

/**
 * Read the package-level <premis:originalName> from a METS file.
 *
 * Archivematica writes one originalName per object. The first is the
 * intellectual entity (the package itself):
 *     wkpld_maverick_festival_photographs-2223d8e0-...-00c5e2629d80
 * Every later one is a file path carrying a %transferDirectory% or
 * %SIPDirectory% variable, so those are skipped by rejecting any value
 * containing '%'.
 *
 * Streams with XMLReader and stops at the first match.
 */
function premis_original_name_via_scan(string $xml_path): ?string {
    $gz = str_ends_with($xml_path, '.gz');
    $h  = $gz ? @gzopen($xml_path, 'rb') : @fopen($xml_path, 'rb');
    if (!$h) return null;

    // The package-level originalName sits in the first dmdSec, well inside the
    // opening 2 MB even for a 37 MB package.
    $head = '';
    while (strlen($head) < 2097152) {
        $chunk = $gz ? gzread($h, 262144) : fread($h, 262144);
        if ($chunk === false || $chunk === '') break;
        $head .= $chunk;
    }
    $gz ? gzclose($h) : fclose($h);

    if (!preg_match_all('#<[A-Za-z0-9]*:?originalName>(.*?)</[A-Za-z0-9]*:?originalName>#s',
                        $head, $m)) {
        return null;
    }
    foreach ($m[1] as $value) {
        $value = trim($value);
        if ($value !== '' && !str_contains($value, '%')) return $value;
    }
    return null;
}

function extract_premis_original_name(string $xml_path): ?string {
    if (!class_exists('XMLReader')) return premis_original_name_via_scan($xml_path);

    $reader = new XMLReader();
    $source = str_ends_with($xml_path, '.gz') ? 'compress.zlib://' . $xml_path : $xml_path;
    if (!@$reader->open($source)) return null;

    $found = null;
    while (@$reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) continue;
        if ($reader->localName !== 'originalName') continue;

        $value = trim((string)$reader->readString());
        if ($value === '' || str_contains($value, '%')) continue; // file-level path
        $found = $value;
        break;
    }
    $reader->close();
    return $found ?? premis_original_name_via_scan($xml_path);
}

/**
 * Reduce a premis:originalName to a clean, human-readable slug for filenames.
 *
 * Removes, in order:
 *   - any %variable% wrapper and directory path
 *   - UUIDs (Archivematica appends one to every object name)
 *   - standalone hex blobs of 8+ chars (truncated checksums)
 *   - standalone digit runs of 5+ (batch/sequence noise). Four-digit runs are
 *     kept because they are almost always meaningful years in this collection
 *     (woodstock-bulletin-1929, Arendt_Tiffs_2024).
 *   - the organization's LOC code, with or without its leading N, since the
 *     final filename already begins with it
 *
 * Whatever survives is reduced to [A-Za-z0-9._-] with separators collapsed.
 */
function clean_original_name(string $raw, string $loc_code): string {
    $name = $raw;

    // Drop %transferDirectory% style wrappers and any directory component.
    $name = preg_replace('/%[^%]*%/', '', $name);
    $name = str_replace('\\', '/', $name);
    if (str_contains($name, '/')) {
        $name = substr($name, strrpos($name, '/') + 1);
    }

    // UUIDs, then loose hex blobs.
    $name = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '', $name);
    $name = preg_replace('/(?<![A-Za-z0-9])[0-9a-f]{8,}(?![A-Za-z0-9])/i', '', $name);

    // Full ISO dates are redundant — the filename already carries the create
    // date — but a bare 4-digit year is usually meaningful here
    // (Arendt_Tiffs_2024, woodstock-bulletin-1929), so those survive.
    $name = preg_replace('/(?<!\d)\d{4}[-_]\d{2}[-_]\d{2}(?!\d)/', '', $name);

    // Long digit runs, but keep 4-digit years.
    $name = preg_replace('/(?<!\d)\d{5,}(?!\d)/', '', $name);

    // Boilerplate that appears across most exports and carries no information.
    $name = preg_replace('/digital[-_]archive[-_]senylrc[-_]?/i', '', $name);
    $name = preg_replace('/(?<![A-Za-z0-9])us[-_](east|west)([-_]\d)?(?![A-Za-z0-9])/i', '', $name);

    // Every package here is METS; the suffix distinguishes nothing.
    $name = preg_replace('/[-_]?mets$/i', '', $name);

    // The LOC code, with or without the leading N (originalName often omits it).
    $codes = [$loc_code];
    if (strlen($loc_code) > 1 && strtoupper($loc_code[0]) === 'N') {
        $codes[] = substr($loc_code, 1);
    }
    foreach ($codes as $code) {
        $name = preg_replace('/(?<![A-Za-z0-9])' . preg_quote($code, '/') . '(?![A-Za-z0-9])/i', '', $name);
    }

    // Normalise to filesystem-safe characters and tidy separators.
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    $name = preg_replace('/_{2,}/', '_', $name);
    $name = preg_replace('/-{2,}/', '-', $name);
    $name = trim($name, "._-");

    return $name !== '' ? $name : 'package';
}

/**
 * Build the standardised stored basename (no extension):
 *     <LOC>-<YYYY-MM-DD>-<cleaned originalName>
 *
 * $fallback_name is used when the METS carries no usable originalName.
 */
function build_stored_basename(string $loc_code, ?string $created,
                               ?string $original_name, string $fallback_name): string {
    $date = $created !== null ? substr($created, 0, 10) : date('Y-m-d');

    $source = $original_name;
    if ($source === null || $source === '') {
        // Fall back to the source filename, minus its extension.
        $source = preg_replace('/\.(xml)$/i', '', $fallback_name);
    }
    $slug = clean_original_name($source, $loc_code);

    return strtoupper($loc_code) . '-' . $date . '-' . $slug;
}
