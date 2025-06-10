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
    $bills_data = [];
    
    // Get all classes tenant is part of
    $class_result = mysqli_query($conn, "
        SELECT 
            c.id AS class_id, 
            u.firstname AS landlord_fname, 
            u.lastname AS landlord_lname 
        FROM class_members cm
        JOIN classes c ON cm.class_id = c.id
        JOIN users u ON c.landlord_id = u.id
        WHERE cm.tenant_id = '$tenant_id'
    ");
    
    while ($class = mysqli_fetch_assoc($class_result)) {
        $class_id = $class['class_id'];
        $landlord_name = $class['landlord_fname'] . " " . $class['landlord_lname'];
        $landlord_initials = strtoupper(substr($class['landlord_fname'], 0, 1) . substr($class['landlord_lname'], 0, 1));
        
        // Get bills for this class
        $bills = mysqli_query($conn, "
            SELECT DISTINCT id, bill_name, amount, due_date 
            FROM bills 
            WHERE class_id = '$class_id' 
            ORDER BY due_date ASC
        ");
        
        while ($bill = mysqli_fetch_assoc($bills)) {
            $bill_id = $bill['id'];
            $bill_name = $bill['bill_name'];
            $amount = $bill['amount'];
            $due_date = $bill['due_date'];
            
            // Calculate due status
            $due_timestamp = strtotime($due_date);
            $current_time = time();
            $due_soon = ($due_timestamp - $current_time) < (7 * 24 * 60 * 60) && $due_timestamp > $current_time;
            $is_overdue = $due_timestamp < $current_time;
            
            // Check if this tenant has paid
            $payment_check = mysqli_query($conn, "
                SELECT * FROM payments 
                WHERE bill_id = '$bill_id' AND tenant_id = '$tenant_id'
            ");
            $paid = mysqli_num_rows($payment_check) > 0;
            
            // Check if co-tenant(s) have paid
            $note = "";
            $co_query = mysqli_query($conn, "
                SELECT tenant_id FROM class_members 
                WHERE class_id = '$class_id' AND tenant_id != '$tenant_id'
            ");
            
            while ($co = mysqli_fetch_assoc($co_query)) {
                $co_id = $co['tenant_id'];
                $co_paid = mysqli_query($conn, "
                    SELECT * FROM payments 
                    WHERE bill_id = '$bill_id' AND tenant_id = '$co_id'
                ");
                if (mysqli_num_rows($co_paid) > 0) {
                    $note = "Co-tenant has paid";
                    break;
                }
            }
            
            // Determine utility type
            $utility_type = "other";
            $utility_icon = "fas fa-file-invoice";
            $bill_lower = strtolower($bill_name);
            
            if (strpos($bill_lower, 'electric') !== false || strpos($bill_lower, 'power') !== false) {
                $utility_type = "electricity";
                $utility_icon = "fas fa-bolt";
            } elseif (strpos($bill_lower, 'water') !== false) {
                $utility_type = "water";
                $utility_icon = "fas fa-tint";
            } elseif (strpos($bill_lower, 'gas') !== false) {
                $utility_type = "gas";
                $utility_icon = "fas fa-fire";
            } elseif (strpos($bill_lower, 'internet') !== false || strpos($bill_lower, 'wifi') !== false) {
                $utility_type = "internet";
                $utility_icon = "fas fa-wifi";
            } elseif (strpos($bill_lower, 'rent') !== false) {
                $utility_type = "rent";
                $utility_icon = "fas fa-home";
            }
            
            // Determine status
            $status = "unpaid";
            if ($paid) {
                $status = "paid";
            } elseif ($is_overdue) {
                $status = "overdue";
            } elseif ($due_soon) {
                $status = "due_soon";
            }
            
            $bills_data[] = [
                'id' => $bill_id,
                'bill_name' => $bill_name,
                'amount' => floatval($amount),
                'due_date' => $due_date,
                'due_date_formatted' => date('M d, Y', strtotime($due_date)),
                'paid' => $paid,
                'status' => $status,
                'is_overdue' => $is_overdue,
                'due_soon' => $due_soon,
                'landlord_name' => $landlord_name,
                'landlord_initials' => $landlord_initials,
                'note' => $note,
                'utility_type' => $utility_type,
                'utility_icon' => $utility_icon,
                'class_id' => $class_id
            ];
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $bills_data
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