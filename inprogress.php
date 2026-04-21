<?php
header("Content-Type: application/json; charset=UTF-8");
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'vendor/autoload.php'; // ✅ VERY IMPORTANT
include "db.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dompdf\Dompdf;

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

    sqlsrv_begin_transaction($conn);

    try {

        /* 1. UPDATE ORDER */
        $sql1 = "UPDATE orders 
                 SET status='Approved', fulfilled=1 
                 WHERE invoice_no=? AND status!='Approved'";
        $stmt1 = sqlsrv_query($conn, $sql1, [$invoice_no]);

        if (!$stmt1) throw new Exception(print_r(sqlsrv_errors(), true));

        /* 2. GET ORDER ITEMS */
        $sql2 = "SELECT * FROM orders WHERE invoice_no=?";
        $stmt2 = sqlsrv_query($conn, $sql2, [$invoice_no]);

        if (!$stmt2) throw new Exception(print_r(sqlsrv_errors(), true));

        while ($item = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {

            $product_name = trim($item['product_name']);
            $sold_qty     = (int)$item['quantity'];
            $sale_price   = (float)($item['sale_price'] ?? 0);
            $created_by   = (int)($item['created_by'] ?? 0);
            $order_date   = $item['order_date'] ?? null;

            if (!$product_name) {
                throw new Exception("Product name missing in order");
            }

            /* 3. GET PRODUCT DETAILS */
            $sqlProd = "SELECT TOP 1 id, category
                        FROM product_detail_description
                        WHERE LOWER(LTRIM(RTRIM(product_name))) = LOWER(LTRIM(RTRIM(?)))";

            $stmtProd = sqlsrv_query($conn, $sqlProd, [$product_name]);

            if (!$stmtProd) throw new Exception(print_r(sqlsrv_errors(), true));

            $prodRow = sqlsrv_fetch_array($stmtProd, SQLSRV_FETCH_ASSOC);

            if (!$prodRow) {
                throw new Exception("Product not found: " . $product_name);
            }

            $product_id = (string)$prodRow['id'];
            $category   = $prodRow['category'] ?? '';

            /* 4. GET STOCK (IMPORTANT FIX) */
            $sqlStock = "SELECT TOP 1 inventory_id, product_qty
             FROM inventory
             WHERE LOWER(LTRIM(RTRIM(product_name))) = LOWER(LTRIM(RTRIM(?)))
             ORDER BY product_qty DESC";
            $stmtStock = sqlsrv_query($conn, $sqlStock, [$product_name]);

            if (!$stmtStock) throw new Exception(print_r(sqlsrv_errors(), true));

            $stockRow = sqlsrv_fetch_array($stmtStock, SQLSRV_FETCH_ASSOC);

            if (!$stockRow) {
                throw new Exception("Stock not found for product: " . $product_name);
            }

            $inventory_id   = (int)$stockRow['inventory_id'];
         $previous_stock = (int)$stockRow['product_qty']; // ✅ FIX

            /* Prevent negative stock */
            if ($previous_stock < $sold_qty) {
                throw new Exception("Not enough stock for " . $product_name);
            }

            /* 5. CALCULATE STOCK */
            $new_stock = $previous_stock - $sold_qty;

            /* 6. INSERT INTO inventory_log */
            $sql3 = "INSERT INTO inventory_log
            (product_id, product_name, category, barcode,
             sale_price, qty, totalproduct_qty,
             invoice_no, invoice_date, sales_inv_no,
             order_no, order_date,
             status, created_on, created_by, active, inventory_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, ?, ?)";

            $params3 = [
                $product_id,
                $product_name,
                $category,
                $barcode,
                $sale_price,
                $sold_qty,
                $new_stock,               // ✅ correct stock
                $invoice_no,
                date('Y-m-d H:i:s'),
                $invoice_no,
                $invoice_no,
                $order_date,
                'sales_inventory',
                $created_by,
                1,
                $inventory_id
            ];

            $stmt3 = sqlsrv_query($conn, $sql3, $params3);

            if (!$stmt3) throw new Exception(print_r(sqlsrv_errors(), true));

            /* 7. UPDATE INVENTORY (IMPORTANT FIX) */
            $sql4 = "UPDATE inventory 
                     SET product_qty=? 
                     WHERE inventory_id=?";

            $stmt4 = sqlsrv_query($conn, $sql4, [$new_stock, $inventory_id]);

            if (!$stmt4) throw new Exception(print_r(sqlsrv_errors(), true));
        }

        /* 8. COMMIT */
        sqlsrv_commit($conn);

        sendResponse(true, [], "Approved & inventory updated successfully");

    } catch (Exception $e) {

        /* 9. ROLLBACK */
        sqlsrv_rollback($conn);

        sendResponse(false, [], $e->getMessage());
    }
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
function generateInvoicePDF($invoice_no, $items)
{
    $first = $items[0];

    $rows = "";
    foreach ($items as $item) {
        $rows .= "
        <tr>
            <td>{$item['product_name']}</td>
            <td align='center'>{$item['quantity']}</td>
            <td align='right'>&#8377; {$item['sale_price']}</td>
            <td align='right'>&#8377; {$item['total_price']}</td>
        </tr>";
    }

    // GST Calculation
    $subtotal = floatval($first['total_price']);
    $gst = ($subtotal * 18) / 100;
    $cgst = $gst / 2;
    $sgst = $gst / 2;
    $final = $subtotal + $gst;

    $html = "
    <style>
        body { font-family: DejaVu Sans, sans-serif; }

        .header {
            background:#0f172a;
            color:#fff;
            padding:15px;
            font-size:20px;
            font-weight:bold;
        }

        .header span {
            float:right;
            font-size:14px;
            font-weight:normal;
        }

        .container {
            padding:20px;
        }

        .box {
            border:1px solid #ccc;
            width:300px;
            padding:10px;
            margin-top:15px;
        }

        .table {
            width:100%;
            border-collapse: collapse;
            margin-top:20px;
        }

        .table th {
            background:#cbd5e1;
            padding:10px;
            text-align:left;
        }

        .table td {
            padding:10px;
        }

        .summary {
            background:#c8e6c9;
            margin-top:20px;
            padding:15px;
        }

        .summary hr {
            border:1px solid #333;
        }

        .total {
            font-size:18px;
            font-weight:bold;
            margin-top:10px;
        }

        .sign {
            margin-top:40px;
            text-align:right;
        }
    </style>

    <div class='header'>
        INVOICE
        <span>#{$invoice_no}</span>
    </div>

    <div class='container'>

        <div class='box'>
            <b>Customer Details</b><br><br>
            Name: {$first['customer_name']}<br>
            Mobile: {$first['mobile']}<br>
            Address: {$first['address']}<br>
            Date: {$first['created_on']}
        </div>

        <table class='table'>
            <tr>
                <th>Item</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Total</th>
            </tr>
            $rows
        </table>

        <div class='summary'>
            Subtotal: &#8377; {$subtotal}<br>
            CGST: &#8377; {$cgst}<br>
            SGST: &#8377; {$sgst}
            <hr>
            <div class='total'>
                TOTAL: &#8377; {$final}
            </div>
        </div>

        <div class='sign'>
            Authorized Signatory
        </div>

    </div>
    ";

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->set_option('isHtml5ParserEnabled', true);
    $dompdf->setPaper('A4');
    $dompdf->render();

    $folder = __DIR__ . "/invoices";
    if (!file_exists($folder)) mkdir($folder, 0777, true);

    $filePath = "$folder/$invoice_no.pdf";
    file_put_contents($filePath, $dompdf->output());

    return $filePath;
}

/* ================= MAIN API ================= */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($type == "inprogress") {

        // existing inprogress code...

    } elseif ($type == "invoice_view") {

        // existing invoice_view code...

    } elseif ($type == "download_invoice") {

        // existing download code...

    } else {
        sendResponse(false, [], "Invalid GET type");
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $invoice_no = $_POST['invoice_no'] ?? "";
        $email = $_POST['email'] ?? "";

        if ($invoice_no == "" || $email == "") {
            sendResponse(false, [], "Missing fields");
        }

        // FETCH DATA
        $sql = "SELECT * FROM orders WHERE invoice_no = ?";
        $stmt = sqlsrv_query($conn, $sql, [$invoice_no]);

        if (!$stmt) {
            sendResponse(false, sqlsrv_errors(), "SQL Error");
        }

        $items = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row["created_on"] = formatDate($row["created_on"]);
            $items[] = $row;
        }

        if (count($items) == 0) {
            sendResponse(false, [], "Invoice not found");
        }

        // GST
        $amount = floatval($items[0]["total_price"] ?? 0);
        $gstData = calculateGST($amount);
        $items[0]["final_amount"] = $gstData["final_price"];

        // PDF
        $filePath = generateInvoicePDF($invoice_no, $items);

        // EMAIL
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
   $mail->Username = 'shrutirathod1909@gmail.com';
        $mail->Password = 'xzggtfqofcasfjlc';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('shrutirathod1909@gmail.com', 'Invoice System');
        $mail->addAddress($email);

        $mail->addAttachment($filePath);

        $mail->isHTML(true);
        $mail->Subject = "Invoice #$invoice_no";
        $mail->Body = "Your invoice is attached.";

        $mail->send();

        sendResponse(true, [], "Email sent successfully");

    } catch (Exception $e) {
        sendResponse(false, [], $e->getMessage());
    }

} else {
    sendResponse(false, [], "Invalid request method");
}
