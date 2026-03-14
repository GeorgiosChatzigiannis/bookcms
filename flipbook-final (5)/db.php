<?php
/**
 * FlipBook Database Layer (SQLite)
 * 
 * Usage: require_once __DIR__ . '/db.php';
 *        $db = flipbook_db();
 */

define('FLIPBOOK_DB_PATH', __DIR__ . '/data/flipbook.db');

function flipbook_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = dirname(FLIPBOOK_DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $isNew = !file_exists(FLIPBOOK_DB_PATH);

    $pdo = new PDO('sqlite:' . FLIPBOOK_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Performance pragmas
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=5000');

    if ($isNew) flipbook_create_schema($pdo);

    return $pdo;
}

function flipbook_create_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS book_meta (
            key   TEXT PRIMARY KEY,
            value TEXT DEFAULT ''
        );

        CREATE TABLE IF NOT EXISTS chapters (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            slug       TEXT    UNIQUE NOT NULL,
            title      TEXT    NOT NULL,
            icon       TEXT    DEFAULT '📄',
            sort_order INTEGER DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS sections (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            chapter_id       INTEGER NOT NULL,
            slug             TEXT    NOT NULL,
            title            TEXT    NOT NULL,
            type             TEXT    DEFAULT 'article',
            content          TEXT    DEFAULT '',
            image            TEXT    DEFAULT '',
            video_url        TEXT    DEFAULT '',
            meta_description TEXT    DEFAULT '',
            meta_keywords    TEXT    DEFAULT '',
            sort_order       INTEGER DEFAULT 0,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE,
            UNIQUE(chapter_id, slug)
        );

        CREATE INDEX IF NOT EXISTS idx_sections_chapter ON sections(chapter_id, sort_order);
        CREATE INDEX IF NOT EXISTS idx_chapters_sort ON chapters(sort_order);
    ");

    // Migration: add SEO columns if missing (for existing databases)
    try { $pdo->exec("ALTER TABLE sections ADD COLUMN meta_description TEXT DEFAULT ''"); } catch (\Exception $e) {}
    try { $pdo->exec("ALTER TABLE sections ADD COLUMN meta_keywords TEXT DEFAULT ''"); } catch (\Exception $e) {}
}

// ─── Helpers ───

function get_meta(string $key, string $default = ''): string {
    $db = flipbook_db();
    $stmt = $db->prepare('SELECT value FROM book_meta WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

function set_meta(string $key, string $value): void {
    $db = flipbook_db();
    $stmt = $db->prepare('INSERT OR REPLACE INTO book_meta (key, value) VALUES (?, ?)');
    $stmt->execute([$key, $value]);
}

/**
 * Get TOC (chapters + section titles, NO content) - lightweight for sidebar
 */
function get_toc(): array {
    $db = flipbook_db();
    
    $chapters = $db->query('SELECT id, slug, title, icon FROM chapters ORDER BY sort_order, id')->fetchAll();
    
    $sections = $db->query('
        SELECT id, chapter_id, slug, title, type, image 
        FROM sections 
        ORDER BY sort_order, id
    ')->fetchAll();
    
    // Group sections by chapter
    $secByChapter = [];
    foreach ($sections as $s) {
        $secByChapter[$s['chapter_id']][] = $s;
    }
    
    foreach ($chapters as &$ch) {
        $ch['sections'] = $secByChapter[$ch['id']] ?? [];
    }
    
    return $chapters;
}

/**
 * Get single section by chapter_slug/section_slug - for lazy loading
 */
function get_section(string $chapterSlug, string $sectionSlug): ?array {
    $db = flipbook_db();
    $stmt = $db->prepare('
        SELECT s.*, c.slug AS chapter_slug, c.title AS chapter_title, c.icon AS chapter_icon
        FROM sections s
        JOIN chapters c ON c.id = s.chapter_id
        WHERE c.slug = ? AND s.slug = ?
    ');
    $stmt->execute([$chapterSlug, $sectionSlug]);
    return $stmt->fetch() ?: null;
}

/**
 * Get section by flat index (0-based) for page=N URLs
 */
function get_section_by_index(int $index): ?array {
    $db = flipbook_db();
    $stmt = $db->prepare('
        SELECT s.*, c.slug AS chapter_slug, c.title AS chapter_title, c.icon AS chapter_icon
        FROM sections s
        JOIN chapters c ON c.id = s.chapter_id
        ORDER BY c.sort_order, c.id, s.sort_order, s.id
        LIMIT 1 OFFSET ?
    ');
    $stmt->execute([$index]);
    return $stmt->fetch() ?: null;
}

/**
 * Get total section count
 */
function get_total_sections(): int {
    $db = flipbook_db();
    return (int) $db->query('SELECT COUNT(*) FROM sections')->fetchColumn();
}

/**
 * Get flat page list (for building slugToIndex map) - titles only, no content
 */
function get_flat_pages(): array {
    $db = flipbook_db();
    return $db->query('
        SELECT s.slug AS sec_slug, s.title AS sec_title, s.type,
               c.slug AS ch_slug, c.title AS ch_title, c.icon AS ch_icon
        FROM sections s
        JOIN chapters c ON c.id = s.chapter_id
        ORDER BY c.sort_order, c.id, s.sort_order, s.id
    ')->fetchAll();
}

/**
 * Slugify Greek text
 */
function slugify(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    $map = [
        'α'=>'a','β'=>'v','γ'=>'g','δ'=>'d','ε'=>'e','ζ'=>'z','η'=>'i','θ'=>'th',
        'ι'=>'i','κ'=>'k','λ'=>'l','μ'=>'m','ν'=>'n','ξ'=>'x','ο'=>'o','π'=>'p',
        'ρ'=>'r','σ'=>'s','ς'=>'s','τ'=>'t','υ'=>'y','φ'=>'f','χ'=>'ch','ψ'=>'ps',
        'ω'=>'o','ά'=>'a','έ'=>'e','ή'=>'i','ί'=>'i','ό'=>'o','ύ'=>'y','ώ'=>'o',
        'ϊ'=>'i','ϋ'=>'y','ΐ'=>'i','ΰ'=>'y'
    ];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9\-]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}
