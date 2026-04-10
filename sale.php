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
        $data[] = $row;
    }

    return [
        "status" => true,
        "data" => $data
    ];
}

# ================= SALES PARTY =================
function get_sales_by_party($conn, $from = null, $to = null)
{
    $sql = "SELECT customer_name,
                   SUM(quantity) AS total_qty
            FROM orders
            WHERE fulfilled = 1";

    $params = [];

    if (!empty($from) && !empty($to)) {
        $sql .= " AND CONVERT(date, created_on) BETWEEN ? AND ?";
        $params[] = $from;
        $params[] = $to;
    }

    $sql .= " GROUP BY customer_name
              ORDER BY total_qty DESC";

    return runQuery($conn, $sql, $params, "Sales by party error");
}

# ================= SALES PRODUCT =================
function get_sales_by_product($conn, $from = null, $to = null)
{
    $sql = "SELECT product_name,
                   SUM(quantity) AS total_qty
            FROM orders
            WHERE fulfilled = 1";

    $params = [];

    if (!empty($from) && !empty($to)) {
        $sql .= " AND CONVERT(date, created_on) BETWEEN ? AND ?";
        $params[] = $from;
        $params[] = $to;
    }

    $sql .= " GROUP BY product_name
              ORDER BY total_qty DESC";

    return runQuery($conn, $sql, $params, "Sales by product error");
}

# ================= ROUTER =================
$type = $_GET['type'] ?? '';
$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;

switch ($type) {

    case "sales_party":
        $response = get_sales_by_party($conn, $from, $to);
        break;

    case "sales_product":
        $response = get_sales_by_product($conn, $from, $to);
        break;

    default:
        echo json_encode([
            "status" => false,
            "message" => "Invalid API type"
        ]);
        exit;
}

echo json_encode($response);
sqlsrv_close($conn);
?>