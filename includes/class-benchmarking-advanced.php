<?php
/**
 * Benchmarking Avanzado con O/U, AH y análisis detallado
 */

class FT_Benchmarking_Advanced {
    /**
 * Obtener temporadas disponibles desde la base de datos
 */
public function get_available_seasons() {
    global $wpdb;
    $table = $wpdb->prefix . 'ft_matches_advanced';
    
    $seasons = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT season 
         FROM $table 
         WHERE season IS NOT NULL 
         AND season != '' 
         AND fthg IS NOT NULL 
         AND ftag IS NOT NULL
         AND b365h IS NOT NULL
         GROUP BY season 
         HAVING COUNT(*) > 100
         ORDER BY season DESC"
    ));
    
    return $seasons;
}
	
	/**
 * Migrar benchmarks antiguos si existen
 */
public function migrate_old_benchmarks() {
    global $wpdb;
    $old_table = $wpdb->prefix . 'ft_benchmarks';
    $new_table = $wpdb->prefix . 'ft_benchmarks_advanced';
    
    // Verificar si existe la tabla antigua
    $old_exists = $wpdb->get_var("SHOW TABLES LIKE '$old_table'");
    
    if ($old_exists) {
        $old_benchmarks = $wpdb->get_results("SELECT * FROM $old_table ORDER BY test_date DESC");
        
        if (!empty($old_benchmarks)) {
            echo '<div class="notice notice-info"><p>';
            echo '<strong>Se encontraron ' . count($old_benchmarks) . ' benchmarks antiguos.</strong><br>';
            echo 'Usa este botón para migrarlos a la nueva tabla: ';
            echo '<button class="button" onclick="migrateBenchmarks()">Migrar Benchmarks</button>';
            echo '</p></div>';
        }
    }
}

/**
 * Mostrar benchmarks antiguos si la nueva tabla está vacía
 */
/**
 * Mostrar benchmarks antiguos si existen
 */
public function display_all_benchmarks() {
    global $wpdb;
    
    // Intentar la tabla nueva primero
    $new_table = $wpdb->prefix . 'ft_benchmarks_advanced';
    $new_exists = $wpdb->get_var("SHOW TABLES LIKE '$new_table'");
    
    if ($new_exists) {
        $benchmarks = $wpdb->get_results("SELECT * FROM $new_table ORDER BY test_date DESC LIMIT 10");
        
        if (!empty($benchmarks)) {
            $this->display_advanced_benchmark_table();
            return;
        }
    }
    
    // Si no hay en la nueva tabla, mostrar la antigua
    $old_table = $wpdb->prefix . 'ft_benchmarks';
    $old_exists = $wpdb->get_var("SHOW TABLES LIKE '$old_table'");
    
    if ($old_exists) {
        $benchmarks = $wpdb->get_results("SELECT * FROM $old_table ORDER BY test_date DESC LIMIT 10");
        
        if (!empty($benchmarks)) {
            echo '<h4>📊 Benchmarks Ejecutados</h4>';
            $this->display_old_benchmark_table($benchmarks);
            return;
        }
    }
    
    echo '<p>No hay benchmarks ejecutados todavía.</p>';
}

/**
 * Mostrar tabla de benchmarks antiguos
 */
private function display_old_benchmark_table($benchmarks) {
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Temporada</th>
                <th>Modelo</th>
                <th>Fecha</th>
                <th>Precisión</th>
                <th>ROI</th>
                <th>Apuestas</th>
                <th>Detalles</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($benchmarks as $benchmark): ?>
            <tr>
                <td><strong><?php echo esc_html($benchmark->season); ?></strong></td>
                <td><?php echo $benchmark->model_type === 'with_xg' ? '⭐ Con xG' : '📊 Básico'; ?></td>
                <td><?php echo date('d/m/Y H:i', strtotime($benchmark->test_date)); ?></td>
                <td>
                    <span class="accuracy-badge <?php echo $benchmark->overall_accuracy > 0.5 ? 'good' : 'poor'; ?>">
                        <?php echo round($benchmark->overall_accuracy * 100, 1); ?>%
                    </span>
                </td>
                <td>
                    <span class="roi-badge <?php echo ($benchmark->value_betting_roi ?? 0) > 0 ? 'positive' : 'negative'; ?>">
                        <?php echo round($benchmark->value_betting_roi ?? 0, 1); ?>%
                    </span>
                </td>
                <td>
                    <?php 
                    if (isset($benchmark->metadata) && !empty($benchmark->metadata)): 
                        $metadata = json_decode($benchmark->metadata, true);
                        $total_bets = $metadata['full_value_betting']['total_bets'] ?? 0;
                        echo $total_bets;
                    else: 
                        echo 'N/A';
                    endif; 
                    ?>
                </td>
                <td>
                    <button class="button button-small" onclick="viewOldBenchmarkDetails(<?php echo $benchmark->id; ?>)">
                        Ver Detalles
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <script>
    function viewOldBenchmarkDetails(benchmarkId) {
        jQuery.post(ajaxurl, {
            action: 'get_old_benchmark_details',
            benchmark_id: benchmarkId,
            nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                showBenchmarkDetailsModal(response.data);
            } else {
                alert('Error: ' + response.data);
            }
        });
    }
    </script>
    <?php
}


/**
 * Mostrar tabla de benchmarks antiguos
 */


/**
 * Obtener ligas disponibles desde la base de datos
 */
public function get_available_leagues() {
    global $wpdb;
    $table = $wpdb->prefix . 'ft_matches_advanced';
    
    $leagues = $wpdb->get_results(
        "SELECT DISTINCT division, COUNT(*) as total_matches,
         CASE 
            WHEN division = 'E0' THEN 'Premier League'
            WHEN division = 'E1' THEN 'Championship'
            WHEN division = 'E2' THEN 'League One'
            WHEN division = 'E3' THEN 'League Two'
            WHEN division = 'SP1' THEN 'La Liga'
            WHEN division = 'SP2' THEN 'Segunda División'
            WHEN division = 'I1' THEN 'Serie A'
            WHEN division = 'I2' THEN 'Serie B'
            WHEN division = 'D1' THEN 'Bundesliga'
            WHEN division = 'D2' THEN 'Bundesliga 2'
            WHEN division = 'F1' THEN 'Ligue 1'
            WHEN division = 'F2' THEN 'Ligue 2'
            WHEN division = 'N1' THEN 'Eredivisie'
            WHEN division = 'B1' THEN 'Pro League'
            WHEN division = 'P1' THEN 'Primeira Liga'
            WHEN division = 'T1' THEN 'Süper Lig'
            WHEN division = 'G1' THEN 'Bundesliga (Austria)'
            WHEN division = 'SC0' THEN 'Premiership'
            ELSE division
         END as league_name
         FROM $table 
         WHERE division IS NOT NULL 
         AND division != ''
         AND fthg IS NOT NULL
         GROUP BY division 
         HAVING total_matches > 50
         ORDER BY total_matches DESC"
    );
    
    return $leagues;
}

/**
 * Mostrar formulario de benchmark con temporadas dinámicas
 */
public function display_benchmark_form() {
    $seasons = $this->get_available_seasons();
    ?>
    <div class="card">
        <h3>🚀 Configurar Benchmark Avanzado</h3>
        <form id="advanced-benchmark-form">
            <?php wp_nonce_field('ft_nonce', 'nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th><label for="season">Temporada a Evaluar</label></th>
                    <td>
                        <select name="season" id="season" required>
                            <option value="">Seleccionar temporada...</option>
                            <?php foreach ($seasons as $season): ?>
                                <option value="<?php echo esc_attr($season); ?>">
                                    <?php echo esc_html($season); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            Temporadas disponibles con datos completos y cuotas B365
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="model_type">Tipo de Modelo</label></th>
                    <td>
                        <select name="model_type" id="model_type">
                            <option value="with_xg">⭐ Con xG (Recomendado)</option>
                            <option value="without_xg">📊 Sin xG</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="league">Liga</label></th>
                    <td>
                        <select name="league" id="league">
                            <option value="all">Todas las ligas</option>
                            <option value="E0">Premier League</option>
                            <option value="SP1">La Liga</option>
                            <option value="I1">Serie A</option>
                            <option value="D1">Bundesliga</option>
                            <option value="F1">Ligue 1</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Mercados a Incluir</th>
                    <td>
                        <label>
                            <input type="checkbox" name="moneyline" checked disabled> 
                            Moneyline (1X2) - Siempre incluido
                        </label><br>
                        <label>
                            <input type="checkbox" name="include_over_under" id="include_over_under" checked> 
                            Over/Under 2.5 goles (usando B365)
                        </label><br>
                        <label>
                            <input type="checkbox" name="include_asian_handicap" id="include_asian_handicap"> 
                            Asian Handicap (simulado)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Opciones Avanzadas</th>
                    <td>
                        <label>
                            <input type="checkbox" name="exclude_draws" id="exclude_draws"> 
                            🚫 Excluir empates (X) del análisis
                        </label><br>
                        <small class="description">
                            Si activas esta opción, solo se analizarán apuestas a victoria local o visitante.
                        </small>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" class="button button-primary" id="run-advanced-benchmark">
                    🚀 Ejecutar Benchmark Avanzado
                </button>
            </p>
        </form>
    </div>
    <?php
}
/**
 * Verificar datos disponibles por temporada
 */
public function verify_season_data_detailed($season = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'ft_matches_advanced';
    
    if ($season) {
        // Verificar temporada específica
        $data = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_matches,
                COUNT(CASE WHEN fthg IS NOT NULL AND ftag IS NOT NULL THEN 1 END) as with_results,
                COUNT(CASE WHEN b365h IS NOT NULL AND b365d IS NOT NULL AND b365a IS NOT NULL THEN 1 END) as with_b365_odds,
                COUNT(CASE WHEN hs IS NOT NULL AND as_shots IS NOT NULL THEN 1 END) as with_stats,
                MIN(date) as first_match,
                MAX(date) as last_match,
                COUNT(DISTINCT division) as leagues
            FROM $table 
            WHERE season = %s",
            $season
        ));
        
        return [
            'season' => $season,
            'data' => $data
        ];
    } else {
        // Verificar todas las temporadas
        $seasons_data = $wpdb->get_results(
            "SELECT 
                season,
                COUNT(*) as total_matches,
                COUNT(CASE WHEN fthg IS NOT NULL AND ftag IS NOT NULL THEN 1 END) as with_results,
                COUNT(CASE WHEN b365h IS NOT NULL AND b365d IS NOT NULL AND b365a IS NOT NULL THEN 1 END) as with_b365_odds,
                MIN(date) as first_match,
                MAX(date) as last_match
            FROM $table 
            WHERE season IS NOT NULL 
            GROUP BY season 
            ORDER BY season DESC"
        );
        
        return $seasons_data;
    }
}

    /**
     * Ejecutar benchmark avanzado
     */
    public function run_advanced_benchmark($season, $model_type = 'with_xg', $options = []) {
        try {
            error_log("FT Advanced Benchmark: Iniciando para temporada $season");
            
            // Opciones por defecto
            $default_options = [
                'league' => 'all',
                'exclude_draws' => false,
                'include_over_under' => true,
                'include_asian_handicap' => true,
                'detailed_analysis' => true
            ];
            
            $options = array_merge($default_options, $options);
            
            // Verificar datos
            $test_count = $this->verify_season_data($season, $options['league']);
            if ($test_count == 0) {
                return ['error' => "No hay datos para la temporada $season"];
            }
            
            // Actualizar configuración
            $this->update_db_config();
            
            // Ejecutar script avanzado
            $benchmark_result = $this->execute_advanced_benchmark_script($season, $model_type, $options);
            
            if (isset($benchmark_result['error'])) {
                return $benchmark_result;
            }
            
            // Guardar resultados completos
            $this->save_advanced_benchmark_results(
                $season,
                $model_type,
                $benchmark_result['test_metrics'],
                $benchmark_result['value_betting'],
                $benchmark_result['betting_details']
            );
            
            return $benchmark_result;
            
        } catch (Exception $e) {
            error_log("FT Advanced Benchmark Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Ejecutar script Python avanzado
     */
    /**
 * Ejecutar script Python avanzado con mejor manejo de errores
 */
private function execute_advanced_benchmark_script($season, $model_type, $options) {
    $python_script = FT_PYTHON_PATH . 'benchmark_season.py';
    
    if (!file_exists($python_script)) {
        return ['error' => 'Script benchmark_season.py no encontrado en ' . FT_PYTHON_PATH];
    }
    
    // Verificar y actualizar configuración de BD
    $this->update_db_config();
    
    $exclude_draws = $options['exclude_draws'] ? 'true' : 'false';
    
    $command = sprintf(
        'cd %s && /usr/bin/python3.8 benchmark_season.py %s %s %s %s 2>&1',
        FT_PYTHON_PATH,
        escapeshellarg($season),
        escapeshellarg($model_type),
        escapeshellarg($options['league']),
        escapeshellarg($exclude_draws)
    );
    
    error_log("FT Advanced Benchmark: Ejecutando comando: " . $command);
    
    // Verificar que el archivo db_config.json existe y es correcto
    $config_file = FT_PYTHON_PATH . 'db_config.json';
    if (!file_exists($config_file)) {
        return ['error' => 'Archivo db_config.json no encontrado. Ejecuta "Actualizar configuración" primero.'];
    }
    
    $config_content = file_get_contents($config_file);
    $config = json_decode($config_content, true);
    
    if (!$config || !isset($config['host']) || !isset($config['database'])) {
        return ['error' => 'Configuración de base de datos inválida en db_config.json'];
    }
    
    // Ejecutar con timeout extendido
    $output = shell_exec($command);
    
    if (!$output) {
        return ['error' => 'No se recibió respuesta del script Python. Verifica que Python 3.8 esté instalado y las librerías disponibles.'];
    }
    
    error_log("FT Advanced Benchmark: Output completo: " . $output);
    
    // Buscar errores comunes de conexión
    if (strpos($output, 'Access denied') !== false) {
        return ['error' => 'Error de acceso a la base de datos. Verifica usuario y contraseña en la configuración.'];
    }
    
    if (strpos($output, "Can't connect to MySQL") !== false) {
        return ['error' => 'No se puede conectar a MySQL. Verifica que el servidor esté ejecutándose y el host sea correcto.'];
    }
    
    if (strpos($output, 'Unknown database') !== false) {
        return ['error' => 'Base de datos no encontrada. Verifica el nombre de la base de datos en la configuración.'];
    }
    
    if (strpos($output, 'ModuleNotFoundError') !== false) {
        preg_match("/ModuleNotFoundError: No module named '([^']+)'/", $output, $matches);
        $missing_module = $matches[1] ?? 'unknown';
        return ['error' => "Módulo Python faltante: $missing_module. Instala las dependencias necesarias."];
    }
    
    // Buscar JSON en la salida
    $lines = explode("\n", $output);
    $json_str = '';
    
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        
        if (substr($line, 0, 1) === '{' && substr($line, -1) === '}') {
            $json_str = $line;
            break;
        }
    }
    
    if (empty($json_str)) {
        $first_brace = strpos($output, '{');
        $last_brace = strrpos($output, '}');
        if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
            $json_str = substr($output, $first_brace, $last_brace - $first_brace + 1);
        }
    }
    
    if (!empty($json_str)) {
        $result = json_decode($json_str, true);
        if ($result !== null) {
            if (isset($result['error'])) {
                return ['error' => $result['error']];
            } elseif (isset($result['test_metrics'])) {
                return $result;
            }
        } else {
            error_log("FT Advanced Benchmark: Error JSON decode: " . json_last_error_msg());
        }
    }
    
    // Si llegamos aquí, mostrar output completo para debug
    return ['error' => 'Error procesando respuesta del script. Output: ' . substr($output, 0, 1000)];
}

/**
 * Actualizar configuración de BD con validación
 */
private function update_db_config() {
    global $wpdb;
    
    $db_config = [
        'host' => DB_HOST,
        'user' => DB_USER,
        'password' => DB_PASSWORD,
        'database' => DB_NAME,
        'table_prefix' => $wpdb->prefix // PP0Fhoci_
    ];
    
    // Extraer puerto si está en el host
    if (strpos(DB_HOST, ':') !== false) {
        list($host, $port) = explode(':', DB_HOST, 2);
        $db_config['host'] = $host;
        $db_config['port'] = intval($port);
    }
    
    $config_file = FT_PYTHON_PATH . 'db_config.json';
    $result = file_put_contents($config_file, json_encode($db_config, JSON_PRETTY_PRINT));
    
    if ($result === false) {
        error_log("FT Advanced Benchmark: Error escribiendo db_config.json");
    } else {
        error_log("FT Advanced Benchmark: db_config.json actualizado correctamente");
    }
}

    /**
     * Guardar resultados avanzados del benchmark
     */
    private function save_advanced_benchmark_results($season, $model_type, $metrics, $value_betting, $betting_details) {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_benchmarks_advanced';
        
        // Crear tabla avanzada si no existe
        $this->create_advanced_benchmarks_table();
        
        // Calcular estadísticas adicionales
        $market_breakdown = $value_betting['market_breakdown'] ?? [];
        $advanced_stats = $this->calculate_advanced_statistics($betting_details);
        
        $wpdb->insert($table, [
            'season' => $season,
            'model_type' => $model_type,
            'total_predictions' => $metrics['total_predictions'],
            'correct_predictions' => $metrics['correct_predictions'],
            'overall_accuracy' => $metrics['overall_accuracy'],
            'home_accuracy' => $metrics['home_wins']['accuracy'],
            'draw_accuracy' => $metrics['draws']['accuracy'],
            'away_accuracy' => $metrics['away_wins']['accuracy'],
            
            // Value betting total
            'value_betting_roi' => $value_betting['roi'],
            'value_betting_profit' => $value_betting['profit_loss'],
            'value_betting_win_rate' => $value_betting['win_rate'],
            'total_bets' => $value_betting['total_bets'],
            'winning_bets' => $value_betting['winning_bets'],
            'total_stakes' => $value_betting['total_stakes'],
            
            // Por mercado
            'moneyline_bets' => $market_breakdown['moneyline']['bets'] ?? 0,
            'moneyline_roi' => $market_breakdown['moneyline']['roi'] ?? 0,
            'moneyline_win_rate' => $market_breakdown['moneyline']['win_rate'] ?? 0,
            
            'total_bets_ou' => $market_breakdown['total']['bets'] ?? 0,
            'total_roi_ou' => $market_breakdown['total']['roi'] ?? 0,
            'total_win_rate_ou' => $market_breakdown['total']['win_rate'] ?? 0,
            
            'spread_bets' => $market_breakdown['spread']['bets'] ?? 0,
            'spread_roi' => $market_breakdown['spread']['roi'] ?? 0,
            'spread_win_rate' => $market_breakdown['spread']['win_rate'] ?? 0,
            
            // Estadísticas avanzadas
            'max_drawdown' => $advanced_stats['max_drawdown'],
            'profit_factor' => $advanced_stats['profit_factor'],
            'sharpe_ratio' => $advanced_stats['sharpe_ratio'],
            'consecutive_wins' => $advanced_stats['consecutive_wins'],
            'consecutive_losses' => $advanced_stats['consecutive_losses'],
            'avg_odds' => $advanced_stats['avg_odds'],
            'avg_value' => $advanced_stats['avg_value'],
            'best_bet_profit' => $advanced_stats['best_bet_profit'],
            'worst_bet_loss' => $advanced_stats['worst_bet_loss'],
            
            // Metadata completa
            'metadata' => json_encode([
                'full_metrics' => $metrics,
                'full_value_betting' => $value_betting,
                'betting_details' => $betting_details,
                'advanced_stats' => $advanced_stats,
                'market_breakdown' => $market_breakdown
            ])
        ]);
        
        error_log("FT Advanced Benchmark: Guardados " . count($betting_details) . " detalles de apuestas");
    }
    
    /**
     * Crear tabla de benchmarks avanzados
     */
    private function create_advanced_benchmarks_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_benchmarks_advanced';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id int(11) NOT NULL AUTO_INCREMENT,
            season varchar(20) NOT NULL,
            model_type varchar(50) NOT NULL,
            test_date datetime DEFAULT CURRENT_TIMESTAMP,
            
            -- Métricas de predicción
            total_predictions int(11) NOT NULL,
            correct_predictions int(11) NOT NULL,
            overall_accuracy decimal(5,4) NOT NULL,
            home_accuracy decimal(5,4) DEFAULT NULL,
            draw_accuracy decimal(5,4) DEFAULT NULL,
            away_accuracy decimal(5,4) DEFAULT NULL,
            
            -- Value betting general
            value_betting_roi decimal(8,2) DEFAULT NULL,
            value_betting_profit decimal(8,2) DEFAULT NULL,
            value_betting_win_rate decimal(5,4) DEFAULT NULL,
            total_bets int(11) DEFAULT 0,
            winning_bets int(11) DEFAULT 0,
            total_stakes decimal(10,2) DEFAULT 0,
            
            -- Moneyline
            moneyline_bets int(11) DEFAULT 0,
            moneyline_roi decimal(8,2) DEFAULT 0,
            moneyline_win_rate decimal(5,2) DEFAULT 0,
            
            -- Over/Under
            total_bets_ou int(11) DEFAULT 0,
            total_roi_ou decimal(8,2) DEFAULT 0,
            total_win_rate_ou decimal(5,2) DEFAULT 0,
            
            -- Asian Handicap
            spread_bets int(11) DEFAULT 0,
            spread_roi decimal(8,2) DEFAULT 0,
            spread_win_rate decimal(5,2) DEFAULT 0,
            
            -- Estadísticas avanzadas
            max_drawdown decimal(5,2) DEFAULT 0,
            profit_factor decimal(6,2) DEFAULT 0,
            sharpe_ratio decimal(6,3) DEFAULT 0,
            consecutive_wins int(11) DEFAULT 0,
            consecutive_losses int(11) DEFAULT 0,
            avg_odds decimal(6,3) DEFAULT 0,
            avg_value decimal(5,2) DEFAULT 0,
            best_bet_profit decimal(8,2) DEFAULT 0,
            worst_bet_loss decimal(8,2) DEFAULT 0,
            
            -- Metadata
            metadata longtext DEFAULT NULL,
            
            PRIMARY KEY (id),
            KEY idx_season (season),
            KEY idx_model_type (model_type),
            KEY idx_test_date (test_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Calcular estadísticas avanzadas
     */
    private function calculate_advanced_statistics($betting_details) {
        if (empty($betting_details)) {
            return [
                'max_drawdown' => 0,
                'profit_factor' => 0,
                'sharpe_ratio' => 0,
                'consecutive_wins' => 0,
                'consecutive_losses' => 0,
                'avg_odds' => 0,
                'avg_value' => 0,
                'best_bet_profit' => 0,
                'worst_bet_loss' => 0
            ];
        }
        
        // Calcular drawdown máximo
        $peak = 1000; // Bankroll inicial
        $max_drawdown = 0;
        
        foreach ($betting_details as $bet) {
            $current_bankroll = $bet['bankroll'];
            if ($current_bankroll > $peak) {
                $peak = $current_bankroll;
            }
            $drawdown = ($peak - $current_bankroll) / $peak * 100;
            if ($drawdown > $max_drawdown) {
                $max_drawdown = $drawdown;
            }
        }
        
        // Factor de beneficio
        $total_wins = 0;
        $total_losses = 0;
        $odds_sum = 0;
        $value_sum = 0;
        $best_profit = 0;
        $worst_loss = 0;
        
        foreach ($betting_details as $bet) {
            $profit = $bet['profit'];
            if ($profit > 0) {
                $total_wins += $profit;
            } else {
                $total_losses += abs($profit);
            }
            
            $odds_sum += $bet['odds'];
            $value_sum += $bet['value'] * 100; // Convertir a porcentaje
            
            if ($profit > $best_profit) $best_profit = $profit;
            if ($profit < $worst_loss) $worst_loss = $profit;
        }
        
        $profit_factor = $total_losses > 0 ? $total_wins / $total_losses : 0;
        $avg_odds = count($betting_details) > 0 ? $odds_sum / count($betting_details) : 0;
        $avg_value = count($betting_details) > 0 ? $value_sum / count($betting_details) : 0;
        
        // Rachas consecutivas
        $consecutive_wins = 0;
        $consecutive_losses = 0;
        $max_consecutive_wins = 0;
        $max_consecutive_losses = 0;
        
        foreach ($betting_details as $bet) {
            if ($bet['won']) {
                $consecutive_wins++;
                $consecutive_losses = 0;
                $max_consecutive_wins = max($max_consecutive_wins, $consecutive_wins);
            } else {
                $consecutive_losses++;
                $consecutive_wins = 0;
                $max_consecutive_losses = max($max_consecutive_losses, $consecutive_losses);
            }
        }
        
        // Ratio de Sharpe simplificado
        $returns = [];
        foreach ($betting_details as $bet) {
            $return_pct = $bet['stake_amount'] > 0 ? $bet['profit'] / $bet['stake_amount'] : 0;
            $returns[] = $return_pct;
        }
        
        $sharpe_ratio = 0;
        if (count($returns) > 1) {
            $mean_return = array_sum($returns) / count($returns);
            $variance = 0;
            foreach ($returns as $return) {
                $variance += pow($return - $mean_return, 2);
            }
            $std_dev = sqrt($variance / count($returns));
            $sharpe_ratio = $std_dev > 0 ? $mean_return / $std_dev : 0;
        }
        
        return [
            'max_drawdown' => round($max_drawdown, 2),
            'profit_factor' => round($profit_factor, 2),
            'sharpe_ratio' => round($sharpe_ratio, 3),
            'consecutive_wins' => $max_consecutive_wins,
            'consecutive_losses' => $max_consecutive_losses,
            'avg_odds' => round($avg_odds, 3),
            'avg_value' => round($avg_value, 2),
            'best_bet_profit' => round($best_profit, 2),
            'worst_bet_loss' => round($worst_loss, 2)
        ];
    }
    
    /**
     * Mostrar tabla de benchmarks avanzados
     */
    public function display_advanced_benchmark_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_benchmarks_advanced';
        
        $benchmarks = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY test_date DESC LIMIT 10"
        );
        
        if (empty($benchmarks)) {
            echo '<p>No hay benchmarks avanzados ejecutados todavía.</p>';
            return;
        }
        
        ?>
        <div class="benchmark-advanced-stats">
            <h3>🚀 Benchmarking Avanzado - Múltiples Mercados</h3>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Temporada</th>
                        <th>Modelo</th>
                        <th>Precisión</th>
                        <th>Total Apuestas</th>
                        <th>ROI General</th>
                        <th>Moneyline</th>
                        <th>Over/Under</th>
                        <th>Asian Handicap</th>
                        <th>Max DD</th>
                        <th>Detalles</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($benchmarks as $benchmark): ?>
                    <tr>
                        <td><strong><?php echo esc_html($benchmark->season); ?></strong></td>
                        <td>
                            <?php echo $benchmark->model_type === 'with_xg' ? '⭐ Con xG' : '📊 Básico'; ?>
                        </td>
                        <td>
                            <span class="accuracy-badge <?php echo $benchmark->overall_accuracy > 0.5 ? 'good' : 'poor'; ?>">
                                <?php echo round($benchmark->overall_accuracy * 100, 1); ?>%
                            </span>
                        </td>
                        <td>
                            <strong><?php echo $benchmark->total_bets; ?></strong><br>
                            <small><?php echo $benchmark->winning_bets; ?> ganadas</small>
                        </td>
                        <td>
                            <span class="roi-badge <?php echo $benchmark->value_betting_roi > 0 ? 'positive' : 'negative'; ?>">
                                <?php echo round($benchmark->value_betting_roi, 1); ?>%
                            </span>
                            <br><small>€<?php echo round($benchmark->value_betting_profit, 0); ?></small>
                        </td>
                        <td>
                            <?php echo $benchmark->moneyline_bets; ?> apuestas<br>
                            <small><?php echo round($benchmark->moneyline_roi, 1); ?>% ROI</small>
                        </td>
                        <td>
                            <?php echo $benchmark->total_bets_ou; ?> apuestas<br>
                            <small><?php echo round($benchmark->total_roi_ou, 1); ?>% ROI</small>
                        </td>
                        <td>
                            <?php echo $benchmark->spread_bets; ?> apuestas<br>
                            <small><?php echo round($benchmark->spread_roi, 1); ?>% ROI</small>
                        </td>
                        <td>
                            <span class="drawdown-badge">
                                <?php echo $benchmark->max_drawdown; ?>%
                            </span>
                        </td>
                        <td>
                            <button class="button button-small" onclick="showAdvancedBenchmarkDetails('<?php echo $benchmark->id; ?>')">
                                Ver Detalles
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <style>
        .accuracy-badge.good { background: #46b450; color: white; padding: 2px 6px; border-radius: 3px; }
        .accuracy-badge.poor { background: #dc3232; color: white; padding: 2px 6px; border-radius: 3px; }
        .roi-badge.positive { background: #00a32a; color: white; padding: 2px 6px; border-radius: 3px; }
        .roi-badge.negative { background: #d63638; color: white; padding: 2px 6px; border-radius: 3px; }
        .drawdown-badge { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; }
        </style>
        <?php
    }
}
