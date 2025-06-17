<?php
/**
 * Plugin Name: WooCommerce Factura.com
 * Description: Integración completa con Factura.com para generar CFDIs automáticamente
 * Version: 1.0.2
 * Author: Cesar.G.A
 * Text Domain: woo-factura-com
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes solo si las funciones de WordPress están disponibles
if (!defined('WOO_FACTURA_COM_VERSION')) {
    define('WOO_FACTURA_COM_VERSION', '1.0.2');
}

if (function_exists('plugin_dir_path')) {
    define('WOO_FACTURA_COM_PLUGIN_DIR', plugin_dir_path(__FILE__));
} else {
    define('WOO_FACTURA_COM_PLUGIN_DIR', dirname(__FILE__) . '/');
}

// Clase principal
if (!class_exists('WooFacturaCom')) {
    class WooFacturaCom {
        
        private static $instance = null;
        
        public static function get_instance() {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        private function __construct() {
            // Solo agregar hooks si estamos en WordPress
            if (function_exists('add_action')) {
                add_action('admin_menu', array($this, 'add_admin_menu'));
                add_action('wp_ajax_woo_factura_com_test_connection', array($this, 'ajax_test_connection'));
                add_action('wp_ajax_woo_factura_com_generate_cfdi', array($this, 'ajax_generate_cfdi'));
                add_action('wp_ajax_woo_factura_com_cancel_cfdi', array($this, 'ajax_cancel_cfdi'));
                add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
                
                // HOOKS PARA RFC
                add_filter('woocommerce_checkout_fields', array($this, 'add_rfc_field_to_checkout'));
                add_action('woocommerce_checkout_process', array($this, 'validate_rfc_field'));
                add_action('woocommerce_checkout_update_order_meta', array($this, 'save_rfc_field'));
                add_filter('woocommerce_admin_billing_fields', array($this, 'add_rfc_field_to_admin'));
                add_filter('woocommerce_customer_meta_fields', array($this, 'add_rfc_field_to_customer'));
                add_action('add_meta_boxes', array($this, 'add_rfc_meta_box'));
                add_action('save_post', array($this, 'save_rfc_meta_box'));
                
                // Enqueue scripts
                add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'));
                add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
                
                // Hook para generación automática
                add_action('woocommerce_order_status_completed', array($this, 'auto_generate_cfdi'));
            }
        }
        
        public function add_admin_menu() {
            if (function_exists('add_submenu_page')) {
                add_submenu_page(
                    'woocommerce',
                    'Factura.com',
                    'Factura.com',
                    'manage_options',
                    'woo-factura-com',
                    array($this, 'admin_page')
                );
            }
        }
        
        public function admin_page() {
            // Debug: Mostrar información de rutas
            $settings_file = WOO_FACTURA_COM_PLUGIN_DIR . 'admin/views/settings-page.php';
            
            // Verificar si el archivo existe
            if (file_exists($settings_file)) {
                // Cargar la página de configuración completa
                include $settings_file;
            } else {
                // Mostrar página de error con información de debug
                echo '<div class="wrap">';
                echo '<h1>WooCommerce Factura.com</h1>';
                
                echo '<div class="notice notice-error">';
                echo '<p><strong>⚠️ Error de Configuración:</strong> No se encuentra el archivo de configuración.</p>';
                echo '</div>';
                
                echo '<div class="notice notice-info">';
                echo '<h3>🔍 Información de Debug:</h3>';
                echo '<p><strong>Archivo buscado:</strong><br><code>' . esc_html($settings_file) . '</code></p>';
                echo '<p><strong>Directorio del plugin:</strong><br><code>' . esc_html(WOO_FACTURA_COM_PLUGIN_DIR) . '</code></p>';
                echo '<p><strong>Directorio actual:</strong><br><code>' . esc_html(getcwd()) . '</code></p>';
                
                // Verificar estructura de directorios
                $admin_dir = WOO_FACTURA_COM_PLUGIN_DIR . 'admin/';
                $views_dir = WOO_FACTURA_COM_PLUGIN_DIR . 'admin/views/';
                
                echo '<p><strong>Verificación de estructura:</strong></p>';
                echo '<ul>';
                echo '<li>Directorio admin/: ' . (is_dir($admin_dir) ? '✅ Existe' : '❌ No existe') . '</li>';
                echo '<li>Directorio admin/views/: ' . (is_dir($views_dir) ? '✅ Existe' : '❌ No existe') . '</li>';
                echo '<li>Archivo settings-page.php: ' . (file_exists($settings_file) ? '✅ Existe' : '❌ No existe') . '</li>';
                echo '</ul>';
                echo '</div>';
                
                // Mostrar página básica de configuración
                echo '<div class="card" style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">';
                echo '<h2>📊 Estado del Sistema (Modo Básico)</h2>';
                echo '<table class="widefat">';
                echo '<tbody>';
                echo '<tr><td><strong>Plugin Version:</strong></td><td>' . WOO_FACTURA_COM_VERSION . '</td></tr>';
                echo '<tr><td><strong>WooCommerce:</strong></td><td>' . (class_exists('WooCommerce') ? '✅ Activo' : '❌ No encontrado') . '</td></tr>';
                echo '<tr><td><strong>PHP Version:</strong></td><td>' . phpversion() . '</td></tr>';
                echo '<tr><td><strong>WordPress Version:</strong></td><td>' . get_bloginfo('version') . '</td></tr>';
                echo '</tbody>';
                echo '</table>';
                echo '</div>';
                
                echo '<div class="card" style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">';
                echo '<h3>🔧 Solución Sugerida:</h3>';
                echo '<ol>';
                echo '<li>Verifica que el archivo <code>admin/views/settings-page.php</code> existe</li>';
                echo '<li>Verifica los permisos del archivo (debe ser 644)</li>';
                echo '<li>Verifica el propietario del archivo (debe ser http:http)</li>';
                echo '<li>Si el problema persiste, recrea el archivo de configuración</li>';
                echo '</ol>';
                echo '</div>';
                
                echo '</div>';
            }
        }
        
        // ============== FUNCIONES RFC ==============
        
        public function add_rfc_field_to_checkout($fields) {
            $fields['billing']['billing_rfc'] = array(
                'label' => 'RFC *',
                'placeholder' => 'Ej: XAXX010101000',
                'required' => false,
                'class' => array('form-row-wide'),
                'priority' => 25,
                'custom_attributes' => array(
                    'maxlength' => 13,
                    'style' => 'text-transform: uppercase;'
                ),
                'description' => 'RFC mexicano para facturación electrónica (opcional)'
            );
            
            return $fields;
        }
        
        public function validate_rfc_field() {
            if (isset($_POST['billing_rfc']) && !empty($_POST['billing_rfc'])) {
                $rfc = sanitize_text_field($_POST['billing_rfc']);
                if (!$this->validate_rfc($rfc)) {
                    wc_add_notice('El RFC ingresado no tiene un formato válido. Debe ser como: XAXX010101000', 'error');
                }
            }
        }
        
        public function save_rfc_field($order_id) {
            if (isset($_POST['billing_rfc'])) {
                $rfc = strtoupper(sanitize_text_field($_POST['billing_rfc']));
                update_post_meta($order_id, '_billing_rfc', $rfc);
            }
        }
        
        public function add_rfc_field_to_admin($fields) {
            $fields['rfc'] = array(
                'label' => 'RFC',
                'show' => true,
                'wrapper_class' => 'form-field-wide',
                'style' => 'width: 100%;'
            );
            
            return $fields;
        }
        
        public function add_rfc_field_to_customer($fields) {
            $fields['billing']['fields']['billing_rfc'] = array(
                'label' => 'RFC',
                'description' => 'RFC mexicano para facturación'
            );
            
            return $fields;
        }
        
        public function add_rfc_meta_box() {
            add_meta_box(
                'woo-factura-com-rfc',
                '📋 RFC del Cliente',
                array($this, 'rfc_meta_box_callback'),
                'shop_order',
                'side',
                'default'
            );
        }
        
        public function rfc_meta_box_callback($post) {
            $order = wc_get_order($post->ID);
            $rfc = $order->get_meta('_billing_rfc');
            
            echo '<div style="padding: 10px;">';
            echo '<p>';
            echo '<label for="billing_rfc"><strong>RFC del Cliente:</strong></label><br>';
            echo '<input type="text" id="billing_rfc" name="billing_rfc" value="' . esc_attr($rfc) . '" ';
            echo 'style="width: 100%; text-transform: uppercase; margin-top: 5px;" maxlength="13" placeholder="XAXX010101000">';
            echo '</p>';
            echo '<p><small style="color: #666;">RFC mexicano para facturación electrónica.<br>Formato: 4 letras + 6 números + 3 caracteres</small></p>';
            
            if (!empty($rfc)) {
                $is_valid = $this->validate_rfc($rfc);
                if ($is_valid) {
                    echo '<p style="color: green;">✅ RFC válido</p>';
                } else {
                    echo '<p style="color: red;">❌ RFC con formato incorrecto</p>';
                }
            }
            
            echo '</div>';
            
            wp_nonce_field('save_rfc_field', 'rfc_nonce');
        }
        
        public function save_rfc_meta_box($post_id) {
            // Verificar nonce
            if (!isset($_POST['rfc_nonce']) || !wp_verify_nonce($_POST['rfc_nonce'], 'save_rfc_field')) {
                return;
            }
            
            // Verificar permisos
            if (!current_user_can('edit_shop_order', $post_id)) {
                return;
            }
            
            // Guardar RFC
            if (isset($_POST['billing_rfc'])) {
                $rfc = strtoupper(sanitize_text_field($_POST['billing_rfc']));
                update_post_meta($post_id, '_billing_rfc', $rfc);
            }
        }
        
        private function validate_rfc($rfc) {
            // Limpiar y convertir a mayúsculas
            $rfc = strtoupper(trim($rfc));
            
            // Verificar longitud
            if (strlen($rfc) != 13) {
                return false;
            }
            
            // Patrón más permisivo para RFC mexicano
            // 3-4 letras + 6 dígitos + 3 caracteres alfanuméricos
            $pattern = '/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/';
            
            return preg_match($pattern, $rfc);
        }
        
        public function enqueue_checkout_scripts() {
            if (is_checkout()) {
                wp_add_inline_script('jquery', '
                    jQuery(document).ready(function($) {
                        // Convertir RFC a mayúsculas automáticamente
                        $(document).on("input", "#billing_rfc", function() {
                            var cursorPos = this.selectionStart;
                            this.value = this.value.toUpperCase();
                            this.setSelectionRange(cursorPos, cursorPos);
                        });
                        
                        // Validar RFC en tiempo real
                        $(document).on("blur", "#billing_rfc", function() {
                            var rfc = $(this).val().trim();
                            var field = $(this).closest(".form-row");
                            
                            // Limpiar mensajes anteriores
                            field.find(".rfc-validation").remove();
                            
                            if (rfc.length > 0) {
                                var pattern = /^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/;
                                if (pattern.test(rfc)) {
                                    field.append("<small class=\"rfc-validation\" style=\"color: green; display: block;\">✅ RFC válido</small>");
                                } else {
                                    field.append("<small class=\"rfc-validation\" style=\"color: red; display: block;\">❌ Formato incorrecto. Ej: XAXX010101000</small>");
                                }
                            }
                        });
                    });
                ');
            }
        }
        
        public function enqueue_admin_scripts($hook) {
            if (strpos($hook, 'woo-factura-com') !== false || $hook === 'shop_order' || strpos($hook, 'wc-orders') !== false) {
                wp_enqueue_script('jquery');
            }
        }
        
        // ============== FUNCIONES METABOX CFDI (ACTUALIZADAS) ==============
        
        public function add_order_meta_box() {
            add_meta_box(
                'woo-factura-com-cfdi',
                '📄 Factura.com CFDI',
                array($this, 'order_meta_box_callback'),
                'shop_order',
                'side',
                'high'
            );
            
            // Para WooCommerce HPOS (High Performance Order Storage)
            add_meta_box(
                'woo-factura-com-cfdi',
                '📄 Factura.com CFDI',
                array($this, 'order_meta_box_callback'),
                'woocommerce_page_wc-orders',
                'side',
                'high'
            );
        }
        
        public function order_meta_box_callback($post_or_order) {
            // DETECCIÓN MEJORADA DEL ORDER ID
            $order_id = null;
            
            if (is_object($post_or_order)) {
                if (method_exists($post_or_order, 'get_id')) {
                    // Es un objeto WC_Order
                    $order_id = $post_or_order->get_id();
                } elseif (isset($post_or_order->ID)) {
                    // Es un objeto WP_Post
                    $order_id = $post_or_order->ID;
                }
            } elseif (is_numeric($post_or_order)) {
                $order_id = $post_or_order;
            }
            
            // Si aún no tenemos ID, intentar obtenerlo de la URL
            if (!$order_id && isset($_GET['id'])) {
                $order_id = intval($_GET['id']);
            }
            
            // Si aún no tenemos ID, intentar con post parameter
            if (!$order_id && isset($_GET['post'])) {
                $order_id = intval($_GET['post']);
            }
            
            $order = wc_get_order($order_id);
            
            if (!$order) {
                echo '<div style="padding: 10px; background: #ffebee; border: 1px solid #f44336;">';
                echo '<p><strong>❌ Error:</strong> No se pudo cargar el pedido</p>';
                echo '<p><strong>Debug Info:</strong></p>';
                echo '<ul style="font-size: 11px; color: #666;">';
                echo '<li>ID detectado: ' . ($order_id ? $order_id : 'null') . '</li>';
                echo '<li>Tipo de objeto: ' . gettype($post_or_order) . '</li>';
                if (is_object($post_or_order)) {
                    echo '<li>Clase: ' . get_class($post_or_order) . '</li>';
                }
                echo '<li>GET[id]: ' . (isset($_GET['id']) ? $_GET['id'] : 'no definido') . '</li>';
                echo '<li>GET[post]: ' . (isset($_GET['post']) ? $_GET['post'] : 'no definido') . '</li>';
                echo '</ul>';
                echo '</div>';
                return;
            }
            
            $cfdi_uuid = $order->get_meta('_factura_com_cfdi_uuid');
            $cfdi_pdf_url = $order->get_meta('_factura_com_cfdi_pdf_url');
            $cfdi_xml_url = $order->get_meta('_factura_com_cfdi_xml_url');
            $cfdi_serie = $order->get_meta('_factura_com_cfdi_serie');
            $cfdi_folio = $order->get_meta('_factura_com_cfdi_folio');
            $cfdi_environment = $order->get_meta('_factura_com_cfdi_environment');
            $cfdi_cancelled = $order->get_meta('_factura_com_cfdi_cancelled');
            
            echo '<div class="woo-factura-com-meta-box" style="padding: 10px;">';
            
            if ($cfdi_uuid) {
                // CFDI existente
                $status_color = $cfdi_cancelled ? 'red' : 'green';
                $status_text = $cfdi_cancelled ? '❌ CFDI Cancelado' : '✅ CFDI Generado';
                $env_badge = $cfdi_environment === 'demo' ? ' 🧪' : ($cfdi_environment === 'sandbox' ? ' 🔧' : ' 🚀');
                
                echo '<p><strong>Estado:</strong> <span style="color: ' . $status_color . ';">' . $status_text . '</span>' . $env_badge . '</p>';
                
                if ($cfdi_serie && $cfdi_folio) {
                    echo '<p><strong>Serie-Folio:</strong> ' . esc_html($cfdi_serie . '-' . $cfdi_folio) . '</p>';
                }
                
                echo '<p><strong>UUID:</strong><br>';
                echo '<code style="font-size: 11px; word-break: break-all; background: #f1f1f1; padding: 5px; display: block; margin: 5px 0;">' . esc_html($cfdi_uuid) . '</code></p>';
                
                if ($cfdi_pdf_url || $cfdi_xml_url) {
                    echo '<p><strong>Archivos:</strong><br>';
                    if ($cfdi_pdf_url) {
                        echo '<a href="' . esc_url($cfdi_pdf_url) . '" target="_blank" class="button button-secondary button-small">📄 PDF</a> ';
                    }
                    if ($cfdi_xml_url) {
                        echo '<a href="' . esc_url($cfdi_xml_url) . '" target="_blank" class="button button-secondary button-small">📁 XML</a>';
                    }
                    echo '</p>';
                }
                
                echo '<hr>';
                echo '<p><strong>Acciones:</strong></p>';
                echo '<button type="button" class="button button-secondary button-small" onclick="copyUUID(\'' . esc_js($cfdi_uuid) . '\')">📋 Copiar UUID</button><br><br>';
                
                if (!$cfdi_cancelled) {
                    echo '<button type="button" class="button button-secondary" id="regenerate-cfdi-btn" data-order-id="' . $order_id . '">🔄 Regenerar CFDI</button><br><br>';
                    echo '<button type="button" class="button button-link-delete" id="cancel-cfdi-btn" data-order-id="' . $order_id . '" data-uuid="' . esc_attr($cfdi_uuid) . '">❌ Cancelar CFDI</button>';
                }
                
            } else {
                // Sin CFDI
                echo '<p><strong>Estado:</strong> <span style="color: orange;">⏳ Sin generar</span></p>';
                echo '<p>No se ha generado CFDI para este pedido.</p>';
                
                // Verificar si el pedido tiene RFC
                $rfc = $order->get_meta('_billing_rfc');
                if (empty($rfc)) {
                    echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0;">';
                    echo '<p><strong>⚠️ Advertencia:</strong> Este pedido no tiene RFC del cliente.</p>';
                    echo '<p><small>Puedes agregarlo en el metabox "📋 RFC del Cliente" de abajo.</small></p>';
                    echo '</div>';
                } else {
                    echo '<p><strong>RFC del cliente:</strong> ' . esc_html($rfc) . '</p>';
                    if ($this->validate_rfc($rfc)) {
                        echo '<p style="color: green; font-size: 12px;">✅ RFC válido</p>';
                    } else {
                        echo '<p style="color: red; font-size: 12px;">❌ RFC con formato incorrecto</p>';
                    }
                }
                
                echo '<button type="button" class="button button-primary" id="generate-cfdi-btn" data-order-id="' . $order_id . '">';
                echo '🧾 Generar CFDI';
                echo '</button>';
            }
            
            echo '</div>';
            
            // JavaScript mejorado con funciones adicionales
            echo '<script>
            function copyUUID(uuid) {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(uuid).then(function() {
                        alert("UUID copiado al portapapeles");
                    });
                } else {
                    var textArea = document.createElement("textarea");
                    textArea.value = uuid;
                    document.body.appendChild(textArea);
                    textArea.select();
                    document.execCommand("copy");
                    document.body.removeChild(textArea);
                    alert("UUID copiado al portapapeles");
                }
            }
            
            jQuery(document).ready(function($) {
                $("#generate-cfdi-btn, #regenerate-cfdi-btn").on("click", function() {
                    var button = $(this);
                    var orderId = button.data("order-id");
                    var isRegenerate = button.attr("id") === "regenerate-cfdi-btn";
                    
                    if (!orderId) {
                        alert("Error: No se pudo obtener el ID del pedido");
                        return;
                    }
                    
                    var confirmMsg = isRegenerate ? 
                        "¿Estás seguro de regenerar el CFDI? Esto cancelará el CFDI actual y creará uno nuevo." :
                        "¿Generar CFDI para este pedido?";
                    
                    if (!confirm(confirmMsg)) {
                        return;
                    }
                    
                    button.prop("disabled", true).text(isRegenerate ? "🔄 Regenerando..." : "🔄 Generando...");
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "woo_factura_com_generate_cfdi",
                            order_id: orderId,
                            regenerate: isRegenerate ? 1 : 0,
                            nonce: "' . wp_create_nonce('generate_cfdi_nonce') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                alert("✅ CFDI generado exitosamente!\\n\\nUUID: " + response.data.uuid + "\\n\\nLa página se recargará para mostrar los cambios.");
                                location.reload();
                            } else {
                                alert("❌ Error: " + (response.data || "Error desconocido"));
                            }
                        },
                        error: function(xhr, status, error) {
                            console.log("❌ Error AJAX:", xhr.responseText);
                            alert("Error de conexión: " + error + "\\nRevisa la consola para más detalles.");
                        },
                        complete: function() {
                            button.prop("disabled", false);
                            button.text(isRegenerate ? "🔄 Regenerar CFDI" : "🧾 Generar CFDI");
                        }
                    });
                });
                
                // Cancelar CFDI
                $("#cancel-cfdi-btn").on("click", function() {
                    var button = $(this);
                    var orderId = button.data("order-id");
                    var uuid = button.data("uuid");
                    
                    if (!confirm("¿Estás seguro de cancelar este CFDI?\\n\\nUUID: " + uuid + "\\n\\nEsta acción no se puede deshacer.")) {
                        return;
                    }
                    
                    button.prop("disabled", true).text("❌ Cancelando...");
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "woo_factura_com_cancel_cfdi",
                            order_id: orderId,
                            nonce: "' . wp_create_nonce('cancel_cfdi_nonce') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                alert("✅ CFDI cancelado exitosamente.\\n\\nLa página se recargará para mostrar los cambios.");
                                location.reload();
                            } else {
                                alert("❌ Error al cancelar: " + (response.data || "Error desconocido"));
                            }
                        },
                        error: function(xhr, status, error) {
                            alert("Error de conexión: " + error);
                        },
                        complete: function() {
                            button.prop("disabled", false).text("❌ Cancelar CFDI");
                        }
                    });
                });
            });
            </script>';
        }
        
        // ============== FUNCIONES AJAX ACTUALIZADAS ==============
        
        public function ajax_generate_cfdi() {
            // Verificar nonce
            if (!wp_verify_nonce($_POST['nonce'], 'generate_cfdi_nonce')) {
                wp_send_json_error('Error de seguridad');
            }
            
            $order_id = intval($_POST['order_id']);
            $regenerate = isset($_POST['regenerate']) && $_POST['regenerate'] == '1';
            
            if (!$order_id) {
                wp_send_json_error('ID de pedido inválido');
            }
            
            // Cargar el manager real
            if (file_exists(WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-real-cfdi-manager.php')) {
                require_once WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-real-cfdi-manager.php';
                $cfdi_manager = new WooFacturaComRealCFDIManager();
                
                if ($regenerate) {
                    $result = $cfdi_manager->regenerate_cfdi($order_id);
                } else {
                    $result = $cfdi_manager->generate_cfdi_for_order($order_id);
                }
            } else {
                // Fallback al sistema anterior si no existe el manager real
                $result = $this->fallback_generate_cfdi($order_id, $regenerate);
            }
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result['error']);
            }
        }
        
        public function ajax_cancel_cfdi() {
            // Verificar nonce
            if (!wp_verify_nonce($_POST['nonce'], 'cancel_cfdi_nonce')) {
                wp_send_json_error('Error de seguridad');
            }
            
            $order_id = intval($_POST['order_id']);
            
            if (!$order_id) {
                wp_send_json_error('ID de pedido inválido');
            }
            
            // Cargar el manager real
            if (file_exists(WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-real-cfdi-manager.php')) {
                require_once WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-real-cfdi-manager.php';
                $cfdi_manager = new WooFacturaComRealCFDIManager();
                $result = $cfdi_manager->cancel_cfdi($order_id);
            } else {
                // Fallback básico
                $order = wc_get_order($order_id);
                if ($order) {
                    $order->update_meta_data('_factura_com_cfdi_cancelled', current_time('mysql'));
                    $order->save();
                    $order->add_order_note('CFDI cancelado manualmente');
                    $result = ['success' => true, 'message' => 'CFDI cancelado'];
                } else {
                    $result = ['success' => false, 'error' => 'Pedido no encontrado'];
                }
            }
            
            if ($result['success']) {
                wp_send_json_success($result['message']);
            } else {
                wp_send_json_error($result['error']);
            }
        }
        
        /**
         * Fallback para generar CFDI si no existe el manager real
         */
        private function fallback_generate_cfdi($order_id, $regenerate) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return ['success' => false, 'error' => 'Pedido no encontrado'];
            }
            
            // Verificar si ya tiene CFDI y no es regeneración
            if (!$regenerate && $order->get_meta('_factura_com_cfdi_uuid')) {
                return ['success' => false, 'error' => 'Este pedido ya tiene un CFDI generado. Usa "Regenerar" si necesitas crear uno nuevo.'];
            }
            
            // Simular generación de CFDI
            $fake_uuid = $this->generate_fake_uuid();
            $fake_pdf_url = 'https://factura.com/demo/cfdi/' . $fake_uuid . '.pdf';
            $fake_xml_url = 'https://factura.com/demo/cfdi/' . $fake_uuid . '.xml';
            $fake_serie = 'DEMO';
            $fake_folio = rand(1000, 9999);
            
            // Obtener RFC del cliente
            $rfc = $order->get_meta('_billing_rfc');
            if (empty($rfc)) {
                $rfc = 'XAXX010101000'; // RFC genérico
            }
            
            // Guardar en el pedido
            $order->update_meta_data('_factura_com_cfdi_uuid', $fake_uuid);
            $order->update_meta_data('_factura_com_cfdi_pdf_url', $fake_pdf_url);
            $order->update_meta_data('_factura_com_cfdi_xml_url', $fake_xml_url);
            $order->update_meta_data('_factura_com_cfdi_serie', $fake_serie);
            $order->update_meta_data('_factura_com_cfdi_folio', $fake_folio);
            $order->update_meta_data('_factura_com_cfdi_generated_at', current_time('mysql'));
            $order->update_meta_data('_factura_com_cfdi_environment', 'demo');
            $order->update_meta_data('_factura_com_rfc_used', $rfc);
            $order->save();
            
            // Agregar nota al pedido
            $order->add_order_note(
                sprintf(
                    'CFDI DEMO generado. UUID: %s | Serie-Folio: %s-%s | RFC: %s',
                    $fake_uuid,
                    $fake_serie,
                    $fake_folio,
                    $rfc
                )
            );
            
            return [
                'success' => true,
                'uuid' => $fake_uuid,
                'pdf_url' => $fake_pdf_url,
                'xml_url' => $fake_xml_url,
                'serie' => $fake_serie,
                'folio' => $fake_folio,
                'message' => 'CFDI demo generado exitosamente. Para CFDIs reales, configura la integración con Factura.com'
            ];
        }
        
        private function generate_fake_uuid() {
            return sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
        }
        
        public function ajax_test_connection() {
            // Verificar nonce
            if (!wp_verify_nonce($_POST['nonce'], 'test_connection_nonce')) {
                wp_die('Error de seguridad');
            }
            
            $api_key = sanitize_text_field($_POST['api_key']);
            $secret_key = sanitize_text_field($_POST['secret_key']);
            $sandbox_mode = isset($_POST['sandbox_mode']) && $_POST['sandbox_mode'] == '1';
            
            // Guardar temporalmente las credenciales para la prueba
            $original_api_key = get_option('woo_factura_com_api_key');
            $original_secret_key = get_option('woo_factura_com_secret_key');
            $original_sandbox = get_option('woo_factura_com_sandbox_mode');
            
            update_option('woo_factura_com_api_key', $api_key);
            update_option('woo_factura_com_secret_key', $secret_key);
            update_option('woo_factura_com_sandbox_mode', $sandbox_mode ? 'yes' : 'no');
            
            // Probar conexión
            if (file_exists(WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-real-api-client.php')) {
                require_once WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-real-api-client.php';
                $api_client = new WooFacturaComRealAPIClient();
                $result = $api_client->test_connection();
            } else {
                // Simulación básica si no existe el cliente real
                if (empty($api_key) || empty($secret_key)) {
                    $result = ['success' => false, 'message' => 'API Key y Secret Key son requeridos'];
                } elseif (strlen($api_key) < 10) {
                    $result = ['success' => false, 'message' => 'API Key parece ser inválida (muy corta)'];
                } elseif (strlen($secret_key) < 10) {
                    $result = ['success' => false, 'message' => 'Secret Key parece ser inválida (muy corta)'];
                } else {
                    $result = [
                        'success' => true, 
                        'message' => 'Credenciales válidas (simulación)',
                        'environment' => $sandbox_mode ? 'Sandbox' : 'Producción'
                    ];
                }
            }
            
            // Restaurar credenciales originales
            if ($original_api_key !== false) update_option('woo_factura_com_api_key', $original_api_key);
            if ($original_secret_key !== false) update_option('woo_factura_com_secret_key', $original_secret_key);
            if ($original_sandbox !== false) update_option('woo_factura_com_sandbox_mode', $original_sandbox);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result['message']);
            }
        }
        
        /**
         * Generación automática de CFDI cuando el pedido se completa
         */
        public function auto_generate_cfdi($order_id) {
            $auto_generate = get_option('woo_factura_com_auto_generate', 'no');
            
            if ($auto_generate !== 'yes') {
                return;
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }
            
            // Verificar si ya tiene CFDI
            if ($order->get_meta('_factura_com_cfdi_uuid')) {
                return;
            }
            
            // Esperar 5 segundos para que se procese completamente el pedido
            sleep(5);
            
            // Generar CFDI automáticamente
            if (file_exists(WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-real-cfdi-manager.php')) {
                require_once WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-real-cfdi-manager.php';
                $cfdi_manager = new WooFacturaComRealCFDIManager();
                $result = $cfdi_manager->generate_cfdi_for_order($order_id);
                
                if ($result['success']) {
                    $order->add_order_note('CFDI generado automáticamente al completar el pedido. UUID: ' . $result['uuid']);
                } else {
                    $order->add_order_note('Error al generar CFDI automáticamente: ' . $result['error']);
                }
            }
        }
        
        public function test_function() {
            return "Clase WooFacturaCom cargada correctamente";
        }
    }
}

// Solo ejecutar hooks de WordPress si estamos en WordPress
if (function_exists('add_action')) {
    
    // Inicializar el plugin cuando WordPress esté listo
    add_action('plugins_loaded', function() {
        if (class_exists('WooCommerce')) {
            WooFacturaCom::get_instance();
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>WooCommerce Factura.com requiere WooCommerce activo.</p></div>';
            });
        }
    });
    
    // Hook de activación
    if (function_exists('register_activation_hook')) {
        register_activation_hook(__FILE__, function() {
            if (!class_exists('WooCommerce')) {
                if (function_exists('wp_die')) {
                    wp_die('Este plugin requiere WooCommerce. Por favor instala y activa WooCommerce primero.');
                }
            }
            if (function_exists('add_option')) {
                add_option('woo_factura_com_version', WOO_FACTURA_COM_VERSION);
                // Configuraciones por defecto
                add_option('woo_factura_com_demo_mode', 'yes');
                add_option('woo_factura_com_sandbox_mode', 'yes');
                add_option('woo_factura_com_auto_generate', 'no');
                add_option('woo_factura_com_send_email', 'yes');
                add_option('woo_factura_com_debug_mode', 'no');
                add_option('woo_factura_com_uso_cfdi', 'G01');
                add_option('woo_factura_com_forma_pago', '01');
                add_option('woo_factura_com_metodo_pago', 'PUE');
                add_option('woo_factura_com_clave_prod_serv', '81112101');
                add_option('woo_factura_com_clave_unidad', 'E48');
                add_option('woo_factura_com_unidad', 'Unidad de servicio');
                add_option('woo_factura_com_tasa_iva', '0.16');
                add_option('woo_factura_com_lugar_expedicion', '44650');
            }
        });
    }
    
    // Hook de desactivación
    if (function_exists('register_deactivation_hook')) {
        register_deactivation_hook(__FILE__, function() {
            // Limpiar tareas programadas
            wp_clear_scheduled_hook('woo_factura_com_cleanup');
        });
    }
}

// Cargar funcionalidad extendida si está disponible
if (file_exists(WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-woo-factura-com.php')) {
    require_once WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-woo-factura-com.php';
    
    // Inicializar funcionalidad extendida
    add_action('plugins_loaded', function() {
        if (class_exists('WooFacturaComExtended') && class_exists('WooCommerce')) {
            WooFacturaComExtended::instance();
        }
    }, 20);
}
