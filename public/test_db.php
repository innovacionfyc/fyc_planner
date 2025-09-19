<?php
require_once __DIR__ . '/../config/db.php';

$res = $conn->query("SELECT DATABASE() AS db, NOW() AS ahora");
$row = $res ? $res->fetch_assoc() : null;

if ($row) {
    echo "✅ Conectado a: " . htmlspecialchars($row['db']) . "<br>";
    echo "🕒 Servidor MySQL hora: " . htmlspecialchars($row['ahora']);
} else {
    echo "❌ No pude ejecutar la consulta. Error: " . $conn->error;
}
