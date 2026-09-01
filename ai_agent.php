<?php
/**
 * DigiHRMS AI Copilot (BETA) — chat + agent-loop endpoint.
 *
 *   GET  ?action=conversations                     → list current user's conversations
 *   GET  ?action=messages&conversation_id=N        → full transcript (display roles only)
 *   POST {message, conversation_id?}               → send a message, run the agent
 *   POST {resume:true, conversation_id, approvals} → resume after write confirmation
 *
 * Access: logged-in users with users.is_beta_tester = 1 only.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai_agent_tools.php';

header('Content-Type: application/json');

function jout($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_login();
$U = current_user();
if (!current_user_is_beta()) {
    jout(['error' => 'The AI Copilot is in closed beta for the tech team.'], 403);
}

$conn->exec("SET SESSION wait_timeout = 180");

/* ── GET: read-only listing ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'conversations') {
        $s = $conn->prepare(
            "SELECT id, title, updated_at FROM ai_agent_conversations
             WHERE user_id = ? ORDER BY updated_at DESC LIMIT 30"
        );
        $s->execute([$U['id']]);
        jout(['conversations' => $s->fetchAll()]);
    }

    if ($action === 'messages') {
        $cid = (int) ($_GET['conversation_id'] ?? 0);
        aiagent_assert_owner($conn, $cid, $U['id']);
        $s = $conn->prepare(
            "SELECT role, content, tool_calls, name, created_at FROM ai_agent_messages
             WHERE conversation_id = ? AND role IN ('user','assistant','tool') ORDER BY id ASC"
        );
        $s->execute([$cid]);
        jout(['messages' => $s->fetchAll()]);
    }

    jout(['error' => 'Unknown action'], 400);
}

/* ── POST ───────────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jout(['error' => 'Method not allowed'], 405);

if (!aiagent_configured()) {
    jout(['error' => 'AI Copilot is not configured yet — add OPENROUTER_API_KEY to .env.'], 503);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

$csrf = $body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verify_csrf($csrf)) jout(['error' => 'Invalid CSRF token — reload the page.'], 419);

$isResume = !empty($body['resume']);
$cid      = (int) ($body['conversation_id'] ?? 0);

try {
    if ($isResume) {
        aiagent_assert_owner($conn, $cid, $U['id']);
        $approvals = [];
        foreach ((array) ($body['approvals'] ?? []) as $k => $v) {
            $approvals[(string) $k] = (bool) $v;
        }
        $result = aiagent_run_loop($conn, $cid, $U, $approvals);
        jout($result);
    }

    $message = trim((string) ($body['message'] ?? ''));
    if ($message === '') jout(['error' => 'Empty message'], 400);
    if (mb_strlen($message) > 8000) jout(['error' => 'Message too long'], 400);

    if ($cid) {
        aiagent_assert_owner($conn, $cid, $U['id']);
    } else {
        $conn->prepare("INSERT INTO ai_agent_conversations (user_id, title) VALUES (?, ?)")
             ->execute([$U['id'], mb_substr($message, 0, 60)]);
        $cid = (int) $conn->lastInsertId();
        aiagent_add_message($conn, $cid, 'system', aiagent_system_prompt($U));
    }

    aiagent_add_message($conn, $cid, 'user', $message);
    $conn->prepare("UPDATE ai_agent_conversations SET updated_at = NOW() WHERE id = ?")->execute([$cid]);

    $result = aiagent_run_loop($conn, $cid, $U, []);
    jout($result);

} catch (Throwable $e) {
    jout(['error' => $e->getMessage(), 'conversation_id' => $cid], 500);
}

/* ── Agent loop ─────────────────────────────────────────────────────────── */

function aiagent_run_loop(PDO $conn, int $cid, array $U, array $approvals): array {
    $maxSteps = 12;

    for ($step = 0; $step < $maxSteps; $step++) {
        $rows = aiagent_fetch_rows($conn, $cid);
        $last = end($rows);

        // 1. Are there unprocessed tool calls on the last assistant message?
        $pending = aiagent_unprocessed_tool_calls($rows);
        if ($pending) {
            $needConfirm = [];
            $doneSigs = aiagent_executed_signatures($rows);
            foreach ($pending as $tc) {
                $name = $tc['function']['name'] ?? '';
                $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                $tcId = $tc['id'];
                $sig  = $name . '|' . json_encode($args);

                // Reject out-of-scope / DDL BEFORE it can reach a confirmation card.
                $pre = aiagent_precheck_tool($conn, $name, $args);
                if ($pre !== null) {
                    aiagent_audit([
                        'user_id' => $U['id'], 'conversation_id' => $cid, 'tool' => $name,
                        'arguments' => json_encode($args), 'status' => 'blocked', 'error' => $pre,
                    ]);
                    aiagent_add_message($conn, $cid, 'tool',
                        json_encode(['error' => $pre, 'blocked' => true]), null, $tcId, $name);
                    continue;
                }

                // Don't repeat an identical call already made in this conversation.
                if (isset($doneSigs[$sig]) && $name !== 'run_sql') {
                    aiagent_add_message($conn, $cid, 'tool',
                        json_encode(['note' => 'Identical call already made earlier — not repeated.']),
                        null, $tcId, $name);
                    continue;
                }

                if (aiagent_needs_confirmation($name, $args)) {
                    if (!array_key_exists($tcId, $approvals)) {
                        $needConfirm[] = array_merge(
                            ['tool_call_id' => $tcId, 'tool' => $name],
                            aiagent_confirm_card($name, $args)
                        );
                        continue;
                    }
                    if ($approvals[$tcId] === false) {
                        aiagent_add_message($conn, $cid, 'tool',
                            json_encode(['skipped' => true, 'note' => 'User declined to run this statement.']),
                            null, $tcId, $name);
                        continue;
                    }
                    $confirmed = true;
                } else {
                    $confirmed = $approvals[$tcId] ?? false;
                }

                $out = aiagent_execute_tool($name, $args, [
                    'user_id' => $U['id'], 'conversation_id' => $cid,
                ], $confirmed);
                aiagent_add_message($conn, $cid, 'tool', $out, null, $tcId, $name);
            }

            if ($needConfirm) {
                return ['status' => 'confirm', 'conversation_id' => $cid, 'pending' => $needConfirm];
            }
            $approvals = []; // consumed
            continue;
        }

        // 2. Otherwise, ask the model for the next step.
        $resp  = aiagent_chat(aiagent_openai_messages($rows), aiagent_tool_specs());
        $text  = aiagent_message_text($resp['choices'][0]['message'] ?? []);
        $calls = $resp['choices'][0]['message']['tool_calls'] ?? [];

        if ($calls) {
            aiagent_add_message($conn, $cid, 'assistant', $text, json_encode($calls));
            continue;
        }

        // No tool calls -> this is the final turn.
        if ($text === '') {
            // Model stopped without writing an answer -> force one plain-text synthesis pass.
            $msgs = aiagent_openai_messages($rows);
            $msgs[] = ['role' => 'user', 'content' =>
                'Now answer my question in plain English using what you found above. '
                . 'Short markdown table if useful. Do not call any tools.'];
            try {
                $text = aiagent_message_text(aiagent_chat($msgs)['choices'][0]['message'] ?? []);
            } catch (Throwable $e) { /* fall through */ }
        }
        $reply = trim($text) !== '' ? trim($text)
            : "_(I ran the lookup but didn't get a written summary back — the data is in the steps above. Try asking again.)_";
        aiagent_add_message($conn, $cid, 'assistant', $reply, null);
        $conn->prepare("UPDATE ai_agent_conversations SET updated_at = NOW() WHERE id = ?")->execute([$cid]);
        return ['status' => 'done', 'conversation_id' => $cid, 'reply' => $reply];
    }

    // Hit the step limit — make the model summarise what it has, no more tools.
    $reply = '';
    try {
        $msgs = aiagent_openai_messages(aiagent_fetch_rows($conn, $cid));
        $msgs[] = ['role' => 'user', 'content' =>
            'Stop here and answer my original question in plain English with what you have so far. Do not call tools.'];
        $reply = aiagent_message_text(aiagent_chat($msgs)['choices'][0]['message'] ?? []);
    } catch (Throwable $e) { /* fall through */ }
    if ($reply === '') {
        $reply = "_(This took too many steps — check the steps above, or try a more specific question.)_";
    }
    aiagent_add_message($conn, $cid, 'assistant', $reply, null);
    $conn->prepare("UPDATE ai_agent_conversations SET updated_at = NOW() WHERE id = ?")->execute([$cid]);
    return ['status' => 'done', 'conversation_id' => $cid, 'reply' => $reply];
}

/* ── Helpers ────────────────────────────────────────────────────────────── */

function aiagent_assert_owner(PDO $conn, int $cid, int $uid): void {
    $s = $conn->prepare("SELECT user_id FROM ai_agent_conversations WHERE id = ?");
    $s->execute([$cid]);
    $owner = $s->fetchColumn();
    if (!$owner || (int) $owner !== (int) $uid) jout(['error' => 'Conversation not found'], 404);
}

function aiagent_add_message(PDO $conn, int $cid, string $role, ?string $content,
                             ?string $toolCalls = null, ?string $toolCallId = null, ?string $name = null): void {
    $conn->prepare(
        "INSERT INTO ai_agent_messages (conversation_id, role, content, tool_calls, tool_call_id, name)
         VALUES (?,?,?,?,?,?)"
    )->execute([$cid, $role, $content, $toolCalls, $toolCallId, $name]);
}

/** Raw rows, oldest first, capped to the most recent slice for context budget. */
function aiagent_fetch_rows(PDO $conn, int $cid): array {
    $s = $conn->prepare("SELECT * FROM ai_agent_messages WHERE conversation_id = ? ORDER BY id ASC");
    $s->execute([$cid]);
    $all = $s->fetchAll();
    if (count($all) <= 60) return $all;
    // keep the system message + a recent slice, but never start the slice on an
    // orphan 'tool' row (must be preceded by its assistant/tool_calls message)
    $system = array_slice($all, 0, 1);
    $tail   = array_slice($all, -58);
    while ($tail && $tail[0]['role'] === 'tool') array_shift($tail);
    return array_merge($system, $tail);
}

/** Tool calls on the trailing assistant message that have no matching tool row yet. */
function aiagent_unprocessed_tool_calls(array $rows): array {
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        $r = $rows[$i];
        if ($r['role'] === 'tool') continue;
        if ($r['role'] !== 'assistant' || empty($r['tool_calls'])) return [];
        $calls = json_decode($r['tool_calls'], true) ?: [];
        $doneIds = [];
        for ($j = $i + 1; $j < count($rows); $j++) {
            if ($rows[$j]['role'] === 'tool' && $rows[$j]['tool_call_id']) {
                $doneIds[$rows[$j]['tool_call_id']] = true;
            }
        }
        return array_values(array_filter($calls, fn($c) => empty($doneIds[$c['id'] ?? ''])));
    }
    return [];
}

/** Extract plain text from an assistant message, tolerating array-shaped content. */
function aiagent_message_text(array $msg): string {
    $c = $msg['content'] ?? '';
    if (is_array($c)) {
        $c = implode('', array_map(
            fn($p) => is_array($p) ? ($p['text'] ?? '') : (string) $p, $c));
    }
    return trim((string) $c);
}

/** Signatures ("name|argsJson") of tool calls that already ran in this conversation. */
function aiagent_executed_signatures(array $rows): array {
    $done = [];
    $responded = [];
    foreach ($rows as $r) {
        if ($r['role'] === 'tool' && $r['tool_call_id']) $responded[$r['tool_call_id']] = true;
    }
    foreach ($rows as $r) {
        if ($r['role'] !== 'assistant' || empty($r['tool_calls'])) continue;
        foreach (json_decode($r['tool_calls'], true) ?: [] as $tc) {
            if (empty($responded[$tc['id'] ?? ''])) continue;
            $name = $tc['function']['name'] ?? '';
            $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
            $done[$name . '|' . json_encode($args)] = true;
        }
    }
    return $done;
}

/** Convert stored rows to OpenAI chat-completions message format. */
function aiagent_openai_messages(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        switch ($r['role']) {
            case 'system':
            case 'user':
                $out[] = ['role' => $r['role'], 'content' => (string) $r['content']];
                break;
            case 'assistant':
                $m = ['role' => 'assistant'];
                $calls = $r['tool_calls'] ? json_decode($r['tool_calls'], true) : null;
                $m['content'] = ($r['content'] === '' && $calls) ? null : (string) $r['content'];
                if ($calls) $m['tool_calls'] = $calls;
                $out[] = $m;
                break;
            case 'tool':
                $out[] = [
                    'role'         => 'tool',
                    'tool_call_id' => (string) $r['tool_call_id'],
                    'content'      => (string) $r['content'],
                ];
                break;
        }
    }
    return $out;
}
