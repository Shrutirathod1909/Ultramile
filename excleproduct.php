<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "db.php";

/* =========================
   ACTION CHECK
========================= */
$action = $_GET['action'] ?? '';

/* =========================
   1. BULK INSERT API
========================= */
if ($action == "bulkInsert") {

    header("Content-Type: application/json");

    $input = json_decode(file_get_contents("php://input"), true);
    $data = $input['data'] ?? [];

    $inserted = 0;
    $skipped = 0;

    foreach ($data as $row) {

        $product_name = trim($row['product'] ?? '');
        $sku_code     = trim($row['sku'] ?? '');

        if ($product_name == '') continue;

        // CHECK DUPLICATE
        $checkSql = "SELECT id FROM product_detail_description WHERE product_code = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$sku_code]);

        if ($checkStmt && sqlsrv_has_rows($checkStmt)) {
            $skipped++;
            continue;
        }

        // GST
        $gstType = $row['gst'] ?? $row['gst_type'] ?? 'Exclusive';
        $gstPerc = $row['gstPerc'] ?? $row['gst_perc'] ?? 0;

        $price   = floatval($row['price'] ?? 0);
        $gstPerc = floatval($gstPerc);
        $gstType = strtolower(trim($gstType));

        // GST CALC
        if ($gstType == "inclusive") {
            $salePrice = $price / (1 + ($gstPerc / 100));
            $mrpPrice  = $price;
        } else {
            $salePrice = $price;
            $mrpPrice  = $price + ($price * $gstPerc / 100);
        }

        // INSERT
        $sql = "INSERT INTO product_detail_description
        (product_name, product_code, category, type, mrp_price, sale_price, gst_type, gst_perc, created_on)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";

        $params = [
            $product_name,
            $sku_code,
            $row['category'] ?? '',
            $row['size'] ?? '',
            round($mrpPrice, 2),
            round($salePrice, 2),
            ucfirst($gstType),
            $gstPerc
        ];

        $result = sqlsrv_query($conn, $sql, $params);

        if ($result === false) {
            die(json_encode([
                "status" => false,
                "error" => sqlsrv_errors()
            ]));
        }

        $inserted++;
    }

    echo json_encode([
        "status" => true,
        "inserted" => $inserted,
        "skipped" => $skipped
    ]);

    exit;
}


/* =========================
   2. EXCEL DOWNLOAD API
========================= */
if ($action == "downloadTemplate") {

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=product_template.xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    // ✅ PROPER COLUMN NAMES
    echo "Product Name\tSKU Code\tCategory\tSize\tPrice\tGST Type\tGST Percentage\n";

    exit;
}


/* =========================
   DEFAULT RESPONSE
========================= */
echo json_encode([
    "status" => false,
    "message" => "Invalid action"
]);
?>