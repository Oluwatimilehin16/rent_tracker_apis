<?php
// Suppress all output before headers
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

// Only allow PUT requests
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Check if JSON decode was successful
if ($input === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

// Validate required fields
$required_fields = ['bill_id', 'landlord_id', 'bill_name', 'amount', 'due_date', 'users_id'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || $input[$field] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

$bill_id = (int)$input['bill_id'];
$landlord_id = (int)$input['landlord_id'];
$bill_name = mysqli_real_escape_string($conn, $input['bill_name']);
$amount = (float)$input['amount'];
$due_date = mysqli_real_escape_string($conn, $input['due_date']);
$users_id = (int)$input['users_id'];
$status = isset($input['status']) && $input['status'] === 'paid' ? 'paid' : 'unpaid';

// Verify the bill belongs to the landlord
$verify_query = "SELECT id FROM bills WHERE id = ? AND landlord_id = ?";
$verify_stmt = $conn->prepare($verify_query);
$verify_stmt->bind_param("ii", $bill_id, $landlord_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();

if ($verify_result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Bill not found or access denied']);
    exit;
}

// FIXED: Update the bill - now includes users_id
$update_query = "
    UPDATE bills SET 
        bill_name = ?,
        amount = ?,
        due_date = ?,
        users_id = ?,
        status = ?
    WHERE id = ? AND landlord_id = ?
";

// FIXED: Correct parameter binding - 7 parameters: s,d,s,i,s,i,i
$stmt = $conn->prepare($update_query);
$stmt->bind_param("sdsisii", $bill_name, $amount, $due_date, $users_id, $status, $bill_id, $landlord_id);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'Bill updated successfully',
        'data' => [
            'bill_id' => $bill_id,
            'bill_name' => $bill_name,
            'amount' => $amount,
            'due_date' => $due_date,
            'users_id' => $users_id,
            'status' => $status
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update bill: ' . mysqli_error($conn)]);
}

$stmt->close();
$verify_stmt->close();
$conn->close();
?>