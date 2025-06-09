<?php
// --- CORS HEADERS (must be at the very top, before any output) ---
header('Access-Control-Allow-Origin: https://rent-tracker-frontend.onrender.com');
header('Access-Control-Allow-Credentials: true'); // Allow cookies/session
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// --- Handle preflight OPTIONS request ---
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- SESSION COOKIE SETTINGS for cross-origin ---
session_set_cookie_params([
    'samesite' => 'None',
    'secure' => true
]);
session_start();

include 'config.php';

// --- Helper function to send JSON response ---
function sendResponse($success, $data = null, $message = '', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// --- Validate landlord authentication ---
if (!isset($_SESSION['landlord_id']) && !isset($_GET['landlord_id']) && !isset($_POST['landlord_id'])) {
    sendResponse(false, null, 'Authentication required', 401);
}

$landlord_id = $_SESSION['landlord_id'] ?? $_GET['landlord_id'] ?? $_POST['landlord_id'];

// --- Validate bill_id parameter ---
if (!isset($_GET['id']) || empty($_GET['id'])) {
    sendResponse(false, null, 'Bill ID is required', 400);
}

$bill_id = (int)$_GET['id'];

if ($bill_id <= 0) {
    sendResponse(false, null, 'Invalid bill ID', 400);
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            // Fetch bill data for editing
            $bill_query = "
                SELECT b.*, u.firstname AS tenant_firstname, u.id AS users_id
                FROM bills b
                JOIN user_classes uc ON b.class_id = uc.class_id
                JOIN users u ON uc.user_id = u.id
                WHERE b.id = ? AND b.landlord_id = ?
            ";

            $stmt = $conn->prepare($bill_query);
            if (!$stmt) {
                sendResponse(false, null, 'Database prepare error: ' . $conn->error, 500);
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
                SELECT u.id, u.firstname 
                FROM users u 
                INNER JOIN user_classes uc ON u.id = uc.user_id 
                INNER JOIN classes c ON uc.class_id = c.id 
                WHERE c.landlord_id = ?
                GROUP BY u.id
                ORDER BY u.firstname
            ";

            $tenants_stmt = $conn->prepare($tenants_query);
            if (!$tenants_stmt) {
                sendResponse(false, null, 'Database prepare error: ' . $conn->error, 500);
            }

            $tenants_stmt->bind_param("i", $landlord_id);
            $tenants_stmt->execute();
            $tenants_result = $tenants_stmt->get_result();

            $tenants = [];
            while ($tenant = $tenants_result->fetch_assoc()) {
                $tenants[] = $tenant;
            }

            // Fetch landlord's classes
            $classes_query = "SELECT id, class_name FROM classes WHERE landlord_id = ? ORDER BY class_name";
            $classes_stmt = $conn->prepare($classes_query);
            if (!$classes_stmt) {
                sendResponse(false, null, 'Database prepare error: ' . $conn->error, 500);
            }

            $classes_stmt->bind_param("i", $landlord_id);
            $classes_stmt->execute();
            $classes_result = $classes_stmt->get_result();

            $classes = [];
            while ($class = $classes_result->fetch_assoc()) {
                $classes[] = $class;
            }

            $response_data = [
                'bill' => $bill,
                'tenants' => $tenants,
                'classes' => $classes
            ];

            sendResponse(true, $response_data, 'Bill data retrieved successfully');
            break;

        case 'POST':
        case 'PUT':
            // Handle bill update
            $input = json_decode(file_get_contents('php://input'), true);

            if (empty($input)) {
                $input = $_POST;
            }

            $required_fields = ['bill_name', 'amount', 'due_date', 'users_id'];
            foreach ($required_fields as $field) {
                if (!isset($input[$field]) || empty($input[$field])) {
                    sendResponse(false, null, "Field '$field' is required", 400);
                }
            }

            $bill_name = trim($input['bill_name']);
            $amount = (float)$input['amount'];
            $due_date = $input['due_date'];
            $user_id = (int)$input['users_id'];
            $status = isset($input['status']) && ($input['status'] === true || $input['status'] === 'paid') ? 'paid' : 'unpaid';

            if ($amount <= 0) {
                sendResponse(false, null, 'Amount must be greater than 0', 400);
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
                sendResponse(false, null, 'Invalid due date format. Use YYYY-MM-DD', 400);
            }

            // Validate user belongs to landlord
            $user_validation_query = "
                SELECT u.id 
                FROM users u 
                INNER JOIN user_classes uc ON u.id = uc.user_id 
                INNER JOIN classes c ON uc.class_id = c.id 
                WHERE u.id = ? AND c.landlord_id = ?
            ";

            $user_stmt = $conn->prepare($user_validation_query);
            if (!$user_stmt) {
                sendResponse(false, null, 'Database prepare error: ' . $conn->error, 500);
            }

            $user_stmt->bind_param("ii", $user_id, $landlord_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();

            if ($user_result->num_rows === 0) {
                sendResponse(false, null, 'Selected tenant does not belong to your properties', 400);
            }

            // Handle payment date
            $payment_date = null;
            if ($status === 'paid') {
                if (isset($input['payment_date']) && !empty($input['payment_date'])) {
                    $payment_date = $input['payment_date'];
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
                        sendResponse(false, null, 'Invalid payment date format. Use YYYY-MM-DD', 400);
                    }
                } else {
                    $payment_date = date('Y-m-d');
                }
            }

            // Verify the bill belongs to the landlord before updating
            $ownership_query = "SELECT id FROM bills WHERE id = ? AND landlord_id = ?";
            $ownership_stmt = $conn->prepare($ownership_query);
            if (!$ownership_stmt) {
                sendResponse(false, null, 'Database prepare error: ' . $conn->error, 500);
            }

            $ownership_stmt->bind_param("ii", $bill_id, $landlord_id);
            $ownership_stmt->execute();
            $ownership_result = $ownership_stmt->get_result();

            if ($ownership_result->num_rows === 0) {
                sendResponse(false, null, 'Bill not found or access denied', 404);
            }

            // Update the bill
            $update_query = "
                UPDATE bills SET 
                    bill_name = ?,
                    amount = ?,
                    due_date = ?,
                    status = ?,
                    payment_date = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND landlord_id = ?
            ";

            $update_stmt = $conn->prepare($update_query);
            if (!$update_stmt) {
                sendResponse(false, null, 'Database prepare error: ' . $conn->error, 500);
            }
            $update_stmt->bind_param("sdsssii", $bill_name, $amount, $due_date, $status, $payment_date, $bill_id, $landlord_id);

            if ($update_stmt->execute()) {
                if ($update_stmt->affected_rows > 0) {
                    // Fetch the updated bill data
                    $updated_bill_query = "
                        SELECT b.*, u.firstname AS tenant_firstname 
                        FROM bills b
                        JOIN user_classes uc ON b.class_id = uc.class_id
                        JOIN users u ON uc.user_id = u.id
                        WHERE b.id = ? AND b.landlord_id = ?
                    ";

                    $updated_stmt = $conn->prepare($updated_bill_query);
                    $updated_stmt->bind_param("ii", $bill_id, $landlord_id);
                    $updated_stmt->execute();
                    $updated_result = $updated_stmt->get_result();
                    $updated_bill = $updated_result->fetch_assoc();

                    sendResponse(true, $updated_bill, 'Bill updated successfully');
                } else {
                    sendResponse(false, null, 'No changes were made to the bill', 400);
                }
            } else {
                sendResponse(false, null, 'Failed to update bill: ' . $update_stmt->error, 500);
            }
            break;

        default:
            sendResponse(false, null, 'Method not allowed', 405);
            break;
    }

} catch (Exception $e) {
    error_log("Edit Bill API Error: " . $e->getMessage());
    sendResponse(false, null, 'An unexpected error occurred', 500);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>
