<?php
/**
 * DigiHRMS AI Copilot (BETA) — shared helpers.
 *
 * Add to .env:
 *   OPENROUTER_API_KEY=sk-or-v1-........................
 *   OPENROUTER_MODEL=deepseek/deepseek-chat-v3-0324:free
 *   OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
 *   AI_AGENT_CONFIRM_WRITES=true     # show non-SELECT SQL for confirmation before running
 *
 * OPENROUTER_BASE_URL is swappable — point it at a LAN Ollama box
 * (http://192.168.x.x:11434/v1) with OPENROUTER_API_KEY=ollama to go fully local.
 */

require_once __DIR__ . '/config.php';

function aiagent_cfg(string $key, $default = null) {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

function aiagent_model(): string {
    return aiagent_cfg('OPENROUTER_MODEL', 'deepseek/deepseek-chat-v3-0324:free');
}

function aiagent_base_url(): string {
    return rtrim(aiagent_cfg('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/');
}

function aiagent_confirm_writes(): bool {
    return strtolower((string) aiagent_cfg('AI_AGENT_CONFIRM_WRITES', 'true')) !== 'false';
}

function aiagent_configured(): bool {
    return aiagent_cfg('OPENROUTER_API_KEY') !== null;
}

/** Is the current logged-in user a tech-team beta tester? */
function current_user_is_beta(): bool {
    global $conn;
    $u = current_user();
    if (!$u || empty($u['id']) || !$conn) return false;
    static $cache = [];
    if (isset($cache[$u['id']])) return $cache[$u['id']];
    try {
        $s = $conn->prepare("SELECT is_beta_tester FROM users WHERE id = ? LIMIT 1");
        $s->execute([$u['id']]);
        return $cache[$u['id']] = (bool) $s->fetchColumn();
    } catch (Exception $e) {
        return $cache[$u['id']] = false; // column not migrated yet
    }
}

/**
 * Call an OpenAI-compatible /chat/completions endpoint.
 * @return array  Decoded JSON response.
 * @throws RuntimeException on transport / API error.
 */
function aiagent_chat(array $messages, array $tools = []): array {
    $key = aiagent_cfg('OPENROUTER_API_KEY');
    if (!$key) throw new RuntimeException('OPENROUTER_API_KEY is not set in .env');

    $payload = [
        'model'       => aiagent_model(),
        'messages'    => $messages,
        'temperature' => 0.2,
    ];
    if ($tools) {
        $payload['tools']       = $tools;
        $payload['tool_choice'] = 'auto';
    }

    $url  = aiagent_base_url() . '/chat/completions';
    $post = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($post === false) {
        throw new RuntimeException('Could not encode request payload: ' . json_last_error_msg());
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'HTTP-Referer: ' . (aiagent_cfg('APP_URL', 'https://hrms.digifyce.com')),
            'X-Title: DigiHRMS Copilot',
        ],
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false)  throw new RuntimeException("LLM request failed ($url): $err");
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $body = trim(substr((string) $raw, 0, 300));
        throw new RuntimeException("LLM returned non-JSON (HTTP $code) from POST $url — model '" . aiagent_model() . "'. Body: " . ($body === '' ? '(empty)' : $body));
    }
    if ($code >= 400) {
        $m = $json['error']['message'] ?? $json['message'] ?? "HTTP $code";
        throw new RuntimeException("LLM API error (HTTP $code): $m");
    }
    return $json;
}

/** Insert a row into ai_agent_audit and return its id. */
function aiagent_audit(array $row): int {
    global $conn;
    $cols = ['user_id','conversation_id','tool','arguments','is_write','confirmed','status','rows_affected','result_preview','error','ip'];
    $data = [];
    foreach ($cols as $c) $data[$c] = $row[$c] ?? null;
    $data['ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
    $conn->prepare(
        "INSERT INTO ai_agent_audit (user_id,conversation_id,tool,arguments,is_write,confirmed,status,rows_affected,result_preview,error,ip)
         VALUES (:user_id,:conversation_id,:tool,:arguments,:is_write,:confirmed,:status,:rows_affected,:result_preview,:error,:ip)"
    )->execute($data);
    return (int) $conn->lastInsertId();
}

function aiagent_audit_update(int $id, array $fields): void {
    global $conn;
    if (!$id || !$fields) return;
    $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
    $fields['id'] = $id;
    $conn->prepare("UPDATE ai_agent_audit SET $set WHERE id = :id")->execute($fields);
}
