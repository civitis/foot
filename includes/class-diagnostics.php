<?php

if (!defined('ABSPATH')) {
    exit;
}

class FT_Diagnostics {
    
    public static function run_full_diagnosis() {
        $results = array();
        
        $results['database'] = self::check_database();
        $results['permissions'] = self::check_permissions();
        $results['python'] = self::check_python();
        $results['files'] = self::check_files();
        
        return $results;
    }
    
    public static function check_database() {
        global $wpdb;
        
        $results = array();
        $results['connection'] = $wpdb->db_connect() ? 'OK' : 'ERROR';
        
        $required_tables = array(
            'ft_matches_advanced',
            'ft_team_stats_advanced', 
            'ft_predictions',
            'ft_config'
        );
        
        foreach ($required_tables as $table) {
            $full_table_name = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table_name'");
            $results['tables'][$table] = $exists ? 'OK' : 'MISSING';
            
            if ($exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $full_table_name");
                $results['table_counts'][$table] = $count;
            }
        }
        
        return $results;
    }
    
    public static function check_permissions() {
        $results = array();
        
        $directories = array(
            FT_PLUGIN_PATH => 'plugin_root',
            FT_PYTHON_PATH => 'python',
            FT_MODELS_PATH => 'models'
        );
        
        foreach ($directories as $dir => $name) {
            if (!file_exists($dir)) {
                $results[$name] = 'MISSING';
            } else {
                $results[$name] = array(
                    'exists' => true,
                    'readable' => is_readable($dir),
                    'writable' => is_writable($dir),
                    'permissions' => substr(sprintf('%o', fileperms($dir)), -4)
                );
            }
        }
        
        return $results;
    }
    
  public static function check_python() {
    $results = array();
    
    // Verificar Python
    $python_version = shell_exec('python3 --version 2>&1');
    $results['python_version'] = $python_version ? trim($python_version) : 'NOT_FOUND';
    
    // Verificar pip
    $pip_version = shell_exec('python3 -m pip --version 2>&1');
    $results['pip_version'] = $pip_version ? trim($pip_version) : 'NOT_FOUND';
    
    // Verificar librerías con path personalizado
    $libraries = array(
        'pandas' => 'pandas',
        'numpy' => 'numpy',
        'scikit-learn' => 'sklearn',
        'mysql-connector' => 'mysql.connector',
        'joblib' => 'joblib'
    );
    
    foreach ($libraries as $lib_name => $import_name) {
        // Script Python que busca en múltiples ubicaciones
        $python_script = "
import sys
import os

# Agregar posibles ubicaciones de librerías
possible_paths = [
    '/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs',
    os.path.expanduser('~/.local/lib/python3.8/site-packages'),
    os.path.expanduser('~/.local/lib/python3.6/site-packages'),
    '/usr/local/lib/python3.8/site-packages',
    '/usr/lib/python3.8/site-packages'
]

for path in possible_paths:
    if os.path.exists(path):
        sys.path.insert(0, path)

try:
    import $import_name
    if hasattr($import_name, '__version__'):
        print('INSTALLED:', $import_name.__version__)
    else:
        print('INSTALLED: OK')
except ImportError as e:
    print('NOT_INSTALLED:', str(e))
except Exception as e:
    print('ERROR:', str(e))
";
        
       $plugin_libs = FT_PLUGIN_PATH . 'python-libs';
$command = "cd " . FT_PYTHON_PATH . " && PYTHONPATH=$plugin_libs python3 -c \"$python_script\" 2>&1";
        $check = shell_exec($command);
        $results['libraries'][$lib_name] = $check ? trim($check) : 'NOT_FOUND';
    }
    
    return $results;
}
    
    public static function check_files() {
        $results = array();
        
        $required_files = array(
            'includes/class-predictor.php',
            'includes/class-ajax-handlers.php',
            'includes/class-csv-importer.php'
        );
        
        foreach ($required_files as $file) {
            $full_path = FT_PLUGIN_PATH . $file;
            $results[$file] = file_exists($full_path) ? 'OK' : 'MISSING';
        }
        
        return $results;
    }
    
    public static function test_csv_import() {
        try {
            // Datos de prueba simples
            $sample_data = "Div,Date,HomeTeam,AwayTeam,FTHG,FTAG,FTR\n";
            $sample_data .= "E0,01/08/2023,Arsenal,Chelsea,2,1,H\n";
            
            $temp_file = tempnam(sys_get_temp_dir(), 'ft_test_');
            file_put_contents($temp_file, $sample_data);
            
            if (!class_exists('FT_CSV_Importer')) {
                require_once FT_PLUGIN_PATH . 'includes/class-csv-importer.php';
            }
            
            $importer = new FT_CSV_Importer();
            $result = $importer->import_from_file($temp_file);
            
            unlink($temp_file);
            
            return array(
                'success' => $result['success'],
                'message' => $result['message'] ?? $result['error'],
                'imported' => $result['imported'] ?? 0
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'imported' => 0
            );
        }
    }
}