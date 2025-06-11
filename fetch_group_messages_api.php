<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

// Check database connection
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Validate required parameters
if (!isset($_GET['group_id']) || !isset($_GET['user_id']) || !isset($_GET['user_role'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$group_id = intval($_GET['group_id']);
$user_id = intval($_GET['user_id']); // Fixed: make this integer
$user_role = mysqli_real_escape_string($conn, $_GET['user_role']);
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

// Validate that IDs are positive integers
if ($group_id <= 0 || $user_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid ID parameters']);
    exit();
}

// Validate limit and offset
if ($limit <= 0 || $limit > 100) $limit = 50;
if ($offset < 0) $offset = 0;

try {
    // First verify user has access to this group (same logic as access API)
    if ($user_role === 'tenant') {
        $check_access = mysqli_query($conn, "
            SELECT gcc.group_id 
            FROM group_chat_classes gcc
            JOIN user_classes uc ON gcc.class_id = uc.class_id
            WHERE uc.user_id = $user_id AND gcc.group_id = $group_id
        ");
    } elseif ($user_role === 'landlord') {
        $check_access = mysqli_query($conn, "
            SELECT id FROM group_chats 
            WHERE id = $group_id AND landlord_id = $user_id
        ");
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid user role']);
        exit();
    }

    if (!$check_access) {
        throw new Exception('Access check query failed: ' . mysqli_error($conn));
    }

    if (mysqli_num_rows($check_access) == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }

    // Fetch messages for the group
    $messages_query = mysqli_query($conn, "
        SELECT 
            gm.id,
            gm.sender_id,
            gm.message,
            gm.timestamp,
            u.firstname as sender_name,
            CASE 
                WHEN gc.landlord_id = gm.sender_id THEN 'landlord'
                ELSE 'tenant'
            END as sender_role
        FROM group_messages gm
        JOIN users u ON gm.sender_id = u.id
        JOIN group_chats gc ON gm.group_id = gc.id
        WHERE gm.group_id = $group_id
        ORDER BY gm.timestamp ASC
        LIMIT $limit OFFSET $offset
    ");

    if (!$messages_query) {
        throw new Exception('Messages query failed: ' . mysqli_error($conn));
    }

    $messages = [];
    while ($message = mysqli_fetch_assoc($messages_query)) {
        $messages[] = [
            'id' => intval($message['id']),
            'sender_id' => intval($message['sender_id']),
            'sender_name' => $message['sender_name'],
            'sender_role' => $message['sender_role'],
            'message' => $message['message'],
            'timestamp' => $message['timestamp']
        ];
    }

    // Get total message count for pagination
    $count_query = mysqli_query($conn, "
        SELECT COUNT(*) as total 
        FROM group_messages 
        WHERE group_id = $group_id
    ");
    
    if (!$count_query) {
        throw new Exception('Count query failed: ' . mysqli_error($conn));
    }
    
    $total_messages = 0;
    if ($count_query) {
        $count_result = mysqli_fetch_assoc($count_query);
        $total_messages = intval($count_result['total']);
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'messages' => $messages,
            'pagination' => [
                'total' => $total_messages,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total_messages
            ]
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    // For debugging - remove in production:
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    // For production:
    // echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

// Close connection
if ($conn) {
    mysqli_close($conn);
}
?>