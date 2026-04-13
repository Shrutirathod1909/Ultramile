<?php
header("Content-Type: application/json; charset=UTF-8");
ob_start();

error_reporting(0);
ini_set('display_errors', 0);

include "db.php";

/* ================= RESPONSE ================= */
function sendResponse($status, $data = [], $message = "")
{
    ob_clean();
    echo json_encode([
        "status" => $status,
        "data" => $data,
        "message" => $message
    ]);
    exit;
}

/* ================= GST ================= */
function calculateGST($amount, $rate = 18)
{
    $amount = floatval($amount ?? 0);
    $gst = ($amount * $rate) / 100;

    return [
        "gst" => round($gst, 2),
        "final_price" => round($amount + $gst, 2)
    ];
}

/* ================= DATE ================= */
function formatDate($date)
{
    if ($date instanceof DateTime) {
        return $date->format('Y-m-d H:i:s');
    }
    return $date ?? "-";
}

$type = $_GET['type'] ?? "";

/* ================= INPROGRESS ================= */
if ($type == "inprogress") {

    $sql = "SELECT 
                invoice_no,
                customer_name,
                to_branch,
                MAX(created_on) as created_on,
                SUM(quantity) as quantity,
                SUM(total_price) as total_price,
                MAX(status) as status
            FROM orders
            WHERE fulfilled='0'
            GROUP BY invoice_no, customer_name, to_branch
            ORDER BY invoice_no DESC";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        sendResponse(false, [], "SQL Error");
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

        $row["created_on"] = formatDate($row["created_on"]);

        $gst = calculateGST($row["total_price"] ?? 0);
        $row["gst"] = $gst["gst"];
        $row["final_amount"] = $gst["final_price"];

        $data[] = $row;
    }

    sendResponse(true, $data);
}

/* ================= INVOICE VIEW ================= */
elseif ($type == "invoice_view") {

    $invoice_no = $_GET['invoice_no'] ?? "";

    if ($invoice_no == "") {
        sendResponse(false, [], "invoice_no required");
    }

    $sql = "SELECT * FROM orders WHERE invoice_no = ?";
    $stmt = sqlsrv_query($conn, $sql, [$invoice_no]);

    if ($stmt === false) {
        sendResponse(false, [], "SQL Error");
    }

    $items = [];
    $first = null;

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $row["created_on"] = formatDate($row["created_on"]);
        if (!$first) $first = $row;
        $items[] = $row;
    }

    if (!$first) {
        sendResponse(false, [], "Invoice not found");
    }

    $amount = floatval($first["total_price"] ?? 0);
    $gstData = calculateGST($amount);

    sendResponse(true, [
        "invoice_no" => $invoice_no,
        "customer_name" => $first["customer_name"] ?? "-",
        "address" => $first["address"] ?? "-",
        "mobile" => $first["mobile"] ?? "-",
        "gst_no" => $first["gst_no"] ?? "-",
        "created_on" => $first["created_on"],
        "subtotal" => $amount,
        "gst" => $gstData["gst"],
        "final_amount" => $gstData["final_price"],
        "items" => $items
    ]);
}

/* ================= APPROVE + MOVE ================= */
elseif ($type == "approve") {

    $invoice_no = $_POST['invoice_no'] ?? "";

    if ($invoice_no == "") {
        sendResponse(false, [], "invoice_no required");
    }

    /* 1. UPDATE */
    $sql1 = "UPDATE orders SET status='Approved', fulfilled='1' WHERE invoice_no=?";
    $stmt1 = sqlsrv_query($conn, $sql1, [$invoice_no]);

    if ($stmt1 === false) {
        sendResponse(false, [], "Update failed");
    }

    /* 2. FETCH */
    $sql2 = "SELECT * FROM orders WHERE invoice_no=?";
    $stmt2 = sqlsrv_query($conn, $sql2, [$invoice_no]);

    $items = [];

    while ($row = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
        $items[] = $row;
    }

    if (count($items) == 0) {
        sendResponse(false, [], "No data found");
    }

    /* 3. INSERT */
    foreach ($items as $item) {

        $sql3 = "INSERT INTO fulfilled_orders 
                (invoice_no, customer_name, product_name, quantity, total_price, created_on)
                VALUES (?, ?, ?, ?, ?, ?)";

        $params = [
            $item['invoice_no'],
            $item['customer_name'],
            $item['product_name'] ?? '',
            $item['quantity'],
            $item['total_price'],
            $item['created_on']
        ];

        sqlsrv_query($conn, $sql3, $params);
    }

    sendResponse(true, [], "Approved & moved to fulfilled_orders");
}

/* ================= DOWNLOAD ================= */
elseif ($type == "download_invoice") {

    $invoice_no = $_GET['invoice_no'] ?? "";

    if ($invoice_no == "") {
        sendResponse(false, [], "invoice_no required");
    }

    $sql = "SELECT * FROM orders WHERE invoice_no = ?";
    $stmt = sqlsrv_query($conn, $sql, [$invoice_no]);

    if ($stmt === false) {
        sendResponse(false, [], "SQL Error");
    }

    $items = [];
    $first = null;

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $row["created_on"] = formatDate($row["created_on"]);
        if (!$first) $first = $row;
        $items[] = $row;
    }

    if (!$first) {
        sendResponse(false, [], "Invoice not found");
    }

    $amount = floatval($first["total_price"] ?? 0);
    $gstData = calculateGST($amount);

    sendResponse(true, [
        "invoice_no" => $invoice_no,
        "customer_name" => $first["customer_name"] ?? "-",
        "address" => $first["address"] ?? "-",
        "mobile" => $first["mobile"] ?? "-",
        "gst_no" => $first["gst_no"] ?? "-",
        "created_on" => $first["created_on"],
        "subtotal" => $amount,
        "cgst" => $gstData["gst"] / 2,
        "sgst" => $gstData["gst"] / 2,
        "final_amount" => $gstData["final_price"],
        "items" => $items
    ], "Invoice ready");
}

/* ================= SEND EMAIL ================= */
elseif ($type == "send_email") {

    try {

        require 'vendor/autoload.php';

        $invoice_no = $_POST['invoice_no'] ?? "";
        $email = $_POST['email'] ?? "";

        if ($invoice_no == "" || $email == "") {
            sendResponse(false, [], "required fields missing");
        }

        $sql = "SELECT * FROM orders WHERE invoice_no = ?";
        $stmt = sqlsrv_query($conn, $sql, [$invoice_no]);

        $first = null;

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (!$first) $first = $row;
        }

        if (!$first) {
            sendResponse(false, [], "Invoice not found");
        }

        $amount = floatval($first["total_price"] ?? 0);

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;

        $mail->Username = 'yourgmail@gmail.com';
        $mail->Password = 'your_app_password';

        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('yourgmail@gmail.com', 'Invoice System');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = "Invoice #$invoice_no";

        $mail->Body = "
            <h2>Invoice Details</h2>
            <p><b>Invoice:</b> $invoice_no</p>
            <p><b>Customer:</b> {$first['customer_name']}</p>
            <p><b>Total:</b> ₹$amount</p>
        ";

        $mail->send();

        sendResponse(true, [], "Email sent successfully");

    } catch (\Throwable $e) {
        sendResponse(false, [], $e->getMessage());
    }
}

/* ================= DEFAULT ================= */
else {
    sendResponse(false, [], "Invalid type");
}
?>