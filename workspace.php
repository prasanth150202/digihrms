<?php
// The Workspace board now lives in tasks.php as its 'board' tab, so the two share one
// set of task actions, approvals and DigiOps sync. Kept as a redirect for old links.
require_once 'config.php';
require_login();
header('Location: tasks.php?tab=board');
exit;
