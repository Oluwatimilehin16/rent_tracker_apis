<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate required parameters
if (!isset($_GET['bill_id']) || !isset($_GET['landlord_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters: bill_id and landlord_id']);
    exit;
}

$bill_id = (int)$_GET['bill_id'];
$landlord_id = (int)$_GET['landlord_id'];

// Fetch bill data
$bill_query = "
    SELECT b.*, u.firstname AS tenant_firstname, u.id AS users_id
    FROM bills b
    JOIN user_classes uc ON b.class_id = uc.class_id
    JOIN users u ON uc.user_id = u.id
    WHERE b.id = ? AND b.landlord_id = ?
";

$stmt = $conn->prepare($bill_query);
$stmt->bind_param("ii", $bill_id, $landlord_id);
$stmt->execute();
$bill_result = $stmt->get_result();
$bill = $bill_result->fetch_assoc();

if (!$bill) {
    echo json_encode(['success' => false, 'message' => 'Bill not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => $bill
]);

mysqli_close($conn);
?>