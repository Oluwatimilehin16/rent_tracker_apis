<?php
// groupchat_api.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

// Get the action and method
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Get JSON input for POST/PUT requests
$input = json_decode(file_get_contents('php://input'), true);

// Response helper function
function sendResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

// Verify group ownership
function verifyGroupOwnership($conn, $group_id, $landlord_id) {
    $query = "SELECT id, name FROM group_chats WHERE id = ? AND landlord_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $group_id, $landlord_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendResponse(false, null, 'Group chat not found or access denied', 404);
    }
    
    return $result->fetch_assoc();
}

switch ($action) {
    case 'get_group_details':
        if ($method !== 'GET') {
            sendResponse(false, null, 'Method not allowed', 405);
        }
        
        $group_id = $_GET['group_id'] ?? 0;
        $landlord_id = $_GET['landlord_id'] ?? 0;
        
        if (!$group_id || !$landlord_id) {
            sendResponse(false, null, 'Missing required parameters', 400);
        }
        
        $group_chat = verifyGroupOwnership($conn, $group_id, $landlord_id);
        
        // Get current members (classes) in this group
        $current_members_query = "
            SELECT gcc.class_id, c.class_name, COUNT(uc.user_id) as member_count
            FROM group_chat_classes gcc
            JOIN classes c ON gcc.class_id = c.id
            LEFT JOIN user_classes uc ON c.id = uc.class_id
            WHERE gcc.group_id = ?
            GROUP BY gcc.class_id, c.class_name
            ORDER BY c.class_name
        ";
        $stmt = $conn->prepare($current_members_query);
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $current_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get available classes
        $available_classes_query = "
            SELECT c.id, c.class_name, COUNT(uc.user_id) as member_count
            FROM classes c
            LEFT JOIN user_classes uc ON c.id = uc.class_id
            WHERE c.landlord_id = ? 
            AND c.id NOT IN (
                SELECT class_id FROM group_chat_classes WHERE group_id = ?
            )
            GROUP BY c.id, c.class_name
            ORDER BY c.class_name
        ";
        $stmt = $conn->prepare($available_classes_query);
        $stmt->bind_param("ii", $landlord_id, $group_id);
        $stmt->execute();
        $available_classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Get group stats
        $stats_query = "
            SELECT 
                (SELECT COUNT(*) FROM group_chat_messages WHERE group_id = ?) as message_count,
                (SELECT COUNT(DISTINCT uc.user_id) 
                 FROM group_chat_classes gcc 
                 JOIN user_classes uc ON gcc.class_id = uc.class_id 
                 WHERE gcc.group_id = ?) as total_members
        ";
        $stmt = $conn->prepare($stats_query);
        $stmt->bind_param("ii", $group_id, $group_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        
        sendResponse(true, [
            'group_chat' => $group_chat,
            'current_members' => $current_members,
            'available_classes' => $available_classes,
            'stats' => $stats
        ]);
        break;
        
    case 'add_class':
        if ($method !== 'POST') {
            sendResponse(false, null, 'Method not allowed', 405);
        }
        
        $group_id = $input['group_id'] ?? 0;
        $class_id = $input['class_id'] ?? 0;
        $landlord_id = $input['landlord_id'] ?? 0;
        
        if (!$group_id || !$class_id || !$landlord_id) {
            sendResponse(false, null, 'Missing required parameters', 400);
        }
        
        // Verify group ownership
        verifyGroupOwnership($conn, $group_id, $landlord_id);
        
        // Check if class already exists in this group
        $check_existing = "SELECT id FROM group_chat_classes WHERE group_id = ? AND class_id = ?";
        $stmt = $conn->prepare($check_existing);
        $stmt->bind_param("ii", $group_id, $class_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            sendResponse(false, null, 'This class is already added to the group chat', 409);
        }
        
        // Verify the class belongs to the landlord
        $verify_class = "SELECT id FROM classes WHERE id = ? AND landlord_id = ?";
        $stmt = $conn->prepare($verify_class);
        $stmt->bind_param("ii", $class_id, $landlord_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows === 0) {
            sendResponse(false, null, 'Class not found or access denied', 404);
        }
        
        // Add class to group chat
        $add_class = "INSERT INTO group_chat_classes (group_id, class_id) VALUES (?, ?)";
        $stmt = $conn->prepare($add_class);
        $stmt->bind_param("ii", $group_id, $class_id);
        
        if ($stmt->execute()) {
            sendResponse(true, null, 'Class added to group chat successfully');
        } else {
            sendResponse(false, null, 'Error adding class to group chat', 500);
        }
        break;
        
    case 'remove_class':
        if ($method !== 'DELETE') {
            sendResponse(false, null, 'Method not allowed', 405);
        }
        
        $group_id = $input['group_id'] ?? 0;
        $class_id = $input['class_id'] ?? 0;
        $landlord_id = $input['landlord_id'] ?? 0;
        
        if (!$group_id || !$class_id || !$landlord_id) {
            sendResponse(false, null, 'Missing required parameters', 400);
        }
        
        // Verify group ownership
        verifyGroupOwnership($conn, $group_id, $landlord_id);
        
        // Remove class from group chat
        $remove_class = "DELETE FROM group_chat_classes WHERE group_id = ? AND class_id = ?";
        $stmt = $conn->prepare($remove_class);
        $stmt->bind_param("ii", $group_id, $class_id);
        
        if ($stmt->execute()) {
            sendResponse(true, null, 'Class removed from group chat successfully');
        } else {
            sendResponse(false, null, 'Error removing class from group chat', 500);
        }
        break;
        
    case 'delete_group':
        if ($method !== 'DELETE') {
            sendResponse(false, null, 'Method not allowed', 405);
        }
        
        $group_id = $input['group_id'] ?? 0;
        $landlord_id = $input['landlord_id'] ?? 0;
        
        if (!$group_id || !$landlord_id) {
            sendResponse(false, null, 'Missing required parameters', 400);
        }
        
        // Verify group ownership
        verifyGroupOwnership($conn, $group_id, $landlord_id);
        
        $conn->begin_transaction();
        
        try {
            // Delete group chat messages
            $delete_messages = "DELETE FROM group_chat_messages WHERE group_id = ?";
            $stmt = $conn->prepare($delete_messages);
            $stmt->bind_param("i", $group_id);
            $stmt->execute();
            
            // Delete group chat classes associations
            $delete_classes = "DELETE FROM group_chat_classes WHERE group_id = ?";
            $stmt = $conn->prepare($delete_classes);
            $stmt->bind_param("i", $group_id);
            $stmt->execute();
            
            // Delete the group chat itself
            $delete_group = "DELETE FROM group_chats WHERE id = ?";
            $stmt = $conn->prepare($delete_group);
            $stmt->bind_param("i", $group_id);
            $stmt->execute();
            
            $conn->commit();
            sendResponse(true, null, 'Group chat deleted successfully');
            
        } catch (Exception $e) {
            $conn->rollback();
            sendResponse(false, null, 'Error deleting group chat: ' . $e->getMessage(), 500);
        }
        break;
        
    default:
        sendResponse(false, null, 'Invalid action', 400);
}
?>