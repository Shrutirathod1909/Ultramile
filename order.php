<?php
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 1);

include "db.php";

// ===================== HELPER =====================
function errorResponse($msg, $error = null) {
    echo json_encode([
        "status" => false,
        "message" => $msg,
        "error" => $error
    ]);
    exit;
}

// ===================== SAFE TYPE =====================
$type = $_GET['type'] ?? '';

// ===================== GET ALL ORDERS =====================
if ($type == 'get_orders') {

    $sql = "
        SELECT invoice_no,
               customer_name,
               to_branch,
               order_date,
               SUM(quantity) AS quantity,
               SUM(total_price) AS total_amount
        FROM orders
        GROUP BY invoice_no, customer_name, to_branch, order_date
        ORDER BY invoice_no DESC
    ";

    $result = sqlsrv_query($conn, $sql);

    if ($result === false) {
        errorResponse("SQL Error", sqlsrv_errors());
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
    exit;
}

// ===================== CREATE ORDER =====================
if ($type == 'create_order') {

    $invoice_no = $_POST['invoice_no'] ?? '';
    $customer_name = $_POST['customer_name'] ?? '';
    $order_date = $_POST['order_date'] ?? '';
    $to_branch = $_POST['to_branch'] ?? '';

    if (!$invoice_no || !$customer_name) {
        errorResponse("Missing required fields");
    }

    $product_name = $_POST['product_name'] ?? [];
    $product_code = $_POST['product_code'] ?? [];
    $quantity = $_POST['quantity'] ?? [];
    $price = $_POST['price'] ?? [];

    if (!is_array($product_name)) {
        $product_name = [$product_name];
        $product_code = [$product_code];
        $quantity = [$quantity];
        $price = [$price];
    }

    $count = count($product_name);

    if (
        $count == 0 ||
        $count != count($product_code) ||
        $count != count($quantity) ||
        $count != count($price)
    ) {
        errorResponse("Product data mismatch");
    }

    $total_amount = 0;

    $sql = "
        INSERT INTO orders
        (invoice_no, customer_name, product_name, product_code,
         quantity, sale_price, total_price, to_branch, order_date, fulfilled, created_on)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
    ";

    for ($i = 0; $i < $count; $i++) {

        $total = $quantity[$i] * $price[$i];
        $total_amount += $total;

        $params = [
            $invoice_no,
            $customer_name,
            $product_name[$i],
            $product_code[$i],
            $quantity[$i],
            $price[$i],
            $total,
            $to_branch,
            $order_date,
            0
        ];

        $result = sqlsrv_query($conn, $sql, $params);

        if ($result === false) {
            errorResponse("Insert failed", sqlsrv_errors());
        }
    }

    // ================= BILL DETAILS =================
    $bill_sql = "
        INSERT INTO bill_details
        (invoice_no, customer_name, total_amount, received_amount, balance_amount, created_on)
        VALUES (?, ?, ?, 0, ?, GETDATE())
    ";

    $bill = sqlsrv_query($conn, $bill_sql, [
        $invoice_no,
        $customer_name,
        $total_amount,
        $total_amount
    ]);

    if ($bill === false) {
        errorResponse("Bill insert failed", sqlsrv_errors());
    }

    echo json_encode([
        "status" => true,
        "message" => "Order created successfully",
        "invoice_no" => $invoice_no,
        "total_amount" => $total_amount
    ]);
    exit;
}

// ===================== ORDER DETAILS =====================
if ($type == 'order_detail') {

    $invoice_no = $_GET['invoice_no'] ?? '';

    if (!$invoice_no) {
        errorResponse("Invoice number required");
    }

    $sql = "SELECT * FROM orders WHERE invoice_no = ?";
    $stmt = sqlsrv_query($conn, $sql, [$invoice_no]);

    if ($stmt === false) {
        errorResponse("Fetch failed", sqlsrv_errors());
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
    exit;
}

// ===================== PAYMENT =====================
if ($type == 'payment') {

    $invoice_no = $_POST['invoice_no'] ?? '';
    $amount = $_POST['amount'] ?? 0;

    if (!$invoice_no || !$amount) {
        errorResponse("Invalid payment data");
    }

    // GET CURRENT DATA
    $sql = "SELECT received_amount, total_amount FROM bill_details WHERE invoice_no = ?";
    $stmt = sqlsrv_query($conn, $sql, [$invoice_no]);

    if ($stmt === false) {
        errorResponse("Fetch failed", sqlsrv_errors());
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    $current_paid = $row['received_amount'] ?? 0;
    $total_amount = $row['total_amount'] ?? 0;

    $new_paid = $current_paid + $amount;

  if ($new_paid > $total_amount) {
    errorResponse("Payment exceeds total amount", [
        "total_amount" => $total_amount,
        "already_paid" => $current_paid,
        "remaining" => $total_amount - $current_paid
    ]);
}

    $balance = $total_amount - $new_paid;

    // UPDATE BILL
    $update = "
        UPDATE bill_details 
        SET received_amount = ?, balance_amount = ?
        WHERE invoice_no = ?
    ";

    $res = sqlsrv_query($conn, $update, [$new_paid, $balance, $invoice_no]);

    if ($res === false) {
        errorResponse("Update failed", sqlsrv_errors());
    }

    // INSERT PAYMENT HISTORY
    $insert = "
        INSERT INTO payment_history
        (bill_no, paid_amount, type, created_on)
        VALUES (?, ?, ?, GETDATE())
    ";

    $his = sqlsrv_query($conn, $insert, [
        $invoice_no,
        $amount,
        'Cash'
    ]);

    if ($his === false) {
        errorResponse("History insert failed", sqlsrv_errors());
    }

    echo json_encode([
        "status" => true,
        "message" => "Payment updated",
        "paid_total" => $new_paid,
        "balance" => $balance
    ]);
    exit;
}

// ===================== DEFAULT =====================
errorResponse("Invalid request type");
?>