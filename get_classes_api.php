<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get landlord_id from query parameter
if (!isset($_GET['landlord_id']) || empty($_GET['landlord_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing landlord_id parameter']);
    exit();
}

$landlord_id = mysqli_real_escape_string($conn, $_GET['landlord_id']);

// Get all classes created by the landlord
$classes_query = "SELECT id, class_name, class_code, created_at 
                  FROM classes 
                  WHERE landlord_id = '$landlord_id' 
                  ORDER BY class_name ASC";

$classes_result = mysqli_query($conn, $classes_query);

if ($classes_result) {
    $classes = [];
    while ($class = mysqli_fetch_assoc($classes_result)) {
        $classes[] = $class;
    }
    
    echo json_encode([
        'success' => true,
        'classes' => $classes,
        'count' => count($classes)
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . mysqli_error($conn)
    ]);
}

mysqli_close($conn);
?>