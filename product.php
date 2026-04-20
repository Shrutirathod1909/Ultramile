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

    // ❌ JSON mat use karo
    // $input hata do

    $data = $_POST;  // ✅ use this

    $user_id = $data['user_id'] ?? 1;

    /* ===== IMAGE UPLOAD ===== */
    function uploadImage($fileKey)
    {
        if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] != 0) {
            return "";
        }

        $dir = "productgallery/img/";
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
        $fileName = time() . "_" . rand(1000, 9999) . "." . $ext;

        $path = $dir . $fileName;
        move_uploaded_file($_FILES[$fileKey]['tmp_name'], $path);

        return $path;
    }

    $img1 = uploadImage("img1");
    $img2 = uploadImage("img2");
    $img3 = uploadImage("img3");
    $img4 = uploadImage("img4");

    /* ===== INSERT ===== */
    $sql = "INSERT INTO product_detail_description
    (product_name, product_code, category, subcategory, type, unit, mrp_price, sale_price, gst_type, gst_perc, active, created_on, created_by, subcode,
     product_img_1, product_img_2, product_img_3, product_img_4)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, ?, ?, ?, ?, ?)";

    $params = [
        $data['product_name'] ?? '',
        $data['product_code'] ?? '',
        $data['category'] ?? '',
        $data['subcategory'] ?? '',
        $data['type'] ?? '',
        $data['unit'] ?? '',
        $data['mrp_price'] ?? 0,
        $data['sale_price'] ?? 0,
        $data['gst_type'] ?? 'Exclusive',
        $data['gst_perc'] ?? 0,
        $data['active'] ?? 1,
        $user_id,
        $data['subcode'] ?? '',
        $img1,
        $img2,
        $img3,
        $img4
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt) {
        echo json_encode([
            "status" => true,
            "message" => "Product Added Successfully"
        ]);
    } else {
        echo json_encode([
            "status" => false,
            "message" => "Insert Failed",
            "error" => sqlsrv_errors()
        ]);
    }
}

/* =====================================================
   2. LIST PRODUCT (NULL SAFE)
===================================================== */ elseif ($action == "list") {

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
            "subcode" => $row['subcode'] ?? "",

            // 🔥 ADD THIS
            "product_img_1" => $row['product_img_1'] ?? "",
            "product_img_2" => $row['product_img_2'] ?? "",
            "product_img_3" => $row['product_img_3'] ?? "",
            "product_img_4" => $row['product_img_4'] ?? ""
        ];
    }

    echo json_encode(["status" => true, "data" => $data]);
}

/* =====================================================
   3. UPDATE PRODUCT
===================================================== */ elseif ($action == "update") {

    $data = $_POST; // multipart use

    $id = $data['id'];

    /* ===== OLD IMAGES FETCH ===== */
    $oldSql = "SELECT product_img_1, product_img_2, product_img_3, product_img_4 
               FROM product_detail_description WHERE id = ?";
    $oldStmt = sqlsrv_query($conn, $oldSql, [$id]);
    $old = sqlsrv_fetch_array($oldStmt, SQLSRV_FETCH_ASSOC);

    /* ===== IMAGE UPLOAD FUNCTION ===== */
    function uploadImage($key, $oldPath)
    {

        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] != 0) {
            return $oldPath; // old keep
        }

        $dir = "productgallery/img/";
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        $ext = strtolower(pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION));
        $fileName = time() . "_" . rand(1000, 9999) . "." . $ext;
        $path = $dir . $fileName;

        move_uploaded_file($_FILES[$key]['tmp_name'], $path);

        // old delete
        if (!empty($oldPath) && file_exists($oldPath)) {
            unlink($oldPath);
        }

        return $path;
    }

    /* ===== HANDLE 4 IMAGES ===== */
    $img1 = uploadImage("img1", $old['product_img_1']);
    $img2 = uploadImage("img2", $old['product_img_2']);
    $img3 = uploadImage("img3", $old['product_img_3']);
    $img4 = uploadImage("img4", $old['product_img_4']);

    /* ===== UPDATE ===== */
    $sql = "UPDATE product_detail_description SET
        product_name = ?, product_code = ?, category = ?, subcategory = ?, 
        type = ?, unit = ?, mrp_price = ?, sale_price = ?, 
        gst_type = ?, gst_perc = ?, active = ?, 
        modified_on = GETDATE(), subcode = ?,
        product_img_1 = ?, product_img_2 = ?, product_img_3 = ?, product_img_4 = ?
        WHERE id = ?";

    $params = [
        $data['product_name'],
        $data['product_code'],
        $data['category'],
        $data['subcategory'],
        $data['type'],
        $data['unit'],
        $data['mrp_price'],
        $data['sale_price'],
        $data['gst_type'],
        $data['gst_perc'],
        $data['active'],
        $data['subcode'],
        $img1,
        $img2,
        $img3,
        $img4,
        $id
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    echo json_encode([
        "status" => $stmt ? true : false,
        "message" => $stmt ? "Updated with 4 Images" : "Update Failed"
    ]);
}
/* =====================================================
   4. DELETE PRODUCT
===================================================== */ elseif ($action == "delete") {

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
   5. PRODUCT TYPE COUNT (LTR / PCR / TBR)
===================================================== */ elseif ($action == "type_count") {

    $sql = "SELECT category, COUNT(*) AS total
            FROM product_detail_description
            GROUP BY category";

    $stmt = sqlsrv_query($conn, $sql);

    $data = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = [
            "type" => $row['category'] ?? "",
            "total" => $row['total'] ?? 0
        ];
    }

    echo json_encode([
        "status" => true,
        "data" => $data
    ]);
}
/* =====================================================
   DEFAULT
===================================================== */ else {
    echo json_encode(["status" => false, "message" => "Invalid Action"]);
}
