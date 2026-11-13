<?php
// Custom "forbidden" page. Apache serves it through ErrorDocument (.htaccess),
// e.g. for the blocked folders (config/, includes/, database/).
require_once __DIR__ . '/config/config.php';
lume_error_page(403, 'Access denied', 'You do not have permission to view this page.');
