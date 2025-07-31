<?php
/**
 * Gestor de configuración centralizado
 */

class FT_Config_Manager {
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Obtener configuración de value betting
     */
    public function get_value_betting_config() {
        return [
            'bankroll' => get_option('ft_bankroll', 1000),
            'min_value_threshold' => get_option('ft_min_value_threshold', 5.0),
            'min_confidence_threshold' => get_option('ft_min_confidence_threshold', 0.6),
            'max_stake_percentage' => get_option('ft_max_stake_percentage', 5),
            'kelly_fraction' => get_option('ft_kelly_fraction', 0.25),
            'markets_enabled' => get_option('ft_markets_enabled', 'moneyline,total'),
            'auto_analyze' => get_option('ft_auto_analyze', 1),
            'min_odds' => get_option('ft_min_odds', 1.6),
            'max_odds' => get_option('ft_max_odds', 4.0)
        ];
    }
    
    /**
     * Obtener configuración de Pinnacle
     */
    public function get_pinnacle_config() {
        return [
            'username' => get_option('ft_pinnacle_username'),
            'password' => get_option('ft_pinnacle_password'),
            'sync_frequency' => get_option('ft_pinnacle_sync_frequency', '1hour')
        ];
    }
    
    /**
     * Obtener configuración de la base de datos para Python
     */
    public function get_python_db_config() {
        global $wpdb;
        
        $config = [
            'host' => DB_HOST,
            'user' => DB_USER,
            'password' => DB_PASSWORD,
            'database' => DB_NAME,
            'table_prefix' => $wpdb->prefix // PP0Fhoci_
        ];
        
        // Extraer puerto si está en el host
        if (strpos(DB_HOST, ':') !== false) {
            list($host, $port) = explode(':', DB_HOST, 2);
            $config['host'] = $host;
            $config['port'] = intval($port);
        }
        
        return $config;
    }
    
    /**
     * Guardar configuración de value betting
     */
    public function save_value_betting_config($config) {
        foreach ($config as $key => $value) {
            update_option('ft_' . $key, $value);
        }
    }
}
