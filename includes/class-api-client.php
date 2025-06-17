<?php
/**
 * Cliente para la API de Factura.com
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WooFacturaComAPIClient')) {
    
    class WooFacturaComAPIClient {
        
        private $api_key;
        private $api_secret;
        private $api_url = 'https://factura.com/api/v1/';
        private $sandbox_url = 'https://sandbox.factura.com/api/v1/';
        
        public function __construct() {
            $this->api_key = get_option('woo_factura_com_api_key');
            $this->api_secret = get_option('woo_factura_com_api_secret');
            $this->sandbox_mode = get_option('woo_factura_com_sandbox_mode', 'yes');
        }
        
        public function create_cfdi($data) {
            $endpoint = 'cfdi/create';
            return $this->make_request('POST', $endpoint, $data);
        }
        
        public function get_cfdi($uuid) {
            $endpoint = 'cfdi/' . $uuid;
            return $this->make_request('GET', $endpoint);
        }
        
        public function cancel_cfdi($uuid) {
            $endpoint = 'cfdi/' . $uuid . '/cancel';
            return $this->make_request('POST', $endpoint);
        }
        
        public function validate_credentials() {
            $endpoint = 'auth/validate';
            $response = $this->make_request('GET', $endpoint);
            return $response && isset($response['valid']) && $response['valid'];
        }
        
        private function make_request($method, $endpoint, $data = null) {
            $url = $this->get_api_url() . $endpoint;
            
            $args = array(
                'method' => $method,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->get_access_token(),
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'WooCommerce-FacturaCom/1.0.0',
                ),
                'timeout' => 30,
                'sslverify' => !$this->is_sandbox(),
            );
            
            if ($data) {
                $args['body'] = json_encode($data);
            }
            
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                $this->log_error('Request error: ' . $response->get_error_message());
                return false;
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($http_code >= 400) {
                $this->log_error('HTTP error ' . $http_code . ': ' . $body);
                return false;
            }
            
            $decoded = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log_error('JSON decode error: ' . json_last_error_msg());
                return false;
            }
            
            return $decoded;
        }
        
        private function get_access_token() {
            // Implementar autenticación según la API de Factura.com
            // Por ahora retornamos la API key directamente
            return $this->api_key;
        }
        
        private function get_api_url() {
            return $this->is_sandbox() ? $this->sandbox_url : $this->api_url;
        }
        
        private function is_sandbox() {
            return $this->sandbox_mode === 'yes';
        }
        
        private function log_error($message) {
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->error($message, array('source' => 'woo-factura-com'));
            } else {
                error_log('WooFacturaCom: ' . $message);
            }
        }
        
        public function get_last_error() {
            return $this->last_error;
        }
        
        public function test_connection() {
            return $this->validate_credentials();
        }
    }
}
