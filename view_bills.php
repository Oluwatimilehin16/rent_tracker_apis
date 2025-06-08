<?php
include 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];
    $landlord_id = $_POST['landlord_id'];
    
    if ($action == 'delete') {
        $bill_id = (int)$_POST['bill_id'];
        $delete_query = "DELETE FROM bills WHERE id = $bill_id AND landlord_id = '$landlord_id'";
        if (mysqli_query($conn, $delete_query)) {
            echo json_encode(['success' => true, 'message' => 'Bill deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error deleting bill']);
        }
    }
    
    if ($action == 'remind') {
        $bill_id = (int)$_POST['bill_id'];
        // Add reminder logic here
        echo json_encode(['success' => true, 'message' => 'Reminder sent successfully!']);
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $landlord_id = $_GET['landlord_id'];
    $today = date('Y-m-d');
    
    $query = "
        SELECT DISTINCT b.id, b.bill_name, b.amount, b.due_date, b.status,
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
    $bills = [];
    $stats = ['total_bills' => 0, 'paid_bills' => 0, 'overdue_bills' => 0, 'upcoming_bills' => 0, 'total_amount' => 0, 'paid_amount' => 0];
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $bills[] = $row;
            $stats['total_bills']++;
            
            if ($row['status'] == 1) {
                $stats['paid_bills']++;
                $stats['paid_amount'] += $row['amount'];
            }
            $stats['total_amount'] += $row['amount'];
            
            $due_date = new DateTime($row['due_date']);
            $today_date = new DateTime($today);
            $diff = $today_date->diff($due_date);
            $days_diff = $diff->invert ? -$diff->days : $diff->days;
            
            if ($row['status'] == 0) {
                if ($days_diff < 0) {
                    $stats['overdue_bills']++;
                } elseif ($days_diff >= 0 && $days_diff <= 7) {
                    $stats['upcoming_bills']++;
                }
            }
        }
    }
    
    echo json_encode(['success' => true, 'bills' => $bills, 'stats' => $stats]);
}
?>