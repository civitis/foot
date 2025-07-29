<?php
/**
 * Clase principal para predicciones de fútbol usando Random Forest
 */

if (!defined('ABSPATH')) {
    exit;
}

class FT_Predictor {
    
    private static $python_path;
    private static $models_path;
    
    public function __construct() {
        self::$python_path = FT_PYTHON_PATH;
        self::$models_path = FT_MODELS_PATH;
    }
    
    /**
     * Predice el resultado de un partido
     */
    public static function predict_match($home_team, $away_team, $sport = 'football') {
        try {
            error_log("FT: Prediciendo $home_team vs $away_team");
            
            // 1. Obtener estadísticas de los equipos
            $home_stats = self::get_team_stats($home_team);
            $away_stats = self::get_team_stats($away_team);
            
            if (!$home_stats || !$away_stats) {
                return array('error' => 'No se encontraron estadísticas para uno o ambos equipos');
            }
            
            // 2. Preparar características para el modelo
            $features = self::prepare_features($home_stats, $away_stats);
            
            // 3. Ejecutar predicción Python
            $result = self::execute_python_prediction($features, $sport);
            
            // 4. Guardar predicción en BD
            if (!isset($result['error'])) {
                self::save_prediction($home_team, $away_team, $result);
            }
            
            error_log("FT: Predicción completada para $home_team vs $away_team");
            return $result;
            
        } catch (Exception $e) {
            error_log("FT: Error en predicción: " . $e->getMessage());
            return array('error' => $e->getMessage());
        }
    }
    
    /**
     * Obtiene estadísticas de un equipo
     */
    /**
 * Obtiene estadísticas de un equipo
 */
private static function get_team_stats($team_name) {
    global $wpdb;
    
    $matches_table = $wpdb->prefix . 'ft_matches_advanced';
    
    // Obtener estadísticas básicas como local (SIN LÍMITE DE FECHA)
    $home_stats = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(*) as matches_played,
            SUM(CASE WHEN ftr = 'H' THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN ftr = 'D' THEN 1 ELSE 0 END) as draws,
            SUM(CASE WHEN ftr = 'A' THEN 1 ELSE 0 END) as losses,
            AVG(fthg) as avg_goals_for,
            AVG(ftag) as avg_goals_against,
            AVG(hs) as avg_shots,
            AVG(hst) as avg_shots_target,
            AVG(hc) as avg_corners,
            AVG(hf) as avg_fouls
        FROM $matches_table 
        WHERE home_team = %s",
        $team_name
    ));
    
    // Obtener estadísticas como visitante (SIN LÍMITE DE FECHA)
    $away_stats = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(*) as matches_played,
            SUM(CASE WHEN ftr = 'A' THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN ftr = 'D' THEN 1 ELSE 0 END) as draws,
            SUM(CASE WHEN ftr = 'H' THEN 1 ELSE 0 END) as losses,
            AVG(ftag) as avg_goals_for,
            AVG(fthg) as avg_goals_against,
            AVG(as_shots) as avg_shots,
            AVG(ast) as avg_shots_target,
            AVG(ac) as avg_corners,
            AVG(af) as avg_fouls
        FROM $matches_table 
        WHERE away_team = %s",
        $team_name
    ));
    
    if (!$home_stats || !$away_stats) {
        return null;
    }
    
    // Combinar estadísticas
    return array(
        'team_name' => $team_name,
        'total_matches' => $home_stats->matches_played + $away_stats->matches_played,
        'total_wins' => $home_stats->wins + $away_stats->wins,
        'total_draws' => $home_stats->draws + $away_stats->draws,
        'total_losses' => $home_stats->losses + $away_stats->losses,
        'avg_goals_for' => ($home_stats->avg_goals_for + $away_stats->avg_goals_for) / 2,
        'avg_goals_against' => ($home_stats->avg_goals_against + $away_stats->avg_goals_against) / 2,
        'avg_shots' => ($home_stats->avg_shots + $away_stats->avg_shots) / 2,
        'avg_shots_target' => ($home_stats->avg_shots_target + $away_stats->avg_shots_target) / 2,
        'avg_corners' => ($home_stats->avg_corners + $away_stats->avg_corners) / 2,
        'home_form' => self::get_recent_form($team_name, true),
        'away_form' => self::get_recent_form($team_name, false)
    );
}
    
    /**
     * Obtiene forma reciente de un equipo
     */
    /**
 * Obtiene forma reciente de un equipo
 */
private static function get_recent_form($team_name, $as_home = null) {
    global $wpdb;
    $matches_table = $wpdb->prefix . 'ft_matches_advanced';
    
    $where_clause = "";
    if ($as_home === true) {
        $where_clause = "AND home_team = %s";
    } elseif ($as_home === false) {
        $where_clause = "AND away_team = %s";
    } else {
        $where_clause = "AND (home_team = %s OR away_team = %s)";
    }
    
    $query = "SELECT ftr, home_team, away_team 
              FROM $matches_table 
              WHERE 1=1 $where_clause
              ORDER BY date DESC 
              LIMIT 5";
    
    $params = array($team_name);
    if ($as_home === null) {
        $params[] = $team_name;
    }
    
    $recent_matches = $wpdb->get_results($wpdb->prepare($query, $params));
    
    $form = '';
    $points = 0;
    
    foreach ($recent_matches as $match) {
        if (($match->home_team === $team_name && $match->ftr === 'H') ||
            ($match->away_team === $team_name && $match->ftr === 'A')) {
            $form .= 'W';
            $points += 3;
        } elseif ($match->ftr === 'D') {
            $form .= 'D';
            $points += 1;
        } else {
            $form .= 'L';
        }
    }
    
    return array(
        'form_string' => $form,
        'points' => $points,
        'matches' => count($recent_matches)
    );
}
    
    /**
     * Prepara características para el modelo Python
     */
    private static function prepare_features($home_stats, $away_stats) {
        return array(
            // Estadísticas del equipo local
            $home_stats['total_wins'] / max(1, $home_stats['total_matches']), // Win rate
            $home_stats['total_draws'] / max(1, $home_stats['total_matches']), // Draw rate  
            $home_stats['avg_goals_for'] ?: 1.0,
            $home_stats['avg_goals_against'] ?: 1.0,
            $home_stats['avg_shots'] ?: 10.0,
            $home_stats['avg_shots_target'] ?: 4.0,
            $home_stats['avg_corners'] ?: 5.0,
            $home_stats['home_form']['points'] / max(1, $home_stats['home_form']['matches']),
            
            // Estadísticas del equipo visitante
            $away_stats['total_wins'] / max(1, $away_stats['total_matches']),
            $away_stats['total_draws'] / max(1, $away_stats['total_matches']),
            $away_stats['avg_goals_for'] ?: 1.0,
            $away_stats['avg_goals_against'] ?: 1.0,
            $away_stats['avg_shots'] ?: 10.0,
            $away_stats['avg_shots_target'] ?: 4.0,
            $away_stats['avg_corners'] ?: 5.0,
            $away_stats['away_form']['points'] / max(1, $away_stats['away_form']['matches']),
            
            // Características adicionales
            $home_stats['avg_goals_for'] - $away_stats['avg_goals_against'], // Attack vs Defense
            $away_stats['avg_goals_for'] - $home_stats['avg_goals_against']  // Attack vs Defense
        );
    }
    
    /**
     * Ejecuta predicción usando Python
     */
    private static function execute_python_prediction($features, $sport) {
        // Verificar que el modelo existe
        $model_file = self::$models_path . $sport . '_model.pkl';
        if (!file_exists($model_file)) {
            return array('error' => 'Modelo no entrenado. Ve a Admin → Football Tipster → Entrenar Modelo');
        }
        
        // Preparar datos para Python
        $features_json = json_encode($features);
        
        // Ejecutar script Python
        $python_script = self::$python_path . 'predict_simple.py';
        $command = escapeshellcmd("/usr/bin/python3.8 $python_script '$model_file' '$features_json'");
        
        error_log("FT: Ejecutando comando: " . $command);
        
        $output = shell_exec($command);
        
        if (!$output) {
            return array('error' => 'No se obtuvo respuesta del modelo Python');
        }
        
        error_log("FT: Output Python: " . $output);
        
        $result = json_decode(trim($output), true);
        
        if (!$result) {
            return array('error' => 'Error al decodificar respuesta del modelo: ' . $output);
        }
        
        return $result;
    }
    
    /**
     * Guarda predicción en base de datos
     */
    private static function save_prediction($home_team, $away_team, $result) {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_predictions';
        
        $data = array(
            'match_date' => current_time('mysql'),
            'home_team' => $home_team,
            'away_team' => $away_team,
            'prediction' => $result['prediction'],
            'probability' => $result['confidence'],
            'metadata' => json_encode($result)
        );
        
        $wpdb->insert($table, $data);
    }
    
    /**
     * AJAX handler para predicciones
     */
    public static function ajax_get_prediction() {
        check_ajax_referer('ft_nonce', 'nonce');
        
        $home_team = sanitize_text_field($_POST['home_team']);
        $away_team = sanitize_text_field($_POST['away_team']);
        $sport = sanitize_text_field($_POST['sport'] ?? 'football');
        
        $result = self::predict_match($home_team, $away_team, $sport);
        
        wp_send_json($result);
    }
    
    /**
     * Obtener equipos disponibles para predicción
     */
   /**
 * Obtener equipos disponibles para predicción
 */
	public static function get_available_teams() {
    global $wpdb;
    $table = $wpdb->prefix . 'ft_matches_advanced';
    
    $teams = $wpdb->get_col(
        "SELECT DISTINCT home_team FROM $table 
         WHERE home_team IS NOT NULL AND home_team != ''
         UNION 
         SELECT DISTINCT away_team FROM $table 
         WHERE away_team IS NOT NULL AND away_team != ''
         ORDER BY home_team"
    );
    
    return $teams;
	}
    
    /**
     * AJAX handler para obtener equipos
     */
    public static function ajax_get_teams() {
        $teams = self::get_available_teams();
        wp_send_json_success($teams);
    }
}

// Registrar AJAX handlers
add_action('wp_ajax_ft_get_prediction', array('FT_Predictor', 'ajax_get_prediction'));
add_action('wp_ajax_nopriv_ft_get_prediction', array('FT_Predictor', 'ajax_get_prediction'));
add_action('wp_ajax_ft_get_teams', array('FT_Predictor', 'ajax_get_teams'));
add_action('wp_ajax_nopriv_ft_get_teams', array('FT_Predictor', 'ajax_get_teams'));