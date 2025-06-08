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
$limit = $_GET['limit'] ?? 50;
$offset = $_GET['offset'] ?? 0;

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
    
    // Get group messages with sender info
    $messages_stmt = $conn->prepare("
        SELECT 
            gm.id,
            gm.group_id,
            gm.sender_id,
            gm.message,
            gm.timestamp,
            u.firstname as sender_name,
            CASE 
                WHEN u.id = ? THEN 'landlord'
                ELSE 'tenant'
            END as sender_role
        FROM group_messages gm
        JOIN users u ON gm.sender_id = u.id
        WHERE gm.group_id = ?
        ORDER BY gm.timestamp ASC
        LIMIT ? OFFSET ?
    ");
    
    $messages_stmt->bind_param("iiii", $landlord_id, $group_id, $limit, $offset);
    $messages_stmt->execute();
    $messages_result = $messages_stmt->get_result();
    
    $messages = [];
    while ($message = $messages_result->fetch_assoc()) {
        $messages[] = [
            'id' => (int)$message['id'],
            'group_id' => (int)$message['group_id'],
            'sender_id' => (int)$message['sender_id'],
            'sender_name' => $message['sender_name'],
            'sender_role' => $message['sender_role'],
            'message' => $message['message'],
            'timestamp' => $message['timestamp']
        ];
    }
    
    // Get total message count
    $count_stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM group_messages 
        WHERE group_id = ?
    ");
    $count_stmt->bind_param("i", $group_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_messages = $count_result->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'message' => 'Messages retrieved successfully',
        'data' => [
            'messages' => $messages,
            'total_count' => (int)$total_messages,
            'limit' => (int)$limit,
            'offset' => (int)$offset,
            'has_more' => ($offset + $limit) < $total_messages
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