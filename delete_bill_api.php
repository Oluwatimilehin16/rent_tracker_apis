<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

include 'config.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

// Validate required fields
if (!isset($input['bill_id']) || !isset($input['landlord_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bill ID and Landlord ID are required']);
    exit;
}

$bill_id = (int)$input['bill_id'];
$landlord_id = mysqli_real_escape_string($conn, $input['landlord_id']);

// Verify the bill belongs to the landlord before deleting
$verify_query = "SELECT id FROM bills WHERE id = $bill_id AND landlord_id = '$landlord_id'";
$verify_result = mysqli_query($conn, $verify_query);

if (!$verify_result || mysqli_num_rows($verify_result) === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Bill not found or access denied']);
    exit;
}

// Delete the bill
$delete_query = "DELETE FROM bills WHERE id = $bill_id AND landlord_id = '$landlord_id'";

if (mysqli_query($conn, $delete_query)) {
    echo json_encode([
        'success' => true,
        'message' => 'Bill deleted successfully'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting bill: ' . mysqli_error($conn)
    ]);
}

mysqli_close($conn);
?>