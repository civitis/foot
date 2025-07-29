<?php
/**
 * Versión debug del importador CSV
 */

if (!defined('ABSPATH')) {
    exit;
}

class FT_CSV_Importer_Debug {
    
    private $errors = array();
    private $imported_count = 0;
    
    public function import_from_file($file_path) {
        try {
            echo "🔍 DEBUG: Iniciando import_from_file<br/>";
            
            // Verificar archivo
            if (!file_exists($file_path)) {
                throw new Exception('Archivo no existe: ' . $file_path);
            }
            echo "✅ Archivo existe<br/>";
            
            // Verificar que se puede leer
            if (!is_readable($file_path)) {
                throw new Exception('Archivo no es legible');
            }
            echo "✅ Archivo es legible<br/>";
            
            // Abrir archivo
            $handle = fopen($file_path, "r");
            if ($handle === FALSE) {
                throw new Exception('No se pudo abrir el archivo CSV');
            }
            echo "✅ Archivo abierto<br/>";
            
            // Leer headers
            $headers = fgetcsv($handle, 1000, ",");
            if ($headers === FALSE) {
                fclose($handle);
                throw new Exception('Error al leer encabezados del CSV');
            }
            echo "✅ Headers leídos: " . implode(', ', $headers) . "<br/>";
            
            // Mapeo básico
            $column_mapping = array(
                'Div' => 'division',
                'Date' => 'date',
                'HomeTeam' => 'home_team',
                'AwayTeam' => 'away_team',
                'FTHG' => 'fthg',
                'FTAG' => 'ftag',
                'FTR' => 'ftr'
            );
            echo "✅ Mapeo creado<br/>";
            
            // Procesar líneas
            $line_count = 0;
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $line_count++;
                echo "📝 Procesando línea $line_count<br/>";
                
                try {
                    $row_data = $this->process_row_debug($headers, $data, $column_mapping);
                    echo "✅ Línea $line_count procesada<br/>";
                    
                    // Intentar insertar en BD
                    $this->insert_row_debug($row_data);
                    echo "✅ Línea $line_count insertada<br/>";
                    
                    $this->imported_count++;
                    
                } catch (Exception $e) {
                    echo "❌ Error en línea $line_count: " . $e->getMessage() . "<br/>";
                    $this->errors[] = "Línea $line_count: " . $e->getMessage();
                }
                
                // Limitar a 2 líneas para debug
                if ($line_count >= 2) {
                    echo "🛑 Limitando a 2 líneas para debug<br/>";
                    break;
                }
            }
            
            fclose($handle);
            echo "✅ Archivo cerrado<br/>";
            
            return array(
                'success' => true,
                'message' => "Importadas $this->imported_count filas",
                'imported' => $this->imported_count,
                'errors' => $this->errors
            );
            
        } catch (Exception $e) {
            echo "❌ EXCEPCIÓN: " . $e->getMessage() . "<br/>";
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    private function process_row_debug($headers, $data, $column_mapping) {
        echo "&nbsp;&nbsp;🔍 Procesando fila con " . count($data) . " campos<br/>";
        
        $row_data = array();
        
        foreach ($headers as $index => $header) {
            if (isset($column_mapping[$header]) && isset($data[$index])) {
                $value = trim($data[$index]);
                
                if ($value === '' || $value === 'NA') {
                    $value = null;
                }
                
                // Convertir fecha simple
                if ($header === 'Date') {
                    $value = $this->convert_date_debug($value);
                }
                
                $row_data[$column_mapping[$header]] = $value;
                echo "&nbsp;&nbsp;&nbsp;&nbsp;✅ $header -> " . $column_mapping[$header] . " = " . ($value ?? 'NULL') . "<br/>";
            }
        }
        
        // Agregar campos obligatorios
        $row_data['data_source'] = 'csv_debug';
        $row_data['sport'] = 'football';
        $row_data['season'] = '2023-24';
        
        echo "&nbsp;&nbsp;✅ Fila procesada con " . count($row_data) . " campos<br/>";
        return $row_data;
    }
    
    private function convert_date_debug($date_string) {
        echo "&nbsp;&nbsp;&nbsp;&nbsp;📅 Convirtiendo fecha: $date_string -> ";
        
        if (empty($date_string)) {
            echo "NULL<br/>";
            return null;
        }
        
        // Formato dd/mm/yy
        $parts = explode('/', $date_string);
        if (count($parts) === 3) {
            $day = $parts[0];
            $month = $parts[1]; 
            $year = $parts[2];
            
            // Convertir año de 2 dígitos
            if (strlen($year) === 2) {
                $year = (int)$year < 50 ? '20' . $year : '19' . $year;
            }
            
            $converted = $year . '-' . $month . '-' . $day;
            echo "$converted<br/>";
            return $converted;
        }
        
        echo "ERROR<br/>";
        throw new Exception("Formato de fecha inválido: $date_string");
    }
    
    private function insert_row_debug($row_data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ft_matches_advanced';
        echo "&nbsp;&nbsp;💾 Insertando en tabla: $table<br/>";
        
        // Verificar campos obligatorios
        if (!isset($row_data['home_team']) || !isset($row_data['away_team']) || !isset($row_data['date'])) {
            throw new Exception('Faltan campos obligatorios');
        }
        
        echo "&nbsp;&nbsp;&nbsp;&nbsp;✅ Campos obligatorios OK<br/>";
        
        // Verificar si ya existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE date = %s AND home_team = %s AND away_team = %s",
            $row_data['date'],
            $row_data['home_team'],
            $row_data['away_team']
        ));
        
        if ($exists) {
            echo "&nbsp;&nbsp;&nbsp;&nbsp;⚠️ Registro ya existe, actualizando<br/>";
            $result = $wpdb->update($table, $row_data, array('id' => $exists));
        } else {
            echo "&nbsp;&nbsp;&nbsp;&nbsp;➕ Insertando nuevo registro<br/>";
            $result = $wpdb->insert($table, $row_data);
        }
        
        if ($result === false) {
            throw new Exception('Error BD: ' . $wpdb->last_error);
        }
        
        echo "&nbsp;&nbsp;&nbsp;&nbsp;✅ Operación BD exitosa<br/>";
    }
    
    public function get_errors() {
        return $this->errors;
    }
}