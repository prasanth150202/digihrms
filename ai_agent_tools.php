<?php
/**
 * DigiHRMS AI Copilot (BETA) — tool definitions & executors.
 *
 * The agent is scoped to task & workflow tables only (see aiagent_write_tables /
 * aiagent_read_tables in ai_agent_helper.php — both overridable via .env). Any SQL
 * touching another table, or any DDL, is blocked before it runs. Every call is
 * written to ai_agent_audit. Every INSERT/UPDATE/DELETE is surfaced to the user
 * for an explicit Run/Skip confirmation — no exceptions.
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
                    'Execute a single SQL statement — ONLY against task & workflow tables (call list_tables to see which). '
                    . 'SELECT/SHOW/DESCRIBE/EXPLAIN run immediately and return rows. '
                    . 'INSERT/UPDATE/DELETE are shown to the user for an explicit Run/Skip confirmation before running. '
                    . 'Schema changes (ALTER/DROP/CREATE/TRUNCATE) and any other table are blocked. '
                    . 'Standard MySQL syntax, one statement per call, no trailing semicolon.',
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
 * Every write needs the user's explicit Run/Skip confirmation — no exceptions.
 */
function aiagent_needs_confirmation(string $tool, array $args): bool {
    if ($tool !== 'run_sql') return false;
    $sql = trim((string) ($args['sql'] ?? ''));
    return $sql !== '' && !aiagent_sql_is_readonly($sql);
}

/**
 * Scope guard: the Copilot may only touch task & workflow tables.
 * Returns an error string to hand back to the model, or null if the SQL is in scope.
 */
function aiagent_scope_check(PDO $conn, string $sql, bool $readonly): ?string {
    // 1. No schema / privileged / file / multi-statement operations, ever.
    if (preg_match('/\b(DROP|ALTER|CREATE|TRUNCATE|RENAME|GRANT|REVOKE|FLUSH|LOCK|UNLOCK|CALL|PREPARE|EXECUTE|SET\s+GLOBAL|LOAD\s+DATA|INTO\s+(OUT|DUMP)FILE)\b/i', $sql)) {
        return 'Blocked: the Copilot can only read and modify rows in task & workflow tables. '
             . 'Schema changes, privileged statements and file operations are not allowed.';
    }
    if (str_contains(rtrim($sql, "; \t\n\r"), ';')) {
        return 'Blocked: only one SQL statement per call.';
    }

    $writeTables = aiagent_write_tables();
    $allowed = $readonly
        ? array_unique(array_merge($writeTables, aiagent_read_tables()))
        : $writeTables;

    // 2. Compare against REAL table names, so aliases/spacing can't smuggle a table past us.
    static $realTables = null;
    if ($realTables === null) {
        $realTables = array_map('strtolower', $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
    }
    $lc = strtolower($sql);
    $outOfScope = [];
    foreach ($realTables as $t) {
        if (in_array($t, $allowed, true)) continue;
        if (preg_match('/(?<![a-z0-9_])' . preg_quote($t, '/') . '(?![a-z0-9_])/', $lc)) {
            $outOfScope[] = $t;
        }
    }
    if ($outOfScope) {
        return 'Out of scope: references ' . implode(', ', array_unique($outOfScope))
             . '. The Copilot may ' . ($readonly ? 'read' : 'write') . ' only: ' . implode(', ', $allowed);
    }

    // 3. A write must actually target one of the allowed tables.
    if (!$readonly) {
        $hitsAllowed = false;
        foreach ($writeTables as $t) {
            if (preg_match('/\b(INTO|UPDATE|TABLE|FROM)\s+`?' . preg_quote($t, '/') . '`?(?![a-z0-9_])/i', $sql)) {
                $hitsAllowed = true; break;
            }
        }
        if (!$hitsAllowed) {
            return 'Blocked: could not confirm this write targets an allowed task/workflow table.';
        }
    }
    return null;
}

/**
 * Fast pre-flight run in the agent loop BEFORE any confirmation card is shown.
 * Returns an error string for out-of-scope / DDL calls, or null if the call may proceed.
 */
function aiagent_precheck_tool(PDO $conn, string $tool, array $args): ?string {
    if ($tool === 'run_sql') {
        $sql = trim(rtrim(trim((string) ($args['sql'] ?? '')), ';'));
        if ($sql === '') return null;
        return aiagent_scope_check($conn, $sql, aiagent_sql_is_readonly($sql));
    }
    if ($tool === 'describe_table') {
        $scope = array_unique(array_merge(aiagent_write_tables(), aiagent_read_tables()));
        $t = strtolower((string) ($args['table'] ?? ''));
        if ($t !== '' && !in_array($t, $scope, true)) {
            return "'$t' is outside the Copilot's scope (task & workflow tables only).";
        }
    }
    return null;
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
            'status'         => !empty($result['blocked']) ? 'blocked' : (isset($result['error']) ? 'error' : 'ok'),
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
    $real  = array_map('strtolower', $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
    $scope = array_unique(array_merge(aiagent_write_tables(), aiagent_read_tables()));
    $inScope = array_values(array_intersect($real, $scope));
    sort($inScope);
    return [
        'tables' => $inScope,
        'count'  => count($inScope),
        'note'   => 'Only task & workflow tables are visible to the Copilot; other HRMS tables cannot be accessed.',
    ];
}

function aiagent_tool_describe_table(string $table): array {
    global $conn;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return ['error' => 'Invalid table name'];
    }
    $scope = array_unique(array_merge(aiagent_write_tables(), aiagent_read_tables()));
    if (!in_array(strtolower($table), $scope, true)) {
        return ['error' => "'$table' is outside the Copilot's scope (task & workflow tables only).", 'blocked' => true];
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

    $scopeErr = aiagent_scope_check($conn, $sql, $readonly);
    if ($scopeErr !== null) {
        return ['error' => $scopeErr, 'blocked' => true];
    }

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
You are the DigiHRMS Copilot, an internal assistant for the Digifyce tech team. You are in BETA.
Current user: {$u['name']} (id {$u['id']}, role {$u['role']}). Server time: {$today} (Asia/Kolkata).

SCOPE — you can ONLY work with tasks, projects, workflows and triggers. The database enforces this:
any SQL touching other tables (employees, salary, leaves, candidates, payroll, users…) is rejected.
Do not try to work around it or promise things outside this scope.

Tools:
- list_tables / describe_table — see the tables and columns you're allowed to use. Check before writing SQL.
- run_sql — ONE MySQL statement against task/workflow tables. Reads run immediately; INSERT/UPDATE/DELETE
  are shown to the user for a Run/Skip confirmation. Put a clear explanation in "reason".
- send_notification — notify an HRMS user by id.

ACTING vs CHATTING — decide this first, every message:
- Do ONLY what the user explicitly asked for in their latest message.
- "hi", "ok", "thanks", greetings, small talk, or a vague/unclear message → reply in ONE short sentence
  and call NO tools. Do not inspect schema, do not run queries, do not prepare anything.
- Never carry out something mentioned earlier in the chat unless the user asks again now.
- Unclear request → ask ONE clarifying question, call no tools until they answer.

When you DO have a real task:
- Default to reading. Investigate with SELECTs; only propose a write when the user clearly wants a change.
- Never chain writes — propose ONE at a time and wait.
- Every UPDATE/DELETE needs a WHERE clause. First SELECT the affected rows, tell the user how many rows
  and their current values, THEN propose the write.
- Never invent column names — verify with describe_table.
- Report real numbers/rows, not just "done". Markdown tables. Be concise.
SYS;
}
