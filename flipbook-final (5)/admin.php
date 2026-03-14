<?php
session_start();
require_once __DIR__ . '/db.php';

define('ADMIN_PIN', '1234'); // ← ΑΛΛΑΞΕ ΤΟ

$loggedIn = isset($_SESSION['flipbook_admin']) && $_SESSION['flipbook_admin'] === true;

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if (($_POST['pin'] ?? '') === ADMIN_PIN) { $_SESSION['flipbook_admin'] = true; $loggedIn = true; }
    else { $loginError = 'Λάθος PIN'; }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

$toc = $loggedIn ? get_toc() : [];
$dbExists = file_exists(FLIPBOOK_DB_PATH);
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — FlipBook</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<?php if ($loggedIn): ?>
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<?php endif; ?>
<style>
:root{
    --bg:#f5f7fa;--bg2:#ffffff;--bg3:#f0f2f5;--bg4:#e8ebf0;
    --border:#e0e4ea;--border2:#d0d5dd;
    --accent:#2563eb;--accent2:#3b82f6;--accent-bg:rgba(37,99,235,.06);
    --text:#1a1d23;--text2:#4b5563;--text3:#9ca3af;
    --danger:#dc2626;--danger-bg:rgba(220,38,38,.06);
    --success:#16a34a;--success-bg:rgba(22,163,74,.08);
    --warn:#d97706;--warn-bg:rgba(217,119,6,.06);
    --r:8px;--shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
    --shadow-lg:0 10px 25px rgba(0,0,0,.08);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14px;line-height:1.5}
a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}

/* Login */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;background:linear-gradient(135deg,#eff6ff,#f5f7fa)}
.login-box{background:#fff;border:1px solid var(--border);border-radius:16px;padding:48px 40px;width:100%;max-width:380px;text-align:center;box-shadow:var(--shadow-lg)}
.login-box h1{font-size:22px;margin-bottom:6px;color:var(--text)}
.login-box p{font-size:13px;color:var(--text2);margin-bottom:28px}
.login-box input[type="password"]{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);padding:14px;color:var(--text);font-size:20px;text-align:center;letter-spacing:8px;outline:none;font-family:inherit;margin-bottom:16px;transition:border .2s}
.login-box input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.login-box .error{color:var(--danger);font-size:12px;margin-bottom:12px;background:var(--danger-bg);padding:8px;border-radius:6px}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--r);border:1px solid transparent;font-family:inherit;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;line-height:1.4}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}.btn-primary:hover{background:var(--accent2)}
.btn-danger{background:var(--danger-bg);color:var(--danger);border-color:rgba(220,38,38,.15)}.btn-danger:hover{background:var(--danger);color:#fff}
.btn-ghost{background:#fff;color:var(--text2);border-color:var(--border)}.btn-ghost:hover{background:var(--bg3);color:var(--text)}
.btn-sm{padding:5px 10px;font-size:11px;border-radius:6px}

/* Header */
.admin-header{background:#fff;border-bottom:1px solid var(--border);padding:0 24px;display:flex;align-items:center;gap:16px;height:56px;position:sticky;top:0;z-index:100;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.admin-header h1{font-size:15px;font-weight:600;color:var(--text)}.admin-header .spacer{flex:1}
.admin-header a{color:var(--text2);font-size:12px;padding:6px 12px;border-radius:6px;transition:background .15s}.admin-header a:hover{background:var(--bg3);text-decoration:none}

.admin-body{padding:24px;max-width:1100px;margin:0 auto}

/* Panels */
.panel{background:#fff;border:1px solid var(--border);border-radius:12px;margin-bottom:20px;box-shadow:var(--shadow)}
.panel-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.panel-header h2{font-size:15px;font-weight:600;flex:1;color:var(--text)}
.panel-body{padding:20px}

/* Forms */
.form-row{margin-bottom:16px}
.form-row label{display:block;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px}
.form-row input[type="text"],.form-row textarea,.form-row select{width:100%;background:#fff;border:1px solid var(--border);border-radius:var(--r);padding:10px 12px;color:var(--text);font-size:13px;font-family:inherit;outline:none;transition:border .2s,box-shadow .2s}
.form-row input:focus,.form-row textarea:focus,.form-row select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.form-row textarea{min-height:60px;resize:vertical}
.form-row .hint{font-size:11px;color:var(--text3);margin-top:4px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}

/* Chapter list */
.ch-item{border:1px solid var(--border);border-radius:10px;margin-bottom:10px;overflow:hidden;background:#fff;transition:box-shadow .15s}
.ch-item:hover{box-shadow:var(--shadow)}
.ch-item-header{display:flex;align-items:center;gap:10px;padding:14px 16px;cursor:pointer;user-select:none;transition:background .1s}
.ch-item-header:hover{background:var(--bg3)}
.ch-item-header .ch-icon{font-size:18px}
.ch-item-header .ch-title{flex:1;font-weight:600;font-size:14px;color:var(--text)}
.ch-item-header .ch-badge{font-size:11px;color:var(--text3);background:var(--bg3);padding:2px 8px;border-radius:10px}
.ch-item-body{display:none;padding:16px;border-top:1px solid var(--border);background:var(--bg)}
.ch-item.open .ch-item-body{display:block}

.sec-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--r);margin-bottom:6px;background:#fff;cursor:pointer;transition:all .15s}
.sec-item:hover{border-color:var(--accent);box-shadow:0 0 0 2px rgba(37,99,235,.08)}
.sec-item .sec-title{flex:1;font-size:13px;font-weight:500}
.sec-item .sec-type{font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.sec-type.article{background:rgba(37,99,235,.08);color:var(--accent)}.sec-type.video{background:rgba(220,38,38,.08);color:#dc2626}.sec-type.app{background:rgba(22,163,74,.08);color:#16a34a}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:500;display:none;align-items:flex-start;justify-content:center;padding:40px 20px;overflow-y:auto;backdrop-filter:blur(2px)}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:16px;width:100%;max-width:960px;box-shadow:0 20px 60px rgba(0,0,0,.15);animation:modalIn .2s ease}
@keyframes modalIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
.modal-header{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.modal-header h3{flex:1;font-size:17px;font-weight:600}
.modal-body{padding:24px}
.modal-footer{padding:16px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;background:var(--bg)}

/* Tabs in modal */
.tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:20px}
.tab{padding:10px 20px;font-size:13px;font-weight:500;color:var(--text3);cursor:pointer;border-bottom:2px solid transparent;transition:all .15s}
.tab:hover{color:var(--text)}
.tab.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab-panel{display:none}.tab-panel.active{display:block}

/* Quill overrides (light) */
.ql-toolbar.ql-snow{border:1px solid var(--border)!important;border-radius:var(--r) var(--r) 0 0;background:var(--bg)}
.ql-container.ql-snow{border:1px solid var(--border)!important;border-top:none!important;border-radius:0 0 var(--r) var(--r);font-family:'Inter',sans-serif;font-size:14px;min-height:300px;background:#fff}
.ql-editor{min-height:300px;line-height:1.7}
.ql-editor.ql-blank::before{color:var(--text3);font-style:normal}

/* HTML raw editor */
.raw-editor{width:100%;min-height:300px;background:var(--bg);border:1px solid var(--border);border-radius:var(--r);padding:14px;color:var(--text);font-family:'Courier New',monospace;font-size:12px;resize:vertical;outline:none;display:none;line-height:1.6}
.raw-editor:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.editor-toggle{display:inline-flex;align-items:center;gap:4px;margin-top:8px;font-size:11px;color:var(--text3);cursor:pointer;padding:4px 8px;border-radius:4px;transition:all .15s;user-select:none}
.editor-toggle:hover{background:var(--bg3);color:var(--accent)}

/* Image upload */
.upload-zone{border:2px dashed var(--border);border-radius:var(--r);padding:24px;text-align:center;cursor:pointer;transition:all .2s;background:var(--bg)}
.upload-zone:hover,.upload-zone.dragover{border-color:var(--accent);background:var(--accent-bg)}
.upload-zone p{font-size:12px;color:var(--text3);margin-top:6px}
.upload-zone .upload-icon{font-size:28px;margin-bottom:4px}
.upload-preview{margin-top:10px;display:flex;flex-wrap:wrap;gap:8px}
.upload-preview img{height:60px;border-radius:6px;border:1px solid var(--border);object-fit:cover}
.upload-preview .img-url{font-size:11px;color:var(--accent);word-break:break-all;flex:1;padding:4px 0}

/* Toast */
.admin-toast{position:fixed;bottom:24px;right:24px;background:var(--success);color:#fff;padding:12px 20px;border-radius:var(--r);font-size:13px;font-weight:500;opacity:0;transform:translateY(10px);transition:all .3s;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.15)}
.admin-toast.show{opacity:1;transform:translateY(0)}

.warn-box{background:var(--warn-bg);border:1px solid rgba(217,119,6,.2);border-radius:var(--r);padding:14px 16px;margin-bottom:20px;font-size:13px;color:var(--warn);display:flex;align-items:center;gap:8px}

@media(max-width:700px){.form-grid,.form-grid-3{grid-template-columns:1fr}.admin-body{padding:12px}.modal{margin:10px}}
</style>
</head>
<body>
<?php if (!$loggedIn): ?>
<div class="login-wrap">
    <form class="login-box" method="POST">
        <div style="font-size:40px;margin-bottom:16px">📖</div>
        <h1>FlipBook Admin</h1>
        <p>Εισάγετε το PIN διαχείρισης</p>
        <?php if (isset($loginError)): ?><div class="error"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
        <input type="hidden" name="action" value="login">
        <input type="password" name="pin" maxlength="10" autofocus placeholder="••••">
        <br><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px">Είσοδος</button>
    </form>
</div>
<?php else: ?>

<header class="admin-header">
    <span style="font-size:20px">📖</span>
    <h1>FlipBook Admin</h1>
    <span class="spacer"></span>
    <?php if (!$dbExists): ?><a href="install.php" style="color:var(--warn)">⚠️ Install DB</a><?php endif; ?>
    <a href="./" target="_blank">👁 Προβολή</a>
    <a href="admin.php?logout=1">Αποσύνδεση</a>
</header>

<div class="admin-body">

<?php if (!$dbExists): ?>
<div class="warn-box">⚠️ <span>Η βάση δεδομένων δεν υπάρχει. <a href="install.php">Τρέξε install.php</a></span></div>
<?php endif; ?>

<!-- Book Meta -->
<div class="panel">
    <div class="panel-header">
        <h2>📚 Στοιχεία Βιβλίου</h2>
        <button class="btn btn-primary btn-sm" onclick="saveBookMeta()">💾 Αποθήκευση</button>
    </div>
    <div class="panel-body">
        <div class="form-grid">
            <div class="form-row"><label>Τίτλος</label><input type="text" id="bookTitle" value="<?= htmlspecialchars(get_meta('title')) ?>"></div>
            <div class="form-row"><label>Υπότιτλος</label><input type="text" id="bookSubtitle" value="<?= htmlspecialchars(get_meta('subtitle')) ?>"></div>
            <div class="form-row"><label>Συγγραφέας</label><input type="text" id="bookAuthor" value="<?= htmlspecialchars(get_meta('author')) ?>"></div>
            <div class="form-row"><label>Cover Image URL</label><input type="text" id="bookCover" value="<?= htmlspecialchars(get_meta('cover_image')) ?>"></div>
        </div>
        <div class="form-row"><label>Περιγραφή (SEO)</label><textarea id="bookDesc"><?= htmlspecialchars(get_meta('description')) ?></textarea></div>
    </div>
</div>

<!-- Chapters -->
<div class="panel">
    <div class="panel-header">
        <h2>📑 Κεφάλαια & Ενότητες</h2>
        <button class="btn btn-primary btn-sm" onclick="addChapter()">+ Νέο Κεφάλαιο</button>
    </div>
    <div class="panel-body" id="chaptersList"></div>
</div>
</div>

<!-- Section Editor Modal -->
<div class="modal-overlay" id="editorModal">
<div class="modal">
    <div class="modal-header">
        <h3 id="editorTitle">Επεξεργασία</h3>
        <button class="btn btn-ghost btn-sm" onclick="closeEditor()">✕ Κλείσιμο</button>
    </div>
    <div class="modal-body">
        <input type="hidden" id="edSectionId">

        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="switchTab('content')">📝 Περιεχόμενο</div>
            <div class="tab" onclick="switchTab('seo')">🔍 SEO</div>
            <div class="tab" onclick="switchTab('media')">🖼️ Media</div>
        </div>

        <!-- Tab: Content -->
        <div class="tab-panel active" id="tab-content">
            <div class="form-grid">
                <div class="form-row"><label>Τίτλος</label><input type="text" id="edTitle"></div>
                <div class="form-row"><label>Slug (URL)</label><input type="text" id="edSlug"><div class="hint">π.χ. papoytsia-treksimatos</div></div>
            </div>
            <div class="form-grid">
                <div class="form-row">
                    <label>Τύπος</label>
                    <select id="edType" onchange="document.getElementById('edVideoRow').style.display=this.value==='video'?'':'none'">
                        <option value="article">📄 Άρθρο</option><option value="video">🎬 Βίντεο</option><option value="app">🛠️ Εφαρμογή</option>
                    </select>
                </div>
                <div class="form-row" id="edVideoRow" style="display:none"><label>Video Embed URL</label><input type="text" id="edVideo" placeholder="https://youtube.com/embed/..."></div>
            </div>
            <div class="form-row">
                <label>Περιεχόμενο</label>
                <div id="quillWrap"><div id="edQuill"></div></div>
                <textarea id="edRawHtml" class="raw-editor"></textarea>
                <div class="editor-toggle" onclick="toggleHtml()"><span id="toggleIcon">⟨/⟩</span> <span id="toggleLabel">HTML κώδικας</span></div>
            </div>
        </div>

        <!-- Tab: SEO -->
        <div class="tab-panel" id="tab-seo">
            <div class="form-row"><label>Meta Description</label><textarea id="edMetaDesc" placeholder="Σύντομη περιγραφή για μηχανές αναζήτησης (150-160 χαρακτήρες)"></textarea><div class="hint" id="metaDescCount">0 / 160</div></div>
            <div class="form-row"><label>Meta Keywords</label><input type="text" id="edMetaKeywords" placeholder="λέξη1, λέξη2, λέξη3"><div class="hint">Χωρισμένες με κόμμα</div></div>
            <div class="form-row"><label>Εικόνα OG (Social Sharing)</label><input type="text" id="edImage" placeholder="URL εικόνας για κοινοποιήσεις"><div class="hint">Εμφανίζεται στο Facebook/Twitter/LinkedIn</div></div>
        </div>

        <!-- Tab: Media -->
        <div class="tab-panel" id="tab-media">
            <div class="form-row"><label>Ανέβασμα Εικόνας</label>
                <div class="upload-zone" id="uploadZone">
                    <div class="upload-icon">📁</div>
                    <strong>Σύρε εδώ ή κλίκαρε</strong>
                    <p>JPG, PNG, GIF, WebP, SVG — Μέχρι 5MB</p>
                </div>
                <input type="file" id="uploadInput" accept="image/*" style="display:none">
                <div class="upload-preview" id="uploadPreview"></div>
            </div>
            <div class="form-row" style="margin-top:16px">
                <label>Πρόσφατα Uploads</label>
                <div class="hint">Κάνε κλικ σε URL για αντιγραφή. Χρησιμοποίησε στο περιεχόμενο μέσω toolbar εικόνας.</div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" onclick="closeEditor()">Ακύρωση</button>
        <button class="btn btn-primary" onclick="saveSection()">💾 Αποθήκευση</button>
    </div>
</div>
</div>

<div class="admin-toast" id="adminToast"></div>

<script>
const API = 'api.php';
let appData = <?= json_encode($toc, JSON_UNESCAPED_UNICODE) ?>;
let quill = null;
let htmlMode = false;

// ─── API ───
async function api(action, params = {}) {
    const form = new FormData();
    form.append('action', action);
    for (const [k, v] of Object.entries(params)) form.append(k, v);
    const res = await fetch(API, { method: 'POST', body: form });
    return res.json();
}
async function apiGet(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    return (await fetch(API + '?' + qs)).json();
}
function toast(msg, ok = true) {
    const t = document.getElementById('adminToast');
    t.textContent = msg;
    t.style.background = ok ? 'var(--success)' : 'var(--danger)';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2500);
}
async function refreshData() { appData = await apiGet('toc'); renderChapters(); }

// ─── Book Meta ───
async function saveBookMeta() {
    const res = await api('save_meta', {
        title: document.getElementById('bookTitle').value,
        subtitle: document.getElementById('bookSubtitle').value,
        author: document.getElementById('bookAuthor').value,
        description: document.getElementById('bookDesc').value,
        cover_image: document.getElementById('bookCover').value
    });
    toast(res.ok ? '✓ Αποθηκεύτηκε' : '✗ Σφάλμα', res.ok);
}

// ─── Render Chapters ───
function renderChapters() {
    const el = document.getElementById('chaptersList');
    if (!appData || !appData.length) { el.innerHTML = '<p style="color:var(--text3);text-align:center;padding:40px">Κανένα κεφάλαιο. Πατήστε "+ Νέο Κεφάλαιο".</p>'; return; }
    let html = '';
    appData.forEach(ch => {
        const cnt = (ch.sections || []).length;
        html += `<div class="ch-item" data-id="${ch.id}"><div class="ch-item-header" onclick="this.parentElement.classList.toggle('open')">`;
        html += `<span class="ch-icon">${ch.icon||'📄'}</span><span class="ch-title">${ch.title}</span>`;
        html += `<span class="ch-badge">${cnt} ενότ.</span>`;
        html += `<button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();editChapter(${ch.id},\`${esc(ch.title)}\`,\`${esc(ch.slug)}\`,\`${esc(ch.icon)}\`)">✏️</button>`;
        html += `<button class="btn btn-danger btn-sm" onclick="event.stopPropagation();deleteChapter(${ch.id},\`${esc(ch.title)}\`)">🗑</button>`;
        html += `</div><div class="ch-item-body">`;
        (ch.sections||[]).forEach(sec => {
            html += `<div class="sec-item" onclick="openEditor(${sec.id},\`${esc(ch.slug)}\`,\`${esc(sec.slug)}\`)">`;
            html += `<span class="sec-title">${sec.title}</span><span class="sec-type ${sec.type}">${sec.type}</span>`;
            html += `<button class="btn btn-danger btn-sm" onclick="event.stopPropagation();deleteSection(${sec.id},\`${esc(sec.title)}\`)">✕</button></div>`;
        });
        html += `<button class="btn btn-ghost btn-sm" style="margin-top:10px;width:100%;justify-content:center" onclick="addSection(${ch.id})">+ Νέα Ενότητα</button>`;
        html += `</div></div>`;
    });
    el.innerHTML = html;
}
function esc(s) { return (s||'').replace(/`/g,'\\`').replace(/\$/g,'\\$'); }

// ─── Chapter CRUD ───
async function addChapter() {
    const title = prompt('Τίτλος Κεφαλαίου:'); if (!title) return;
    const icon = prompt('Emoji Icon:', '📄') || '📄';
    const res = await api('add_chapter', { title, icon });
    if (res.ok) { await refreshData(); toast('✓ Κεφάλαιο προστέθηκε'); }
}
async function editChapter(id, title, slug, icon) {
    const t = prompt('Τίτλος:', title); if (!t) return;
    const s = prompt('Slug:', slug) || slug;
    const i = prompt('Icon:', icon) || icon;
    await api('update_chapter', { id, title: t, slug: s, icon: i });
    await refreshData(); toast('✓ Ενημερώθηκε');
}
async function deleteChapter(id, title) {
    if (!confirm('Διαγραφή "' + title + '" και ΟΛΩΝ των ενοτήτων;')) return;
    await api('delete_chapter', { id }); await refreshData(); toast('✓ Διαγράφηκε');
}
async function addSection(chapterId) {
    const title = prompt('Τίτλος Ενότητας:'); if (!title) return;
    await api('add_section', { chapter_id: chapterId, title, type: 'article' });
    await refreshData(); toast('✓ Ενότητα προστέθηκε');
}
async function deleteSection(id, title) {
    if (!confirm('Διαγραφή "' + title + '";')) return;
    await api('delete_section', { id }); await refreshData(); toast('✓ Διαγράφηκε');
}

// ─── Tabs ───
function switchTab(name) {
    document.querySelectorAll('.tab').forEach((t, i) => {
        const panels = ['content', 'seo', 'media'];
        const isActive = panels[i] === name;
        t.classList.toggle('active', isActive);
        document.getElementById('tab-' + panels[i]).classList.toggle('active', isActive);
    });
}

// ─── Section Editor ───
async function openEditor(secId, chSlug, secSlug) {
    const sec = await apiGet('section', { ch: chSlug, sec: secSlug });
    if (sec.error) { toast('✗ ' + sec.error, false); return; }

    document.getElementById('edSectionId').value = sec.id;
    document.getElementById('edTitle').value = sec.title || '';
    document.getElementById('edSlug').value = sec.slug || '';
    document.getElementById('edType').value = sec.type || 'article';
    document.getElementById('edImage').value = sec.image || '';
    document.getElementById('edVideo').value = sec.video_url || '';
    document.getElementById('edMetaDesc').value = sec.meta_description || '';
    document.getElementById('edMetaKeywords').value = sec.meta_keywords || '';
    document.getElementById('edVideoRow').style.display = sec.type === 'video' ? '' : 'none';
    document.getElementById('editorTitle').textContent = '✏️ ' + sec.title;
    updateMetaCount();

    // Reset to content tab
    switchTab('content');

    // Reset HTML mode
    htmlMode = false;
    document.getElementById('edRawHtml').style.display = 'none';
    document.getElementById('quillWrap').style.display = '';
    document.getElementById('toggleIcon').textContent = '⟨/⟩';
    document.getElementById('toggleLabel').textContent = 'HTML κώδικας';

    // Destroy old Quill completely
    destroyQuill();

    // Show modal
    document.getElementById('editorModal').classList.add('open');

    // Init fresh Quill
    quill = new Quill('#edQuill', {
        theme: 'snow',
        placeholder: 'Γράψτε εδώ...',
        modules: {
            toolbar: [
                [{ header: [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['blockquote', 'code-block'],
                ['link', 'image', 'video'],
                [{ align: [] }],
                ['clean']
            ]
        }
    });

    // Load content
    quill.root.innerHTML = sec.content || '';
}

function destroyQuill() {
    if (quill) {
        quill = null;
    }
    // Fully reset the container
    const wrap = document.getElementById('quillWrap');
    wrap.innerHTML = '<div id="edQuill"></div>';
}

function toggleHtml() {
    if (!htmlMode) {
        // Visual → HTML
        const html = quill ? quill.root.innerHTML : '';
        document.getElementById('edRawHtml').value = html;
        document.getElementById('edRawHtml').style.display = 'block';
        document.getElementById('quillWrap').style.display = 'none';
        document.getElementById('toggleIcon').textContent = '✏️';
        document.getElementById('toggleLabel').textContent = 'Visual Editor';
        htmlMode = true;
    } else {
        // HTML → Visual
        const rawHtml = document.getElementById('edRawHtml').value;
        document.getElementById('edRawHtml').style.display = 'none';
        document.getElementById('quillWrap').style.display = '';
        if (quill) quill.root.innerHTML = rawHtml;
        document.getElementById('toggleIcon').textContent = '⟨/⟩';
        document.getElementById('toggleLabel').textContent = 'HTML κώδικας';
        htmlMode = false;
    }
}

function closeEditor() {
    document.getElementById('editorModal').classList.remove('open');
    destroyQuill();
    htmlMode = false;
}

function getEditorContent() {
    if (htmlMode) return document.getElementById('edRawHtml').value;
    if (quill) {
        const html = quill.root.innerHTML;
        return html === '<p><br></p>' ? '' : html;
    }
    return '';
}

async function saveSection() {
    const res = await api('update_section', {
        id: document.getElementById('edSectionId').value,
        title: document.getElementById('edTitle').value,
        slug: document.getElementById('edSlug').value,
        type: document.getElementById('edType').value,
        content: getEditorContent(),
        image: document.getElementById('edImage').value,
        video_url: document.getElementById('edVideo').value,
        meta_description: document.getElementById('edMetaDesc').value,
        meta_keywords: document.getElementById('edMetaKeywords').value
    });
    if (res.ok) { await refreshData(); closeEditor(); toast('✓ Αποθηκεύτηκε'); }
    else { toast('✗ Σφάλμα αποθήκευσης', false); }
}

// ─── Meta description counter ───
function updateMetaCount() {
    const len = (document.getElementById('edMetaDesc').value || '').length;
    const el = document.getElementById('metaDescCount');
    el.textContent = len + ' / 160';
    el.style.color = len > 160 ? 'var(--danger)' : 'var(--text3)';
}
document.getElementById('edMetaDesc').addEventListener('input', updateMetaCount);

// ─── Image Upload ───
const uploadZone = document.getElementById('uploadZone');
const uploadInput = document.getElementById('uploadInput');

uploadZone.addEventListener('click', () => uploadInput.click());
uploadZone.addEventListener('dragover', (e) => { e.preventDefault(); uploadZone.classList.add('dragover'); });
uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('dragover'));
uploadZone.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadZone.classList.remove('dragover');
    if (e.dataTransfer.files.length) uploadFile(e.dataTransfer.files[0]);
});
uploadInput.addEventListener('change', () => { if (uploadInput.files.length) uploadFile(uploadInput.files[0]); });

async function uploadFile(file) {
    const form = new FormData();
    form.append('action', 'upload_image');
    form.append('image', file);

    uploadZone.innerHTML = '<div class="upload-icon">⏳</div><strong>Ανεβαίνει...</strong>';

    try {
        const res = await fetch(API, { method: 'POST', body: form });
        const data = await res.json();

        uploadZone.innerHTML = '<div class="upload-icon">📁</div><strong>Σύρε εδώ ή κλίκαρε</strong><p>JPG, PNG, GIF, WebP, SVG — Μέχρι 5MB</p>';

        if (data.ok) {
            const preview = document.getElementById('uploadPreview');
            preview.innerHTML = `<img src="${data.url}" alt=""><span class="img-url" onclick="copyUrl('${data.url}')" title="Κλικ για αντιγραφή">${data.url}</span>`;
            toast('✓ Ανέβηκε: ' + data.filename);
        } else {
            toast('✗ ' + (data.error || 'Σφάλμα'), false);
        }
    } catch (err) {
        uploadZone.innerHTML = '<div class="upload-icon">📁</div><strong>Σύρε εδώ ή κλίκαρε</strong><p>JPG, PNG, GIF, WebP, SVG — Μέχρι 5MB</p>';
        toast('✗ Σφάλμα upload', false);
    }
}

function copyUrl(url) {
    navigator.clipboard.writeText(url).then(() => toast('✓ URL αντιγράφηκε'));
}

// ─── Init ───
renderChapters();
</script>
<?php endif; ?>
</body>
</html>
