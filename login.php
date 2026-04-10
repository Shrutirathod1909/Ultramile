<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");

require_once "db.php";

// INPUT
$data = json_decode(file_get_contents("php://input"), true);

$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

// VALIDATION
if (empty($email) || empty($password)) {
    echo json_encode([
        "status" => false,
        "message" => "Email and Password required"
    ]);
    exit;
}

// 🔐 Convert input password to MD5
$hashedPassword = md5($password);

// QUERY
$sql = "SELECT * FROM users WHERE email = ?";
$params = [$email];

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

// LOGIN CHECK
if ($user) {

    // ✅ Compare hashed password
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
?>