<?php
// Custom "not found" page. Apache serves it through ErrorDocument (.htaccess).
require_once __DIR__ . '/config/config.php';
lume_error_page(404, 'Page not found', 'The page you are looking for does not exist or has moved.');
