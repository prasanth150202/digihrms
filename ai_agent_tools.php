<?php
/**
 * DigiHRMS AI Copilot (BETA) — tool definitions & executors.
 *
 * The agent is intentionally all-powerful for the tech team: run_sql executes
 * arbitrary SQL against the live HRMS database. Every call is written to
 * ai_agent_audit. Non-SELECT statements are surfaced to the user for an explicit
 * Run/Skip confirmation (unless AI_AGENT_CONFIRM_WRITES=false, and always for
 * destructive statements).
 */

require_once __DIR__ . '/ai_agent_helper.php';

const AIAGENT_MAX_ROWS   = 150;
const AIAGENT_MAX_CHARS   = 14000;

/** JSON-schema tool list sent to the model. */
function aiagent_tool_specs(): array {
    return [
        [
            'type' => 'function',
            'function' => [
                'name' => 'list_tables',
                'description' => 'List every table in the HRMS database. Call this first when you need to know the schema.',
                'parameters' => ['type' => 'object', 'properties' => new stdClass()],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'describe_table',
                'description' => 'Show the columns, types, keys and a few sample rows for one table.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table' => ['type' => 'string', 'description' => 'Exact table name'],
                    ],
                    'required' => ['table'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'run_sql',
                'description' =>
                    'Execute a single SQL statement against the HRMS MySQL database. '
                    . 'SELECT/SHOW/DESCRIBE/EXPLAIN run immediately and return rows. '
                    . 'INSERT/UPDATE/DELETE/ALTER/etc. are shown to the user for confirmation before running. '
                    . 'Use standard MySQL syntax. One statement per call, no trailing semicolon needed.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql'    => ['type' => 'string', 'description' => 'The SQL statement'],
                        'reason' => ['type' => 'string', 'description' => 'Short human explanation of what this does and why'],
                    ],
                    'required' => ['sql'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'send_notification',
                'description' => 'Send an in-app + push notification to an HRMS user by their user id.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'title'   => ['type' => 'string'],
                        'body'    => ['type' => 'string'],
                        'link'    => ['type' => 'string', 'description' => 'Optional relative URL, e.g. leaves.php'],
                    ],
                    'required' => ['user_id', 'title'],
                ],
            ],
        ],
    ];
}

/* ── SQL classification ──────────────────────────────────────────────────── */

function aiagent_sql_is_readonly(string $sql): bool {
    $first = strtoupper(preg_split('/\s+/', ltrim($sql, " \t\n\r(")) [0] ?? '');
    return in_array($first, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH', 'VALUES'], true);
}

function aiagent_sql_is_dangerous(string $sql): bool {
    // DELETE / UPDATE with no WHERE clause = whole-table change
    if (preg_match('/\b(DELETE|UPDATE)\b/i', $sql) && !preg_match('/\bWHERE\b/i', $sql)) {
        return true;
    }
    $patterns = [
        '/\bDROP\s+(DATABASE|SCHEMA|TABLE)\b/i',
        '/\bTRUNCATE\b/i',
        '/\b(GRANT|REVOKE)\b/i',
        '/\b(CREATE|ALTER|DROP)\s+USER\b/i',
        '/\bSET\s+GLOBAL\b/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $sql)) return true;
    }
    return false;
}

/**
 * Does this tool call need user confirmation before it runs?
 */
function aiagent_needs_confirmation(string $tool, array $args): bool {
    if ($tool !== 'run_sql') return false;
    $sql = trim((string) ($args['sql'] ?? ''));
    if ($sql === '' || aiagent_sql_is_readonly($sql)) return false;
    if (aiagent_sql_is_dangerous($sql)) return true;
    return aiagent_confirm_writes();
}

/* ── Executor ────────────────────────────────────────────────────────────── */

/**
 * Execute one tool call. Returns a string (JSON) to feed back to the model.
 * $ctx = ['user_id'=>int, 'conversation_id'=>int]
 */
function aiagent_execute_tool(string $tool, array $args, array $ctx, bool $confirmed): string {
    global $conn;

    $isWrite = ($tool === 'run_sql')
        && !aiagent_sql_is_readonly(trim((string) ($args['sql'] ?? '')));

    $auditId = aiagent_audit([
        'user_id'         => $ctx['user_id'],
        'conversation_id' => $ctx['conversation_id'],
        'tool'            => $tool,
        'arguments'       => json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'is_write'        => $isWrite ? 1 : 0,
        'confirmed'       => $confirmed ? 1 : 0,
        'status'          => 'pending',
    ]);

    try {
        $result = match ($tool) {
            'list_tables'       => aiagent_tool_list_tables(),
            'describe_table'    => aiagent_tool_describe_table((string) ($args['table'] ?? '')),
            'run_sql'           => aiagent_tool_run_sql((string) ($args['sql'] ?? ''), $confirmed),
            'send_notification' => aiagent_tool_send_notification($args, $ctx),
            default             => ['error' => "Unknown tool: $tool"],
        };

        $preview = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        aiagent_audit_update($auditId, [
            'status'         => isset($result['error']) ? 'error' : 'ok',
            'rows_affected'  => $result['rows_affected'] ?? $result['row_count'] ?? null,
            'result_preview' => mb_substr($preview, 0, 4000),
            'error'          => $result['error'] ?? null,
        ]);
        return aiagent_clip($preview);

    } catch (Throwable $e) {
        aiagent_audit_update($auditId, ['status' => 'error', 'error' => $e->getMessage()]);
        return json_encode(['error' => $e->getMessage()]);
    }
}

function aiagent_clip(string $s): string {
    return strlen($s) > AIAGENT_MAX_CHARS
        ? mb_substr($s, 0, AIAGENT_MAX_CHARS) . ' …[truncated]'
        : $s;
}

function aiagent_tool_list_tables(): array {
    global $conn;
    $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    return ['tables' => $tables, 'count' => count($tables)];
}

function aiagent_tool_describe_table(string $table): array {
    global $conn;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return ['error' => 'Invalid table name'];
    }
    $exists = $conn->query("SHOW TABLES LIKE " . $conn->quote($table))->fetch();
    if (!$exists) return ['error' => "Table '$table' does not exist"];

    $cols = $conn->query("SHOW FULL COLUMNS FROM `$table`")->fetchAll();
    $sample = $conn->query("SELECT * FROM `$table` LIMIT 3")->fetchAll();
    $count  = (int) $conn->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    return ['table' => $table, 'row_count' => $count, 'columns' => $cols, 'sample_rows' => $sample];
}

function aiagent_tool_run_sql(string $sql, bool $confirmed): array {
    global $conn;
    $sql = trim(rtrim(trim($sql), ';'));
    if ($sql === '') return ['error' => 'Empty SQL'];

    $readonly = aiagent_sql_is_readonly($sql);

    if (!$readonly && !$confirmed) {
        // Loop should have caught this — belt & braces.
        return ['error' => 'Write statement was not confirmed by the user; it was not executed.'];
    }

    if ($readonly) {
        $stmt = $conn->query($sql);
        $rows = $stmt->fetchAll();
        $out  = ['row_count' => count($rows)];
        if (count($rows) > AIAGENT_MAX_ROWS) {
            $out['rows'] = array_slice($rows, 0, AIAGENT_MAX_ROWS);
            $out['note'] = 'Result truncated to first ' . AIAGENT_MAX_ROWS . ' rows.';
        } else {
            $out['rows'] = $rows;
        }
        return $out;
    }

    $affected = $conn->exec($sql);
    return [
        'ok'            => true,
        'rows_affected' => $affected,
        'last_insert_id'=> $conn->lastInsertId() ?: null,
    ];
}

function aiagent_tool_send_notification(array $args, array $ctx): array {
    global $conn;
    $uid = (int) ($args['user_id'] ?? 0);
    if (!$uid) return ['error' => 'user_id required'];
    $ok = $conn->query("SELECT id FROM users WHERE id = " . $uid)->fetch();
    if (!$ok) return ['error' => "No user with id $uid"];
    hrms_notify(
        $conn, $uid, 'ai_copilot',
        (string) ($args['title'] ?? 'Notification'),
        (string) ($args['body'] ?? ''),
        (string) ($args['link'] ?? '')
    );
    return ['ok' => true, 'sent_to' => $uid];
}

/* ── System prompt ──────────────────────────────────────────────────────── */

function aiagent_system_prompt(array $u): string {
    $today = date('Y-m-d H:i');
    return <<<SYS
You are the DigiHRMS Copilot, an internal engineering assistant for the Digifyce tech team. You are in BETA.
Current user: {$u['name']} (id {$u['id']}, role {$u['role']}). Server time: {$today} (Asia/Kolkata).

You operate directly on the live DigiHRMS MySQL database through tools:
- list_tables / describe_table — inspect the schema. Do this before writing SQL for unfamiliar tables.
- run_sql — run ONE MySQL statement. Reads return rows immediately. Writes (INSERT/UPDATE/DELETE/ALTER/…)
  are shown to the user for a Run/Skip confirmation before they execute — so explain clearly in the "reason"
  field what the statement does and what it will affect.
- send_notification — notify an HRMS user.

Rules:
- Prefer a single well-scoped SELECT to understand data before changing it.
- For any UPDATE/DELETE, always include a WHERE clause and state the expected row count.
- Never invent column names — verify with describe_table.
- Show the user the actual numbers/rows you found; don't just say "done".
- If a request is ambiguous or destructive, ask a clarifying question instead of guessing.
- Keep answers concise. Use markdown tables for row output.
SYS;
}
