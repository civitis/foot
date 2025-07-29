<?php

class FT_XG_Scraper {
    
    private $base_url = 'https://fbref.com';
    private $leagues = array(
        'premier-league' => '/en/comps/9/Premier-League-Stats',
        'la-liga' => '/en/comps/12/La-Liga-Stats',
        'serie-a' => '/en/comps/11/Serie-A-Stats',
        'bundesliga' => '/en/comps/20/Bundesliga-Stats',
        'ligue-1' => '/en/comps/13/Ligue-1-Stats'
    );
    
    /**
     * Obtiene xG de un partido específico
     */
    public function get_match_xg($home_team, $away_team, $date, $league = 'premier-league') {
        // Primero buscar el enlace del partido
        $match_url = $this->find_match_url($home_team, $away_team, $date, $league);
        
        if (!$match_url) {
            return null;
        }
        
        // Obtener datos xG del partido
        return $this->scrape_match_xg($match_url);
    }
    
    /**
     * Busca la URL de un partido específico
     */
    private function find_match_url($home_team, $away_team, $date, $league) {
        $season = date('Y', strtotime($date));
        $fixtures_url = $this->base_url . $this->leagues[$league];
        
        // Hacer petición HTTP
        $response = wp_remote_get($fixtures_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        // Parsear HTML con DOMDocument
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        // Buscar el partido
        $match_date = date('Y-m-d', strtotime($date));
        $query = "//td[@data-stat='date' and contains(text(), '$match_date')]/../td[@data-stat='home_team']/a[contains(text(), '$home_team')]/../..";
        
        $matches = $xpath->query($query);
        
        if ($matches->length > 0) {
            $match_row = $matches->item(0);
            $link = $xpath->query(".//td[@data-stat='match_report']/a", $match_row);
            
            if ($link->length > 0) {
                return $this->base_url . $link->item(0)->getAttribute('href');
            }
        }
        
        return null;
    }
    
    /**
     * Extrae xG de la página del partido
     */
    private function scrape_match_xg($match_url) {
        $response = wp_remote_get($match_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $html = wp_remote_retrieve_body($response);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        // Buscar tabla de estadísticas
        $xg_data = array();
        
        // Buscar xG en la tabla de estadísticas del equipo
        $home_xg = $xpath->query("//div[@id='team_stats']//td[contains(text(), 'xG')]/../td[1]");
        $away_xg = $xpath->query("//div[@id='team_stats']//td[contains(text(), 'xG')]/../td[2]");
        
        if ($home_xg->length > 0 && $away_xg->length > 0) {
            $xg_data['home_xg'] = floatval($home_xg->item(0)->textContent);
            $xg_data['away_xg'] = floatval($away_xg->item(0)->textContent);
        }
        
        return $xg_data;
    }
    
    /**
     * Actualiza xG para partidos existentes sin xG
     */
    public function update_missing_xg($limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_matches_advanced';
        
        // Obtener partidos sin xG
        $matches = $wpdb->get_results($wpdb->prepare(
            "SELECT id, home_team, away_team, date, division 
             FROM $table 
             WHERE home_xg IS NULL 
             AND date >= DATE_SUB(NOW(), INTERVAL 2 YEAR)
             ORDER BY date DESC 
             LIMIT %d",
            $limit
        ));
        
        $updated = 0;
        
        foreach ($matches as $match ) {
            // Mapear división a liga de FBref
            $league = $this->map_division_to_league($match->division);
            
            if ($league) {
                $xg_data = $this->get_match_xg(
                    $match->home_team,
                    $match->away_team,
                    $match->date,
                    $league
                );
                
                if ($xg_data) {
                    $wpdb->update(
                        $table,
                        $xg_data,
                        array('id' => $match->id)
                    );
                    $updated++;
                    
                    // Pausa para no sobrecargar el servidor
                    sleep(2);
                }
            }
        }
        
        return $updated;
    }
    
    /**
     * Mapea códigos de división a ligas de FBref
     */
    private function map_division_to_league($division) {
        $mapping = array(
            'E0' => 'premier-league',
            'E1' => 'championship',
            'SP1' => 'la-liga',
            'I1' => 'serie-a',
            'D1' => 'bundesliga',
            'F1' => 'ligue-1'
        );
        
        return isset($mapping[$division]) ? $mapping[$division] : null;
    }
}