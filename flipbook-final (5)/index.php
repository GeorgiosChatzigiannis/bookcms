<?php
/**
 * FlipBook Frontend — SQLite Backed, Lazy Content Loading
 */
require_once __DIR__ . '/db.php';

// ─── TOC (lightweight, no content) ───
$toc = get_toc();
$totalPages = get_total_sections();

// ─── Build flat slug list for routing ───
$flatSlugs = [];
$chapterFirstIndex = [];
$idx = 0;
foreach ($toc as $ch) {
    $chapterFirstIndex[$ch['slug']] = $idx;
    foreach ($ch['sections'] as $sec) {
        $flatSlugs[$ch['slug'] . '/' . $sec['slug']] = $idx;
        $idx++;
    }
}

// ─── Parse Route ───
$route = isset($_GET['route']) ? trim($_GET['route'], '/') : '';
$currentIndex = 0;
$currentSection = null;

if ($route && isset($flatSlugs[$route])) {
    $currentIndex = $flatSlugs[$route];
    $parts = explode('/', $route, 2);
    $currentSection = get_section($parts[0], $parts[1]);
} elseif ($route && isset($chapterFirstIndex[$route])) {
    $currentIndex = $chapterFirstIndex[$route];
} elseif (isset($_GET['page'])) {
    $p = (int)$_GET['page'];
    if ($p > 0 && $p <= $totalPages) {
        $currentIndex = $p - 1;
        $currentSection = get_section_by_index($currentIndex);
    }
}

// If no specific section resolved, get first
if (!$currentSection && $totalPages > 0) {
    $currentSection = get_section_by_index($currentIndex);
}

// ─── SEO Meta (server-side, per section) ───
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$bookTitle = get_meta('title', 'FlipBook');

if ($currentSection) {
    $metaTitle = $currentSection['title'] . ' — ' . $bookTitle;
    $metaDesc = strip_tags(mb_substr($currentSection['content'], 0, 160)) . '...';
    $metaImage = $currentSection['image'] ?: get_meta('cover_image', '');
    $metaUrl = $baseUrl . $basePath . '/' . $currentSection['chapter_slug'] . '/' . $currentSection['slug'];
} else {
    $metaTitle = $bookTitle;
    $metaDesc = get_meta('description', '');
    $metaImage = get_meta('cover_image', '');
    $metaUrl = $baseUrl . $basePath . '/';
}

// ─── Build TOC JSON (titles only, ~2-5KB even with 500 sections) ───
$tocJson = [];
foreach ($toc as $ch) {
    $sections = [];
    foreach ($ch['sections'] as $s) {
        $sections[] = [
            'id' => $s['id'],
            'slug' => $s['slug'],
            'title' => $s['title'],
            'type' => $s['type'],
        ];
    }
    $tocJson[] = [
        'id' => $ch['id'],
        'slug' => $ch['slug'],
        'title' => $ch['title'],
        'icon' => $ch['icon'],
        'sections' => $sections,
    ];
}
?>
<!DOCTYPE html>
<html lang="el" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

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

    <link rel="stylesheet" href="css/bookblock.css">
    <link rel="stylesheet" href="css/custom.css">
    <script src="js/modernizr.custom.79639.js"></script>
    <script>
        (function(){
            var t = localStorage.getItem('flipbook-theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

    <script>
        // TOC + current page content pre-loaded server-side
        window.FLIPBOOK = {
            basePath: <?= json_encode($basePath) ?>,
            apiBase: <?= json_encode($basePath . '/api.php') ?>,
            startPage: <?= (int)$currentIndex ?>,
            totalPages: <?= (int)$totalPages ?>,
            bookTitle: <?= json_encode($bookTitle) ?>,
            toc: <?= json_encode($tocJson, JSON_UNESCAPED_UNICODE) ?>,
            // Current page content embedded (no AJAX needed on first load)
            preloadedContent: <?= json_encode($currentSection ? $currentSection['content'] : '', JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
</head>
<body>

    <div id="container" class="container">

        <div class="menu-panel">
            <div class="menu-header">
                <div class="menu-brand">
                    <div class="logo-icon">📖</div>
                    <h3>
                        <?= htmlspecialchars($bookTitle) ?>
                        <small><?= htmlspecialchars(get_meta('subtitle', '')) ?></small>
                    </h3>
                </div>
                <div class="menu-search">
                    <span class="s-icon">🔍</span>
                    <input type="text" id="menuSearch" placeholder="Αναζήτηση..." autocomplete="off">
                </div>
            </div>

            <div class="menu-chapters">
                <!-- Built by JS from window.FLIPBOOK.toc -->
            </div>

            <div class="menu-footer">
                <div class="progress-row">
                    <span>Πρόοδος</span>
                    <span id="progressCount">0 / <?= $totalPages ?></span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill"></div>
                </div>
            </div>
        </div>

        <div class="bb-custom-wrapper">
            <div id="bb-bookblock" class="bb-bookblock"></div>

            <nav>
                <span id="bb-nav-prev">&larr;</span>
                <span id="bb-nav-next">&rarr;</span>
            </nav>

            <span id="tblcontents" class="menu-button">Περιεχόμενα</span>

            <div class="share-bar">
                <span class="page-indicator" id="pageIndicator">1 / <?= $totalPages ?></span>
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

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.8.3/jquery.min.js"></script>
    <script src="js/jquery.mousewheel.js"></script>
    <script src="js/jquerypp.custom.js"></script>
    <script src="js/jquery.bookblock.js"></script>
    <script src="js/app.js"></script>

</body>
</html>
