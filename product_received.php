<?php
error_reporting(E_ALL);
ini_set('display_errors',1);

header("Content-Type: application/json");
require_once "db.php";

date_default_timezone_set("Asia/Kolkata");

function response($status,$message,$data=[]){
 echo json_encode([
   "status"=>$status,
   "message"=>$message,
   "data"=>$data
 ]);
 exit;
}

$post=json_decode(
 file_get_contents("php://input"),
 true
);

if(!$post){
 $post=$_POST;
}

$type=$_GET['type'] ?? "";


/* ================= INSERT ================= */

if($type=="insert"){

$product_name=trim(
 $post['product_name'] ?? ''
);

$qty=intval(
 $post['qty'] ?? 0
);

$barcode=trim(
 $post['barcode'] ?? ''
);

if($product_name=='' || $qty<=0){
 response(
  false,
  "product_name and qty required"
 );
}

/* PRODUCT */

$p=sqlsrv_query(
 $conn,
 "SELECT * FROM product_detail_description
  WHERE product_name=?",
 [$product_name]
);

$product=sqlsrv_fetch_array(
 $p,
 SQLSRV_FETCH_ASSOC
);

if(!$product){
 response(false,"Product not found");
}

/* OPTIONAL */

$purchase_price=floatval(
 $post['purchase_price']
 ?? $product['purchase_price']
 ?? 0
);

$container_no=
$post['container_no'] ?? null;

$bl_no=
$post['bl_no'] ?? null;

$invoice_no=
$post['invoice_no'] ?? null;

$invoice_date=
$post['invoice_date']
?? date('Y-m-d');

$created_by=intval(
$post['created_by'] ?? 0
);

$status=
$post['status']
?? "invt_received";


if($barcode==''){
 $barcode=
 "BC".rand(
 100000,
 999999
 );
}

sqlsrv_begin_transaction(
 $conn
);

try{

/* ===== INSERT INVENTORY ===== */

$sql1="
INSERT INTO inventory(

 product_name,
 product_id,
 sku_code,
 category,
 subcategory,
 size,
 color,
 barcode,
 purchase_price,
 product_qty,
 container_no,
 bl_no,
 bill_type,
 invoice_no,
 invoice_date,
 vendor_name,
 created_by,
 created_on,
 status,
 active,
 readable,
 received_date

)

OUTPUT INSERTED.inventory_id

VALUES(

 ?,?,?,?,?,?,?,?,?,?,
 ?,?,?,?,?,
 ?,?,
 GETDATE(),
 ?,?,?,GETDATE()

)";

$params1=[

$product_name,
$product['id'],
$post['sku_code'] ?? $product['sku_code'],
$product['category'],
$product['subcategory'],
$product['size'],
$product['color'],
$barcode,
$purchase_price,
$qty,
$container_no,
$bl_no,
'IN',
$invoice_no,
$invoice_date,
$product['vendor_name'],
$created_by,
$status,
1,
1

];

$stmt1=
sqlsrv_query(
 $conn,
 $sql1,
 $params1
);

if($stmt1===false){
 throw new Exception(
 print_r(
 sqlsrv_errors(),
 true
 )
 );
}

/* GET INSERTED ID */

$row=
sqlsrv_fetch_array(
 $stmt1,
 SQLSRV_FETCH_ASSOC
);

$inventory_id=
intval(
$row['inventory_id']
);

if(
 !$inventory_id
){
 throw new Exception(
 "Inventory ID not generated"
 );
}


/* ===== TOTAL STOCK ===== */

$totalQtySql=
sqlsrv_query(
$conn,

"SELECT
 SUM(product_qty)
 total_qty

 FROM inventory

 WHERE product_id=?",

[$product['id']]
);

$totalRow=
sqlsrv_fetch_array(
$totalQtySql,
SQLSRV_FETCH_ASSOC
);

$totalproduct_qty=
intval(
$totalRow['total_qty']
?? 0
);


//* ===== AVG PRICE ===== */

$avgSql=
sqlsrv_query(
$conn,

"SELECT

SUM(
 product_qty * purchase_price
) as total_value,

SUM(
 product_qty
) as total_qty

FROM inventory

WHERE product_id=?",

[$product['id']]
);

$avgRow=
sqlsrv_fetch_array(
$avgSql,
SQLSRV_FETCH_ASSOC
);

$totalValue=
floatval(
$avgRow['total_value'] ?? 0
);

$totalQty=
floatval(
$avgRow['total_qty'] ?? 0
);

/* weighted average */

$average_price=
($totalQty > 0)
? round(
   $totalValue / $totalQty,
   2
 )
: 0;

/* ===== INSERT LOG ===== */

$sql2="

INSERT INTO inventory_log(

inventory_id,
product_name,
product_id,
sku_code,
category,
subcategory,
size,
color,
barcode,
purchase_price,
product_qty,
totalproduct_qty,
average_price,
container_no,
bl_no,
invoice_no,
invoice_date,
created_by,
created_on,
status

)

VALUES(

?,?,?,?,?,?,?,?,?,?,
?,?,?,?,?,
?,?,?,
GETDATE(),
?

)";

$params2=[

$inventory_id,
$product_name,
$product['id'],
$post['sku_code'] ?? $product['sku_code'],
$product['category'],
$product['subcategory'],
$product['size'],
$product['color'],
$barcode,
$purchase_price,
$qty,
$totalproduct_qty,
$average_price,
$container_no,
$bl_no,
$invoice_no,
$invoice_date,
$created_by,
$status

];

$stmt2=
sqlsrv_query(
$conn,
$sql2,
$params2
);

if($stmt2===false){

throw new Exception(
 print_r(
 sqlsrv_errors(),
 true
 )
);

}

sqlsrv_commit($conn);

response(
true,
"Inserted Successfully",
[
"inventory_id"=>$inventory_id,
"totalproduct_qty"=>$totalproduct_qty,
"average_price"=>$average_price
]
);

}

catch(Exception $e){

sqlsrv_rollback(
$conn
);

response(
false,
"Insert failed",
$e->getMessage()
);

}

}



/* ================= LIST ================= */

if($type=="list"){

$sql="
SELECT *
FROM inventory
ORDER BY inventory_id DESC
";

$stmt=
sqlsrv_query(
$conn,
$sql
);

$data=[];

while(
$row=
sqlsrv_fetch_array(
$stmt,
SQLSRV_FETCH_ASSOC
)
){
$data[]=$row;
}

response(
true,
"List fetched",
$data
);

}

/*
=================================================
3️⃣ PRODUCT LIST
=================================================
*/

if ($type == "product_list") {

    $sql = "SELECT DISTINCT product_name 
            FROM dbo.product_detail_description 
            WHERE product_name IS NOT NULL 
            AND product_name != ''
            ORDER BY product_name ASC";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        response(false, "Product fetch failed", sqlsrv_errors());
    }

    $products = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $products[] = $row['product_name'];
    }

    response(true, "Product list fetched", $products);
}









/* ================= LOG ================= */

if($type=="log_list"){

$sql="
SELECT *
FROM inventory_log
ORDER BY id DESC
";

$stmt=
sqlsrv_query(
$conn,
$sql
);

$data=[];

while(
$row=
sqlsrv_fetch_array(
$stmt,
SQLSRV_FETCH_ASSOC
)
){
$data[]=$row;
}

response(
true,
"Log fetched",
$data
);

}


response(
false,
"Invalid type"
);

?>