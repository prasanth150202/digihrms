<?php
require_once 'config.php';
require_role('SUPER_ADMIN', 'HR_ADMIN');
$page      = 'reports';
$pageTitle = 'Reports';

// Dept headcount
$dept_hc = $conn->query("SELECT d.name, COUNT(e.id) as cnt FROM departments d LEFT JOIN employees e ON e.dept_id=d.id AND e.status='ACTIVE' GROUP BY d.id,d.name ORDER BY cnt DESC")->fetchAll();

// Candidate pipeline
$pipeline = $conn->query("SELECT status, COUNT(*) as cnt FROM candidates GROUP BY status")->fetchAll();

// Monthly hires (last 12 months)
$monthly = $conn->query("SELECT DATE_FORMAT(joining_date,'%b %Y') as month, COUNT(*) as cnt FROM employees WHERE joining_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(joining_date,'%Y-%m') ORDER BY joining_date")->fetchAll();

// Leave summary
$leave_summary = $conn->query("SELECT leave_type, COUNT(*) as total, SUM(CASE WHEN status='APPROVED' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='REJECTED' THEN 1 ELSE 0 END) as rejected FROM leaves GROUP BY leave_type")->fetchAll();

// Request summary
$req_summary = $conn->query("SELECT status, COUNT(*) as cnt, SUM(quantity) as total_qty FROM manpower_requests GROUP BY status")->fetchAll();

// Top recruiters (sources)
$sources = $conn->query("SELECT source, COUNT(*) as cnt FROM candidates WHERE source IS NOT NULL AND source != '' GROUP BY source ORDER BY cnt DESC")->fetchAll();

include 'header.php';
?>

<div class="row g-4">

    <!-- Dept Headcount -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Department Headcount (Active)</h6>
                <canvas id="deptChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <!-- Candidate Pipeline -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Candidate Pipeline</h6>
                <canvas id="pipelineChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <!-- Monthly Hiring Trend -->
    <div class="col-12">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Monthly Hiring Trend (Last 12 Months)</h6>
                <canvas id="hireChart" height="70"></canvas>
            </div>
        </div>
    </div>

    <!-- Leave Summary Table -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Leave Summary by Type</h6>
                <table class="table table-sm table-hover">
                    <thead class="table-light"><tr><th>Type</th><th>Total</th><th>Approved</th><th>Pending</th><th>Rejected</th></tr></thead>
                    <tbody>
                    <?php foreach ($leave_summary as $l): ?>
                    <tr>
                        <td><?= sanitize($l['leave_type']) ?></td>
                        <td><?= $l['total'] ?></td>
                        <td><span class="text-success fw-semibold"><?= $l['approved'] ?></span></td>
                        <td><span class="text-warning fw-semibold"><?= $l['pending'] ?></span></td>
                        <td><span class="text-danger fw-semibold"><?= $l['rejected'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$leave_summary): ?><tr><td colspan="5" class="text-center text-muted">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Request Summary -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3">Manpower Request Summary</h6>
                <table class="table table-sm table-hover">
                    <thead class="table-light"><tr><th>Status</th><th>Requests</th><th>Total Qty</th></tr></thead>
                    <tbody>
                    <?php foreach ($req_summary as $r): ?>
                    <tr>
                        <td><span class="badge bg-<?= badge($r['status']) ?>"><?= $r['status'] ?></span></td>
                        <td><?= $r['cnt'] ?></td>
                        <td><?= $r['total_qty'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$req_summary): ?><tr><td colspan="3" class="text-center text-muted">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>

                <h6 class="fw-bold mb-3 mt-4">Candidate Sources</h6>
                <table class="table table-sm table-hover">
                    <thead class="table-light"><tr><th>Source</th><th>Candidates</th></tr></thead>
                    <tbody>
                    <?php foreach ($sources as $s): ?>
                    <tr><td><?= sanitize($s['source']) ?></td><td><?= $s['cnt'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$sources): ?><tr><td colspan="2" class="text-center text-muted">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($dept_hc, 'name')) ?>,
        datasets: [{ label: 'Employees', data: <?= json_encode(array_map('intval', array_column($dept_hc, 'cnt'))) ?>, backgroundColor: '#0f4c81', borderRadius: 6 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
});

new Chart(document.getElementById('pipelineChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($pipeline, 'status')) ?>,
        datasets: [{ data: <?= json_encode(array_map('intval', array_column($pipeline, 'cnt'))) ?>, backgroundColor: ['#0f4c81','#1e88e5','#fb8c00','#43a047','#8e24aa','#e53935'] }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
});

new Chart(document.getElementById('hireChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($monthly, 'month')) ?>,
        datasets: [{ label: 'Hires', data: <?= json_encode(array_map('intval', array_column($monthly, 'cnt'))) ?>, borderColor: '#0f4c81', backgroundColor: 'rgba(15,76,129,0.1)', fill: true, tension: 0.4 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
});
</script>

<?php include 'footer.php'; ?>
