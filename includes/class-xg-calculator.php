<?php
/**
 * Calculadora de Expected Goals (xG) para Football Tipster
 * Basada en datos estadísticos de Football-Data.co.uk
 * 
 * Archivo: includes/class-xg-calculator.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class FootballTipster_xG_Calculator {
    
    /**
     * Calcula xG para un equipo basado en estadísticas del partido
     * 
     * @param array $stats Estadísticas del partido
     * @return float xG calculado
     */
    public function calculate_xG($stats) {
        // Inicializar xG base
        $xG = 0;
        
        // 1. xG base por tiros
        $shots = isset($stats['shots']) ? (int)$stats['shots'] : 0;
        $shots_on_target = isset($stats['shots_on_target']) ? (int)$stats['shots_on_target'] : 0;
        $woodwork = isset($stats['woodwork']) ? (int)$stats['woodwork'] : 0;
        
        // Calidad de tiro base
        if ($shots > 0) {
            $shot_quality = ($shots_on_target + $woodwork) / $shots;
        } else {
            $shot_quality = 0;
        }
        
        // xG base por tiros (valor empírico ajustado)
        $xG += $shots * 0.08; // Cada tiro tiene valor base de 0.08 xG
        
        // 2. Bonus por tiros a puerta
        $xG += $shots_on_target * 0.12; // Tiros a puerta valen más
        
        // 3. Bonus por tiros al palo (casi gol)
        $xG += $woodwork * 0.4; // Tiros al palo tienen alta probabilidad
        
        // 4. Ajuste por corners (presión ofensiva)
        $corners = isset($stats['corners']) ? (int)$stats['corners'] : 0;
        $xG += $corners * 0.03; // Cada corner añade pequeño valor
        
        // 5. Ajuste por faltas cometidas por rival (presión en área)
        $rival_fouls = isset($stats['rival_fouls']) ? (int)$stats['rival_fouls'] : 0;
        if ($rival_fouls > 15) { // Muchas faltas = presión
            $xG += ($rival_fouls - 15) * 0.02;
        }
        
        // 6. Ajuste por eficiencia ofensiva
        if ($shots > 0) {
            $efficiency = $shots_on_target / $shots;
            if ($efficiency > 0.4) { // Muy eficiente
                $xG *= 1.1;
            } elseif ($efficiency < 0.2) { // Poco eficiente
                $xG *= 0.9;
            }
        }
        
        // 7. Ajuste por dominio territorial (corners como proxy)
        if ($corners > 8) { // Muchos corners = dominio
            $xG *= 1.05;
        }
        
        // 8. Límites realistas
        $xG = max(0, $xG); // No puede ser negativo
        $xG = min(5, $xG); // Máximo realista de 5 xG
        
        return round($xG, 2);
    }
    
    /**
     * Calcula xG para ambos equipos de un partido
     * 
     * @param array $match_data Datos del partido de Football-Data
     * @return array ['home_xG' => float, 'away_xG' => float]
     */
    public function calculate_match_xG($match_data) {
        // Preparar datos del equipo local
        $home_stats = array(
            'shots' => isset($match_data['hs']) ? $match_data['hs'] : (isset($match_data['HS']) ? $match_data['HS'] : 0),
            'shots_on_target' => isset($match_data['hst']) ? $match_data['hst'] : (isset($match_data['HST']) ? $match_data['HST'] : 0),
            'woodwork' => isset($match_data['hhw']) ? $match_data['hhw'] : (isset($match_data['HHW']) ? $match_data['HHW'] : 0),
            'corners' => isset($match_data['hc']) ? $match_data['hc'] : (isset($match_data['HC']) ? $match_data['HC'] : 0),
            'rival_fouls' => isset($match_data['af']) ? $match_data['af'] : (isset($match_data['AF']) ? $match_data['AF'] : 0),
        );
        
        // Preparar datos del equipo visitante
        $away_stats = array(
            'shots' => isset($match_data['as_shots']) ? $match_data['as_shots'] : (isset($match_data['AS']) ? $match_data['AS'] : 0),
            'shots_on_target' => isset($match_data['ast']) ? $match_data['ast'] : (isset($match_data['AST']) ? $match_data['AST'] : 0),
            'woodwork' => isset($match_data['ahw']) ? $match_data['ahw'] : (isset($match_data['AHW']) ? $match_data['AHW'] : 0),
            'corners' => isset($match_data['ac']) ? $match_data['ac'] : (isset($match_data['AC']) ? $match_data['AC'] : 0),
            'rival_fouls' => isset($match_data['hf']) ? $match_data['hf'] : (isset($match_data['HF']) ? $match_data['HF'] : 0),
        );
        
        return array(
            'home_xG' => $this->calculate_xG($home_stats),
            'away_xG' => $this->calculate_xG($away_stats)
        );
    }
    
    /**
     * Actualiza xG para todos los partidos sin xG en la base de datos
     */
    public function update_missing_xG() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ft_matches_advanced';
        
        // Obtener partidos sin xG
        $matches = $wpdb->get_results("
            SELECT * FROM $table_name 
            WHERE (home_xg IS NULL OR away_xg IS NULL) 
            AND hs IS NOT NULL 
            AND as_shots IS NOT NULL
            LIMIT 100
        ");
        
        $updated = 0;
        
        foreach ($matches as $match) {
            $match_array = (array) $match;
            $xG_data = $this->calculate_match_xG($match_array);
            
            $wpdb->update(
                $table_name,
                array(
                    'home_xg' => $xG_data['home_xG'],
                    'away_xg' => $xG_data['away_xG']
                ),
                array('id' => $match->id)
            );
            
            $updated++;
        }
        
        return $updated;
    }
    
    /**
     * Calcula precisión del modelo xG comparando con goles reales
     */
    public function calculate_xG_accuracy() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ft_matches_advanced';
        
        $matches = $wpdb->get_results("
            SELECT fthg, ftag, home_xg, away_xg 
            FROM $table_name 
            WHERE home_xg IS NOT NULL 
            AND away_xg IS NOT NULL 
            AND fthg IS NOT NULL 
            AND ftag IS NOT NULL
            LIMIT 1000
        ");
        
        $total_error = 0;
        $count = 0;
        
        foreach ($matches as $match) {
            $home_error = abs($match->fthg - $match->home_xg);
            $away_error = abs($match->ftag - $match->away_xg);
            
            $total_error += $home_error + $away_error;
            $count += 2; // Contamos local y visitante
        }
        
        if ($count > 0) {
            $avg_error = $total_error / $count;
            $accuracy = max(0, 100 - ($avg_error * 50)); // Convertir a porcentaje
            return round($accuracy, 1);
        }
        
        return 0;
    }
    
    /**
     * Obtiene estadísticas del modelo xG
     */
    public function get_xG_stats() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ft_matches_advanced';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_matches,
                SUM(CASE WHEN home_xg IS NOT NULL AND away_xg IS NOT NULL THEN 1 ELSE 0 END) as matches_with_xG,
                AVG(home_xg) as avg_home_xG,
                AVG(away_xg) as avg_away_xG
            FROM $table_name
        ");
        
        return array(
            'total_matches' => $stats->total_matches,
            'matches_with_xG' => $stats->matches_with_xG,
            'coverage' => $stats->total_matches > 0 ? round(($stats->matches_with_xG / $stats->total_matches) * 100, 1) : 0,
            'avg_home_xG' => round($stats->avg_home_xG, 2),
            'avg_away_xG' => round($stats->avg_away_xG, 2),
            'accuracy' => $this->calculate_xG_accuracy()
        );
    }
}

// NO AÑADIR ESTAS FUNCIONES - YA EXISTEN EN EL ARCHIVO PRINCIPAL
?>