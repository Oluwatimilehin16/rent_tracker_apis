<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

function sendResponse($success, $data = null, $message = '', $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

function validateInput($data, $required_fields) {
    $errors = [];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            $errors[] = "Field '$field' is required";
        }
    }
    return $errors;
}

// GET request - Fetch bill data for editing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['bill_id']) || !isset($_GET['landlord_id'])) {
        sendResponse(false, null, 'Bill ID and Landlord ID are required', 400);
    }

    $bill_id = (int)$_GET['bill_id'];
    $landlord_id = (int)$_GET['landlord_id'];

    // Fetch bill data
    $bill_query = "
        SELECT b.*
        FROM bills b
        WHERE b.id = ? AND b.landlord_id = ?
    ";

    $stmt = $conn->prepare($bill_query);
    if (!$stmt) {
        sendResponse(false, null, 'Database error: ' . $conn->error, 500);
    }

    $stmt->bind_param("ii", $bill_id, $landlord_id);
    $stmt->execute();
    $bill_result = $stmt->get_result();
    $bill = $bill_result->fetch_assoc();

    if (!$bill) {
        sendResponse(false, null, 'Bill not found or access denied', 404);
    }

    // Fetch tenants under the landlord
    $tenants_query = "
        SELECT id, firstname 
        FROM users 
        WHERE landlord_id = ?
        ORDER BY firstname
    ";

    $stmt2 = $conn->prepare($tenants_query);
    $stmt2->bind_param("i", $landlord_id);
    $stmt2->execute();
    $tenants_result = $stmt2->get_result();
    
    $tenants = [];
    while ($tenant = $tenants_result->fetch_assoc()) {
        $tenants[] = $tenant;
    }

    sendResponse(true, [
        'bill' => $bill,
        'tenants' => $tenants
    ], 'Bill data retrieved successfully');
}

// PUT request - Update bill
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendResponse(false, null, 'Invalid JSON input', 400);
    }

    // Validate required fields
    $required_fields = ['bill_id', 'landlord_id', 'bill_name', 'amount', 'due_date', 'user_id'];
    $validation_errors = validateInput($input, $required_fields);
    
    if (!empty($validation_errors)) {
        sendResponse(false, null, implode(', ', $validation_errors), 400);
    }

    $bill_id = (int)$input['bill_id'];
    $landlord_id = (int)$input['landlord_id'];
    $bill_name = mysqli_real_escape_string($conn, trim($input['bill_name']));
    $amount = (float)$input['amount'];
    $due_date = mysqli_real_escape_string($conn, $input['due_date']);
    $user_id = (int)$input['user_id'];
    $status = isset($input['status']) && $input['status'] === true ? 'paid' : 'unpaid';

    // Validate amount
    if ($amount <= 0) {
        sendResponse(false, null, 'Amount must be greater than 0', 400);
    }

    // Validate due date format
    if (!DateTime::createFromFormat('Y-m-d', $due_date)) {
        sendResponse(false, null, 'Invalid due date format. Use YYYY-MM-DD', 400);
    }

    // First, verify the bill belongs to the landlord
    $verify_query = "SELECT id FROM bills WHERE id = ? AND landlord_id = ?";
    $verify_stmt = $conn->prepare($verify_query);
    $verify_stmt->bind_param("ii", $bill_id, $landlord_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows === 0) {
        sendResponse(false, null, 'Bill not found or access denied', 404);
    }

    // Verify the tenant belongs to the landlord
    $tenant_verify_query = "SELECT id FROM users WHERE id = ? AND landlord_id = ?";
    $tenant_stmt = $conn->prepare($tenant_verify_query);
    $tenant_stmt->bind_param("ii", $user_id, $landlord_id);
    $tenant_stmt->execute();
    $tenant_result = $tenant_stmt->get_result();
    
    if ($tenant_result->num_rows === 0) {
        sendResponse(false, null, 'Selected tenant is not associated with you', 400);
    }

    // Update the bill
    $update_query = "
        UPDATE bills SET 
            bill_name = ?,
            amount = ?,
            due_date = ?,
            status = ?,
            user_id = ?,
            updated_at = NOW()
        WHERE id = ? AND landlord_id = ?
    ";

    $stmt = $conn->prepare($update_query);
    if (!$stmt) {
        sendResponse(false, null, 'Database error: ' . $conn->error, 500);
    }

    $stmt->bind_param("sdsisii", $bill_name, $amount, $due_date, $status, $user_id, $bill_id, $landlord_id);
    
    if ($stmt->execute()) {
        // Fetch updated bill data to return
        $fetch_query = "SELECT * FROM bills WHERE id = ?";
        $fetch_stmt = $conn->prepare($fetch_query);
        $fetch_stmt->bind_param("i", $bill_id);
        $fetch_stmt->execute();
        $updated_bill = $fetch_stmt->get_result()->fetch_assoc();
        
        sendResponse(true, $updated_bill, 'Bill updated successfully');
    } else {
        sendResponse(false, null, 'Failed to update bill: ' . $stmt->error, 500);
    }
}

// Method not allowed
sendResponse(false, null, 'Method not allowed', 405);
?>