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
$user_id = mysqli_real_escape_string($conn, $_GET['user_id']);
$user_role = mysqli_real_escape_string($conn, $_GET['user_role']);

try {
    // Check access based on user role
    if ($user_role === 'tenant') {
        // Check if tenant has access to this group
        $check_access = mysqli_query($conn, "
            SELECT gcc.group_id 
            FROM group_chat_classes gcc
            JOIN user_classes uc ON gcc.class_id = uc.class_id
            WHERE uc.user_id = '$user_id' AND gcc.group_id = '$group_id'
        ");
    } elseif ($user_role === 'landlord') {
        // Check if landlord owns the group
        $check_access = mysqli_query($conn, "
            SELECT id FROM group_chats 
            WHERE id = '$group_id' AND landlord_id = '$user_id'
        ");
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid user role']);
        exit();
    }

    if (!$check_access) {
        throw new Exception('Database error occurred');
    }

    if (mysqli_num_rows($check_access) == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }

    // Fetch group chat information
    $group_query = mysqli_query($conn, "SELECT name FROM group_chats WHERE id = '$group_id'");
    if (!$group_query) {
        throw new Exception('Database error occurred');
    }
    
    $group = mysqli_fetch_assoc($group_query);
    if (!$group) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Group not found']);
        exit();
    }

    // Fetch user name
    $name_result = mysqli_query($conn, "SELECT firstname FROM users WHERE id = '$user_id'");
    if (!$name_result) {
        throw new Exception('Database error occurred');
    }
    
    $user_data = mysqli_fetch_assoc($name_result);
    $user_name = $user_data['firstname'] ?? 'Unknown';

    // Fetch group members
    $members_query = mysqli_query($conn, "
        SELECT u.id, u.firstname, u.email, 'tenant' as role 
        FROM users u 
        JOIN user_classes uc ON u.id = uc.user_id
        JOIN group_chat_classes gcc ON uc.class_id = gcc.class_id
        WHERE gcc.group_id = '$group_id'

        UNION

        SELECT u.id, u.firstname, u.email, 'landlord' as role
        FROM users u
        JOIN group_chats gc ON u.id = gc.landlord_id
        WHERE gc.id = '$group_id'
    ");

    if (!$members_query) {
        throw new Exception('Database error occurred');
    }

    $members = [];
    while ($member = mysqli_fetch_assoc($members_query)) {
        $members[] = [
            'id' => $member['id'],
            'firstname' => $member['firstname'],
            'email' => $member['email'],
            'role' => $member['role']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'group' => [
                'id' => $group_id,
                'name' => $group['name']
            ],
            'user' => [
                'id' => $user_id,
                'name' => $user_name,
                'role' => $user_role
            ],
            'members' => $members,
            'member_count' => count($members)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

// Close connection
mysqli_close($conn);
?>