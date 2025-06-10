<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

// Get tenant_id from request
$tenant_id = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tenant_id = $_GET['tenant_id'] ?? null;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $tenant_id = $input['tenant_id'] ?? null;
}

if (!$tenant_id) {
    http_response_code(400);
    echo json_encode(['error' => 'tenant_id is required']);
    exit;
}

try {
    // Check if tenant has joined any class
    $class_check = mysqli_query($conn, "SELECT class_id FROM class_members WHERE tenant_id = '$tenant_id'");
    if (mysqli_num_rows($class_check) == 0) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'has_class' => false,
            'message' => 'Tenant has not joined any class'
        ]);
        exit;
    }

    // Get total bills amount
    $total_bills_result = mysqli_query($conn, "
        SELECT SUM(amount) as total FROM bills 
        WHERE class_id IN (SELECT class_id FROM class_members WHERE tenant_id = '$tenant_id')
    ");
    
    // Get total paid amount
    $total_paid_result = mysqli_query($conn, "
        SELECT SUM(b.amount) as paid FROM payments p 
        JOIN bills b ON p.bill_id = b.id 
        WHERE p.tenant_id = '$tenant_id'
    ");
    
    $total_bills = mysqli_fetch_assoc($total_bills_result)['total'] ?? 0;
    $total_paid = mysqli_fetch_assoc($total_paid_result)['paid'] ?? 0;
    $balance = $total_bills - $total_paid;
    
    // Initialize counters
    $overdue_count = 0;
    $due_soon_count = 0;
    $total_unpaid_count = 0;
    
    // Get all classes tenant is part of and calculate counts
    $class_result_count = mysqli_query($conn, "
        SELECT c.id AS class_id FROM class_members cm
        JOIN classes c ON cm.class_id = c.id
        WHERE cm.tenant_id = '$tenant_id'
    ");
    
    while ($class = mysqli_fetch_assoc($class_result_count)) {
        $class_id = $class['class_id'];
        $bills = mysqli_query($conn, "SELECT id, due_date FROM bills WHERE class_id = '$class_id'");
        
        while ($bill = mysqli_fetch_assoc($bills)) {
            $bill_id = $bill['id'];
            $due_date = $bill['due_date'];
            
            // Check if tenant has paid this bill
            $payment_check = mysqli_query($conn, "
                SELECT * FROM payments 
                WHERE bill_id = '$bill_id' AND tenant_id = '$tenant_id'
            ");
            $paid = mysqli_num_rows($payment_check) > 0;
            
            if (!$paid) {
                $total_unpaid_count++;
                if (strtotime($due_date) < time()) {
                    $overdue_count++;
                } elseif ((strtotime($due_date) - time()) < (7 * 24 * 60 * 60)) {
                    $due_soon_count++;
                }
            }
        }
    }
    
    // Return dashboard statistics
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'has_class' => true,
        'data' => [
            'total_bills' => floatval($total_bills),
            'total_paid' => floatval($total_paid),
            'balance' => floatval($balance),
            'overdue_count' => $overdue_count,
            'due_soon_count' => $due_soon_count,
            'total_unpaid_count' => $total_unpaid_count
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}

mysqli_close($conn);
?>