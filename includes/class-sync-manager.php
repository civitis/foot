<?php
/**
 * Gestor de sincronización unificado
 * Maneja todas las operaciones de sincronización con APIs externas
 */

class FT_Sync_Manager {
    private $pinnacle_api;
    private $value_analyzer;
    
    public function __construct() {
        $this->pinnacle_api = new FT_Pinnacle_API();
        $this->value_analyzer = new FT_Value_Analyzer();
    }
    
    /**
     * Sincronización completa
     */
    public function full_sync() {
        $start_time = microtime(true);
        
        try {
            // 1. Sincronizar fixtures
            $fixtures_synced = $this->pinnacle_api->sync_fixtures();
            
            // 2. Sincronizar odds
            $odds_synced = $this->pinnacle_api->sync_odds();
            
            // 3. Analizar value bets
            $value_analysis = $this->value_analyzer->analyze_all_fixtures();
            
            // 4. Log del resultado
            $duration = round(microtime(true) - $start_time, 2);
            $this->log_sync_operation('full_sync', 'success', 
                "Fixtures: {$fixtures_synced}, Odds: {$odds_synced}, Value Bets: {$value_analysis['value_bets_found']}", 
                $duration);
            
            return [
                'success' => true,
                'fixtures_synced' => $fixtures_synced,
                'odds_synced' => $odds_synced,
                'value_bets_found' => $value_analysis['value_bets_found']
            ];
            
        } catch (Exception $e) {
            $this->log_sync_operation('full_sync', 'error', $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Log de operaciones de sincronización
     */
    private function log_sync_operation($operation, $status, $message, $duration = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ft_sync_logs';
        
        // Crear tabla si no existe
        $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
            id int(11) NOT NULL AUTO_INCREMENT,
            operation varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            duration float DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_operation (operation),
            KEY idx_created (created_at)
        )");
        
        $wpdb->insert($table, [
            'operation' => $operation,
            'status' => $status,
            'message' => $message,
            'duration' => $duration
        ]);
    }
    
    /**
     * Obtener logs de sincronización
     */
    public function get_sync_logs($limit = 20) {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_sync_logs';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Obtener etiqueta de operación para mostrar
     */
    public function get_operation_label($operation) {
        $labels = [
            'full_sync' => 'Sincronización Completa',
            'fixtures_sync' => 'Sincronizar Fixtures',
            'odds_sync' => 'Sincronizar Odds',
            'value_analysis' => 'Análisis Value Bets'
        ];
        
        return $labels[$operation] ?? ucfirst(str_replace('_', ' ', $operation));
    }
}
