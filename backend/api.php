
<?php
// backend/api.php — El Catre S.R.L.
session_start();
require __DIR__ . '/db.php';

/* ===== CONFIG GOOGLE ===== */
$GOOGLE_CLIENT_ID = '458554697515-ltrv6o27ba9th2s4rusip0lu5m7qt4r4.apps.googleusercontent.com';

/* ---------- Helpers ---------- */
function json_out($data, $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}
function require_fields($arr, $fields){
  foreach($fields as $f){
    if(!isset($arr[$f]) || trim($arr[$f])===''){
      json_out(['error'=>"Falta el campo: $f"], 400);
    }
  }
}
function current_user(){
  return $_SESSION['user'] ?? null;
}

/* =================== CORS (dev y prod) =================== */
$allowed_origins = [
  'http://localhost',
  'http://127.0.0.1',
  'http://localhost:5500',
  'http://127.0.0.1:5500',
  // 'https://elcatre.com.uy',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $allowed_origins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Vary: Origin');
} else if (!$origin) {
  // same-origin
} else {
  header("Access-Control-Allow-Origin: *");
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* ===================== ROUTER ===================== */
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action){

/************** AUTENTICACIÓN BÁSICA **************/
case 'auth_register': {
  try {
    $nombre = $_POST['nombre'] ?? '';
    $email  = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

    // Validar reCAPTCHA
    $recaptchaSecret = '6LfN3u8rAAAAAD_Ga7ObHp7hCE5dOV1fEdvQ4l-Q';
    $verify = file_get_contents(
      "https://www.google.com/recaptcha/api/siteverify?secret={$recaptchaSecret}&response={$recaptchaResponse}"
    );
    $captcha = json_decode($verify, true);
    if (!($captcha['success'] ?? false)) {
      json_out(['error'=>'Captcha falló','google'=>$captcha],400);
    }

    // Validaciones simples
    if (!$nombre || !$email || !$password) json_out(['error'=>'Datos incompletos'],400);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
      json_out(['error'=>'Email inválido'],400);

    if (strlen($password) < 6)
      json_out(['error'=>'Contraseña demasiado corta'],400);

    // Insertar usuario
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, pass_hash, rol) VALUES (?, ?, ?, 'cliente')");
    $stmt->execute([$nombre, $email, $hash]);

    json_out(['ok'=>true]);
  } catch (Throwable $e) {
    json_out(['error'=>'Error interno','detalle'=>$e->getMessage()],500);
  }
  break;
}


case 'auth_login': {
  $login = trim($_POST['email'] ?? $_POST['user'] ?? '');
  $pass  = $_POST['password'] ?? '';

  if ($login === '' || $pass === '') {
    json_out(['error'=>'Faltan credenciales'], 400);
  }

  if ($login === 'Admin_ElCatre' && $pass === '123456789') {
    $_SESSION['user'] = [
      'id'     => 0,
      'nombre' => 'Admin_ElCatre',
      'email'  => 'admin@local',
      'rol'    => 'admin'
    ];
    json_out(['ok'=>true,'user'=>$_SESSION['user']]);
  }

  $q = $pdo->prepare("
    SELECT id,nombre,email,rol,pass_hash
    FROM usuarios
    WHERE email = :login OR nombre = :login
    LIMIT 1
  ");
  $q->execute([':login'=>$login]);
  $u = $q->fetch();

  if(!$u || !password_verify($pass,$u['pass_hash'])) {
    json_out(['error'=>'Credenciales inválidas'], 401);
  }

  $_SESSION['user'] = ['id'=>$u['id'],'nombre'=>$u['nombre'],'email'=>$u['email'],'rol'=>$u['rol']];
  json_out(['ok'=>true,'user'=>$_SESSION['user']]);
}

case 'auth_logout': {
  $_SESSION=[];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p["path"],$p["domain"],$p["secure"],$p["httponly"]);
  }
  session_destroy();
  json_out(['ok'=>true]);
}

case 'auth_me': {
  $u=current_user();
  json_out($u?['authenticated'=>true,'user'=>$u]:['authenticated'=>false]);
}

case 'admin_save_product':
    $input = json_decode(file_get_contents("php://input"), true);

    $id = $input['id'] ?? null;
    $nombre = $input['nombre'] ?? '';
    $categoria_id = $input['categoria_id'] ?? null;
    $precio = $input['precio'] ?? 0;
    $imagen = $input['imagen'] ?? null;
    $descripcion = $input['descripcion'] ?? '';
    $stock = $input['stock'] ?? 0;

    if (!$nombre || !$categoria_id) {
        echo json_encode(['error' => 'Faltan campos obligatorios']);
        exit;
    }

    if ($id) {
        // EDITAR producto existente
        $sql = "UPDATE productos 
                SET nombre=?, categoria_id=?, precio=?, imagen=?, descripcion=?, stock=? 
                WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nombre, $categoria_id, $precio, $imagen, $descripcion, $stock, $id]);
    } else {
        // AGREGAR nuevo producto
        $sql = "INSERT INTO productos (nombre, categoria_id, precio, imagen, descripcion, stock) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nombre, $categoria_id, $precio, $imagen, $descripcion, $stock]);
        $id = $pdo->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);
    break;


case 'admin_delete_product': {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID inválido']);
    exit;
  }
  $stmt = $pdo->prepare("DELETE FROM productos WHERE id = ?");
  $stmt->execute([$id]);
  echo json_encode(['ok' => true]);
  break;
}

/************** (UTILIDAD) SEMBRAR ADMIN **************/
case 'seed_admin': {
  $email  = 'admin@elcatre.local';
  $nombre = 'Admin_ElCatre';
  $pass   = '123456789';
  $rol    = 'admin';

  $q = $pdo->prepare("SELECT id FROM usuarios WHERE email=:e OR nombre=:n LIMIT 1");
  $q->execute([':e'=>$email, ':n'=>$nombre]);
  if($q->fetch()){
    json_out(['ok'=>true,'msg'=>'Admin ya existía (nada que hacer).']);
  }

  $hash = password_hash($pass, PASSWORD_BCRYPT);
  $pdo->prepare("INSERT INTO usuarios (nombre,email,pass_hash,rol) VALUES (:n,:e,:h,:r)")
      ->execute([':n'=>$nombre, ':e'=>$email, ':h'=>$hash, ':r'=>$rol]);

  json_out(['ok'=>true,'msg'=>'Admin creado','email'=>$email,'nombre'=>$nombre]);
}

/************** LOGIN CON GOOGLE **************/
case 'auth_google': {
  require_fields($_POST, ['id_token']);
  $idToken = $_POST['id_token'];

  $info = @file_get_contents("https://oauth2.googleapis.com/tokeninfo?id_token=".urlencode($idToken));
  if(!$info) json_out(['error'=>'No se pudo verificar el token'], 401);
  $payload = json_decode($info, true);

  if(empty($payload['aud']) || $payload['aud'] !== $GOOGLE_CLIENT_ID){
    json_out(['error'=>'Audiencia inválida'], 401);
  }
  if(($payload['email_verified'] ?? 'false') !== 'true'){
    json_out(['error'=>'Email no verificado en Google'], 401);
  }

  $email = $payload['email'];
  $nombre = $payload['name'] ?? explode('@',$email)[0];

  $q = $pdo->prepare("SELECT id,nombre,email,rol FROM usuarios WHERE email=:e LIMIT 1");
  $q->execute([':e'=>$email]); $u=$q->fetch();
  if(!$u){
    $pdo->prepare("INSERT INTO usuarios (nombre,email,rol) VALUES (:n,:e,'cliente')")
        ->execute([':n'=>$nombre, ':e'=>$email]);
    $id = (int)$pdo->lastInsertId();
    $u = ['id'=>$id,'nombre'=>$nombre,'email'=>$email,'rol'=>'cliente'];
  }
  $_SESSION['user']=$u;
  json_out(['ok'=>true,'user'=>$u]);
}

/************** CATÁLOGO **************/
case 'list_categorias': {
  $rows = $pdo->query("SELECT id,nombre,slug FROM categorias ORDER BY nombre")->fetchAll();
  json_out($rows);
}
case 'list_productos': {
  $p=[];
  $sql="SELECT p.id,p.nombre,p.descripcion,p.precio,p.stock,p.imagen,c.slug AS categoria
        FROM productos p JOIN categorias c ON c.id=p.categoria_id WHERE p.activo=1";
  if(!empty($_GET['categoria'])){ $sql.=" AND c.slug=:cat"; $p[':cat']=$_GET['categoria']; }
  if(!empty($_GET['q'])){ $sql.=" AND p.nombre LIKE :q"; $p[':q']="%".$_GET['q']."%"; }
  $sql.=" ORDER BY p.nombre";
  $stm=$pdo->prepare($sql); $stm->execute($p);
  json_out($stm->fetchAll());
}

/************** CARRITO **************/
case 'carrito_get': {
  $cart = $_SESSION['cart'] ?? [];
  $total=0; foreach($cart as $it){ $total += $it['precio']*$it['cantidad']; }
  json_out(['items'=>$cart,'total'=>$total]);
}
case 'carrito_add': {
  $id=(int)($_POST['producto_id']??0);
  $qty=max(1,(int)($_POST['cantidad']??1));
  $s=$pdo->prepare("SELECT id,nombre,precio FROM productos WHERE id=:id AND activo=1");
  $s->execute([':id'=>$id]); $p=$s->fetch();
  if(!$p) json_out(['error'=>'Producto no encontrado'],404);
  $_SESSION['cart']=$_SESSION['cart']??[];
  if(!isset($_SESSION['cart'][$id]))
    $_SESSION['cart'][$id]=['id'=>$p['id'],'nombre'=>$p['nombre'],'precio'=>(float)$p['precio'],'cantidad'=>0];
  $_SESSION['cart'][$id]['cantidad']+=$qty;
  json_out(['ok'=>true,'item'=>$_SESSION['cart'][$id]]);
}
case 'carrito_remove': {
  $id=(int)($_POST['producto_id']??0);
  if(isset($_SESSION['cart'][$id])) unset($_SESSION['cart'][$id]);
  json_out(['ok'=>true]);
}
case 'carrito_clear' : {
  $_SESSION['cart']=[];
  json_out(['ok'=>true]);
}


case 'carrito_sync': {
  $data = json_decode(file_get_contents('php://input'), true);
  if(!$data || !is_array($data)) json_out(['error'=>'Formato inválido'],400);
  $_SESSION['cart'] = [];

  foreach($data as $item){
    $id = (int)($item['id'] ?? 0);
    $nombre = trim($item['nombre'] ?? '');
    $precio = (float)($item['precio'] ?? 0);
    $cantidad = max(1, (int)($item['cantidad'] ?? 1));
    if($nombre && $precio){
      $id = $id ?: crc32($nombre);
      $_SESSION['cart'][$id] = [
        'id' => $id,
        'nombre' => $nombre,
        'precio' => $precio,
        'cantidad' => $cantidad
      ];
    }
  }

  json_out(['ok' => true, 'items' => $_SESSION['cart']]);
}


/************** PEDIDOS **************/
case 'pedido_crear': {
  $nombre = trim($_POST['nombre'] ?? '');
  $email  = trim($_POST['email'] ?? '');
  $tel    = trim($_POST['telefono'] ?? '');
  $dir    = trim($_POST['direccion'] ?? '');

  // Aceptar tanto $_SESSION['cart'] como $_SESSION['carrito']
  $cart = $_SESSION['cart'] ?? $_SESSION['carrito'] ?? [];

  if (!$nombre || !$email || empty($cart)) {
    json_out(['error' => 'Datos insuficientes o carrito vacío'], 400);
  }

  $pdo->beginTransaction();
  $pdo->exec("INSERT INTO carritos (estado) VALUES ('cerrado')");
  $carrito_id = (int)$pdo->lastInsertId();

  $total = 0;
  $ins = $pdo->prepare("
    INSERT INTO carrito_items (carrito_id, producto_id, cantidad, precio_unit)
    VALUES (:c, :p, :q, :u)
  ");

  foreach ($cart as $it) {
    $total += $it['precio'] * $it['cantidad'];

    // Verificar si el producto existe
    $pid = (int)$it['id'];
    $check = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE id = :id");
    $check->execute([':id' => $pid]);
    $exists = $check->fetchColumn() > 0;

    // Si no existe, poner NULL
    $ins->execute([
      ':c' => $carrito_id,
      ':p' => $exists ? $pid : null,
      ':q' => $it['cantidad'],
      ':u' => $it['precio']
    ]);
  }

  $uid = current_user()['id'] ?? null;
  $pdo->prepare("
    INSERT INTO pedidos (carrito_id, usuario_id, nombre, email, telefono, direccion, total, estado)
    VALUES (:c, :u, :n, :e, :t, :d, :tot, 'recibido')
  ")->execute([
    ':c' => $carrito_id,
    ':u' => $uid,
    ':n' => $nombre,
    ':e' => $email,
    ':t' => $tel,
    ':d' => $dir,
    ':tot' => $total
  ]);

  $pedido_id = (int)$pdo->lastInsertId();
  $pdo->commit();
  $_SESSION['cart'] = [];

  json_out(['ok' => true, 'pedido_id' => $pedido_id, 'total' => $total]);
}


/************** RESEÑAS **************/
case 'addReview': {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error'=>'Método no permitido'],405);
  $data = json_decode(file_get_contents('php://input'), true);
  $nombre = $data['nombre'] ?? '';
  $email = $data['email'] ?? '';
  $estrellas = intval($data['estrellas'] ?? 0);
  $comentario = $data['comentario'] ?? '';

  if (!$nombre || !$comentario) json_out(['error' => 'Faltan datos'],400);

  $stmt = $pdo->prepare("INSERT INTO resenas (nombre, email, estrellas, comentario) VALUES (?, ?, ?, ?)");
  $stmt->execute([$nombre, $email, $estrellas, $comentario]);
  json_out(['ok' => true]);
}

case 'getReviews': {
  $rows = $pdo->query("SELECT * FROM resenas ORDER BY fecha DESC")->fetchAll(PDO::FETCH_ASSOC);
  json_out($rows);
}

case 'deleteReview': {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error'=>'Método no permitido'],405);
  if (($_SESSION['user']['nombre'] ?? '') !== 'Admin_ElCatre') json_out(['error' => 'No autorizado']);
  $data = json_decode(file_get_contents('php://input'), true);
  $id = intval($data['id'] ?? 0);
  $stmt = $pdo->prepare("DELETE FROM resenas WHERE id = ?");
  $stmt->execute([$id]);
  json_out(['ok' => true]);
}

/************** DEFAULT **************/
default:
  json_out(['error'=>'Acción no soportada'],400);
}
