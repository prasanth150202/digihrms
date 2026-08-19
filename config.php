<?php
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

date_default_timezone_set('Asia/Kolkata');

// ── .env loader (runs before DB so credentials are available) ─────────────────
(function () {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (!array_key_exists($k, $_ENV)) { $_ENV[$k] = $v; putenv("$k=$v"); }
    }
})();

// ── Database connection ───────────────────────────────────────────────────────
$host     = $_ENV['DB_HOST'] ?? "193.203.184.87";
$db_name  = $_ENV['DB_NAME'] ?? "u218702675_hrms";
$username = $_ENV['DB_USER'] ?? "u218702675_hrms";
$password = $_ENV['DB_PASS'] ?? "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<div style='font-family:sans-serif;padding:40px;color:red;'>DB Connection Failed: " . $e->getMessage() . "</div>");
}

// ── Database-backed session handler ──────────────────────────────────────────
// Stores sessions in MySQL instead of /tmp — fixes the shared-hosting GC race
// where Hostinger's cron deletes session files before gc_maxlifetime expires.
define('HRMS_SESS_LIFETIME', 2592000); // 30 days

if (!class_exists('HrmsDbSession')) {
    class HrmsDbSession implements SessionHandlerInterface {
        public function __construct(private PDO $db, private int $ttl) {}
        public function open($path, $name): bool { return true; }
        public function close(): bool            { return true; }
        public function read($id): string|false {
            try {
                $s = $this->db->prepare("SELECT data FROM hrms_sessions WHERE id=? AND expires_at > NOW() LIMIT 1");
                $s->execute([$id]);
                return (string)($s->fetchColumn() ?: '');
            } catch (Exception $e) { return ''; }
        }
        public function write($id, $data): bool {
            try {
                $this->db->prepare(
                    "INSERT INTO hrms_sessions (id, data, expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL ? SECOND))
                     ON DUPLICATE KEY UPDATE data=VALUES(data), expires_at=VALUES(expires_at)"
                )->execute([$id, $data, $this->ttl]);
                return true;
            } catch (Exception $e) { return false; }
        }
        public function destroy($id): bool {
            try { $this->db->prepare("DELETE FROM hrms_sessions WHERE id=?")->execute([$id]); return true; }
            catch (Exception $e) { return false; }
        }
        public function gc($max_lifetime): int|false {
            try { $this->db->exec("DELETE FROM hrms_sessions WHERE expires_at < NOW()"); return true; }
            catch (Exception $e) { return false; }
        }
    }
}

// Auto-create sessions table, then register the handler
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS hrms_sessions (
        id         VARCHAR(128) NOT NULL PRIMARY KEY,
        data       MEDIUMTEXT   NOT NULL,
        expires_at DATETIME     NOT NULL,
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    session_set_save_handler(new HrmsDbSession($conn, HRMS_SESS_LIFETIME), true);
} catch (PDOException $e) {
    // DB unavailable — fall back to file sessions (may GC, but app stays functional)
}

// Apply cookie settings before session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime',  HRMS_SESS_LIFETIME);
// cookie_lifetime = 0 (session cookie) by default; login.php sets it to 30 days when "Remember me" is checked
session_start();

// ── DigiOps DB connection (for cross-system features: My Points, reverse task sync) ──
function digiops_db(): ?PDO {
    // On production (separate hosting), DigiOps DB is not reachable — use webhook only
    if (($_ENV['DIGIOPS_ENV'] ?? 'local') === 'production') return null;

    static $pdo = null;
    static $failed = false;
    if ($failed) return null;
    if ($pdo)   return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . ($_ENV['DIGIOPS_HOST'] ?? 'srv1408.hstgr.io') . ';dbname=' . ($_ENV['DIGIOPS_NAME'] ?? 'u218702675_operations') . ';charset=utf8mb4',
            $_ENV['DIGIOPS_USER'] ?? 'u218702675_operations',
            $_ENV['DIGIOPS_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_TIMEOUT => 5]
        );
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Exception $e) {
        $failed = true;
        return null;
    }
    return $pdo;
}

function getHrmsWebhookSecret(): string {
    $pdo = digiops_db();
    if (!$pdo) return '';
    try {
        $st = $pdo->prepare("SELECT value FROM settings WHERE skey = 'hrms_webhook_secret' LIMIT 1");
        $st->execute();
        $row = $st->fetch();
        return $row['value'] ?? '';
    } catch (Exception $e) { return ''; }
}

function require_login() {
    global $conn;
    if (!isset($_SESSION['user'])) {
        header("Location: login.php");
        exit;
    }
    // Re-sync role from DB every request so TL promotions take effect without re-login
    if ($conn && !empty($_SESSION['user']['id'])) {
        $s = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $s->execute([$_SESSION['user']['id']]);
        $fresh = $s->fetchColumn();
        if ($fresh && $fresh !== $_SESSION['user']['role']) {
            $_SESSION['user']['role'] = $fresh;
        }
    }
    // Fire login-based triggers once per day per user
    $uid = $_SESSION['user']['id'] ?? null;
    if ($uid && empty($_SESSION['trigger_login_checked_' . date('Y-m-d')])) {
        $_SESSION['trigger_login_checked_' . date('Y-m-d')] = true;
        try {
            $te = __DIR__ . '/trigger_engine.php';
            if (file_exists($te)) {
                require_once $te;
                fireTriggersByLogin($conn, (int)$uid);
            }
        } catch (Exception $e) {}
    }
}

function require_role(...$roles) {
    require_login();
    if (!in_array($_SESSION['user']['role'], $roles)) {
        http_response_code(403);
        include 'header.php';
        echo '<div class="alert alert-danger"><i class="bi bi-shield-x me-2"></i>Access Denied. You do not have permission to view this page.</div>';
        include 'footer.php';
        exit;
    }
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function has_role(...$roles) {
    return isset($_SESSION['user']) && in_array($_SESSION['user']['role'], $roles);
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf($token) {
    return !empty($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash() {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// Insert in-app notification + fire push (fire-and-forget)
function hrms_notify(PDO $conn, int $userId, string $type, string $title, string $body = '', string $link = ''): void {
    if (!$userId) return;
    $conn->prepare("INSERT INTO notifications (user_id,type,title,body,link) VALUES (?,?,?,?,?)")
         ->execute([$userId, $type, $title, $body, $link]);
    static $pushLoaded = false;
    if (!$pushLoaded) {
        $f = __DIR__ . '/push_helper.php';
        if (file_exists($f)) { require_once $f; $pushLoaded = true; }
    }
    if (function_exists('hrmsPushUser')) {
        hrmsPushUser($conn, $userId, $title, $body, $link);
    }
}

function badge($status) {
    $map = [
        'APPROVED'  => 'success',  'PENDING'   => 'warning',  'REJECTED'  => 'danger',
        'ON_HOLD'   => 'secondary','ACTIVE'    => 'success',  'EXITED'    => 'danger',
        'PROBATION' => 'warning',  'SCHEDULED' => 'info',     'COMPLETED' => 'success',
        'CANCELLED' => 'danger',   'APPLIED'   => 'primary',  'INTERVIEW' => 'info',
        'TODO'      => 'secondary','IN_PROGRESS'=> 'primary', 'REVIEW'    => 'warning',
        'DONE'      => 'success',  'REQUESTED' => 'info',     'URGENT'    => 'dark',
        'TEAM_LEAD' => 'info',
        'OFFERED'   => 'purple',   'HIRED'     => 'success',  'LOW'       => 'success',
        'MEDIUM'    => 'warning',  'HIGH'      => 'danger',
        'PRESENT'   => 'success',  'ABSENT'    => 'danger',   'HALF_DAY'  => 'warning',
        'LATE'      => 'info',     'WFH'       => 'primary',
    ];
    return $map[$status] ?? 'secondary';
}

function sanitize($val) {
    return htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8');
}

function extract_pdf_text(string $filepath): string {
    if (!class_exists('\Smalot\PdfParser\Parser')) return '';
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseFile($filepath);
        return $pdf->getText();
    } catch (\Exception $e) {
        return '';
    }
}
?>
