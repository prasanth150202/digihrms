<?php
// Migration: DigiHRMS AI Copilot (beta) — tech-team only agent
// Run once: https://hrms.digifyce.com/migrate_ai_agent.php
//
// ⚠️  Before you rely on this agent, make sure you have a scheduled mysqldump.
//     The agent can run arbitrary SQL (see ai_agent_tools.php).

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');
$log = [];

/* ── 1. users.is_beta_tester flag ─────────────────────────────────────────── */
try {
    $has = $conn->query("SHOW COLUMNS FROM users LIKE 'is_beta_tester'")->fetch();
    if ($has) {
        $log[] = "= users.is_beta_tester already exists";
    } else {
        $conn->exec("ALTER TABLE users ADD COLUMN is_beta_tester TINYINT(1) NOT NULL DEFAULT 0");
        $log[] = "+ users.is_beta_tester added";
    }
} catch (Exception $e) {
    $log[] = "! users.is_beta_tester: " . $e->getMessage();
}

/* ── 2. Conversations ────────────────────────────────────────────────────── */
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS ai_agent_conversations (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            title      VARCHAR(200) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = "+ ai_agent_conversations ready";
} catch (Exception $e) {
    $log[] = "! ai_agent_conversations: " . $e->getMessage();
}

/* ── 3. Messages ────────────────────────────────────────────────────────── */
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS ai_agent_messages (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            role            ENUM('system','user','assistant','tool') NOT NULL,
            content         MEDIUMTEXT,
            tool_calls      MEDIUMTEXT,      -- JSON: assistant tool_calls
            tool_call_id    VARCHAR(80) DEFAULT NULL,
            name            VARCHAR(80) DEFAULT NULL,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conv (conversation_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = "+ ai_agent_messages ready";
} catch (Exception $e) {
    $log[] = "! ai_agent_messages: " . $e->getMessage();
}

/* ── 4. Audit log (every tool call) ─────────────────────────────────────── */
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS ai_agent_audit (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            user_id         INT NOT NULL,
            conversation_id INT DEFAULT NULL,
            tool            VARCHAR(40) NOT NULL,
            arguments       MEDIUMTEXT,
            is_write        TINYINT(1) NOT NULL DEFAULT 0,
            confirmed       TINYINT(1) NOT NULL DEFAULT 0,
            status          ENUM('ok','error','blocked','pending') NOT NULL DEFAULT 'ok',
            rows_affected   INT DEFAULT NULL,
            result_preview  MEDIUMTEXT,
            error           TEXT DEFAULT NULL,
            ip              VARCHAR(45) DEFAULT NULL,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id, created_at),
            INDEX idx_conv (conversation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = "+ ai_agent_audit ready";
} catch (Exception $e) {
    $log[] = "! ai_agent_audit: " . $e->getMessage();
}

echo implode("\n", $log) . "\n\nDone.\n";
echo "\nNext steps:\n";
echo "  1. Add OPENROUTER_API_KEY to .env (see ai_agent_helper.php header).\n";
echo "  2. In Users, tick 'AI Copilot beta' for each tech-team account.\n";
echo "  3. Reload any HRMS page — the copilot bubble appears bottom-right.\n";
