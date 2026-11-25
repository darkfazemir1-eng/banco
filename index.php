<?php
session_start();
require_once "config.php";
// --- BLOQUE TEMPORAL PARA REPARAR LA CONTRASEÑA DEMO ---
// Entra con: demo@campitos.edu.mx / password123 y luego borra este bloque.
$correoDemo = "demo@campitos.edu.mx";
$passwordDemo = "password123";
$hashReal = password_hash($passwordDemo, PASSWORD_DEFAULT);

$stmtFix = $conexion->prepare(
    "UPDATE usuarios SET password_hash = ? WHERE correo = ? AND id = 1"
);
if ($stmtFix) {
    $stmtFix->bind_param("ss", $hashReal, $correoDemo);
    $stmtFix->execute();
    $stmtFix->close();
}
// --- FIN DEL BLOQUE TEMPORAL ---
// Cerrar sesión
if (isset($_GET["logout"])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: index.php");
    exit;
}

$mensaje = "";
$tipo_mensaje = "exito";
$error_login = "";

// Procesar inicio de sesión
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? "") === "login") {
    $correo = trim($_POST["correo"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($correo === "" || $password === "") {
        $error_login = "Ingresa tu correo y contraseña.";
    } else {
        $stmt = $conexion->prepare("SELECT id, nombre, password_hash FROM usuarios WHERE correo = ? LIMIT 1");
        $stmt->bind_param("s", $correo);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($userRow && password_verify($password, $userRow["password_hash"])) {
            session_regenerate_id(true);
            $_SESSION["usuario_id"] = (int)$userRow["id"];
            header("Location: index.php");
            exit;
        } else {
            $error_login = "Correo o contraseña incorrectos.";
        }
    }
}

// Si no está autenticado, mostrar pantalla de inicio de sesión
if (empty($_SESSION["usuario_id"])):
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mercadito Campitos | Iniciar sesión</title>
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
<header class="barra">
    <a class="marca" href="index.php"><span class="marca-icono">EP</span> Mercado <b>Campitos</b></a>
    <span class="usuario">Acceso de demostración</span>
</header>

<main class="contenedor">
    <section class="seccion" style="max-width: 440px; margin: 2rem auto;">
        <div class="encabezado-seccion">
            <h2>Iniciar sesión</h2>
            <span>Cuenta escolar</span>
        </div>

        <?php if ($error_login !== ""): ?>
            <div class="alerta alerta-error"><?= htmlspecialchars($error_login) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="accion" value="login">
            <label>Correo electrónico</label>
            <input type="email" name="correo" value="<?= htmlspecialchars($_POST["correo"] ?? "demo@campitos.edu.mx") ?>" required>

            <label>Contraseña</label>
            <input type="password" name="password" placeholder="••••••••••" required>

            <button class="primario" style="margin-top: 1rem;">Entrar</button>
        </form>
        <p class="nota" style="margin-top: 1rem;">Demo: <b>demo@campitos.edu.mx</b> / <b>password123</b></p>
    </section>
</main>
</body>
</html>
<?php
exit;
endif;

$usuario_id = (int)$_SESSION["usuario_id"];

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

function guardarContactoFrecuente($conexion, $usuario_id, $clabe, $alias) {
    $aliasLimpio = trim($alias);
    if ($aliasLimpio === "") {
        $aliasLimpio = "CLABE *" . substr($clabe, -4);
    }
    $stmt = $conexion->prepare(
        "INSERT INTO contactos_frecuentes (usuario_id, alias, clabe, veces_enviado)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             alias = IF(TRIM(?) <> '', TRIM(?), alias),
             veces_enviado = veces_enviado + 1"
    );
    $stmt->bind_param("issss", $usuario_id, $aliasLimpio, $clabe, $alias, $alias);
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
                    $contactoSeleccionado = trim($_POST["contacto_frecuente"] ?? "");
                    $clabeInput = trim($_POST["clabe"] ?? "");
                    $clabe = $clabeInput !== "" ? $clabeInput : $contactoSeleccionado;
                    $alias = trim($_POST["alias_contacto"] ?? "");

                    if (!preg_match('/^[0-9]{18}$/', $clabe)) throw new Exception("La CLABE debe contener 18 números.");
                    if ($monto > $saldo) throw new Exception("No tienes saldo suficiente.");

                    $stmt = $conexion->prepare("UPDATE usuarios SET saldo = saldo - ? WHERE id = ?");
                    $stmt->bind_param("di", $monto, $usuario_id);
                    $stmt->execute();
                    $stmt->close();

                    guardarContactoFrecuente($conexion, $usuario_id, $clabe, $alias);
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

if (!$usuario) {
    header("Location: index.php?logout=1");
    exit;
}

$contactosStmt = $conexion->prepare(
    "SELECT id, alias, clabe, veces_enviado FROM contactos_frecuentes WHERE usuario_id = ? ORDER BY veces_enviado DESC, alias ASC"
);
$contactosStmt->bind_param("i", $usuario_id);
$contactosStmt->execute();
$contactos = $contactosStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$contactosStmt->close();

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
    <span class="usuario">
        Hola, <?= htmlspecialchars($usuario["nombre"]) ?> · <a href="index.php?logout=1">Cerrar sesión</a>
    </span>
</header>

<main class="contenedor">
    <section class="bienvenida">
        <div>
            <p class="sobre-titulo">TU DINERO, EN UN SOLO LUGAR</p>
            <h1>Hola, <?= htmlspecialchars($usuario["nombre"]) ?></h1>
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

<div class="modal" id="modal-transferir">
    <div class="modal-caja">
        <button class="cerrar">×</button>
        <h2>Realizar transferencia</h2>
        <p>Transferencia interna simulada; no envía dinero real.</p>
        <form method="POST">
            <input type="hidden" name="accion" value="transferir">

            <?php if (!empty($contactos)): ?>
                <label>Personas a las que más envías</label>
                <select name="contacto_frecuente" id="select-contacto-frecuente">
                    <option value="">-- Seleccionar contacto frecuente --</option>
                    <?php foreach ($contactos as $c): ?>
                        <option value="<?= htmlspecialchars($c["clabe"]) ?>">
                            <?= htmlspecialchars($c["alias"]) ?> (*<?= substr($c["clabe"], -4) ?> · <?= (int)$c["veces_enviado"] ?> envíos)
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <label>CLABE de destino (18 dígitos)</label>
            <input type="text" name="clabe" id="input-clabe-destino" maxlength="18" pattern="[0-9]{18}" required>

            <label>Nombre o alias para guardar en frecuentes (opcional)</label>
            <input type="text" name="alias_contacto" maxlength="80" placeholder="Ej. Mamá, Renta, Santiago">

            <label>Cantidad en pesos</label>
            <input type="number" name="monto" min="1" step="0.01" required>

            <button class="primario">Transferir</button>
        </form>
    </div>
</div>

<div class="modal" id="modal-recargar"><div class="modal-caja"><button class="cerrar">×</button><h2>Recargar teléfono</h2><p>Registra una recarga ficticia.</p><form method="POST"><input type="hidden" name="accion" value="recargar"><label>Número telefónico</label><input type="tel" name="telefono" maxlength="10" pattern="[0-9]{10}" placeholder="10 dígitos" required><label>Importe</label><select name="monto"><option value="50">$50</option><option value="100">$100</option><option value="150">$150</option><option value="200">$200</option></select><button class="primario">Confirmar recarga</button></form></div></div>

<div class="modal" id="modal-tarjeta"><div class="modal-caja"><button class="cerrar">×</button><h2>Mi tarjeta digital</h2><div class="tarjeta-demo"><span>NARANJA WALLET</span><strong>•••• •••• •••• <?= substr($usuario["tarjeta_demo"], -4) ?></strong><div><span><?= htmlspecialchars($usuario["nombre"]) ?></span><span>DEMO</span></div></div><p class="nota">Tarjeta ficticia para presentación escolar. No se puede utilizar para compras.</p></div></div>

<div class="modal" id="modal-clabe"><div class="modal-caja"><button class="cerrar">×</button><h2>Mi CLABE de demostración</h2><p class="dato-grande"><?= htmlspecialchars($usuario["clabe"]) ?></p><button class="primario copiar" data-copiar="<?= htmlspecialchars($usuario["clabe"]) ?>">Copiar CLABE</button><p class="nota">No corresponde a una cuenta bancaria real.</p></div></div>

<div class="modal" id="modal-nip"><div class="modal-caja"><button class="cerrar">×</button><h2>NIP de demostración</h2><p class="dato-grande"><?= htmlspecialchars($usuario["nip_demo"]) ?></p><p class="nota">Este NIP es ficticio. Nunca compartas el NIP de una tarjeta real.</p></div></div>

<script src="script.js"></script>
<script>
const selectContacto = document.getElementById("select-contacto-frecuente");
const inputClabe = document.getElementById("input-clabe-destino");
if (selectContacto && inputClabe) {
    selectContacto.addEventListener("change", () => {
        if (selectContacto.value) {
            inputClabe.value = selectContacto.value;
        }
    });
}
</script>
</body>
</html>
