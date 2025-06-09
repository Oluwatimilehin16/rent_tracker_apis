<?php
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

// Validate required fields
$required_fields = ['bill_id', 'landlord_id', 'bill_name', 'amount', 'due_date', 'users_id'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
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

// Update the bill
$update_query = "
    UPDATE bills SET 
        bill_name = ?,
        amount = ?,
        due_date = ?,
        status = ?
    WHERE id = ? AND landlord_id = ?
";

$stmt = $conn->prepare($update_query);
$stmt->bind_param("sdsiii", $bill_name, $amount, $due_date, $status, $bill_id, $landlord_id);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'Bill updated successfully',
        'data' => [
            'bill_id' => $bill_id,
            'bill_name' => $bill_name,
            'amount' => $amount,
            'due_date' => $due_date,
            'status' => $status
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update bill: ' . mysqli_error($conn)]);
}

$stmt->close();
$conn->close();
?>