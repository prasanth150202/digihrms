<?php
// push_helper.php — send FCM push to an HRMS user (by users.id)

define('HRMS_FCM_PROJECT_ID',      'digifyce-internal');
define('HRMS_FCM_SERVICE_ACCOUNT', __DIR__ . '/../../diigiops/firebase-service-account.json');

function _hrmsFcmAccessToken(): ?string {
    static $cached = null;
    static $expiry  = 0;
    if ($cached && time() < $expiry - 60) return $cached;

    $path = HRMS_FCM_SERVICE_ACCOUNT;
    if (!file_exists($path)) return null;
    $sa = json_decode(file_get_contents($path), true);
    if (!$sa) return null;

    $now    = time();
    $header = rtrim(strtr(base64_encode(json_encode(['alg'=>'RS256','typ'=>'JWT'])), '+/', '-_'), '=');
    $claim  = rtrim(strtr(base64_encode(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ])), '+/', '-_'), '=');
    $sig = '';
    openssl_sign("$header.$claim", $sig, $sa['private_key'], 'SHA256');
    $sig = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    $jwt = "$header.$claim.$sig";

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ])]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($resp['access_token'])) {
        $cached = $resp['access_token'];
        $expiry = $now + (int)($resp['expires_in'] ?? 3600);
        return $cached;
    }
    return null;
}

function hrmsPushUser(PDO $conn, int $userId, string $title, string $body, string $link = ''): void {
    try {
        $s = $conn->prepare("SELECT fcm_token FROM push_subscriptions WHERE user_id = ?");
        $s->execute([$userId]);
        $tokens = $s->fetchAll(PDO::FETCH_COLUMN);
        if (!$tokens) return;

        $accessToken = _hrmsFcmAccessToken();
        if (!$accessToken) return;

        $baseUrl = 'http://localhost/hrms/php_implementation';
        $url     = 'https://fcm.googleapis.com/v1/projects/' . HRMS_FCM_PROJECT_ID . '/messages:send';

        foreach ($tokens as $token) {
            $payload = json_encode([
                'message' => [
                    'token'        => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'webpush'      => [
                        'notification' => [
                            'icon'         => 'https://digifyce.com/favicon.ico',
                            'click_action' => $baseUrl . $link,
                        ],
                        'fcm_options'  => ['link' => $baseUrl . $link],
                    ],
                ],
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => $payload,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 404 || $code === 410) {
                $conn->prepare("DELETE FROM push_subscriptions WHERE fcm_token = ?")->execute([$token]);
            }
        }
    } catch (Exception $e) {
        error_log('hrmsPushUser error: ' . $e->getMessage());
    }
}
