<?php
header("Content-Type: application/json");
include "db.php";

$user_id = $_GET['user_id'] ?? 0;

if ($user_id == 0) {
    echo json_encode([
        "status" => false,
        "message" => "User ID missing"
    ]);
    exit;
}

$sql = "SELECT id, fullname, email, role FROM users WHERE id = ?";
$params = array($user_id);

$stmt = sqlsrv_query($conn, $sql, $params);

$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if ($user) {
    echo json_encode([
        "status" => true,
        "data" => $user
    ]);
} else {
    echo json_encode([
        "status" => false,
        "message" => "User not found"
    ]);
}
?>