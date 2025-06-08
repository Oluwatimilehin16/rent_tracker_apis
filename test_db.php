<?php
header('Content-Type: application/json');

// Test environment variables
$env_vars = [
    'DB_HOST' => getenv('DB_HOST'),
    'DB_USER' => getenv('DB_USER'),
    'DB_PASS' => getenv('DB_PASS') ? '***SET***' : null,
    'DB_NAME' => getenv('DB_NAME'),
    'DB_PORT' => getenv('DB_PORT')
];

$response = [
    'environment_variables' => $env_vars,
    'connection_status' => 'failed',
    'error' => null
];

try {
    include 'config.php';
    
    // Test a simple query
    $result = $conn->query("SELECT 1 as test");
    if ($result) {
        $response['connection_status'] = 'success';
        $response['test_query'] = 'passed';
    }
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>