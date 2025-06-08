<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['tenant_id']) || !isset($input['class_code'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$tenant_id = mysqli_real_escape_string($conn, $input['tenant_id']);
$class_code = mysqli_real_escape_string($conn, $input['class_code']);

try {
    // Check if class exists
    $class_check = mysqli_query($conn, "SELECT * FROM classes WHERE class_code = '$class_code'");
    
    if (!$class_check) {
        throw new Exception('Database error occurred');
    }
    
    if (mysqli_num_rows($class_check) === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Class with that code doesn\'t exist']);
        exit();
    }
    
    $class = mysqli_fetch_assoc($class_check);
    $class_id = $class['id'];
    
    // Check if tenant already belongs to a class
    $already_joined = mysqli_query($conn, "SELECT * FROM class_members WHERE tenant_id = '$tenant_id'");
    
    if (!$already_joined) {
        throw new Exception('Database error occurred');
    }
    
    if (mysqli_num_rows($already_joined) > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'You\'ve already joined a class']);
        exit();
    }
    
    // Begin transaction
    mysqli_autocommit($conn, false);
    
    // Insert into class_members
    $join_class_query = "INSERT INTO class_members (class_id, tenant_id) VALUES ('$class_id', '$tenant_id')";
    $insert_member = mysqli_query($conn, $join_class_query);
    
    // Insert into user_classes so their name appears in view_bills
    $insert_user_class = mysqli_query($conn, "INSERT INTO user_classes (class_id, user_id) VALUES ('$class_id', '$tenant_id')");
    
    if ($insert_member && $insert_user_class) {
        // Commit transaction
        mysqli_commit($conn);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Successfully joined class',
            'class_info' => [
                'class_id' => $class_id,
                'class_name' => $class['class_name'] ?? null,
                'class_code' => $class_code
            ]
        ]);
    } else {
        // Rollback transaction
        mysqli_rollback($conn);
        throw new Exception('Error joining class');
    }
    
} catch (Exception $e) {
    // Rollback transaction if it was started
    mysqli_rollback($conn);
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error joining class. Try again later.']);
}

// Close connection
mysqli_close($conn);
?>