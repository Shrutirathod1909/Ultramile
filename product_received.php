<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
require_once "db.php";

date_default_timezone_set("Asia/Kolkata");

// ================= RESPONSE =================
function response($status, $message, $data = []) {
    echo json_encode([
        "status" => $status,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}

// ================= BARCODE =================
function generateBarcode($conn) {
    do {
        $barcode = "UM" . uniqid() . rand(100, 999);

        $sql = "SELECT COUNT(*) AS cnt FROM inventory WHERE barcode = ?";
        $stmt = sqlsrv_query($conn, $sql, [$barcode]);

        if ($stmt === false) {
            response(false, "Barcode check failed", sqlsrv_errors());
        }

        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $exists = $row['cnt'] ?? 0;

    } while ($exists > 0);

    return $barcode;
}

// ================= INSERT =================
function insertInventory($conn, $data) {

    $sql = "INSERT INTO inventory (
        product_name,
        sku_code,
        category,
        purchase_price,
        barcode,
        status,
        active,
        readable,
        invoice_no,
        invoice_date,
        container_no,
        bl_no,
        received_date,
        created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = sqlsrv_query($conn, $sql, $data);

    if ($stmt === false) {
        response(false, "Insert failed", sqlsrv_errors());
    }
}

// ================= ROUTER =================
$type = $_GET['type'] ?? "";

/*
=================================================
1️⃣ INSERT API
=================================================
*/
if ($type == "insert" && isset($_POST['save'])) {

    $name   = trim($_POST['product_name'] ?? '');
    $sku    = trim($_POST['sku_code'] ?? '');
    $price  = (float)($_POST['price'] ?? 0);
    $qty    = (int)($_POST['qty'] ?? 0);

    $invoice_no    = $_POST['invoice_no'] ?? '';
    $invoice_date  = $_POST['invoice_date'] ?? date('Y-m-d');
    $container_no  = $_POST['container_no'] ?? '';
    $bl_no         = $_POST['bl_no'] ?? '';
    $received_date = date('Y-m-d');

    $created_by = (int)($_POST['created_by'] ?? 0);

    if ($name == '' || $qty <= 0) {
        response(false, "Invalid input");
    }

    // ================= CATEGORY FETCH =================
    $catSql = "SELECT TOP 1 category 
               FROM dbo.product_detail_description 
               WHERE product_name = ?";

    $catStmt = sqlsrv_query($conn, $catSql, [$name]);

    if ($catStmt === false) {
        response(false, "Category fetch failed", sqlsrv_errors());
    }

    $catRow = sqlsrv_fetch_array($catStmt, SQLSRV_FETCH_ASSOC);
    $category = $catRow['category'] ?? '';

    // ================= INVOICE CHECK =================
    if ($invoice_no != '') {

        $checkSql = "SELECT COUNT(*) as cnt FROM inventory WHERE invoice_no = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$invoice_no]);

        if ($checkStmt === false) {
            response(false, "Invoice check failed", sqlsrv_errors());
        }

        $checkRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);

        if (($checkRow['cnt'] ?? 0) > 0) {
            response(false, "Invoice number already exists");
        }
    }

    // ================= TRANSACTION =================
    sqlsrv_begin_transaction($conn);

    try {

        for ($i = 0; $i < $qty; $i++) {

            $barcode = generateBarcode($conn);

            insertInventory($conn, [
                $name,
                $sku,
                $category,
                $price,
                $barcode,
                'received',
                1,
                1,
                $invoice_no,
                $invoice_date,
                $container_no,
                $bl_no,
                $received_date,
                $created_by
            ]);
        }

        sqlsrv_commit($conn);

        response(true, "Product inserted successfully");

    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        response(false, "Insert failed", ["error" => $e->getMessage()]);
    }
}

/*
=================================================
2️⃣ LIST API
=================================================
*/
if ($type == "list") {

    $sql = "SELECT 
                inventory_id,
                product_name,
                sku_code,
                category,
                purchase_price,
                barcode,
                created_by,
                invoice_no,
                invoice_date,
                container_no,
                bl_no,
                received_date
            FROM inventory
            ORDER BY inventory_id DESC";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        response(false, "Fetch failed", sqlsrv_errors());
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    response(true, "Data fetched successfully", $data);
}

/*
=================================================
3️⃣ PRODUCT LIST
=================================================
*/
if ($type == "product_list") {

    $sql = "SELECT DISTINCT product_name 
            FROM dbo.product_detail_description 
            WHERE product_name IS NOT NULL 
            AND product_name != ''
            ORDER BY product_name ASC";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        response(false, "Product fetch failed", sqlsrv_errors());
    }

    $products = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $products[] = $row['product_name'];
    }

    response(true, "Product list fetched", $products);
}

// ================= DEFAULT =================
response(false, "Invalid API type");
?>