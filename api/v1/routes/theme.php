<?php
// Expose theme configuration, general settings, and homepage sections
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        // Fetch active theme CSS
        $themeCss = '';
        try {
            $themeQuery = db()->query("SELECT css_content FROM store_themes WHERE is_active = 1 LIMIT 1");
            $activeTheme = $themeQuery->fetch(PDO::FETCH_ASSOC);
            $themeCss = $activeTheme ? $activeTheme['css_content'] : '';
        } catch (Exception $e) {
            // Ignore if store_themes doesn't exist
        }

        // Fetch general settings
        $settingsQuery = db()->query("SELECT key_name, value FROM settings");
        $settingsRows = $settingsQuery->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($settingsRows as $row) {
            $settings[$row['key_name']] = $row['value'];
        }

        // Fetch homepage sections
        $homepageSections = [];
        if (function_exists('get_homepage_sections')) {
            $homepageSections = get_homepage_sections(true); // Fetch active sections
        }

        // Fetch featured products for the homepage (or categories, whatever is needed)
        // Actually, we can fetch featured products in the frontend using /api/v1/products, but we can also include them here for speed
        
        echo json_encode([
            'status' => 200,
            'data' => [
                'css' => $themeCss,
                'settings' => $settings,
                'homepage_sections' => $homepageSections
            ]
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 500, 'error' => 'Database error']);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 405, 'error' => 'Method not allowed']);
}
