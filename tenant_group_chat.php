<?php
include 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $tenant_id = $_GET['tenant_id'];

    // Fetch class IDs the tenant belongs to
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
        echo json_encode([
            'success' => true,
            'groups' => [],
            'stats' => [
                'total_groups' => 0,
                'active_groups' => 0,
                'last_activity' => 'No activity'
            ]
        ]);
    } else {
        // Build placeholders for prepared statement
        $class_ids_placeholder = implode(",", array_fill(0, count($tenant_class_ids), '?'));

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
        while ($row = $groups->fetch_assoc()) {
            $groups_data[] = $row;
        }

        // Calculate statistics
        $total_groups = count($groups_data);
        $active_groups = 0;
        $last_activity_time = null;

        foreach ($groups_data as $group) {
            if ($group['message_count'] > 0) {
                $active_groups++;
            }
            if ($group['last_activity'] && (!$last_activity_time || $group['last_activity'] > $last_activity_time)) {
                $last_activity_time = $group['last_activity'];
            }
        }

        // Format last activity
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

        $last_activity_formatted = $last_activity_time ? timeAgo($last_activity_time) : 'No activity';

        echo json_encode([
            'success' => true,
            'groups' => $groups_data,
            'stats' => [
                'total_groups' => $total_groups,
                'active_groups' => $active_groups,
                'last_activity' => $last_activity_formatted
            ]
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>