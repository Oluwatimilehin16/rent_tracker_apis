<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['bill_name', 'amount', 'due_date', 'landlord_id', 'class_id'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

$bill_name = mysqli_real_escape_string($conn, $input['bill_name']);
$amount = mysqli_real_escape_string($conn, $input['amount']);
$due_date = mysqli_real_escape_string($conn, $input['due_date']);
$landlord_id = mysqli_real_escape_string($conn, $input['landlord_id']);
$class_id = mysqli_real_escape_string($conn, $input['class_id']);

// Validate that the class belongs to the landlord
$class_check = mysqli_query($conn, "SELECT id FROM classes WHERE id = '$class_id' AND landlord_id = '$landlord_id'");
if (mysqli_num_rows($class_check) === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: Class does not belong to landlord']);
    exit();
}

// Validate amount is numeric and positive
if (!is_numeric($amount) || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Amount must be a positive number']);
    exit();
}

// Validate due date format
if (!strtotime($due_date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid due date format']);
    exit();
}

// Insert bill
$insert_query = "INSERT INTO bills (bill_name, amount, due_date, landlord_id, class_id) 
                 VALUES ('$bill_name', '$amount', '$due_date', '$landlord_id', '$class_id')";

$insert_result = mysqli_query($conn, $insert_query);

if ($insert_result) {
    $bill_id = mysqli_insert_id($conn);
    
    // Get the inserted bill details
    $get_bill = mysqli_query($conn, "SELECT b.*, c.class_name, c.class_code 
                                     FROM bills b 
                                     JOIN classes c ON b.class_id = c.id 
                                     WHERE b.id = '$bill_id'");
    
    if ($bill_data = mysqli_fetch_assoc($get_bill)) {
        echo json_encode([
            'success' => true, 
            'message' => 'Bill added successfully',
            'bill' => $bill_data
        ]);
    } else {
        echo json_encode(['success' => true, 'message' => 'Bill added successfully']);
    }
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . mysqli_error($conn)
    ]);
}

mysqli_close($conn);
?>