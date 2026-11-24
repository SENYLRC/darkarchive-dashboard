#!/usr/bin/env python3
"""
generate_pdf_report.py  —  Dark Archive PDF report generator

Usage (called from pdf_report.php via proc_open):
  python3 generate_pdf_report.py <org_id> <output_pdf_path>

The database config is read as JSON on stdin, not passed as an argument:
/proc/<pid>/cmdline is world-readable, so credentials in argv are visible to
every local user via `ps` for the lifetime of the process.

Generates a PDF listing all files in all packages for the given organization,
with columns: Filename | Size | File Type | Date Created | Date Package Processed
"""

import sys
import json
import os
import re
from datetime import datetime

import pymysql

from reportlab.lib.pagesizes import landscape, letter
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import inch
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
    PageBreak, HRFlowable
)
from reportlab.lib.enums import TA_LEFT, TA_CENTER, TA_RIGHT

# ── Config ───────────────────────────────────────────────────────────────────
if len(sys.argv) < 3:
    print("Usage: generate_pdf_report.py <org_id> <output_path>", file=sys.stderr)
    print("       (DB config JSON is read from stdin)", file=sys.stderr)
    sys.exit(1)

org_id      = int(sys.argv[1])
output_path = sys.argv[2]

# Config arrives on stdin so the password never appears in the command line.
try:
    db_conf = json.loads(sys.stdin.read())
except (ValueError, TypeError) as exc:
    print(f"ERROR: could not parse DB config from stdin: {exc}", file=sys.stderr)
    sys.exit(1)

for _required in ('host', 'name', 'user', 'pass'):
    if _required not in db_conf:
        print(f"ERROR: DB config is missing '{_required}'", file=sys.stderr)
        sys.exit(1)

# ── DB connection ─────────────────────────────────────────────────────────────
# The CA bundle path comes from RDS_CERT in .env rather than being hardcoded;
# the previous literal pointed at us-east-1-bundle.pem, which no longer matches
# the configured global-bundle.pem.
_ssl_ca   = db_conf.get('ssl_ca') or ''
_ssl_args = {'ssl': {'ca': _ssl_ca}} if _ssl_ca and os.path.exists(_ssl_ca) else {}
if _ssl_ca and not os.path.exists(_ssl_ca):
    print(f"WARNING: CA bundle not found at {_ssl_ca}; connecting without TLS verification",
          file=sys.stderr)

conn = pymysql.connect(
    host=db_conf['host'], db=db_conf['name'],
    user=db_conf['user'], password=db_conf['pass'],
    charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor,
    **_ssl_args
)
cur = conn.cursor()

# Org info
cur.execute("SELECT name, loc_code FROM organizations WHERE id = %s", (org_id,))
org = cur.fetchone()
if not org:
    print(f"Organization {org_id} not found", file=sys.stderr)
    sys.exit(1)

# All packages for this org
cur.execute("""
    SELECT id, original_name, file_size, size_gb, glacier_location, processed_at
    FROM packages WHERE org_id = %s ORDER BY processed_at, original_name
""", (org_id,))
packages = cur.fetchall()

def fmt_bytes(b):
    b = int(b) if b else 0
    if b < 1024:      return f"{b} B"
    if b < 1048576:   return f"{b/1024:.1f} KB"
    return f"{b/1048576:.1f} MB"

def fmt_date(v):
    if not v:
        return "—"
    # ISO / ExifTool dates: 2026-04-21T17:05:35... or 2011:06:15 17:21:03
    v = str(v).strip()
    # Only the date part is shown, so parse the first 10 chars (2026-04-21 or
    # 2011:06:15); slicing to len(fmt) never matched, since a format string's
    # length isn't the length of the value it describes.
    for fmt in ("%Y-%m-%d", "%Y:%m:%d"):
        try:
            return datetime.strptime(v[:10], fmt).strftime("%b %d, %Y")
        except ValueError:
            pass
    return v[:10]

# Pull metadata for each package
# We re-build the same per-file grouping logic as view.php
FILE_NAME_RE   = re.compile(r'(^|:)FileName$')
FILE_SIZE_RE   = re.compile(r'(^|:)FileSize$')
CREATED_RE     = re.compile(r'(^|:)(CreateDate|DateTimeOriginal)$')
FILE_TYPE_RE   = re.compile(r'(^|:)FileType$')
EVENT_DATE_RE  = re.compile(r'(^|:)eventDateTime$')

# Preferred source: the METS original-file lists pdf_report.php builds with
# cached_mets_original_files() and sends on stdin, keyed by package id. The
# file_metadata grouping below only sees files ExifTool ran on, so it is used
# only for packages whose list is null (no USE="original" group) or missing.
mets_file_lists = db_conf.get('file_lists') or {}

def get_file_rows(pkg_id):
    mets = mets_file_lists.get(str(pkg_id))
    if mets is not None:
        return [{'name': f.get('name'),
                 'size': fmt_bytes(f['size']) if isinstance(f.get('size'), int) else None,
                 'file_type': f.get('file_type'),
                 'created': f.get('created'),
                 'pkg_date': None} for f in mets]

    cur.execute(
        "SELECT element, value FROM file_metadata WHERE package_id = %s ORDER BY id",
        (pkg_id,)
    )
    rows = cur.fetchall()
    files = []
    current = None
    for r in rows:
        el, v = r['element'], r['value'] or ''
        if FILE_NAME_RE.search(el):
            if current:
                files.append(current)
            current = {'name': v, 'size': None, 'file_type': None,
                       'created': None, 'pkg_date': None}
            continue
        if current is None:
            continue
        if FILE_SIZE_RE.search(el)  and not current['size']:      current['size']     = v
        if CREATED_RE.search(el)    and not current['created']:   current['created']  = v
        if FILE_TYPE_RE.search(el)  and not current['file_type']: current['file_type']= v
        if EVENT_DATE_RE.search(el) and not current['pkg_date']:  current['pkg_date'] = v
    if current:
        files.append(current)
    return files

# ── Styles ────────────────────────────────────────────────────────────────────
styles = getSampleStyleSheet()

DARK   = colors.HexColor('#0f1117')
AMBER  = colors.HexColor('#b5821d')
AMBER2 = colors.HexColor('#d4a435')
LIGHT  = colors.HexColor('#e2e8f0')
MUTED  = colors.HexColor('#64748b')
SURF   = colors.HexColor('#1a1f2e')
SURF2  = colors.HexColor('#232838')
WHITE  = colors.white

title_style = ParagraphStyle('DATitle', parent=styles['Normal'],
    fontSize=18, textColor=AMBER2, fontName='Helvetica-Bold',
    spaceAfter=4)
subtitle_style = ParagraphStyle('DASub', parent=styles['Normal'],
    fontSize=11, textColor=LIGHT, fontName='Helvetica', spaceAfter=2)
meta_style = ParagraphStyle('DAMeta', parent=styles['Normal'],
    fontSize=9, textColor=MUTED, fontName='Helvetica')
pkg_style = ParagraphStyle('DAPkg', parent=styles['Normal'],
    fontSize=11, textColor=AMBER2, fontName='Helvetica-Bold',
    spaceBefore=16, spaceAfter=6)
cell_style = ParagraphStyle('DACell', parent=styles['Normal'],
    fontSize=7, textColor=LIGHT, fontName='Helvetica', leading=9)
muted_style = ParagraphStyle('DAMutedCell', parent=styles['Normal'],
    fontSize=7, textColor=MUTED, fontName='Helvetica', leading=9)

# ── Page layout ───────────────────────────────────────────────────────────────
PAGE  = landscape(letter)
LEFT  = 0.6 * inch
RIGHT = 0.6 * inch
TOP   = 0.6 * inch
BOT   = 0.5 * inch

def make_header_footer(canvas, doc):
    canvas.saveState()
    w, h = PAGE
    # Header bar
    canvas.setFillColor(SURF)
    canvas.rect(0, h - 36, w, 36, fill=1, stroke=0)
    canvas.setFillColor(AMBER)
    canvas.setFont('Helvetica-Bold', 11)
    canvas.drawString(LEFT, h - 22, "DARK ARCHIVE")
    canvas.setFillColor(MUTED)
    canvas.setFont('Helvetica', 9)
    canvas.drawString(LEFT + 110, h - 22, f"| {org['name']} [{org['loc_code']}]")
    canvas.setFillColor(MUTED)
    canvas.drawRightString(w - RIGHT, h - 22,
        f"Generated {datetime.now().strftime('%B %d, %Y %I:%M %p')}")
    # Footer
    canvas.setFillColor(SURF)
    canvas.rect(0, 0, w, 28, fill=1, stroke=0)
    canvas.setFillColor(MUTED)
    canvas.setFont('Helvetica', 8)
    canvas.drawString(LEFT, 10, "SENYLRC Dark Archive — Confidential")
    canvas.drawRightString(w - RIGHT, 10, f"Page {doc.page}")
    canvas.restoreState()

# ── Build content ─────────────────────────────────────────────────────────────
doc = SimpleDocTemplate(
    output_path, pagesize=PAGE,
    leftMargin=LEFT, rightMargin=RIGHT,
    topMargin=TOP + 30, bottomMargin=BOT + 20
)

story = []

# Cover block
story.append(Paragraph(f"Package File Report", title_style))
story.append(Paragraph(f"{org['name']}", subtitle_style))
story.append(Paragraph(f"LOC Code: {org['loc_code']}  |  "
                        f"Total Packages: {len(packages)}  |  "
                        f"Report Date: {datetime.now().strftime('%B %d, %Y')}",
                        meta_style))
story.append(HRFlowable(width="100%", thickness=1, color=AMBER, spaceAfter=14))

COL_WIDTHS = [3.0*inch, 0.7*inch, 0.7*inch, 1.4*inch, 1.4*inch]

total_files = 0
for pkg in packages:
    pkg_date = fmt_date(pkg['processed_at'])
    size_disp = f"{float(pkg['size_gb']):.3f} GB" if pkg.get('size_gb') else fmt_bytes(pkg['file_size'])
    glacier   = f"  |  Glacier: {pkg['glacier_location']}" if pkg.get('glacier_location') else ''
    story.append(Paragraph(
        f"📦  {pkg['original_name']}  "
        f"<font color='#64748b' size='8'>#{pkg['id']}  |  "
        f"{size_disp}  |  Processed: {pkg_date}{glacier}</font>",
        pkg_style
    ))

    file_rows = get_file_rows(pkg['id'])
    total_files += len(file_rows)

    if not file_rows:
        story.append(Paragraph("No file-level metadata available for this package.", meta_style))
        continue

    # Table header
    header = [
        Paragraph('<b>Filename</b>', ParagraphStyle('H', parent=cell_style,
            textColor=AMBER2, fontName='Helvetica-Bold')),
        Paragraph('<b>Size</b>', ParagraphStyle('H', parent=cell_style,
            textColor=AMBER2, fontName='Helvetica-Bold')),
        Paragraph('<b>Type</b>', ParagraphStyle('H', parent=cell_style,
            textColor=AMBER2, fontName='Helvetica-Bold')),
        Paragraph('<b>Date Created</b>', ParagraphStyle('H', parent=cell_style,
            textColor=AMBER2, fontName='Helvetica-Bold')),
        Paragraph('<b>Date Processed</b>', ParagraphStyle('H', parent=cell_style,
            textColor=AMBER2, fontName='Helvetica-Bold')),
    ]

    table_data = [header]
    for f in file_rows:
        pkg_dt = f['pkg_date'] or str(pkg['processed_at'])
        table_data.append([
            Paragraph(f['name'] or '—', cell_style),
            Paragraph(f['size'] or '—', muted_style),
            Paragraph(f['file_type'] or '—', muted_style),
            Paragraph(fmt_date(f['created']), muted_style),
            Paragraph(fmt_date(pkg_dt), muted_style),
        ])

    t = Table(table_data, colWidths=COL_WIDTHS, repeatRows=1)
    t.setStyle(TableStyle([
        ('BACKGROUND',   (0, 0), (-1, 0),  SURF2),
        ('ROWBACKGROUNDS', (0, 1), (-1, -1), [DARK, SURF]),
        ('GRID',         (0, 0), (-1, -1),  0.25, colors.HexColor('#2d3548')),
        ('TOPPADDING',   (0, 0), (-1, -1),  4),
        ('BOTTOMPADDING',(0, 0), (-1, -1),  4),
        ('LEFTPADDING',  (0, 0), (-1, -1),  5),
        ('RIGHTPADDING', (0, 0), (-1, -1),  5),
        ('VALIGN',       (0, 0), (-1, -1),  'TOP'),
    ]))
    story.append(t)

story.append(Spacer(1, 20))
story.append(HRFlowable(width="100%", thickness=0.5, color=MUTED))
story.append(Paragraph(
    f"End of report  |  {total_files} total files across {len(packages)} packages",
    ParagraphStyle('Footer', parent=meta_style, spaceAfter=0, spaceBefore=6)
))

doc.build(story, onFirstPage=make_header_footer, onLaterPages=make_header_footer)
conn.close()
print(f"OK:{output_path}")
