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

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Only POST requests are accepted.'
    ]);
    exit();
}

include 'config.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate landlord_id is provided
    if (empty($input['landlord_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'landlord_id is required.',
        ]);
        exit();
    }
    
    $landlord_id = mysqli_real_escape_string($conn, $input['landlord_id']);
    
    // Verify landlord exists (optional security check)
    $landlord_check = mysqli_query($conn, "SELECT id FROM landlords WHERE id = '$landlord_id'");
    if (mysqli_num_rows($landlord_check) === 0) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid landlord ID.'
        ]);
        exit();
    }
    
    // Get all classes created by the landlord
    $classes_query = "SELECT id, class_name, class_code, created_at, 
                     (SELECT COUNT(*) FROM tenants WHERE class_id = classes.id) as tenant_count 
                     FROM classes 
                     WHERE landlord_id = '$landlord_id' 
                     ORDER BY created_at DESC";
    
    $classes_result = mysqli_query($conn, $classes_query);
    
    if (!$classes_result) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred.',
            'error' => mysqli_error($conn)
        ]);
        exit();
    }
    
    $classes = [];
    while ($class = mysqli_fetch_assoc($classes_result)) {
        $classes[] = [
            'id' => $class['id'],
            'class_name' => $class['class_name'],
            'class_code' => $class['class_code'],
            'tenant_count' => (int)$class['tenant_count'],
            'created_at' => $class['created_at']
        ];
    }
    
    // Get suggested bill names
    $suggested_bills = ['Rent', 'Water', 'Water Bill', 'Electricity', 'Electricity Bill', 'Gas', 'Internet', 'Maintenance', 'Security', 'Cleaning'];
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Classes retrieved successfully.',
        'data' => [
            'classes' => $classes,
            'suggested_bills' => $suggested_bills,
            'total_classes' => count($classes)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error occurred.',
        'error' => $e->getMessage()
    ]);
}

mysqli_close($conn);
?>