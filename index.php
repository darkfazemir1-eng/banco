<?php
session_start();
require_once "config.php";

// Esta es una demostracion escolar: todas las operaciones son ficticias.
// La cuenta de demostracion se crea con el archivo database.sql.
$usuario_id = 1;
$mensaje = "";
$tipo_mensaje = "exito";

function obtenerSaldo($conexion, $usuario_id) {
    $stmt = $conexion->prepare("SELECT saldo FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $usuario_id);
    $stmt->execute();
    $resultado = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $resultado ? (float)$resultado["saldo"] : 0;
}

function registrarActividad($conexion, $usuario_id, $tipo, $descripcion, $monto) {
    $stmt = $conexion->prepare(
        "INSERT INTO movimientos (usuario_id, tipo, descripcion, monto) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("issd", $usuario_id, $tipo, $descripcion, $monto);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST["accion"] ?? "";
    $monto = isset($_POST["monto"]) ? (float)$_POST["monto"] : 0;

    if ($accion === "ingresar" || $accion === "retirar" || $accion === "transferir" || $accion === "recargar") {
        if ($monto <= 0 || $monto > 100000) {
            $mensaje = "Ingresa una cantidad válida.";
            $tipo_mensaje = "error";
        } else {
            $conexion->begin_transaction();
            try {
                $saldo = obtenerSaldo($conexion, $usuario_id);

                if ($accion === "ingresar") {
                    $stmt = $conexion->prepare("UPDATE usuarios SET saldo = saldo + ? WHERE id = ?");
                    $stmt->bind_param("di", $monto, $usuario_id);
                    $stmt->execute();
                    $stmt->close();
                    registrarActividad($conexion, $usuario_id, "Ingreso", "Ingreso de dinero a la cuenta", $monto);
                    $mensaje = "El ingreso de demostración se realizó correctamente.";
                } elseif ($accion === "retirar") {
                    if ($monto > $saldo) throw new Exception("No tienes saldo suficiente.");
                    $stmt = $conexion->prepare("UPDATE usuarios SET saldo = saldo - ? WHERE id = ?");
                    $stmt->bind_param("di", $monto, $usuario_id);
                    $stmt->execute();
                    $stmt->close();
                    registrarActividad($conexion, $usuario_id, "Retiro", "Retiro de dinero (simulado)", -$monto);
                    $mensaje = "El retiro de demostración se realizó correctamente.";
                } elseif ($accion === "transferir") {
                    $clabe = trim($_POST["clabe"] ?? "");
                    if (!preg_match('/^[0-9]{18}$/', $clabe)) throw new Exception("La CLABE debe contener 18 números.");
                    if ($monto > $saldo) throw new Exception("No tienes saldo suficiente.");
                    $stmt = $conexion->prepare("UPDATE usuarios SET saldo = saldo - ? WHERE id = ?");
                    $stmt->bind_param("di", $monto, $usuario_id);
                    $stmt->execute();
                    $stmt->close();
                    registrarActividad($conexion, $usuario_id, "Transferencia", "Transferencia simulada a CLABE " . $clabe, -$monto);
                    $mensaje = "La transferencia simulada quedó registrada.";
                } elseif ($accion === "recargar") {
                    $telefono = trim($_POST["telefono"] ?? "");
                    if (!preg_match('/^[0-9]{10}$/', $telefono)) throw new Exception("Escribe un teléfono de 10 dígitos.");
                    if ($monto > $saldo) throw new Exception("No tienes saldo suficiente.");
                    $stmt = $conexion->prepare("UPDATE usuarios SET saldo = saldo - ? WHERE id = ?");
                    $stmt->bind_param("di", $monto, $usuario_id);
                    $stmt->execute();
                    $stmt->close();
                    registrarActividad($conexion, $usuario_id, "Recarga", "Recarga simulada al número " . $telefono, -$monto);
                    $mensaje = "La recarga de demostración quedó registrada.";
                }
                $conexion->commit();
            } catch (Exception $e) {
                $conexion->rollback();
                $mensaje = $e->getMessage();
                $tipo_mensaje = "error";
            }
        }
    }
}

$consulta = $conexion->prepare("SELECT nombre, saldo, clabe, tarjeta_demo, nip_demo FROM usuarios WHERE id = ?");
$consulta->bind_param("i", $usuario_id);
$consulta->execute();
$usuario = $consulta->get_result()->fetch_assoc();
$consulta->close();

if (!$usuario) die("No existe la cuenta de demostración. Importa database.sql primero.");

$historial = $conexion->prepare(
    "SELECT tipo, descripcion, monto, fecha FROM movimientos WHERE usuario_id = ? ORDER BY fecha DESC LIMIT 20"
);
$historial->bind_param("i", $usuario_id);
$historial->execute();
$actividades = $historial->get_result();
$historial->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mercadito Campitos | Mi cuenta</title>
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
<header class="barra">
    <a class="marca" href="index.php"><span class="marca-icono">EP</span> Mercado <b>Campitos</b></a>
    <span class="usuario">Hola, persona exitosa.</span>
</header>

<main class="contenedor">
    <section class="bienvenida">
        <div>
            <p class="sobre-titulo">TU DINERO, EN UN SOLO LUGAR</p>
            <h1>Hola, Fabian Camacho Granillo</h1>
            <p>Administra tu cuenta de manera sencilla.</p>
        </div>
        <div class="saldo">
            <span>Dinero disponible</span>
            <strong>$<?= number_format((float)$usuario["saldo"], 2) ?> <small>MXN</small></strong>
            <span class="etiqueta">Hola, me gusta el dinero.</span>
        </div>
    </section>

    <?php if ($mensaje !== ""): ?>
        <div class="alerta <?= $tipo_mensaje === "error" ? "alerta-error" : "" ?>">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>

    <section class="seccion">
        <div class="encabezado-seccion">
            <h2>¿Qué quieres hacer?</h2>
            <span>Gasta tu dinero</span>
        </div>
        <div class="acciones">
            <button class="accion" data-modal="modal-ingresar"><span class="icono">＋</span>Ingresar dinero</button>
            <button class="accion" data-modal="modal-retirar"><span class="icono">↗</span>Retirar</button>
            <button class="accion" data-modal="modal-tarjeta"><span class="icono">▰</span>Ver tarjeta</button>
            <button class="accion" data-modal="modal-clabe"><span class="icono">⌗</span>Ver CLABE</button>
            <button class="accion" data-modal="modal-transferir"><span class="icono">⇄</span>Transferencias</button>
            <button class="accion" data-modal="modal-nip"><span class="icono">•••</span>Buscar tu NIP</button>
            <a class="accion" href="#actividades"><span class="icono">▤</span>Ver actividades</a>
            <button class="accion" data-modal="modal-recargar"><span class="icono">▯</span>Recargar teléfono</button>
        </div>
    </section>

    <section class="seccion resumen">
        <div class="encabezado-seccion"><h2>Tu cuenta</h2><span>Información de demostración</span></div>
        <div class="datos-cuenta">
            <div><span>Nombre</span><strong><?= htmlspecialchars($usuario["nombre"]) ?></strong></div>
            <div><span>CLABE</span><strong><?= htmlspecialchars($usuario["clabe"]) ?></strong></div>
            <div><span>Tarjeta</span><strong>•••• •••• •••• <?= substr($usuario["tarjeta_demo"], -4) ?></strong></div>
        </div>
    </section>

    <section class="seccion" id="actividades">
        <div class="encabezado-seccion"><h2>Últimos movimientos</h2><span>Hasta 20 registros</span></div>
        <div class="tabla-contenedor">
            <table>
                <thead><tr><th>Actividad</th><th>Descripción</th><th>Fecha</th><th>Monto</th></tr></thead>
                <tbody>
                <?php if ($actividades->num_rows === 0): ?>
                    <tr><td colspan="4" class="vacio">Todavía no tienes movimientos.</td></tr>
                <?php else: while ($mov = $actividades->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($mov["tipo"]) ?></td>
                        <td><?= htmlspecialchars($mov["descripcion"]) ?></td>
                        <td><?= date("d/m/Y H:i", strtotime($mov["fecha"])) ?></td>
                        <td class="<?= $mov["monto"] >= 0 ? "positivo" : "negativo" ?>">
                            <?= $mov["monto"] >= 0 ? "+" : "−" ?>$<?= number_format(abs((float)$mov["monto"]), 2) ?>
                        </td>
                    </tr>
                <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <footer> Chivas el mejor equipo de la liga mx.
    </footer>
</main>

<!-- Ventanas de operaciones -->
<div class="modal" id="modal-ingresar"><div class="modal-caja"><button class="cerrar">×</button><h2>Ingresar dinero</h2><p>Agrega saldo ficticio a tu cuenta.</p><form method="POST"><input type="hidden" name="accion" value="ingresar"><label>Cantidad en pesos</label><input type="number" name="monto" min="1" max="100000" step="0.01" required><button class="primario">Confirmar ingreso</button></form></div></div>

<div class="modal" id="modal-retirar"><div class="modal-caja"><button class="cerrar">×</button><h2>Retirar dinero</h2><p>Se descontará del saldo de demostración.</p><form method="POST"><input type="hidden" name="accion" value="retirar"><label>Cantidad en pesos</label><input type="number" name="monto" min="1" step="0.01" required><button class="primario">Confirmar retiro</button></form></div></div>

<div class="modal" id="modal-transferir"><div class="modal-caja"><button class="cerrar">×</button><h2>Realizar transferencia</h2><p>Transferencia interna simulada; no envía dinero real.</p><form method="POST"><input type="hidden" name="accion" value="transferir"><label>CLABE de destino (18 dígitos)</label><input type="text" name="clabe" maxlength="18" pattern="[0-9]{18}" required><label>Cantidad en pesos</label><input type="number" name="monto" min="1" step="0.01" required><button class="primario">Transferir</button></form></div></div>

<div class="modal" id="modal-recargar"><div class="modal-caja"><button class="cerrar">×</button><h2>Recargar teléfono</h2><p>Registra una recarga ficticia.</p><form method="POST"><input type="hidden" name="accion" value="recargar"><label>Número telefónico</label><input type="tel" name="telefono" maxlength="10" pattern="[0-9]{10}" placeholder="10 dígitos" required><label>Importe</label><select name="monto"><option value="50">$50</option><option value="100">$100</option><option value="150">$150</option><option value="200">$200</option></select><button class="primario">Confirmar recarga</button></form></div></div>

<div class="modal" id="modal-tarjeta"><div class="modal-caja"><button class="cerrar">×</button><h2>Mi tarjeta digital</h2><div class="tarjeta-demo"><span>NARANJA WALLET</span><strong>•••• •••• •••• <?= substr($usuario["tarjeta_demo"], -4) ?></strong><div><span><?= htmlspecialchars($usuario["nombre"]) ?></span><span>DEMO</span></div></div><p class="nota">Tarjeta ficticia para presentación escolar. No se puede utilizar para compras.</p></div></div>

<div class="modal" id="modal-clabe"><div class="modal-caja"><button class="cerrar">×</button><h2>Mi CLABE de demostración</h2><p class="dato-grande"><?= htmlspecialchars($usuario["clabe"]) ?></p><button class="primario copiar" data-copiar="<?= htmlspecialchars($usuario["clabe"]) ?>">Copiar CLABE</button><p class="nota">No corresponde a una cuenta bancaria real.</p></div></div>

<div class="modal" id="modal-nip"><div class="modal-caja"><button class="cerrar">×</button><h2>NIP de demostración</h2><p class="dato-grande"><?= htmlspecialchars($usuario["nip_demo"]) ?></p><p class="nota">Este NIP es ficticio. Nunca compartas el NIP de una tarjeta real.</p></div></div>

<script src="script.js"></script>
</body>
</html>
