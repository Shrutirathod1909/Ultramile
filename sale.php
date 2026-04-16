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

        // ✅ ORDER DATE FORMAT FIX
        if (isset($row['order_date']) && $row['order_date'] instanceof DateTime) {
            $row['order_date'] = $row['order_date']->format('d M Y');
        }

        // fallback (other APIs)
        if (isset($row['created_on']) && $row['created_on'] instanceof DateTime) {
            $row['created_on'] = $row['created_on']->format('d M Y');
        }

        $data[] = $row;
    }

    return [
        "status" => true,
        "data" => $data
    ];
}

// ================= PARTY DETAILS =================
function get_party_details($conn, $party, $from, $to)
{
    $sql = "SELECT 
                customer_name,
                quantity,
                created_on AS order_date,
                invoice_no AS order_no,
                address
            FROM orders
            WHERE LTRIM(RTRIM(customer_name)) LIKE '%' + LTRIM(RTRIM(?)) + '%'
            AND fulfilled = 1
            AND CONVERT(date, created_on) BETWEEN ? AND ?
            ORDER BY created_on DESC";

    return runQuery($conn, $sql, [$party, $from, $to]);
}

// ================= SALES PARTY =================
function get_sales_by_party($conn, $from, $to)
{
    $sql = "SELECT 
                customer_name, 
                SUM(quantity) AS total_qty,
                MAX(created_on) AS order_date
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
                SUM(quantity) AS total_qty
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
                quantity, 
                created_on AS order_date
            FROM orders
            WHERE product_name = ?
            AND fulfilled = 1
            AND CONVERT(date, created_on) BETWEEN ? AND ?
            ORDER BY created_on DESC";

    return runQuery($conn, $sql, [$product, $from, $to], "Product details error");
}

// ================= DEFAULT DATE =================
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-1 month'));
$to   = $_GET['to'] ?? date('Y-m-d');

// ================= ROUTER =================
$type = $_GET['type'] ?? '';

switch ($type) {

    case "party_details":
        $party = $_GET['party'] ?? '';
        echo json_encode(get_party_details($conn, $party, $from, $to));
        break;

    case "sales_party":
        $response = get_sales_by_party($conn, $from, $to);
        echo json_encode($response);
        break;

    case "sales_product":
        $response = get_sales_by_product($conn, $from, $to);
        echo json_encode($response);
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
        echo json_encode($response);
        break;

    default:
        echo json_encode([
            "status" => false,
            "message" => "Invalid API type"
        ]);
        break;
}

sqlsrv_close($conn);
?>