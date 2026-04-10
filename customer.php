<?php
error_reporting(0);
ini_set('display_errors', 0);

/* ================= INCLUDE DB ================= */
include 'db.php';

/* ================= GET ACTION ================= */
$action = $_GET['action'] ?? '';

/* ================= READ JSON INPUT ================= */
$input = json_decode(file_get_contents("php://input"), true);

/* =====================================================
   1. GET CUSTOMER LIST
===================================================== */
if ($action == "list") {

    $search = $_GET['search'] ?? '';

    if ($search != '') {
        $sql = "SELECT * FROM customers 
                WHERE hide='N' 
                AND (customer_name LIKE ? OR email_id LIKE ?)
                ORDER BY customer_id DESC";
        $params = array("%$search%", "%$search%");
    } else {
        $sql = "SELECT * FROM customers 
                WHERE hide='N' 
                ORDER BY customer_id DESC";
        $params = array();
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    $data = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode(["status"=>true,"data"=>$data]);
}

/* =====================================================
   2. INSERT CUSTOMER
===================================================== */
/* =====================================================
   2. INSERT CUSTOMER WITH UNIQUE EMAIL/PHONE CHECK
===================================================== */
elseif ($action == "insert") {

    $email = $input['email'] ?? '';
    $phone = $input['phone'] ?? '';

    // Check if email or phone already exists
    $checkSql = "SELECT customer_id FROM customers WHERE email_id = ? OR phone = ?";
    $checkParams = array($email, $phone);
    $checkStmt = sqlsrv_query($conn, $checkSql, $checkParams);

    if (sqlsrv_has_rows($checkStmt)) {
        echo json_encode([
            "status" => false,
            "message" => "Email or Phone already exists"
        ]);
        exit;
    }

    // If not exists, insert
    $sql = "INSERT INTO customers (
        customer_name, email_id, address, phone, city, pincode,
        contactable_person, pancard_no, registration_no,
        vat_no, gst_no, gst_code,
        ac_no, bank_name, ifsc_code, micr_no,
        created_on, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?)";

    $params = array(
        $input['customer_name'] ?? '',
        $email,
        $input['address'] ?? '',
        $phone,
        $input['city'] ?? '',
        $input['pincode'] ?? '',
        $input['contactable_person'] ?? '',
        $input['pancard_no'] ?? '',
        $input['registration_no'] ?? '',
        $input['vat_no'] ?? '',
        $input['gst_no'] ?? '',
        $input['gst_code'] ?? '',
        $input['ac_no'] ?? '',
        $input['bank_name'] ?? '',
        $input['ifsc_code'] ?? '',
        $input['micr_no'] ?? '',
        1 // created_by
    );

    $stmt = sqlsrv_query($conn, $sql, $params);

    echo json_encode([
        "status"=>$stmt ? true : false,
        "message"=>$stmt ? "Inserted" : sqlsrv_errors()
    ]);
}

/* =====================================================
   3. UPDATE CUSTOMER
===================================================== */
elseif ($action == "update") {

    $id = intval($input['id'] ?? 0);
    $email = $input['email'] ?? '';
    $phone = $input['phone'] ?? '';

    // 1️⃣ Check duplicate email (ignore current record)
    $check_email = "SELECT COUNT(*) as cnt FROM customers WHERE email_id = ? AND customer_id != ?";
    $stmt_email = sqlsrv_query($conn, $check_email, array($email, $id));
    $row_email = sqlsrv_fetch_array($stmt_email, SQLSRV_FETCH_ASSOC);

    if ($row_email['cnt'] > 0) {
        echo json_encode(["status"=>false, "message"=>"Email already exists"]);
        exit;
    }

    // 2️⃣ Check duplicate phone (ignore current record)
    $check_phone = "SELECT COUNT(*) as cnt FROM customers WHERE phone = ? AND customer_id != ?";
    $stmt_phone = sqlsrv_query($conn, $check_phone, array($phone, $id));
    $row_phone = sqlsrv_fetch_array($stmt_phone, SQLSRV_FETCH_ASSOC);

    if ($row_phone['cnt'] > 0) {
        echo json_encode(["status"=>false, "message"=>"Phone number already exists"]);
        exit;
    }

    // 3️⃣ Proceed to update
    $sql = "UPDATE customers SET
        customer_name = ?, email_id = ?, address = ?, phone = ?, city = ?, pincode = ?,
        contactable_person = ?, pancard_no = ?, registration_no = ?, vat_no = ?,
        gst_no = ?, gst_code = ?, ac_no = ?, bank_name = ?, ifsc_code = ?, micr_no = ?,
        modified_on = GETDATE(), modified_by = ?
        WHERE customer_id = ?";

    $params = array(
        $input['customer_name'] ?? '',
        $email,
        $input['address'] ?? '',
        $phone,
        $input['city'] ?? '',
        $input['pincode'] ?? '',
        $input['contactable_person'] ?? '',
        $input['pancard_no'] ?? '',
        $input['registration_no'] ?? '',
        $input['vat_no'] ?? '',
        $input['gst_no'] ?? '',
        $input['gst_code'] ?? '',
        $input['ac_no'] ?? '',
        $input['bank_name'] ?? '',
        $input['ifsc_code'] ?? '',
        $input['micr_no'] ?? '',
        1, // modified_by
        $id
    );

    $stmt = sqlsrv_query($conn, $sql, $params);

    echo json_encode([
        "status"=>$stmt ? true : false,
        "message"=>$stmt ? "Updated" : sqlsrv_errors()
    ]);
}

/* =====================================================
   4. DELETE (SOFT DELETE)
===================================================== */
elseif ($action == "delete") {

    $id = intval($input['id'] ?? 0); // ensure it's integer

    $sql = "UPDATE customers SET 
            hide = ?, 
            disabled_on = GETDATE(), 
            disabled_by = ?
            WHERE customer_id = ?";

    $params = array('Y', 1, $id); // hide='Y', disabled_by=1, customer_id=$id

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode([
            "status" => false,
            "message" => sqlsrv_errors()
        ]);
    } else {
        echo json_encode([
            "status" => true,
            "message" => "Deleted"
        ]);
    }
}

/* =====================================================
   5. GET SINGLE CUSTOMER
===================================================== */
elseif ($action == "single") {

    $id = $_GET['id'] ?? 0;

    $sql = "SELECT * FROM customers WHERE customer_id = ?";
    $params = array($id);

    $stmt = sqlsrv_query($conn, $sql, $params);

    $data = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    echo json_encode(["status"=>true,"data"=>$data]);
}

/* =====================================================
   INVALID ACTION
===================================================== */
else {
    echo json_encode(["status"=>false,"message"=>"Invalid action"]);
}
?>