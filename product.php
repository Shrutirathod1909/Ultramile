<?php
error_reporting(0);
ini_set('display_errors', 0);

/* ================= INCLUDE DB ================= */
include 'db.php';

/* ===== GET ACTION ===== */
$action = $_GET['action'] ?? '';

/* ===== GET JSON INPUT ===== */
$input = json_decode(file_get_contents("php://input"), true);

/* =====================================================
   1. ADD PRODUCT
===================================================== */
if ($action == "add") {

    $user_id = $input['user_id'] ?? 1; // 🔥 MUST ADD THIS

    $sql = "INSERT INTO product_detail_description
    (product_name, product_code, category, subcategory, type, unit, mrp_price, sale_price, gst_type, gst_perc, active, created_on, created_by, subcode)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, ?)";

    $params = [
        $input['product_name'] ?? '',
        $input['product_code'] ?? '',
        $input['category'] ?? '',
        $input['subcategory'] ?? '',
        $input['type'] ?? '',
        $input['unit'] ?? '',
        $input['mrp_price'] ?? 0,
        $input['sale_price'] ?? 0,
        $input['gst_type'] ?? '',
        $input['gst_perc'] ?? 0,
        $input['active'] ?? 1,
        $user_id,   // ✅ NOW WILL WORK
        $input['subcode'] ?? ''
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode([
            "status" => false,
            "message" => "Insert Failed",
            "error" => sqlsrv_errors()
        ]);
    } else {
        echo json_encode([
            "status" => true,
            "message" => "Product Added"
        ]);
    }
}

/* =====================================================
   2. LIST PRODUCT (NULL SAFE)
===================================================== */
elseif ($action == "list") {

    $sql = "SELECT * FROM product_detail_description ORDER BY id DESC";
    $stmt = sqlsrv_query($conn, $sql);

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

        $data[] = [
            "id" => $row['id'],
            "product_name" => $row['product_name'] ?? "",
            "product_code" => $row['product_code'] ?? "",
            "category" => $row['category'] ?? "",
            "subcategory" => $row['subcategory'] ?? "General",
            "type" => $row['type'] ?? "Standard",
            "unit" => $row['unit'] ?? "pcs",
            "mrp_price" => $row['mrp_price'] ?? 0,
            "sale_price" => $row['sale_price'] ?? 0,
            "gst_type" => $row['gst_type'] ?? "",
            "gst_perc" => $row['gst_perc'] ?? 0,
            "active" => $row['active'] ?? 0,
            "created_on" => isset($row['created_on']) ? $row['created_on']->format('Y-m-d H:i:s') : "",
            "subcode" => $row['subcode'] ?? ""
        ];
    }

    echo json_encode(["status" => true, "data" => $data]);
}

/* =====================================================
   3. UPDATE PRODUCT
===================================================== */
elseif ($action == "update") {

    $sql = "UPDATE product_detail_description SET
        product_name = ?, product_code = ?, category = ?, subcategory = ?, 
        type = ?, unit = ?, mrp_price = ?, sale_price = ?, 
        gst_type = ?, gst_perc = ?, active = ?, 
        modified_on = GETDATE(), subcode = ?
        WHERE id = ?";

    $params = [
        $input['product_name'],
        $input['product_code'],
        $input['category'],
        $input['subcategory'],
        $input['type'],
        $input['unit'],
        $input['mrp_price'],
        $input['sale_price'],
        $input['gst_type'],
        $input['gst_perc'],
        $input['active'],
        $input['subcode'],
        $input['id']
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt) {
        echo json_encode(["status" => true, "message" => "Updated"]);
    } else {
        echo json_encode(["status" => false, "message" => "Update Failed"]);
    }
}

/* =====================================================
   4. DELETE PRODUCT
===================================================== */
elseif ($action == "delete") {

    $id = $_GET['id'] ?? 0;

    $sql = "DELETE FROM product_detail_description WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$id]);

    if ($stmt) {
        echo json_encode(["status" => true, "message" => "Deleted"]);
    } else {
        echo json_encode(["status" => false, "message" => "Delete Failed"]);
    }
}

/* =====================================================
   DEFAULT
===================================================== */
else {
    echo json_encode(["status" => false, "message" => "Invalid Action"]);
}
?>