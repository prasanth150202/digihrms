<?php
// Digifyce REST API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'config.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
function api_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function api_ok($data, $meta = []) {
    echo json_encode(array_merge(['success' => true], $meta, is_array($data) && isset($data[0]) ? ['count' => count($data), 'data' => $data] : ['data' => $data]));
    exit;
}

// Get API key from header
$headers   = getallheaders();
$api_key   = $headers['X-Api-Key'] ?? $headers['X-API-Key'] ?? '';
if (!$api_key) {
    // Try Authorization: Bearer {key}
    $auth = $headers['Authorization'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) $api_key = substr($auth, 7);
}
if (!$api_key) api_error('API key required. Pass X-API-Key header.', 401);

$key_row = $conn->prepare("SELECT k.*, u.role FROM api_keys k JOIN users u ON k.user_id = u.id WHERE k.api_key = ? AND k.is_active = 1");
$key_row->execute([$api_key]);
$auth_user = $key_row->fetch();
if (!$auth_user) api_error('Invalid or inactive API key.', 401);

// Update last used
$conn->prepare("UPDATE api_keys SET last_used = NOW() WHERE api_key = ?")->execute([$api_key]);

$method   = $_SERVER['REQUEST_METHOD'];
$endpoint = strtolower(trim($_GET['endpoint'] ?? ''));
$id       = isset($_GET['id']) ? (int)$_GET['id'] : null;
$body     = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Helpers ───────────────────────────────────────────────────────────────────
function paginate($conn, $sql, $params = []) {
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $offset   = ($page - 1) * $per_page;

    $count_sql = "SELECT COUNT(*) FROM (" . $sql . ") t";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $stmt = $conn->prepare("$sql LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    return ['data' => $data, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per_page, 'pages' => (int)ceil($total / $per_page)]];
}

// ── Routes ────────────────────────────────────────────────────────────────────

// GET /api.php?endpoint=stats
if ($endpoint === 'stats') {
    api_ok([
        'active_employees'      => (int)$conn->query("SELECT COUNT(*) FROM employees WHERE status='ACTIVE'")->fetchColumn(),
        'open_requests'         => (int)$conn->query("SELECT COUNT(*) FROM manpower_requests WHERE status='PENDING'")->fetchColumn(),
        'candidates_in_pipeline'=> (int)$conn->query("SELECT COUNT(*) FROM candidates WHERE status NOT IN ('HIRED','REJECTED')")->fetchColumn(),
        'interviews_scheduled'  => (int)$conn->query("SELECT COUNT(*) FROM interviews WHERE status='SCHEDULED'")->fetchColumn(),
        'pending_leaves'        => (int)$conn->query("SELECT COUNT(*) FROM leaves WHERE status='PENDING'")->fetchColumn(),
        'total_departments'     => (int)$conn->query("SELECT COUNT(*) FROM departments")->fetchColumn(),
    ]);
}

// ── EMPLOYEES ─────────────────────────────────────────────────────────────────
if ($endpoint === 'employees') {
    if ($method === 'GET') {
        if ($id) {
            $stmt = $conn->prepare("SELECT e.*, d.name as department FROM employees e LEFT JOIN departments d ON e.dept_id=d.id WHERE e.id=?");
            $stmt->execute([$id]);
            $emp = $stmt->fetch();
            if (!$emp) api_error('Employee not found.', 404);
            api_ok($emp);
        }
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $dept   = (int)($_GET['dept_id'] ?? 0);
        $sql    = "SELECT e.*, d.name as department FROM employees e LEFT JOIN departments d ON e.dept_id=d.id WHERE 1=1";
        $params = [];
        if ($search) { $sql .= " AND (e.name LIKE ? OR e.email LIKE ? OR e.emp_code LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
        if ($status) { $sql .= " AND e.status=?"; $params[] = $status; }
        if ($dept)   { $sql .= " AND e.dept_id=?"; $params[] = $dept; }
        $sql .= " ORDER BY e.name";
        $result = paginate($conn, $sql, $params);
        echo json_encode(array_merge(['success' => true], $result['meta'], ['data' => $result['data']])); exit;
    }
    if ($method === 'POST') {
        $required = ['emp_code','name','email'];
        foreach ($required as $f) if (empty($body[$f])) api_error("Field '$f' is required.");
        $stmt = $conn->prepare("INSERT INTO employees (emp_code,name,email,dept_id,designation,joining_date,salary,status) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$body['emp_code'],$body['name'],$body['email'],$body['dept_id']??null,$body['designation']??null,$body['joining_date']??null,$body['salary']??null,$body['status']??'ACTIVE']);
        api_ok(['id' => (int)$conn->lastInsertId(), 'message' => 'Employee created.']);
    }
    if ($method === 'PUT') {
        if (!$id) api_error('ID required for update.');
        $fields = ['emp_code','name','email','dept_id','designation','joining_date','salary','status'];
        $sets   = []; $params = [];
        foreach ($fields as $f) { if (array_key_exists($f, $body)) { $sets[] = "$f=?"; $params[] = $body[$f]; } }
        if (!$sets) api_error('No fields to update.');
        $params[] = $id;
        $conn->prepare("UPDATE employees SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
        api_ok(['message' => 'Employee updated.']);
    }
    if ($method === 'DELETE') {
        if (!$id) api_error('ID required.');
        $conn->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
        api_ok(['message' => 'Employee deleted.']);
    }
}

// ── CANDIDATES ────────────────────────────────────────────────────────────────
if ($endpoint === 'candidates') {
    if ($method === 'GET') {
        if ($id) {
            $stmt = $conn->prepare("SELECT * FROM candidates WHERE id=?");
            $stmt->execute([$id]);
            $c = $stmt->fetch();
            if (!$c) api_error('Candidate not found.', 404);
            // Include interviews
            $ivs = $conn->query("SELECT i.*, u.name as panel FROM interviews i LEFT JOIN users u ON i.panel_id=u.id WHERE i.candidate_id=$id ORDER BY i.date_time")->fetchAll();
            $c['interviews'] = $ivs;
            api_ok($c);
        }
        $status = $_GET['status'] ?? '';
        $sql    = "SELECT * FROM candidates WHERE 1=1";
        $params = [];
        if ($status) { $sql .= " AND status=?"; $params[] = $status; }
        if (!empty($_GET['search'])) { $sql .= " AND (name LIKE ? OR email LIKE ?)"; $params = array_merge($params, ["%{$_GET['search']}%","%{$_GET['search']}%"]); }
        $sql .= " ORDER BY created_at DESC";
        $result = paginate($conn, $sql, $params);
        echo json_encode(array_merge(['success' => true], $result['meta'], ['data' => $result['data']])); exit;
    }
    if ($method === 'POST') {
        if (empty($body['name'])) api_error("Field 'name' is required.");
        $stmt = $conn->prepare("INSERT INTO candidates (name,email,phone,experience,current_salary,expected_salary,skills,source,applied_role,status) VALUES (?,?,?,?,?,?,?,?,?,'APPLIED')");
        $stmt->execute([$body['name'],$body['email']??null,$body['phone']??null,$body['experience']??null,$body['current_salary']??null,$body['expected_salary']??null,$body['skills']??null,$body['source']??null,$body['applied_role']??null]);
        api_ok(['id' => (int)$conn->lastInsertId(), 'message' => 'Candidate created.']);
    }
    if ($method === 'PUT') {
        if (!$id) api_error('ID required.');
        $fields = ['name','email','phone','experience','current_salary','expected_salary','skills','source','applied_role','status'];
        $sets = []; $params = [];
        foreach ($fields as $f) { if (array_key_exists($f, $body)) { $sets[] = "$f=?"; $params[] = $body[$f]; } }
        if (!$sets) api_error('No fields to update.');
        $params[] = $id;
        $conn->prepare("UPDATE candidates SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
        api_ok(['message' => 'Candidate updated.']);
    }
    if ($method === 'DELETE') {
        if (!$id) api_error('ID required.');
        $conn->prepare("DELETE FROM candidates WHERE id=?")->execute([$id]);
        api_ok(['message' => 'Candidate deleted.']);
    }
}

// ── DEPARTMENTS ───────────────────────────────────────────────────────────────
if ($endpoint === 'departments') {
    if ($method === 'GET') {
        $data = $conn->query("SELECT d.*, COUNT(e.id) as employee_count FROM departments d LEFT JOIN employees e ON e.dept_id=d.id AND e.status='ACTIVE' GROUP BY d.id ORDER BY d.name")->fetchAll();
        api_ok($data);
    }
    if ($method === 'POST') {
        if (empty($body['name'])) api_error("Field 'name' is required.");
        $conn->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$body['name']]);
        api_ok(['id' => (int)$conn->lastInsertId(), 'message' => 'Department created.']);
    }
    if ($method === 'PUT') {
        if (!$id || empty($body['name'])) api_error('ID and name required.');
        $conn->prepare("UPDATE departments SET name=? WHERE id=?")->execute([$body['name'], $id]);
        api_ok(['message' => 'Department updated.']);
    }
    if ($method === 'DELETE') {
        if (!$id) api_error('ID required.');
        $conn->prepare("DELETE FROM departments WHERE id=?")->execute([$id]);
        api_ok(['message' => 'Department deleted.']);
    }
}

// ── REQUESTS ──────────────────────────────────────────────────────────────────
if ($endpoint === 'requests') {
    if ($method === 'GET') {
        if ($id) {
            $stmt = $conn->prepare("SELECT r.*, d.name as department FROM manpower_requests r LEFT JOIN departments d ON r.dept_id=d.id WHERE r.id=?");
            $stmt->execute([$id]);
            $r = $stmt->fetch();
            if (!$r) api_error('Request not found.', 404);
            api_ok($r);
        }
        $status = $_GET['status'] ?? '';
        $sql    = "SELECT r.*, d.name as department FROM manpower_requests r LEFT JOIN departments d ON r.dept_id=d.id WHERE 1=1";
        $params = [];
        if ($status) { $sql .= " AND r.status=?"; $params[] = $status; }
        $sql .= " ORDER BY r.created_at DESC";
        $result = paginate($conn, $sql, $params);
        echo json_encode(array_merge(['success' => true], $result['meta'], ['data' => $result['data']])); exit;
    }
    if ($method === 'POST') {
        if (empty($body['title'])) api_error("Field 'title' is required.");
        $stmt = $conn->prepare("INSERT INTO manpower_requests (dept_id,title,quantity,reason,salary_range,experience,urgency,status,requested_by) VALUES (?,?,?,?,?,?,?,'PENDING',?)");
        $stmt->execute([$body['dept_id']??null,$body['title'],$body['quantity']??1,$body['reason']??null,$body['salary_range']??null,$body['experience']??null,$body['urgency']??'MEDIUM',$body['requested_by']??'API']);
        api_ok(['id' => (int)$conn->lastInsertId(), 'message' => 'Request created.']);
    }
    if ($method === 'PUT') {
        if (!$id) api_error('ID required.');
        $allowed = ['PENDING','APPROVED','REJECTED','ON_HOLD'];
        if (!empty($body['status']) && !in_array($body['status'], $allowed)) api_error('Invalid status value.');
        $fields = ['title','quantity','urgency','status','reason'];
        $sets = []; $params = [];
        foreach ($fields as $f) { if (array_key_exists($f, $body)) { $sets[] = "$f=?"; $params[] = $body[$f]; } }
        if (!$sets) api_error('No fields to update.');
        $params[] = $id;
        $conn->prepare("UPDATE manpower_requests SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
        api_ok(['message' => 'Request updated.']);
    }
}

// ── INTERVIEWS ────────────────────────────────────────────────────────────────
if ($endpoint === 'interviews') {
    if ($method === 'GET') {
        $sql    = "SELECT i.*, c.name as candidate, u.name as panel FROM interviews i LEFT JOIN candidates c ON i.candidate_id=c.id LEFT JOIN users u ON i.panel_id=u.id WHERE 1=1";
        $params = [];
        if (!empty($_GET['status']))       { $sql .= " AND i.status=?"; $params[] = $_GET['status']; }
        if (!empty($_GET['candidate_id'])) { $sql .= " AND i.candidate_id=?"; $params[] = (int)$_GET['candidate_id']; }
        $sql .= " ORDER BY i.date_time DESC";
        $result = paginate($conn, $sql, $params);
        echo json_encode(array_merge(['success' => true], $result['meta'], ['data' => $result['data']])); exit;
    }
    if ($method === 'POST') {
        if (empty($body['candidate_id'])) api_error("Field 'candidate_id' is required.");
        $conn->prepare("INSERT INTO interviews (candidate_id,round,panel_id,date_time,status) VALUES (?,?,?,?,'SCHEDULED')")
             ->execute([$body['candidate_id'],$body['round']??'HR Round',$body['panel_id']??null,$body['date_time']??null]);
        api_ok(['id' => (int)$conn->lastInsertId(), 'message' => 'Interview scheduled.']);
    }
    if ($method === 'PUT') {
        if (!$id) api_error('ID required.');
        $fields = ['status','feedback','rating'];
        $sets = []; $params = [];
        foreach ($fields as $f) { if (array_key_exists($f, $body)) { $sets[] = "$f=?"; $params[] = $body[$f]; } }
        if (!$sets) api_error('No fields to update.');
        $params[] = $id;
        $conn->prepare("UPDATE interviews SET " . implode(',', $sets) . " WHERE id=?")->execute($params);
        api_ok(['message' => 'Interview updated.']);
    }
}

// ── LEAVES ────────────────────────────────────────────────────────────────────
if ($endpoint === 'leaves') {
    if ($method === 'GET') {
        $sql    = "SELECT l.*, e.name as employee, e.emp_code FROM leaves l LEFT JOIN employees e ON l.employee_id=e.id WHERE 1=1";
        $params = [];
        if (!empty($_GET['status']))      { $sql .= " AND l.status=?"; $params[] = $_GET['status']; }
        if (!empty($_GET['employee_id'])) { $sql .= " AND l.employee_id=?"; $params[] = (int)$_GET['employee_id']; }
        $sql .= " ORDER BY l.created_at DESC";
        $result = paginate($conn, $sql, $params);
        echo json_encode(array_merge(['success' => true], $result['meta'], ['data' => $result['data']])); exit;
    }
    if ($method === 'PUT') {
        if (!$id) api_error('ID required.');
        $allowed = ['PENDING','APPROVED','REJECTED'];
        if (!empty($body['status']) && !in_array($body['status'], $allowed)) api_error('Invalid status.');
        $conn->prepare("UPDATE leaves SET status=? WHERE id=?")->execute([$body['status'], $id]);
        api_ok(['message' => 'Leave status updated.']);
    }
}

api_error("Unknown endpoint '$endpoint'. See /api_docs.php for available endpoints.", 404);
