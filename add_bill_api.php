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

// Start session for authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if landlord is authenticated
if (!isset($_SESSION['landlord_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Please log in first.'
    ]);
    exit();
}

$landlord_id = $_SESSION['landlord_id'];

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required_fields = ['bill_name', 'amount', 'due_date', 'class_id'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            $missing_fields[] = $field;
        }
    }
    
    if (!empty($missing_fields)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: ' . implode(', ', $missing_fields)
        ]);
        exit();
    }
    
    // Sanitize and validate input
    $bill_name = mysqli_real_escape_string($conn, trim($input['bill_name']));
    $amount = mysqli_real_escape_string($conn, trim($input['amount']));
    $due_date = mysqli_real_escape_string($conn, trim($input['due_date']));
    $class_id = mysqli_real_escape_string($conn, trim($input['class_id']));
    
    // Additional validation
    if (!is_numeric($amount) || $amount <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Amount must be a positive number.'
        ]);
        exit();
    }
    
    // Validate date format
    if (!DateTime::createFromFormat('Y-m-d', $due_date)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid date format. Use YYYY-MM-DD.'
        ]);
        exit();
    }
    
    // Verify that the class belongs to the landlord
    $class_check = mysqli_query($conn, "SELECT id FROM classes WHERE id = '$class_id' AND landlord_id = '$landlord_id'");
    if (mysqli_num_rows($class_check) === 0) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to add bills to this class.'
        ]);
        exit();
    }
    
    // Handle "other" bill name if provided
    if (isset($input['other_bill_name']) && !empty($input['other_bill_name'])) {
        $bill_name = mysqli_real_escape_string($conn, trim($input['other_bill_name']));
    }
    
    // Insert the bill
    $insert_query = "INSERT INTO bills (bill_name, amount, due_date, landlord_id, class_id, created_at) 
                     VALUES ('$bill_name', '$amount', '$due_date', '$landlord_id', '$class_id', NOW())";
    
    $insert_result = mysqli_query($conn, $insert_query);
    
    if ($insert_result) {
        $bill_id = mysqli_insert_id($conn);
        
        // Get the created bill details for response
        $bill_query = mysqli_query($conn, "
            SELECT b.*, c.class_name, c.class_code 
            FROM bills b 
            JOIN classes c ON b.class_id = c.id 
            WHERE b.id = '$bill_id'
        ");
        $bill_data = mysqli_fetch_assoc($bill_query);
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Bill added successfully!',
            'data' => [
                'bill_id' => $bill_id,
                'bill_name' => $bill_data['bill_name'],
                'amount' => $bill_data['amount'],
                'due_date' => $bill_data['due_date'],
                'class_name' => $bill_data['class_name'],
                'class_code' => $bill_data['class_code'],
                'created_at' => $bill_data['created_at']
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: Could not add bill.',
            'error' => mysqli_error($conn)
        ]);
    }
    
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