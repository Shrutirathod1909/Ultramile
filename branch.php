<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';

$action = $_GET['action'] ?? '';

// JSON + POST support
$rawData = file_get_contents("php://input");
$input = json_decode($rawData, true);

if (!$input) {
    $input = $_POST;
}

/* ================= ADD ================= */
/* ================= ADD ================= */
if ($action == "add") {

    $created_by = $input['user_id'] ?? 1;
    $branch_name = trim($input['branch_name'] ?? '');

    // ❌ Empty name check
    if ($branch_name == '') {
        echo json_encode([
            "status" => "error",
            "message" => "Branch name is required"
        ]);
        exit;
    }

    // ✅ Duplicate Branch Name Check
    $check_sql = "SELECT COUNT(*) as cnt FROM branch WHERE branch_name = ? AND hide = 'N'";
    $check_stmt = sqlsrv_query($conn, $check_sql, [$branch_name]);
    $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);

    if ($row['cnt'] > 0) {
        echo json_encode([
            "status" => "error",
            "message" => "Branch name already exists"
        ]);
        exit;
    }

    // ✅ Unique Branch Code Generate
    do {
        $branch_code = "BR" . rand(1000,9999);

        $check_code_sql = "SELECT COUNT(*) as cnt FROM branch WHERE branch_code = ?";
        $check_code_stmt = sqlsrv_query($conn, $check_code_sql, [$branch_code]);
        $code_row = sqlsrv_fetch_array($check_code_stmt, SQLSRV_FETCH_ASSOC);

    } while ($code_row['cnt'] > 0);

    // ✅ Insert
    $sql = "INSERT INTO branch 
    (branch_name, address, city, email, pincode, branch_code, hide, created_by, created_on)
    VALUES (?, ?, ?, ?, ?, ?, 'N', ?, GETDATE())";

    $params = [
        $branch_name,
        $input['address'] ?? '',
        $input['city'] ?? '',
        $input['email'] ?? '',
        $input['pincode'] ?? '',
        $branch_code,
        $created_by
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt) {
        echo json_encode([
            "status" => "success",
            "message" => "Branch Added",
            "branch_code" => $branch_code
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "error" => sqlsrv_errors()
        ]);
    }
}


/* ================= UPDATE ================= */
elseif ($action == "update") {

    $modified_by = $input['user_id'] ?? 1;
    $branch_name = trim($input['branch_name'] ?? '');
    $id = $input['id'] ?? 0;

    // ❌ Validation
    if ($branch_name == '' || $id == 0) {
        echo json_encode([
            "status" => "error",
            "message" => "Invalid data"
        ]);
        exit;
    }

    // ✅ Duplicate check (ignore same id)
    $check_sql = "SELECT COUNT(*) as cnt 
                  FROM branch 
                  WHERE branch_name = ? 
                  AND id != ? 
                  AND hide = 'N'";

    $check_stmt = sqlsrv_query($conn, $check_sql, [$branch_name, $id]);
    $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);

    if ($row['cnt'] > 0) {
        echo json_encode([
            "status" => "error",
            "message" => "Branch name already exists"
        ]);
        exit;
    }

    // ✅ Update
    $sql = "UPDATE branch SET
        branch_name = ?, address = ?, city = ?, email = ?, pincode = ?,
        modified_by = ?, modified_on = GETDATE()
        WHERE id = ?";

    $params = [
        $branch_name,
        $input['address'] ?? '',
        $input['city'] ?? '',
        $input['email'] ?? '',
        $input['pincode'] ?? '',
        $modified_by,
        $id
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt) {
        echo json_encode([
            "status" => "success",
            "message" => "Branch Updated"
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "error" => sqlsrv_errors()
        ]);
    }
}
/* ================= DELETE (SOFT DELETE) ================= */
elseif ($action == "delete") {

    $disabled_by = $input['user_id'] ?? 1;

    $sql = "UPDATE branch SET
        hide = 'Y',
        disabled_by = ?,
        disabled_on = GETDATE()
        WHERE id = ?";

    $params = [
        $disabled_by,
        $input['id']
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt) {
        echo json_encode(["status" => "success", "message" => "Branch Deleted"]);
    } else {
        echo json_encode(["status" => "error", "error" => sqlsrv_errors()]);
    }
}

/* ================= LIST ================= */
elseif ($action == "list") {

    $sql = "SELECT * FROM branch WHERE hide = 'N' ORDER BY id DESC";
    $stmt = sqlsrv_query($conn, $sql);

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode($data);
}

/* ================= GET SINGLE ================= */
elseif ($action == "get") {

    $sql = "SELECT * FROM branch WHERE id = ?";
    $params = [$input['id']];

    $stmt = sqlsrv_query($conn, $sql, $params);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    echo json_encode($row);
}

/* ================= INVALID ================= */
else {
    echo json_encode(["status" => "error", "message" => "Invalid Action"]);
}
?>