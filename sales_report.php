<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");

require_once "db.php";

// ================= DB CHECK =================
if (!$conn) {
    echo json_encode([
        "status" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

// ================= COMMON QUERY =================
function runQuery($conn, $sql, $params = [], $errorMsg = "SQL Error")
{
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        return [
            "status" => false,
            "message" => $errorMsg,
            "error" => sqlsrv_errors()
        ];
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // format date if exists
        if (isset($row['created_on']) && $row['created_on'] instanceof DateTime) {
            $row['created_on'] = $row['created_on']->format('Y-m-d');
        }
        $data[] = $row;
    }

    return [
        "status" => true,
        "data" => $data
    ];
}

// ================= SALES PARTY =================
function get_sales_by_party($conn, $from, $to)
{
    $sql = "SELECT customer_name, SUM(quantity) AS total_qty
            FROM orders
            WHERE fulfilled = 1
            AND CONVERT(date, created_on) BETWEEN ? AND ?
            GROUP BY customer_name
            ORDER BY total_qty DESC";

    return runQuery($conn, $sql, [$from, $to], "Sales by party error");
}

// ================= SALES PRODUCT =================
function get_sales_by_product($conn, $from, $to)
{
    $sql = "SELECT 
                product_name, 
                SUM(quantity) AS total_qty,
                SUM(amount) AS total_amount
            FROM orders
            WHERE fulfilled = 1
            AND CONVERT(date, created_on) BETWEEN ? AND ?
            GROUP BY product_name
            ORDER BY total_qty DESC";

    return runQuery($conn, $sql, [$from, $to], "Sales by product error");
}

// ================= PRODUCT DETAILS =================
function get_product_details($conn, $product, $from, $to)
{
    $sql = "SELECT 
                customer_name,
                SUM(quantity) AS qty,
                SUM(amount) AS amount
            FROM orders
            WHERE product_name = ?
            AND fulfilled = 1
            AND CONVERT(date, created_on) BETWEEN ? AND ?
            GROUP BY customer_name
            ORDER BY qty DESC";

    return runQuery($conn, $sql, [$product, $from, $to], "Product details error");
}
// ================= DEFAULT DATE =================
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 month'));
$to   = $_GET['to'] ?? date('Y-m-d');

// ================= ROUTER =================
$type = $_GET['type'] ?? '';

switch ($type) {

    case "sales_party":
        $response = get_sales_by_party($conn, $from, $to);
        break;

    case "sales_product":
        $response = get_sales_by_product($conn, $from, $to);
        break;

    case "product_details":
        $product = $_GET['product'] ?? '';
        if (empty($product)) {
            echo json_encode([
                "status" => false,
                "message" => "Product required"
            ]);
            exit;
        }
        $response = get_product_details($conn, $product, $from, $to);
        break;

    default:
        echo json_encode([
            "status" => false,
            "message" => "Invalid API type"
        ]);
        exit;
}

// ================= OUTPUT =================
echo json_encode($response, JSON_PRETTY_PRINT);

sqlsrv_close($conn);
?>