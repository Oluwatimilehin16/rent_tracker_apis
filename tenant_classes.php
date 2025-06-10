<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

// Get tenant_id from request
$tenant_id = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tenant_id = $_GET['tenant_id'] ?? null;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $tenant_id = $input['tenant_id'] ?? null;
}

if (!$tenant_id) {
    http_response_code(400);
    echo json_encode(['error' => 'tenant_id is required']);
    exit;
}

try {
    // Fetch class IDs the tenant belongs to
    $tenant_class_ids = [];
    $stmt = $conn->prepare("SELECT class_id FROM user_classes WHERE user_id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $tenant_class_ids[] = (int)$row['class_id'];
    }
    $stmt->close();
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'class_ids' => $tenant_class_ids,
            'has_classes' => !empty($tenant_class_ids)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}

mysqli_close($conn);
?>