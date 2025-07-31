<?php
/**
 * Creador de tablas de base de datos
 */

class FT_Table_Creator {
    
    public function create_all_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Usar el prefijo correcto
        $prefix = FT_Database_Utils::get_table_prefix(); // PP0Fhoci_
        
        $this->create_matches_table($prefix, $charset_collate);
        $this->create_team_stats_table($prefix, $charset_collate);
        $this->create_predictions_table($prefix, $charset_collate);
        $this->create_fixtures_table($prefix, $charset_collate);
        $this->create_odds_table($prefix, $charset_collate);
        $this->create_value_bets_table($prefix, $charset_collate);
        $this->create_config_table($prefix, $charset_collate);
        $this->create_sync_logs_table($prefix, $charset_collate);
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Ejecutar todas las tablas
        foreach ($this->get_table_queries() as $sql) {
            dbDelta($sql);
		
// Tabla para configuración de value betting
$table_value_config = $wpdb->prefix . 'ft_value_config';
$sql_value_config = "CREATE TABLE IF NOT EXISTS $table_value_config (
    id int(11) NOT NULL AUTO_INCREMENT,
    min_value_threshold decimal(5,2) DEFAULT 10.00 COMMENT 'Minimum value % to bet',
    min_confidence_threshold decimal(3,2) DEFAULT 0.40 COMMENT 'Minimum confidence to bet',
    max_stake_percentage decimal(5,2) DEFAULT 5.00 COMMENT 'Max % of bankroll per bet',
    kelly_fraction decimal(3,2) DEFAULT 0.25 COMMENT 'Kelly criterion fraction',
    markets_enabled varchar(255) DEFAULT 'moneyline' COMMENT 'Enabled markets',
    auto_analyze tinyint(1) DEFAULT 1 COMMENT 'Auto analyze fixtures',
    min_odds decimal(4,2) DEFAULT 1.60 COMMENT 'Minimum odds to consider',
    max_odds decimal(4,2) DEFAULT 4.00 COMMENT 'Maximum odds to consider',
    stake_system varchar(20) DEFAULT 'variable' COMMENT 'fixed or variable',
    base_unit decimal(8,2) DEFAULT 10.00 COMMENT 'Base unit for betting',
    max_daily_bets int(11) DEFAULT 5 COMMENT 'Maximum bets per day',
    stop_loss_daily decimal(8,2) DEFAULT 100.00 COMMENT 'Daily stop loss amount',
    stop_loss_weekly decimal(8,2) DEFAULT 500.00 COMMENT 'Weekly stop loss amount',
    min_bankroll_percentage decimal(5,2) DEFAULT 10.00 COMMENT 'Min bankroll % to continue',
    risk_level varchar(20) DEFAULT 'medium' COMMENT 'low, medium, high',
    league_filters varchar(255) DEFAULT '' COMMENT 'Comma separated league filters',
    time_filters varchar(255) DEFAULT '' COMMENT 'Time-based filters',
    streak_protection tinyint(1) DEFAULT 1 COMMENT 'Enable losing streak protection',
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) $charset_collate;";

dbDelta($sql_value_config);

        }
    }
    
    private function create_matches_table($prefix, $charset_collate) {
        // Copiar la definición existente pero usando el prefijo correcto
        $table_matches = $prefix . 'ft_matches_advanced';
        // ... resto del código de la tabla matches
    }
    
    // ... resto de métodos para crear tablas
}
