<?php
/**
 * FlipBook — Production Frontend
 * Current page rendered server-side. No AJAX on first load.
 */
require_once __DIR__ . '/db.php';

// ─── TOC ───
$toc = get_toc();
$totalPages = get_total_sections();
if ($totalPages === 0) { echo '<h1>Empty book</h1>'; exit; }

// ─── Build slug → index map ───
$flatSlugs = [];
$flatPages = []; // id, slug, title, type, ch_slug, ch_title, ch_icon
$chapterFirst = [];
$idx = 0;
foreach ($toc as $ch) {
    if (!isset($chapterFirst[$ch['slug']])) $chapterFirst[$ch['slug']] = $idx;
    foreach ($ch['sections'] as $sec) {
        $key = $ch['slug'] . '/' . $sec['slug'];
        $flatSlugs[$key] = $idx;
        $flatPages[$idx] = [
            'slug' => $key,
            'title' => $sec['title'],
            'type' => $sec['type'],
            'ch_title' => $ch['title'],
            'ch_icon' => $ch['icon'] ?? ''
        ];
        $idx++;
    }
}

// ─── Resolve current page ───
$route = '';
if (!empty($_GET['route'])) {
    $route = trim($_GET['route'], '/');
}
if (!$route) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $uri = substr($uri, strlen($scriptDir));
    $uri = trim($uri, '/');
    if ($uri && !preg_match('/\.(php|css|js|png|jpg|gif|svg|ico|json|db)$/i', $uri)) {
        $route = $uri;
    }
}

$currentIndex = 0;
if ($route && isset($flatSlugs[$route])) {
    $currentIndex = $flatSlugs[$route];
} elseif ($route && isset($chapterFirst[$route])) {
    $currentIndex = $chapterFirst[$route];
} elseif (isset($_GET['page'])) {
    $p = (int)$_GET['page'];
    if ($p > 0 && $p <= $totalPages) $currentIndex = $p - 1;
}

// ─── Get current section content from DB ───
$currentSection = get_section_by_index($currentIndex);

// ─── SEO ───
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$bookTitle = get_meta('title', 'FlipBook');
$bookSubtitle = get_meta('subtitle', '');

if ($currentSection) {
    $metaTitle = $currentSection['title'] . ' — ' . $bookTitle;
    $metaDesc = strip_tags(mb_substr($currentSection['content'] ?? '', 0, 155));
    $metaImage = $currentSection['image'] ?: get_meta('cover_image', '');
    $metaUrl = $baseUrl . $basePath . '/' . $currentSection['chapter_slug'] . '/' . $currentSection['slug'];
} else {
    $metaTitle = $bookTitle;
    $metaDesc = get_meta('description', '');
    $metaImage = get_meta('cover_image', '');
    $metaUrl = $baseUrl . $basePath . '/';
}

// ─── TOC JSON (titles only, ~2KB) ───
$tocJson = [];
foreach ($toc as $ch) {
    $secs = [];
    foreach ($ch['sections'] as $s) {
        $secs[] = ['id'=>$s['id'], 'slug'=>$s['slug'], 'title'=>$s['title'], 'type'=>$s['type']];
    }
    $tocJson[] = ['id'=>$ch['id'], 'slug'=>$ch['slug'], 'title'=>$ch['title'], 'icon'=>$ch['icon'], 'sections'=>$secs];
}

// ─── Type labels ───
$typeLabels = ['article'=>'📄 Άρθρο', 'video'=>'🎬 Βίντεο', 'app'=>'🛠️ Εφαρμογή'];

// ─── Render a page div ───
// ─── Sanitize images: strip width/height, add lazy loading, auto srcset ───
function sanitizeImages(string $html): string {
    // Remove width/height attributes
    $html = preg_replace('/<img([^>]*)\s+width\s*=\s*["\'][^"\']*["\']([^>]*)>/i', '<img$1$2>', $html);
    $html = preg_replace('/<img([^>]*)\s+height\s*=\s*["\'][^"\']*["\']([^>]*)>/i', '<img$1$2>', $html);
    // Remove inline width/height styles
    $html = preg_replace_callback('/<img([^>]*)style\s*=\s*"([^"]*)"([^>]*)>/i', function($m) {
        $style = preg_replace('/\b(width|height)\s*:\s*[^;]+;?/i', '', $m[2]);
        $style = trim($style, '; ');
        return '<img' . $m[1] . ($style ? 'style="'.$style.'"' : '') . $m[3] . '>';
    }, $html);
    // Add loading="lazy"
    $html = preg_replace('/<img(?![^>]*loading=)([^>]*)>/i', '<img loading="lazy"$1>', $html);
    
    // Auto-detect responsive variants for uploaded images
    $html = preg_replace_callback('/<img([^>]*)src\s*=\s*"(uploads\/[^"]+)"([^>]*)>/i', function($m) {
        $before = $m[1]; $src = $m[2]; $after = $m[3];
        // Skip if already has srcset
        if (stripos($before . $after, 'srcset') !== false) return $m[0];
        
        // Check for responsive variants (name-400w.webp, name-800w.webp, name-1200w.webp)
        $base = preg_replace('/-\d+w\.webp$/i', '', $src);
        $base = preg_replace('/\.webp$/i', '', $base);
        
        $variants = [];
        foreach ([400, 800, 1200] as $w) {
            $variant = $base . '-' . $w . 'w.webp';
            if (file_exists(__DIR__ . '/' . $variant)) {
                $variants[] = $variant . ' ' . $w . 'w';
            }
        }
        
        if (count($variants) > 1) {
            $srcset = implode(', ', $variants);
            return '<img' . $before . 'src="' . $src . '" srcset="' . $srcset . '" sizes="(max-width:480px) 400px,(max-width:900px) 800px,1200px"' . $after . '>';
        }
        
        return $m[0];
    }, $html);
    
    return $html;
}

function renderPageDiv(int $i, array $flatPages, ?string $content = null): string {
    global $typeLabels;
    if (!isset($flatPages[$i])) return "<div class=\"bb-item\" id=\"page-{$i}\"></div>";
    $p = $flatPages[$i];
    if ($content === null) {
        return "<div class=\"bb-item\" id=\"page-{$i}\"></div>";
    }
    // Sanitize images in content
    $content = sanitizeImages($content);
    $type = $p['type'];
    $tl = $typeLabels[$type] ?? $type;
    $html = "<div class=\"bb-item\" id=\"page-{$i}\">";
    $html .= "<div class=\"content\"><div class=\"scroller\">";
    $html .= "<div class=\"chapter-label\">" . htmlspecialchars($p['ch_icon'] . ' ' . $p['ch_title']) . "</div>";
    $html .= "<h2>" . htmlspecialchars($p['title']) . "</h2>";
    $html .= "<span class=\"type-indicator {$type}\">{$tl}</span>";
    $html .= $content;
    $html .= "</div></div></div>";
    return $html;
}

// Cache headers
header('X-Content-Type-Options: nosniff');
// Prevent aggressive caching (mobile browsers serve stale page on refresh)
header('Cache-Control: no-cache, must-revalidate');
header('Vary: Accept-Encoding');
?>
<!DOCTYPE html>
<html lang="el" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="Cache-Control" content="no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <title><?= htmlspecialchars($metaTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDesc) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($metaTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($metaDesc) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($metaImage) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($metaUrl) ?>">
    <meta property="og:type" content="article">
    <meta property="og:locale" content="el_GR">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($metaTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($metaDesc) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($metaImage) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <base href="<?= htmlspecialchars($basePath) ?>/">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="stylesheet" href="css/bookblock.css">
    <link rel="stylesheet" href="css/custom.css">

    <style>
    /* Critical inline — touch + mobile nav position */
    button,span[id^="bb-nav"],.menu-button{touch-action:manipulation;-webkit-tap-highlight-color:transparent}
    @media(max-width:768px){
        .bb-custom-wrapper nav{top:auto!important;bottom:16px!important;left:50%!important;transform:translateX(-50%);display:flex!important;gap:16px}
        .bb-custom-wrapper nav span{position:static!important;width:52px!important;height:52px!important;line-height:52px!important;font-size:20px!important;border-radius:50%!important;box-shadow:0 4px 16px rgba(0,0,0,.15)}
        .menu-button{width:44px!important;height:44px!important;border-radius:12px!important}
        .page-indicator{display:none}.bar-btn .s-label{display:none}
        .share-bar{top:10px;right:10px}.bar-btn{padding:8px 10px}
        .js .content{top:50px;bottom:80px}
        .js .content::before,.js .content::after{display:none!important}
        .scroller{padding:8px 5% 20px}
    }
    </style>

    <script src="js/modernizr.custom.79639.js"></script>
    <script>
    (function(){ var t=localStorage.getItem('flipbook-theme')||'light'; document.documentElement.setAttribute('data-theme',t); })();
    </script>
    <script>
    window.FLIPBOOK={
        basePath:<?= json_encode($basePath) ?>,
        apiBase:<?= json_encode($basePath.'/api.php') ?>,
        startPage:<?= $currentIndex ?>,
        totalPages:<?= $totalPages ?>,
        bookTitle:<?= json_encode($bookTitle) ?>,
        toc:<?= json_encode($tocJson, JSON_UNESCAPED_UNICODE) ?>
    };
    </script>
</head>
<body>
<div id="container" class="container">
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <div class="menu-panel">
        <div class="menu-header">
            <div class="menu-brand">
                <div class="logo-icon">📖</div>
                <h3><?= htmlspecialchars($bookTitle) ?><small><?= htmlspecialchars($bookSubtitle) ?></small></h3>
            </div>
            <div class="menu-search"><span class="s-icon">🔍</span><input type="text" id="menuSearch" placeholder="Αναζήτηση..." autocomplete="off"></div>
        </div>
        <div class="menu-chapters"></div>
        <div class="menu-footer">
            <div class="progress-row"><span>Πρόοδος</span><span id="progressCount">0 / <?= $totalPages ?></span></div>
            <div class="progress-track"><div class="progress-fill"></div></div>
        </div>
    </div>

    <div class="bb-custom-wrapper">
        <div id="bb-bookblock" class="bb-bookblock">
            <?php
            // ── SERVER-SIDE RENDER ALL PAGES ──
            // Current page: full content. Others: empty (lazy loaded by JS)
            for ($i = 0; $i < $totalPages; $i++) {
                if ($i === $currentIndex && $currentSection) {
                    echo renderPageDiv($i, $flatPages, $currentSection['content']);
                } else {
                    echo renderPageDiv($i, $flatPages);
                }
            }
            ?>
        </div>
        <nav>
            <span id="bb-nav-prev">&larr;</span>
            <span id="bb-nav-next">&rarr;</span>
        </nav>
        <span id="tblcontents" class="menu-button">Περιεχόμενα</span>
        <div class="share-bar">
            <span class="page-indicator" id="pageIndicator"><?= $currentIndex+1 ?> / <?= $totalPages ?></span>
            <button class="bar-btn theme-btn" id="themeBtn" title="Light / Dark">🌙</button>
            <button class="bar-btn" id="shareBtn">↗ <span class="s-label">Share</span></button>
            <div class="share-dropdown" id="shareDropdown">
                <a href="#" id="shareFb"><span class="s-ico">f</span> Facebook</a>
                <a href="#" id="shareX"><span class="s-ico">𝕏</span> X / Twitter</a>
                <a href="#" id="shareLi"><span class="s-ico">in</span> LinkedIn</a>
                <a href="#" id="shareWa"><span class="s-ico">💬</span> WhatsApp</a>
                <a href="#" id="shareCopy"><span class="s-ico">🔗</span> Αντιγραφή Link</a>
            </div>
        </div>
    </div>
</div>
<div class="toast-msg"></div>

<!-- Embed Popup (for HTML apps/pages) -->
<div class="embed-overlay" id="embedOverlay">
    <div class="embed-modal">
        <div class="embed-header">
            <span class="embed-title" id="embedTitle">Εφαρμογή</span>
            <button class="embed-close" id="embedClose" title="Κλείσιμο">✕</button>
        </div>
        <div class="embed-body">
            <iframe id="embedFrame" src="about:blank" sandbox="allow-scripts allow-same-origin allow-forms allow-popups" loading="lazy"></iframe>
        </div>
    </div>
</div>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.8.3/jquery.min.js"></script>
<script src="js/jquery.mousewheel.js"></script>
<script src="js/jquerypp.custom.js"></script>
<script src="js/jquery.bookblock.js"></script>
<script src="js/app.js"></script>
<script>
// Register Service Worker for PWA / offline support
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function(){});
}
</script>
</body>
</html>
