<?php
require_once 'config.php';
require_login();
require_role('SUPER_ADMIN', 'HR_ADMIN');

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    set_flash('danger', 'Indeed authorization denied: ' . htmlspecialchars($_GET['error_description'] ?? $error));
    header('Location: portals.php?tab=indeed'); exit;
}

if (!$code || !$state || $state !== ($_SESSION['indeed_oauth_state'] ?? '')) {
    set_flash('danger', 'Invalid OAuth state. Please try connecting again.');
    header('Location: portals.php?tab=indeed'); exit;
}
unset($_SESSION['indeed_oauth_state']);

try {
    $s = $conn->prepare("SELECT * FROM portal_configs WHERE portal='INDEED'");
    $s->execute();
    $cfg = $s->fetch();

    if (!$cfg || empty($cfg['client_id'])) {
        set_flash('danger', 'Indeed app credentials not configured. Add Client ID & Secret first.');
        header('Location: portals.php?tab=indeed'); exit;
    }

    $redirect_uri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                  . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/indeed_callback.php';

    // Exchange auth code → access token
    $ch = curl_init('https://apis.indeed.com/oauth/v2/tokens');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect_uri,
            'client_id'     => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $token_raw  = curl_exec($ch);
    $curl_err   = curl_error($ch);
    curl_close($ch);

    if ($token_raw === false) {
        set_flash('danger', 'Indeed token request failed (curl): ' . $curl_err);
        header('Location: portals.php?tab=indeed'); exit;
    }

    $token_data = json_decode($token_raw, true);

    if (empty($token_data['access_token'])) {
        set_flash('danger', 'Indeed token exchange failed: ' . ($token_data['error_description'] ?? substr($token_raw, 0, 300)));
        header('Location: portals.php?tab=indeed'); exit;
    }

    $access_token  = $token_data['access_token'];
    $refresh_token = $token_data['refresh_token'] ?? null;
    $expires_at    = date('Y-m-d H:i:s', time() + ($token_data['expires_in'] ?? 3600));

    // Fetch employer info
    $ch = curl_init('https://employers.indeed.com/api/v2/employers/current');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $emp_raw = curl_exec($ch);
    curl_close($ch);
    $emp = json_decode($emp_raw, true);

    $employer_name = $emp['name'] ?? $emp['companyName'] ?? null;
    $employer_id   = $emp['id']   ?? $emp['employerId']  ?? null;

    $extra = json_encode([
        'employer_id'   => $employer_id,
        'employer_name' => $employer_name,
        'refresh_token' => $refresh_token,
    ]);

    $conn->prepare("INSERT INTO portal_configs (portal,client_id,client_secret,access_token,token_expiry,extra)
                    VALUES ('INDEED',?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE access_token=?,token_expiry=?,extra=?")
         ->execute([
             $cfg['client_id'], $cfg['client_secret'],
             $access_token, $expires_at, $extra,
             $access_token, $expires_at, $extra,
         ]);

    set_flash('success', 'Indeed connected' . ($employer_name ? " as <strong>$employer_name</strong>" : '') . '. You can now post jobs.');
    header('Location: portals.php?tab=indeed'); exit;
} catch (Throwable $e) {
    set_flash('danger', 'Indeed connect failed: ' . $e->getMessage());
    header('Location: portals.php?tab=indeed'); exit;
}
