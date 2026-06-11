<?php
/**
 * HRMS Trigger Cron Endpoint
 * Call every minute via cron: * * * * * curl -s http://localhost/hrms/php_implementation/trigger_cron.php
 * Or via XAMPP task scheduler / Windows Task Scheduler
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/trigger_engine.php';

// Simple secret protection
$secret = $_GET['secret'] ?? '';
$stored = $conn->query("SELECT value FROM settings WHERE skey='trigger_cron_secret' LIMIT 1")->fetchColumn();
if ($stored && $secret !== $stored) {
    http_response_code(403); die('Forbidden');
}

header('Content-Type: application/json');
$results = fireDueTimeTriggers($conn);
echo json_encode(['ok'=>true,'fired'=>count($results),'results'=>$results,'time'=>date('Y-m-d H:i:s')]);
