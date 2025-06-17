<?php
/**
 * Gestor de CFDIs real para Factura.com
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WooFacturaComRealCFDIManager')) {
    
    class WooFacturaComRealCFDIManager {
        
        private $api_client;
        
        public function __construct() {
            require_once WOO_FACTURA_COM_PLUGIN_DIR . 'includes/class-real-api-client.php';
            $this->api_client = new WooFacturaComRealAPIClient();
        }
        
        /**
         * Generar CFDI para un pedido
         */
        public function generate_cfdi_for_order($order_id) {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                return ['success' => false, 'error' => 'Pedido no encontrado'];
            }
            
            // Verificar si ya tiene CFDI
            if ($order->get_meta('_factura_com_cfdi_uuid')) {
                return ['success' => false, 'error' => 'Este pedido ya tiene un CFDI generado'];
            }
            
            // Verificar configuración
            $config_check = $this->check_configuration();
            if (!$config_check['valid']) {
                return ['success' => false, 'error' => $config_check['message']];
            }
            
            // Preparar datos del CFDI
            $cfdi_preparation = $this->api_client->prepare_cfdi_data($order);
            if (!$cfdi_preparation['success']) {
                return $cfdi_preparation;
            }
            
            $cfdi_data = $cfdi_preparation['data'];
            
            // Log de debug
            $this->log_debug('Generando CFDI para pedido', [
                'order_id' => $order_id,
                'cfdi_data' => $cfdi_data
            ]);
            
            // Modo demo o real
            $demo_mode = get_option('woo_factura_com_demo_mode', 'yes');
            
            if ($demo_mode === 'yes') {
                return $this->generate_demo_cfdi($order, $cfdi_data);
            } else {
                return $this->generate_real_cfdi($order, $cfdi_data);
            }
        }
        
        /**
         * Generar CFDI real usando la API
         */
        private function generate_real_cfdi($order, $cfdi_data) {
            $response = $this->api_client->create_cfdi_40($cfdi_data);
            
            if (!$response['success']) {
                $this->log_error('Error al generar CFDI real', [
                    'order_id' => $order->get_id(),
                    'error' => $response['error']
                ]);
                
                return ['success' => false, 'error' => 'Error API: ' . $response['error']];
            }
            
            $cfdi_response = $response['data'];
            
            // Extraer datos del CFDI
            $uuid = $cfdi_response['UUID'] ?? null;
            $pdf_url = $cfdi_response['pdf_url'] ?? null;
            $xml_url = $cfdi_response['xml_url'] ?? null;
            $serie = $cfdi_response['INV']['Serie'] ?? '';
            $folio = $cfdi_response['INV']['Folio'] ?? '';
            
            if (!$uuid) {
                return ['success' => false, 'error' => 'UUID no recibido de la API'];
            }
            
            // Guardar datos en el pedido
            $this->save_cfdi_data($order, [
                'uuid' => $uuid,
                'pdf_url' => $pdf_url,
                'xml_url' => $xml_url,
                'serie' => $serie,
                'folio' => $folio,
                'api_response' => $cfdi_response,
                'generated_at' => current_time('mysql'),
                'environment' => get_option('woo_factura_com_sandbox_mode') === 'yes' ? 'sandbox' : 'production'
            ]);
            
            // Agregar nota al pedido
            $order->add_order_note(
                sprintf(
                    'CFDI generado exitosamente via Factura.com API.\nUUID: %s\nSerie-Folio: %s-%s\nEntorno: %s',
                    $uuid,
                    $serie,
                    $folio,
                    get_option('woo_factura_com_sandbox_mode') === 'yes' ? 'Sandbox' : 'Producción'
                )
            );
            
            // Enviar email al cliente si está habilitado
            if (get_option('woo_factura_com_send_email', 'yes') === 'yes') {
                $this->send_cfdi_email($order, [
                    'uuid' => $uuid,
                    'pdf_url' => $pdf_url,
                    'xml_url' => $xml_url
                ]);
            }
            
            $this->log_debug('CFDI generado exitosamente', [
                'order_id' => $order->get_id(),
                'uuid' => $uuid,
                'serie_folio' => $serie . '-' . $folio
            ]);
            
            return [
                'success' => true,
                'uuid' => $uuid,
                'pdf_url' => $pdf_url,
                'xml_url' => $xml_url,
                'serie' => $serie,
                'folio' => $folio,
                'message' => 'CFDI generado exitosamente via API real'
            ];
        }
        
        /**
         * Generar CFDI demo para pruebas
         */
        private function generate_demo_cfdi($order, $cfdi_data) {
            // Simular respuesta de la API
            $fake_uuid = $this->generate_fake_uuid();
            $fake_serie = 'DEMO';
            $fake_folio = rand(1000, 9999);
            
            // URLs demo
            $demo_pdf_url = 'https://factura.com/demo/cfdi/' . $fake_uuid . '.pdf';
            $demo_xml_url = 'https://factura.com/demo/cfdi/' . $fake_uuid . '.xml';
            
            // Guardar datos demo
            $this->save_cfdi_data($order, [
                'uuid' => $fake_uuid,
                'pdf_url' => $demo_pdf_url,
                'xml_url' => $demo_xml_url,
                'serie' => $fake_serie,
                'folio' => $fake_folio,
                'demo_data' => $cfdi_data,
                'generated_at' => current_time('mysql'),
                'environment' => 'demo'
            ]);
            
            // Agregar nota al pedido
            $order->add_order_note(
                sprintf(
                    'CFDI DEMO generado.\nUUID: %s\nSerie-Folio: %s-%s\nModo: Demostración\n\nPara generar CFDIs reales:\n1. Configura credenciales de Factura.com\n2. Desactiva modo demo',
                    $fake_uuid,
                    $fake_serie,
                    $fake_folio
                )
            );
            
            return [
                'success' => true,
                'uuid' => $fake_uuid,
                'pdf_url' => $demo_pdf_url,
                'xml_url' => $demo_xml_url,
                'serie' => $fake_serie,
                'folio' => $fake_folio,
                'message' => 'CFDI demo generado. Configura la API real para CFDIs válidos.'
            ];
        }
        
        /**
         * Guardar datos del CFDI en el pedido
         */
        private function save_cfdi_data($order, $cfdi_data) {
            $order->update_meta_data('_factura_com_cfdi_uuid', $cfdi_data['uuid']);
            $order->update_meta_data('_factura_com_cfdi_pdf_url', $cfdi_data['pdf_url']);
            $order->update_meta_data('_factura_com_cfdi_xml_url', $cfdi_data['xml_url'] ?? '');
            $order->update_meta_data('_factura_com_cfdi_serie', $cfdi_data['serie']);
            $order->update_meta_data('_factura_com_cfdi_folio', $cfdi_data['folio']);
            $order->update_meta_data('_factura_com_cfdi_generated_at', $cfdi_data['generated_at']);
            $order->update_meta_data('_factura_com_cfdi_environment', $cfdi_data['environment']);
            
            if (isset($cfdi_data['api_response'])) {
                $order->update_meta_data('_factura_com_api_response', json_encode($cfdi_data['api_response']));
            }
            
            if (isset($cfdi_data['demo_data'])) {
                $order->update_meta_data('_factura_com_demo_data', json_encode($cfdi_data['demo_data']));
            }
            
            $order->save();
        }
        
        /**
         * Verificar configuración del plugin
         */
        private function check_configuration() {
            $demo_mode = get_option('woo_factura_com_demo_mode', 'yes');
            
            if ($demo_mode === 'yes') {
                return ['valid' => true, 'message' => 'Modo demo activo'];
            }
            
            // Verificar credenciales para modo real
            $api_key = get_option('woo_factura_com_api_key');
            $secret_key = get_option('woo_factura_com_secret_key');
            $serie_id = get_option('woo_factura_com_serie_id');
            
            if (empty($api_key)) {
                return ['valid' => false, 'message' => 'API Key no configurada'];
            }
            
            if (empty($secret_key)) {
                return ['valid' => false, 'message' => 'Secret Key no configurada'];
            }
            
            if (empty($serie_id)) {
                return ['valid' => false, 'message' => 'Serie ID no configurada'];
            }
            
            return ['valid' => true, 'message' => 'Configuración válida'];
        }
