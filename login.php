<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");

require_once "db.php";

$data = json_decode(file_get_contents("php://input"), true);

$action = $data['action'] ?? "";

/* ================= LOGIN ================= */
if ($action == "login") {

    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode([
            "status" => false,
            "message" => "Email and Password required"
        ]);
        exit;
    }

    $hashedPassword = md5($password);

    $sql = "SELECT * FROM users WHERE email = ?";
    $params = array($email);

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode([
            "status" => false,
            "message" => "Query Failed",
            "error" => sqlsrv_errors()
        ]);
        exit;
    }

    $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if ($user) {

        if ($hashedPassword == $user['password']) {

            echo json_encode([
                "status" => true,
                "message" => "Login Successful",
                "user" => [
                    "id" => $user['id'],
                    "fullname" => $user['fullname'],
                    "email" => $user['email']
                ]
            ]);

        } else {
            echo json_encode([
                "status" => false,
                "message" => "Wrong Password"
            ]);
        }

    } else {
        echo json_encode([
            "status" => false,
            "message" => "Email not found"
        ]);
    }
}

/* ================= LOGOUT ================= */
elseif ($action == "logout") {

    $user_id = $data['user_id'] ?? '';

    if (empty($user_id)) {
        echo json_encode([
            "status" => false,
            "message" => "User ID required"
        ]);
        exit;
    }

    // optional tracking logout time
    $sql = "UPDATE users SET last_logout = GETDATE() WHERE id = ?";
    $params = array($user_id);

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt) {
        echo json_encode([
            "status" => true,
            "message" => "Logout Successful"
        ]);
    } else {
        echo json_encode([
            "status" => false,
            "message" => "Logout Failed",
            "error" => sqlsrv_errors()
        ]);
    }
}

/* ================= INVALID ================= */
else {
    echo json_encode([
        "status" => false,
        "message" => "Invalid action"
    ]);
}
?>