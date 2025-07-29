<?php
/**
 * Importador de CSV para Football Tipster
 * Versión actualizada con soporte para cuotas de apuestas
 */

if (!defined('ABSPATH')) {
    exit;
}

class FT_CSV_Importer {
    
    private $db;
    private $table_name;
    private $xg_calculator;
    private $processed = 0;
    private $skipped = 0;
    private $errors = 0;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->table_name = $wpdb->prefix . 'ft_matches_advanced';
        
        // Cargar calculadora de xG si existe
        if (class_exists('FootballTipster_xG_Calculator')) {
            $this->xg_calculator = new FootballTipster_xG_Calculator();
        }
    }
    
    /**
     * Importar desde archivo
     */
    public function import_from_file($file_path) {
        if (!file_exists($file_path)) {
            return array('success' => false, 'error' => 'El archivo no existe');
        }
        
        if (!is_readable($file_path)) {
            return array('success' => false, 'error' => 'No se puede leer el archivo');
        }
        
        try {
            $handle = fopen($file_path, 'r');
            if (!$handle) {
                return array('success' => false, 'error' => 'No se pudo abrir el archivo');
            }
            
            // Leer encabezados
            $headers = fgetcsv($handle);
            if (!$headers) {
                fclose($handle);
                return array('success' => false, 'error' => 'El archivo está vacío o no es válido');
            }
            
            // Normalizar encabezados
            $headers = array_map('trim', $headers);
            $headers = array_map('strtolower', $headers);
            
            // Mapeo de columnas
            $column_map = $this->get_column_mapping($headers);
            
            // Procesar filas
            while (($data = fgetcsv($handle)) !== false) {
                $this->process_row($data, $column_map);
            }
            
            fclose($handle);
            
            $message = sprintf(
                'Importación completada: %d procesados, %d omitidos, %d errores',
                $this->processed,
                $this->skipped,
                $this->errors
            );
            
            // Calcular xG para partidos que no lo tienen
            if ($this->xg_calculator) {
                $xg_updated = $this->xg_calculator->update_missing_xG();
                if ($xg_updated > 0) {
                    $message .= sprintf(' (%d con xG calculado)', $xg_updated);
                }
            }
            
            return array(
                'success' => true,
                'message' => $message,
                'processed' => $this->processed,
                'skipped' => $this->skipped,
                'errors' => $this->errors
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Error procesando archivo: ' . $e->getMessage());
        }
    }
    
    /**
     * Importar desde URL
     */
    public function import_from_url($url) {
        try {
            // Descargar archivo
            $response = wp_remote_get($url, array('timeout' => 60));
            
            if (is_wp_error($response)) {
                return array('success' => false, 'error' => 'Error descargando archivo: ' . $response->get_error_message());
            }
            
            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                return array('success' => false, 'error' => 'El archivo descargado está vacío');
            }
            
            // Guardar temporalmente
            $temp_file = FT_PLUGIN_PATH . 'temp/import_' . time() . '.csv';
            
            if (!file_exists(dirname($temp_file))) {
                wp_mkdir_p(dirname($temp_file));
            }
            
            file_put_contents($temp_file, $body);
            
            // Importar
            $result = $this->import_from_file($temp_file);
            
            // Limpiar
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
            
            return $result;
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtener mapeo de columnas
     */
    private function get_column_mapping($headers) {
        $mapping = array();
        
        // Mapeo de nombres de columnas del CSV a campos de BD
        $field_map = array(
            // Información básica
            'div' => 'division',
            'date' => 'date',
            'time' => 'time',
            'hometeam' => 'home_team',
            'awayteam' => 'away_team',
            
            // Resultados
            'fthg' => 'fthg',
            'ftag' => 'ftag',
            'ftr' => 'ftr',
            'hthg' => 'hthg',
            'htag' => 'htag',
            'htr' => 'htr',
            
            // Estadísticas
            'hs' => 'hs',
            'as' => 'as_shots',
            'hst' => 'hst',
            'ast' => 'ast',
            'hhw' => 'hhw',
            'ahw' => 'ahw',
            'hc' => 'hc',
            'ac' => 'ac',
            'hf' => 'hf',
            'af' => 'af',
            'hfkc' => 'hfkc',
            'afkc' => 'afkc',
            'ho' => 'ho',
            'ao' => 'ao',
            'hy' => 'hy',
            'ay' => 'ay',
            'hr' => 'hr',
            'ar' => 'ar',
            'hbp' => 'hbp',
            'abp' => 'abp',
            
            // Otros
            'attendance' => 'attendance',
            'referee' => 'referee',
            
            // Cuotas Bet365
            'b365h' => 'b365h',
            'b365d' => 'b365d',
            'b365a' => 'b365a',
            'b365>2.5' => 'b365_over25',
            'b365<2.5' => 'b365_under25',
            
            // Cuotas Bet&Win
            'bwh' => 'bwh',
            'bwd' => 'bwd',
            'bwa' => 'bwa',
            
            // Cuotas Interwetten
            'iwh' => 'iwh',
            'iwd' => 'iwd',
            'iwa' => 'iwa',
            
            // Cuotas Pinnacle
            'psh' => 'psh',
            'psd' => 'psd',
            'psa' => 'psa',
            
            // Cuotas William Hill
            'whh' => 'whh',
            'whd' => 'whd',
            'wha' => 'wha',
            
            // Cuotas VC Bet
            'vch' => 'vch',
            'vcd' => 'vcd',
            'vca' => 'vca',
            
            // Cuotas promedio
            'avgh' => 'avgh',
            'avgd' => 'avgd',
            'avga' => 'avga'
        );
        
        // Crear mapeo basado en los headers del archivo
        foreach ($headers as $index => $header) {
            $header = strtolower(trim($header));
            if (isset($field_map[$header])) {
                $mapping[$field_map[$header]] = $index;
            }
        }
        
        return $mapping;
    }
    
    /**
     * Procesar una fila del CSV
     */
    private function process_row($data, $column_map) {
        try {
            // Extraer datos básicos requeridos
            $date = $this->get_value($data, $column_map, 'date');
            $home_team = $this->get_value($data, $column_map, 'home_team');
            $away_team = $this->get_value($data, $column_map, 'away_team');
            
            if (empty($date) || empty($home_team) || empty($away_team)) {
                $this->skipped++;
                return;
            }
            
            // Formatear fecha
            $date = $this->format_date($date);
            if (!$date) {
                $this->skipped++;
                return;
            }
            
            // Determinar temporada
            $season = $this->determine_season($date);
            
            // Verificar si ya existe
            if ($this->match_exists($date, $home_team, $away_team)) {
                // Actualizar si tiene nuevos datos (como cuotas)
                $this->update_match($date, $home_team, $away_team, $data, $column_map);
                $this->processed++; // Cambiar aquí
                return;
            }
            
            // Preparar datos para insertar
            $insert_data = array(
                'division' => $this->get_value($data, $column_map, 'division', ''),
                'date' => $date,
                'time' => $this->get_value($data, $column_map, 'time'),
                'home_team' => $home_team,
                'away_team' => $away_team,
                'fthg' => $this->get_numeric_value($data, $column_map, 'fthg'),
                'ftag' => $this->get_numeric_value($data, $column_map, 'ftag'),
                'ftr' => $this->get_value($data, $column_map, 'ftr'),
                'hthg' => $this->get_numeric_value($data, $column_map, 'hthg'),
                'htag' => $this->get_numeric_value($data, $column_map, 'htag'),
                'htr' => $this->get_value($data, $column_map, 'htr'),
                'attendance' => $this->get_numeric_value($data, $column_map, 'attendance'),
                'referee' => $this->get_value($data, $column_map, 'referee'),
                'hs' => $this->get_numeric_value($data, $column_map, 'hs'),
                'as_shots' => $this->get_numeric_value($data, $column_map, 'as_shots'),
                'hst' => $this->get_numeric_value($data, $column_map, 'hst'),
                'ast' => $this->get_numeric_value($data, $column_map, 'ast'),
                'hhw' => $this->get_numeric_value($data, $column_map, 'hhw'),
                'ahw' => $this->get_numeric_value($data, $column_map, 'ahw'),
                'hc' => $this->get_numeric_value($data, $column_map, 'hc'),
                'ac' => $this->get_numeric_value($data, $column_map, 'ac'),
                'hf' => $this->get_numeric_value($data, $column_map, 'hf'),
                'af' => $this->get_numeric_value($data, $column_map, 'af'),
                'hfkc' => $this->get_numeric_value($data, $column_map, 'hfkc'),
                'afkc' => $this->get_numeric_value($data, $column_map, 'afkc'),
                'ho' => $this->get_numeric_value($data, $column_map, 'ho'),
                'ao' => $this->get_numeric_value($data, $column_map, 'ao'),
                'hy' => $this->get_numeric_value($data, $column_map, 'hy'),
                'ay' => $this->get_numeric_value($data, $column_map, 'ay'),
                'hr' => $this->get_numeric_value($data, $column_map, 'hr'),
                'ar' => $this->get_numeric_value($data, $column_map, 'ar'),
                'hbp' => $this->get_numeric_value($data, $column_map, 'hbp'),
                'abp' => $this->get_numeric_value($data, $column_map, 'abp'),
                'season' => $season,
                'sport' => 'football',
                'data_source' => 'csv',
                
                // Cuotas
                'b365h' => $this->get_decimal_value($data, $column_map, 'b365h'),
                'b365d' => $this->get_decimal_value($data, $column_map, 'b365d'),
                'b365a' => $this->get_decimal_value($data, $column_map, 'b365a'),
                'bwh' => $this->get_decimal_value($data, $column_map, 'bwh'),
                'bwd' => $this->get_decimal_value($data, $column_map, 'bwd'),
                'bwa' => $this->get_decimal_value($data, $column_map, 'bwa'),
                'iwh' => $this->get_decimal_value($data, $column_map, 'iwh'),
                'iwd' => $this->get_decimal_value($data, $column_map, 'iwd'),
                'iwa' => $this->get_decimal_value($data, $column_map, 'iwa')
            );
            
            // Calcular xG si es posible
            if ($this->xg_calculator && isset($insert_data['hs']) && isset($insert_data['as_shots'])) {
                $xg_data = $this->xg_calculator->calculate_match_xG($insert_data);
                $insert_data['home_xg'] = $xg_data['home_xG'];
                $insert_data['away_xg'] = $xg_data['away_xG'];
            }
            
            // Insertar en BD
            $result = $this->db->insert($this->table_name, $insert_data);
            
            if ($result === false) {
                $this->errors++;
                error_log('FT CSV Import Error: ' . $this->db->last_error);
            } else {
                $this->processed++;
            }
            
        } catch (Exception $e) {
            $this->errors++;
            error_log('FT CSV Import Exception: ' . $e->getMessage());
        }
    }
    
    /**
     * Actualizar partido existente con nuevos datos (ej: cuotas)
     */
    private function update_match($date, $home_team, $away_team, $data, $column_map) {
        $update_data = array();
        
        // Campos de cuotas que podrían necesitar actualización
        $odds_fields = array(
            'b365h', 'b365d', 'b365a',
            'bwh', 'bwd', 'bwa',
            'iwh', 'iwd', 'iwa'
        );
        
        foreach ($odds_fields as $field) {
            $value = $this->get_decimal_value($data, $column_map, $field);
            if ($value !== null) {
                $update_data[$field] = $value;
            }
        }
        
        if (!empty($update_data)) {
            $this->db->update(
                $this->table_name,
                $update_data,
                array(
                    'date' => $date,
                    'home_team' => $home_team,
                    'away_team' => $away_team
                )
            );
        }
    }
    
    /**
     * Obtener valor decimal (para cuotas)
     */
    private function get_decimal_value($data, $column_map, $field, $default = null) {
        $value = $this->get_value($data, $column_map, $field);
        
        if ($value === null || $value === '' || $value === 'NA') {
            return $default;
        }
        
        $value = floatval($value);
        
        // Validar que sea una cuota válida (entre 1.01 y 100)
        if ($value >= 1.01 && $value <= 100) {
            return $value;
        }
        
        return $default;
    }
    
    /**
     * Obtener valor de una columna
     */
    private function get_value($data, $column_map, $field, $default = null) {
        if (!isset($column_map[$field])) {
            return $default;
        }
        
        $index = $column_map[$field];
        
        if (!isset($data[$index])) {
            return $default;
        }
        
        $value = trim($data[$index]);
        
        if ($value === '' || $value === 'NA' || $value === 'N/A') {
            return $default;
        }
        
        return $value;
    }
    
    /**
     * Obtener valor numérico
     */
    private function get_numeric_value($data, $column_map, $field, $default = null) {
        $value = $this->get_value($data, $column_map, $field);
        
        if ($value === null) {
            return $default;
        }
        
        if (!is_numeric($value)) {
            return $default;
        }
        
        return intval($value);
    }
    
    /**
     * Formatear fecha
     */
    private function format_date($date) {
        // Intentar diferentes formatos
        $formats = array(
            'd/m/Y',
            'd/m/y',
            'Y-m-d',
            'd-m-Y',
            'm/d/Y'
        );
        
        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat($format, $date);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }
        
        // Intentar con strtotime como último recurso
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return false;
    }
    
    /**
     * Determinar temporada basada en fecha
     */
    private function determine_season($date) {
        $year = date('Y', strtotime($date));
        $month = date('n', strtotime($date));
        
        // La temporada empieza en julio
        if ($month >= 7) {
            return $year . '-' . ($year + 1);
        } else {
            return ($year - 1) . '-' . $year;
        }
    }
    
    /**
     * Verificar si el partido ya existe
     */
    private function match_exists($date, $home_team, $away_team) {
        $count = $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE date = %s AND home_team = %s AND away_team = %s",
            $date,
            $home_team,
            $away_team
        ));
        
        return $count > 0;
    }
    
    /**
     * Obtener estadísticas de importación
     */
    public function get_import_stats() {
        return array(
            'processed' => $this->processed,
            'skipped' => $this->skipped,
            'errors' => $this->errors
        );
    }
}
?>