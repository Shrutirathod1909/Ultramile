<?php
header("Content-Type: application/json");

error_reporting(E_ALL);
ini_set('display_errors', 1);

include "db.php";

// ================= DB CHECK =================
if ($conn === false) {
    echo json_encode([
        "status" => false,
        "message" => "DB connection failed",
        "error" => sqlsrv_errors()
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    // ================= GET =================
    case 'GET':

        // ===== MASTER LIST (DROPDOWN) =====
      if (isset($_GET['type']) && $_GET['type'] == 'master') {

    $modules = [];
    $branches = [];
    $users = [];

    // ===== MODULES =====
    $q1 = sqlsrv_query($conn, "SELECT module_id, module_name FROM modules");
    if ($q1) {
        while ($r = sqlsrv_fetch_array($q1, SQLSRV_FETCH_ASSOC)) {
            $modules[] = $r;
        }
    }

    // ===== BRANCHES =====
    $q2 = sqlsrv_query($conn, "SELECT id, branch_name FROM branch");
    if ($q2) {
        while ($r = sqlsrv_fetch_array($q2, SQLSRV_FETCH_ASSOC)) {
            $branches[] = $r;
        }
    }

    // ===== USERS =====
    $q3 = sqlsrv_query($conn, "SELECT id, fullname FROM users ORDER BY fullname ASC");
    if ($q3) {
        while ($r = sqlsrv_fetch_array($q3, SQLSRV_FETCH_ASSOC)) {
            $users[] = $r;
        }
    }

    echo json_encode([
        "status" => true,
        "modules" => $modules,
        "branches" => $branches,
        "users" => $users
    ]);

    exit;
}

        // ===== FULL USER LIST =====
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

            // ================= MODULES =================
            $moduleData = [];
            $raw = $row['modules'] ?? '';

            if (!empty($raw)) {
                $items = array_unique(array_map('trim', explode(",", $raw)));

                foreach ($items as $item) {
                    if (is_numeric($item)) {
                        $q = sqlsrv_query($conn,
                            "SELECT module_name FROM modules WHERE module_id = ?",
                            [$item]
                        );
                        if ($q && $r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) {
                            $moduleData[] = $r['module_name'];
                        }
                    } else {
                        $moduleData[] = $item;
                    }
                }
            }

            // ================= BRANCH =================
            $branchData = [];
            $raw = $row['access_branch'] ?? '';

            if (!empty($raw)) {
                $items = array_unique(array_map('trim', explode(",", $raw)));

                foreach ($items as $item) {
                    if (is_numeric($item)) {
                        $q = sqlsrv_query($conn,
                            "SELECT branch_name FROM branch WHERE id = ?",
                            [$item]
                        );
                        if ($q && $r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) {
                            $branchData[] = $r['branch_name'];
                        }
                    } else {
                        $branchData[] = $item;
                    }
                }
            }

            // ================= REPORTING USERS =================
            $reportingNames = [];
            $rawUsers = $row['reporting_users'] ?? '';

            if (!empty($rawUsers)) {
                $ids = array_unique(array_filter(array_map('trim', explode(",", $rawUsers))));

                foreach ($ids as $id) {
                    $id = (int)$id;

                    if ($id > 0) {
                        $q = sqlsrv_query($conn,
                            "SELECT fullname FROM users WHERE id = ?",
                            [$id]
                        );
                        if ($q && $r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)) {
                            $reportingNames[] = $r['fullname'];
                        }
                    }
                }
            }

            // ================= FINAL OUTPUT =================
            $row['modules'] = $moduleData;
            $row['access_branch'] = $branchData;
            $row['reporting_users'] = $reportingNames;

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

        if (!$data) {
            echo json_encode([
                "status" => false,
                "message" => "Invalid JSON"
            ]);
            exit;
        }

        $modules  = implode(",", array_unique($data['modules'] ?? []));
        $branches = implode(",", array_unique($data['branches'] ?? []));
        $users    = implode(",", array_unique($data['reporting_users'] ?? []));

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

        if (!$stmt) {
            echo json_encode([
                "status" => false,
                "error" => sqlsrv_errors()
            ]);
            exit;
        }

        echo json_encode([
            "status" => true,
            "message" => "User created"
        ]);

        break;

    // ================= UPDATE =================
    case 'PUT':

        $data = json_decode(file_get_contents("php://input"), true);

        if (!$data) {
            echo json_encode([
                "status" => false,
                "message" => "Invalid JSON"
            ]);
            exit;
        }

        $modules  = implode(",", array_unique($data['modules'] ?? []));
        $branches = implode(",", array_unique($data['branches'] ?? []));
        $users    = implode(",", array_unique($data['reporting_users'] ?? []));

        $password = !empty($data['password']) ? md5($data['password']) : null;

        if ($password) {
            $sql = "UPDATE users SET fullname=?, email=?, phone=?, city=?, role=?, password=?, modules=?, access_branch=?, reporting_users=? WHERE id=?";
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
            $sql = "UPDATE users SET fullname=?, email=?, phone=?, city=?, role=?, modules=?, access_branch=?, reporting_users=? WHERE id=?";
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

        if (!$stmt) {
            echo json_encode([
                "status" => false,
                "error" => sqlsrv_errors()
            ]);
            exit;
        }

        echo json_encode([
            "status" => true,
            "message" => "User updated"
        ]);

        break;

    // ================= DELETE =================
    case 'DELETE':

        $id = $_GET['id'] ?? 0;

        $stmt = sqlsrv_query($conn, "DELETE FROM users WHERE id = ?", [$id]);

        if (!$stmt) {
            echo json_encode([
                "status" => false,
                "error" => sqlsrv_errors()
            ]);
            exit;
        }

        echo json_encode([
            "status" => true,
            "message" => "User deleted"
        ]);

        break;
}
?>