
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

    // ================= MASTER DATA =================
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

        if (!$stmt) {
            echo json_encode([
                "status" => false,
                "error" => sqlsrv_errors()
            ]);
            exit;
        }

        $data = [];

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

            $row['modules'] = !empty($row['modules']) ? explode(",", $row['modules']) : [];
            $row['access_branch'] = !empty($row['access_branch']) ? explode(",", $row['access_branch']) : [];
            $row['reporting_users'] = !empty($row['reporting_users']) ? explode(",", $row['reporting_users']) : [];

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

        $fullname = $data['fullname'] ?? '';
        $email    = $data['email'] ?? '';
        $phone    = $data['phone'] ?? '';
        $city     = $data['city'] ?? '';
        $role     = $data['role'] ?? '';

        // 🔥 MD5 PASSWORD
        $password = md5($data['password'] ?? '');

        $modules  = implode(",", $data['modules'] ?? []);
        $branches = implode(",", $data['branches'] ?? []);
        $users    = implode(",", $data['reporting_users'] ?? []);

        $sql = "INSERT INTO users 
        (fullname, email, phone, city, role, password, modules, access_branch, reporting_users)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $fullname,
            $email,
            $phone,
            $city,
            $role,
            $password,
            $modules,
            $branches,
            $users
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);

        if (!$stmt) {
            echo json_encode([
                "status" => false,
                "error" => sqlsrv_errors()
            ]);
        } else {
            echo json_encode([
                "status" => true,
                "message" => "User created"
            ]);
        }
        break;


    // ================= UPDATE =================
    case 'PUT':

        $data = json_decode(file_get_contents("php://input"), true);

        $id = $data['id'] ?? 0;

        $fullname = $data['fullname'] ?? '';
        $email    = $data['email'] ?? '';
        $phone    = $data['phone'] ?? '';
        $city     = $data['city'] ?? '';
        $role     = $data['role'] ?? '';

        // optional password update
        $password = !empty($data['password']) ? md5($data['password']) : null;

        $modules  = implode(",", $data['modules'] ?? []);
        $branches = implode(",", $data['branches'] ?? []);
        $users    = implode(",", $data['reporting_users'] ?? []);

        if ($password) {
            $sql = "UPDATE users SET 
            fullname=?, email=?, phone=?, city=?, role=?, 
            password=?, modules=?, access_branch=?, reporting_users=? 
            WHERE id=?";

            $params = [
                $fullname,
                $email,
                $phone,
                $city,
                $role,
                $password,
                $modules,
                $branches,
                $users,
                $id
            ];
        } else {
            $sql = "UPDATE users SET 
            fullname=?, email=?, phone=?, city=?, role=?, 
            modules=?, access_branch=?, reporting_users=? 
            WHERE id=?";

            $params = [
                $fullname,
                $email,
                $phone,
                $city,
                $role,
                $modules,
                $branches,
                $users,
                $id
            ];
        }

        $stmt = sqlsrv_query($conn, $sql, $params);

        if (!$stmt) {
            echo json_encode([
                "status" => false,
                "error" => sqlsrv_errors()
            ]);
        } else {
            echo json_encode([
                "status" => true,
                "message" => "User updated"
            ]);
        }
        break;


    // ================= DELETE =================
    case 'DELETE':

        $id = $_GET['id'] ?? 0;

        $sql = "DELETE FROM users WHERE id=?";
        $stmt = sqlsrv_query($conn, $sql, [$id]);

        echo json_encode([
            "status" => $stmt ? true : false,
            "message" => $stmt ? "User deleted" : sqlsrv_errors()
        ]);
        break;
}
?>