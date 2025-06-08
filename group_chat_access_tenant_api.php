<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

// Get request data
$landlord_id = $_GET['landlord_id'] ?? '';
$group_id = $_GET['group_id'] ?? '';

// Validate required fields
if (empty($landlord_id) || empty($group_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'landlord_id and group_id are required'
    ]);
    exit();
}

try {
    // Verify landlord owns this group chat
    $stmt = $conn->prepare("
        SELECT id, name, created_at 
        FROM group_chats 
        WHERE id = ? AND landlord_id = ?
    ");
    
    $stmt->bind_param("ii", $group_id, $landlord_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Group not found or you do not own this group.'
        ]);
        exit();
    }
    
    $group = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'message' => 'Access granted',
        'data' => [
            'group' => $group,
            'access_granted' => true
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>