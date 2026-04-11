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

# ================= TOTAL CATEGORY =================
function getCategoryChart($conn)
{
    $sql = "
        SELECT 
            COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='0' THEN 1 END) AS TBR_stock,
            COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='1' THEN 1 END) AS TBR_ordered,

            COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='0' THEN 1 END) AS LTR_stock,
            COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='1' THEN 1 END) AS LTR_ordered,

            COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='0' THEN 1 END) AS PCR_stock,
            COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='1' THEN 1 END) AS PCR_ordered

        FROM inventory i
        INNER JOIN product_detail_description p 
            ON p.id = i.product_id
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) returnError();

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    echo json_encode([
        "status" => true,
        "data" => $row ?: [
            "TBR_stock"=>0,"TBR_ordered"=>0,
            "LTR_stock"=>0,"LTR_ordered"=>0,
            "PCR_stock"=>0,"PCR_ordered"=>0
        ]
    ]);
}

# ================= BRANCH WISE CATEGORY =================
function getCategoryChartBranch($conn)
{
    $sql = "
        SELECT 
            b.id AS branch_id,
            b.branch_name,

            COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='0' THEN 1 END) AS TBR_stock,
            COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='1' THEN 1 END) AS TBR_ordered,

            COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='0' THEN 1 END) AS LTR_stock,
            COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='1' THEN 1 END) AS LTR_ordered,

            COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='0' THEN 1 END) AS PCR_stock,
            COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='1' THEN 1 END) AS PCR_ordered

        FROM branch b
        LEFT JOIN inventory i 
            ON LTRIM(RTRIM(UPPER(i.to_branch))) = LTRIM(RTRIM(UPPER(b.branch_name)))
        LEFT JOIN product_detail_description p 
            ON p.id = i.product_id
        GROUP BY b.id, b.branch_name
        ORDER BY b.branch_name ASC
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) returnError();

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode([
        "status" => true,
        "count" => count($data),
        "data" => $data
    ]);
}

# ================= SINGLE BRANCH =================
function getCategoryByBranch($conn)
{
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
        INNER JOIN product_detail_description p 
            ON p.id = i.product_id
        WHERE LTRIM(RTRIM(UPPER(i.to_branch))) = LTRIM(RTRIM(UPPER(?)))
    ";

    $stmt = sqlsrv_query($conn, $sql, [$branch_name]);
    if ($stmt === false) returnError();

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    echo json_encode([
        "status" => true,
        "data" => $row ?: [
            "TBR_stock"=>0,"TBR_ordered"=>0,
            "LTR_stock"=>0,"LTR_ordered"=>0,
            "PCR_stock"=>0,"PCR_ordered"=>0
        ]
    ]);
}

# ================= PRODUCT WISE =================
function getProductsByBranchCategory($conn)
{
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
        ORDER BY i.product_name ASC
    ";

    $stmt = sqlsrv_query($conn, $sql, [$branch, $category]);
    if ($stmt === false) returnError();

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    echo json_encode([
        "status" => true,
        "count" => count($data),
        "data" => $data
    ]);
}

# ================= SIZE WISE =================
function getSizeWiseData($conn)
{
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
        INNER JOIN product_detail_description p 
            ON p.id = i.product_id
        WHERE 
            LTRIM(RTRIM(UPPER(i.to_branch))) = LTRIM(RTRIM(UPPER(?)))
            AND UPPER(p.category) = UPPER(?)
        GROUP BY p.type
        ORDER BY p.type ASC
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

# ================= ERROR =================
function returnError()
{
    echo json_encode([
        "status" => false,
        "error" => sqlsrv_errors()
    ]);
    exit;
}
?>