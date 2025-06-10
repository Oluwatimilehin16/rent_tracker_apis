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
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['landlord_id', 'bill_id', 'bill_name', 'amount', 'due_date', 'users_id'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

$landlord_id = (int)$input['landlord_id'];
$bill_id = (int)$input['bill_id'];
$bill_name = mysqli_real_escape_string($conn, $input['bill_name']);
$amount = (float)$input['amount'];
$due_date = mysqli_real_escape_string($conn, $input['due_date']);
$user_id = (int)$input['users_id'];
$status = isset($input['status']) && $input['status'] === 'paid' ? 'paid' : 'unpaid';

$payment_date = null;
if ($status === 'paid' && !empty($input['payment_date'])) {
    $payment_date = mysqli_real_escape_string($conn, $input['payment_date']);
} elseif ($status === 'paid') {
    $payment_date = date('Y-m-d');
}

$payment_date_sql = $payment_date ? "'$payment_date'" : "NULL";

$update_query = "
    UPDATE bills SET 
        bill_name = '$bill_name',
        amount = $amount,
        due_date = '$due_date',
        status = '$status'
    WHERE id = $bill_id AND landlord_id = '$landlord_id'
";

if (mysqli_query($conn, $update_query)) {
    echo json_encode([
        'success' => true, 
        'message' => 'Bill updated successfully!'
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to update bill: ' . mysqli_error($conn)
    ]);
}

mysqli_close($conn);
?>