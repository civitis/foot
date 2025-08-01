<?php

/**
 * Sistema de Benchmarking para validar modelos de predicción
 * Versión corregida con soporte para benchmark_season.py y cuotas reales
 */

if (!defined('ABSPATH')) {
    exit;
}

class FT_Benchmarking {
    
    private $current_season;
    private $test_matches;
    private $results;
    
    public function __construct() {
        $this->results = array();
    }
    
    /**
     * Ejecuta benchmarking completo de una temporada
     * @param string $season Temporada a evaluar
     * @param string $model_type Tipo de modelo ('with_xg' o 'without_xg')
     * @param string $league Liga específica (opcional, 'all' para todas)
     */
	
	
    public function get_available_leagues() {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_matches_advanced';
        
        $leagues = $wpdb->get_results(
            "SELECT DISTINCT division as league, COUNT(*) as total
             FROM $table 
             WHERE division IS NOT NULL 
             AND division != ''
             AND fthg IS NOT NULL
             GROUP BY division
             HAVING total > 100
             ORDER BY division"
        );
        
        return $leagues;
    }
	
    public function run_season_benchmark($season, $model_type = 'with_xg', $league = 'all') {
        try {
            error_log("FT Benchmark: Iniciando benchmark para temporada $season con modelo $model_type" . 
                     ($league !== 'all' ? " y liga $league" : ""));
            
            $this->current_season = $season;
            
            // Verificar que tenemos datos
            $test_count = $this->verify_season_data($season, $league);
            if ($test_count == 0) {
                return array('error' => "No hay datos con resultados para la temporada $season");
            }
            
            error_log("FT Benchmark: Encontrados $test_count partidos para evaluar");
            
            // Asegurar que db_config.json tiene el prefijo correcto
            $this->update_db_config();
            
            // Ejecutar benchmark usando benchmark_season.py
            $benchmark_result = $this->execute_benchmark_script($season, $model_type, $league);
            
            if (isset($benchmark_result['error'])) {
                return array('error' => $benchmark_result['error']);
            }
            
            // Guardar resultados
            $this->save_benchmark_results(
                $season, 
                $model_type, 
                $benchmark_result['test_metrics'], 
                $benchmark_result['value_betting']
            );
            
            error_log("FT Benchmark: Completado exitosamente");
            
            return $benchmark_result;
            
        } catch (Exception $e) {
            error_log("FT Benchmark Error: " . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }
    
    /**
     * Verifica que hay datos para la temporada y liga
     */
   // CORRECCIÓN 2: Actualizar método de verificación de datos para ser más específico
private function verify_season_data($season, $league = 'all') {
    global $wpdb;
    $table = $wpdb->prefix . 'ft_matches_advanced';
    
    // Construir WHERE clause para liga
    $where_league = "";
    $params = array($season);
    
    if ($league !== 'all') {
        $where_league = " AND division = %s";
        $params[] = $league;
    }
    
    // Query mejorada para verificar datos
    $sql = "SELECT COUNT(*) FROM $table 
            WHERE season = %s 
            AND fthg IS NOT NULL 
            AND ftag IS NOT NULL
            AND hs IS NOT NULL
            AND as_shots IS NOT NULL
            AND home_team IS NOT NULL
            AND away_team IS NOT NULL" . $where_league;
    
    $count = $wpdb->get_var($wpdb->prepare($sql, $params));
    
    error_log("FT Benchmark: Verificando datos - Temporada: $season, Liga: $league, Partidos: " . intval($count));
    
    return intval($count);
}
    
    /**
     * Actualiza db_config.json con el prefijo correcto
     */
    private function update_db_config() {
        global $wpdb;
        
        $db_config = array(
            'host' => DB_HOST,
            'user' => DB_USER,
            'password' => DB_PASSWORD,
            'database' => DB_NAME,
            'table_prefix' => $wpdb->prefix
        );
        
        $config_file = FT_PYTHON_PATH . 'db_config.json';
        file_put_contents($config_file, json_encode($db_config, JSON_PRETTY_PRINT));
        
        error_log("FT Benchmark: db_config.json actualizado con prefijo: " . $wpdb->prefix);
    }
    

private function execute_benchmark_script($season, $model_type, $league = 'all') {
    $python_script = FT_PYTHON_PATH . 'benchmark_season.py';
    
    // Verificar que el script existe
    if (!file_exists($python_script)) {
        error_log("FT Benchmark: ERROR - No existe benchmark_season.py en " . FT_PYTHON_PATH);
        return array('error' => 'Script benchmark_season.py no encontrado');
    }
    
    // Comando para ejecutar el script directamente
    $command = sprintf(
        'cd %s && /usr/bin/python3.8 benchmark_season.py %s %s %s 2>&1',
        FT_PYTHON_PATH,
        escapeshellarg($season),
        escapeshellarg($model_type),
        escapeshellarg($league)
    );
    
    error_log("FT Benchmark: Ejecutando comando: " . $command);
    
    // Ejecutar el script y capturar toda la salida
    $output = shell_exec($command);
    
    if (!$output) {
        return array('error' => 'No se recibió respuesta del script Python');
    }
    
    error_log("FT Benchmark: Output recibido (primeros 1000 chars): " . substr($output, 0, 1000));
    
    // Buscar el JSON en la salida
    // El JSON debe estar en la última línea que comience con '{'
    $lines = explode("\n", $output);
    $json_str = '';
    
    // Buscar la última línea que parezca JSON
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        
        // Si la línea empieza con '{' y termina con '}', probablemente es nuestro JSON
        if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
            $json_str = $line;
            break;
        }
    }
    
    // Si no encontramos una línea JSON simple, intentar extraer JSON multilinea
    if (empty($json_str)) {
        // Buscar el primer '{' y el último '}'
        $first_brace = strpos($output, '{');
        $last_brace = strrpos($output, '}');
        
        if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
            $json_str = substr($output, $first_brace, $last_brace - $first_brace + 1);
        }
    }
    
    if (!empty($json_str)) {
        // Intentar decodificar el JSON
        $result = json_decode($json_str, true);
        
        if ($result !== null) {
            if (isset($result['error'])) {
                error_log("FT Benchmark: Error del script: " . $result['error']);
                return array('error' => $result['error']);
            } elseif (isset($result['test_metrics'])) {
                error_log("FT Benchmark: Resultados decodificados correctamente");
                return $result;
            }
        } else {
            error_log("FT Benchmark: Error decodificando JSON: " . json_last_error_msg());
            error_log("FT Benchmark: JSON string: " . substr($json_str, 0, 500));
        }
    }
    
    // Si llegamos aquí, buscar mensajes de error específicos
    if (strpos($output, 'ModuleNotFoundError') !== false) {
        return array('error' => 'Error: Falta instalar módulos Python necesarios');
    }
    
    if (strpos($output, 'mysql.connector.errors') !== false) {
        return array('error' => 'Error de conexión a la base de datos');
    }
    
    if (strpos($output, 'No hay datos') !== false || strpos($output, 'No se encontraron') !== false) {
        // Extraer el mensaje de error
        preg_match('/(?:ERROR:|Error:)?\s*(.+)/', $output, $matches);
        if (isset($matches[1])) {
            return array('error' => trim($matches[1]));
        }
    }
    
    // Error genérico
    return array('error' => 'No se pudo procesar la respuesta del script. Revisa los logs para más detalles.');
}
// CORRECCIÓN 3: Método para debuggear datos de temporada
public function debug_season_data($season, $league = 'all') {
    global $wpdb;
    $table = $wpdb->prefix . 'ft_matches_advanced';
    
    // Verificar qué temporadas existen
    $seasons = $wpdb->get_col("SELECT DISTINCT season FROM $table WHERE season IS NOT NULL ORDER BY season");
    
    // Verificar datos específicos de la temporada
    $where_league = ($league !== 'all') ? $wpdb->prepare(" AND division = %s", $league) : "";
    
    $season_data = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN fthg IS NOT NULL THEN 1 END) as with_fthg,
            COUNT(CASE WHEN ftag IS NOT NULL THEN 1 END) as with_ftag,
            COUNT(CASE WHEN hs IS NOT NULL THEN 1 END) as with_hs,
            COUNT(CASE WHEN as_shots IS NOT NULL THEN 1 END) as with_as,
            MIN(date) as first_match,
            MAX(date) as last_match
         FROM $table 
         WHERE season = %s" . $where_league,
        $season
    ));
    
    return array(
        'available_seasons' => $seasons,
        'target_season' => $season,
        'target_league' => $league,
        'season_stats' => $season_data
    );
}
    
    /**
     * Guarda resultados del benchmarking
     */
   	private function save_benchmark_results($season, $model_type, $metrics, $value_betting) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'ft_benchmarks';
    
    // Crear tabla si no existe
    $charset_collate = $wpdb->get_charset_collate();
    
    $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
        id int(11) NOT NULL AUTO_INCREMENT,
        season varchar(20) NOT NULL,
        model_type varchar(50) NOT NULL,
        test_date datetime DEFAULT CURRENT_TIMESTAMP,
        total_predictions int(11) NOT NULL,
        correct_predictions int(11) NOT NULL,
        overall_accuracy decimal(5,4) NOT NULL,
        home_accuracy decimal(5,4) DEFAULT NULL,
        draw_accuracy decimal(5,4) DEFAULT NULL,
        away_accuracy decimal(5,4) DEFAULT NULL,
        high_confidence_accuracy decimal(5,4) DEFAULT NULL,
        value_betting_roi decimal(8,2) DEFAULT NULL,
        value_betting_profit decimal(8,2) DEFAULT NULL,
        value_betting_win_rate decimal(5,4) DEFAULT NULL,
        metadata longtext DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_season (season),
        KEY idx_model_type (model_type)
    ) $charset_collate");
    
    // Extraer detalles de apuestas antes de guardar
    $betting_details = isset($value_betting['betting_details']) ? $value_betting['betting_details'] : array();
    unset($value_betting['betting_details']);  // No guardar en value_betting principal
    
    $wpdb->insert($table, array(
        'season' => $season,
        'model_type' => $model_type,
        'total_predictions' => $metrics['total_predictions'],
        'correct_predictions' => $metrics['correct_predictions'],
        'overall_accuracy' => $metrics['overall_accuracy'],
        'home_accuracy' => $metrics['home_wins']['accuracy'],
        'draw_accuracy' => $metrics['draws']['accuracy'],
        'away_accuracy' => $metrics['away_wins']['accuracy'],
        'high_confidence_accuracy' => isset($metrics['high_confidence']['accuracy']) ? $metrics['high_confidence']['accuracy'] : null,
        'value_betting_roi' => $value_betting['roi'],
        'value_betting_profit' => $value_betting['profit_loss'],
        'value_betting_win_rate' => $value_betting['win_rate'],
        'metadata' => json_encode(array(
            'full_metrics' => $metrics,
            'full_value_betting' => $value_betting,
            'betting_details' => $betting_details,  // Guardar detalles aquí
            'test_season' => $season,
            'model_type' => $model_type
        ))
    ));
    
    error_log("FT Benchmark: Resultados guardados con " . count($betting_details) . " apuestas detalladas");
}
    
    /**
     * Obtiene temporadas disponibles
     */
    public function get_available_seasons() {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_matches_advanced';
        
        $seasons = $wpdb->get_col(
            "SELECT DISTINCT season 
             FROM $table 
             WHERE season IS NOT NULL 
             AND season != ''
             AND fthg IS NOT NULL
             AND ftag IS NOT NULL
             AND hs IS NOT NULL
             GROUP BY season
             HAVING COUNT(*) > 100
             ORDER BY season DESC"
        );
        
        return $seasons;
    }
    
    /**
     * Obtiene historial de benchmarks
     */
    public function get_benchmark_history($limit = 20) {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_benchmarks';
        
        // Verificar que la tabla existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return array();
        }
        
        return $wpdb->get_results(
            "SELECT * FROM $table 
             ORDER BY test_date DESC 
             LIMIT $limit"
        );
    }
    
    /**
     * Método de debug para verificar el sistema
     */
    public function debug_benchmark_system() {
        $debug_info = array();
        
        // 1. Verificar scripts Python
        $scripts = array(
            'benchmark_season.py' => 'Script principal de benchmark',
            'predict_match.py' => 'Script de predicción',
            'train_model_fixed.py' => 'Script de entrenamiento'
        );
        
        $debug_info['scripts'] = array();
        foreach ($scripts as $script => $desc) {
            $path = FT_PYTHON_PATH . $script;
            $debug_info['scripts'][$script] = array(
                'exists' => file_exists($path),
                'path' => $path,
                'description' => $desc,
                'size' => file_exists($path) ? filesize($path) : 0
            );
        }
        
        // 2. Verificar modelo
        $model_path = FT_MODELS_PATH . 'football_rf_advanced.pkl';
        $debug_info['model'] = array(
            'exists' => file_exists($model_path),
            'path' => $model_path,
            'size' => file_exists($model_path) ? filesize($model_path) : 0
        );
        
        // 3. Verificar db_config.json
        $config_path = FT_PYTHON_PATH . 'db_config.json';
        $debug_info['db_config'] = array(
            'exists' => file_exists($config_path),
            'path' => $config_path,
            'content' => file_exists($config_path) ? json_decode(file_get_contents($config_path), true) : null
        );
        
        // 4. Verificar datos
        global $wpdb;
        $table = $wpdb->prefix . 'ft_matches_advanced';
        
        $debug_info['data'] = array(
            'total_matches' => $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'matches_with_results' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE fthg IS NOT NULL"),
            'matches_with_stats' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE hs IS NOT NULL"),
            'matches_with_odds' => $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE b365h IS NOT NULL"),
            'seasons' => $wpdb->get_col("SELECT DISTINCT season FROM $table WHERE season IS NOT NULL ORDER BY season DESC")
        );
        
        return $debug_info;
    }

}

?>