<?php
/**
 * Gestor de CFDIs mejorado para Factura.com
 * Implementación completa según documentación oficial
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
            try {
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
                
                // Log inicio del proceso
                $this->log_info('Iniciando generación de CFDI', [
                    'order_id' => $order_id,
                    'order_number' => $order->get_order_number(),
                    'total' => $order->get_total()
                ]);
                
                // Preparar datos del CFDI
                $cfdi_preparation = $this->api_client->prepare_cfdi_data($order);
                if (!$cfdi_preparation['success']) {
                    return $cfdi_preparation;
                }
                
                $cfdi_data = $cfdi_preparation['data'];
                
                // Log de datos preparados
                $this->log_debug('Datos CFDI preparados', [
                    'order_id' => $order_id,
                    'conceptos_count' => count($cfdi_data['Conceptos']),
                    'serie' => $cfdi_data['Serie'],
                    'receptor_uid' => $cfdi_data['Receptor']['UID']
                ]);
                
                // Determinar modo de operación
                $demo_mode = get_option('woo_factura_com_demo_mode', 'yes');
                
                if ($demo_mode === 'yes') {
                    return $this->generate_demo_cfdi($order, $cfdi_data);
                } else {
                    return $this->generate_real_cfdi($order, $cfdi_data);
                }
                
            } catch (Exception $e) {
                $this->log_error('Excepción en generación de CFDI', [
                    'order_id' => $order_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return ['success' => false, 'error' => 'Error interno: ' . $e->getMessage()];
            }
        }
        
        /**
         * Generar CFDI real usando la API de Factura.com
         */
        private function generate_real_cfdi($order, $cfdi_data) {
            $order_id = $order->get_id();
            
            $this->log_info('Generando CFDI real via API', [
                'order_id' => $order_id,
                'environment' => get_option('woo_factura_com_sandbox_mode') === 'yes' ? 'sandbox' : 'production'
            ]);
            
            // Crear CFDI usando la API real
            $response = $this->api_client->create_cfdi_40($cfdi_data);
            
            if (!$response['success']) {
                $this->log_error('Error al generar CFDI real', [
                    'order_id' => $order_id,
                    'error' => $response['error'],
                    'cfdi_data' => $cfdi_data
                ]);
                
                return ['success' => false, 'error' => 'Error API: ' . $response['error']];
            }
            
            $cfdi_response = $response['data'];
            
            // Validar respuesta según documentación de Factura.com
            if ($cfdi_response['response'] !== 'success') {
                $error_msg = $cfdi_response['message'] ?? 'Error desconocido';
                if (is_array($error_msg)) {
                    $error_msg = $error_msg['message'] ?? 'Error en respuesta de API';
                }
                
                $this->log_error('API devolvió error', [
                    'order_id' => $order_id,
                    'api_response' => $cfdi_response
                ]);
                
                return ['success' => false, 'error' => 'Error API: ' . $error_msg];
            }
            
            // Extraer datos del CFDI según formato de Factura.com
            $uuid = $cfdi_response['UUID'] ?? null;
            $serie = $cfdi_response['INV']['Serie'] ?? '';
            $folio = $cfdi_response['INV']['Folio'] ?? '';
            $fecha_timbrado = $cfdi_response['SAT']['FechaTimbrado'] ?? '';
            $no_certificado_sat = $cfdi_response['SAT']['NoCertificadoSAT'] ?? '';
            $sello_sat = $cfdi_response['SAT']['SelloSAT'] ?? '';
            $sello_cfd = $cfdi_response['SAT']['SelloCFD'] ?? '';
            
            if (!$uuid || $uuid === 'sin_uuid') {
                return ['success' => false, 'error' => 'UUID no recibido de la API'];
            }
            
            // Construir URLs de descarga (según estructura común de Factura.com)
            $base_url = get_option('woo_factura_com_sandbox_mode') === 'yes' 
                ? 'https://sandbox.factura.com' 
                : 'https://factura.com';
            
            $pdf_url = $cfdi_response['pdf_url'] ?? ($base_url . '/cfdi/pdf/' . $uuid);
            $xml_url = $cfdi_response['xml_url'] ?? ($base_url . '/cfdi/xml/' . $uuid);
            
            // Guardar todos los datos en el pedido
            $cfdi_meta_data = [
                'uuid' => $uuid,
                'pdf_url' => $pdf_url,
                'xml_url' => $xml_url,
                'serie' => $serie,
                'folio' => $folio,
                'fecha_timbrado' => $fecha_timbrado,
                'no_certificado_sat' => $no_certificado_sat,
                'sello_sat' => $sello_sat,
                'sello_cfd' => $sello_cfd,
                'api_response' => $cfdi_response,
                'generated_at' => current_time('mysql'),
                'environment' => get_option('woo_factura_com_sandbox_mode') === 'yes' ? 'sandbox' : 'production',
                'cfdi_data_sent' => $cfdi_data
            ];
            
            $this->save_cfdi_data($order, $cfdi_meta_data);
            
            // Agregar nota detallada al pedido
            $order->add_order_note(
                sprintf(
                    "CFDI generado exitosamente via Factura.com API.\n\n" .
                    "UUID: %s\n" .
                    "Serie-Folio: %s-%s\n" .
                    "Fecha Timbrado: %s\n" .
                    "Entorno: %s\n" .
                    "Certificado SAT: %s",
                    $uuid,
                    $serie,
                    $folio,
                    $fecha_timbrado,
                    get_option('woo_factura_com_sandbox_mode') === 'yes' ? 'Sandbox' : 'Producción',
                    $no_certificado_sat
                )
            );
            
            // Enviar email al cliente si está habilitado
            if (get_option('woo_factura_com_send_email', 'yes') === 'yes') {
                $email_result = $this->send_cfdi_email($order, [
                    'uuid' => $uuid,
                    'pdf_url' => $pdf_url,
                    'xml_url' => $xml_url,
                    'serie' => $serie,
                    'folio' => $folio
                ]);
                
                if ($email_result) {
                    $this->log_info('Email CFDI enviado exitosamente', ['order_id' => $order_id]);
                } else {
                    $this->log_error('Error enviando email CFDI', ['order_id' => $order_id]);
                }
            }
            
            $this->log_info('CFDI generado exitosamente', [
                'order_id' => $order_id,
                'uuid' => $uuid,
                'serie_folio' => $serie . '-' . $folio,
                'environment' => get_option('woo_factura_com_sandbox_mode') === 'yes' ? 'sandbox' : 'production'
            ]);
            
            return [
                'success' => true,
                'uuid' => $uuid,
                'pdf_url' => $pdf_url,
                'xml_url' => $xml_url,
                'serie' => $serie,
                'folio' => $folio,
                'fecha_timbrado' => $fecha_timbrado,
                'message' => 'CFDI generado exitosamente via API de Factura.com'
            ];
        }
        
        /**
         * Generar CFDI demo para pruebas
         */
        private function generate_demo_cfdi($order, $cfdi_data) {
            $order_id = $order->get_id();
            
            $this->log_info('Generando CFDI demo', ['order_id' => $order_id]);
            
            // Simular respuesta de la API con datos realistas
            $fake_uuid = $this->generate_fake_uuid();
            $fake_serie = 'DEMO';
            $fake_folio = rand(1000, 9999);
            $fake_fecha = current_time('Y-m-d\TH:i:s');
            $fake_certificado = '30001000000400002330';
            
            // URLs demo
            $demo_pdf_url = 'https://factura.com/demo/cfdi/' . $fake_uuid . '.pdf';
            $demo_xml_url = 'https://factura.com/demo/cfdi/' . $fake_uuid . '.xml';
            
            // Simular respuesta completa de API
            $fake_api_response = [
                'response' => 'success',
                'message' => 'CFDI demo generado satisfactoriamente',
                'UUID' => $fake_uuid,
                'uid' => 'demo_' . time(),
                'SAT' => [
                    'UUID' => $fake_uuid,
                    'FechaTimbrado' => $fake_fecha,
                    'NoCertificadoSAT' => $fake_certificado,
                    'Version' => '1.1',
                    'SelloSAT' => 'SelloSAT_demo_' . md5($fake_uuid),
                    'SelloCFD' => 'SelloCFD_demo_' . md5($fake_uuid . 'CFD')
                ],
                'INV' => [
                    'Serie' => $fake_serie,
                    'Folio' => $fake_folio
                ],
                'invoice_uid' => 'demo_' . time()
            ];
            
            // Guardar datos demo
            $cfdi_meta_data = [
                'uuid' => $fake_uuid,
                'pdf_url' => $demo_pdf_url,
                'xml_url' => $demo_xml_url,
                'serie' => $fake_serie,
                'folio' => $fake_folio,
                'fecha_timbrado' => $fake_fecha,
                'no_certificado_sat' => $fake_certificado,
                'sello_sat' => $fake_api_response['SAT']['SelloSAT'],
                'sello_cfd' => $fake_api_response['SAT']['SelloCFD'],
                'demo_data' => $cfdi_data,
                'api_response' => $fake_api_response,
                'generated_at' => current_time('mysql'),
                'environment' => 'demo'
            ];
            
            $this->save_cfdi_data($order, $cfdi_meta_data);
            
            // Agregar nota explicativa al pedido
            $order->add_order_note(
                sprintf(
                    "CFDI DEMO generado para pruebas.\n\n" .
                    "UUID: %s\n" .
                    "Serie-Folio: %s-%s\n" .
                    "Fecha: %s\n" .
                    "Modo: Demostración\n\n" .
                    "⚠️ ESTE CFDI NO TIENE VALIDEZ FISCAL\n\n" .
                    "Para generar CFDIs reales:\n" .
                    "1. Configura credenciales de Factura.com\n" .
                    "2. Desactiva modo demo en configuración",
                    $fake_uuid,
                    $fake_serie,
                    $fake_folio,
                    $fake_fecha
                )
            );
            
            $this->log_info('CFDI demo generado', [
                'order_id' => $order_id,
                'uuid' => $fake_uuid,
                'serie_folio' => $fake_serie . '-' . $fake_folio
            ]);
            
            return [
                'success' => true,
                'uuid' => $fake_uuid,
                'pdf_url' => $demo_pdf_url,
                'xml_url' => $demo_xml_url,
                'serie' => $fake_serie,
                'folio' => $fake_folio,
                'fecha_timbrado' => $fake_fecha,
                'message' => 'CFDI demo generado. Configura la API real para CFDIs válidos.'
            ];
        }
        
        /**
         * Cancelar CFDI
         */
        public function cancel_cfdi($order_id, $cancellation_reason = '02') {
            try {
                $order = wc_get_order($order_id);
                if (!$order) {
                    return ['success' => false, 'error' => 'Pedido no encontrado'];
                }
                
                $uuid = $order->get_meta('_factura_com_cfdi_uuid');
                if (!$uuid) {
                    return ['success' => false, 'error' => 'Este pedido no tiene CFDI para cancelar'];
                }
                
                // Verificar si ya está cancelado
                if ($order->get_meta('_factura_com_cfdi_cancelled')) {
                    return ['success' => false, 'error' => 'Este CFDI ya está cancelado'];
                }
                
                $demo_mode = get_option('woo_factura_com_demo_mode', 'yes');
                
                $this->log_info('Iniciando cancelación de CFDI', [
                    'order_id' => $order_id,
                    'uuid' => $uuid,
                    'reason' => $cancellation_reason,
                    'demo_mode' => $demo_mode
                ]);
                
                if ($demo_mode === 'yes') {
                    // Simular cancelación en demo
                    $order->update_meta_data('_factura_com_cfdi_cancelled', current_time('mysql'));
                    $order->update_meta_data('_factura_com_cfdi_cancel_reason', $cancellation_reason);
                    $order->update_meta_data('_factura_com_cfdi_cancel_method', 'demo');
                    $order->save();
                    
                    $order->add_order_note(
                        sprintf(
                            'CFDI DEMO cancelado.\n\nUUID: %s\nMotivo: %s\nFecha: %s\n\n⚠️ Cancelación simulada (modo demo)',
                            $uuid,
                            $this->get_cancellation_reason_description($cancellation_reason),
                            current_time('Y-m-d H:i:s')
                        )
                    );
                    
                    $this->log_info('CFDI demo cancelado', ['order_id' => $order_id, 'uuid' => $uuid]);
                    
                    return ['success' => true, 'message' => 'CFDI demo cancelado exitosamente'];
                } else {
                    // Cancelar via API real
                    $response = $this->api_client->cancel_cfdi($uuid, $cancellation_reason);
                    
                    if ($response['success']) {
                        $order->update_meta_data('_factura_com_cfdi_cancelled', current_time('mysql'));
                        $order->update_meta_data('_factura_com_cfdi_cancel_reason', $cancellation_reason);
                        $order->update_meta_data('_factura_com_cfdi_cancel_method', 'api');
                        $order->update_meta_data('_factura_com_cfdi_cancel_response', json_encode($response['data']));
                        $order->save();
                        
                        $order->add_order_note(
                            sprintf(
                                'CFDI cancelado via API de Factura.com.\n\nUUID: %s\nMotivo: %s\nFecha: %s',
                                $uuid,
                                $this->get_cancellation_reason_description($cancellation_reason),
                                current_time('Y-m-d H:i:s')
                            )
                        );
                        
                        $this->log_info('CFDI cancelado via API', [
                            'order_id' => $order_id,
                            'uuid' => $uuid,
                            'reason' => $cancellation_reason
                        ]);
                        
                        return ['success' => true, 'message' => 'CFDI cancelado exitosamente'];
                    } else {
                        $this->log_error('Error cancelando CFDI via API', [
                            'order_id' => $order_id,
                            'uuid' => $uuid,
                            'error' => $response['error']
                        ]);
                        
                        return ['success' => false, 'error' => 'Error al cancelar: ' . $response['error']];
                    }
                }
                
            } catch (Exception $e) {
                $this->log_error('Excepción cancelando CFDI', [
                    'order_id' => $order_id,
                    'error' => $e->getMessage()
                ]);
                
                return ['success' => false, 'error' => 'Error interno: ' . $e->getMessage()];
            }
        }
        
        /**
         * Regenerar CFDI (cancelar el actual y crear uno nuevo)
         */
        public function regenerate_cfdi($order_id) {
            try {
                $order = wc_get_order($order_id);
                if (!$order) {
                    return ['success' => false, 'error' => 'Pedido no encontrado'];
                }
                
                $this->log_info('Iniciando regeneración de CFDI', ['order_id' => $order_id]);
                
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
                $result = $this->generate_cfdi_for_order($order_id);
                
                if ($result['success']) {
                    $this->log_info('CFDI regenerado exitosamente', [
                        'order_id' => $order_id,
                        'new_uuid' => $result['uuid']
                    ]);
                }
                
                return $result;
                
            } catch (Exception $e) {
                $this->log_error('Excepción regenerando CFDI', [
                    'order_id' => $order_id,
                    'error' => $e->getMessage()
                ]);
                
                return ['success' => false, 'error' => 'Error interno: ' . $e->getMessage()];
            }
        }
        
        /**
         * Guardar datos del CFDI en el pedido
         */
        private function save_cfdi_data($order, $cfdi_data) {
            // Datos básicos del CFDI
            $order->update_meta_data('_factura_com_cfdi_uuid', $cfdi_data['uuid']);
            $order->update_meta_data('_factura_com_cfdi_pdf_url', $cfdi_data['pdf_url']);
            $order->update_meta_data('_factura_com_cfdi_xml_url', $cfdi_data['xml_url'] ?? '');
            $order->update_meta_data('_factura_com_cfdi_serie', $cfdi_data['serie']);
            $order->update_meta_data('_factura_com_cfdi_folio', $cfdi_data['folio']);
            $order->update_meta_data('_factura_com_cfdi_generated_at', $cfdi_data['generated_at']);
            $order->update_meta_data('_factura_com_cfdi_environment', $cfdi_data['environment']);
            
            // Datos adicionales del SAT (si están disponibles)
            if (isset($cfdi_data['fecha_timbrado'])) {
                $order->update_meta_data('_factura_com_cfdi_fecha_timbrado', $cfdi_data['fecha_timbrado']);
            }
            if (isset($cfdi_data['no_certificado_sat'])) {
                $order->update_meta_data('_factura_com_cfdi_no_certificado_sat', $cfdi_data['no_certificado_sat']);
            }
            if (isset($cfdi_data['sello_sat'])) {
                $order->update_meta_data('_factura_com_cfdi_sello_sat', $cfdi_data['sello_sat']);
            }
            if (isset($cfdi_data['sello_cfd'])) {
                $order->update_meta_data('_factura_com_cfdi_sello_cfd', $cfdi_data['sello_cfd']);
            }
            
            // Respuesta completa de la API (para debugging)
            if (isset($cfdi_data['api_response'])) {
                $order->update_meta_data('_factura_com_api_response', json_encode($cfdi_data['api_response'], JSON_UNESCAPED_UNICODE));
            }
            
            // Datos enviados para generar el CFDI (para auditoría)
            if (isset($cfdi_data['cfdi_data_sent'])) {
                $order->update_meta_data('_factura_com_cfdi_data_sent', json_encode($cfdi_data['cfdi_data_sent'], JSON_UNESCAPED_UNICODE));
            }
            
            // Datos demo (si aplica)
            if (isset($cfdi_data['demo_data'])) {
                $order->update_meta_data('_factura_com_demo_data', json_encode($cfdi_data['demo_data'], JSON_UNESCAPED_UNICODE));
            }
            
            $order->save();
        }
        
        /**
         * Limpiar datos del CFDI
         */
        private function clear_cfdi_data($order) {
            $meta_keys = [
                '_factura_com_cfdi_uuid',
                '_factura_com_cfdi_pdf_url',
                '_factura_com_cfdi_xml_url',
                '_factura_com_cfdi_serie',
                '_factura_com_cfdi_folio',
                '_factura_com_cfdi_generated_at',
                '_factura_com_cfdi_environment',
                '_factura_com_cfdi_fecha_timbrado',
                '_factura_com_cfdi_no_certificado_sat',
                '_factura_com_cfdi_sello_sat',
                '_factura_com_cfdi_sello_cfd',
                '_factura_com_api_response',
                '_factura_com_cfdi_data_sent',
                '_factura_com_demo_data',
                '_factura_com_cfdi_cancelled',
                '_factura_com_cfdi_cancel_reason',
                '_factura_com_cfdi_cancel_method',
                '_factura_com_cfdi_cancel_response'
            ];
            
            foreach ($meta_keys as $key) {
                $order->delete_meta_data($key);
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
                return ['valid' => false, 'message' => 'F-Api-Key no configurada. Ve a WooCommerce → Factura.com para configurar.'];
            }
            
            if (empty($secret_key)) {
                return ['valid' => false, 'message' => 'F-Secret-Key no configurada. Ve a WooCommerce → Factura.com para configurar.'];
            }
            
            if (empty($serie_id)) {
                return ['valid' => false, 'message' => 'Serie ID no configurada. Ve a WooCommerce → Factura.com para configurar.'];
            }
            
            // Verificar longitud mínima de credenciales
            if (strlen($api_key) < 10) {
                return ['valid' => false, 'message' => 'F-Api-Key parece inválida (muy corta)'];
            }
            
            if (strlen($secret_key) < 10) {
                return ['valid' => false, 'message' => 'F-Secret-Key parece inválida (muy corta)'];
            }
            
            return ['valid' => true, 'message' => 'Configuración válida'];
        }
        
        /**
         * Enviar email con CFDI al cliente
         */
        private function send_cfdi_email($order, $cfdi_data) {
            try {
                $to = $order->get_billing_email();
                if (empty($to)) {
                    $this->log_error('No se puede enviar email: email del cliente vacío', ['order_id' => $order->get_id()]);
                    return false;
                }
                
                $subject = sprintf(
                    'Tu Factura Electrónica (CFDI) - Pedido #%s',
                    $order->get_order_number()
                );
                
                // Preparar variables para la plantilla
                $template_vars = [
                    'order' => $order,
                    'cfdi_data' => $cfdi_data,
                    'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'company_name' => get_bloginfo('name'),
                    'site_url' => get_bloginfo('url')
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
                    $order->add_order_note('Email con CFDI enviado exitosamente a: ' . $to);
                    $this->log_info('Email CFDI enviado', [
                        'order_id' => $order->get_id(),
                        'email' => $to,
                        'uuid' => $cfdi_data['uuid']
                    ]);
                } else {
                    $this->log_error('Error enviando email CFDI', [
                        'order_id' => $order->get_id(),
                        'email' => $to
                    ]);
                }
                
                return $sent;
                
            } catch (Exception $e) {
                $this->log_error('Excepción enviando email CFDI', [
                    'order_id' => $order->get_id(),
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        }
        
        /**
         * Renderizar plantilla de email mejorada
         */
        private function render_email_template($vars) {
            extract($vars);
            $rfc = $order->get_meta('_billing_rfc') ?: 'XAXX010101000';
            ?>
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Tu Factura Electrónica</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
                    .container { max-width: 600px; margin: 0 auto; background-color: white; }
                    .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center; }
                    .header h1 { margin: 0; font-size: 28px; font-weight: 300; }
                    .content { padding: 30px 20px; }
                    .cfdi-info { background: #f8f9fa; padding: 20px; margin: 20px 0; border-left: 4px solid #667eea; border-radius: 4px; }
                    .cfdi-info h3 { margin-top: 0; color: #495057; }
                    .uuid-box { background: #e9ecef; padding: 15px; border-radius: 6px; font-family: 'Courier New', monospace; word-break: break-all; font-size: 14px; margin: 10px 0; }
                    .button { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; margin: 10px 5px; font-weight: 500; transition: background-color 0.3s; }
                    .button:hover { background: #5a6fd8; }
                    .button.secondary { background: #6c757d; }
                    .button.secondary:hover { background: #5a6268; }
                    .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 14px; color: #6c757d; border-top: 1px solid #dee2e6; }
                    .order-details { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 15px 0; }
                    .order-detail { text-align: center; padding: 15px; background: white; border: 1px solid #dee2e6; border-radius: 6px; }
                    .order-detail .label { font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
                    .order-detail .value { font-size: 18px; font-weight: 600; color: #495057; margin-top: 5px; }
                    .important-notice { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin: 20px 0; }
                    .important-notice strong { color: #856404; }
                    @media (max-width: 600px) {
                        .order-details { grid-template-columns: 1fr; }
                        .button { display: block; margin: 10px 0; text-align: center; }
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>🧾 Tu Factura Electrónica (CFDI)</h1>
                        <p style="margin: 10px 0 0 0; opacity: 0.9;">Comprobante Fiscal Digital por Internet</p>
                    </div>
                    
                    <div class="content">
                        <p>Estimado/a <strong><?php echo esc_html($customer_name ?: 'Cliente'); ?></strong>,</p>
                        
                        <p>Tu factura electrónica (CFDI) ha sido generada exitosamente. Este comprobante tiene validez fiscal ante el SAT y puedes utilizarlo para tus deducciones fiscales.</p>
                        
                        <div class="order-details">
                            <div class="order-detail">
                                <div class="label">Pedido</div>
                                <div class="value">#<?php echo $order->get_order_number(); ?></div>
                            </div>
                            <div class="order-detail">
                                <div class="label">Total</div>
                                <div class="value"><?php echo $order->get_formatted_order_total(); ?></div>
                            </div>
                            <div class="order-detail">
                                <div class="label">Fecha</div>
                                <div class="value"><?php echo $order->get_date_created()->format('d/m/Y'); ?></div>
                            </div>
                            <div class="order-detail">
                                <div class="label">RFC</div>
                                <div class="value"><?php echo esc_html($rfc); ?></div>
                            </div>
                        </div>
                        
                        <div class="cfdi-info">
                            <h3>🔐 Datos del CFDI</h3>
                            <?php if (isset($cfdi_data['serie']) && isset($cfdi_data['folio'])): ?>
                                <p><strong>Serie-Folio:</strong> <?php echo esc_html($cfdi_data['serie'] . '-' . $cfdi_data['folio']); ?></p>
                            <?php endif; ?>
                            <p><strong>UUID (Folio Fiscal):</strong></p>
                            <div class="uuid-box"><?php echo esc_html($cfdi_data['uuid']); ?></div>
                            <p style="font-size: 14px; color: #6c757d; margin-top: 10px;">
                                <strong>Importante:</strong> Conserva este UUID para tus registros fiscales y declaraciones.
                            </p>
                        </div>
                        
                        <div style="text-align: center; margin: 30px 0;">
                            <h3 style="color: #495057;">📥 Descarga tus archivos</h3>
                            <?php if (!empty($cfdi_data['pdf_url'])): ?>
                                <a href="<?php echo esc_url($cfdi_data['pdf_url']); ?>" class="button">📄 Descargar PDF</a>
                            <?php endif; ?>
                            
                            <?php if (!empty($cfdi_data['xml_url'])): ?>
                                <a href="<?php echo esc_url($cfdi_data['xml_url']); ?>" class="button secondary">📁 Descargar XML</a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="important-notice">
                            <p><strong>📋 ¿Para qué sirven estos archivos?</strong></p>
                            <ul style="margin: 10px 0; padding-left: 20px;">
                                <li><strong>PDF:</strong> Para imprimir y archivo personal</li>
                                <li><strong>XML:</strong> Para cargar en tu sistema contable y declaraciones</li>
                            </ul>
                        </div>
                        
                        <p>Si tienes alguna pregunta sobre tu factura o necesitas algún cambio, no dudes en contactarnos.</p>
                        
                        <p>¡Gracias por tu preferencia!</p>
                    </div>
                    
                    <div class="footer">
                        <p><strong><?php echo esc_html($company_name); ?></strong></p>
                        <p><?php echo esc_url($site_url); ?></p>
                        <p style="margin-top: 15px; font-size: 12px;">
                            Este email contiene información fiscal importante. Conserva este mensaje para tus registros.
                        </p>
                    </div>
                </div>
            </body>
            </html>
            <?php
        }
        
        /**
         * Obtener descripción del motivo de cancelación
         */
        private function get_cancellation_reason_description($reason) {
            $reasons = [
                '01' => 'Comprobante emitido con errores con relación',
                '02' => 'Comprobante emitido con errores sin relación',
                '03' => 'No se llevó a cabo la operación',
                '04' => 'Operación nominativa relacionada en una factura global'
            ];
            
            return $reasons[$reason] ?? 'Motivo: ' . $reason;
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
         * Log de información
         */
        private function log_info($message, $context = []) {
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->info($message . ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE), ['source' => 'woo-factura-com-cfdi']);
            } else {
                error_log('WooFacturaCom CFDI Info: ' . $message . ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE));
            }
        }
        
        /**
         * Log de errores
         */
        private function log_error($message, $context = []) {
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->error($message . ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE), ['source' => 'woo-factura-com-cfdi']);
            } else {
                error_log('WooFacturaCom CFDI Error: ' . $message . ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE));
            }
        }
        
        /**
         * Log de debug
         */
        private function log_debug($message, $context = []) {
            if (get_option('woo_factura_com_debug_mode') === 'yes') {
                if (function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->debug($message . ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE), ['source' => 'woo-factura-com-cfdi']);
                } else {
                    error_log('WooFacturaCom CFDI Debug: ' . $message . ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE));
                }
            }
        }
    }
}
