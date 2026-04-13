<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "db.php";

header("Content-Type: application/json");

if (isset($_GET['action']) && $_GET['action'] == "bulkInsert") {

    $input = json_decode(file_get_contents("php://input"), true);
    $data = $input['data'] ?? [];

    $inserted = 0;
    $skipped = 0;

    foreach ($data as $row) {

        $product_name = trim($row['product'] ?? '');
        $sku_code     = trim($row['sku'] ?? '');

        if ($product_name == '') continue;

        // ✅ DUPLICATE CHECK
        $checkSql = "SELECT id FROM product_detail_description WHERE product_code = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$sku_code]);

        if ($checkStmt && sqlsrv_has_rows($checkStmt)) {
            $skipped++;
            continue;
        }

        // ✅ GST FIX (handles both key types)
        $gstType = $row['gst'] ?? $row['gst_type'] ?? 'Exclusive';
        $gstPerc = $row['gstPerc'] ?? $row['gst_perc'] ?? 0;

        $price   = floatval($row['price'] ?? 0);
        $gstPerc = floatval($gstPerc);
        $gstType = strtolower(trim($gstType));

        // ✅ GST CALCULATION
        if ($gstType == "inclusive") {
            $salePrice = $price / (1 + ($gstPerc / 100)); // without GST
            $mrpPrice  = $price; // with GST
        } else {
            $salePrice = $price;
            $mrpPrice  = $price + ($price * $gstPerc / 100);
        }

        // ✅ INSERT
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

        sqlsrv_query($conn, $sql, $params);

        $inserted++;
    }

    echo json_encode([
        "status" => true,
        "inserted" => $inserted,
        "skipped" => $skipped
    ]);
}
?>