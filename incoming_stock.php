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
    $stmt = sqlsrv_query($conn, "SELECT * FROM users WHERE id = ?", [$user_id]);
    if ($stmt === false) return null;

    return sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
}

/* ================= SAFE NEXT ID ================= */
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
    $user = getUser($conn, $user_id);

    if (!$user) {
        return ["status" => false, "message" => "Invalid user"];
    }

    $product_ids = $post['product_id'] ?? [];
    $qty_arr     = $post['quantity'] ?? [];

    if (empty($product_ids)) {
        return ["status" => false, "message" => "product_id missing"];
    }

    sqlsrv_begin_transaction($conn);

    try {

        for ($i = 0; $i < count($product_ids); $i++) {

            $product_id = intval($product_ids[$i]);
            $qty = intval($qty_arr[$i] ?? 0);

            if ($qty <= 0) continue;

            /* ================= GET PRODUCT ================= */
            $p = sqlsrv_query(
                $conn,
                "SELECT * FROM product_detail_description WHERE id = ?",
                [$product_id]
            );

            if ($p === false) {
                throw new Exception("Product fetch failed");
            }

            $product = sqlsrv_fetch_array($p, SQLSRV_FETCH_ASSOC);
            if (!$product) continue;

            for ($j = 0; $j < $qty; $j++) {

                $inventory_id = getNextId($conn);
                $barcode = rand(10000, 99999) . substr($inventory_id, -2);

                /* ================= INSERT ================= */
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
                    received_date
                )
                VALUES (
                    ?,?,?,?,?,?,?,?,?,?,   -- 10
                    ?,?,?,?,? ,           -- 5 (15)
                    GETDATE(),
                    'N',
                    'received',
                    ?,?,                 -- (17)
                    ?,?,?,?,             -- (21)
                    ?,?                 -- (23)
                )
                ";

                /* ================= PARAMS ================= */
                $params = [
                    $inventory_id,                         //1
                    $post['bill_type'] ?? 'IN',            //2
                    $post['invoice_no'] ?? '',             //3
                    $product['vendor_name'] ?? '',         //4
                    $product['product_name'] ?? '',        //5
                    $product_id,                           //6
                    $product['sku_code'] ?? '',            //7
                    $product['category'] ?? '',            //8
                    $product['subcategory'] ?? '',         //9
                    $product['size'] ?? '',                //10
                    $product['color'] ?? '',               //11
                    $product['purchase_price'] ?? 0,       //12
                    $product['sale_price'] ?? 0,           //13
                    $barcode,                              //14
                    $user_id,                              //15
                    $user['city'],                         //16
                    $user['city'],                         //17
                    '0',                                   //18 active ✅
                    $post['expected_date'][$i] ?? null,    //19
                    $post['invoice_date'] ?? null,         //20
                    $post['container_no'] ?? null,         //21
                    $post['bl_no'] ?? null,                //22
                    $post['received_date'] ?? null         //23
                ];

                /* ================= COUNT CHECK ================= */
                if (count($params) != 23) {
                    throw new Exception("Parameter mismatch: " . count($params));
                }

                $stmt = sqlsrv_query($conn, $sql, $params);

                if ($stmt === false) {
                    throw new Exception(print_r(sqlsrv_errors(), true));
                }
            }
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
$user_id = $_GET['user_id'] ?? 0;

switch ($action) {

    case "insert_stock":
        echo json_encode(insertStock($conn, $_POST, $user_id));
        break;

    case "list":
        listStock($conn, $user_id);
        break;

    default:
        response(false, "Invalid action");
}
?>