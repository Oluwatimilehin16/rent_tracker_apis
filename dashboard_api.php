<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

// Function to validate tenant session/token
function validateTenant($conn) {
    // For now, using session - you can replace with JWT token validation later
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['tenant_id']) || $_SESSION['users_role'] !== 'tenant') {
        return false;
    }
    
    return [
        'tenant_id' => $_SESSION['tenant_id'],
        'tenant_email' => $_SESSION['tenant_email'],
        'firstname' => $_SESSION['users_name']
    ];
}

// Function to check if tenant has joined any class
function checkClassMembership($conn, $tenant_id) {
    $stmt = mysqli_prepare($conn, "SELECT class_id FROM class_members WHERE tenant_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $tenant_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    return mysqli_num_rows($result) > 0;
}

// Function to get dashboard statistics
function getDashboardStats($conn, $tenant_id) {
    // Get total bills amount
    $stmt = mysqli_prepare($conn, "
        SELECT SUM(amount) as total FROM bills 
        WHERE class_id IN (SELECT class_id FROM class_members WHERE tenant_id = ?)
    ");
    mysqli_stmt_bind_param($stmt, "i", $tenant_id);
    mysqli_stmt_execute($stmt);
    $total_bills_result = mysqli_stmt_get_result($stmt);
    $total_bills = mysqli_fetch_assoc($total_bills_result)['total'] ?? 0;

    // Get total paid amount
    $stmt = mysqli_prepare($conn, "
        SELECT SUM(b.amount) as paid FROM payments p 
        JOIN bills b ON p.bill_id = b.id 
        WHERE p.tenant_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $tenant_id);
    mysqli_stmt_execute($stmt);
    $total_paid_result = mysqli_stmt_get_result($stmt);
    $total_paid = mysqli_fetch_assoc($total_paid_result)['paid'] ?? 0;

    $balance = $total_bills - $total_paid;

    // Get bill counts
    $overdue_count = 0;
    $due_soon_count = 0;
    $total_unpaid_count = 0;

    // Get classes tenant belongs to
    $stmt = mysqli_prepare($conn, "SELECT class_id FROM class_members WHERE tenant_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $tenant_id);
    mysqli_stmt_execute($stmt);
    $class_result = mysqli_stmt_get_result($stmt);

    while ($class = mysqli_fetch_assoc($class_result)) {
        $class_id = $class['class_id'];
        
        $stmt = mysqli_prepare($conn, "SELECT id, due_date FROM bills WHERE class_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $bills_result = mysqli_stmt_get_result($stmt);
        
        while ($bill = mysqli_fetch_assoc($bills_result)) {
            $bill_id = $bill['id'];
            $due_date = $bill['due_date'];
            
            // Check if tenant has paid this bill
            $stmt = mysqli_prepare($conn, "SELECT id FROM payments WHERE bill_id = ? AND tenant_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bill_id, $tenant_id);
            mysqli_stmt_execute($stmt);
            $payment_result = mysqli_stmt_get_result($stmt);
            $paid = mysqli_num_rows($payment_result) > 0;
            
            if (!$paid) {
                $total_unpaid_count++;
                $current_time = time();
                $due_timestamp = strtotime($due_date);
                
                if ($due_timestamp < $current_time) {
                    $overdue_count++;
                } elseif (($due_timestamp - $current_time) < (7 * 24 * 60 * 60)) {
                    $due_soon_count++;
                }
            }
        }
    }

    return [
        'total_bills' => (float)$total_bills,
        'total_paid' => (float)$total_paid,
        'balance' => (float)$balance,
        'overdue_count' => $overdue_count,
        'due_soon_count' => $due_soon_count,
        'total_unpaid_count' => $total_unpaid_count
    ];
}

// Function to get tenant's bills with details
function getTenantBills($conn, $tenant_id) {
    $bills = [];
    
    // Get all classes tenant is part of with landlord info
    $stmt = mysqli_prepare($conn, "
        SELECT 
            c.id AS class_id, 
            u.firstname AS landlord_fname, 
            u.lastname AS landlord_lname 
        FROM class_members cm
        JOIN classes c ON cm.class_id = c.id
        JOIN users u ON c.landlord_id = u.id
        WHERE cm.tenant_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $tenant_id);
    mysqli_stmt_execute($stmt);
    $class_result = mysqli_stmt_get_result($stmt);

    while ($class = mysqli_fetch_assoc($class_result)) {
        $class_id = $class['class_id'];
        $landlord_name = $class['landlord_fname'] . " " . $class['landlord_lname'];
        $landlord_initials = strtoupper(substr($class['landlord_fname'], 0, 1) . substr($class['landlord_lname'], 0, 1));

        // Get bills for this class
        $stmt = mysqli_prepare($conn, "
            SELECT DISTINCT id, bill_name, amount, due_date 
            FROM bills 
            WHERE class_id = ? 
            ORDER BY due_date ASC
        ");
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        mysqli_stmt_execute($stmt);
        $bills_result = mysqli_stmt_get_result($stmt);
        
        while ($bill = mysqli_fetch_assoc($bills_result)) {
            $bill_id = $bill['id'];
            $bill_name = $bill['bill_name'];
            $amount = $bill['amount'];
            $due_date = $bill['due_date'];
            
            $current_time = time();
            $due_timestamp = strtotime($due_date);
            $due_soon = ($due_timestamp - $current_time) < (7 * 24 * 60 * 60);
            $is_overdue = $due_timestamp < $current_time;

            // Check if this tenant has paid
            $stmt = mysqli_prepare($conn, "SELECT id FROM payments WHERE bill_id = ? AND tenant_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $bill_id, $tenant_id);
            mysqli_stmt_execute($stmt);
            $payment_result = mysqli_stmt_get_result($stmt);
            $paid = mysqli_num_rows($payment_result) > 0;

            // Check if co-tenants have paid
            $co_tenant_paid = false;
            $stmt = mysqli_prepare($conn, "SELECT tenant_id FROM class_members WHERE class_id = ? AND tenant_id != ?");
            mysqli_stmt_bind_param($stmt, "ii", $class_id, $tenant_id);
            mysqli_stmt_execute($stmt);
            $co_result = mysqli_stmt_get_result($stmt);

            while ($co = mysqli_fetch_assoc($co_result)) {
                $co_id = $co['tenant_id'];
                $stmt = mysqli_prepare($conn, "SELECT id FROM payments WHERE bill_id = ? AND tenant_id = ?");
                mysqli_stmt_bind_param($stmt, "ii", $bill_id, $co_id);
                mysqli_stmt_execute($stmt);
                $co_payment_result = mysqli_stmt_get_result($stmt);
                if (mysqli_num_rows($co_payment_result) > 0) {
                    $co_tenant_paid = true;
                    break;
                }
            }

            // Determine utility type and icon
            $utility_type = determineUtilityType($bill_name);
            
            // Determine status and filter classes
            $status = $paid ? 'paid' : 'unpaid';
            $filter_classes = [$status];
            
            if (!$paid) {
                if ($is_overdue) {
                    $filter_classes[] = 'overdue';
                } elseif ($due_soon) {
                    $filter_classes[] = 'due-soon';
                }
            }

            $bills[] = [
                'id' => (int)$bill_id,
                'name' => $bill_name,
                'amount' => (float)$amount,
                'due_date' => $due_date,
                'due_date_formatted' => date('M d, Y', strtotime($due_date)),
                'paid' => $paid,
                'is_overdue' => $is_overdue,
                'due_soon' => $due_soon,
                'landlord' => [
                    'name' => $landlord_name,
                    'initials' => $landlord_initials
                ],
                'utility_type' => $utility_type,
                'co_tenant_paid' => $co_tenant_paid,
                'note' => $co_tenant_paid ? 'Co-tenant has paid' : null,
                'filter_classes' => $filter_classes
            ];
        }
    }

    return $bills;
}

// Helper function to determine utility type
function determineUtilityType($bill_name) {
    $bill_lower = strtolower($bill_name);
    
    if (strpos($bill_lower, 'electric') !== false || strpos($bill_lower, 'power') !== false) {
        return ['type' => 'electricity', 'icon' => 'fas fa-bolt', 'class' => 'utility-electricity'];
    } elseif (strpos($bill_lower, 'water') !== false) {
        return ['type' => 'water', 'icon' => 'fas fa-tint', 'class' => 'utility-water'];
    } elseif (strpos($bill_lower, 'gas') !== false) {
        return ['type' => 'gas', 'icon' => 'fas fa-fire', 'class' => 'utility-gas'];
    } elseif (strpos($bill_lower, 'internet') !== false || strpos($bill_lower, 'wifi') !== false) {
        return ['type' => 'internet', 'icon' => 'fas fa-wifi', 'class' => 'utility-internet'];
    } elseif (strpos($bill_lower, 'rent') !== false) {
        return ['type' => 'rent', 'icon' => 'fas fa-home', 'class' => 'utility-rent'];
    } else {
        return ['type' => 'other', 'icon' => 'fas fa-file-invoice', 'class' => 'utility-other'];
    }
}

// Main API logic
try {
    // Validate tenant
    $tenant_data = validateTenant($conn);
    if (!$tenant_data) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized access',
            'redirect' => 'login.php'
        ]);
        exit;
    }

    $tenant_id = $tenant_data['tenant_id'];
    $firstname = $tenant_data['firstname'];

    // Check if tenant has joined any class
    if (!checkClassMembership($conn, $tenant_id)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'No class membership found',
            'redirect' => 'join_class.php'
        ]);
        exit;
    }

    // Get dashboard data
    $stats = getDashboardStats($conn, $tenant_id);
    $bills = getTenantBills($conn, $tenant_id);

    // Return successful response
    echo json_encode([
        'success' => true,
        'data' => [
            'tenant' => [
                'id' => $tenant_id,
                'name' => $firstname,
                'email' => $tenant_data['tenant_email']
            ],
            'stats' => $stats,
            'bills' => $bills,
            'has_bills' => count($bills) > 0
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'message' => $e->getMessage()
    ]);
}

mysqli_close($conn);
?>