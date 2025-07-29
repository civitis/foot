<?php
/**
 * Clase para analizar value bets comparando predicciones vs cuotas del mercado
 */

if (!defined('ABSPATH')) {
    exit;
}

class FT_Value_Analyzer {
    
    private $min_value_threshold = 5.0; // Mínimo 5% de valor
    private $min_confidence_threshold = 0.6; // Mínimo 60% de confianza
    private $bankroll = 1000; // Bankroll por defecto
    private $max_stake_percentage = 5; // Máximo 5% del bankroll por apuesta
    
    public function __construct($options = array()) {
        $this->min_value_threshold = $options['min_value_threshold'] ?? get_option('ft_min_value_threshold', 5.0);
        $this->min_confidence_threshold = $options['min_confidence_threshold'] ?? get_option('ft_min_confidence_threshold', 0.6);
        $this->bankroll = $options['bankroll'] ?? get_option('ft_bankroll', 1000);
        $this->max_stake_percentage = $options['max_stake_percentage'] ?? get_option('ft_max_stake_percentage', 5);
    }
    
    /**
     * Analizar todos los fixtures próximos para encontrar value bets
     */
    public function analyze_all_fixtures($limit = 50) {
        global $wpdb;
        
        // Obtener fixtures próximos con odds
        $sql = "
            SELECT DISTINCT f.*, 
                   f.id as fixture_id,
                   f.home_team, 
                   f.away_team,
                   f.start_time,
                   f.league
            FROM {$wpdb->prefix}ft_fixtures f
            INNER JOIN {$wpdb->prefix}ft_odds o ON f.id = o.fixture_id
            WHERE f.start_time > NOW() 
            AND f.start_time < DATE_ADD(NOW(), INTERVAL 7 DAY)
            AND f.status = 'upcoming'
            ORDER BY f.start_time ASC
            LIMIT %d
        ";
        
        $fixtures = $wpdb->get_results($wpdb->prepare($sql, $limit));
        
        $value_bets = array();
        $processed = 0;
        $found = 0;
        
        foreach ($fixtures as $fixture) {
            $processed++;
            
            try {
                // Obtener predicción para este fixture
                $prediction = $this->get_or_create_prediction($fixture);
                
                if (!$prediction) {
                    continue;
                }
                
                // Analizar cada mercado
                $fixture_value_bets = $this->analyze_fixture($fixture, $prediction);
                
                if (!empty($fixture_value_bets)) {
                    $value_bets = array_merge($value_bets, $fixture_value_bets);
                    $found += count($fixture_value_bets);
                }
                
            } catch (Exception $e) {
                error_log("Error analizando fixture {$fixture->id}: " . $e->getMessage());
            }
        }
        
        // Ordenar por valor descendente
        usort($value_bets, function($a, $b) {
            return $b['value_percentage'] <=> $a['value_percentage'];
        });
        
        return array(
            'processed_fixtures' => $processed,
            'value_bets_found' => $found,
            'value_bets' => array_slice($value_bets, 0, 20), // Top 20
            'analysis_time' => current_time('mysql')
        );
    }
    
    /**
     * Analizar un fixture específico
     */
    public function analyze_fixture($fixture, $prediction) {
        global $wpdb;
        
        $value_bets = array();
        
        // Obtener todas las odds para este fixture
        $odds = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ft_odds WHERE fixture_id = %d ORDER BY market_type, bet_type",
            $fixture->fixture_id ?? $fixture->id
        ));
        
        if (empty($odds)) {
            return $value_bets;
        }
        
        // Agrupar odds por mercado
        $markets = array();
        foreach ($odds as $odd) {
            $markets[$odd->market_type][] = $odd;
        }
        
        // Analizar cada mercado
        foreach ($markets as $market_type => $market_odds) {
            switch ($market_type) {
                case 'moneyline':
                    $market_value_bets = $this->analyze_moneyline_market($fixture, $prediction, $market_odds);
                    break;
                case 'total':
                    $market_value_bets = $this->analyze_total_market($fixture, $prediction, $market_odds);
                    break;
                case 'spread':
                    $market_value_bets = $this->analyze_spread_market($fixture, $prediction, $market_odds);
                    break;
                default:
                    $market_value_bets = array();
            }
            
            $value_bets = array_merge($value_bets, $market_value_bets);
        }
        
        // Guardar value bets en BD
        $this->save_value_bets($value_bets);
        
        return $value_bets;
    }
    
    /**
     * Analizar mercado moneyline (1X2)
     */
    private function analyze_moneyline_market($fixture, $prediction, $odds) {
        $value_bets = array();
        
        // Mapear nuestras probabilidades
        $our_probs = array(
            'home' => $prediction['probabilities']['home_win'] ?? 0,
            'draw' => $prediction['probabilities']['draw'] ?? 0,
            'away' => $prediction['probabilities']['away_win'] ?? 0
        );
        
        foreach ($odds as $odd) {
            $bet_type = $odd->bet_type;
            $our_prob = $our_probs[$bet_type] ?? 0;
            
            if ($our_prob <= 0) continue;
            
            $market_prob = $odd->implied_probability;
            $market_odds = $odd->decimal_odds;
            
            // Calcular valor
            $value_analysis = $this->calculate_value($our_prob, $market_odds, $market_prob);
            
            if ($value_analysis['has_value']) {
                $confidence = $this->calculate_confidence($prediction, $bet_type, 'moneyline');
                
                if ($confidence >= $this->min_confidence_threshold) {
                    $value_bets[] = array(
                        'fixture_id' => $fixture->fixture_id ?? $fixture->id,
                        'fixture' => $fixture,
                        'market_type' => 'moneyline',
                        'bet_type' => $bet_type,
                        'our_probability' => $our_prob,
                        'market_odds' => $market_odds,
                        'implied_probability' => $market_prob,
                        'value_percentage' => $value_analysis['value_percentage'],
                        'expected_value' => $value_analysis['expected_value'],
                        'confidence_score' => $confidence,
                        'recommended_stake' => $this->calculate_kelly_stake($our_prob, $market_odds),
                        'bet_description' => $this->get_bet_description($fixture, 'moneyline', $bet_type),
                        'odds_id' => $odd->id
                    );
                }
            }
        }
        
        return $value_bets;
    }
    
    /**
     * Analizar mercado de totales (Over/Under)
     */
    private function analyze_total_market($fixture, $prediction, $odds) {
        $value_bets = array();
        
        // Necesitamos predecir el total de goles
        $predicted_total_goals = $this->predict_total_goals($fixture, $prediction);
        
        // Agrupar por línea
        $lines = array();
        foreach ($odds as $odd) {
            $line = $odd->line_value;
            $lines[$line][] = $odd;
        }
        
        foreach ($lines as $line => $line_odds) {
            // Calcular probabilidades para esta línea
            $over_prob = $this->calculate_over_probability($predicted_total_goals, $line);
            $under_prob = 1 - $over_prob;
            
            $our_probs = array(
                'over' => $over_prob,
                'under' => $under_prob
            );
            
            foreach ($line_odds as $odd) {
                $bet_type = $odd->bet_type;
                $our_prob = $our_probs[$bet_type] ?? 0;
                
                if ($our_prob <= 0) continue;
                
                $market_prob = $odd->implied_probability;
                $market_odds = $odd->decimal_odds;
                
                $value_analysis = $this->calculate_value($our_prob, $market_odds, $market_prob);
                
                if ($value_analysis['has_value']) {
                    $confidence = $this->calculate_confidence($prediction, $bet_type, 'total');
                    
                    if ($confidence >= $this->min_confidence_threshold) {
                        $value_bets[] = array(
                            'fixture_id' => $fixture->fixture_id ?? $fixture->id,
                            'fixture' => $fixture,
                            'market_type' => 'total',
                            'bet_type' => $bet_type,
                            'line_value' => $line,
                            'our_probability' => $our_prob,
                            'market_odds' => $market_odds,
                            'implied_probability' => $market_prob,
                            'value_percentage' => $value_analysis['value_percentage'],
                            'expected_value' => $value_analysis['expected_value'],
                            'confidence_score' => $confidence,
                            'recommended_stake' => $this->calculate_kelly_stake($our_prob, $market_odds),
                            'bet_description' => $this->get_bet_description($fixture, 'total', $bet_type, $line),
                            'predicted_total' => $predicted_total_goals,
                            'odds_id' => $odd->id
                        );
                    }
                }
            }
        }
        
        return $value_bets;
    }
    
    /**
     * Analizar mercado de spread (Handicap)
     */
    private function analyze_spread_market($fixture, $prediction, $odds) {
        $value_bets = array();
        
        // Predecir diferencia de goles
        $predicted_goal_difference = $this->predict_goal_difference($fixture, $prediction);
        
        // Agrupar por línea
        $lines = array();
        foreach ($odds as $odd) {
            $line = $odd->line_value;
            $lines[$line][] = $odd;
        }
        
        foreach ($lines as $line => $line_odds) {
            // Calcular probabilidades para esta línea de handicap
            $home_prob = $this->calculate_spread_probability($predicted_goal_difference, $line, 'home');
            $away_prob = $this->calculate_spread_probability($predicted_goal_difference, $line, 'away');
            
            $our_probs = array(
                'home' => $home_prob,
                'away' => $away_prob
            );
            
            foreach ($line_odds as $odd) {
                $bet_type = $odd->bet_type;
                $our_prob = $our_probs[$bet_type] ?? 0;
                
                if ($our_prob <= 0) continue;
                
                $market_prob = $odd->implied_probability;
                $market_odds = $odd->decimal_odds;
                
                $value_analysis = $this->calculate_value($our_prob, $market_odds, $market_prob);
                
                if ($value_analysis['has_value']) {
                    $confidence = $this->calculate_confidence($prediction, $bet_type, 'spread');
                    
                    if ($confidence >= $this->min_confidence_threshold) {
                        $value_bets[] = array(
                            'fixture_id' => $fixture->fixture_id ?? $fixture->id,
                            'fixture' => $fixture,
                            'market_type' => 'spread',
                            'bet_type' => $bet_type,
                            'line_value' => $line,
                            'our_probability' => $our_prob,
                            'market_odds' => $market_odds,
                            'implied_probability' => $market_prob,
                            'value_percentage' => $value_analysis['value_percentage'],
                            'expected_value' => $value_analysis['expected_value'],
                            'confidence_score' => $confidence,
                            'recommended_stake' => $this->calculate_kelly_stake($our_prob, $market_odds),
                            'bet_description' => $this->get_bet_description($fixture, 'spread', $bet_type, $line),
                            'predicted_difference' => $predicted_goal_difference,
                            'odds_id' => $odd->id
                        );
                    }
                }
            }
        }
        
        return $value_bets;
    }
    
    /**
     * Calcular valor de una apuesta
     */
    private function calculate_value($our_probability, $market_odds, $market_probability) {
        // Expected Value = (Our_Prob * (Odds - 1)) - (1 - Our_Prob)
        $expected_value = ($our_probability * ($market_odds - 1)) - (1 - $our_probability);
        
        // Value percentage = ((Our_Prob * Odds) - 1) * 100
        $value_percentage = (($our_probability * $market_odds) - 1) * 100;
        
        $has_value = $value_percentage >= $this->min_value_threshold;
        
        return array(
            'expected_value' => round($expected_value, 4),
            'value_percentage' => round($value_percentage, 2),
            'has_value' => $has_value,
            'edge' => round($our_probability - $market_probability, 4)
        );
    }
    
    /**
     * Calcular stake usando Kelly Criterion
     */
    private function calculate_kelly_stake($probability, $odds) {
        // Kelly = (bp - q) / b
        // b = odds - 1, p = probability, q = 1 - probability
        $b = $odds - 1;
        $p = $probability;
        $q = 1 - $probability;
        
        $kelly_fraction = ($b * $p - $q) / $b;
        
        // Limitar a máximo del bankroll
        $kelly_fraction = max(0, min($kelly_fraction, $this->max_stake_percentage / 100));
        
        // Usar Kelly fraccionario (25% del Kelly completo para reducir volatilidad)
        $fractional_kelly = $kelly_fraction * 0.25;
        
        $recommended_stake = $this->bankroll * $fractional_kelly;
        
        return array(
            'kelly_fraction' => round($kelly_fraction, 4),
            'fractional_kelly' => round($fractional_kelly, 4),
            'recommended_amount' => round($recommended_stake, 2),
            'percentage_of_bankroll' => round($fractional_kelly * 100, 2)
        );
    }
    
    /**
     * Calcular nivel de confianza
     */
    private function calculate_confidence($prediction, $bet_type, $market_type) {
        $base_confidence = $prediction['confidence'] ?? 0.5;
        
        // Ajustar por tipo de mercado
        $market_multipliers = array(
            'moneyline' => 1.0,
            'total' => 0.85,    // Menos confianza en totales
            'spread' => 0.9     // Menos confianza en spreads
        );
        
        $multiplier = $market_multipliers[$market_type] ?? 0.8;
        
        // Ajustar por tipo de apuesta
        if ($bet_type === 'draw') {
            $multiplier *= 0.8; // Empates son más difíciles de predecir
        }
        
        $confidence = $base_confidence * $multiplier;
        
        return round(min(1.0, max(0.0, $confidence)), 3);
    }
    
    /**
     * Obtener o crear predicción para un fixture
     */
    private function get_or_create_prediction($fixture) {
        // Intentar obtener predicción existente
        global $wpdb;
        
        $prediction = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ft_predictions 
             WHERE home_team = %s AND away_team = %s 
             AND predicted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY predicted_at DESC LIMIT 1",
            $fixture->home_team,
            $fixture->away_team
        ));
        
        if ($prediction) {
            return array(
                'prediction' => $prediction->prediction,
                'confidence' => $prediction->probability,
                'probabilities' => array(
                    'home_win' => $prediction->prediction === 'H' ? $prediction->probability : (1 - $prediction->probability) / 2,
                    'draw' => $prediction->prediction === 'D' ? $prediction->probability : 0.25,
                    'away_win' => $prediction->prediction === 'A' ? $prediction->probability : (1 - $prediction->probability) / 2
                )
            );
        }
        
        // Si no existe, crear nueva predicción usando nuestro modelo
        if (class_exists('FT_Predictor')) {
            try {
                $new_prediction = FT_Predictor::predict_match($fixture->home_team, $fixture->away_team);
                
                if (!isset($new_prediction['error'])) {
                    return $new_prediction;
                }
            } catch (Exception $e) {
                error_log("Error creando predicción: " . $e->getMessage());
            }
        }
        
        return null;
    }
    
    /**
     * Predecir total de goles
     */
    private function predict_total_goals($fixture, $prediction) {
        // Implementación simplificada basada en estadísticas
        $home_avg = 1.5; // Podría obtenerse de las estadísticas del equipo
        $away_avg = 1.3;
        
        // Ajustar basado en la fuerza relativa de los equipos
        $strength_factor = $prediction['confidence'] ?? 0.5;
        
        return $home_avg + $away_avg + ($strength_factor * 0.5);
    }
    
    /**
     * Predecir diferencia de goles
     */
    private function predict_goal_difference($fixture, $prediction) {
        $home_expected = 1.5;
        $away_expected = 1.3;
        
        // Ajustar basado en la predicción
        if ($prediction['prediction'] === 'H') {
            $home_expected += 0.5;
        } elseif ($prediction['prediction'] === 'A') {
            $away_expected += 0.5;
        }
        
        return $home_expected - $away_expected;
    }
    
    /**
     * Calcular probabilidad Over/Under
     */
    private function calculate_over_probability($predicted_total, $line) {
        // Usando distribución de Poisson simplificada
        $lambda = $predicted_total;
        
        if ($predicted_total > $line) {
            return 0.65; // Probabilidad alta de Over
        } elseif ($predicted_total < $line - 0.5) {
            return 0.35; // Probabilidad baja de Over
        } else {
            return 0.5; // Cerca de la línea
        }
    }
    
    /**
     * Calcular probabilidad de spread
     */
    private function calculate_spread_probability($predicted_difference, $line, $bet_type) {
        if ($bet_type === 'home') {
            return $predicted_difference > $line ? 0.6 : 0.4;
        } else {
            return $predicted_difference < $line ? 0.6 : 0.4;
        }
    }
    
    /**
     * Obtener descripción de la apuesta
     */
    private function get_bet_description($fixture, $market_type, $bet_type, $line = null) {
        $home = $fixture->home_team;
        $away = $fixture->away_team;
        
        switch ($market_type) {
            case 'moneyline':
                $descriptions = array(
                    'home' => "$home ganador",
                    'draw' => "Empate",
                    'away' => "$away ganador"
                );
                return $descriptions[$bet_type] ?? '';
                
            case 'total':
                return $bet_type === 'over' ? "Más de $line goles" : "Menos de $line goles";
                
            case 'spread':
                $line_text = $line > 0 ? "+$line" : (string)$line;
                return $bet_type === 'home' ? "$home $line_text" : "$away " . (-$line);
                
            default:
                return "$market_type $bet_type";
        }
    }
    
    /**
     * Guardar value bets en la base de datos
     */
    private function save_value_bets($value_bets) {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_value_bets';
        
        foreach ($value_bets as $bet) {
            // Verificar si ya existe
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table 
                 WHERE fixture_id = %d AND market_type = %s AND bet_type = %s 
                 AND DATE(created_at) = DATE(NOW())",
                $bet['fixture_id'],
                $bet['market_type'],
                $bet['bet_type']
            ));
            
            $data = array(
                'fixture_id' => $bet['fixture_id'],
                'market_type' => $bet['market_type'],
                'bet_type' => $bet['bet_type'],
                'our_probability' => $bet['our_probability'],
                'market_odds' => $bet['market_odds'],
                'implied_probability' => $bet['implied_probability'],
                'value_percentage' => $bet['value_percentage'],
                'expected_value' => $bet['expected_value'],
                'confidence_score' => $bet['confidence_score'],
                'recommended_stake' => $bet['recommended_stake']['recommended_amount'] ?? 0
            );
            
            if ($exists) {
                $wpdb->update($table, $data, array('id' => $exists));
            } else {
                $wpdb->insert($table, $data);
            }
        }
    }
    
    /**
     * Obtener mejores value bets
     */
    public function get_top_value_bets($limit = 20) {
        global $wpdb;
        
        $sql = "
            SELECT vb.*, 
                   f.home_team,
                   f.away_team,
                   f.start_time,
                   f.league,
                   o.decimal_odds
            FROM {$wpdb->prefix}ft_value_bets vb
            INNER JOIN {$wpdb->prefix}ft_fixtures f ON vb.fixture_id = f.id
            LEFT JOIN {$wpdb->prefix}ft_odds o ON f.id = o.fixture_id 
                AND vb.market_type = o.market_type 
                AND vb.bet_type = o.bet_type
            WHERE f.start_time > NOW()
            AND vb.status = 'active'
            AND vb.value_percentage >= %f
            ORDER BY vb.value_percentage DESC, vb.confidence_score DESC
            LIMIT %d
        ";
        
        return $wpdb->get_results($wpdb->prepare($sql, $this->min_value_threshold, $limit));
    }
}