<?php
include 'config.php'; // Your database config in API folder
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $firstname = htmlspecialchars($_POST["firstname"]);
    $lastname  = htmlspecialchars($_POST["lastname"]);
    $email     = htmlspecialchars($_POST["email"]);
    $password  = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $users_role = htmlspecialchars($_POST["users_role"]);

    // Check if the user already exists
    $select_user = mysqli_query($conn, "SELECT * FROM `users` WHERE email='$email'") or die(mysqli_error($conn));

    if (mysqli_num_rows($select_user) > 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'User already exists!'
        ]);
    } else {
        $query = "INSERT INTO `users`(`firstname`, `lastname`, `email`, `password`, `users_role`)
                  VALUES ('$firstname','$lastname', '$email', '$password', '$users_role')";
        
        if (mysqli_query($conn, $query)) {
            $users_id = mysqli_insert_id($conn);
            
            echo json_encode([
                'success' => true,
                'message' => 'Registration successful!',
                'user' => [
                    'id' => $users_id,
                    'firstname' => $firstname,
                    'email' => $email,
                    'users_role' => $users_role
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Registration failed, please try again!'
            ]);
        }
    }
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request method'
    ]);
}
?>