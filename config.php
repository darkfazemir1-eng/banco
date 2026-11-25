
<?php
// Lee variables de entorno de Railway y usa valores locales como respaldo
$host = getenv("MYSQLHOST") ?: (getenv("DB_HOST") ?: "localhost");
$usuario = getenv("MYSQLUSER") ?: (getenv("DB_USERNAME") ?: "root");
$contrasena = getenv("MYSQLPASSWORD") ?: (getenv("DB_PASSWORD") ?: "");
$base_datos = getenv("MYSQLDATABASE") ?: (getenv("DB_DATABASE") ?: "naranja_wallet");
$puerto = (int)(getenv("MYSQLPORT") ?: (getenv("DB_PORT") ?: 3306));

mysqli_report(MYSQLI_REPORT_OFF);

$conexion = new mysqli($host, $usuario, $contrasena, $base_datos, $puerto);

if ($conexion->connect_errno) {
    error_log("Error de conexión MySQL ({$conexion->connect_errno}): {$conexion->connect_error}");
    http_response_code(500);
    die("No se pudo conectar con la base de datos.");
}

$conexion->set_charset("utf8mb4");
