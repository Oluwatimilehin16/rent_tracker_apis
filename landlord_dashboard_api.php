<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

function sendResponse($success, $data = null, $message = null, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit();
}

// Validate landlord_id
if (!isset($_GET['landlord_id']) || !is_numeric($_GET['landlord_id'])) {
    sendResponse(false, null, 'Invalid landlord ID', 400);
}

$landlord_id = intval($_GET['landlord_id']);

try {
    // Get landlord details
    $landlord_query = "SELECT firstname FROM users WHERE id = ?";
    $stmt = $conn->prepare($landlord_query);
    $stmt->bind_param("i", $landlord_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendResponse(false, null, 'Landlord not found', 404);
    }
    
    $landlord = $result->fetch_assoc();

    // Get group chats with details
    $group_query = "SELECT id, name, created_at FROM group_chats WHERE landlord_id = ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($group_query);
    $stmt->bind_param("i", $landlord_id);
    $stmt->execute();
    $group_chats_result = $stmt->get_result();
    
    $group_chats = [];
    while ($row = $group_chats_result->fetch_assoc()) {
        $group_id = $row['id'];

        // Get members count for this group
        $member_stmt = $conn->prepare("
            SELECT COUNT(DISTINCT uc.user_id) AS member_count
            FROM group_chat_classes gcc
            JOIN user_classes uc ON gcc.class_id = uc.class_id
            WHERE gcc.group_id = ?
        ");
        $member_stmt->bind_param("i", $group_id);
        $member_stmt->execute();
        $member_result = $member_stmt->get_result();
        $member_count = $member_result->fetch_assoc()['member_count'] ?? 0;

        // Get message count for this group
        $message_stmt = $conn->prepare("
            SELECT COUNT(*) AS msg_count
            FROM group_chat_messages
            WHERE group_id = ?
        ");
        $message_stmt->bind_param("i", $group_id);
        $message_stmt->execute();
        $msg_result = $message_stmt->get_result();
        $message_count = $msg_result->fetch_assoc()['msg_count'] ?? 0;

        $group_chats[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'created_at' => $row['created_at'],
            'formatted_date' => date('M d, Y', strtotime($row['created_at'])),
            'member_count' => $member_count,
            'message_count' => $message_count
        ];
    }

    // Get total statistics
    $total_group_chats = count($group_chats);

    // Get total tenants across all landlord's classes
    $tenant_query = "
        SELECT COUNT(DISTINCT uc.user_id) AS tenant_count
        FROM group_chat_classes gcc
        JOIN user_classes uc ON gcc.class_id = uc.class_id
        JOIN group_chats gc ON gcc.group_id = gc.id
        WHERE gc.landlord_id = ?
    ";
    $stmt = $conn->prepare($tenant_query);
    $stmt->bind_param("i", $landlord_id);
    $stmt->execute();
    $tenant_result = $stmt->get_result();
    $tenant_data = $tenant_result->fetch_assoc();
    $total_tenants = $tenant_data['tenant_count'] ?? 0;

    // Get total messages in landlord's group chats
    $message_query = "
        SELECT COUNT(*) AS message_count
        FROM group_chat_messages gcm
        JOIN group_chats gc ON gcm.group_id = gc.id
        WHERE gc.landlord_id = ?
    ";
    $stmt = $conn->prepare($message_query);
    $stmt->bind_param("i", $landlord_id);
    $stmt->execute();
    $message_result = $stmt->get_result();
    $message_data = $message_result->fetch_assoc();
    $total_messages = $message_data['message_count'] ?? 0;

    // Return all data
    sendResponse(true, [
        'landlord' => $landlord,
        'group_chats' => $group_chats,
        'statistics' => [
            'total_group_chats' => $total_group_chats,
            'total_tenants' => $total_tenants,
            'total_messages' => $total_messages
        ]
    ]);

} catch (Exception $e) {
    error_log("Landlord Dashboard API Error: " . $e->getMessage());
    sendResponse(false, null, 'Internal server error', 500);
}
?>