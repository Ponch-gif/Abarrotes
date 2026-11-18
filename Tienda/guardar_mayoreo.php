<?php
include("conexion.php");

// Configurar la zona horaria local
date_default_timezone_set('America/Mexico_City');

$nombre_negocio = $_POST['nombre_negocio'];
$nombre_contacto = $_POST['nombre_contacto'];
$correo = $_POST['correo'];
$telefono = $_POST['telefono'];
$rfc = !empty($_POST['rfc']) ? $_POST['rfc'] : 'NO APLICA';
$direccion = $_POST['direccion'];
$cantidades = $_POST['cantidades'];

$resumen_pedido = "";
$hay_productos = false;

// Obtener fecha y hora exacta
$fecha_actual = date('Y-m-d');
$hora_actual = date('H:i:s');
$timestamp_ticket = date('Ymd_His');

$conexion->begin_transaction();

try {
    foreach ($cantidades as $id_producto => $cantidad) {
        $cantidad = intval($cantidad);
        if ($cantidad > 0) {
            $hay_productos = true;
            $sql_prod = $conexion->query("SELECT nombre, stock, precio FROM productos WHERE id = $id_producto");
            $prod = $sql_prod->fetch_assoc();
            
            if ($cantidad > $prod['stock']) {
                throw new Exception("Stock insuficiente para: " . $prod['nombre'] . " (Disponibles: " . $prod['stock'] . ")");
            }
            
            $nuevo_stock = $prod['stock'] - $cantidad;
            $conexion->query("UPDATE productos SET stock = $nuevo_stock WHERE id = $id_producto");
            // Si el producto comprado estaba en oferta, la desactivamos y regresa a su precio original
$conexion->query("UPDATE productos SET en_oferta = 0, precio_oferta = NULL WHERE id = $id_producto");
            $subtotal = $cantidad * $prod['precio'];
            $resumen_pedido .= "- " . $prod['nombre'] . " x $cantidad unidades Subtotal: $" . number_format($subtotal, 2) . "\n";
        }
    }

    if (!$hay_productos) {
        throw new Exception("Debes seleccionar al menos un producto para registrar el pedido de mayoreo.");
    }

    $sql_pedido = $conexion->prepare("INSERT INTO pedidos_mayoreo (nombre_negocio, nombre_contacto, telefono, rfc, direccion, detalles_pedido) VALUES (?, ?, ?, ?, ?, ?)");
    $sql_pedido->bind_param("ssssss", $nombre_negocio, $nombre_contacto, $telefono, $rfc, $direccion, $resumen_pedido);
    $sql_pedido->execute();
    
    $conexion->commit();
    $conexion->close();

    // ==========================================
    // GENERAR TICKET EN TXT Y ENVIAR POR CORREO
    // ==========================================
    $contenido_ticket = "========================================\n";
    $contenido_ticket .= "          ABARROTES DON PONCE           \n";
    $contenido_ticket .= "========================================\n";
    $contenido_ticket .= "Fecha del pedido: " . $fecha_actual . "\n";
    $contenido_ticket .= "Hora del pedido:      " . $hora_actual . "\n";
    $contenido_ticket .= "Negocio:          " . $nombre_negocio . "\n";
    $contenido_ticket .= "Contacto:         " . $nombre_contacto . "\n";
    $contenido_ticket .= "RFC:              " . $rfc . "\n";
    $contenido_ticket .= "Teléfono:         " . $telefono . "\n";
    $contenido_ticket .= "Dirección:        " . $direccion . "\n";
    $contenido_ticket .= "----------------------------------------\n";
    $contenido_ticket .= "Productos:\n" . $resumen_pedido . "\n";
    $contenido_ticket .= "========================================\n";
    $contenido_ticket .= "¡Gracias por tu compra al mayoreo!\n";

    $nombre_archivo = "ticket_mayoreo_" . $timestamp_ticket . ".txt";
    
    if (!file_exists('tickets')) {
        mkdir('tickets', 0777, true);
    }
    $ruta_archivo = "tickets/" . $nombre_archivo;
    file_put_contents($ruta_archivo, $contenido_ticket);

    // Enviar correo electrónico con el archivo adjunto
    $destinatario = $correo;
    $asunto = "Ticket de Pedido Mayoreo - Abarrotes Don Ponce";
    $mensaje_correo = "Hola $nombre_contacto,\n\nAdjunto encontrarás el ticket en formato de texto con los detalles y la hora exacta de tu pedido al mayoreo para $nombre_negocio.\n\n¡Saludos!";
    
    $remitente = "no-reply@abarrotesdonponce.com";
    $uid = md5(time());
    
    $headers = "From: Abarrotes Don Ponce <$remitente>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"".$uid."\"\r\n";

    $body = "--".$uid."\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $mensaje_correo."\r\n\r\n";

    $contenido_adjunto = chunk_split(base64_encode(file_get_contents($ruta_archivo)));
    $body .= "--".$uid."\r\n";
    $body .= "Content-Type: application/octet-stream; name=\"".$nombre_archivo."\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"".$nombre_archivo."\"\r\n\r\n";
    $body .= $contenido_adjunto."\r\n\r\n";
    $body .= "--".$uid."--";

    @mail($destinatario, $asunto, $body, $headers);

    header("Location: exito.php");
    exit();

} catch (Exception $e) {
    $conexion->rollback();
    $conexion->close();
    echo "<h3 style='color:red; font-family:Arial;'>Error en mayoreo: " . $e->getMessage() . "</h3>";
    echo "<br><a href='mayoreo.php'>Regresar al formulario</a>";
}
?>