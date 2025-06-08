<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Only POST method allowed', null, 405);
}

/**
 * Generate unique class code
 */
function generateClassCode($conn, $max_attempts = 10) {
    $characters = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $attempts = 0;
    
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        // Check if code already exists
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) as count FROM classes WHERE class_code = ?");
        if (!$stmt) {
            throw new Exception('Failed to prepare code check query');
        }
        
        mysqli_stmt_bind_param($stmt, "s", $code);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        $attempts++;
        
        if (intval($row['count']) === 0) {
            return $code; // Found unique code
        }
        
    } while ($attempts < $max_attempts);
    
    throw new Exception('Unable to generate unique class code after ' . $max_attempts . ' attempts');
}

/**
 * Handle class creation
 */
function handleCreateClass() {
    global $conn;
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, 'Invalid JSON input', null, 400);
    }
    
    // Validate required fields
    $required_fields = ['landlord_id', 'class_name'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty(trim($input[$field]))) {
            sendResponse(false, "Field '$field' is required", null, 400);
        }
    }
    
    $landlord_id = intval($input['landlord_id']);
    $class_name = trim($input['class_name']);
    
    // Validate inputs
    if (!is_numeric($landlord_id) || $landlord_id <= 0) {
        sendResponse(false, 'Invalid landlord ID', null, 400);
    }
    
    if (strlen($class_name) < 2 || strlen($class_name) > 100) {
        sendResponse(false, 'Class name must be between 2 and 100 characters', null, 400);
    }
    
    // Check for potentially harmful content
    if (preg_match('/[<>"\']/', $class_name)) {
        sendResponse(false, 'Class name contains invalid characters', null, 400);
    }
    
    try {
        // Check if class name already exists for this landlord
        $name_check_stmt = mysqli_prepare($conn, 
            "SELECT COUNT(*) as count FROM classes WHERE landlord_id = ? AND class_name = ?"
        );
        
        if (!$name_check_stmt) {
            throw new Exception('Failed to prepare name check query');
        }
        
        mysqli_stmt_bind_param($name_check_stmt, "is", $landlord_id, $class_name);
        mysqli_stmt_execute($name_check_stmt);
        $name_result = mysqli_stmt_get_result($name_check_stmt);
        $name_row = mysqli_fetch_assoc($name_result);
        mysqli_stmt_close($name_check_stmt);
        
        if (intval($name_row['count']) > 0) {
            sendResponse(false, 'A class with this name already exists', null, 409);
        }
        
        // Generate unique class code
        $class_code = generateClassCode($conn);
        
        // Insert new class
        $stmt = mysqli_prepare($conn, 
            "INSERT INTO classes (landlord_id, class_name, class_code, created_at) VALUES (?, ?, ?, NOW())"
        );
        
        if (!$stmt) {
            throw new Exception('Failed to prepare class insertion query');
        }
        
        mysqli_stmt_bind_param($stmt, "iss", $landlord_id, $class_name, $class_code);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to create class: ' . mysqli_error($conn));
        }
        
        $class_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        
        // Return success response
        sendResponse(true, 'Class created successfully', [
            'class_id' => intval($class_id),
            'class_name' => $class_name,
            'class_code' => $class_code,
            'landlord_id' => $landlord_id,
            'created_at' => date('Y-m-d H:i:s')
        ], 201);
        
    } catch (Exception $e) {
        sendResponse(false, $e->getMessage(), null, 500);
    }
}

// Handle the request
handleCreateClass();

// Close database connection
mysqli_close($conn);
?>