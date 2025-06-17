<?php
/**
 * Cliente real para la API de Factura.com
 * Basado en la documentación oficial v4
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WooFacturaComRealAPIClient')) {
    
    class WooFacturaComRealAPIClient {
        
        private $api_key;
        private $secret_key;
        private $sandbox_mode;
        private $production_url = 'https://api.factura.com';
        private $sandbox_url = 'https://sandbox.factura.com/api';
        
        public function __construct() {
            $this->api_key = get_option('woo_factura_com_api_key');
            $this->secret_key = get_option('woo_factura_com_secret_key');
            $this->sandbox_mode = get_option('woo_factura_com_sandbox_mode', 'yes') === 'yes';
        }
        
        /**
         * Crear CFDI 4.0 usando la API real de Factura.com
         */
        public function create_cfdi_40($cfdi_data) {
            $endpoint = '/v4/cfdi40/create';
            
            // Validar datos requeridos
            $validation = $this->validate_cfdi_data($cfdi_data);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['message']];
            }
            
            return $this->make_request('POST', $endpoint, $cfdi_data);
        }
        
        /**
         * Crear cliente en Factura.com
         */
        public function create_client($client_data) {
            $endpoint = '/v1/clients/create';
            return $this->make_request('POST', $endpoint, $client_data);
        }
        
        /**
         * Obtener cliente por RFC
         */
        public function get_client_by_rfc($rfc) {
            $endpoint = '/v1/clients';
            $params = ['filters' => ['rfc' => $rfc]];
            return $this->make_request('GET', $endpoint, $params);
        }
        
        /**
         * Listar CFDIs
         */
        public function list_cfdis($filters = []) {
            $endpoint = '/v1/invoices';
            return $this->make_request('GET', $endpoint, $filters);
        }
        
        /**
         * Obtener CFDI por UUID
         */
        public function get_cfdi($uuid) {
            $endpoint = '/v1/invoices/' . $uuid;
            return $this->make_request('GET', $endpoint);
        }
        
        /**
         * Cancelar CFDI
         */
        public function cancel_cfdi($uuid, $cancellation_reason = '02') {
            $endpoint = '/v1/invoices/' . $uuid . '/cancel';
            $data = ['motive' => $cancellation_reason];
            return $this->make_request('POST', $endpoint, $data);
        }
        
        /**
         * Preparar datos del CFDI según el formato de Factura.com
         */
        public function prepare_cfdi_data($wc_order) {
            // Obtener configuraciones
            $serie_id = get_option('woo_factura_com_serie_id');
            $uso_cfdi = get_option('woo_factura_com_uso_cfdi', 'G01');
            $forma_pago = get_option('woo_factura_com_forma_pago', '01');
            $metodo_pago = get_option('woo_factura_com_metodo_pago', 'PUE');
            $clave_prod_serv = get_option('woo_factura_com_clave_prod_serv', '81112101');
            $lugar_expedicion = get_option('woo_factura_com_lugar_expedicion', '44650');
            
            // Obtener o crear cliente
            $rfc = $wc_order->get_meta('_billing_rfc') ?: 'XAXX010101000';
            $receptor_uid = $this->get_or_create_client($wc_order, $rfc);
            
            if (!$receptor_uid) {
                return ['success' => false, 'error' => 'No se pudo crear/obtener cliente'];
            }
            
            // Preparar conceptos
            $conceptos = $this->prepare_conceptos($wc_order, $clave_prod_serv);
            
            // Estructura del CFDI según Factura.com API v4
            $cfdi_data = [
                'Receptor' => [
                    'UID' => $receptor_uid
                ],
                'TipoDocumento' => 'factura',
                'BorradorSiFalla' => $this->sandbox_mode ? '1' : '0',
                'Conceptos' => $conceptos,
                'UsoCFDI' => $uso_cfdi,
                'Serie' => intval($serie_id),
                'FormaPago' => $forma_pago,
                'MetodoPago' => $metodo_pago,
                'Moneda' => $wc_order->get_currency(),
                'NumOrder' => $wc_order->get_order_number(),
                'EnviarCorreo' => true,
                'LugarExpedicion' => $lugar_expedicion,
                'Comentarios' => 'Generado desde WooCommerce - Pedido #' . $wc_order->get_order_number()
            ];
            
            // Agregar tipo de cambio si no es MXN
            if ($wc_order->get_currency() !== 'MXN') {
                $cfdi_data['TipoCambio'] = get_option('woo_factura_com_tipo_cambio', '20.00');
            }
            
            return ['success' => true, 'data' => $cfdi_data];
        }
        
        /**
         * Preparar conceptos del pedido
         */
        private function prepare_conceptos($wc_order, $clave_prod_serv) {
            $conceptos = [];
            $clave_unidad = get_option('woo_factura_com_clave_unidad', 'E48');
            $unidad = get_option('woo_factura_com_unidad', 'Unidad de servicio');
            $tasa_iva = floatval(get_option('woo_factura_com_tasa_iva', '0.16'));
            
            foreach ($wc_order->get_items() as $item) {
                $producto = $item->get_product();
                $cantidad = $item->get_quantity();
                $precio_unitario = floatval($item->get_subtotal()) / $cantidad;
                $importe = floatval($item->get_subtotal());
                
                // Calcular IVA
                $iva_importe = $importe * $tasa_iva;
                
                $concepto = [
                    'ClaveProdServ' => $clave_prod_serv,
                    'NoIdentificacion' => $producto->get_sku() ?: $producto->get_id(),
                    'Cantidad' => number_format($cantidad, 6, '.', ''),
                    'ClaveUnidad' => $clave_unidad,
                    'Unidad' => $unidad,
                    'Descripcion' => $item->get_name(),
                    'ValorUnitario' => number_format($precio_unitario, 6, '.', ''),
                    'Importe' => number_format($importe, 6, '.', ''),
                    'Descuento' => '0',
                    'ObjetoImp' => '02',
                    'Impuestos' => [
                        'Traslados' => [
                            [
                                'Base' => number_format($importe, 6, '.', ''),
                                'Impuesto' => '002',
                                'TipoFactor' => 'Tasa',
                                'TasaOCuota' => number_format($tasa_iva, 6, '.', ''),
                                'Importe' => number_format($iva_importe, 6, '.', '')
                            ]
                        ],
                        'Retenidos' => [],
                        'Locales' => []
                    ]
                ];
                
                $conceptos[] = $concepto;
            }
            
            // Agregar envío si existe
            if ($wc_order->get_shipping_total() > 0) {
                $envio_importe = floatval($wc_order->get_shipping_total());
                $envio_iva = $envio_importe * $tasa_iva;
                
                $conceptos[] = [
                    'ClaveProdServ' => '78102203', // Servicios de entrega
                    'Cantidad' => '1.000000',
                    'ClaveUnidad' => 'E48',
                    'Unidad' => 'Unidad de servicio',
                    'Descripcion' => 'Servicio de envío',
                    'ValorUnitario' => number_format($envio_importe, 6, '.', ''),
                    'Importe' => number_format($envio_importe, 6, '.', ''),
                    'Descuento' => '0',
                    'ObjetoImp' => '02',
                    'Impuestos' => [
                        'Traslados' => [
                            [
                                'Base' => number_format($envio_importe, 6, '.', ''),
                                'Impuesto' => '002',
                                'TipoFactor' => 'Tasa',
                                'TasaOCuota' => number_format($tasa_iva, 6, '.', ''),
                                'Importe' => number_format($envio_iva, 6, '.', '')
                            ]
                        ],
                        'Retenidos' => [],
                        'Locales' => []
                    ]
                ];
            }
            
            return $conceptos;
        }
        
        /**
         * Obtener o crear cliente en Factura.com
         */
        private function get_or_create_client($wc_order, $rfc) {
            // Primero intentar obtener cliente existente
            $existing_client = $this->get_client_by_rfc($rfc);
            
            if ($existing_client && $existing_client['success'] && !empty($existing_client['data'])) {
                return $existing_client['data'][0]['UID'];
            }
            
            // Si no existe, crear nuevo cliente
            $client_data = [
                'NombreFiscal' => $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name(),
                'RFC' => $rfc,
                'Email' => $wc_order->get_billing_email(),
                'Telefono' => $wc_order->get_billing_phone(),
                'Calle' => $wc_order->get_billing_address_1(),
                'Numero' => '',
                'Colonia' => $wc_order->get_billing_address_2(),
                'Municipio' => $wc_order->get_billing_city(),
                'Estado' => $wc_order->get_billing_state(),
                'CodigoPostal' => $wc_order->get_billing_postcode(),
                'Pais' => $wc_order->get_billing_country()
            ];
            
            $new_client = $this->create_client($client_data);
            
            if ($new_client && $new_client['success']) {
                return $new_client['data']['UID'];
            }
            
            return false;
        }
        
        /**
         * Validar datos del CFDI
         */
        private function validate_cfdi_data($cfdi_data) {
            $required_fields = ['Receptor', 'TipoDocumento', 'Conceptos', 'UsoCFDI', 'Serie', 'FormaPago', 'MetodoPago', 'Moneda'];
            
            foreach ($required_fields as $field) {
                if (!isset($cfdi_data[$field])) {
                    return ['valid' => false, 'message' => "Campo requerido faltante: $field"];
                }
            }
            
            // Validar receptor
            if (!isset($cfdi_data['Receptor']['UID'])) {
                return ['valid' => false, 'message' => 'UID del receptor es requerido'];
            }
            
            // Validar conceptos
            if (empty($cfdi_data['Conceptos'])) {
                return ['valid' => false, 'message' => 'Al menos un concepto es requerido'];
            }
            
            // Validar Serie
            if (!is_numeric($cfdi_data['Serie'])) {
                return ['valid' => false, 'message' => 'Serie debe ser un número'];
            }
            
            return ['valid' => true, 'message' => 'Datos válidos'];
        }
        
        /**
         * Realizar petición HTTP a la API
         */
        private function make_request($method, $endpoint, $data = null) {
            $url = $this->get_api_url() . $endpoint;
            
            $headers = [
                'Content-Type: application/json',
                'F-PLUGIN: 9d4095c8f7ed5785cb14c0e3b033eeb8252416ed',
                'F-Api-Key: ' . $this->api_key,
                'F-Secret-Key: ' . $this->secret_key
            ];
            
            $args = [
                'method' => $method,
                'headers' => $headers,
                'timeout' => 60,
                'sslverify' => !$this->sandbox_mode
            ];
            
            if ($data) {
                if ($method === 'GET') {
                    $url .= '?' . http_build_query($data);
                } else {
                    $args['body'] = json_encode($data);
                }
            }
            
            $response = wp_remote_request($url, $args);
            
            // Log de debug
            $this->log_debug('API Request', [
                'url' => $url,
                'method' => $method,
                'data' => $data,
                'sandbox' => $this->sandbox_mode
            ]);
            
            if (is_wp_error($response)) {
                $this->log_error('Request error: ' . $response->get_error_message());
                return ['success' => false, 'error' => $response->get_error_message()];
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            $this->log_debug('API Response', [
                'http_code' => $http_code,
                'body' => $body
            ]);
            
            if ($http_code >= 400) {
                $this->log_error('HTTP error ' . $http_code . ': ' . $body);
                return ['success' => false, 'error' => 'HTTP ' . $http_code . ': ' . $body];
            }
            
            $decoded = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log_error('JSON decode error: ' . json_last_error_msg());
                return ['success' => false, 'error' => 'Error al decodificar respuesta JSON'];
            }
            
            // Interpretar respuesta según formato de Factura.com
            if (isset($decoded['response'])) {
                if ($decoded['response'] === 'success') {
                    return ['success' => true, 'data' => $decoded];
                } else {
                    $error_msg = isset($decoded['message']) ? $decoded['message'] : 'Error desconocido';
                    if (is_array($error_msg)) {
                        $error_msg = $error_msg['message'] ?? 'Error en API';
                    }
                    return ['success' => false, 'error' => $error_msg];
                }
            }
            
            return ['success' => true, 'data' => $decoded];
        }
        
        /**
         * Obtener URL de la API según entorno
         */
        private function get_api_url() {
            return $this->sandbox_mode ? $this->sandbox_url : $this->production_url;
        }
        
        /**
         * Probar conexión con la API
         */
        public function test_connection() {
            $endpoint = '/v1/ping';
            $result = $this->make_request('GET', $endpoint);
            
            if ($result['success']) {
                return [
                    'success' => true,
                    'message' => 'Conexión exitosa con Factura.com',
                    'environment' => $this->sandbox_mode ? 'Sandbox' : 'Producción',
                    'api_url' => $this->get_api_url()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error de conexión: ' . $result['error']
                ];
            }
        }
        
        /**
         * Obtener información de la cuenta
         */
        public function get_account_info() {
            $endpoint = '/v1/user/account';
            return $this->make_request('GET', $endpoint);
        }
        
        /**
         * Listar series disponibles
         */
        public function get_series() {
            $endpoint = '/v1/series';
            return $this->make_request('GET', $endpoint);
        }
        
        /**
         * Log de errores
         */
        private function log_error($message) {
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->error($message, ['source' => 'woo-factura-com-api']);
            } else {
                error_log('WooFacturaCom API: ' . $message);
            }
        }
        
        /**
         * Log de debug
         */
        private function log_debug($title, $data) {
            if (get_option('woo_factura_com_debug_mode') === 'yes') {
                if (function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->debug($title . ': ' . print_r($data, true), ['source' => 'woo-factura-com-api']);
                } else {
                    error_log('WooFacturaCom API Debug - ' . $title . ': ' . print_r($data, true));
                }
            }
        }
    }
}
