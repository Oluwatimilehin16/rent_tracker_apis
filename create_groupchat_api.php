<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include 'config.php';

// Response helper function
function sendResponse($success, $message, $data = null, $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// Validate database connection
if (!$conn) {
    sendResponse(false, 'Database connection failed', null, 500);
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGetClasses();
        break;
    case 'POST':
        handleCreateGroupChat();
        break;
    default:
        sendResponse(false, 'Method not allowed', null, 405);
}

/**
 * Handle GET request - Fetch landlord's classes
 */
function handleGetClasses() {
    global $conn;
    
    // Get landlord_id from query parameter or header
    $landlord_id = null;
    
    // Check query parameter first
    if (isset($_GET['landlord_id'])) {
        $landlord_id = intval($_GET['landlord_id']);
    }
    
    // Check Authorization header as alternative
    if (!$landlord_id && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $landlord_id = intval($matches[1]); // Simple token = landlord_id for now
        }
    }
    
    if (!$landlord_id) {
        sendResponse(false, 'Landlord ID is required', null, 400);
    }
    if (!$landlord_id && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $landlord_id = intval($matches[1]); // Simple token = landlord_id for now
        }
    }
    
    if (!$landlord_id) {
        sendResponse(false, 'Landlord ID is required', null, 400);
    }
    
    // Validate landlord_id is numeric and positive
    if (!is_numeric($landlord_id) || $landlord_id <= 0) {
        sendResponse(false, 'Invalid landlord ID', null, 400);
    }
    
    try {
        // Use prepared statement to prevent SQL injection
        $stmt = mysqli_prepare($conn, "SELECT id, class_name FROM classes WHERE landlord_id = ? ORDER BY class_name ASC");
        
        if (!$stmt) {
            sendResponse(false, 'Database query preparation failed', null, 500);
        }
        
        mysqli_stmt_bind_param($stmt, "i", $landlord_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $classes = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $classes[] = [
                'id' => intval($row['id']),
                'class_name' => $row['class_name']
            ];
        }
        
        mysqli_stmt_close($stmt);
        
        sendResponse(true, 'Classes retrieved successfully', [
            'classes' => $classes,
            'total_count' => count($classes)
        ]);
        
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving classes: ' . $e->getMessage(), null, 500);
    }
}

/**
 * Handle POST request - Create group chat
 */
function handleCreateGroupChat() {
    global $conn;
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, 'Invalid JSON input', null, 400);
    }
    
    // Validate required fields
    $required_fields = ['landlord_id', 'group_name', 'class_ids'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            sendResponse(false, "Field '$field' is required", null, 400);
        }
    }
    
    $landlord_id = intval($input['landlord_id']);
    $group_name = trim($input['group_name']);
    $class_ids = $input['class_ids'];
    
    // Validate inputs
    if (!is_numeric($landlord_id) || $landlord_id <= 0) {
        sendResponse(false, 'Invalid landlord ID', null, 400);
    }
    
    if (strlen($group_name) < 2 || strlen($group_name) > 100) {
        sendResponse(false, 'Group name must be between 2 and 100 characters', null, 400);
    }
    
    if (!is_array($class_ids) || empty($class_ids)) {
        sendResponse(false, 'At least one class must be selected', null, 400);
    }
    
    // Validate all class_ids are numeric
    foreach ($class_ids as $class_id) {
        if (!is_numeric($class_id) || intval($class_id) <= 0) {
            sendResponse(false, 'Invalid class ID provided', null, 400);
        }
    }
    
    try {
        // Start transaction
        mysqli_autocommit($conn, false);
        
        // Verify that all selected classes belong to the landlord
        $class_ids_str = implode(',', array_map('intval', $class_ids));
        $verify_stmt = mysqli_prepare($conn, 
            "SELECT COUNT(*) as count FROM classes WHERE landlord_id = ? AND id IN ($class_ids_str)"
        );
        
        if (!$verify_stmt) {
            throw new Exception('Failed to prepare class verification query');
        }
        
        mysqli_stmt_bind_param($verify_stmt, "i", $landlord_id);
        mysqli_stmt_execute($verify_stmt);
        $verify_result = mysqli_stmt_get_result($verify_stmt);
        $verify_row = mysqli_fetch_assoc($verify_result);
        mysqli_stmt_close($verify_stmt);
        
        if (intval($verify_row['count']) !== count($class_ids)) {
            throw new Exception('One or more selected classes do not belong to this landlord');
        }
        
        // Check if group name already exists for this landlord
        $name_check_stmt = mysqli_prepare($conn, 
            "SELECT COUNT(*) as count FROM group_chats WHERE landlord_id = ? AND name = ?"
        );
        
        if (!$name_check_stmt) {
            throw new Exception('Failed to prepare name check query');
        }
        
        mysqli_stmt_bind_param($name_check_stmt, "is", $landlord_id, $group_name);
        mysqli_stmt_execute($name_check_stmt);
        $name_result = mysqli_stmt_get_result($name_check_stmt);
        $name_row = mysqli_fetch_assoc($name_result);
        mysqli_stmt_close($name_check_stmt);
        
        if (intval($name_row['count']) > 0) {
            throw new Exception('A group chat with this name already exists');
        }
        
        // Insert into group_chats table (removed created_at)
        $group_stmt = mysqli_prepare($conn, 
            "INSERT INTO group_chats (landlord_id, name) VALUES (?, ?)"
        );
        
        if (!$group_stmt) {
            throw new Exception('Failed to prepare group chat insertion');
        }
        
        mysqli_stmt_bind_param($group_stmt, "is", $landlord_id, $group_name);
        
        if (!mysqli_stmt_execute($group_stmt)) {
            throw new Exception('Failed to create group chat');
        }
        
        $group_id = mysqli_insert_id($conn);
        mysqli_stmt_close($group_stmt);
        
        // Insert selected classes into group_chat_classes (removed created_at)
        $class_stmt = mysqli_prepare($conn, 
            "INSERT INTO group_chat_classes (group_id, class_id) VALUES (?, ?)"
        );
        
        if (!$class_stmt) {
            throw new Exception('Failed to prepare class-group association');
        }
        
        $inserted_classes = [];
        foreach ($class_ids as $class_id) {
            $class_id = intval($class_id);
            mysqli_stmt_bind_param($class_stmt, "ii", $group_id, $class_id);
            
            if (!mysqli_stmt_execute($class_stmt)) {
                throw new Exception("Failed to associate class ID $class_id with group");
            }
            
            $inserted_classes[] = $class_id;
        }
        
        mysqli_stmt_close($class_stmt);
        
        // Commit transaction
        mysqli_commit($conn);
        mysqli_autocommit($conn, true);
        
        // Return success response with created group details (removed created_at)
        sendResponse(true, 'Group chat created successfully', [
            'group_id' => intval($group_id),
            'group_name' => $group_name,
            'landlord_id' => $landlord_id,
            'associated_classes' => $inserted_classes,
            'total_classes' => count($inserted_classes)
        ], 201);
        
    } catch (Exception $e) {
        // Rollback transaction
        mysqli_rollback($conn);
        mysqli_autocommit($conn, true);
        
        sendResponse(false, $e->getMessage(), null, 500);
    }
}

// Close database connection
mysqli_close($conn);
?>