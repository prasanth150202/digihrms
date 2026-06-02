<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digifyce — API Documentation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root { --primary: #0f4c81; --sidebar-w: 280px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8fafc; }

        /* Sidebar */
        #sidebar { width: var(--sidebar-w); min-height: 100vh; background: #1e293b; position: fixed; top: 0; left: 0; overflow-y: auto; z-index: 100; }
        #sidebar .brand { padding: 24px 20px 16px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        #sidebar .brand h5 { color: #fff; font-weight: 700; margin: 0; }
        #sidebar .brand small { color: #64748b; font-size: 0.75rem; }
        #sidebar .section-label { padding: 16px 16px 6px; color: #475569; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
        #sidebar a { display: block; padding: 8px 16px; color: #94a3b8; font-size: 0.85rem; text-decoration: none; border-radius: 6px; margin: 1px 8px; }
        #sidebar a:hover, #sidebar a.active { background: rgba(255,255,255,0.08); color: #fff; }
        #main { margin-left: var(--sidebar-w); padding: 40px; max-width: calc(1200px + var(--sidebar-w)); }

        /* Method badges */
        .method { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.5px; min-width: 60px; text-align: center; }
        .method.get    { background: #dcfce7; color: #166534; }
        .method.post   { background: #dbeafe; color: #1e40af; }
        .method.put    { background: #fef9c3; color: #854d0e; }
        .method.delete { background: #fee2e2; color: #991b1b; }

        /* Endpoint cards */
        .endpoint-card { border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 16px; overflow: hidden; background: #fff; }
        .endpoint-header { padding: 14px 20px; cursor: pointer; display: flex; align-items: center; gap: 12px; user-select: none; }
        .endpoint-header:hover { background: #f8fafc; }
        .endpoint-path { font-family: monospace; font-size: 0.9rem; color: #1e293b; font-weight: 600; }
        .endpoint-body { border-top: 1px solid #e2e8f0; padding: 20px; display: none; }
        .endpoint-body.open { display: block; }

        /* Code blocks */
        .code-block { background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: 16px; font-family: monospace; font-size: 0.8rem; overflow-x: auto; position: relative; line-height: 1.6; }
        .code-block .copy-btn { position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.1); border: none; color: #94a3b8; border-radius: 4px; padding: 4px 8px; font-size: 0.7rem; cursor: pointer; }
        .code-block .copy-btn:hover { background: rgba(255,255,255,0.2); color: #fff; }
        .string { color: #86efac; }
        .number { color: #fca5a5; }
        .key    { color: #93c5fd; }
        .bool   { color: #c4b5fd; }

        /* Param table */
        .param-table th { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #64748b; background: #f8fafc; }
        .param-table td { font-size: 0.85rem; vertical-align: middle; }
        .param-required { color: #ef4444; font-size: 0.7rem; font-weight: 700; }
        .param-optional { color: #94a3b8; font-size: 0.7rem; }
        code { background: #f1f5f9; color: #0f4c81; padding: 2px 6px; border-radius: 4px; font-size: 0.82rem; }

        /* Hero */
        .hero { background: linear-gradient(135deg, #1e293b 0%, #0f4c81 100%); color: #fff; padding: 36px 40px; border-radius: 16px; margin-bottom: 36px; }
        .hero h1 { font-weight: 800; font-size: 1.8rem; margin: 0; }
        .hero p { color: rgba(255,255,255,0.7); margin: 8px 0 0; }
        .base-url-box { background: rgba(255,255,255,0.1); border-radius: 8px; padding: 10px 16px; font-family: monospace; font-size: 0.9rem; color: #fff; margin-top: 16px; display: inline-block; }

        .try-panel { background: #f8fafc; border-radius: 8px; padding: 16px; margin-top: 16px; }
        .response-box { background: #0f172a; color: #86efac; border-radius: 8px; padding: 16px; font-family: monospace; font-size: 0.8rem; min-height: 80px; white-space: pre-wrap; margin-top: 10px; max-height: 300px; overflow-y: auto; }
    </style>
</head>
<body>

<!-- Sidebar -->
<div id="sidebar">
    <div class="brand">
        <h5><i class="bi bi-braces me-2 text-primary"></i>HRMS API</h5>
        <small>v1.0 · REST API Docs</small>
    </div>
    <div class="section-label">Overview</div>
    <a href="#overview" onclick="scrollTo('overview')">Introduction</a>
    <a href="#auth" onclick="scrollTo('auth')">Authentication</a>
    <a href="#errors" onclick="scrollTo('errors')">Error Codes</a>
    <a href="#pagination" onclick="scrollTo('pagination')">Pagination</a>
    <div class="section-label">Endpoints</div>
    <a href="#stats" onclick="scrollTo('stats')"><span class="method get me-2">GET</span> Stats</a>
    <a href="#employees" onclick="scrollTo('employees')">Employees</a>
    <a href="#candidates" onclick="scrollTo('candidates')">Candidates</a>
    <a href="#departments" onclick="scrollTo('departments')">Departments</a>
    <a href="#requests" onclick="scrollTo('requests')">Manpower Requests</a>
    <a href="#interviews" onclick="scrollTo('interviews')">Interviews</a>
    <a href="#leaves" onclick="scrollTo('leaves')">Leaves</a>
    <div class="section-label">Tools</div>
    <a href="api_keys.php"><i class="bi bi-key me-2"></i>Manage API Keys</a>
    <a href="index.php"><i class="bi bi-arrow-left me-2"></i>Back to HRMS</a>
</div>

<!-- Main -->
<div id="main">

<!-- Hero -->
<div class="hero">
    <h1><i class="bi bi-braces-asterisk me-2"></i>Digifyce REST API</h1>
    <p>Complete REST API for integrating HRMS data with external applications, mobile apps, and automations.</p>
    <div class="base-url-box">
        <span style="color:#94a3b8;">Base URL &nbsp;</span>
        <?php
        $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api.php';
        echo htmlspecialchars($base);
        ?>
    </div>
</div>

<!-- Auth -->
<section id="auth" class="mb-5">
    <h4 class="fw-bold mb-3">Authentication</h4>
    <p class="text-muted">All requests require an API key. Generate one from <a href="api_keys.php">API Keys</a>. Pass it as a request header:</p>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<span class="key"># Option 1 — X-API-Key header</span>
X-API-Key: <span class="string">your_api_key_here</span>

<span class="key"># Option 2 — Bearer token</span>
Authorization: Bearer <span class="string">your_api_key_here</span></div>
        </div>
        <div class="col-md-6">
            <div class="code-block">
                <button class="copy-btn" onclick="copyCode(this)">Copy</button>
<span class="key"># cURL example</span>
curl -X GET \
  '<?= htmlspecialchars($base) ?>?endpoint=stats' \
  -H <span class="string">'X-API-Key: your_api_key_here'</span></div>
        </div>
    </div>
</section>

<!-- Errors -->
<section id="errors" class="mb-5">
    <h4 class="fw-bold mb-3">Error Codes</h4>
    <table class="table table-bordered param-table">
        <thead><tr><th>Code</th><th>Meaning</th></tr></thead>
        <tbody>
            <tr><td><code>200</code></td><td>Success</td></tr>
            <tr><td><code>400</code></td><td>Bad request — missing or invalid parameters</td></tr>
            <tr><td><code>401</code></td><td>Unauthorized — invalid or missing API key</td></tr>
            <tr><td><code>404</code></td><td>Resource not found</td></tr>
        </tbody>
    </table>
    <div class="code-block" style="max-width:480px;">
<span class="key">"success"</span>: <span class="bool">false</span>,
<span class="key">"error"</span>: <span class="string">"Employee not found."</span></div>
</section>

<!-- Pagination -->
<section id="pagination" class="mb-5">
    <h4 class="fw-bold mb-3">Pagination</h4>
    <p class="text-muted">List endpoints support pagination via query parameters:</p>
    <table class="table table-bordered param-table" style="max-width:500px;">
        <thead><tr><th>Param</th><th>Default</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td><code>page</code></td><td>1</td><td>Page number</td></tr>
            <tr><td><code>per_page</code></td><td>20</td><td>Results per page (max 100)</td></tr>
        </tbody>
    </table>
    <div class="code-block" style="max-width:560px;">
<span class="key">"success"</span>: <span class="bool">true</span>,
<span class="key">"total"</span>: <span class="number">84</span>,
<span class="key">"page"</span>: <span class="number">1</span>,
<span class="key">"per_page"</span>: <span class="number">20</span>,
<span class="key">"pages"</span>: <span class="number">5</span>,
<span class="key">"data"</span>: [ ... ]</div>
</section>

<!-- ── ENDPOINTS ─────────────────────────────────────── -->

<?php
$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api.php';

function endpoint_card($method, $path, $title, $desc, $params, $example_req, $example_res, $base_url, $try_fields = []) {
    $id = md5($method.$path);
    $m  = strtolower($method);
    echo <<<HTML
<div class="endpoint-card">
    <div class="endpoint-header" onclick="toggleEndpoint('$id')">
        <span class="method $m">$method</span>
        <span class="endpoint-path">$path</span>
        <span class="text-muted small ms-2">$title</span>
        <i class="bi bi-chevron-down ms-auto text-muted" id="chevron-$id"></i>
    </div>
    <div class="endpoint-body" id="body-$id">
        <p class="text-muted small mb-3">$desc</p>
HTML;

    if ($params) {
        echo '<h6 class="fw-bold small mb-2">Parameters</h6>';
        echo '<table class="table table-bordered param-table mb-4"><thead><tr><th>Name</th><th>Type</th><th>Required</th><th>Description</th></tr></thead><tbody>';
        foreach ($params as $p) {
            $req = $p[2] ? '<span class="param-required">Required</span>' : '<span class="param-optional">Optional</span>';
            echo "<tr><td><code>{$p[0]}</code></td><td>{$p[1]}</td><td>$req</td><td class='text-muted small'>{$p[3]}</td></tr>";
        }
        echo '</tbody></table>';
    }

    echo '<div class="row g-3">';
    echo '<div class="col-md-6"><h6 class="fw-bold small mb-2">Example Request</h6><div class="code-block"><button class="copy-btn" onclick="copyCode(this)">Copy</button>' . htmlspecialchars($example_req) . '</div></div>';
    echo '<div class="col-md-6"><h6 class="fw-bold small mb-2">Example Response</h6><div class="code-block">' . $example_res . '</div></div>';
    echo '</div>';

    // Try it
    if ($try_fields !== false) {
        echo <<<HTML
        <div class="try-panel mt-4">
            <h6 class="fw-bold small mb-3"><i class="bi bi-play-circle me-1 text-success"></i>Try it</h6>
            <div class="row g-2 mb-2">
                <div class="col-md-5">
                    <input type="text" class="form-control form-control-sm" placeholder="Your API Key" id="key-$id">
                </div>
            </div>
            <button class="btn btn-sm btn-success" onclick="tryRequest('$id','$method','$base_url?endpoint=$path')">
                <i class="bi bi-send me-1"></i>Send Request
            </button>
            <div class="response-box mt-2" id="res-$id">Response will appear here...</div>
        </div>
HTML;
    }

    echo '</div></div>';
}
?>

<!-- STATS -->
<section id="stats" class="mb-5">
    <h4 class="fw-bold mb-3 d-flex align-items-center gap-2"><i class="bi bi-bar-chart-fill text-primary"></i> Stats</h4>
    <?php endpoint_card('GET','stats','Dashboard Statistics','Returns overall HRMS statistics.',
        [],
        "curl '$base_url?endpoint=stats' \\\n  -H 'X-API-Key: your_key'",
        '<span class="key">"success"</span>: <span class="bool">true</span>,
<span class="key">"data"</span>: {
  <span class="key">"active_employees"</span>: <span class="number">42</span>,
  <span class="key">"open_requests"</span>: <span class="number">7</span>,
  <span class="key">"candidates_in_pipeline"</span>: <span class="number">18</span>,
  <span class="key">"interviews_scheduled"</span>: <span class="number">5</span>,
  <span class="key">"pending_leaves"</span>: <span class="number">3</span>,
  <span class="key">"total_departments"</span>: <span class="number">5</span>
}', $base_url); ?>
</section>

<!-- EMPLOYEES -->
<section id="employees" class="mb-5">
    <h4 class="fw-bold mb-3 d-flex align-items-center gap-2"><i class="bi bi-person-badge text-primary"></i> Employees</h4>

    <?php endpoint_card('GET','employees','List Employees','Returns paginated list of employees.',
        [['search','string',false,'Filter by name, email, emp_code'],['dept_id','integer',false,'Filter by department ID'],['status','string',false,'ACTIVE | PROBATION | EXITED'],['page','integer',false,'Page number'],['per_page','integer',false,'Results per page (max 100)']],
        "curl '$base_url?endpoint=employees&status=ACTIVE&page=1' \\\n  -H 'X-API-Key: your_key'",
        '<span class="key">"total"</span>: <span class="number">42</span>, <span class="key">"page"</span>: <span class="number">1</span>,
<span class="key">"data"</span>: [{
  <span class="key">"id"</span>: <span class="number">1</span>,
  <span class="key">"emp_code"</span>: <span class="string">"EMP001"</span>,
  <span class="key">"name"</span>: <span class="string">"John Doe"</span>,
  <span class="key">"department"</span>: <span class="string">"Engineering"</span>,
  <span class="key">"status"</span>: <span class="string">"ACTIVE"</span>
}]', $base_url);

    endpoint_card('GET','employees&id={id}','Get Employee','Returns single employee by ID.',
        [['id','integer',true,'Employee ID']],
        "curl '$base_url?endpoint=employees&id=1' \\\n  -H 'X-API-Key: your_key'",
        '<span class="key">"data"</span>: {
  <span class="key">"id"</span>: <span class="number">1</span>,
  <span class="key">"emp_code"</span>: <span class="string">"EMP001"</span>,
  <span class="key">"name"</span>: <span class="string">"John Doe"</span>,
  <span class="key">"email"</span>: <span class="string">"john@company.com"</span>,
  <span class="key">"designation"</span>: <span class="string">"Senior Developer"</span>,
  <span class="key">"joining_date"</span>: <span class="string">"2023-01-15"</span>,
  <span class="key">"salary"</span>: <span class="number">75000</span>
}', $base_url, false);

    endpoint_card('POST','employees','Create Employee','Creates a new employee record.',
        [['emp_code','string',true,'Unique employee code'],['name','string',true,'Full name'],['email','string',true,'Email address'],['dept_id','integer',false,'Department ID'],['designation','string',false,'Job title'],['joining_date','date',false,'YYYY-MM-DD'],['salary','number',false,'Monthly salary'],['status','string',false,'ACTIVE | PROBATION | EXITED']],
        "curl -X POST '$base_url?endpoint=employees' \\\n  -H 'X-API-Key: your_key' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\n    \"emp_code\": \"EMP042\",\n    \"name\": \"Jane Smith\",\n    \"email\": \"jane@company.com\",\n    \"designation\": \"Designer\",\n    \"joining_date\": \"2024-06-01\"\n  }'",
        '<span class="key">"success"</span>: <span class="bool">true</span>,
<span class="key">"data"</span>: {
  <span class="key">"id"</span>: <span class="number">42</span>,
  <span class="key">"message"</span>: <span class="string">"Employee created."</span>
}', $base_url, false);

    endpoint_card('PUT','employees&id={id}','Update Employee','Updates an existing employee.',
        [['id','integer',true,'Employee ID (query param)'],['name','string',false,'Full name'],['status','string',false,'ACTIVE | PROBATION | EXITED'],['...','','false','Any employee field']],
        "curl -X PUT '$base_url?endpoint=employees&id=1' \\\n  -H 'X-API-Key: your_key' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"status\": \"EXITED\"}'",
        '<span class="key">"success"</span>: <span class="bool">true</span>,
<span class="key">"data"</span>: { <span class="key">"message"</span>: <span class="string">"Employee updated."</span> }', $base_url, false);

    endpoint_card('DELETE','employees&id={id}','Delete Employee','Permanently deletes an employee record.',
        [['id','integer',true,'Employee ID']],
        "curl -X DELETE '$base_url?endpoint=employees&id=1' \\\n  -H 'X-API-Key: your_key'",
        '<span class="key">"success"</span>: <span class="bool">true</span>,
<span class="key">"data"</span>: { <span class="key">"message"</span>: <span class="string">"Employee deleted."</span> }', $base_url, false);
?>
</section>

<!-- CANDIDATES -->
<section id="candidates" class="mb-5">
    <h4 class="fw-bold mb-3 d-flex align-items-center gap-2"><i class="bi bi-person-lines-fill text-primary"></i> Candidates</h4>

    <?php endpoint_card('GET','candidates','List Candidates','Returns paginated candidate list.',
        [['status','string',false,'APPLIED | SCREENING | INTERVIEW | OFFERED | HIRED | REJECTED'],['search','string',false,'Search by name or email'],['page','integer',false,'Page number']],
        "curl '$base_url?endpoint=candidates&status=INTERVIEW' \\\n  -H 'X-API-Key: your_key'",
        '<span class="key">"total"</span>: <span class="number">18</span>,
<span class="key">"data"</span>: [{
  <span class="key">"id"</span>: <span class="number">5</span>,
  <span class="key">"name"</span>: <span class="string">"Ravi Kumar"</span>,
  <span class="key">"applied_role"</span>: <span class="string">"PHP Developer"</span>,
  <span class="key">"status"</span>: <span class="string">"INTERVIEW"</span>,
  <span class="key">"experience"</span>: <span class="string">"3 years"</span>
}]', $base_url);

    endpoint_card('GET','candidates&id={id}','Get Candidate','Returns single candidate with interview history.',
        [['id','integer',true,'Candidate ID']],
        "curl '$base_url?endpoint=candidates&id=5' \\\n  -H 'X-API-Key: your_key'",
        '<span class="key">"data"</span>: {
  <span class="key">"id"</span>: <span class="number">5</span>,
  <span class="key">"name"</span>: <span class="string">"Ravi Kumar"</span>,
  <span class="key">"interviews"</span>: [{
    <span class="key">"round"</span>: <span class="string">"HR Round"</span>,
    <span class="key">"status"</span>: <span class="string">"COMPLETED"</span>,
    <span class="key">"rating"</span>: <span class="number">4</span>
  }]
}', $base_url, false);

    endpoint_card('POST','candidates','Create Candidate','Adds a new candidate to the pipeline.',
        [['name','string',true,'Full name'],['email','string',false,'Email'],['phone','string',false,'Phone'],['applied_role','string',false,'Role applied for'],['experience','string',false,'e.g. 3 years'],['source','string',false,'LinkedIn | Referral | Job Portal'],['skills','string',false,'Comma-separated skills']],
        "curl -X POST '$base_url?endpoint=candidates' \\\n  -H 'X-API-Key: your_key' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\n    \"name\": \"Ravi Kumar\",\n    \"email\": \"ravi@email.com\",\n    \"applied_role\": \"PHP Developer\",\n    \"experience\": \"3 years\",\n    \"source\": \"LinkedIn\"\n  }'",
        '<span class="key">"success"</span>: <span class="bool">true</span>,
<span class="key">"data"</span>: { <span class="key">"id"</span>: <span class="number">15</span>, <span class="key">"message"</span>: <span class="string">"Candidate created."</span> }', $base_url, false);

    endpoint_card('PUT','candidates&id={id}','Update Candidate / Move Stage','Updates candidate info or moves pipeline stage.',
        [['status','string',false,'APPLIED | SCREENING | INTERVIEW | OFFERED | HIRED | REJECTED'],['...','','false','Any candidate field']],
        "curl -X PUT '$base_url?endpoint=candidates&id=5' \\\n  -H 'X-API-Key: your_key' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"status\": \"OFFERED\"}'",
        '<span class="key">"data"</span>: { <span class="key">"message"</span>: <span class="string">"Candidate updated."</span> }', $base_url, false);
?>
</section>

<!-- DEPARTMENTS -->
<section id="departments" class="mb-5">
    <h4 class="fw-bold mb-3 d-flex align-items-center gap-2"><i class="bi bi-diagram-3 text-primary"></i> Departments</h4>
    <?php endpoint_card('GET','departments','List Departments','Returns all departments with employee count.',
        [],
        "curl '$base_url?endpoint=departments' \\\n  -H 'X-API-Key: your_key'",
        '<span class="key">"data"</span>: [{
  <span class="key">"id"</span>: <span class="number">1</span>,
  <span class="key">"name"</span>: <span class="string">"Engineering"</span>,
  <span class="key">"employee_count"</span>: <span class="number">12</span>
}]', $base_url); ?>
</section>

<!-- REQUESTS -->
<section id="requests" class="mb-5">
    <h4 class="fw-bold mb-3 d-flex align-items-center gap-2"><i class="bi bi-file-earmark-text text-primary"></i> Manpower Requests</h4>
    <?php endpoint_card('GET','requests','List Requests','Returns manpower hiring requests.',
        [['status','string',false,'PENDING | APPROVED | REJECTED | ON_HOLD'],['page','integer',false,'Page number']],
        "curl '$base_url?endpoint=requests&status=PENDING' \\\n  -H 'X-API-Key: your_key'",
        '<span class="key">"data"</span>: [{
  <span class="key">"id"</span>: <span class="number">3</span>,
  <span class="key">"title"</span>: <span class="string">"Senior Developer"</span>,
  <span class="key">"department"</span>: <span class="string">"Engineering"</span>,
  <span class="key">"urgency"</span>: <span class="string">"HIGH"</span>,
  <span class="key">"status"</span>: <span class="string">"PENDING"</span>
}]', $base_url);

    endpoint_card('PUT','requests&id={id}','Approve / Reject Request','Updates request status.',
        [['status','string',true,'APPROVED | REJECTED | ON_HOLD | PENDING']],
        "curl -X PUT '$base_url?endpoint=requests&id=3' \\\n  -H 'X-API-Key: your_key' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"status\": \"APPROVED\"}'",
        '<span class="key">"data"</span>: { <span class="key">"message"</span>: <span class="string">"Request updated."</span> }', $base_url, false); ?>
</section>

<!-- INTERVIEWS -->
<section id="interviews" class="mb-5">
    <h4 class="fw-bold mb-3 d-flex align-items-center gap-2"><i class="bi bi-camera-video text-primary"></i> Interviews</h4>
    <?php endpoint_card('GET','interviews','List Interviews','Returns interviews with candidate and panel info.',
        [['status','string',false,'SCHEDULED | COMPLETED | CANCELLED'],['candidate_id','integer',false,'Filter by candidate']],
        "curl '$base_url?endpoint=interviews&status=SCHEDULED' \\\n  -H 'X-API-Key: your_key'",
        '<span class="key">"data"</span>: [{
  <span class="key">"id"</span>: <span class="number">7</span>,
  <span class="key">"candidate"</span>: <span class="string">"Ravi Kumar"</span>,
  <span class="key">"round"</span>: <span class="string">"Technical Round 1"</span>,
  <span class="key">"date_time"</span>: <span class="string">"2024-06-15 10:00:00"</span>,
  <span class="key">"status"</span>: <span class="string">"SCHEDULED"</span>
}]', $base_url);

    endpoint_card('PUT','interviews&id={id}','Update Interview Feedback','Marks interview status and saves feedback.',
        [['status','string',false,'COMPLETED | CANCELLED'],['rating','integer',false,'1–5'],['feedback','string',false,'Feedback text']],
        "curl -X PUT '$base_url?endpoint=interviews&id=7' \\\n  -H 'X-API-Key: your_key' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"status\":\"COMPLETED\",\"rating\":4,\"feedback\":\"Good candidate.\"}'",
        '<span class="key">"data"</span>: { <span class="key">"message"</span>: <span class="string">"Interview updated."</span> }', $base_url, false); ?>
</section>

<!-- LEAVES -->
<section id="leaves" class="mb-5">
    <h4 class="fw-bold mb-3 d-flex align-items-center gap-2"><i class="bi bi-calendar-check text-primary"></i> Leaves</h4>
    <?php endpoint_card('GET','leaves','List Leaves','Returns leave applications.',
        [['status','string',false,'PENDING | APPROVED | REJECTED'],['employee_id','integer',false,'Filter by employee']],
        "curl '$base_url?endpoint=leaves&status=PENDING' \\\n  -H 'X-API-Key: your_key'",
        '<span class="key">"data"</span>: [{
  <span class="key">"id"</span>: <span class="number">2</span>,
  <span class="key">"employee"</span>: <span class="string">"John Doe"</span>,
  <span class="key">"leave_type"</span>: <span class="string">"Sick Leave"</span>,
  <span class="key">"days"</span>: <span class="number">2</span>,
  <span class="key">"status"</span>: <span class="string">"PENDING"</span>
}]', $base_url);

    endpoint_card('PUT','leaves&id={id}','Approve / Reject Leave','Updates leave status.',
        [['status','string',true,'APPROVED | REJECTED']],
        "curl -X PUT '$base_url?endpoint=leaves&id=2' \\\n  -H 'X-API-Key: your_key' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"status\": \"APPROVED\"}'",
        '<span class="key">"data"</span>: { <span class="key">"message"</span>: <span class="string">"Leave status updated."</span> }', $base_url, false); ?>
</section>

</div><!-- /main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleEndpoint(id) {
    const body    = document.getElementById('body-' + id);
    const chevron = document.getElementById('chevron-' + id);
    body.classList.toggle('open');
    chevron.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
}

function copyCode(btn) {
    const text = btn.parentElement.innerText.replace('Copy','').trim();
    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy', 2000);
    });
}

function scrollTo(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
}

function tryRequest(id, method, url) {
    const key    = document.getElementById('key-' + id)?.value;
    const resBox = document.getElementById('res-' + id);
    if (!key) { resBox.textContent = 'Enter your API key first.'; return; }
    resBox.textContent = 'Sending...';
    fetch(url, { method: method === 'GET' ? 'GET' : method, headers: { 'X-API-Key': key, 'Content-Type': 'application/json' } })
        .then(r => r.json())
        .then(d => resBox.textContent = JSON.stringify(d, null, 2))
        .catch(e => resBox.textContent = 'Error: ' + e.message);
}

// Highlight active sidebar link on scroll
window.addEventListener('scroll', () => {
    document.querySelectorAll('section[id]').forEach(sec => {
        if (sec.getBoundingClientRect().top < 120) {
            document.querySelectorAll('#sidebar a').forEach(a => a.classList.remove('active'));
            const link = document.querySelector('#sidebar a[href="#' + sec.id + '"]');
            if (link) link.classList.add('active');
        }
    });
});
</script>
</body>
</html>
