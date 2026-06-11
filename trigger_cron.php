<?php
/**
 * HRMS Trigger Cron Endpoint
 * Call every minute via cron: * * * * * curl -s https://hrms.digifyce.com/trigger_cron.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/trigger_engine.php';

header('Content-Type: application/json');

// Simple secret protection via query param ?secret=xxx
// Secret stored in hrms_settings if the table exists
$secret = $_GET['secret'] ?? '';
try {
    $stored = $conn->query("SELECT value FROM hrms_settings WHERE skey='trigger_cron_secret' LIMIT 1")->fetchColumn();
    if ($stored && $secret !== $stored) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Forbidden']);
        exit;
    }
} catch (Exception $e) {
    // hrms_settings missing — allow through (no secret configured)
}

try {
    $results = fireDueTimeTriggers($conn);
    echo json_encode(['ok'=>true,'fired'=>count($results),'results'=>$results,'time'=>date('Y-m-d H:i:s')]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'time'=>date('Y-m-d H:i:s')]);
}
