<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

include 'config.php';

// Get parameters
$landlord_id = $_GET['landlord_id'] ?? '';
$filter = $_GET['filter'] ?? 'all';

if (empty($landlord_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Landlord ID is required']);
    exit;
}

$landlord_id = mysqli_real_escape_string($conn, $landlord_id);
$today = date('Y-m-d');

// Fetch bills data
$query = "
    SELECT DISTINCT b.id, b.bill_name, b.amount, b.due_date, b.status, b.payment_date,
           c.class_name, 
           CONCAT(u.firstname, ' ', u.lastname) AS tenant_name
    FROM bills b
    LEFT JOIN classes c ON c.id = b.class_id
    LEFT JOIN user_classes uc ON uc.class_id = c.id
    LEFT JOIN users u ON u.id = uc.user_id AND u.users_role = 'tenant'
    WHERE b.landlord_id = '$landlord_id'
    ORDER BY b.due_date ASC
";

$result = mysqli_query($conn, $query);

if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

// Initialize statistics
$stats = [
    'total_bills' => 0,
    'paid_bills' => 0,
    'overdue_bills' => 0,
    'upcoming_bills' => 0,
    'total_amount' => 0,
    'paid_amount' => 0
];

$bills = [];
$filtered_bills = [];

// Process bills and calculate statistics
while ($row = mysqli_fetch_assoc($result)) {
    // Calculate days difference
    $due_date = new DateTime($row['due_date']);
    $today_date = new DateTime($today);
    $diff = $today_date->diff($due_date);
    $days_diff = $diff->invert ? -$diff->days : $diff->days;

    // Add calculated fields
    $row['days_diff'] = $days_diff;
    
    // Set due status and text
    if ($row['status'] == 1) {
        $payment_date = $row['payment_date'] ? $row['payment_date'] : $today;
        $row['due_text'] = 'Paid on ' . date('M j, Y', strtotime($payment_date));
        $row['due_class'] = 'due-paid';
    } else {
        if ($days_diff < 0) {
            $row['due_text'] = "Overdue by " . abs($days_diff) . " days";
            $row['due_class'] = 'due-overdue';
        } elseif ($days_diff <= 7) {
            $row['due_text'] = $days_diff == 0 ? "Due today" : "Due in $days_diff days";
            $row['due_class'] = 'due-soon';
        } else {
            $row['due_text'] = date('F j, Y', strtotime($row['due_date']));
            $row['due_class'] = 'due-normal';
        }
    }

    $bills[] = $row;

    // Calculate statistics
    $stats['total_bills']++;
    $stats['total_amount'] += $row['amount'];
    
    if ($row['status'] == 1) {
        $stats['paid_bills']++;
        $stats['paid_amount'] += $row['amount'];
    }
    
    // Count overdue and upcoming bills only for unpaid bills
    if ($row['status'] == 0) {
        if ($days_diff < 0) {
            $stats['overdue_bills']++;
        } elseif ($days_diff >= 0 && $days_diff <= 7) {
            $stats['upcoming_bills']++;
        }
    }

    // Apply filter
    $show_bill = true;
    switch ($filter) {
        case 'paid':
            $show_bill = $row['status'] == 1;
            break;
        case 'unpaid':
            $show_bill = $row['status'] == 0;
            break;
        case 'overdue':
            $show_bill = $row['status'] == 0 && $days_diff < 0;
            break;
        case 'upcoming':
            $show_bill = $row['status'] == 0 && $days_diff >= 0 && $days_diff <= 7;
            break;
        case 'all':
        default:
            $show_bill = true;
            break;
    }
    
    if ($show_bill) {
        $filtered_bills[] = $row;
    }
}

// Add unpaid bills count to stats
$stats['unpaid_bills'] = $stats['total_bills'] - $stats['paid_bills'];

echo json_encode([
    'success' => true,
    'data' => [
        'bills' => $filtered_bills,
        'all_bills' => $bills, // Include all bills for client-side filtering if needed
        'statistics' => $stats,
        'filter' => $filter,
        'today' => $today
    ]
]);

mysqli_close($conn);
?>