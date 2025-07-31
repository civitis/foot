<?php
/**
 * Utilidades de base de datos
 */

class FT_Database_Utils {
    
    /**
     * Obtener prefijo de tabla
     */
    public static function get_table_prefix() {
        global $wpdb;
        return $wpdb->prefix; // PP0Fhoci_
    }
    
    /**
     * Obtener nombre completo de tabla
     */
    public static function get_table_name($table_suffix) {
        return self::get_table_prefix() . 'ft_' . $table_suffix;
    }
    
    /**
     * Verificar si una tabla existe
     */
    public static function table_exists($table_suffix) {
        global $wpdb;
        $table_name = self::get_table_name($table_suffix);
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Obtener estadísticas de tabla
     */
    public static function get_table_stats($table_suffix) {
        if (!self::table_exists($table_suffix)) {
            return null;
        }
        
        global $wpdb;
        $table_name = self::get_table_name($table_suffix);
        
        return [
            'name' => $table_name,
            'count' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
            'size' => $wpdb->get_var("SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'DB Size in MB' FROM information_schema.tables WHERE table_name = '$table_name'")
        ];
    }
    
    /**
     * Limpiar datos antiguos
     */
    public static function cleanup_old_data($table_suffix, $days = 30) {
        global $wpdb;
        $table_name = self::get_table_name($table_suffix);
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        return $deleted;
    }
}
