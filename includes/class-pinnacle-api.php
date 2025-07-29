<?php
/**
 * Clase para conectar con la API de Pinnacle
 */

if (!defined('ABSPATH')) {
    exit;
}

class FT_Pinnacle_API {
    
    private $base_url = 'https://api.pinnacle.com/v1/';
    private $username;
    private $password;
    private $sport_id = 29; // Soccer/Football
    
    public function __construct($username = null, $password = null) {
        $this->username = $username ?: get_option('ft_pinnacle_username');
        $this->password = $password ?: get_option('ft_pinnacle_password');
    }
    
    /**
     * Hacer petición a la API de Pinnacle
     */
    private function make_request($endpoint, $params = array()) {
        $url = $this->base_url . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            throw new Exception('Error de conexión: ' . $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code !== 200) {
            throw new Exception("Error API Pinnacle: HTTP $code - $body");
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
        }
        
        return $data;
    }
    
    /**
     * Obtener ligas disponibles
     */
    public function get_leagues() {
        try {
            $data = $this->make_request('sports/' . $this->sport_id . '/leagues');
            return $data['leagues'] ?? array();
        } catch (Exception $e) {
            error_log('Pinnacle API - Error getting leagues: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Obtener fixtures (próximos partidos)
     */
    public function get_fixtures($league_ids = null, $since = null) {
        $params = array(
            'sportId' => $this->sport_id
        );
        
        if ($league_ids) {
            $params['leagueIds'] = is_array($league_ids) ? implode(',', $league_ids) : $league_ids;
        }
        
        if ($since) {
            $params['since'] = $since;
        }
        
        try {
            $data = $this->make_request('fixtures', $params);
            return $data['fixtures'] ?? array();
        } catch (Exception $e) {
            error_log('Pinnacle API - Error getting fixtures: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Obtener odds (cuotas)
     */
    public function get_odds($league_ids = null, $since = null, $odds_format = 'decimal') {
        $params = array(
            'sportId' => $this->sport_id,
            'oddsFormat' => $odds_format
        );
        
        if ($league_ids) {
            $params['leagueIds'] = is_array($league_ids) ? implode(',', $league_ids) : $league_ids;
        }
        
        if ($since) {
            $params['since'] = $since;
        }
        
        try {
            $data = $this->make_request('odds', $params);
            return $data['odds'] ?? array();
        } catch (Exception $e) {
            error_log('Pinnacle API - Error getting odds: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Sincronizar fixtures con la base de datos
     */
    public function sync_fixtures($league_ids = null) {
        global $wpdb;
        
        $fixtures = $this->get_fixtures($league_ids);
        $table = $wpdb->prefix . 'ft_fixtures';
        $synced = 0;
        
        foreach ($fixtures as $fixture) {
            // Verificar si ya existe
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE pinnacle_id = %s",
                $fixture['id']
            ));
            
            $data = array(
                'pinnacle_id' => $fixture['id'],
                'sport' => 'football',
                'league' => $fixture['league']['name'] ?? 'Unknown',
                'league_id' => $fixture['league']['id'] ?? 0,
                'home_team' => $fixture['home'] ?? 'Unknown',
                'away_team' => $fixture['away'] ?? 'Unknown',
                'start_time' => $this->convert_pinnacle_time($fixture['starts']),
                'status' => $this->map_fixture_status($fixture['status'] ?? 'O'),
                'period_number' => $fixture['period'] ?? 0,
                'home_score' => $fixture['homeScore'] ?? null,
                'away_score' => $fixture['awayScore'] ?? null
            );
            
            if ($exists) {
                $wpdb->update($table, $data, array('id' => $exists));
            } else {
                $wpdb->insert($table, $data);
                $synced++;
            }
        }
        
        return $synced;
    }
    
    /**
     * Sincronizar odds con la base de datos
     */
    public function sync_odds($league_ids = null) {
        global $wpdb;
        
        $odds_data = $this->get_odds($league_ids);
        $fixtures_table = $wpdb->prefix . 'ft_fixtures';
        $odds_table = $wpdb->prefix . 'ft_odds';
        $synced = 0;
        
        foreach ($odds_data as $odds) {
            // Buscar fixture en BD
            $fixture_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $fixtures_table WHERE pinnacle_id = %s",
                $odds['id']
            ));
            
            if (!$fixture_id) {
                continue; // Skip si no tenemos el fixture
            }
            
            // Procesar diferentes tipos de apuestas
            if (isset($odds['periods'][0]['moneyline'])) {
                $this->save_moneyline_odds($fixture_id, $odds['id'], $odds['periods'][0]['moneyline']);
                $synced++;
            }
            
            if (isset($odds['periods'][0]['spread'])) {
                $this->save_spread_odds($fixture_id, $odds['id'], $odds['periods'][0]['spread']);
                $synced++;
            }
            
            if (isset($odds['periods'][0]['totals'])) {
                $this->save_totals_odds($fixture_id, $odds['id'], $odds['periods'][0]['totals']);
                $synced++;
            }
        }
        
        return $synced;
    }
    
    /**
     * Guardar odds de moneyline (1X2)
     */
    private function save_moneyline_odds($fixture_id, $pinnacle_fixture_id, $moneyline) {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_odds';
        
        $odds_data = array(
            array('bet_type' => 'home', 'odds' => $moneyline['home'] ?? null),
            array('bet_type' => 'draw', 'odds' => $moneyline['draw'] ?? null),
            array('bet_type' => 'away', 'odds' => $moneyline['away'] ?? null)
        );
        
        foreach ($odds_data as $odd) {
            if ($odd['odds'] === null) continue;
            
            $data = array(
                'fixture_id' => $fixture_id,
                'pinnacle_fixture_id' => $pinnacle_fixture_id,
                'market_type' => 'moneyline',
                'bet_type' => $odd['bet_type'],
                'odds' => $odd['odds'],
                'decimal_odds' => $odd['odds'],
                'implied_probability' => round(1 / $odd['odds'], 4)
            );
            
            // Actualizar o insertar
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE fixture_id = %d AND market_type = %s AND bet_type = %s",
                $fixture_id, 'moneyline', $odd['bet_type']
            ));
            
            if ($exists) {
                $wpdb->update($table, $data, array('id' => $exists));
            } else {
                $wpdb->insert($table, $data);
            }
        }
    }
    
    /**
     * Guardar odds de spread
     */
    private function save_spread_odds($fixture_id, $pinnacle_fixture_id, $spread) {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_odds';
        
        if (!isset($spread['home']) || !isset($spread['away'])) {
            return;
        }
        
        $odds_data = array(
            array('bet_type' => 'home', 'odds' => $spread['home'], 'line' => $spread['hdp'] ?? 0),
            array('bet_type' => 'away', 'odds' => $spread['away'], 'line' => -($spread['hdp'] ?? 0))
        );
        
        foreach ($odds_data as $odd) {
            $data = array(
                'fixture_id' => $fixture_id,
                'pinnacle_fixture_id' => $pinnacle_fixture_id,
                'market_type' => 'spread',
                'bet_type' => $odd['bet_type'],
                'odds' => $odd['odds'],
                'decimal_odds' => $odd['odds'],
                'implied_probability' => round(1 / $odd['odds'], 4),
                'line_value' => $odd['line']
            );
            
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE fixture_id = %d AND market_type = %s AND bet_type = %s",
                $fixture_id, 'spread', $odd['bet_type']
            ));
            
            if ($exists) {
                $wpdb->update($table, $data, array('id' => $exists));
            } else {
                $wpdb->insert($table, $data);
            }
        }
    }
    
    /**
     * Guardar odds de totals (Over/Under)
     */
    private function save_totals_odds($fixture_id, $pinnacle_fixture_id, $totals) {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_odds';
        
        if (!isset($totals['over']) || !isset($totals['under'])) {
            return;
        }
        
        $odds_data = array(
            array('bet_type' => 'over', 'odds' => $totals['over'], 'line' => $totals['points'] ?? 2.5),
            array('bet_type' => 'under', 'odds' => $totals['under'], 'line' => $totals['points'] ?? 2.5)
        );
        
        foreach ($odds_data as $odd) {
            $data = array(
                'fixture_id' => $fixture_id,
                'pinnacle_fixture_id' => $pinnacle_fixture_id,
                'market_type' => 'total',
                'bet_type' => $odd['bet_type'],
                'odds' => $odd['odds'],
                'decimal_odds' => $odd['odds'],
                'implied_probability' => round(1 / $odd['odds'], 4),
                'line_value' => $odd['line']
            );
            
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE fixture_id = %d AND market_type = %s AND bet_type = %s AND line_value = %s",
                $fixture_id, 'total', $odd['bet_type'], $odd['line']
            ));
            
            if ($exists) {
                $wpdb->update($table, $data, array('id' => $exists));
            } else {
                $wpdb->insert($table, $data);
            }
        }
    }
    
    /**
     * Convertir tiempo de Pinnacle a formato MySQL
     */
    private function convert_pinnacle_time($pinnacle_time) {
        return date('Y-m-d H:i:s', strtotime($pinnacle_time));
    }
    
    /**
     * Mapear estado del fixture
     */
    private function map_fixture_status($status) {
        $status_map = array(
            'O' => 'upcoming',
            'H' => 'live',
            'C' => 'completed',
            'I' => 'completed'
        );
        
        return $status_map[$status] ?? 'upcoming';
    }
    
    /**
     * Test de conexión con la API
     */
    public function test_connection() {
        try {
            $leagues = $this->get_leagues();
            return array(
                'success' => true,
                'message' => 'Conexión exitosa',
                'leagues_count' => count($leagues),
                'sample_leagues' => array_slice($leagues, 0, 3)
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
}