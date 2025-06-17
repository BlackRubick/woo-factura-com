<?php
echo "🧪 Probando plugin mejorado...\n";

// Simular constante mínima de WordPress
define('ABSPATH', '/srv/http/wordpress/');

// Cargar el plugin
include 'woo-factura-com.php';

echo "📋 Resultados del test:\n";

if (class_exists('WooFacturaCom')) {
    echo "✅ Clase WooFacturaCom encontrada\n";
    
    try {
        $instance = WooFacturaCom::get_instance();
        echo "✅ Instancia creada exitosamente\n";
        
        $test_result = $instance->test_function();
        echo "✅ Método de prueba: " . $test_result . "\n";
        
    } catch (Exception $e) {
        echo "❌ Error al crear instancia: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Clase WooFacturaCom NO encontrada\n";
}

echo "🏁 Test completado.\n";
