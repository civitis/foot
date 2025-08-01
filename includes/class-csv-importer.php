<?php
/**
 * Importador CSV optimizado para La Liga española con actualización inteligente
 */

class FT_CSV_Importer {
    private $db;
    private $table_name;
    private $processed = 0;
    private $skipped = 0;
    private $errors = 0;
    private $updated = 0;
    private $xg_calculator = null;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->table_name = $wpdb->prefix . 'ft_matches_advanced';

        if (class_exists('FootballTipster_xG_Calculator')) {
            $this->xg_calculator = new FootballTipster_xG_Calculator();
        }
    }

    /**
     * Mapeo optimizado para datos reales de La Liga
     */
    private function get_column_mapping($headers) {
        $mapping = array();
        
        $field_map = array(
            // Información básica (SIEMPRE disponible)
            'div' => 'division',
            'division' => 'division',
            'date' => 'date',
            'hometeam' => 'home_team',
            'home' => 'home_team',
            'awayteam' => 'away_team',
            'away' => 'away_team',

            // Resultados (SIEMPRE disponible)
            'fthg' => 'fthg',
            'ftag' => 'ftag',
            'ftr' => 'ftr',
            'hthg' => 'hthg',
            'htag' => 'htag',
            'htr' => 'htr',

            // Estadísticas básicas (disponibles en La Liga)
            'hs' => 'hs',
            'as' => 'as_shots',
            'hst' => 'hst',
            'ast' => 'ast',
            'hc' => 'hc',
            'ac' => 'ac',
            'hf' => 'hf',
            'af' => 'af',
            'hy' => 'hy',
            'ay' => 'ay',
            'hr' => 'hr',
            'ar' => 'ar',

            // Cuotas principales (MUY IMPORTANTE para value betting)
            'b365h' => 'b365h',
            'b365d' => 'b365d',
            'b365a' => 'b365a',
            
            // Over/Under Bet365
            'b365>2.5' => 'b365_over25',
            'b365<2.5' => 'b365_under25',
            'b365>1.5' => 'b365_over15',
            'b365<1.5' => 'b365_under15',
            'b365>3.5' => 'b365_over35',
            'b365<3.5' => 'b365_under35',

            // Asian Handicap Bet365
            'b365ah' => 'b365_ah_line',
            'b365ahh' => 'b365_ah_home',
            'b365aha' => 'b365_ah_away',

            // Otras casas importantes
            'bwh' => 'bwh',
            'bwd' => 'bwd',
            'bwa' => 'bwa',
            'iwh' => 'iwh',
            'iwd' => 'iwd',
            'iwa' => 'iwa',
            'psh' => 'psh',
            'psd' => 'psd',
            'psa' => 'psa',
            'whh' => 'whh',
            'whd' => 'whd',
            'wha' => 'wha',

            // Cuotas máximas y promedio
            'maxh' => 'max_home',
            'maxd' => 'max_draw',
            'maxa' => 'max_away',
            'max>2.5' => 'max_over25',
            'max<2.5' => 'max_under25',
            'avgh' => 'avg_home',
            'avgd' => 'avg_draw',
            'avga' => 'avg_away',
            'avg>2.5' => 'avg_over25',
            'avg<2.5' => 'avg_under25'
        );

        foreach ($headers as $index => $header) {
            $header = strtolower(trim($header));
            if (isset($field_map[$header])) {
                $mapping[$field_map[$header]] = $index;
            }
        }

        return $mapping;
    }

    /**
     * Procesar fila con actualización inteligente
     */
    private function process_row($data, $column_map) {
        try {
            $date = $this->get_value($data, $column_map, 'date');
            $home_team = $this->get_value($data, $column_map, 'home_team');
            $away_team = $this->get_value($data, $column_map, 'away_team');

            if (empty($date) || empty($home_team) || empty($away_team)) {
                $this->skipped++;
                return;
            }

            $date = $this->format_date($date);
            if (!$date) {
                $this->skipped++;
                return;
            }

            $season = $this->determine_season($date);

            // ACTUALIZACIÓN INTELIGENTE: Si existe, actualizar solo campos faltantes
            if ($this->match_exists($date, $home_team, $away_team)) {
                $this->smart_update_match($date, $home_team, $away_team, $data, $column_map);
                $this->updated++;
                return;
            }

            // INSERTAR NUEVO PARTIDO
            $insert_data = array(
                'division' => $this->get_value($data, $column_map, 'division', ''),
                'date' => $date,
                'home_team' => $home_team,
                'away_team' => $away_team,
                'fthg' => $this->get_numeric_value($data, $column_map, 'fthg'),
                'ftag' => $this->get_numeric_value($data, $column_map, 'ftag'),
                'ftr' => $this->get_value($data, $column_map, 'ftr'),
                'hthg' => $this->get_numeric_value($data, $column_map, 'hthg'),
                'htag' => $this->get_numeric_value($data, $column_map, 'htag'),
                'htr' => $this->get_value($data, $column_map, 'htr'),

                // Estadísticas disponibles en La Liga
                'hs' => $this->get_numeric_value($data, $column_map, 'hs'),
                'as_shots' => $this->get_numeric_value($data, $column_map, 'as_shots'),
                'hst' => $this->get_numeric_value($data, $column_map, 'hst'),
                'ast' => $this->get_numeric_value($data, $column_map, 'ast'),
                'hc' => $this->get_numeric_value($data, $column_map, 'hc'),
                'ac' => $this->get_numeric_value($data, $column_map, 'ac'),
                'hf' => $this->get_numeric_value($data, $column_map, 'hf'),
                'af' => $this->get_numeric_value($data, $column_map, 'af'),
                'hy' => $this->get_numeric_value($data, $column_map, 'hy'),
                'ay' => $this->get_numeric_value($data, $column_map, 'ay'),
                'hr' => $this->get_numeric_value($data, $column_map, 'hr'),
                'ar' => $this->get_numeric_value($data, $column_map, 'ar'),

                // TODAS LAS CUOTAS (críticas para benchmarking)
                'b365h' => $this->get_decimal_value($data, $column_map, 'b365h'),
                'b365d' => $this->get_decimal_value($data, $column_map, 'b365d'),
                'b365a' => $this->get_decimal_value($data, $column_map, 'b365a'),
                'b365_over25' => $this->get_decimal_value($data, $column_map, 'b365_over25'),
                'b365_under25' => $this->get_decimal_value($data, $column_map, 'b365_under25'),
                'b365_over15' => $this->get_decimal_value($data, $column_map, 'b365_over15'),
                'b365_under15' => $this->get_decimal_value($data, $column_map, 'b365_under15'),
                'b365_over35' => $this->get_decimal_value($data, $column_map, 'b365_over35'),
                'b365_under35' => $this->get_decimal_value($data, $column_map, 'b365_under35'),
                'b365_ah_line' => $this->get_decimal_value($data, $column_map, 'b365_ah_line'),
                'b365_ah_home' => $this->get_decimal_value($data, $column_map, 'b365_ah_home'),
                'b365_ah_away' => $this->get_decimal_value($data, $column_map, 'b365_ah_away'),
                'bwh' => $this->get_decimal_value($data, $column_map, 'bwh'),
                'bwd' => $this->get_decimal_value($data, $column_map, 'bwd'),
                'bwa' => $this->get_decimal_value($data, $column_map, 'bwa'),
                'iwh' => $this->get_decimal_value($data, $column_map, 'iwh'),
                'iwd' => $this->get_decimal_value($data, $column_map, 'iwd'),
                'iwa' => $this->get_decimal_value($data, $column_map, 'iwa'),
                'psh' => $this->get_decimal_value($data, $column_map, 'psh'),
                'psd' => $this->get_decimal_value($data, $column_map, 'psd'),
                'psa' => $this->get_decimal_value($data, $column_map, 'psa'),
                'whh' => $this->get_decimal_value($data, $column_map, 'whh'),
                'whd' => $this->get_decimal_value($data, $column_map, 'whd'),
                'wha' => $this->get_decimal_value($data, $column_map, 'wha'),
                'max_home' => $this->get_decimal_value($data, $column_map, 'max_home'),
                'max_draw' => $this->get_decimal_value($data, $column_map, 'max_draw'),
                'max_away' => $this->get_decimal_value($data, $column_map, 'max_away'),
                'max_over25' => $this->get_decimal_value($data, $column_map, 'max_over25'),
                'max_under25' => $this->get_decimal_value($data, $column_map, 'max_under25'),
                'avg_home' => $this->get_decimal_value($data, $column_map, 'avg_home'),
                'avg_draw' => $this->get_decimal_value($data, $column_map, 'avg_draw'),
                'avg_away' => $this->get_decimal_value($data, $column_map, 'avg_away'),
                'avg_over25' => $this->get_decimal_value($data, $column_map, 'avg_over25'),
                'avg_under25' => $this->get_decimal_value($data, $column_map, 'avg_under25'),

                'season' => $season,
                'sport' => 'football',
                'data_source' => 'csv'
            );

            // Calcular xG si es posible
            if ($this->xg_calculator && isset($insert_data['hs']) && isset($insert_data['as_shots'])) {
                $xg_data = $this->xg_calculator->calculate_match_xG($insert_data);
                $insert_data['home_xg'] = $xg_data['home_xG'];
                $insert_data['away_xg'] = $xg_data['away_xG'];
            }

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
     * Actualización inteligente: solo actualiza campos NULL o faltantes
     */
    private function smart_update_match($date, $home_team, $away_team, $data, $column_map) {
        // Obtener registro actual
        $current = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE date = %s AND home_team = %s AND away_team = %s",
            $date, $home_team, $away_team
        ), ARRAY_A);

        if (!$current) return;

        $update_data = array();

        // Lista de campos a actualizar si están vacíos
        $updateable_fields = array(
            'fthg', 'ftag', 'ftr', 'hthg', 'htag', 'htr',
            'hs', 'as_shots', 'hst', 'ast', 'hc', 'ac', 'hf', 'af', 'hy', 'ay', 'hr', 'ar',
            'b365h', 'b365d', 'b365a', 'b365_over25', 'b365_under25', 'b365_over15', 'b365_under15',
            'b365_over35', 'b365_under35', 'b365_ah_line', 'b365_ah_home', 'b365_ah_away',
            'bwh', 'bwd', 'bwa', 'iwh', 'iwd', 'iwa', 'psh', 'psd', 'psa',
            'whh', 'whd', 'wha', 'max_home', 'max_draw', 'max_away',
            'max_over25', 'max_under25', 'avg_home', 'avg_draw', 'avg_away',
            'avg_over25', 'avg_under25'
        );

        foreach ($updateable_fields as $field) {
            // Solo actualizar si el campo actual está vacío/null
            if (is_null($current[$field]) || $current[$field] == '' || $current[$field] == 0) {
                $new_value = null;
                
                if (in_array($field, ['fthg', 'ftag', 'hthg', 'htag', 'hs', 'as_shots', 'hst', 'ast', 'hc', 'ac', 'hf', 'af', 'hy', 'ay', 'hr', 'ar'])) {
                    $new_value = $this->get_numeric_value($data, $column_map, $field);
                } elseif (in_array($field, ['ftr', 'htr'])) {
                    $new_value = $this->get_value($data, $column_map, $field);
                } else {
                    $new_value = $this->get_decimal_value($data, $column_map, $field);
                }

                if ($new_value !== null) {
                    $update_data[$field] = $new_value;
                }
            }
        }

        // Actualizar solo si hay cambios
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

    // Métodos auxiliares (sin cambios importantes)
    public function import_from_file($file_path) {
        if (!file_exists($file_path)) {
            return array('success' => false, 'error' => 'El archivo no existe');
        }

        try {
            $handle = fopen($file_path, 'r');
            if (!$handle) {
                return array('success' => false, 'error' => 'No se pudo abrir el archivo');
            }

            $headers = fgetcsv($handle);
            if (!$headers) {
                fclose($handle);
                return array('success' => false, 'error' => 'El archivo está vacío o no es válido');
            }

            $headers = array_map('trim', $headers);
            $headers = array_map('strtolower', $headers);
            $column_map = $this->get_column_mapping($headers);

            while (($data = fgetcsv($handle)) !== false) {
                $this->process_row($data, $column_map);
            }

            fclose($handle);

            $message = sprintf(
                'Importación completada: %d nuevos, %d actualizados, %d omitidos, %d errores',
                $this->processed,
                $this->updated,
                $this->skipped,
                $this->errors
            );

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
                'updated' => $this->updated,
                'skipped' => $this->skipped,
                'errors' => $this->errors
            );

        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Error procesando archivo: ' . $e->getMessage());
        }
    }

    public function import_from_url($url) {
        try {
            $response = wp_remote_get($url, array('timeout' => 60));
            if (is_wp_error($response)) {
                return array('success' => false, 'error' => 'Error descargando archivo: ' . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                return array('success' => false, 'error' => 'El archivo descargado está vacío');
            }

            $temp_file = FT_PLUGIN_PATH . 'temp/import_' . time() . '.csv';
            if (!file_exists(dirname($temp_file))) {
                wp_mkdir_p(dirname($temp_file));
            }

            file_put_contents($temp_file, $body);
            $result = $this->import_from_file($temp_file);

            if (file_exists($temp_file)) {
                unlink($temp_file);
            }

            return $result;

        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Error: ' . $e->getMessage());
        }
    }

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

    private function get_numeric_value($data, $column_map, $field, $default = null) {
        $value = $this->get_value($data, $column_map, $field);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }
        return intval($value);
    }

    private function get_decimal_value($data, $column_map, $field, $default = null) {
        $value = $this->get_value($data, $column_map, $field);
        if ($value === null || $value === '' || $value === 'NA') {
            return $default;
        }

        $value = floatval($value);
        if ($value >= 1.01 && $value <= 100) {
            return $value;
        }

        return $default;
    }

    private function format_date($date) {
        $formats = array('d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'm/d/Y');
        
        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat($format, $date);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return false;
    }

    private function determine_season($date) {
        $year = date('Y', strtotime($date));
        $month = date('n', strtotime($date));
        
        if ($month >= 7) {
            return $year . '-' . ($year + 1);
        } else {
            return ($year - 1) . '-' . $year;
        }
    }

    private function match_exists($date, $home_team, $away_team) {
        $count = $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table_name}
             WHERE date = %s AND home_team = %s AND away_team = %s",
            $date, $home_team, $away_team
        ));
        return $count > 0;
    }

    public function get_import_stats() {
        return array(
            'processed' => $this->processed,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors
        );
    }
}
?>
