<?php
/**
 * Fix broken news URLs in Nearbypost
 */

$host = '127.0.0.1';
$dbname = 'nearbypost';
$user = 'postgres';
$password = 'nearbypost123';

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== Nearbypost URL Fix Script ===\n\n";
    
    // Step 1: Check current state
    echo "Step 1: Checking current state...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM news_items WHERE url LIKE '%nearbypost.com%'");
    $badNewsCount = $stmt->fetchColumn();
    echo "  - News items with nearbypost.com URLs: $badNewsCount\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM feed_ready_items WHERE url LIKE '%nearbypost.com%'");
    $badFeedCount = $stmt->fetchColumn();
    echo "  - Feed ready items with nearbypost.com URLs: $badFeedCount\n";
    
    // Step 2: Update news_items
    echo "\nStep 2: Fixing news_items URLs...\n";
    
    // Try to find original URLs from raw_ingest by joining on title
    $sql = "
        UPDATE news_items ni
        SET url = ri.raw_json_payload->>'url'
        FROM raw_ingest ri
        WHERE ri.raw_json_payload->>'title' = ni.title
          AND ni.url LIKE '%nearbypost.com%'
          AND ri.raw_json_payload->>'url' IS NOT NULL
          AND ri.raw_json_payload->>'url' != ''
    ";
    
    $updatedFromRaw = $pdo->exec($sql);
    echo "  - Updated from raw_ingest: $updatedFromRaw\n";
    
    // Remaining items - update to fallback URL with unique ID
    $stmt = $pdo->query("SELECT id FROM news_items WHERE url LIKE '%nearbypost.com%'");
    $remaining = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $updatedToFallback = 0;
    foreach ($remaining as $row) {
        $fallbackUrl = 'https://nearbypost.com/feed/' . $row['id'];
        $updateStmt = $pdo->prepare("UPDATE news_items SET url = :url WHERE id = :id");
        $updateStmt->execute([':url' => $fallbackUrl, ':id' => $row['id']]);
        $updatedToFallback++;
    }
    echo "  - Updated to fallback URL: $updatedToFallback\n";
    
    // Step 3: Verify news_items fix
    echo "\nStep 3: Verifying news_items fix...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM news_items WHERE url LIKE '%nearbypost.com%'");
    $remainingBad = $stmt->fetchColumn();
    echo "  - Remaining bad URLs in news_items: $remainingBad\n";
    
    if ($remainingBad == 0) {
        echo "  ✓ All news_items URLs fixed!\n";
    } else {
        echo "  ✗ WARNING: Still have $remainingBad bad URLs\n";
    }
    
    // Step 4: Fix feed_ready_items
    echo "\nStep 4: Fixing feed_ready_items URLs...\n";
    
    // Copy URL from parent news_item
    $sql = "
        UPDATE feed_ready_items fri
        SET url = ni.url
        FROM news_items ni
        WHERE ni.id = fri.news_item_id
          AND fri.url LIKE '%nearbypost.com%'
    ";
    $updatedFromParent = $pdo->exec($sql);
    echo "  - Updated from parent news_items: $updatedFromParent\n";
    
    // For feed_ready_items without parent, use fallback with unique ID
    $stmt = $pdo->query("SELECT id, news_item_id FROM feed_ready_items WHERE url LIKE '%nearbypost.com%'");
    $remainingFeed = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $updatedFeedFallback = 0;
    foreach ($remainingFeed as $row) {
        $parentId = $row['news_item_id'] ?? $row['id'];
        $fallbackUrl = 'https://nearbypost.com/feed/' . $parentId;
        $updateStmt = $pdo->prepare("UPDATE feed_ready_items SET url = :url WHERE id = :id");
        $updateStmt->execute([':url' => $fallbackUrl, ':id' => $row['id']]);
        $updatedFeedFallback++;
    }
    echo "  - Updated to fallback URL: $updatedFeedFallback\n";
    
    // Step 5: Verify feed_ready_items fix
    echo "\nStep 5: Verifying feed_ready_items fix...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM feed_ready_items WHERE url LIKE '%nearbypost.com%'");
    $remainingFeedBad = $stmt->fetchColumn();
    echo "  - Remaining bad URLs in feed_ready_items: $remainingFeedBad\n";
    
    if ($remainingFeedBad == 0) {
        echo "  ✓ All feed_ready_items URLs fixed!\n";
    } else {
        echo "  ✗ WARNING: Still have $remainingFeedBad bad URLs\n";
    }
    
    // Step 6: Show sample of updated URLs
    echo "\nStep 6: Sample of fixed URLs...\n";
    $stmt = $pdo->query("SELECT id, title, url, source FROM news_items WHERE id <= 20 ORDER BY id LIMIT 10");
    echo "  ID | Title | URL | Source\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $title = substr($row['title'], 0, 40) . '...';
        echo "  {$row['id']} | {$title} | {$row['url']} | {$row['source']}\n";
    }
    
    echo "\n=== Fix Complete ===\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
