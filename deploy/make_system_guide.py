"""Build the Dark Archive system guide (.docx) for staff and council users."""
import sys
from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor

OUT = sys.argv[1]
ACCENT = RGBColor(0x1F, 0x3A, 0x5F)

doc = Document()
sec = doc.sections[0]
sec.page_width, sec.page_height = Inches(8.5), Inches(11)
for side in ("left_margin", "right_margin", "top_margin", "bottom_margin"):
    setattr(sec, side, Inches(1))

styles = doc.styles
styles["Normal"].font.name = "Calibri"
styles["Normal"].font.size = Pt(11)
for name, size in (("Title", 26), ("Heading 1", 16), ("Heading 2", 13)):
    styles[name].font.name = "Calibri"
    styles[name].font.size = Pt(size)
    styles[name].font.color.rgb = ACCENT


def shade(cell, hex_fill):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), hex_fill)
    tcPr.append(shd)


def p(text, bold_lead=None, style=None):
    para = doc.add_paragraph(style=style)
    if bold_lead:
        para.add_run(bold_lead).bold = True
    para.add_run(text)
    return para


def bullets(items):
    for it in items:
        if isinstance(it, tuple):
            p(it[1], bold_lead=it[0], style="List Bullet")
        else:
            p(it, style="List Bullet")


def steps(items):
    for it in items:
        p(it, style="List Number")


def table(header, rows, widths):
    t = doc.add_table(rows=1, cols=len(header))
    t.style = "Table Grid"
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    for i, h in enumerate(header):
        c = t.rows[0].cells[i]
        c.text = ""
        r = c.paragraphs[0].add_run(h)
        r.bold = True
        r.font.color.rgb = RGBColor(0xFF, 0xFF, 0xFF)
        shade(c, "1F3A5F")
    for n, row in enumerate(rows):
        cells = t.add_row().cells
        for i, v in enumerate(row):
            cells[i].text = v
            if n % 2:
                shade(cells[i], "EEF2F7")
    t.autofit = False
    for i, w in enumerate(widths):
        t.columns[i].width = Inches(w)
    for row in t.rows:
        for i, w in enumerate(widths):
            row.cells[i].width = Inches(w)
    doc.add_paragraph()
    return t


def callout(text, lead="Note: "):
    t = doc.add_table(rows=1, cols=1)
    c = t.rows[0].cells[0]
    c.width = Inches(6.5)
    shade(c, "FFF4DC")
    c.text = ""
    para = c.paragraphs[0]
    para.add_run(lead).bold = True
    para.add_run(text)
    doc.add_paragraph()


def code(text):
    para = doc.add_paragraph()
    r = para.add_run(text)
    r.font.name = "Consolas"
    r.font.size = Pt(9.5)
    para.paragraph_format.left_indent = Inches(0.3)


# ── Title ────────────────────────────────────────────────────────────────────
doc.add_paragraph("Dark Archive", style="Title")
p("System guide for SENYLRC staff, council staff and member organizations")
p("darkarchive.senylrc.org  ·  Updated October 29, 2026").runs[0].italic = True

# ── 1. Overview ──────────────────────────────────────────────────────────────
doc.add_heading("1. What Dark Archive is", level=1)
p("Dark Archive is SENYLRC's catalog of digitally preserved collections. The "
  "actual files (images, audio, PDFs) live in long-term Amazon S3 / Glacier "
  "storage. Dark Archive keeps the descriptive METS XML for every preserved "
  "package, so staff can search, browse, report on and download that "
  "description without touching cold storage.")
p("Every package belongs to one member organization, and every user can only "
  "see the organizations they have been given access to.")

# ── 2. Key concepts ─────────────────────────────────────────────────────────
doc.add_heading("2. Key concepts", level=1)
table(
    ["Term", "What it means"],
    [
        ["Organization", "A member institution, e.g. Bard College. Identified by its LOC (NUC) code, e.g. NANB."],
        ["LOC code", "Short library code used everywhere: in dropbox folder names, stored file names and the Organizations page."],
        ["Library Council", "One of six regional councils (Long Island, Western NY, Southeastern NY, South Central, Rochester, Central NY). Each organization can be assigned to one council."],
        ["Package", "One preserved collection: one METS XML file plus its extracted metadata, size, file count and storage location."],
        ["Admin user", "SENYLRC staff. Sees and manages everything."],
        ["Read-only user", "Sees only the organizations assigned to them, plus any council members (see section 3). Cannot change data."],
    ],
    [1.6, 4.9],
)

# ── 3. Access ───────────────────────────────────────────────────────────────
doc.add_heading("3. Who can see what", level=1)
table(
    ["Feature", "Admin", "Read-only"],
    [
        ["Dashboard, Search, View package", "All organizations", "Accessible organizations only"],
        ["Reports and CSV export", "All organizations", "Accessible organizations only"],
        ["PDF report", "All organizations", "Accessible organizations only"],
        ["Download METS XML", "All organizations", "Accessible organizations only"],
        ["Upload, Organizations, Users pages", "Yes", "No"],
        ["Edit or delete packages", "Yes", "No"],
        ["Own profile (email, password)", "Yes", "Yes"],
    ],
    [2.6, 1.7, 2.2],
)
p("A read-only user's accessible organizations are worked out in two parts:")
bullets([
    ("Direct assignments. ", "The organizations ticked for that user on the Users page."),
    ("Council access. ", "If one of those organizations is a library council itself, the user also gets every organization whose Library Council is set to that council."),
])

doc.add_heading("Council access, by example", level=2)
p("The user jsmith@example.org is assigned to the organization "
  "\"Central New York Library Resources Council\" (LOC code NSYCNL). Because "
  "that organization is one of the six councils, this user automatically sees:")
bullets([
    "the Central New York council's own packages, and",
    "the packages of every organization whose Library Council is set to "
    "Central New York Library Resources Council on the Organizations page.",
])
p("This user does not see organizations belonging to other councils, or "
  "organizations whose Library Council is left blank (shown as \"—\").")
callout("Council access follows the Library Council field on each "
        "organization. If a Central New York member is missing for a council "
        "user, the fix is almost always to set that organization's Library "
        "Council, not to change the user.")
p("Changes take effect on the user's next page load; they do not need to log "
  "out and back in.")

# ── 4. Admin how-tos ────────────────────────────────────────────────────────
doc.add_heading("4. Admin tasks", level=1)

doc.add_heading("Give someone access to a whole council", level=2)
steps([
    "Open Users. Click Edit on the person, or fill in Add User.",
    "Set Role to Read-only.",
    "In Organizations, select the council's own organization, e.g. "
    "[NSYCNL] Central New York Library Resources Council. Hold Ctrl to "
    "select more than one.",
    "Save. In the user list, the Organizations column now shows a line such as "
    "\"Council NSYCNL: +12 member orgs\". That number is how many "
    "organizations the council assignment adds.",
])

doc.add_heading("Add an organization to a council", level=2)
steps([
    "Open Organizations. To find organizations that still need a council, set the Library Council filter to \"— Unassigned —\".",
    "Click Edit on the organization (or use Add Organization).",
    "Choose its Library Council from the drop-down.",
    "Save. Every user assigned to that council can now see it.",
])

doc.add_heading("Give someone access to a single organization", level=2)
p("Same as above, but select only that organization. A user assigned to a "
  "regular member organization (not a council) gets just that organization.")

doc.add_heading("Troubleshooting: \"I can't see organization X\"", level=2)
table(
    ["Check", "Where"],
    [
        ["Is the account active and Read-only (not disabled)?", "Users page"],
        ["Is X ticked for the user directly, or is the user assigned to a council?", "Users page, Organizations column"],
        ["Is X's Library Council set to that same council?", "Organizations page: filter by that council"],
        ["Does X actually have any packages yet?", "Organizations page, Packages column"],
    ],
    [4.3, 2.2],
)

# ── 5. Pages ────────────────────────────────────────────────────────────────
doc.add_heading("5. A tour of the pages", level=1)
table(
    ["Page", "What it's for"],
    [
        ["Dashboard", "Totals (packages, organizations, files stored, GB stored) and the latest packages, limited to what you can see."],
        ["Search", "Find packages by name, organization, date range, or any XML metadata element and value."],
        ["View package", "Package details and its extracted metadata. Show files lists every original file in the package (name, size, type, PRONOM format, dates) and matches the Files count; Export CSV adds checksums. Admins can edit size, file count, storage location and notes here, or delete the package."],
        ["Reports", "Package or file-list reports filtered by organization, library council and date, grouped by organization or date. The File List shows every original file in each package, with size and format. Export as CSV."],
        ["PDF report", "A formatted PDF summary for one organization."],
        ["Upload (admin)", "Upload a METS XML or ZIP for an organization."],
        ["Organizations (admin)", "Add or edit organizations, their LOC code and Library Council. Filter the list by council (or Unassigned) and by name or LOC code; click any column heading to sort."],
        ["Users (admin)", "Add, edit, disable or delete users and assign their organizations."],
        ["Profile", "Change your own email or password."],
    ],
    [1.7, 4.8],
)

# ── 6. Ingest ───────────────────────────────────────────────────────────────
doc.add_heading("6. How packages get into the system", level=1)
bullets([
    ("Dropbox folder. ", "METS XML or ZIP files are placed in dropbox/<LOC code>/ on the server. "
     "Every 5 minutes, Monday to Friday 8am–4pm, process_uploads.php picks them up."),
    ("Processed. ", "Valid files are stored in uploads/ as a compressed archive named "
     "<LOC>-<date>-<name>.tar.gz and moved to dropbox/<LOC>/processed/."),
    ("Rejected. ", "Invalid XML is moved to dropbox/<LOC>/rejected/."),
    ("Duplicates. ", "A file with the same name for the same organization is skipped."),
    ("Upload page. ", "Admins can also upload a single file through the website."),
])

doc.add_heading("Automated emails", level=2)
bullets([
    ("Weekly data quality (Mondays). ", "Lists packages missing a size or storage location, or sharing a storage location. Sent to noc@yourdomain.org only when something needs fixing."),
    ("Monthly S3 reconciliation. ", "Compares packages against what is really in S3 and flags missing or mismatched storage locations."),
    ("Account emails. ", "New users receive a welcome email with the site address and a single-use link to choose their own password (valid 7 days); the password an admin types is never emailed. Password resets use single-use links that expire after one hour."),
])

# ── 7. Technical reference ──────────────────────────────────────────────────
doc.add_heading("7. Technical reference", level=1)
p("For whoever maintains the code. Full setup instructions are in README.md.")

doc.add_heading("Where access is decided", level=2)
p("All access checks read a single list of organization IDs kept in the "
  "user's session. It is built in includes/auth.php:")
table(
    ["Function", "Role"],
    [
        ["refresh_user_orgs()", "Builds the session list: direct assignments plus council members. Runs at login and on every page load for read-only users."],
        ["council_member_org_ids()", "Finds the member organizations of any council among the given organizations."],
        ["can_access_org()", "Yes/no check for one organization (View, Download, PDF)."],
        ["allowed_orgs() / org_access_sql()", "List and SQL filter used by Dashboard, Search and Reports."],
    ],
    [2.3, 4.2],
)

doc.add_heading("Where file lists come from", level=2)
p("View page \"Show files\" and the Reports File List both list each package's "
  "original files from its METS (the USE=\"original\" file group), the same set "
  "the Files count uses. Details come from each file's PREMIS record, plus "
  "ExifTool data where it exists. Each package is read once and cached in "
  "uploads/.filelists/; run backfill_file_lists.php to rebuild the cache.")

doc.add_heading("How a council organization is matched to a council", level=2)
p("Each council exists twice: as a row in the library_councils table (what "
  "organizations.council_id points to) and as an ordinary row in "
  "organizations (so it can own packages and be assigned to users). The two "
  "are matched by LOC code, or by exact name if the codes differ:")
code("SELECT DISTINCT m.id\n"
     "FROM organizations a\n"
     "JOIN library_councils c ON (c.loc_code = a.loc_code OR c.name = a.name)\n"
     "JOIN organizations m    ON m.council_id = c.id\n"
     "WHERE a.id IN (<user's assigned org ids>)")

doc.add_heading("Database tables", level=2)
table(
    ["Table", "Holds"],
    [
        ["organizations", "Member organizations, LOC code, council_id"],
        ["library_councils", "The six councils and their LOC codes"],
        ["users", "Accounts, role (admin / readonly), active flag"],
        ["user_organizations", "Which organizations each read-only user is assigned to"],
        ["packages", "One row per preserved package"],
        ["file_metadata", "Every leaf XML element extracted from each package"],
        ["password_resets", "One-hour, single-use reset tokens"],
    ],
    [2.0, 4.5],
)
callout("Council access needs no database changes. It uses the existing "
        "library_councils table and organizations.council_id column.",
        lead="Deployment: ")

doc.add_heading("Servers and files", level=2)
bullets([
    ("Live site: ", "/var/www/darkarchive on the web server (only public/ is served by Apache)."),
    ("Same files: ", "The deploy folder on the web server points at the live copy, so changes copied there go live immediately."),
    ("Configuration: ", ".env in the application root (database, S3, reCAPTCHA settings). Never place it under public/."),
])

doc.save(OUT)
print("wrote", OUT)
