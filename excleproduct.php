<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "db.php";

/* ================= RESPONSE HEADER FOR API ================= */
if (isset($_GET['action'])) {
    header("Content-Type: application/json");
}

/* ================= DOWNLOAD CSV ================= */
if (isset($_GET['download'])) {

    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=ProductTemplate.csv");
    echo "\xEF\xBB\xBF";

    $output = fopen("php://output", "w");

    fputcsv($output, [
        "PRODUCT NAME",
        "SKU CODE",
        "CATEGORY",
        "SIZE",
        "COST PRICE",
        "GST TYPE",
        "GST PERC"
    ]);

    fclose($output);
    exit;
}

/* ================= FLUTTER BULK INSERT API ================= */
if (isset($_GET['action']) && $_GET['action'] == "bulkInsert") {

    $input = json_decode(file_get_contents("php://input"), true);
    $data = $input['data'] ?? [];

    $inserted = 0;
    $skipped = 0;

    foreach ($data as $row) {

        $product_name = trim($row['product'] ?? '');
        $sku_code     = trim($row['sku'] ?? '');

        if ($product_name == '') continue;

        // duplicate check
        $checkSql = "SELECT id FROM product_detail_description WHERE product_code = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$sku_code]);

        if ($checkStmt && sqlsrv_has_rows($checkStmt)) {
            $skipped++;
            continue;
        }

        $sql = "INSERT INTO product_detail_description
        (product_name, product_code, category, type, mrp_price, sale_price, gst_type, gst_perc, created_on)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";

        $params = [
            $product_name,
            $sku_code,
            $row['category'] ?? '',
            $row['size'] ?? '',
            $row['price'] ?? 0,
            $row['price'] ?? 0,
            $row['gst'] ?? 'NA',
            $row['gstPerc'] ?? 0
        ];

        sqlsrv_query($conn, $sql, $params);

        $inserted++;
    }

    echo json_encode([
        "status" => true,
        "inserted" => $inserted,
        "skipped" => $skipped
    ]);
    exit;
}

/* ================= CSV UPLOAD (OLD SYSTEM) ================= */
$message = "";

if (isset($_POST['upload'])) {

    $file = $_FILES['file1']['tmp_name'];

    if (($handle = fopen($file, "r")) !== FALSE) {

        $inserted = 0;
        $skipped = 0;
        $row = 0;

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {

            $row++;

            if ($row == 1) continue;

            $product_name = trim($data[0]);
            $sku_code     = trim($data[1]);

            if ($product_name == "") continue;

            $checkSql = "SELECT id FROM product_detail_description WHERE product_code = ?";
            $checkStmt = sqlsrv_query($conn, $checkSql, [$sku_code]);

            if ($checkStmt && sqlsrv_has_rows($checkStmt)) {
                $skipped++;
                continue;
            }

            $sql = "INSERT INTO product_detail_description
            (product_name, product_code, category, type, mrp_price, gst_type, gst_perc, created_on)
            VALUES (?, ?, ?, ?, ?, ?, ?, GETDATE())";

            $params = [
                $product_name,
                $sku_code,
                $data[2],
                $data[3],
                $data[4],
                $data[5],
                $data[6]
            ];

            sqlsrv_query($conn, $sql, $params);

            $inserted++;
        }

        fclose($handle);

        $message = "Inserted: $inserted | Skipped: $skipped";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>MSSQL Product Import</title>
</head>
<body>

<h2>Product Import System (MSSQL)</h2>

<?php if($message != "") { ?>
    <h3 style="color:green;"><?php echo $message; ?></h3>
<?php } ?>

<!-- DOWNLOAD -->
<a href="?download=1">
    <button>Download CSV Template</button>
</a>

<br><br>

<!-- CSV UPLOAD (OLD) -->
<form method="post" enctype="multipart/form-data">

    <input type="file" name="file1" accept=".csv" required>

    <br><br>

    <button type="submit" name="upload">Upload CSV</button>

</form>

</body>
</html>