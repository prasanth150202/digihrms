<?php
require_once 'config.php';
require_login();
require_role('SUPER_ADMIN', 'HR_ADMIN');

$s = $conn->prepare("SELECT * FROM portal_configs WHERE portal='LINKEDIN'");
$s->execute();
$li = $s->fetch();

if (!$li || empty($li['client_id'])) {
    set_flash('danger', 'Save your LinkedIn Client ID & Secret first.');
    header('Location: portals.php?tab=linkedin'); exit;
}

$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
          . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
$li_redirect = $base_url . 'linkedin_callback.php';

$state = bin2hex(random_bytes(8));
$_SESSION['li_oauth_state'] = $state;

$li_auth_url = 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $li['client_id'],
    'redirect_uri'  => $li_redirect,
    'state'         => $state,
    'scope'         => 'w_member_social',
]);

header('Location: ' . $li_auth_url); exit;
