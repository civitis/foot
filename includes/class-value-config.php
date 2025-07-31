<?php
/**
 * Gestor de configuración de Value Betting
 */

class FT_Value_Config {
    
    private static $table_name = 'ft_value_config';
    
    /**
     * Obtener configuración actual
     */
    public static function get_config() {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        $config = $wpdb->get_row("SELECT * FROM $table LIMIT 1", ARRAY_A);
        
        if (!$config) {
            // Crear configuración por defecto
            self::create_default_config();
            $config = $wpdb->get_row("SELECT * FROM $table LIMIT 1", ARRAY_A);
        }
        
        return $config;
    }
    
    /**
     * Actualizar configuración
     */
    public static function update_config($data) {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        // Validar datos
        $validated_data = self::validate_config($data);
        
        $existing = $wpdb->get_row("SELECT id FROM $table LIMIT 1");
        
        if ($existing) {
            return $wpdb->update($table, $validated_data, array('id' => $existing->id));
        } else {
            return $wpdb->insert($table, $validated_data);
        }
    }
    
    /**
     * Crear configuración por defecto
     */
    private static function create_default_config() {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        
        $default_config = array(
            'min_value' => 10.00,
            'min_confidence' => 0.400,
            'max_stake_percentage' => 5.00,
            'kelly_fraction' => 0.250,
            'markets_enabled' => 'moneyline,total',
            'auto_analyze' => 1,
            'min_odds' => 1.600,
            'max_odds' => 4.000,
            'stake_system' => 'variable',
            'base_unit' => 10.00,
            'max_daily_bets' => 10,
            'stop_loss_daily' => 100.00,
            'stop_loss_weekly' => 300.00,
            'min_bankroll_percentage' => 20.00,
            'streak_protection' => 1
        );
        
        $wpdb->insert($table, $default_config);
    }
    
    /**
     * Validar configuración
     */
    private static function validate_config($data) {
        $validated = array();
        
        // Validaciones numéricas
        $validated['min_value'] = max(1, min(50, floatval($data['min_value'] ?? 10)));
        $validated['min_confidence'] = max(0.1, min(0.9, floatval($data['min_confidence'] ?? 0.4)));
        $validated['max_stake_percentage'] = max(1, min(20, floatval($data['max_stake_percentage'] ?? 5)));
        $validated['kelly_fraction'] = max(0.1, min(1, floatval($data['kelly_fraction'] ?? 0.25)));
        $validated['min_odds'] = max(1.1, min(10, floatval($data['min_odds'] ?? 1.6)));
        $validated['max_odds'] = max(2, min(20, floatval($data['max_odds'] ?? 4)));
        $validated['base_unit'] = max(1, min(1000, floatval($data['base_unit'] ?? 10)));
        $validated['max_daily_bets'] = max(1, min(50, intval($data['max_daily_bets'] ?? 10)));
        $validated['stop_loss_daily'] = max(10, min(10000, floatval($data['stop_loss_daily'] ?? 100)));
        $validated['stop_loss_weekly'] = max(50, min(50000, floatval($data['stop_loss_weekly'] ?? 300)));
        $validated['min_bankroll_percentage'] = max(5, min(90, floatval($data['min_bankroll_percentage'] ?? 20)));
        
        // Validaciones de texto
        $validated['markets_enabled'] = sanitize_text_field($data['markets_enabled'] ?? 'moneyline,total');
        $validated['stake_system'] = in_array($data['stake_system'] ?? 'variable', ['fixed', 'variable', 'kelly']) 
            ? $data['stake_system'] : 'variable';
        
        // Validaciones booleanas
        $validated['auto_analyze'] = isset($data['auto_analyze']) ? 1 : 0;
        $validated['streak_protection'] = isset($data['streak_protection']) ? 1 : 0;
        
        // JSON fields
        if (isset($data['avoid_teams']) && is_array($data['avoid_teams'])) {
            $validated['avoid_teams'] = json_encode($data['avoid_teams']);
        }
        
        if (isset($data['preferred_leagues']) && is_array($data['preferred_leagues'])) {
            $validated['preferred_leagues'] = json_encode($data['preferred_leagues']);
        }
        
        if (isset($data['time_restrictions']) && is_array($data['time_restrictions'])) {
            $validated['time_restrictions'] = json_encode($data['time_restrictions']);
        }
        
        return $validated;
    }
    
    /**
     * Crear tabla si no existe
     */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `min_value` decimal(5,2) DEFAULT 10.00 COMMENT 'Valor mínimo en porcentaje',
            `min_confidence` decimal(4,3) DEFAULT 0.400 COMMENT 'Confianza mínima',
            `max_stake_percentage` decimal(5,2) DEFAULT 5.00 COMMENT 'Porcentaje máximo del bankroll',
            `kelly_fraction` decimal(4,3) DEFAULT 0.250 COMMENT 'Fracción de Kelly',
            `markets_enabled` varchar(100) DEFAULT 'moneyline,total' COMMENT 'Mercados habilitados',
            `auto_analyze` tinyint(1) DEFAULT 1 COMMENT 'Análisis automático',
            `min_odds` decimal(6,3) DEFAULT 1.600 COMMENT 'Cuota mínima',
            `max_odds` decimal(6,3) DEFAULT 4.000 COMMENT 'Cuota máxima',
            `stake_system` varchar(20) DEFAULT 'variable' COMMENT 'Sistema de stakes: fixed, variable, kelly',
            `base_unit` decimal(10,2) DEFAULT 10.00 COMMENT 'Unidad base de apuesta',
            `max_daily_bets` int(11) DEFAULT 10 COMMENT 'Máximo de apuestas por día',
            `stop_loss_daily` decimal(10,2) DEFAULT 100.00 COMMENT 'Stop loss diario',
            `stop_loss_weekly` decimal(10,2) DEFAULT 300.00 COMMENT 'Stop loss semanal',
            `min_bankroll_percentage` decimal(5,2) DEFAULT 20.00 COMMENT 'Porcentaje mínimo del bankroll para apostar',
            `avoid_teams` text DEFAULT NULL COMMENT 'Equipos a evitar (JSON)',
            `preferred_leagues` text DEFAULT NULL COMMENT 'Ligas preferidas (JSON)',
            `time_restrictions` text DEFAULT NULL COMMENT 'Restricciones horarias (JSON)',
            `streak_protection` tinyint(1) DEFAULT 1 COMMENT 'Protección contra malas rachas',
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * AJAX handler para guardar configuración
     */
    public static function ajax_save_config() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos');
        }
        
        check_ajax_referer('ft_nonce', 'nonce');
        
        $result = self::update_config($_POST);
        
        if ($result !== false) {
            wp_send_json_success('Configuración guardada');
        } else {
            wp_send_json_error('Error al guardar configuración');
        }
    }
}

// Registrar AJAX handler
add_action('wp_ajax_ft_save_value_config', array('FT_Value_Config', 'ajax_save_config'));
