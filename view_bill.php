<?php
include 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $landlord_id = $_GET['landlord_id'];
    $bill_id = (int)$_GET['bill_id'];

    $query = "
        SELECT b.*, c.class_name, 
               CONCAT(u.firstname, ' ', u.lastname) AS tenant_name,
               u.email AS tenant_email
        FROM bills b
        LEFT JOIN classes c ON c.id = b.class_id
        LEFT JOIN user_classes uc ON uc.class_id = c.id
        LEFT JOIN users u ON u.id = uc.user_id AND u.users_role = 'tenant'
        WHERE b.id = $bill_id AND b.landlord_id = '$landlord_id'
        LIMIT 1
    ";

    $result = mysqli_query($conn, $query);
    $bill = mysqli_fetch_assoc($result);

    if (!$bill) {
        echo json_encode([
            'success' => false, 
            'message' => 'Bill not found'
        ]);
    } else {
        // ✅ Cast status to boolean
        $bill['status'] = ($bill['status'] == 1);  // 1 = paid, 0 = not paid

        // Due date calculation
        $today = date('Y-m-d');
        $due_date = new DateTime($bill['due_date']);
        $today_date = new DateTime($today);
        $diff = $today_date->diff($due_date);
        $days_diff = $diff->invert ? -$diff->days : $diff->days;

        echo json_encode([
            'success' => true,
            'bill' => $bill,
            'due_status' => [
                'days_diff' => $days_diff,
                'is_overdue' => $diff->invert
            ]
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
