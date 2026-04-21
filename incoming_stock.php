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
    $stmt = sqlsrv_query(
        $conn,
        "SELECT * FROM users WHERE id = ?",
        [$user_id]
    );

    if ($stmt === false) return null;

    return sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
}

/* ================= SAFE ID ================= */
function getNextId($conn)
{
    $sql = "SELECT ISNULL(MAX(inventory_id),0)+1 AS id FROM incoming_stock WITH (UPDLOCK, HOLDLOCK)";
    $q = sqlsrv_query($conn, $sql);
    $r = sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC);
    return $r['id'];
}

/* ================= INSERT STOCK ================= */
function insertStock($conn, $post, $user_id)
{
    // 🔥 FIX: user_id safe
    if (!$user_id || $user_id == 0) {
        return ["status" => false, "message" => "Invalid user ID"];
    }

    $user = getUser($conn, $user_id);

    if (!$user) {
        return ["status" => false, "message" => "Invalid user"];
    }

    $product_name = $post['product_name'] ?? '';

    if ($product_name == '') {
        return ["status" => false, "message" => "product_name missing"];
    }

    $p = sqlsrv_query(
        $conn,
        "SELECT * FROM product_detail_description WHERE product_name = ?",
        [$product_name]
    );

    $product = sqlsrv_fetch_array($p, SQLSRV_FETCH_ASSOC);

    if (!$product) {
        return ["status" => false, "message" => "Product not found"];
    }

    $qty = intval($post['quantity'] ?? 0);

    if ($qty <= 0) {
        return ["status" => false, "message" => "Invalid quantity"];
    }

    $purchase_price = floatval($post['purchase_price'] ?? $product['purchase_price'] ?? 0);
    $sale_price = floatval($post['sale_price'] ?? $product['sale_price'] ?? 0);

    sqlsrv_begin_transaction($conn);

    try {

        $inventory_id = getNextId($conn);
        $barcode = rand(10000, 99999) . $inventory_id;

        $sql = "
        INSERT INTO incoming_stock (
            inventory_id,
            bill_type,
            invoice_no,
            vendor_name,
            product_name,
            product_id,
            sku_code,
            category,
            subcategory,
            size,
            color,
            purchase_price,
            sale_price,
            barcode,
            created_by,
            created_on,
            ordered,
            status,
            from_branch,
            to_branch,
            active,
            expected_date,
            invoice_date,
            container_no,
            bl_no,
            received_date,
            product_qty
        )
        VALUES (
            ?,?,?,?,?,?,?,?,?,?,
            ?,?,?,?,?,
            GETDATE(),
            'N',
            'received',
            ?,?,
            0,
            ?,?,?,?,?,
            ?
        )";

        $params = [
            $inventory_id,
            $post['bill_type'] ?? 'IN',
            $post['invoice_no'] ?? '',
            $product['vendor_name'] ?? '',
            $product['product_name'],
            $product['id'],
            $product['sku_code'] ?? '',
            $product['category'] ?? '',
            $product['subcategory'] ?? '',
            $product['size'] ?? '',
            $product['color'] ?? '',
            $purchase_price,
            $sale_price,
            $barcode,
            $user_id,
            $user['city'] ?? '',
            $user['city'] ?? '',
            $post['expected_date'] ?? null,
            $post['invoice_date'] ?? null,
            $post['container_no'] ?? null,
            $post['bl_no'] ?? null,
            $post['received_date'] ?? null,
            $qty
        ];

        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            throw new Exception(print_r(sqlsrv_errors(), true));
        }

        sqlsrv_commit($conn);

        return [
            "status" => true,
            "message" => "Stock inserted successfully"
        ];

    } catch (Exception $e) {
        sqlsrv_rollback($conn);

        return [
            "status" => false,
            "message" => "INSERT FAILED",
            "error" => $e->getMessage()
        ];
    }
}

/* ================= LIST STOCK ================= */
function listStock($conn, $user_id)
{
    $sql = "SELECT 
                inventory_id,
                product_name,
                sku_code,
                invoice_no,
                container_no,
                bl_no,
                purchase_price,
                sale_price,
                product_qty,
                status,
                created_on
            FROM incoming_stock
            WHERE created_by = ?
            ORDER BY inventory_id DESC";

    $stmt = sqlsrv_query($conn, $sql, [$user_id]);

    if ($stmt === false) {
        response(false, "Query failed", sqlsrv_errors());
    }

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

        if ($row['created_on'] instanceof DateTime) {
            $row['created_on'] = $row['created_on']->format('Y-m-d');
        }

        $data[] = $row;
    }

    response(true, "Incoming stock list", $data);
}

/* ================= ROUTER ================= */
$action = $_GET['action'] ?? '';

// 🔥 FIX HERE (MOST IMPORTANT)
$user_id = $_GET['user_id']
    ?? $_POST['user_id']
    ?? 0;

$post = json_decode(file_get_contents("php://input"), true);
if (!$post) $post = $_POST;

switch ($action) {

    case "insert_stock":
        echo json_encode(insertStock($conn, $post, $user_id));
        break;

          case "list":
        listStock($conn, $user_id);
        break;


    default:
        response(false, "Invalid action");
}
?>