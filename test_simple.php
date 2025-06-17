<?php
echo "Probando plugin simple...\n";

// Simular constantes de WordPress
define('ABSPATH', '/srv/http/wordpress/');

// Cargar el plugin
include 'woo-factura-com.php';

if (class_exists('WooFacturaCom')) {
    echo "✅ Clase encontrada!\n";
} else {
    echo "❌ Clase no encontrada\n";
}
