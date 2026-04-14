<?php
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 1);

include "db.php";

function response($status, $data = [], $message = "") {
    echo json_encode([
        "status" => $status,
        "data" => $data,
        "message" => $message
    ]);
    exit;
}

# ================= GET ORDERS =================
if ($_SERVER['REQUEST_METHOD'] == "GET") {

    $page  = isset($_GET['page']) ? (int)$_GET['page'] : 0;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

    $sql = "SELECT 
                o.invoice_no,
                o.customer_name,
                o.to_branch,
                CAST(o.created_on AS DATE) AS fulfilled_date,
                o.order_date,
                o.status,
                SUM(CAST(o.quantity AS INT)) AS quantity,
                SUM(CAST(o.final_amount AS DECIMAL(18,2))) AS total_amount,
                ISNULL(p.paid_amount, 0) AS paid_amount,
                (SUM(CAST(o.final_amount AS DECIMAL(18,2))) - ISNULL(p.paid_amount, 0)) AS balance_amount
            FROM orders o
            LEFT JOIN (
                SELECT bill_no, SUM(CAST(paid_amount AS DECIMAL(18,2))) AS paid_amount
                FROM payment_history
                GROUP BY bill_no
            ) p ON o.invoice_no = p.bill_no
            WHERE o.fulfilled = 1
            GROUP BY o.invoice_no, o.customer_name, o.to_branch, o.created_on, o.order_date, o.status, p.paid_amount
            ORDER BY o.created_on DESC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";

    $stmt = sqlsrv_query($conn, $sql, [$page, $limit]);

    if ($stmt === false) {
        response(false, [], sqlsrv_errors());
    }

    $data = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    response(true, $data, "Orders fetched");
}

# ================= POST =================
if ($_SERVER['REQUEST_METHOD'] == "POST") {

    if (!empty($_POST)) {
        $action = $_POST['action'] ?? '';
        $input = $_POST;
    } else {
        $input = json_decode(file_get_contents("php://input"), true);
        $action = $input['action'] ?? '';
    }

    # ================= PAYMENT =================
    if ($action == "transaction") {

        $uid     = $input['uid'] ?? '';
        $bill_no = $input['bill_no'] ?? '';
        $amount  = (float)($input['amount'] ?? 0);
        $_type   = $input['_type'] ?? '';
        $_id     = $input['_id'] ?? '';
        $bank    = $input['bank_name'] ?? '';

        if ($bill_no == '' || $amount <= 0) {
            response(false, [], "Invalid Input");
        }

        sqlsrv_begin_transaction($conn);

        try {

            $q1 = sqlsrv_query($conn,
                "SELECT SUM(CAST(final_amount AS DECIMAL(18,2))) AS total 
                 FROM orders WHERE invoice_no = ?",
                [$bill_no]
            );

            $total = (float)sqlsrv_fetch_array($q1, SQLSRV_FETCH_ASSOC)['total'];

            $q2 = sqlsrv_query($conn,
                "SELECT ISNULL(SUM(CAST(paid_amount AS DECIMAL(18,2))),0) AS paid 
                 FROM payment_history WHERE bill_no = ?",
                [$bill_no]
            );

            $paid = (float)sqlsrv_fetch_array($q2, SQLSRV_FETCH_ASSOC)['paid'];

            $balance = $total - $paid;

            if ($amount > $balance) {
                throw new Exception("Payment exceeds balance");
            }

            $insert = sqlsrv_query($conn,
                "INSERT INTO payment_history
                (uid, bill_no, paid_amount, _type, _id, bank_name, created_on)
                VALUES (?, ?, ?, ?, ?, ?, GETDATE())",
                [$uid, $bill_no, $amount, $_type, $_id, $bank]
            );

            if ($insert === false) {
                throw new Exception(print_r(sqlsrv_errors(), true));
            }

            sqlsrv_commit($conn);

            response(true, [
                "bill_no" => $bill_no,
                "paid_now" => $amount,
                "remaining" => $balance - $amount
            ], "Payment Success");

        } catch (Exception $e) {
            sqlsrv_rollback($conn);
            response(false, [], $e->getMessage());
        }
    }

    # ================= UPLOAD DOCS =================
    else if ($action == "upload_docs") {

        $bill_no = $_POST['bill_no'] ?? '';

        if ($bill_no == '') {
            response(false, [], "bill_no required");
        }

        # check record exist
        $check = sqlsrv_query($conn, "SELECT invoice_no FROM orders WHERE invoice_no = ?", [$bill_no]);
        if (sqlsrv_fetch_array($check) == null) {
            response(false, [], "Invalid invoice_no");
        }

        $target_dir = "uploads/docs/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        $image1 = null;
        $image2 = null;
        $image3 = null;

        # ---------- IMAGE 1 ----------
        if (isset($_FILES['image1']) && $_FILES['image1']['error'] == 0) {
            $image1 = time() . "_1_" . basename($_FILES["image1"]["name"]);
            if (!move_uploaded_file($_FILES["image1"]["tmp_name"], $target_dir . $image1)) {
                response(false, [], "Image1 upload failed");
            }
        }

        # ---------- IMAGE 2 ----------
        if (isset($_FILES['image2']) && $_FILES['image2']['error'] == 0) {
            $image2 = time() . "_2_" . basename($_FILES["image2"]["name"]);
            if (!move_uploaded_file($_FILES["image2"]["tmp_name"], $target_dir . $image2)) {
                response(false, [], "Image2 upload failed");
            }
        }

        # ---------- IMAGE 3 ----------
        if (isset($_FILES['image3']) && $_FILES['image3']['error'] == 0) {
            $image3 = time() . "_3_" . basename($_FILES["image3"]["name"]);
            if (!move_uploaded_file($_FILES["image3"]["tmp_name"], $target_dir . $image3)) {
                response(false, [], "Image3 upload failed");
            }
        }

        # ---------- UPDATE DB ----------
        $stmt = sqlsrv_query($conn,
            "UPDATE orders SET 
                image1 = COALESCE(?, image1),
                image2 = COALESCE(?, image2),
                image3 = COALESCE(?, image3)
             WHERE invoice_no = ?",
            [$image1, $image2, $image3, $bill_no]
        );

        if ($stmt === false) {
            response(false, [], sqlsrv_errors());
        }

        if (sqlsrv_rows_affected($stmt) == 0) {
            response(false, [], "No record updated");
        }

        response(true, [
            "image1" => $image1,
            "image2" => $image2,
            "image3" => $image3
        ], "Images uploaded + DB updated");
    }

    else {
        response(false, [], "Invalid Action");
    }
}
?>