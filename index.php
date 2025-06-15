<?php
// Only respond to UptimeRobot or browser root access
if ($_SERVER['REQUEST_URI'] === '/' || $_SERVER['REQUEST_URI'] === '') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit();
}

// Continue with the rest of your app...
// If you want to forbid other access, you can do that here.
// But make sure the root `/` works first.
?>
