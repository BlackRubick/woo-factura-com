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
        
        /**
         * Enviar email con CFDI al cliente
         */
        private function send_cfdi_email($order, $cfdi_data) {
            $to = $order->get_billing_email();
            $subject = 'Tu Factura Electrónica (CFDI) - Pedido #' . $order->get_order_number();
            
            // Preparar variables para la plantilla
            $template_vars = [
                'order' => $order,
                'cfdi_data' => $cfdi_data,
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
            ];
            
            // Generar contenido del email
            ob_start();
            $this->render_email_template($template_vars);
            $message = ob_get_clean();
            
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
            ];
            
            $sent = wp_mail($to, $subject, $message, $headers);
            
            if ($sent) {
                $order->add_order_note('Email con CFDI enviado a: ' . $to);
                $this->log_debug('Email CFDI enviado', ['order_id' => $order->get_id(), 'email' => $to]);
            } else {
                $this->log_error('Error al enviar email CFDI', ['order_id' => $order->get_id(), 'email' => $to]);
            }
            
            return $sent;
        }
        
        /**
         * Renderizar plantilla de email
         */
        private function render_email_template($vars) {
            extract($vars);
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Tu Factura Electrónica</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #0073aa; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background: #f9f9f9; }
                    .cfdi-info { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #0073aa; }
                    .button { display: inline-block; padding: 12px 24px; background: #0073aa; color: white; text-decoration: none; border-radius: 4px; margin: 10px 5px; }
                    .footer { background: #f1f1f1; padding: 15px; text-align: center; font-size: 12px; color: #666; }
                    .uuid { background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6; border-radius: 4px; font-family: monospace; word-break: break-all; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>🧾 Tu Factura Electrónica (CFDI)</h1>
                    </div>
                    
                    <div class="content">
                        <p>Hola <strong><?php echo esc_html($customer_name); ?></strong>,</p>
                        
                        <p>Tu factura electrónica (CFDI) ha sido generada exitosamente para tu pedido.</p>
                        
                        <div class="cfdi-info">
                            <h3>📋 Detalles de la Factura</h3>
                            <p><strong>Pedido:</strong> #<?php echo $order->get_order_number(); ?></p>
                            <p><strong>Fecha:</strong> <?php echo $order->get_date_created()->format('d/m/Y H:i'); ?></p>
                            <p><strong>Total:</strong> <?php echo $order->get_formatted_order_total(); ?></p>
                            <p><strong>RFC:</strong> <?php echo $order->get_meta('_billing_rfc') ?: 'XAXX010101000'; ?></p>
                        </div>
                        
                        <div class="cfdi-info">
                            <h3>🔐 UUID del CFDI</h3>
                            <div class="uuid"><?php echo esc_html($cfdi_data['uuid']); ?></div>
                            <p><small>Conserva este UUID para tus registros fiscales.</small></p>
                        </div>
                        
                        <div style="text-align: center; margin: 20px 0;">
                            <?php if (!empty($cfdi_data['pdf_url'])): ?>
                                <a href="<?php echo esc_url($cfdi_data['pdf_url']); ?>" class="button">📄 Descargar PDF</a>
                            <?php endif; ?>
                            
                            <?php if (!empty($cfdi_data['xml_url'])): ?>
                                <a href="<?php echo esc_url($cfdi_data['xml_url']); ?>" class="button">📁 Descargar XML</a>
                            <?php endif; ?>
                        </div>
                        
                        <p><strong>Importante:</strong> Conserva estos archivos para tus registros contables y declaraciones fiscales.</p>
                        
                        <p>Si tienes alguna pregunta sobre tu factura, no dudes en contactarnos.</p>
                        
                        <p>¡Gracias por tu compra!</p>
                    </div>
                    
                    <div class="footer">
                        <p><?php echo get_bloginfo('name'); ?></p>
                        <p><?php echo get_bloginfo('url'); ?></p>
                        <p>Este email contiene información fiscal importante.</p>
                    </div>
                </div>
            </body>
            </html>
            <?php
        }
        
        /**
         * Cancelar CFDI
         */
        public function cancel_cfdi($order_id, $reason = '02') {
            $order = wc_get_order($order_id);
            if (!$order) {
                return ['success' => false, 'error' => 'Pedido no encontrado'];
            }
            
            $uuid = $order->get_meta('_factura_com_cfdi_uuid');
            if (!$uuid) {
                return ['success' => false, 'error' => 'Este pedido no tiene CFDI para cancelar'];
            }
            
            $demo_mode = get_option('woo_factura_com_demo_mode', 'yes');
            
            if ($demo_mode === 'yes') {
                // Simular cancelación en demo
                $order->update_meta_data('_factura_com_cfdi_cancelled', current_time('mysql'));
                $order->update_meta_data('_factura_com_cfdi_cancel_reason', $reason);
                $order->save();
                
                $order->add_order_note('CFDI DEMO cancelado. Motivo: ' . $reason);
                
                return ['success' => true, 'message' => 'CFDI demo cancelado'];
            } else {
                // Cancelar via API real
                $response = $this->api_client->cancel_cfdi($uuid, $reason);
                
                if ($response['success']) {
                    $order->update_meta_data('_factura_com_cfdi_cancelled', current_time('mysql'));
                    $order->update_meta_data('_factura_com_cfdi_cancel_reason', $reason);
                    $order->save();
                    
                    $order->add_order_note('CFDI cancelado via API. UUID: ' . $uuid . ' | Motivo: ' . $reason);
                    
                    return ['success' => true, 'message' => 'CFDI cancelado exitosamente'];
                } else {
                    return ['success' => false, 'error' => 'Error al cancelar: ' . $response['error']];
                }
            }
        }
        
        /**
         * Regenerar CFDI
         */
        public function regenerate_cfdi($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                return ['success' => false, 'error' => 'Pedido no encontrado'];
            }
            
            // Si tiene CFDI, intentar cancelarlo primero
            $uuid = $order->get_meta('_factura_com_cfdi_uuid');
            if ($uuid) {
                $cancel_result = $this->cancel_cfdi($order_id, '01'); // Comprobante emitido con errores
                if (!$cancel_result['success']) {
                    return ['success' => false, 'error' => 'No se pudo cancelar CFDI anterior: ' . $cancel_result['error']];
                }
            }
            
            // Limpiar datos del CFDI anterior
            $this->clear_cfdi_data($order);
            
            // Generar nuevo CFDI
            return $this->generate_cfdi_for_order($order_id);
        }
        
        /**
         * Limpiar datos del CFDI
         */
        private function clear_cfdi_data($order) {
            $order->delete_meta_data('_factura_com_cfdi_uuid');
            $order->delete_meta_data('_factura_com_cfdi_pdf_url');
            $order->delete_meta_data('_factura_com_cfdi_xml_url');
            $order->delete_meta_data('_factura_com_cfdi_serie');
            $order->delete_meta_data('_factura_com_cfdi_folio');
            $order->delete_meta_data('_factura_com_cfdi_generated_at');
            $order->delete_meta_data('_factura_com_cfdi_environment');
            $order->delete_meta_data('_factura_com_api_response');
            $order->delete_meta_data('_factura_com_demo_data');
            $order->save();
        }
        
        /**
         * Generar UUID falso para demo
         */
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
        
        /**
         * Log de errores
         */
        private function log_error($message, $context = []) {
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->error($message . ' | Context: ' . print_r($context, true), ['source' => 'woo-factura-com-cfdi']);
            } else {
                error_log('WooFacturaCom CFDI: ' . $message . ' | ' . print_r($context, true));
            }
        }
        
        /**
         * Log de debug
         */
        private function log_debug($message, $context = []) {
            if (get_option('woo_factura_com_debug_mode') === 'yes') {
                if (function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->debug($message . ' | Context: ' . print_r($context, true), ['source' => 'woo-factura-com-cfdi']);
                } else {
                    error_log('WooFacturaCom CFDI Debug: ' . $message . ' | ' . print_r($context, true));
                }
            }
        }
    }
