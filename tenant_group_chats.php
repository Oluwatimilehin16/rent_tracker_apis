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
    // First, get tenant's class IDs
    $tenant_class_ids = [];
    $stmt = $conn->prepare("SELECT class_id FROM user_classes WHERE user_id = ?");
    $stmt->bind_param("i", $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $tenant_class_ids[] = $row['class_id'];
    }
    $stmt->close();
    
    if (empty($tenant_class_ids)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'groups' => [],
                'statistics' => [
                    'total_groups' => 0,
                    'active_groups' => 0,
                    'last_activity' => null,
                    'last_activity_formatted' => 'No activity'
                ]
            ]
        ]);
        exit;
    }
    
    // Build placeholders for prepared statement
    $class_ids_placeholder = implode(",", array_fill(0, count($tenant_class_ids), '?'));
    
    // Get group chats with statistics
    $query = "
        SELECT gc.id, gc.name, u.lastname AS landlord_name,
            (SELECT COUNT(*) FROM group_chat_classes gcc_sub WHERE gcc_sub.group_id = gc.id) AS class_count,
            (SELECT COUNT(*) FROM group_chat_messages gcm WHERE gcm.group_id = gc.id) AS message_count,
            (SELECT COUNT(DISTINCT uc.user_id)
             FROM user_classes uc
             JOIN group_chat_classes gcc2 ON uc.class_id = gcc2.class_id
             WHERE gcc2.group_id = gc.id
            ) AS active_members,
            (SELECT MAX(gcm3.timestamp) FROM group_chat_messages gcm3 WHERE gcm3.group_id = gc.id) AS last_activity
        FROM group_chats gc
        JOIN users u ON gc.landlord_id = u.id
        JOIN group_chat_classes gcc ON gc.id = gcc.group_id
        WHERE gcc.class_id IN ($class_ids_placeholder)
        GROUP BY gc.id
        ORDER BY last_activity DESC
    ";
    
    $stmt = $conn->prepare($query);
    $types = str_repeat("i", count($tenant_class_ids));
    $stmt->bind_param($types, ...$tenant_class_ids);
    $stmt->execute();
    $groups = $stmt->get_result();
    
    $groups_data = [];
    $total_groups = 0;
    $active_groups = 0;
    $last_activity_time = null;
    
    while ($row = $groups->fetch_assoc()) {
        // Format the group data
        $group = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'landlord_name' => $row['landlord_name'],
            'class_count' => (int)$row['class_count'],
            'message_count' => (int)$row['message_count'],
            'active_members' => (int)($row['active_members'] ?: $row['class_count']),
            'last_activity' => $row['last_activity'],
            'last_activity_formatted' => timeAgo($row['last_activity']),
            'status' => $row['message_count'] > 0 ? 'active' : 'inactive'
        ];
        
        $groups_data[] = $group;
        $total_groups++;
        
        if ($row['message_count'] > 0) {
            $active_groups++;
        }
        
        if ($row['last_activity'] && (!$last_activity_time || $row['last_activity'] > $last_activity_time)) {
            $last_activity_time = $row['last_activity'];
        }
    }
    
    $stmt->close();
    
    // Calculate overall statistics
    $statistics = [
        'total_groups' => $total_groups,
        'active_groups' => $active_groups,
        'last_activity' => $last_activity_time,
        'last_activity_formatted' => $last_activity_time ? timeAgo($last_activity_time) : 'No activity'
    ];
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'groups' => $groups_data,
            'statistics' => $statistics
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred'
    ]);
}

// Function to format time difference
function timeAgo($datetime) {
    if (!$datetime) return 'No activity';

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $past = new DateTime($datetime, new DateTimeZone('UTC'));

    $diff = $now->diff($past);

    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'mo ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'Just now';
}

mysqli_close($conn);
?>