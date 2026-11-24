<?php
/**
 * Shared page chrome: page_header() renders the HTML head and nav bar (with
 * admin-only items), page_footer() closes the page.
 */
function page_header(string $title, string $active = ''): void {
    $nav = [
        'index.php'      => 'Dashboard',
        'upload.php'     => 'Upload XML',
        'search.php'     => 'Search / Browse',
        'report.php'     => 'Reports',
        'pdf_report.php' => 'PDF Report',
        'orgs.php'       => 'Organizations',
    ];
    // Admin-only nav items
    $admin_nav = [
        'users.php' => 'Users',
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — Dark Archive</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg:       #0f1117;
    --surface:  #1a1f2e;
    --surface2: #232838;
    /* WCAG 2.1 AA SC 1.4.11 (non-text contrast): UI component boundaries need
       >=3:1. At .2 alpha this composited to ~1.3:1 against every background
       it outlines (inputs, .btn-ghost) — the border was effectively invisible
       as a boundary cue. .8 alpha clears ~3.3:1 while staying visually
       distinct from the fully-opaque --amber used on :focus. */
    --border:   rgba(181,130,56,.8);
    --amber:    #b5821d;
    --amber-l:  #d4a435;
    --amber-p:  #fac775;
    --text:     #e2e8f0;
    /* WCAG 2.1 AA: normal-size text needs >=4.5:1. The old --muted (#64748b)
       measured 3.08-3.97:1 against --bg/--surface/--surface2, and the old
       --red (#ef4444) measured 3.75:1 as .btn-danger's text-on-tinted-bg.
       These shades keep the same hue but lighten enough to clear 4.5:1 on
       every background they're actually used against in this app. */
    --muted:    #94a3b8;
    --green:    #22c55e;
    --red:      #f87171;
    --yellow:   #eab308;
    --radius:   8px;
    --font:     'Segoe UI', system-ui, sans-serif;
}
body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; }
a { color: var(--amber-l); text-decoration: none; }
a:hover { color: var(--amber-p); text-decoration: underline; }

/* NAV */
nav { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 24px; display: flex; align-items: center; gap: 8px; }
.nav-brand { font-weight: 700; font-size: 15px; color: var(--amber-p); padding: 14px 12px 14px 0; margin-right: 8px; border-right: 1px solid var(--border); white-space: nowrap; }
.nav-brand span { color: var(--muted); font-weight: 400; }
nav a { color: var(--muted); padding: 14px 12px; font-size: 14px; border-bottom: 2px solid transparent; transition: color .15s; }
nav a:hover { color: var(--text); text-decoration: none; }
nav a.active { color: var(--amber-p); border-bottom-color: var(--amber); }

/* LAYOUT */
main { max-width: 1100px; margin: 0 auto; padding: 32px 24px; }
h1 { font-size: 22px; font-weight: 600; color: var(--text); margin-bottom: 24px; }
h2 { font-size: 16px; font-weight: 600; color: var(--amber-p); margin-bottom: 16px; }

/* CARDS */
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 24px; }

/* TABLE */
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th { text-align: left; padding: 10px 12px; background: var(--surface2); color: var(--amber-p); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid var(--border); }
td { padding: 10px 12px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--surface2); }

/* FORMS */
.form-group { margin-bottom: 18px; }
label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
input[type=text], input[type=search], input[type=email], input[type=password], select, textarea {
    width: 100%; padding: 9px 12px; background: var(--surface2); border: 1px solid var(--border);
    border-radius: 6px; color: var(--text); font-size: 14px; font-family: var(--font);
    transition: border-color .15s;
}
input[type=text]:focus, input[type=search]:focus, input[type=email]:focus, input[type=password]:focus, select:focus, textarea:focus {
    outline: none; border-color: var(--amber);
}
select option { background: var(--surface2); }
input[type=file] { color: var(--muted); font-size: 14px; }

/* BUTTONS */
.btn { display: inline-block; padding: 9px 18px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; border: none; transition: background .15s; }
.btn-primary { background: var(--amber); color: #0f1117; }
.btn-primary:hover { background: var(--amber-l); color: #0f1117; text-decoration: none; }
.btn-sm { padding: 5px 12px; font-size: 12px; }
/* border alpha bumped from .3 (~1.3:1, same SC 1.4.11 issue as the old
   --border) to .8 (~3.2-3.7:1 against --surface/--bg) — this is a real
   interactive control (Delete), not a decorative or redundantly-cued box. */
.btn-danger { background: rgba(239,68,68,.15); color: var(--red); border: 1px solid rgba(239,68,68,.8); }
.btn-danger:hover { background: rgba(239,68,68,.25); text-decoration: none; }
.btn-ghost { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--surface); text-decoration: none; }

/* ALERTS */
.alert { padding: 12px 16px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; }
.alert-success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.3); color: var(--green); }
.alert-error   { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); color: var(--red); }
.alert-info    { background: rgba(181,130,56,.1); border: 1px solid var(--border); color: var(--amber-p); }

/* BADGES */
.badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
.badge-org { background: rgba(181,130,56,.15); color: var(--amber-p); border: 1px solid var(--border); }

/* STAT BOXES */
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; }
.stat-value { font-size: 28px; font-weight: 700; color: var(--amber-p); }
.stat-label { font-size: 12px; color: var(--muted); margin-top: 4px; }

/* PAGINATION */
.pagination { display: flex; gap: 6px; margin-top: 20px; align-items: center; }
.pagination a, .pagination span { padding: 6px 12px; border-radius: 6px; font-size: 13px; border: 1px solid var(--border); background: var(--surface); color: var(--muted); }
.pagination a:hover { border-color: var(--amber); color: var(--amber-p); text-decoration: none; }
.pagination .current { background: var(--amber); color: #0f1117; border-color: var(--amber); font-weight: 600; }
</style>
</head>
<body>
<nav>
    <div class="nav-brand">Dark Archive <span>| SENYLRC</span></div>
    <?php foreach ($nav as $file => $label): ?>
        <?php if (($file === 'upload.php' || $file === 'orgs.php') && !is_admin()): continue; endif; ?>
        <a href="<?= $file ?>" class="<?= $active === $file ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <?php if (is_admin()): ?>
        <a href="users.php" class="<?= $active === 'users.php' ? 'active' : '' ?>">Users</a>
    <?php endif; ?>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;padding:0 8px;">
        <span style="font-size:12px;color:var(--muted)">
            <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>
            <?php if (is_admin()): ?>
                <span style="color:var(--amber-p);margin-left:4px">Admin</span>
            <?php endif; ?>
        </span>
        <a href="profile.php" style="font-size:12px;color:var(--muted);border:1px solid var(--border);padding:4px 10px;border-radius:4px">Profile</a>
        <a href="logout.php" style="font-size:12px;color:var(--muted);border:1px solid var(--border);padding:4px 10px;border-radius:4px">Sign out</a>
    </div>
</nav>
<main>
<h1><?= htmlspecialchars($title) ?></h1>
<?php } ?>

<?php
function page_footer(): void { ?>
</main>
</body>
</html>
<?php } ?>
