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

// Get bill and tenant information
$query = "
    SELECT b.id, b.bill_name, b.amount, b.due_date,
           CONCAT(u.firstname, ' ', u.lastname) AS tenant_name,
           u.email AS tenant_email,
           u.phone AS tenant_phone,
           c.class_name
    FROM bills b
    LEFT JOIN classes c ON c.id = b.class_id
    LEFT JOIN user_classes uc ON uc.class_id = c.id
    LEFT JOIN users u ON u.id = uc.user_id AND u.users_role = 'tenant'
    WHERE b.id = $bill_id AND b.landlord_id = '$landlord_id' AND b.status = 0
";

$result = mysqli_query($conn, $query);

if (!$result || mysqli_num_rows($result) === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Bill not found, already paid, or access denied']);
    exit;
}

$bill = mysqli_fetch_assoc($result);

// Here you would implement your actual reminder logic
// For now, we'll just simulate sending a reminder
// You could integrate with email services (PHPMailer), SMS services, push notifications, etc.

// Example reminder logic (you can customize this):
$reminder_message = "Dear {$bill['tenant_name']}, this is a reminder that your {$bill['bill_name']} payment of ₦" . number_format($bill['amount'], 2) . " is due on " . date('F j, Y', strtotime($bill['due_date'])) . ". Please make your payment as soon as possible.";

// Log the reminder (optional - you might want to create a reminders table)
$log_query = "INSERT INTO reminder_logs (bill_id, landlord_id, sent_at, message) VALUES ($bill_id, '$landlord_id', NOW(), '" . mysqli_real_escape_string($conn, $reminder_message) . "')";
// mysqli_query($conn, $log_query); // Uncomment if you have a reminder_logs table

// TODO: Implement actual sending logic here
// Examples:
// - Send email using PHPMailer
// - Send SMS using Twilio or similar service  
// - Send push notification
// - Create in-app notification

echo json_encode([
    'success' => true,
    'message' => 'Reminder sent successfully',
    'data' => [
        'bill_id' => $bill_id,
        'tenant_name' => $bill['tenant_name'],
        'tenant_email' => $bill['tenant_email'],
        'bill_name' => $bill['bill_name'],
        'amount' => $bill['amount'],
        'due_date' => $bill['due_date'],
        'reminder_message' => $reminder_message
    ]
]);

mysqli_close($conn);
?>