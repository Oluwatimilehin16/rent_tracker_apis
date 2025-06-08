<?php
include 'config.php'; // Adjust path to your config
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = mysqli_real_escape_string($conn, $_POST['password']);

    // Fetch user based on email
    $select_user = mysqli_query($conn, "SELECT * FROM `users` WHERE email='$email'") or die(mysqli_error($conn));

    if (mysqli_num_rows($select_user) > 0) {
        $row = mysqli_fetch_assoc($select_user);

        // Verify password
        if (password_verify($password, $row['password'])) {
            // Check if tenant is in class (for tenants only)
            $class_status = null;
            if ($row['users_role'] != 'landlord') {
                $tenant_id = $row['id'];
                $class_check = mysqli_query($conn, "SELECT * FROM class_members WHERE tenant_id = '$tenant_id'");
                $class_status = (mysqli_num_rows($class_check) > 0) ? 'in_class' : 'no_class';
            }

            echo json_encode([
                'success' => true,
                'user' => [
                    'id' => $row['id'],
                    'firstname' => $row['firstname'],
                    'email' => $row['email'],
                    'users_role' => $row['users_role'],
                    'class_status' => $class_status
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Incorrect password!']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Incorrect email or password!']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>