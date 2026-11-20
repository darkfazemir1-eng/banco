<?php
// Conexion con MySQL. Cambia estos datos si tu servidor usa otros.
$host = "localhost";
$usuario = "root";
$contrasena = "";
$base_datos = "naranja_wallet";

$conexion = new mysqli($host, $usuario, $contrasena, $base_datos);
if ($conexion->connect_error) {
    die("No se pudo conectar con MySQL: " . $conexion->connect_error);
}
$conexion->set_charset("utf8mb4");
