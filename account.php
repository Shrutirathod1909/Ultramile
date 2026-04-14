<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json");
require_once "db.php";

function response($status, $data = [], $msg = "") {
    echo json_encode([
        "status" => $status,
        "message" => $msg,
        "data" => $data
    ]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input) $input = $_POST;

$action = $input['action'] ?? '';

// ================= GET =================
if ($_SERVER['REQUEST_METHOD'] == "GET") {

    $type = $_GET['type'] ?? '';

    // ✅ FIXED CREDIT (NOW INCLUDES invoice + status + qty)
    if ($type == "credit") {

        $sql = "
        SELECT 
            o.invoice_no,
            o.status,
            o.customer_name,
            ISNULL(o.email_id,'') AS customer_email,
            ISNULL(o.contact_no,'') AS customer_contact,
            ISNULL(o.address,'') AS customer_address,
            SUM(o.quantity) AS quantity,
            COUNT(DISTINCT o.invoice_no) AS total_orders,
            ISNULL(SUM(bd.total_amount),0) AS total_amount,
            ISNULL(SUM(ph.paid_amount),0) AS paid_amount,
            (ISNULL(SUM(bd.total_amount),0) - ISNULL(SUM(ph.paid_amount),0)) AS balance_amount
        FROM orders o
        LEFT JOIN bill_details bd ON o.invoice_no = bd.invoice_no
        LEFT JOIN payment_history ph ON o.invoice_no = ph.bill_no
        WHERE o.fulfilled = '1'
        AND o.status = 'Approve With Credit'
        GROUP BY 
            o.invoice_no,
            o.status,
            o.customer_name,
            o.email_id,
            o.contact_no,
            o.address
        ORDER BY o.invoice_no DESC
        ";
    }

    // ================= LEDGER =================
    else {

        $sql = "
        SELECT 
            o.invoice_no,
            o.status,
            o.customer_name,
            ISNULL(o.email_id,'') as customer_email,
            ISNULL(o.contact_no,'') as customer_contact,
            o.to_branch,
            SUM(o.quantity) as quantity,
            CAST(o.created_on AS DATE) as order_date,
            ISNULL(bd.total_amount,0) as final_amount,
            ISNULL(SUM(ph.paid_amount),0) as paid_amount,
            (ISNULL(bd.total_amount,0) - ISNULL(SUM(ph.paid_amount),0)) as balance_amount
        FROM orders o
        LEFT JOIN bill_details bd ON o.invoice_no = bd.invoice_no
        LEFT JOIN payment_history ph ON o.invoice_no = ph.bill_no
        WHERE o.fulfilled = '1'
        GROUP BY 
            o.invoice_no,
            o.status,
            o.customer_name,
            o.email_id,
            o.contact_no,
            o.to_branch,
            o.created_on,
            bd.total_amount
        ORDER BY o.created_on DESC
        ";
    }

    $stmt = sqlsrv_query($conn, $sql);

    $data = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    response(true, $data, "Success");
}

// ================= POST =================
if ($_SERVER['REQUEST_METHOD'] == "POST") {

    // ✅ FIXED STATUS UPDATE
    if ($action == "update_status") {

        $invoice_no = $input['invoice_no'] ?? '';
        $status = $input['status'] ?? '';

        if ($invoice_no == '' || $status == '') {
            response(false, [], "Missing invoice or status");
        }

        $sql = "UPDATE orders SET status=? WHERE invoice_no=?";
        $stmt = sqlsrv_query($conn, $sql, [$status, $invoice_no]);

        if (!$stmt) {
            response(false, [], "Update Failed");
        }

        response(true, [], "Status Updated");
    }

    // ================= PAYMENT =================
    elseif ($action == "transaction") {

        $bill_no = $input['bill_no'] ?? '';
        $amount = (float)($input['amount'] ?? 0);

        if ($bill_no == '' || $amount <= 0) {
            response(false, [], "Invalid input");
        }

        $q1 = sqlsrv_query($conn,
            "SELECT ISNULL(total_amount,0) AS total FROM bill_details WHERE invoice_no=?",
            [$bill_no]
        );

        $total = sqlsrv_fetch_array($q1, SQLSRV_FETCH_ASSOC)['total'];

        $q2 = sqlsrv_query($conn,
            "SELECT ISNULL(SUM(paid_amount),0) AS paid FROM payment_history WHERE bill_no=?",
            [$bill_no]
        );

        $paid = sqlsrv_fetch_array($q2, SQLSRV_FETCH_ASSOC)['paid'];

        $balance = $total - $paid;

        if ($amount > $balance) {
            response(false, [], "Payment exceeds balance");
        }

        sqlsrv_query($conn,
            "INSERT INTO payment_history (bill_no, paid_amount, payment_date)
             VALUES (?, ?, GETDATE())",
            [$bill_no, $amount]
        );

        response(true, [
            "remaining" => $balance - $amount
        ], "Payment Success");
    }

    response(false, [], "Invalid Action");
}
?>