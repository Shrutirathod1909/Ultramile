<?php
header("Content-Type: application/json");

error_reporting(E_ALL);
ini_set('display_errors', 1);

include "db.php";

if (!$conn) {
    echo json_encode([
        "status" => false,
        "message" => "DB connection failed",
        "error" => sqlsrv_errors()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    // ================= MASTER =================
    case 'GET':

        if (isset($_GET['type']) && $_GET['type'] == 'master') {

            $modules = [];
            $q1 = sqlsrv_query($conn, "SELECT * FROM modules");
            while ($r = sqlsrv_fetch_array($q1, SQLSRV_FETCH_ASSOC)) {
                $modules[] = $r;
            }

            $branches = [];
            $q2 = sqlsrv_query($conn, "SELECT * FROM branch");
            while ($r = sqlsrv_fetch_array($q2, SQLSRV_FETCH_ASSOC)) {
                $branches[] = $r;
            }

            $users = [];
            $q3 = sqlsrv_query($conn, "SELECT id, fullname FROM users");
            while ($r = sqlsrv_fetch_array($q3, SQLSRV_FETCH_ASSOC)) {
                $users[] = $r;
            }

            echo json_encode([
                "status" => true,
                "modules" => $modules,
                "branches" => $branches,
                "users" => $users
            ]);
            exit;
        }

        // ================= USERS LIST =================
        $sql = "SELECT * FROM users ORDER BY id DESC";
        $stmt = sqlsrv_query($conn, $sql);

        $data = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

            $row['modules'] = !empty($row['modules']) 
                ? array_map('trim', explode(",", $row['modules'])) 
                : [];

            $row['access_branch'] = !empty($row['access_branch']) 
                ? array_map('trim', explode(",", $row['access_branch'])) 
                : [];

            $row['reporting_users'] = !empty($row['reporting_users']) 
                ? array_map('trim', explode(",", $row['reporting_users'])) 
                : [];

            $data[] = $row;
        }

        echo json_encode([
            "status" => true,
            "data" => $data
        ]);
        break;

    // ================= CREATE =================
    case 'POST':

        $data = json_decode(file_get_contents("php://input"), true);

        $modules  = implode(",", array_map('trim', $data['modules'] ?? []));
        $branches = implode(",", array_map('trim', $data['branches'] ?? []));
        $users    = implode(",", array_map('trim', $data['reporting_users'] ?? []));

        $sql = "INSERT INTO users 
        (fullname, email, phone, city, role, password, modules, access_branch, reporting_users)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $data['fullname'],
            $data['email'],
            $data['phone'],
            $data['city'],
            $data['role'],
            md5($data['password']),
            $modules,
            $branches,
            $users
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);

        echo json_encode([
            "status" => $stmt ? true : false,
            "message" => $stmt ? "User created" : sqlsrv_errors()
        ]);
        break;

    // ================= UPDATE =================
   case 'POST':

    $data = json_decode(file_get_contents("php://input"), true);

    // 🔥 UPDATE CHECK
    if (isset($_GET['type']) && $_GET['type'] == 'update') {

        $modules  = implode(",", array_map('trim', $data['modules'] ?? []));
        $branches = implode(",", array_map('trim', $data['branches'] ?? []));
        $users    = implode(",", array_map('trim', $data['reporting_users'] ?? []));

        $password = !empty($data['password']) ? md5($data['password']) : null;

        if ($password) {
            $sql = "UPDATE users SET 
            fullname=?, email=?, phone=?, city=?, role=?, 
            password=?, modules=?, access_branch=?, reporting_users=? 
            WHERE id=?";
            $params = [
                $data['fullname'],
                $data['email'],
                $data['phone'],
                $data['city'],
                $data['role'],
                $password,
                $modules,
                $branches,
                $users,
                $data['id']
            ];
        } else {
            $sql = "UPDATE users SET 
            fullname=?, email=?, phone=?, city=?, role=?, 
            modules=?, access_branch=?, reporting_users=? 
            WHERE id=?";
            $params = [
                $data['fullname'],
                $data['email'],
                $data['phone'],
                $data['city'],
                $data['role'],
                $modules,
                $branches,
                $users,
                $data['id']
            ];
        }

        $stmt = sqlsrv_query($conn, $sql, $params);

        echo json_encode([
            "status" => $stmt ? true : false,
            "message" => $stmt ? "User updated" : sqlsrv_errors()
        ]);
        exit;
    } // ================= DELETE =================
    case 'DELETE':

        $id = $_GET['id'] ?? 0;

        $stmt = sqlsrv_query($conn, "DELETE FROM users WHERE id=?", [$id]);

        echo json_encode([
            "status" => $stmt ? true : false,
            "message" => $stmt ? "User deleted" : sqlsrv_errors()
        ]);
        break;
}
?>