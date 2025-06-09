<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate required parameters
if (!isset($_GET['bill_id']) || !isset($_GET['landlord_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing bill_id or landlord_id parameter']);
    exit;
}

$bill_id = (int)$_GET['bill_id'];
$landlord_id = (int)$_GET['landlord_id'];

// Fetch bill data with tenant information
$bill_query = "
    SELECT 
        b.id,
        b.bill_name,
        b.amount,
        b.due_date,
        b.status,
        b.payment_date,
        b.class_id,
        u.firstname AS tenant_firstname,
        u.id AS users_id
    FROM bills b
    JOIN user_classes uc ON b.class_id = uc.class_id
    JOIN users u ON uc.user_id = u.id
    WHERE b.id = ? AND b.landlord_id = ?
";

$stmt = $conn->prepare($bill_query);
$stmt->bind_param("ii", $bill_id, $landlord_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Bill not found or access denied']);
    exit;
}

$bill = $result->fetch_assoc();

echo json_encode([
    'success' => true,
    'data' => $bill
]);

$stmt->close();
$conn->close();
?>