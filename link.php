<?php
/**
 * ADORNA - Update Gems and Materials
 * ==================================
 * Updates products.gem and products.material based on CSV mapping.
 * Delete after use!
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) die('ERROR: .env file not found');

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    [$key, $val] = explode('=', $line, 2);
    $env[trim($key)] = trim($val, " \t\"'");
}

$dbHost = $env['DB_HOST'] ?? 'localhost';
$dbName = $env['DB_NAME'] ?? '';
$dbUser = $env['DB_USER'] ?? '';
$dbPass = $env['DB_PASS'] ?? '';

if (empty($dbName) || empty($dbUser)) {
    die('ERROR: DB_NAME or DB_USER is empty in .env');
}

try {
    $pdo = new PDO('mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4', $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die('DB connection failed: ' . $e->getMessage());
}

$mapping = [
    'ring-1' => ['material' => 'Gold plated stainless steel', 'gem' => 'acrylic'],
    'bracelet-1' => ['material' => 'Copper', 'gem' => ''],
    'ring-2' => ['material' => 'Copper', 'gem' => ''],
    'bracelet-2' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-3' => ['material' => 'Stainless steel', 'gem' => 'Agate'],
    'bracelet-3' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-4' => ['material' => 'Copper', 'gem' => ''],
    'bracelet-4' => ['material' => 'Copper', 'gem' => 'Agate'],
    'ring-5' => ['material' => 'Copper', 'gem' => ''],
    'bracelet-5' => ['material' => '', 'gem' => ''],
    'ring-6' => ['material' => 'Stainless steel', 'gem' => 'Marcasite'],
    'bracelet-6' => ['material' => 'Gold plated stainless steel', 'gem' => ''],
    'ring-7' => ['material' => 'Copper', 'gem' => 'Agate'],
    'bracelet-7' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-8' => ['material' => 'Stainless steel', 'gem' => 'Agate'],
    'bracelet-8' => ['material' => 'Gold plated stainless steel', 'gem' => ''],
    'ring-9' => ['material' => 'Silver plated stainless steel', 'gem' => 'Marcasite'],
    'bracelet-9' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-10' => ['material' => 'Gold plated stainless steel', 'gem' => 'Agate'],
    'bracelet-10' => ['material' => 'Gold plated stainless steel', 'gem' => ''],
    'ring-11' => ['material' => 'Stainless steel', 'gem' => 'Agate'],
    'bracelet-11' => ['material' => 'Copper', 'gem' => ''],
    'ring-12' => ['material' => 'Gold plated stainless steel', 'gem' => 'Zircon'],
    'bracelet-12' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-13' => ['material' => 'Gold plated stainless steel', 'gem' => 'Marcasite'],
    'bracelet-13' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-14' => ['material' => 'Gold plated stainless steel', 'gem' => 'Zircon'],
    'bracelet-14' => ['material' => 'Gold plated stainless steel', 'gem' => 'Agate'],
    'ring-15' => ['material' => 'Stainless steel', 'gem' => 'Zircon'],
    'bracelet-15' => ['material' => 'Stainless steel', 'gem' => 'Agate'],
    'ring-16' => ['material' => 'Gold plated stainless steel', 'gem' => 'Marcasite'],
    'bracelet-16' => ['material' => 'Stainless steel', 'gem' => 'Agate'],
    'ring-17' => ['material' => 'Copper', 'gem' => 'Agate'],
    'bracelet-17' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-18' => ['material' => 'Stainless steel', 'gem' => 'Marcasite'],
    'bracelet-18' => ['material' => 'Copper', 'gem' => ''],
    'ring-19' => ['material' => 'Stainless steel', 'gem' => 'Zircon'],
    'bracelet-19' => ['material' => 'Copper', 'gem' => ''],
    'ring-20' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-20' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-21' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-21' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-22' => ['material' => 'Gold plated stainless steel', 'gem' => ''],
    'bracelet-22' => ['material' => 'Copper', 'gem' => ''],
    'ring-23' => ['material' => 'Stainless steel', 'gem' => 'Marcasite'],
    'bracelet-23' => ['material' => 'Copper', 'gem' => ''],
    'ring-24' => ['material' => 'Stainless steel', 'gem' => 'Marcasite'],
    'bracelet-24' => ['material' => 'Copper', 'gem' => ''],
    'ring-25' => ['material' => 'Stainless steel', 'gem' => 'Marcasite'],
    'bracelet-25' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-26' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-25' => ['material' => 'Gold plated stainless steel', 'gem' => ''],
    'ring-27' => ['material' => '', 'gem' => ''],
    'bracelet-25' => ['material' => 'Silver plated stainless steel', 'gem' => ''],
    'ring-28' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-26' => ['material' => 'Copper', 'gem' => 'Agate'],
    'ring-29' => ['material' => 'Copper', 'gem' => ''],
    'bracelet-27' => ['material' => 'Gold plated stainless steel', 'gem' => ''],
    'ring-30' => ['material' => 'Copper', 'gem' => ''],
    'bracelet-28' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-31' => ['material' => 'Copper', 'gem' => ''],
    'bracelet-29' => ['material' => 'Copper', 'gem' => 'Agate'],
    'ring-32' => ['material' => 'Copper', 'gem' => ''],
    'bracelet-30' => ['material' => 'Copper', 'gem' => ''],
    'ring-33' => ['material' => 'Copper', 'gem' => 'Resin'],
    'bracelet-31' => ['material' => 'Copper', 'gem' => ''],
    'ring-34' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-32' => ['material' => 'Copper', 'gem' => ''],
    'ring-35' => ['material' => 'Copper', 'gem' => 'Agate'],
    'bracelet-33' => ['material' => 'Copper', 'gem' => 'Agate'],
    'ring-36' => ['material' => 'Copper', 'gem' => ''],
    'bracelet-34' => ['material' => 'Copper', 'gem' => ''],
    'ring-37' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-35' => ['material' => '', 'gem' => ''],
    'ring-38' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-36' => ['material' => 'Iridescent Hematite', 'gem' => ''],
    'ring-39' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-37' => ['material' => 'Copper', 'gem' => 'Agate'],
    'ring-40' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-38' => ['material' => 'Copper', 'gem' => ''],
    'ring-41' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-39' => ['material' => 'Stainless steel', 'gem' => ''],
    'ring-42' => ['material' => 'Stainless steel', 'gem' => 'Zircon'],
    'bracelet-40' => ['material' => 'Stainless steel', 'gem' => 'Marcasite'],
    'ring-43' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-41' => ['material' => 'Copper', 'gem' => 'Agate'],
    'ring-44' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-42' => ['material' => 'Stainless steel', 'gem' => 'Crystals'],
    'ring-45' => ['material' => 'Stainless steel', 'gem' => 'Crystals'],
    'bracelet-43' => ['material' => 'Gold plated stainless steel', 'gem' => ''],
    'ring-46' => ['material' => 'Stainless steel', 'gem' => 'Agate'],
    'bracelet-43' => ['material' => 'Silver plated stainless steel', 'gem' => ''],
    'ring-47' => ['material' => 'Stainless steel', 'gem' => 'Agate'],
    'bracelet-44' => ['material' => 'Copper', 'gem' => ''],
    'ring-48' => ['material' => 'Stainless steel', 'gem' => 'Agate'],
    'bracelet-45' => ['material' => 'Gold plated stainless steel', 'gem' => ''],
    'ring-49' => ['material' => 'Gold plated stainless steel', 'gem' => 'Marcasite'],
    'bracelet-46' => ['material' => 'Copper', 'gem' => '4 versions'],
    'ring-50' => ['material' => 'Stainless steel', 'gem' => 'Agate'],
    'bracelet-47' => ['material' => 'Copper', 'gem' => 'Agate'],
    'bracelet-48' => ['material' => 'Silver plated Copper', 'gem' => ''],
    'bracelet-49' => ['material' => 'Stainless steel', 'gem' => ''],
    'bracelet-50' => ['material' => 'Copper', 'gem' => ''],
];

$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] == '1';
$updated = 0;

echo "<h1>ADORNA Gem/Material Updater</h1>";
if ($dryRun) echo "<h3>DRY RUN - no changes saved</h3>";
else echo "<h3>LIVE - changes saved</h3>";

echo "<pre>";

foreach ($mapping as $slug => $data) {
    $material = $data['material'];
    $gem = $data['gem'];
    
    // Some slugs might be exact, others might just contain the number
    $stmt = $pdo->prepare("SELECT id, slug, material, gem FROM products WHERE slug = ? OR slug = ?");
    // e.g. 'ring-45' and '45' for 'ring-45'
    $alt_slug = explode('-', $slug)[1] ?? $slug; 
    
    $stmt->execute([$slug, $alt_slug]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        if ($product['material'] !== $material || $product['gem'] !== $gem) {
            if (!$dryRun) {
                $update = $pdo->prepare("UPDATE products SET material = ?, gem = ? WHERE id = ?");
                $update->execute([$material, $gem, $product['id']]);
            }
            echo "Updated ID {$product['id']} ($slug): Material -> '$material', Gem -> '$gem'\n";
            $updated++;
        } else {
            echo "Skipped ID {$product['id']} ($slug): Already up to date\n";
        }
    } else {
        echo "Warning: Product with slug '$slug' or '$alt_slug' not found in DB.\n";
    }
}

echo "\nFinished. Updated $updated products.\n";
echo "</pre>";
