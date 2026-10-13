<?php
require_once __DIR__ . '/../includes/functions.php';
lume_session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success'=>false,'message'=>'Method not allowed'],405);
if (!csrf_verify($_POST['csrf'] ?? '')) json_response(['success'=>false,'message'=>'Invalid request'],403);

$name    = trim($_POST['name'] ?? '');
$email   = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if (!$name || !$email || !$message) json_response(['success'=>false,'message'=>'Please fill in all required fields.']);
if (mb_strlen($name) > 150 || mb_strlen($subject) > 200 || mb_strlen($message) > 5000) {
    json_response(['success'=>false,'message'=>'Your message is too long.']);
}
// Honeypot: a hidden "website" field only bots fill in. Pretend success.
if (!empty($_POST['website'])) json_response(['success'=>true,'message'=>'Message sent successfully! We\x27ll get back to you soon.']);
// Basic flood protection: 5 messages per IP per hour.
$rateKey = 'contact:' . get_client_ip();
if (is_rate_limited($rateKey, 5, 3600)) json_response(['success'=>false,'message'=>'Too many messages. Please try again later.'], 429);
record_login_attempt($rateKey, false);

try {
    db()->prepare('INSERT INTO contact_messages (name, email, subject, message, ip) VALUES (?,?,?,?,?)')
        ->execute([$name, $email, $subject, $message, get_client_ip()]);
    json_response(['success'=>true,'message'=>'Message sent successfully! We\'ll get back to you soon.']);
} catch (Exception $e) {
    json_response(['success'=>false,'message'=>'Something went wrong. Please try again.']);
}
