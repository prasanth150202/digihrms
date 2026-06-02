<?php
require_once 'config.php';
require_role('SUPER_ADMIN', 'HR_ADMIN');
$page      = 'api_keys';
$pageTitle = 'API Keys';

$new_key = null;

// Revoke
if (isset($_GET['revoke'])) {
    $conn->prepare("UPDATE api_keys SET is_active=0 WHERE id=?")->execute([(int)$_GET['revoke']]);
    set_flash('success', 'API key revoked.');
    header("Location: api_keys.php"); exit;
}

// Delete
if (isset($_GET['delete'])) {
    $conn->prepare("DELETE FROM api_keys WHERE id=?")->execute([(int)$_GET['delete']]);
    set_flash('success', 'API key deleted.');
    header("Location: api_keys.php"); exit;
}

// Generate new key — save it but show BEFORE redirecting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['label'])) {
    $label   = trim($_POST['label'] ?? 'My App');
    $new_key = bin2hex(random_bytes(32));
    $conn->prepare("INSERT INTO api_keys (user_id, label, api_key) VALUES (?,?,?)")
         ->execute([current_user()['id'], $label, $new_key]);
    // Do NOT redirect — fall through to show the key reveal screen
}

$keys = $conn->query("SELECT k.*, u.name as owner FROM api_keys k JOIN users u ON k.user_id=u.id ORDER BY k.created_at DESC")->fetchAll();

include 'header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-9">

<?php if ($new_key): ?>
<!-- ── KEY REVEAL CARD ─────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4" style="border-radius:14px; border: 2px solid #22c55e !important;">
    <div class="card-body p-4">
        <div class="d-flex align-items-center gap-2 mb-3">
            <div style="width:40px;height:40px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-key-fill text-success fs-5"></i>
            </div>
            <div>
                <h5 class="fw-bold mb-0 text-success">API Key Generated!</h5>
                <small class="text-muted">Copy it now — this is the only time it will be shown in full.</small>
            </div>
        </div>

        <div class="alert alert-warning py-2 small mb-3">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong>This key cannot be retrieved later.</strong> Copy and store it in a safe place before leaving this page.
        </div>

        <!-- Key display -->
        <label class="form-label fw-semibold small">Your API Key</label>
        <div class="input-group mb-3">
            <input type="text" id="newKeyInput" class="form-control font-monospace fw-bold"
                   value="<?= sanitize($new_key) ?>" readonly
                   style="background:#f8fafc;font-size:0.85rem;letter-spacing:1px;">
            <button class="btn btn-success px-4" id="copyBtn" onclick="copyNewKey()">
                <i class="bi bi-clipboard me-1"></i> Copy
            </button>
        </div>

        <!-- Usage snippet -->
        <label class="form-label fw-semibold small">Use it like this:</label>
        <div style="background:#0f172a;color:#86efac;border-radius:8px;padding:14px 16px;font-family:monospace;font-size:0.8rem;position:relative;">
            <button onclick="copySnippet(this)" style="position:absolute;top:8px;right:8px;background:rgba(255,255,255,0.1);border:none;color:#94a3b8;border-radius:4px;padding:3px 8px;font-size:0.7rem;cursor:pointer;">Copy</button>
curl -H "X-API-Key: <?= sanitize($new_key) ?>" \
     "<?= (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] ?>/api.php?endpoint=stats"
        </div>

        <div class="d-flex gap-2 mt-4">
            <button id="confirmBtn" class="btn btn-success" disabled onclick="location.href='api_keys.php'">
                <i class="bi bi-check-lg me-1"></i> I've Copied the Key — Done
            </button>
            <small class="text-muted align-self-center" id="confirmHint">Click <strong>Copy</strong> first to enable this button.</small>
        </div>
    </div>
</div>
<?php endif; ?>

    <div class="alert alert-info small mb-4">
        <i class="bi bi-info-circle me-2"></i>
        Pass your key as <code>X-API-Key: your_key</code> in every API request.
        &nbsp;<a href="api_docs.php" target="_blank" class="fw-semibold">View API Docs →</a>
    </div>

    <!-- Generate -->
    <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle me-2"></i>Generate New API Key</h6>
            <form method="POST" class="d-flex gap-2">
                <input type="text" name="label" class="form-control" placeholder="Label (e.g. Mobile App, Webhook, Zapier)" required>
                <button class="btn btn-primary px-4 text-nowrap"><i class="bi bi-key me-1"></i>Generate</button>
            </form>
        </div>
    </div>

    <!-- Keys List -->
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Label</th>
                        <th>Owner</th>
                        <th>Key</th>
                        <th>Last Used</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$keys): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">No API keys yet</td></tr>
                <?php else: ?>
                <?php foreach ($keys as $k): ?>
                <tr>
                    <td class="ps-4 fw-semibold"><?= sanitize($k['label']) ?></td>
                    <td class="text-muted small"><?= sanitize($k['owner']) ?></td>
                    <td>
                        <code class="small text-muted">
                            <?= substr($k['api_key'],0,8) ?>••••••••••••••••••••••••<?= substr($k['api_key'],-6) ?>
                        </code>
                    </td>
                    <td class="text-muted small"><?= $k['last_used'] ? date('d M Y, h:i A', strtotime($k['last_used'])) : 'Never' ?></td>
                    <td>
                        <span class="badge bg-<?= $k['is_active'] ? 'success' : 'secondary' ?> rounded-pill">
                            <?= $k['is_active'] ? 'Active' : 'Revoked' ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($k['is_active']): ?>
                        <a href="?revoke=<?= $k['id'] ?>" class="btn btn-sm btn-outline-warning py-0 px-2"
                           onclick="return hConfirmSync(event,'Revoke this key? It will stop working immediately.')">
                            <i class="bi bi-slash-circle me-1"></i>Revoke
                        </a>
                        <?php endif; ?>
                        <a href="?delete=<?= $k['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
                           onclick="return hConfirmSync(event,'Permanently delete this key?')">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</div>

<script>
function copyNewKey() {
    const input = document.getElementById('newKeyInput');
    input.select();
    navigator.clipboard.writeText(input.value).then(() => {
        const btn = document.getElementById('copyBtn');
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Copied!';
        btn.classList.replace('btn-success', 'btn-dark');

        const confirmBtn = document.getElementById('confirmBtn');
        confirmBtn.disabled = false;
        confirmBtn.classList.replace('btn-success', 'btn-primary');

        document.getElementById('confirmHint').innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Key copied! You can now close this page.</span>';
    });
}

function copySnippet(btn) {
    const text = btn.parentElement.innerText.replace(btn.innerText, '').trim();
    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy', 2000);
    });
}
</script>

<?php include 'footer.php'; ?>
