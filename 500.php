<?php
// Generic server-error page, used by Apache's ErrorDocument 500. PHP failures
// are answered by includes/error-handler.php with a reference ID instead.
require_once __DIR__ . '/config/config.php';
lume_error_page(500, 'Something went wrong', 'We could not complete your request. Please try again in a moment.');
