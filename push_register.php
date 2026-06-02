<?php
require_once 'config.php';
require_login();
header('Content-Type: application/json');

$u     = current_user();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$token = trim($input['token'] ?? '');
$act   = $input['action'] ?? 'register';

if (!$token) { echo json_encode(['ok'=>false,'error'=>'Missing token']); exit; }

if ($act === 'unregister') {
    $conn->prepare("DELETE FROM push_subscriptions WHERE user_id=? AND fcm_token=?")
         ->execute([$u['id'], $token]);
} else {
    $conn->prepare("INSERT INTO push_subscriptions (user_id,fcm_token) VALUES (?,?)
                    ON DUPLICATE KEY UPDATE updated_at=NOW()")
         ->execute([$u['id'], $token]);
}
echo json_encode(['ok' => true]);
