<?php
// backend/import_overrides.php
// IMPORTA productos desde overrides.json a la BD "elcatre"

require __DIR__ . '/db.php';

// 1) Leer archivo JSON
$path = __DIR__ . '/overrides.json';
if (!file_exists($path)) {
    die("No encuentro overrides.json en backend/");
}

$json = file_get_contents($path);
$data = json_decode($json, true);

if (!$data || !isset($data['overrides'])) {
    die("Formato de JSON inválido (no hay clave 'overrides').");
}

$overrides = $data['overrides'];

// 2) Helper: obtener/crear categoria_id a partir del slug (comedor, living, etc.)
function getCategoriaId(PDO $pdo, string $slug): int {
    // buscar si ya existe
    $stmt = $pdo->prepare("SELECT id FROM categorias WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];

    // si no existe, la creo
    $nombre = ucfirst($slug);
    $ins = $pdo->prepare("INSERT INTO categorias (nombre, slug) VALUES (?, ?)");
    $ins->execute([$nombre, $slug]);
    return (int)$pdo->lastInsertId();
}

$insert = $pdo->prepare("
    INSERT INTO productos (id, nombre, descripcion, precio, stock, imagen, categoria_id, activo)
    VALUES (:id, :nombre, :descripcion, :precio, :stock, :imagen, :categoria_id, 1)
    ON DUPLICATE KEY UPDATE
      nombre = VALUES(nombre),
      descripcion = VALUES(descripcion),
      precio = VALUES(precio),
      stock = VALUES(stock),
      imagen = VALUES(imagen),
      categoria_id = VALUES(categoria_id),
      activo = VALUES(activo)
");

$cont = 0;

// 3) Recorrer todos los productos del JSON
foreach ($overrides as $p) {
    // id (puede ser negativo, no pasa nada)
    $id = (int)($p['id'] ?? 0);
    if (!$id) continue; // si no tiene id, lo salto

    $nombre = trim($p['nombre'] ?? '');
    if ($nombre === '') continue;

    $slugCat = trim($p['categoria'] ?? '');
    if ($slugCat === '') $slugCat = 'otros';

    $categoria_id = getCategoriaId($pdo, $slugCat);

    $precio = $p['precio'] ?? 0;
    // por si vienen strings tipo "18.5"
    $precio = is_numeric($precio) ? (float)$precio : 0;

    $descripcion = $p['descripcion'] ?? '';
    $imagen      = $p['imagen'] ?? '';
    $stockBool   = $p['stock'] ?? true;
    $stock       = $stockBool ? 1 : 0;

    $insert->execute([
        ':id'           => $id,
        ':nombre'       => $nombre,
        ':descripcion'  => $descripcion,
        ':precio'       => $precio,
        ':stock'        => $stock,
        ':imagen'       => $imagen,
        ':categoria_id' => $categoria_id
    ]);

    $cont++;
}

echo "Listo. Productos importados/actualizados: {$cont}";
