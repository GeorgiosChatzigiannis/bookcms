<?php
/**
 * FlipBook API
 * 
 * GET  ?action=toc          → chapters + section titles (no content)
 * GET  ?action=section&ch=X&sec=Y  → single section with content
 * GET  ?action=meta         → book meta
 * POST (admin operations)   → requires session auth
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ═══════════════════════════════════════════
// PUBLIC ENDPOINTS (no auth needed)
// ═══════════════════════════════════════════

if ($action === 'toc') {
    echo json_encode(get_toc(), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'meta') {
    $db = flipbook_db();
    $rows = $db->query('SELECT key, value FROM book_meta')->fetchAll();
    $meta = [];
    foreach ($rows as $r) $meta[$r['key']] = $r['value'];
    echo json_encode($meta, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'section') {
    $chSlug = $_GET['ch'] ?? '';
    $secSlug = $_GET['sec'] ?? '';
    
    if (!$chSlug || !$secSlug) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing ch/sec params']);
        exit;
    }
    
    $section = get_section($chSlug, $secSlug);
    if (!$section) {
        http_response_code(404);
        echo json_encode(['error' => 'Section not found']);
        exit;
    }
    
    echo json_encode($section, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'flat_pages') {
    echo json_encode(get_flat_pages(), JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════
// ADMIN ENDPOINTS (POST, auth required)
// ═══════════════════════════════════════════

session_start();
$loggedIn = isset($_SESSION['flipbook_admin']) && $_SESSION['flipbook_admin'] === true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$loggedIn) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    
    $db = flipbook_db();
    
    // --- Save book meta ---
    if ($action === 'save_meta') {
        $fields = ['title', 'subtitle', 'author', 'description', 'cover_image'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) set_meta($f, $_POST[$f]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    
    // --- Add chapter ---
    if ($action === 'add_chapter') {
        $title = trim($_POST['title'] ?? '');
        if (!$title) { echo json_encode(['ok' => false, 'error' => 'Empty title']); exit; }
        
        $maxSort = (int)$db->query('SELECT COALESCE(MAX(sort_order),0) FROM chapters')->fetchColumn();
        $slug = slugify($title);
        
        // Ensure unique slug
        $existing = $db->prepare('SELECT COUNT(*) FROM chapters WHERE slug = ?');
        $existing->execute([$slug]);
        if ($existing->fetchColumn() > 0) $slug .= '-' . time();
        
        $stmt = $db->prepare('INSERT INTO chapters (slug, title, icon, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$slug, $title, $_POST['icon'] ?? '📄', $maxSort + 1]);
        
        echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
        exit;
    }
    
    // --- Update chapter ---
    if ($action === 'update_chapter') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false]); exit; }
        
        $stmt = $db->prepare('UPDATE chapters SET title=?, slug=?, icon=? WHERE id=?');
        $stmt->execute([$_POST['title'], $_POST['slug'], $_POST['icon'] ?? '📄', $id]);
        
        echo json_encode(['ok' => $stmt->rowCount() > 0]);
        exit;
    }
    
    // --- Delete chapter (cascades to sections) ---
    if ($action === 'delete_chapter') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM chapters WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['ok' => $stmt->rowCount() > 0]);
        exit;
    }
    
    // --- Reorder chapters ---
    if ($action === 'reorder_chapters') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (is_array($ids)) {
            $stmt = $db->prepare('UPDATE chapters SET sort_order = ? WHERE id = ?');
            foreach ($ids as $order => $id) {
                $stmt->execute([$order, $id]);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    
    // --- Add section ---
    if ($action === 'add_section') {
        $chapterId = (int)($_POST['chapter_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if (!$chapterId || !$title) { echo json_encode(['ok' => false, 'error' => 'Missing data']); exit; }
        
        $maxSort = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM sections WHERE chapter_id = $chapterId")->fetchColumn();
        $slug = slugify($title);
        
        // Ensure unique slug within chapter
        $existing = $db->prepare('SELECT COUNT(*) FROM sections WHERE chapter_id = ? AND slug = ?');
        $existing->execute([$chapterId, $slug]);
        if ($existing->fetchColumn() > 0) $slug .= '-' . time();
        
        $stmt = $db->prepare('INSERT INTO sections (chapter_id, slug, title, type, content, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$chapterId, $slug, $title, $_POST['type'] ?? 'article', '<p>Νέα ενότητα...</p>', $maxSort + 1]);
        
        echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
        exit;
    }
    
    // --- Update section ---
    if ($action === 'update_section') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false]); exit; }
        
        $fields = [];
        $values = [];
        foreach (['title', 'slug', 'type', 'content', 'image', 'video_url', 'meta_description', 'meta_keywords'] as $f) {
            if (isset($_POST[$f])) {
                $fields[] = "$f = ?";
                $values[] = $_POST[$f];
            }
        }
        
        if (empty($fields)) { echo json_encode(['ok' => false]); exit; }
        
        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        $values[] = $id;
        
        $sql = 'UPDATE sections SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        
        echo json_encode(['ok' => $stmt->rowCount() >= 0]);
        exit;
    }
    
    // --- Delete section ---
    if ($action === 'delete_section') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM sections WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['ok' => $stmt->rowCount() > 0]);
        exit;
    }
    
    // --- Reorder sections within chapter ---
    if ($action === 'reorder_sections') {
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (is_array($ids)) {
            $stmt = $db->prepare('UPDATE sections SET sort_order = ? WHERE id = ?');
            foreach ($ids as $order => $id) {
                $stmt->execute([$order, $id]);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    
    // --- Upload image (auto-convert WebP, generate 3 responsive sizes) ---
    if ($action === 'upload_image') {
        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
            exit;
        }
        
        $file = $_FILES['image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        
        if (!in_array($ext, $allowed)) {
            echo json_encode(['ok' => false, 'error' => 'Μη αποδεκτός τύπος: ' . $ext]);
            exit;
        }
        
        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'error' => 'Μέγιστο 10MB']);
            exit;
        }

        $baseName = time() . '-' . preg_replace('/[^a-z0-9\-]/', '', strtolower(pathinfo($file['name'], PATHINFO_FILENAME)));
        
        // SVG: just move
        if ($ext === 'svg') {
            $fn = $baseName . '.svg';
            move_uploaded_file($file['tmp_name'], $uploadDir . $fn);
            echo json_encode(['ok' => true, 'url' => 'uploads/' . $fn, 'filename' => $fn]);
            exit;
        }

        // Generate responsive sizes: 400w, 800w, 1200w
        $sizes = [400, 800, 1200];
        $quality = 82;
        $urls = [];
        $mainUrl = '';

        if (function_exists('imagewebp') && function_exists('imagecreatefromstring')) {
            $imgData = file_get_contents($file['tmp_name']);
            $src = @imagecreatefromstring($imgData);
            
            if ($src) {
                $origW = imagesx($src);
                $origH = imagesy($src);
                
                foreach ($sizes as $targetW) {
                    if ($targetW >= $origW && !empty($urls)) continue; // Skip if original is smaller
                    
                    $w = min($targetW, $origW);
                    $h = (int)($origH * ($w / $origW));
                    
                    $resized = imagecreatetruecolor($w, $h);
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    imagecopyresampled($resized, $src, 0, 0, 0, 0, $w, $h, $origW, $origH);
                    
                    $fn = $baseName . '-' . $w . 'w.webp';
                    if (imagewebp($resized, $uploadDir . $fn, $quality)) {
                        $urls[] = ['url' => 'uploads/' . $fn, 'width' => $w];
                        if (!$mainUrl || $w === 800) $mainUrl = 'uploads/' . $fn;
                    }
                    imagedestroy($resized);
                    
                    if ($w >= $origW) break; // Original is smaller than target
                }
                imagedestroy($src);
                
                if (!empty($urls)) {
                    // Build srcset string
                    $srcset = implode(', ', array_map(function($u) {
                        return $u['url'] . ' ' . $u['width'] . 'w';
                    }, $urls));
                    
                    // Main URL = 800w or largest available
                    if (!$mainUrl) $mainUrl = end($urls)['url'];
                    
                    echo json_encode([
                        'ok' => true,
                        'url' => $mainUrl,
                        'srcset' => $srcset,
                        'sizes' => '(max-width: 480px) 400px, (max-width: 900px) 800px, 1200px',
                        'variants' => $urls,
                        'original_size' => $file['size'],
                        'format' => 'webp'
                    ]);
                    exit;
                }
            }
        }
        
        // Fallback: save original
        $fn = $baseName . '.' . $ext;
        move_uploaded_file($file['tmp_name'], $uploadDir . $fn);
        echo json_encode([
            'ok' => true,
            'url' => 'uploads/' . $fn,
            'filename' => $fn,
            'format' => $ext,
            'note' => 'WebP unavailable'
        ]);
        exit;
    }
}

// Default
http_response_code(400);
echo json_encode(['error' => 'Unknown action: ' . $action]);
