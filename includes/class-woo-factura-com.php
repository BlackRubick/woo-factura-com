<?php
/**
 * Clase principal extendida del plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WooFacturaComExtended')) {
    
    class WooFacturaComExtended {
        
        protected static $instance = null;
        
        public static function instance() {
            if (is_null(self::$instance)) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        private function __construct() {
            $this->init_hooks();
            $this->load_dependencies();
        }
        
        private function init_hooks() {
            add_action('init', array($this, 'load_textdomain'));
            add_action('woocommerce_order_status_completed', array($this, 'generate_cfdi_on_order_complete'));
            add_action('woocommerce_checkout_fields', array($this, 'add_rfc_field'));
            add_action('woocommerce_checkout_process', array($this, 'validate_rfc_field'));
            add_action('woocommerce_checkout_update_order_meta', array($this, 'save_rfc_field'));
        }
        
        private function load_dependencies() {
            // Cargar dependencias cuando sea necesario
            if (file_exists(WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-api-client.php')) {
                require_once WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-api-client.php';
            }
            
            if (file_exists(WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-cfdi-manager.php')) {
                require_once WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-cfdi-manager.php';
            }
            
            if (file_exists(WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-admin-settings.php')) {
                require_once WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-admin-settings.php';
            }
        }
        
        public function load_textdomain() {
            load_plugin_textdomain('woo-factura-com', false, dirname(plugin_basename(WOO_FACTURA_COM_PLUGIN_DIR)) . '/languages/');
        }
        
        public function add_rfc_field($fields) {
            $fields['billing']['billing_rfc'] = array(
                'label' => __('RFC', 'woo-factura-com'),
                'placeholder' => _x('Ej: XAXX010101000', 'placeholder', 'woo-factura-com'),
                'required' => false,
                'class' => array('form-row-wide'),
                'priority' => 25,
            );
            
            return $fields;
        }
        
        public function validate_rfc_field() {
            if (isset($_POST['billing_rfc']) && !empty($_POST['billing_rfc'])) {
                $rfc = sanitize_text_field($_POST['billing_rfc']);
                if (!$this->validate_rfc($rfc)) {
                    wc_add_notice(__('El RFC ingresado no es válido.', 'woo-factura-com'), 'error');
                }
            }
        }
        
        public function save_rfc_field($order_id) {
            if (isset($_POST['billing_rfc'])) {
                $rfc = sanitize_text_field($_POST['billing_rfc']);
                update_post_meta($order_id, '_billing_rfc', $rfc);
            }
        }
        
        private function validate_rfc($rfc) {
            // Validación básica de RFC mexicano
            $pattern = '/^[A-ZÑ&]{3,4}[0-9]{6}[A-V1-9][A-Z1-9][0-9A]$/';
            return preg_match($pattern, strtoupper($rfc));
        }
        
        public function generate_cfdi_on_order_complete($order_id) {
            // Solo si está habilitada la generación automática
            if (get_option('woo_factura_com_auto_generate') !== 'yes') {
                return;
            }
            
            if (class_exists('WooFacturaComCFDIManager')) {
                $cfdi_manager = new WooFacturaComCFDIManager();
                $cfdi_manager->generate_cfdi_for_order($order_id);
            }
        }
        
        public function get_plugin_info() {
            return array(
                'version' => WOO_FACTURA_COM_VERSION,
                'name' => 'WooCommerce Factura.com',
                'status' => 'active',
                'woocommerce' => class_exists('WooCommerce'),
                'api_configured' => $this->is_api_configured(),
            );
        }
        
        private function is_api_configured() {
            $api_key = get_option('woo_factura_com_api_key');
            $api_secret = get_option('woo_factura_com_api_secret');
            return !empty($api_key) && !empty($api_secret);
        }
    }
}
