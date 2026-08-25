<?php
require_once 'config.php';
require_login();
require_role('SUPER_ADMIN', 'HR_ADMIN');

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    set_flash('danger', 'LinkedIn authorization denied: ' . htmlspecialchars($_GET['error_description'] ?? $error));
    header('Location: portals.php?tab=linkedin'); exit;
}

if (!$code || !$state || $state !== ($_SESSION['li_oauth_state'] ?? '')) {
    set_flash('danger', 'Invalid OAuth state. Please try connecting again.');
    header('Location: portals.php?tab=linkedin'); exit;
}
unset($_SESSION['li_oauth_state']);

try {
    $s = $conn->prepare("SELECT * FROM portal_configs WHERE portal='LINKEDIN'");
    $s->execute();
    $li_cfg = $s->fetch();

    if (!$li_cfg || empty($li_cfg['client_id'])) {
        set_flash('danger', 'LinkedIn app credentials not configured. Add Client ID & Secret first.');
        header('Location: portals.php?tab=linkedin'); exit;
    }

    $redirect_uri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                  . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/linkedin_callback.php';

    // Exchange auth code → access token
    $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect_uri,
            'client_id'     => $li_cfg['client_id'],
            'client_secret' => $li_cfg['client_secret'],
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $token_raw = curl_exec($ch);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($token_raw === false) {
        set_flash('danger', 'LinkedIn token request failed (curl): ' . $curl_err);
        header('Location: portals.php?tab=linkedin'); exit;
    }

    $token_data = json_decode($token_raw, true);

    if (empty($token_data['access_token'])) {
        set_flash('danger', 'Token exchange failed: ' . ($token_data['error_description'] ?? substr($token_raw, 0, 200)));
        header('Location: portals.php?tab=linkedin'); exit;
    }

    $access_token = $token_data['access_token'];
    $expires_at   = date('Y-m-d H:i:s', time() + ($token_data['expires_in'] ?? 3600));

    // Fetch LinkedIn profile via OpenID userinfo (requires "Sign In with LinkedIn using OpenID Connect" product)
    $person_id   = null;
    $person_name = '';
    $ch = curl_init('https://api.linkedin.com/v2/userInfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $me_raw = curl_exec($ch);
    curl_close($ch);
    $me = json_decode($me_raw, true);
    if (!empty($me['sub'])) {
        $person_id   = $me['sub'];
        $person_name = trim(($me['given_name'] ?? '') . ' ' . ($me['family_name'] ?? ''));
    }

    // Store token + person URN in extra column
    $extra = json_encode(['person_id' => $person_id, 'person_name' => $person_name]);
    $conn->prepare("INSERT INTO portal_configs (portal,client_id,client_secret,access_token,token_expiry,extra)
                    VALUES ('LINKEDIN',?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE access_token=?,token_expiry=?,extra=?")
         ->execute([
             $li_cfg['client_id'], $li_cfg['client_secret'],
             $access_token, $expires_at, $extra,
             $access_token, $expires_at, $extra,
         ]);

    set_flash('success', "LinkedIn connected" . ($person_name ? " as <strong>$person_name</strong>" : '') . ". You can now post jobs.");
    header('Location: portals.php?tab=linkedin'); exit;
} catch (Throwable $e) {
    set_flash('danger', 'LinkedIn connect failed: ' . $e->getMessage());
    header('Location: portals.php?tab=linkedin'); exit;
}
