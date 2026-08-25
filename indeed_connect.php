<?php
require_once 'config.php';
require_login();
require_role('SUPER_ADMIN', 'HR_ADMIN');

$s = $conn->prepare("SELECT * FROM portal_configs WHERE portal='INDEED'");
$s->execute();
$in = $s->fetch();

if (!$in || empty($in['client_id'])) {
    set_flash('danger', 'Save your Indeed Client ID & Secret first.');
    header('Location: portals.php?tab=indeed'); exit;
}

$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
          . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
$in_redirect = $base_url . 'indeed_callback.php';

$state = bin2hex(random_bytes(8));
$_SESSION['indeed_oauth_state'] = $state;

$in_auth_url = 'https://secure.indeed.com/oauth/v2/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $in['client_id'],
    'redirect_uri'  => $in_redirect,
    'state'         => $state,
    'scope'         => 'employer_access',
]);

header('Location: ' . $in_auth_url); exit;
