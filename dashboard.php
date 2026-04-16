<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
require_once "db.php";

$action = $_GET['action'] ?? '';

switch ($action) {

    case "getCategoryChart":
        getCategoryChart($conn);
        break;

    case "getOrderCustomers":
        getOrderCustomers($conn);
        break;

    case "getCategoryChartBranch":
        getCategoryChartBranch($conn);
        break;

    case "getCategoryByBranch":
        getCategoryByBranch($conn);
        break;

    case "getProductsByBranchCategory":
        getProductsByBranchCategory($conn);
        break;

    case "getSizeWiseData":
        getSizeWiseData($conn);
        break;

    default:
        echo json_encode([
            "status" => false,
            "message" => "Invalid action"
        ]);
        break;
}

# ================= ERROR =================
function returnError() {
    echo json_encode([
        "status" => false,
        "error" => sqlsrv_errors()
    ]);
    exit;
}

# ================= CATEGORY CHART =================
function getCategoryChart($conn) {

    $sql = "
    SELECT 
        COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='0' THEN 1 END) AS TBR_stock,
        COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='1' THEN 1 END) AS TBR_ordered,

        STRING_AGG(CASE WHEN UPPER(p.category)='TBR' 
            THEN CAST(i.sku_code AS NVARCHAR(MAX)) END, ', ') AS TBR_skus,

        COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='0' THEN 1 END) AS LTR_stock,
        COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='1' THEN 1 END) AS LTR_ordered,

        STRING_AGG(CASE WHEN UPPER(p.category)='LTR' 
            THEN CAST(i.sku_code AS NVARCHAR(MAX)) END, ', ') AS LTR_skus,

        COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='0' THEN 1 END) AS PCR_stock,
        COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='1' THEN 1 END) AS PCR_ordered,

        STRING_AGG(CASE WHEN UPPER(p.category)='PCR' 
            THEN CAST(i.sku_code AS NVARCHAR(MAX)) END, ', ') AS PCR_skus

    FROM inventory i
    JOIN product_detail_description p ON p.id = i.product_id
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) returnError();

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    echo json_encode([
        "status" => true,
        "data" => $row
    ]);
}

# ================= BRANCH WISE CATEGORY =================
function getCategoryChartBranch($conn) {

    $from = $_GET['from_date'] ?? null;
    $to   = $_GET['to_date'] ?? null;

    $sql = "
    SELECT 
        b.branch_name,

        COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='0' THEN 1 END) AS TBR_stock,
        COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='1' THEN 1 END) AS TBR_ordered,

        STRING_AGG(CASE WHEN UPPER(p.category)='TBR' 
            THEN CAST(i.sku_code AS NVARCHAR(MAX)) END, ', ') AS TBR_skus,

        COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='0' THEN 1 END) AS LTR_stock,
        COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='1' THEN 1 END) AS LTR_ordered,

        STRING_AGG(CASE WHEN UPPER(p.category)='LTR' 
            THEN CAST(i.sku_code AS NVARCHAR(MAX)) END, ', ') AS LTR_skus,

        COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='0' THEN 1 END) AS PCR_stock,
        COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='1' THEN 1 END) AS PCR_ordered,

        STRING_AGG(CASE WHEN UPPER(p.category)='PCR' 
            THEN CAST(i.sku_code AS NVARCHAR(MAX)) END, ', ') AS PCR_skus

    FROM branch b
    LEFT JOIN inventory i 
        ON LTRIM(RTRIM(UPPER(i.to_branch))) = LTRIM(RTRIM(UPPER(b.branch_name)))
    LEFT JOIN product_detail_description p 
        ON p.id = i.product_id

    WHERE 1=1
    ";

    if ($from && $to) {
        $sql .= " AND CONVERT(date, i.created_on) BETWEEN '$from' AND '$to' ";
    }

    $sql .= "
    GROUP BY b.branch_name
    ORDER BY b.branch_name
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) returnError();

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

        $total =
            ($row['TBR_stock'] ?? 0) +
            ($row['TBR_ordered'] ?? 0) +
            ($row['LTR_stock'] ?? 0) +
            ($row['LTR_ordered'] ?? 0) +
            ($row['PCR_stock'] ?? 0) +
            ($row['PCR_ordered'] ?? 0);

        if ($total > 0) {
            $data[] = $row;
        }
    }

    echo json_encode([
        "status" => true,
        "count" => count($data),
        "data" => $data
    ]);
}

# ================= SINGLE BRANCH =================
function getCategoryByBranch($conn) {

    $branch_name = trim($_GET['branch_name'] ?? '');

    if ($branch_name == '') {
        echo json_encode([
            "status" => false,
            "message" => "branch_name is required"
        ]);
        return;
    }

    $sql = "
        SELECT 
            COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='0' THEN 1 END) AS TBR_stock,
            COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='1' THEN 1 END) AS TBR_ordered,

            COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='0' THEN 1 END) AS LTR_stock,
            COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='1' THEN 1 END) AS LTR_ordered,

            COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='0' THEN 1 END) AS PCR_stock,
            COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='1' THEN 1 END) AS PCR_ordered

        FROM inventory i
        INNER JOIN product_detail_description p ON p.id = i.product_id
        WHERE LTRIM(RTRIM(UPPER(i.to_branch))) = LTRIM(RTRIM(UPPER(?)))
    ";

    $stmt = sqlsrv_query($conn, $sql, [$branch_name]);
    if ($stmt === false) returnError();

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    echo json_encode([
        "status" => true,
        "data" => $row
    ]);
}

# ================= PRODUCT WISE =================
function getProductsByBranchCategory($conn) {

    $branch = trim($_GET['branch'] ?? '');
    $category = trim($_GET['category'] ?? '');

    if ($branch == '' || $category == '') {
        echo json_encode([
            "status" => false,
            "message" => "branch and category required"
        ]);
        return;
    }

    $sql = "
        SELECT 
            i.product_name,
            COUNT(CASE WHEN i.ordered='0' THEN 1 END) AS stock,
            COUNT(CASE WHEN i.ordered='1' THEN 1 END) AS ordered
        FROM inventory i
        WHERE 
            LTRIM(RTRIM(UPPER(i.to_branch))) = LTRIM(RTRIM(UPPER(?)))
            AND UPPER(i.category) = UPPER(?)
        GROUP BY i.product_name
    ";

    $stmt = sqlsrv_query($conn, $sql, [$branch, $category]);
    if ($stmt === false) returnError();

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
}

# ================= SIZE WISE =================
function getSizeWiseData($conn) {

    $branch = trim($_GET['branch'] ?? '');
    $category = trim($_GET['category'] ?? '');

    if ($branch == '' || $category == '') {
        echo json_encode([
            "status" => false,
            "message" => "branch and category required"
        ]);
        return;
    }

    $sql = "
        SELECT 
            p.type AS size,
            COUNT(*) AS total
        FROM inventory i
        INNER JOIN product_detail_description p ON p.id = i.product_id
        WHERE 
            LTRIM(RTRIM(UPPER(i.to_branch))) = LTRIM(RTRIM(UPPER(?)))
            AND UPPER(p.category) = UPPER(?)
        GROUP BY p.type
    ";

    $stmt = sqlsrv_query($conn, $sql, [$branch, $category]);
    if ($stmt === false) returnError();

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
}

# ================= ORDER CUSTOMER LIST =================
function getOrderCustomers($conn) {

    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    $search = $_GET['search'] ?? '';
    $branch = $_GET['branch'] ?? '';

    $sql = "
        SELECT 
            customer_name,
            contact_no,
            to_branch,
            MAX(order_date) AS order_date,
            SUM(quantity) AS total_qty,
            SUM(total_price) AS total_amount
        FROM orders
        WHERE 1=1
    ";

    if ($from && $to) {
        $sql .= " AND CAST(order_date AS DATE) BETWEEN '$from' AND '$to' ";
    }

    if ($search != '') {
        $sql .= " AND customer_name LIKE '%$search%' ";
    }

    if ($branch != '') {
        $sql .= " AND LTRIM(RTRIM(UPPER(to_branch))) = LTRIM(RTRIM(UPPER('$branch'))) ";
    }

    $sql .= "
        GROUP BY customer_name, contact_no, to_branch
        ORDER BY MAX(order_date) DESC
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) returnError();

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
}
?>