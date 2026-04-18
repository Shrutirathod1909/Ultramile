<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
require_once "db.php";

/* ================= RESPONSE ================= */
function response($status, $message, $data = [])
{
    echo json_encode([
        "status" => $status,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}

/* ================= USER ================= */
function getUser($conn, $user_id)
{
    $sql = "SELECT TOP 1 * FROM users WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$user_id]);

    if (!$stmt) return null;

    return sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
}

/* ================= RANDOM TRANSFER ID ================= */
function transferId()
{
    return substr(md5(uniqid(mt_rand(), true)), 0, 10);
}

/* ================= STOCK TRANSFER (CREATE) ================= */
function stockTransfer($conn, $post)
{
    $user_id = intval($post['user_id'] ?? 0);
    $product_name = trim($post['product_name'] ?? '');
    $quantity = intval($post['quantity'] ?? 0);

    $from_branch = trim($post['from_branch'] ?? '');
    $to_branch = trim($post['to_branch'] ?? '');

    $invoice_no = trim($post['invoice_no'] ?? '');
    $vehicle_no = trim($post['vehicle_no'] ?? '');

    if ($user_id <= 0 || empty($product_name) || $quantity <= 0) {
        return ["status" => false, "message" => "Invalid input"];
    }

    /* 🔥 STEP 1: GET product_id FROM NAME */
    $sqlProd = "
        SELECT TOP 1 id 
        FROM product_detail_description 
        WHERE LTRIM(RTRIM(product_name)) = LTRIM(RTRIM(?))
    ";

    $stmtProd = sqlsrv_query($conn, $sqlProd, [$product_name]);

    if (!$stmtProd) {
        return ["status" => false, "message" => "Product query failed"];
    }

    $productRow = sqlsrv_fetch_array($stmtProd, SQLSRV_FETCH_ASSOC);

    if (!$productRow) {
        return ["status" => false, "message" => "Product not found"];
    }

    $product_id = $productRow['id'];

    /* 🔥 STEP 2: STOCK CHECK */
    $sql = "
        SELECT TOP (?) inventory_id
        FROM inventory
        WHERE product_id = ?
        AND status = 'received'
        AND to_branch = ?
        ORDER BY inventory_id ASC
    ";

    $stmt = sqlsrv_query($conn, $sql, [$quantity, $product_id, $from_branch]);

    if (!$stmt) {
        return ["status" => false, "message" => "Stock query failed"];
    }

    $stock = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $stock[] = $row;
    }

    if (count($stock) < $quantity) {
        return [
            "status" => false,
            "message" => "Not enough stock",
            "available" => count($stock)
        ];
    }

    $transfer_id = substr(md5(uniqid()), 0, 10);

    sqlsrv_begin_transaction($conn);

    try {
        $update = "
            UPDATE inventory SET
                transfer_no = ?,
                transfer_id = ?,
                vehicle_no = ?,
                from_branch = ?,
                to_branch = ?,
                status = 'intransit',
                transfer_date = GETDATE()
            WHERE inventory_id = ?
        ";

        foreach ($stock as $row) {
            $res = sqlsrv_query($conn, $update, [
                $invoice_no,
                $transfer_id,
                $vehicle_no,
                $from_branch,
                $to_branch,
                $row['inventory_id']
            ]);

            if (!$res) {
                throw new Exception("Update failed");
            }
        }

        sqlsrv_commit($conn);

        return [
            "status" => true,
            "message" => "Stock transferred successfully",
            "transfer_id" => $transfer_id,
            "product_id" => $product_id
        ];

    } catch (Exception $e) {
        sqlsrv_rollback($conn);

        return [
            "status" => false,
            "message" => "Transfer failed",
            "error" => $e->getMessage()
        ];
    }
}
/* ================= INTRANSIT STOCK ================= */
function getInTransit($conn)
{
    $sql = "
        SELECT *
        FROM inventory
        WHERE status = 'intransit'
        ORDER BY transfer_date DESC
    ";

    $stmt = sqlsrv_query($conn, $sql);

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    response(true, "In-transit stock list", $data);
}

/* ================= HISTORY ================= */
function getHistory($conn)
{
    $sql = "
        SELECT *
        FROM inventory
        WHERE transfer_id IS NOT NULL
        ORDER BY transfer_date DESC
    ";

    $stmt = sqlsrv_query($conn, $sql);

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }

    response(true, "Transfer history", $data);
}

/* ================= ROUTER ================= */
$action = $_GET['action'] ?? '';

switch ($action) {

    case "transfer":
        echo json_encode(stockTransfer($conn, $_POST));
        break;

    case "intransit":
        getInTransit($conn);
        break;

    case "history":
        getHistory($conn);
        break;

    default:
        response(false, "Invalid action");
}
?>