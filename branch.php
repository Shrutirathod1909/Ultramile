<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
include 'db.php';

$action = $_GET['action'] ?? '';

// ================= INPUT =================
$rawData = file_get_contents("php://input");
$input = json_decode($rawData, true);

if (!$input) {
    $input = $_POST;
}

/* ================= IMAGE UPLOAD HANDLER ================= */
$address_proof_img = "";
$company_authorization_img = "";

// upload folder
$uploadPath = "uploads/";

if (!file_exists($uploadPath)) {
    mkdir($uploadPath, 0777, true);
}

// ADDRESS PROOF
if (isset($_FILES['address_proof_img'])) {
    $file1 = time() . "_proof.jpg";
    move_uploaded_file($_FILES['address_proof_img']['tmp_name'], $uploadPath . $file1);
    $address_proof_img = $file1;
}

// AUTH IMAGE
if (isset($_FILES['company_authorization_img'])) {
    $file2 = time() . "_auth.jpg";
    move_uploaded_file($_FILES['company_authorization_img']['tmp_name'], $uploadPath . $file2);
    $company_authorization_img = $file2;
}
/* ================= ADD ================= */
if ($action == "add") {

    $branch_name = $_POST['branch_name'] ?? '';
    $address = $_POST['address'] ?? '';
    $city = $_POST['city'] ?? '';
    $email = $_POST['email'] ?? '';
    $pincode = $_POST['pincode'] ?? '';
    $contact_no = $_POST['contact_no'] ?? '';
    $contact_person = $_POST['contact_person'] ?? '';
    $user_id = $_POST['user_id'] ?? 1;

    if ($branch_name == '') {
        echo json_encode(["status" => "error", "message" => "Branch required"]);
        exit;
    }

    // INSERT
    $sql = "INSERT INTO branch 
    (branch_name, address, city, email, pincode, contact_no, contact_person,
    address_proof_img, company_authorization_img, hide, created_by, created_on)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'N', ?, GETDATE())";

    $params = [
        $branch_name,
        $address,
        $city,
        $email,
        $pincode,
        $contact_no,
        $contact_person,
        $address_proof_img,
        $company_authorization_img,
        $user_id
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt) {
        echo json_encode(["status" => "success", "message" => "Added"]);
    } else {
        echo json_encode(["status" => "error", "error" => sqlsrv_errors()]);
    }
}

/* ================= UPDATE ================= */
elseif ($action == "update") {

    $sql = "UPDATE branch SET
        branch_name=?,
        address=?,
        city=?,
        email=?,
        pincode=?,
        contact_no=?,
        contact_person=?,
        address_proof_img = CASE WHEN ? != '' THEN ? ELSE address_proof_img END,
        company_authorization_img = CASE WHEN ? != '' THEN ? ELSE company_authorization_img END,
        modified_on=GETDATE()
        WHERE id=?";

    $params = [
        $_POST['branch_name'],
        $_POST['address'],
        $_POST['city'],
        $_POST['email'],
        $_POST['pincode'],
        $_POST['contact_no'],
        $_POST['contact_person'],

        $address_proof_img, $address_proof_img,
        $company_authorization_img, $company_authorization_img,

        $_POST['id']
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    echo json_encode($stmt
        ? ["status"=>"success","message"=>"Updated"]
        : ["status"=>"error"]);
}

/* ================= LIST ================= */
elseif ($action == "list") {

    $sql = "SELECT * FROM branch WHERE hide = 'N' ORDER BY id DESC";
    $stmt = sqlsrv_query($conn, $sql);

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode([
        "status" => "success",
        "data" => $data
    ]);
}

/* ================= GET ================= */
elseif ($action == "get") {

    $sql = "SELECT * FROM branch WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$input['id']]);

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $row
    ]);
}

/* ================= DELETE ================= */
elseif ($action == "delete") {

    $sql = "UPDATE branch SET hide='Y', disabled_on=GETDATE() WHERE id=?";
    $stmt = sqlsrv_query($conn, $sql, [$input['id']]);

    if ($stmt) {
        echo json_encode(["status" => "success", "message" => "Deleted"]);
    } else {
        echo json_encode(["status" => "error"]);
    }
}

/* ================= INVALID ================= */
else {
    echo json_encode(["status" => "error", "message" => "Invalid Action"]);
}
?>