<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate required parameter
if (!isset($_GET['landlord_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing landlord_id parameter']);
    exit;
}

$landlord_id = (int)$_GET['landlord_id'];

// Fetch tenants under the landlord
$tenants_query = "
    SELECT u.id, u.firstname 
    FROM users u 
    INNER JOIN user_classes uc ON u.id = uc.user_id 
    INNER JOIN classes c ON uc.class_id = c.id 
    WHERE c.landlord_id = ?
    GROUP BY u.id
    ORDER BY u.firstname
";

$stmt = $conn->prepare($tenants_query);
$stmt->bind_param("i", $landlord_id);
$stmt->execute();
$result = $stmt->get_result();

$tenants = [];
while ($tenant = $result->fetch_assoc()) {
    $tenants[] = $tenant;
}

echo json_encode([
    'success' => true,
    'data' => $tenants
]);

$stmt->close();
$conn->close();
?>