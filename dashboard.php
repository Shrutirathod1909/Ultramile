<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
require_once "db.php";

$action = $_GET['action'] ?? '';

switch ($action) {
case "getTopSalespersons":
    getTopSalespersons($conn);
    break;

case "getTopProducts":
    getTopProducts($conn);
    break;
    case "getCategoryChart":
        getCategoryChart($conn);
        break;
        case "getTopCustomers":
    getTopCustomers($conn);
    break;

    case "getProductProfit":
    getProductProfit($conn);
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
  case "getBranchCategoryDetails":
    getBranchCategoryDetails($conn);
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

# ================= HELPER (ZERO CHECK) =================
function isAllZero($row, $keys) {
    foreach ($keys as $k) {
        if (!empty($row[$k]) && $row[$k] != 0) return false;
    }
    return true;
}

function getProductProfit($conn)
{
    $from   = $_GET['from'] ?? null;
    $to     = $_GET['to'] ?? null;
    $branch = $_GET['branch'] ?? '';

    $sql = "
    SELECT
        FORMAT(CAST(order_date AS DATE), 'yyyy-MM') AS month,

        product_name,

        SUM(CASE 
            WHEN LOWER(status) = 'sales_inventory' 
            THEN ISNULL(qty, 0)
            ELSE 0 
        END) AS total_sold_qty,

        SUM(CASE 
            WHEN LOWER(status) = 'invt_received' 
            THEN ISNULL(product_qty, 0)
            ELSE 0 
        END) AS total_received_qty,

        (
            SUM(CASE WHEN LOWER(status) = 'invt_received' THEN ISNULL(product_qty,0) ELSE 0 END)
            -
            SUM(CASE WHEN LOWER(status) = 'sales_inventory' THEN ISNULL(qty,0) ELSE 0 END)
        ) AS remaining_stock,

        SUM(CASE 
            WHEN LOWER(status) = 'sales_inventory' 
            THEN ISNULL(sale_price,0) * ISNULL(qty,0)
            ELSE 0 
        END) AS total_sales,

        SUM(CASE 
            WHEN LOWER(status) = 'invt_received' 
            THEN ISNULL(purchase_price,0) * ISNULL(product_qty,0)
            ELSE 0 
        END) AS total_cost,

        (
            SUM(CASE WHEN LOWER(status) = 'sales_inventory'
                THEN ISNULL(sale_price,0) * ISNULL(qty,0)
                ELSE 0 END)
            -
            SUM(CASE WHEN LOWER(status) = 'invt_received'
                THEN ISNULL(purchase_price,0) * ISNULL(product_qty,0)
                ELSE 0 END)
        ) AS profit

    FROM inventory_log
    WHERE product_name IS NOT NULL
    ";

    $params = [];

    /* DATE FILTER */
    if ($from && $to) {
        $sql .= " AND CAST(order_date AS DATE) BETWEEN ? AND ? ";
        $params[] = $from;
        $params[] = $to;
    }

    /* BRANCH FILTER */
    if ($branch != '') {
        $sql .= " AND UPPER(LTRIM(RTRIM(to_branch))) = UPPER(LTRIM(RTRIM(?))) ";
        $params[] = $branch;
    }

    $sql .= "
    GROUP BY 
        FORMAT(CAST(order_date AS DATE), 'yyyy-MM'),
        product_name
    ORDER BY month DESC, profit DESC
    ";

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = [
            "month"              => $row["month"],
            "product_name"       => $row["product_name"],
            "total_sold_qty"     => (float)$row["total_sold_qty"],
            "total_received_qty" => (float)$row["total_received_qty"],
            "remaining_stock"    => (float)$row["remaining_stock"],
            "total_sales"        => (float)$row["total_sales"],
            "total_cost"         => (float)$row["total_cost"],
            "profit"             => (float)$row["profit"]
        ];
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
}
# ================= Top SalesPersons =================

function getTopSalespersons($conn) {

    $from   = $_GET['from'] ?? null;
    $to     = $_GET['to'] ?? null;
    $branch = $_GET['branch'] ?? '';

    $sql = "
    WITH BaseData AS (
        SELECT 
            FORMAT(TRY_CONVERT(DATE, o.order_date), 'yyyy-MM') AS month,
            o.created_by,
            u.fullname AS user_name,
            COUNT(*) AS total_orders,
            SUM(ISNULL(o.quantity, 0)) AS total_qty,
            SUM(ISNULL(TRY_CAST(o.final_amount AS DECIMAL(18,2)), 0)) AS total_sales
        FROM orders o
        LEFT JOIN users u ON u.id = o.created_by
        WHERE o.created_by IS NOT NULL
          AND o.created_by <> 0
          AND TRY_CONVERT(DATE, o.order_date) IS NOT NULL
    ";

    $params = [];

    if ($from && $to) {
        $sql .= " AND TRY_CONVERT(DATE, o.order_date) BETWEEN ? AND ? ";
        $params[] = $from;
        $params[] = $to;
    }

    if ($branch != '') {
        $sql .= " AND LTRIM(RTRIM(UPPER(o.to_branch))) = LTRIM(RTRIM(UPPER(?))) ";
        $params[] = $branch;
    }

    $sql .= "
        GROUP BY 
            FORMAT(TRY_CONVERT(DATE, o.order_date), 'yyyy-MM'),
            o.created_by,
            u.fullname
    ),
    Ranked AS (
        SELECT *,
        ROW_NUMBER() OVER (
            PARTITION BY month
            ORDER BY total_sales DESC
        ) AS rn
        FROM BaseData
    )
    SELECT *
    FROM Ranked
    WHERE rn <= 3
    ORDER BY month DESC, total_sales DESC
    ";

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode([
            "status" => false,
            "error" => sqlsrv_errors()
        ]);
        return;
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = [
            "month" => $row["month"],
            "user_id" => $row["created_by"],
            "name" => $row["user_name"],
            "orders" => (int)$row["total_orders"],
            "qty" => (int)$row["total_qty"],
            "sales" => (float)$row["total_sales"]
        ];
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
}
# ================= Top PRODUCT =================
function getTopProducts($conn) {

    $from   = $_GET['from'] ?? null;
    $to     = $_GET['to'] ?? null;
    $branch = $_GET['branch'] ?? '';

    $sql = "
    WITH MonthlyProductSales AS (
        SELECT 
            FORMAT(TRY_CONVERT(DATE, order_date), 'yyyy-MM') AS month,
            product_name,
            SUM(ISNULL(quantity, 0)) AS total_qty,
            SUM(ISNULL(TRY_CAST(final_amount AS DECIMAL(18,2)), 0)) AS total_sales,
            ROW_NUMBER() OVER (
                PARTITION BY FORMAT(TRY_CONVERT(DATE, order_date), 'yyyy-MM')
                ORDER BY SUM(ISNULL(quantity, 0)) DESC
            ) AS rn
        FROM orders
        WHERE final_amount IS NOT NULL
          AND TRY_CONVERT(DATE, order_date) IS NOT NULL
    ";

    $params = [];

    // 🔥 DATE FILTER
    if ($from && $to) {
        $sql .= " AND TRY_CONVERT(DATE, order_date) BETWEEN ? AND ? ";
        $params[] = $from;
        $params[] = $to;
    }

    // 🔥 BRANCH FILTER
    if ($branch != '') {
        $sql .= " AND LTRIM(RTRIM(UPPER(to_branch))) = LTRIM(RTRIM(UPPER(?))) ";
        $params[] = $branch;
    }

    $sql .= "
        GROUP BY 
            FORMAT(TRY_CONVERT(DATE, order_date), 'yyyy-MM'),
            product_name
    )
    SELECT 
        month,
        product_name,
        total_qty,
        total_sales
    FROM MonthlyProductSales
    WHERE rn <= 3
    ORDER BY month DESC, total_qty DESC
    ";

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode([
            "status" => false,
            "error" => sqlsrv_errors()
        ]);
        return;
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = [
            "month" => $row["month"],
            "product_name" => $row["product_name"],
            "total_qty" => (int)$row["total_qty"],
            "total_sales" => (float)$row["total_sales"]
        ];
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
}


# ================= Top Customer =================

function getTopCustomers($conn) {

    $from   = $_GET['from'] ?? null;
    $to     = $_GET['to'] ?? null;
    $branch = $_GET['branch'] ?? '';

    $sql = "
    WITH MonthlyCustomerSales AS (
        SELECT 
            FORMAT(TRY_CONVERT(DATE, order_date), 'yyyy-MM') AS month,
            customer_name,
            SUM(ISNULL(CAST(final_amount AS DECIMAL(18,2)), 0)) AS total_sales,
            ROW_NUMBER() OVER (
                PARTITION BY FORMAT(TRY_CONVERT(DATE, order_date), 'yyyy-MM')
                ORDER BY SUM(ISNULL(CAST(final_amount AS DECIMAL(18,2)), 0)) DESC
            ) AS rn
        FROM orders
        WHERE TRY_CONVERT(DATE, order_date) IS NOT NULL
    ";

    // ✅ Date filter
    if ($from && $to) {
        $sql .= " AND TRY_CONVERT(DATE, order_date) BETWEEN ? AND ? ";
    }

    // ✅ Branch filter
    if ($branch != '') {
        $sql .= " AND LTRIM(RTRIM(UPPER(to_branch))) = LTRIM(RTRIM(UPPER(?))) ";
    }

    $sql .= "
        GROUP BY FORMAT(TRY_CONVERT(DATE, order_date), 'yyyy-MM'), customer_name
    )
    SELECT 
        month,
        customer_name,
        total_sales
    FROM MonthlyCustomerSales
    WHERE rn <= 3
    ORDER BY month DESC, total_sales DESC
    ";

    $params = [];

    if ($from && $to) {
        $params[] = $from;
        $params[] = $to;
    }

    if ($branch != '') {
        $params[] = $branch;
    }

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode([
            "status" => false,
            "error" => sqlsrv_errors()
        ]);
        return;
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = [
            "month" => $row['month'],
            "customer_name" => $row['customer_name'],
            "total_sales" => (float)$row['total_sales']
        ];
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
}





# ================= CATEGORY CHART (SKU FIXED) =================
function getCategoryChart($conn) {

    $sql = "
    SELECT 

        COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='0' THEN 1 END) AS TBR_stock,
        COUNT(CASE WHEN UPPER(p.category)='TBR' AND i.ordered='1' THEN 1 END) AS TBR_ordered,

        STRING_AGG(
            CASE WHEN UPPER(p.category)='TBR' AND i.sku_code IS NOT NULL 
            THEN CAST(i.sku_code AS NVARCHAR(MAX)) END, ', '
        ) AS TBR_skus,

        COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='0' THEN 1 END) AS LTR_stock,
        COUNT(CASE WHEN UPPER(p.category)='LTR' AND i.ordered='1' THEN 1 END) AS LTR_ordered,

        STRING_AGG(
            CASE WHEN UPPER(p.category)='LTR' AND i.sku_code IS NOT NULL 
            THEN CAST(i.sku_code AS NVARCHAR(MAX)) END, ', '
        ) AS LTR_skus,

        COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='0' THEN 1 END) AS PCR_stock,
        COUNT(CASE WHEN UPPER(p.category)='PCR' AND i.ordered='1' THEN 1 END) AS PCR_ordered,

        STRING_AGG(
            CASE WHEN UPPER(p.category)='PCR' AND i.sku_code IS NOT NULL 
            THEN CAST(i.sku_code AS NVARCHAR(MAX)) END, ', '
        ) AS PCR_skus

    FROM inventory i
    JOIN product_detail_description p ON p.id = i.product_id
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) returnError();

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    $row['TBR_skus'] = $row['TBR_skus'] ?? '';
    $row['LTR_skus'] = $row['LTR_skus'] ?? '';
    $row['PCR_skus'] = $row['PCR_skus'] ?? '';

    if (isAllZero($row, [
        'TBR_stock','TBR_ordered',
        'LTR_stock','LTR_ordered',
        'PCR_stock','PCR_ordered'
    ])) {
        echo json_encode(["status" => true, "data" => []]);
        return;
    }

    echo json_encode([
        "status" => true,
        "data" => $row
    ]);
}

# ================= BRANCH CHART =================
function getCategoryChartBranch($conn) {

    $from = $_GET['from_date'] ?? null;
    $to   = $_GET['to_date'] ?? null;

    $dateFilter = "";
    if ($from && $to) {
        $dateFilter = " AND CONVERT(date, i2.created_on) BETWEEN '$from' AND '$to' ";
    }

    $sql = "
    SELECT 
        b.branch_name,

        -- TBR COUNT
        (
            SELECT COUNT(*) 
            FROM inventory i2
            JOIN product_detail_description p2 ON p2.id = i2.product_id
            WHERE UPPER(p2.category)='TBR'
            AND i2.ordered='0'
            AND i2.to_branch = b.branch_name
            $dateFilter
        ) AS TBR_stock,

        (
            SELECT COUNT(*) 
            FROM inventory i2
            JOIN product_detail_description p2 ON p2.id = i2.product_id
            WHERE UPPER(p2.category)='TBR'
            AND i2.ordered='1'
            AND i2.to_branch = b.branch_name
            $dateFilter
        ) AS TBR_ordered,

        -- TBR SKU (STOCK ONLY ✅)
        (
            SELECT STRING_AGG(sku_info, ', ')
            FROM (
                SELECT 
                    i2.sku_code,
                    CAST(i2.sku_code AS NVARCHAR) + '(' + CAST(COUNT(*) AS NVARCHAR) + ')' AS sku_info
                FROM inventory i2
                JOIN product_detail_description p2 ON p2.id = i2.product_id
                WHERE UPPER(p2.category)='TBR'
                AND i2.ordered = '0'
                AND i2.to_branch = b.branch_name
                $dateFilter
                GROUP BY i2.sku_code
            ) x
        ) AS TBR_skus,

        -- LTR COUNT
        (
            SELECT COUNT(*) 
            FROM inventory i2
            JOIN product_detail_description p2 ON p2.id = i2.product_id
            WHERE UPPER(p2.category)='LTR'
            AND i2.ordered='0'
            AND i2.to_branch = b.branch_name
            $dateFilter
        ) AS LTR_stock,

        (
            SELECT COUNT(*) 
            FROM inventory i2
            JOIN product_detail_description p2 ON p2.id = i2.product_id
            WHERE UPPER(p2.category)='LTR'
            AND i2.ordered='1'
            AND i2.to_branch = b.branch_name
            $dateFilter
        ) AS LTR_ordered,

        -- LTR SKU (STOCK ONLY ✅)
        (
            SELECT STRING_AGG(sku_info, ', ')
            FROM (
                SELECT 
                    i2.sku_code,
                    CAST(i2.sku_code AS NVARCHAR) + '(' + CAST(COUNT(*) AS NVARCHAR) + ')' AS sku_info
                FROM inventory i2
                JOIN product_detail_description p2 ON p2.id = i2.product_id
                WHERE UPPER(p2.category)='LTR'
                AND i2.ordered = '0'
                AND i2.to_branch = b.branch_name
                $dateFilter
                GROUP BY i2.sku_code
            ) x
        ) AS LTR_skus,

        -- PCR COUNT
        (
            SELECT COUNT(*) 
            FROM inventory i2
            JOIN product_detail_description p2 ON p2.id = i2.product_id
            WHERE UPPER(p2.category)='PCR'
            AND i2.ordered='0'
            AND i2.to_branch = b.branch_name
            $dateFilter
        ) AS PCR_stock,

        (
            SELECT COUNT(*) 
            FROM inventory i2
            JOIN product_detail_description p2 ON p2.id = i2.product_id
            WHERE UPPER(p2.category)='PCR'
            AND i2.ordered='1'
            AND i2.to_branch = b.branch_name
            $dateFilter
        ) AS PCR_ordered,

        -- PCR SKU (STOCK ONLY ✅)
        (
            SELECT STRING_AGG(sku_info, ', ')
            FROM (
                SELECT 
                    i2.sku_code,
                    CAST(i2.sku_code AS NVARCHAR) + '(' + CAST(COUNT(*) AS NVARCHAR) + ')' AS sku_info
                FROM inventory i2
                JOIN product_detail_description p2 ON p2.id = i2.product_id
                WHERE UPPER(p2.category)='PCR'
                AND i2.ordered = '0'
                AND i2.to_branch = b.branch_name
                $dateFilter
                GROUP BY i2.sku_code
            ) x
        ) AS PCR_skus

    FROM branch b
    ORDER BY b.branch_name
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) returnError();

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

        if (isAllZero($row, [
            'TBR_stock','TBR_ordered',
            'LTR_stock','LTR_ordered',
            'PCR_stock','PCR_ordered'
        ])) continue;

        $data[] = $row;
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
}



# ================= SINGLE BRANCH =================
function getCategoryByBranch($conn) {

    $branch_name = trim($_GET['branch_name'] ?? '');

    if ($branch_name == '') {
        echo json_encode(["status" => false, "message" => "branch_name required"]);
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


function getBranchCategoryDetails($conn) {

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
            i.sku_code,
            i.ordered,
            p.category,
            p.type
        FROM inventory i
        INNER JOIN product_detail_description p ON p.id = i.product_id
        WHERE 
            LTRIM(RTRIM(UPPER(i.to_branch))) = LTRIM(RTRIM(UPPER(?)))
            AND UPPER(p.category) = UPPER(?)
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