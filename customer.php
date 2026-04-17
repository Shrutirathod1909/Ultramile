    <?php
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    header("Content-Type: application/json");
    include 'db.php';

    if (!$conn) {
        die(json_encode(sqlsrv_errors()));
    }

    $action = $_GET['action'] ?? '';
    $input = json_decode(file_get_contents("php://input"), true);

    /* ================= LIST ================= */
    if ($action == "list") {

        $sql = "SELECT * FROM customers WHERE hide='N' ORDER BY customer_id DESC";
        $stmt = sqlsrv_query($conn, $sql);

        $data = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $data[] = $row;
        }

        echo json_encode(["status"=>true,"data"=>$data]);
    }

    /* ================= INSERT ================= */
    elseif ($action == "insert") {

        $email = trim($input['email_id'] ?? '');
        $phone = trim($input['phone'] ?? '');

        // EMAIL CHECK
        if ($email != '') {
            $sql = "SELECT COUNT(*) as cnt FROM customers WHERE LOWER(email_id)=?";
            $stmt = sqlsrv_query($conn, $sql, [strtolower($email)]);
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

            if ($row['cnt'] > 0) {
                echo json_encode(["status"=>false,"message"=>"Email already exists"]);
                exit;
            }
        }

        // PHONE CHECK
        if ($phone != '') {
            $sql = "SELECT COUNT(*) as cnt FROM customers WHERE phone=?";
            $stmt = sqlsrv_query($conn, $sql, [$phone]);
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

            if ($row['cnt'] > 0) {
                echo json_encode(["status"=>false,"message"=>"Phone already exists"]);
                exit;
            }
        }

        // INSERT
        $sql = "INSERT INTO customers (
            customer_name, email_id, address, phone, city, pincode,
            contactable_person, pancard_no, registration_no,
            vat_no, gst_no, gst_code,
            ac_no, bank_name, ifsc_code, micr_no,
            created_on, created_by, hide
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, ?)";

        $params = [
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
            1,
            'N'
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);

        echo json_encode([
            "status"=>$stmt ? true : false,
            "message"=>$stmt ? "Customer Created Successfully" : sqlsrv_errors()
        ]);
    }

    /* ================= UPDATE ================= */
    elseif ($action == "update") {

        $id = intval($input['id'] ?? 0);
        $email = $input['email_id'] ?? '';
        $phone = $input['phone'] ?? '';

        // EMAIL CHECK
        $sql = "SELECT COUNT(*) as cnt FROM customers WHERE email_id=? AND customer_id!=?";
        $stmt = sqlsrv_query($conn, $sql, [$email, $id]);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row['cnt'] > 0) {
            echo json_encode(["status"=>false,"message"=>"Email exists"]);
            exit;
        }

        // PHONE CHECK
        $sql = "SELECT COUNT(*) as cnt FROM customers WHERE phone=? AND customer_id!=?";
        $stmt = sqlsrv_query($conn, $sql, [$phone, $id]);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row['cnt'] > 0) {
            echo json_encode(["status"=>false,"message"=>"Phone exists"]);
            exit;
        }

        $sql = "UPDATE customers SET
            customer_name=?, email_id=?, address=?, phone=?, city=?, pincode=?,
            contactable_person=?, pancard_no=?, registration_no=?, vat_no=?,
            gst_no=?, gst_code=?, ac_no=?, bank_name=?, ifsc_code=?, micr_no=?,
            modified_on=GETDATE(), modified_by=?
            WHERE customer_id=?";

        $params = [
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
            1,
            $id
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);

        echo json_encode([
            "status"=>$stmt ? true : false,
            "message"=>$stmt ? "Updated" : sqlsrv_errors()
        ]);
    }

    /* ================= DELETE ================= */
    elseif ($action == "delete") {

        $id = intval($input['id'] ?? 0);

        $sql = "UPDATE customers SET hide='Y' WHERE customer_id=?";
        $stmt = sqlsrv_query($conn, $sql, [$id]);

        echo json_encode([
            "status"=>$stmt ? true : false,
            "message"=>"Deleted"
        ]);
    }
elseif ($action == "single") {

    $id = intval($_GET['id'] ?? 0);

    $sql = "SELECT * FROM customers WHERE customer_id=? AND hide='N'";
    $stmt = sqlsrv_query($conn, $sql, [$id]);

    $data = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if ($data) {
        echo json_encode(["status" => true, "data" => $data]);
    } else {
        echo json_encode(["status" => false, "message" => "Customer not found"]);
    }
}

/* =====================================================
   INVALID ACTION
===================================================== */
else {
    echo json_encode(["status"=>false,"message"=>"Invalid action"]);
}

    ?>