<?php
require_once __DIR__ . '/../includes/functions.php';
lume_session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success'=>false,'message'=>'Method not allowed'],405);
if (!csrf_verify($_POST['csrf'] ?? '')) json_response(['success'=>false,'message'=>'Invalid request'],403);

$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
if (!$email || strlen($email) > 190) json_response(['success'=>false,'message'=>'Please enter a valid email address.']);
$rateKey = 'newsletter:' . get_client_ip();
if (is_rate_limited($rateKey, 10, 3600)) json_response(['success'=>false,'message'=>'Too many attempts. Please try again later.'], 429);
record_login_attempt($rateKey, false);

try {
    db()->prepare('INSERT INTO newsletter_subscribers (email) VALUES (?) ON DUPLICATE KEY UPDATE status = "active"')
        ->execute([$email]);
    json_response(['success'=>true,'message'=>'Welcome to the Inner Circle! ✦']);
} catch (Exception $e) {
    json_response(['success'=>false,'message'=>'Something went wrong. Please try again.']);
}
