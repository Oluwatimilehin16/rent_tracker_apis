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
$group_id = $_GET['group_id'] ?? '';
$landlord_id = $_GET['landlord_id'] ?? '';

// Validate required fields
if (empty($group_id) || empty($landlord_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'group_id and landlord_id are required'
    ]);
    exit();
}

try {
    // First verify access to the group
    $access_stmt = $conn->prepare("
        SELECT id FROM group_chats 
        WHERE id = ? AND landlord_id = ?
    ");
    
    $access_stmt->bind_param("ii", $group_id, $landlord_id);
    $access_stmt->execute();
    $access_result = $access_stmt->get_result();
    
    if ($access_result->num_rows === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied'
        ]);
        exit();
    }
    
    // Get group members (tenants + landlord)
    $members_stmt = $conn->prepare("
        SELECT u.id, u.firstname, u.email, 'tenant' as role 
        FROM users u 
        JOIN user_classes uc ON u.id = uc.user_id
        JOIN group_chat_classes gcc ON uc.class_id = gcc.class_id
        WHERE gcc.group_id = ?

        UNION

        SELECT u.id, u.firstname, u.email, 'landlord' as role
        FROM users u
        WHERE u.id = ?
    ");
    
    $members_stmt->bind_param("ii", $group_id, $landlord_id);
    $members_stmt->execute();
    $members_result = $members_stmt->get_result();
    
    $members = [];
    while ($member = $members_result->fetch_assoc()) {
        $members[] = [
            'id' => (int)$member['id'],
            'firstname' => $member['firstname'],
            'email' => $member['email'],
            'role' => $member['role'],
            'avatar' => strtoupper(substr($member['firstname'], 0, 1))
        ];
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Members retrieved successfully',
        'data' => [
            'members' => $members,
            'total_count' => count($members)
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