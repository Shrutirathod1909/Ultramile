<?php
header("Content-Type: application/json");
error_reporting(E_ALL);
ini_set('display_errors', 1);

include "db.php";

// ================= RESPONSE =================
function response($status, $message, $data = null) {
    echo json_encode([
        "status" => $status,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}

// ================= JSON SUPPORT =================
$input = json_decode(file_get_contents("php://input"), true);

$type = $_GET['type'] ?? '';

try {

// =====================================================
// CREATE ORDER
// =====================================================
if ($type == 'create_order') {

    // ✅ SAFE INVOICE GENERATION
    $invoiceQuery = sqlsrv_query($conn, "
        SELECT ISNULL(MAX(TRY_CAST(invoice_no AS BIGINT)),0) + 1 AS new_invoice
        FROM orders
    ");

    if ($invoiceQuery === false) {
        response(false, "Invoice generation failed", sqlsrv_errors());
    }

    $row = sqlsrv_fetch_array($invoiceQuery, SQLSRV_FETCH_ASSOC);
    $invoice_no = (string)$row['new_invoice'];

    // ================= INPUT =================
    $customer_name = trim($input['customer_name'] ?? $_POST['customer_name'] ?? '');
    $address = $input['address'] ?? $_POST['address'] ?? '';
    $contact_no = $input['contact_no'] ?? $_POST['contact_no'] ?? '';
    $gst_no = $input['gst_no'] ?? $_POST['gst_no'] ?? '';
    $order_date = $input['order_date'] ?? $_POST['order_date'] ?? '';
    
    // ✅ DEFAULT MUMBAI
    $to_branch = $input['to_branch'] ?? $_POST['to_branch'] ?? 'Mumbai';
    $created_by = intval($input['created_by'] ?? $_POST['created_by'] ?? 0);

    if ($customer_name == '') {
        response(false, "Customer name required");
    }

    // ================= CUSTOMER =================
    $cust = sqlsrv_query($conn,
        "SELECT TOP 1 customer_id 
         FROM customers 
         WHERE LOWER(LTRIM(RTRIM(customer_name))) 
         LIKE LOWER('%' + ? + '%')",
        [$customer_name]
    );

    if ($cust === false) {
        response(false, "Customer query failed", sqlsrv_errors());
    }

    $customer = sqlsrv_fetch_array($cust, SQLSRV_FETCH_ASSOC);

    if (!$customer) {
        response(false, "Customer not found", ["name" => $customer_name]);
    }

    $customer_id = $customer['customer_id'];

    // ================= PRODUCTS =================
    $product_name = $input['product_name'] ?? $_POST['product_name'] ?? [];
    $product_code = $input['product_code'] ?? $_POST['product_code'] ?? [];
    $qty = $input['quantity'] ?? $_POST['quantity'] ?? [];
    $price = $input['price'] ?? $_POST['price'] ?? [];
    $gst = $input['gst_percent'] ?? $_POST['gst_percent'] ?? [];
    $gst_type = $input['gst_type'] ?? $_POST['gst_type'] ?? [];

    if (!is_array($product_name) || count($product_name) == 0) {
        response(false, "Invalid product format");
    }

    $total_amount = 0;

    for ($i = 0; $i < count($product_name); $i++) {

        $pname = trim($product_name[$i] ?? '');
        $pcode = trim($product_code[$i] ?? '');

        if ($pname == '' || $pcode == '') continue;

        // ================= PRODUCT CHECK =================
        $prod = sqlsrv_query($conn,
            "SELECT TOP 1 product_name 
             FROM product_detail_description
             WHERE LOWER(LTRIM(RTRIM(product_name))) 
             LIKE LOWER('%' + ? + '%')",
            [$pname]
        );

        if ($prod === false) {
            response(false, "Product query failed", sqlsrv_errors());
        }

        $product = sqlsrv_fetch_array($prod, SQLSRV_FETCH_ASSOC);

        if (!$product) {
            response(false, "Product not found", ["product" => $pname]);
        }

        // ================= CALC =================
        $q = floatval($qty[$i] ?? 0);
        $p = floatval($price[$i] ?? 0);
        $g = floatval($gst[$i] ?? 0);
        $type_gst = $gst_type[$i] ?? 'Exclusive';

        if ($q <= 0 || $p <= 0) continue;

        $base_total = $q * $p;

        // ✅ GST SPLIT
        $cgst = $g / 2;
        $sgst = $g / 2;

        if ($type_gst == 'Exclusive') {
            $gst_amount = ($base_total * $g) / 100;
            $final_amount = $base_total + $gst_amount;
        } else {
            $gst_amount = ($base_total * $g) / (100 + $g);
            $final_amount = $base_total;
        }

        $total_amount += $final_amount;

        // ================= INSERT =================
        $sql = "INSERT INTO orders
        (invoice_no, customer_id, customer_name,
         address, contact_no, gst_no,
         product_name, product_code, quantity, sale_price,
         total_price, final_amount,
         gst_perc, cgst, sgst, gst_type,
         order_date, fulfilled, to_branch, created_by, created_on)
        VALUES (?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, GETDATE())";

        $params = [
            $invoice_no,
            $customer_id,
            $customer_name,
            $address,
            $contact_no,
            $gst_no,

            $pname,
            $pcode,
            $q,
            $p,

            $base_total,
            $final_amount,

            $g,
            $cgst,
            $sgst,
            $type_gst,

            $order_date,
            0, // fulfilled
            $to_branch,
            $created_by
        ];

        $res = sqlsrv_query($conn, $sql, $params);

        if ($res === false) {
            response(false, "Insert failed", sqlsrv_errors());
        }
    }

    // ================= BILL =================
    $bill = sqlsrv_query($conn,
        "INSERT INTO bill_details 
        (invoice_no, customer_name, total_amount, created_on)
        VALUES (?, ?, ?, GETDATE())",
        [$invoice_no, $customer_name, $total_amount]
    );

    if ($bill === false) {
        response(false, "Bill insert failed", sqlsrv_errors());
    }

    response(true, "Order created successfully", [
        "invoice_no" => $invoice_no,
        "total_amount" => round($total_amount, 2)
    ]);
}

elseif ($type == "search_product") {

    $term = $_GET['term'] ?? '';

    $sql = "SELECT TOP 20 product_name, product_code
            FROM product_detail_description
            WHERE product_name LIKE '%' + ? + '%'";

    $stmt = sqlsrv_query($conn, $sql, [$term]);

    if ($stmt === false) {
        response(false, "Search failed", sqlsrv_errors());
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    response(true, "Success", $data);
}

// =====================================================
else {
    response(false, "Invalid request type");
}

} catch (Exception $e) {
    response(false, "Server Error", $e->getMessage());
}
?>