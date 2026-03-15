<?php
/**
 * FlipBook Install / Migration
 * Imports data.json into SQLite database
 * Run once, then delete this file!
 */

require_once __DIR__ . '/db.php';

$jsonPath = __DIR__ . '/data.json';

if (!file_exists($jsonPath)) {
    die("❌ data.json not found. Place it in the same directory and reload.\n");
}

$raw = file_get_contents($jsonPath);
$data = json_decode($raw, true);

if (!$data || !isset($data['chapters'])) {
    die("❌ Invalid data.json format.\n");
}

$db = flipbook_db();

echo "<pre style='font-family:monospace;background:#111;color:#0f0;padding:20px;'>\n";
echo "🔧 FlipBook — Εγκατάσταση / Μετάβαση σε SQLite\n";
echo str_repeat('═', 50) . "\n\n";

// ─── Import Book Meta ───
$book = $data['book'] ?? [];
$metaKeys = ['title', 'subtitle', 'author', 'description', 'cover_image'];
foreach ($metaKeys as $k) {
    if (isset($book[$k])) {
        set_meta($k, $book[$k]);
    }
}
echo "✅ Book meta imported (" . count($metaKeys) . " keys)\n";

// ─── Import Chapters & Sections ───
$totalChapters = 0;
$totalSections = 0;

$db->beginTransaction();

try {
    $chStmt = $db->prepare('INSERT INTO chapters (slug, title, icon, sort_order) VALUES (?, ?, ?, ?)');
    $secStmt = $db->prepare('
        INSERT INTO sections (chapter_id, slug, title, type, content, image, video_url, sort_order) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');

    foreach ($data['chapters'] as $chIdx => $ch) {
        $chStmt->execute([
            $ch['slug'],
            $ch['title'],
            $ch['icon'] ?? '📄',
            $chIdx
        ]);
        $chapterId = $db->lastInsertId();
        $totalChapters++;

        echo "  📁 {$ch['title']} (slug: {$ch['slug']})\n";

        foreach ($ch['sections'] ?? [] as $secIdx => $sec) {
            $secStmt->execute([
                $chapterId,
                $sec['slug'],
                $sec['title'],
                $sec['type'] ?? 'article',
                $sec['content'] ?? '',
                $sec['image'] ?? '',
                $sec['video_url'] ?? '',
                $secIdx
            ]);
            $totalSections++;
            echo "     📄 {$sec['title']}\n";
        }
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ ΣΦΑΛΜΑ: " . $e->getMessage() . "\n";
    echo "</pre>";
    exit;
}

echo "\n" . str_repeat('═', 50) . "\n";
echo "✅ Ολοκληρώθηκε!\n";
echo "   📁 Κεφάλαια: {$totalChapters}\n";
echo "   📄 Ενότητες: {$totalSections}\n";
echo "   💾 Database: " . FLIPBOOK_DB_PATH . "\n";
echo "   📦 Μέγεθος: " . round(filesize(FLIPBOOK_DB_PATH) / 1024, 1) . " KB\n";
echo "\n⚠️  ΣΒΗΣΕ αυτό το αρχείο (install.php) μετά την εγκατάσταση!\n";
echo "🔗 <a href='./'>Δες το βιβλίο</a> | <a href='admin.php'>Admin Panel</a>\n";
echo "</pre>";
