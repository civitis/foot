<?php
/**
 * Plugin Name: Football Tipster Random Forest
 * Description: Sistema de predicción de partidos de fútbol usando Random Forest con estadísticas avanzadas
 * Version: 1.0.0
 * Author: Tu Nombre
 * License: GPL v2 or later
 * Text Domain: football-tipster
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

ini_set('log_errors', 1);

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes
define('FT_PLUGIN_VERSION', '1.0.0');
define('FT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FT_PYTHON_PATH', FT_PLUGIN_PATH . 'python/');
define('FT_MODELS_PATH', FT_PLUGIN_PATH . 'models/');

// Cargar clases principales
require_once FT_PLUGIN_PATH . 'includes/class-predictor.php';
require_once FT_PLUGIN_PATH . 'includes/class-csv-importer.php';
require_once FT_PLUGIN_PATH . 'includes/class-xg-scraper.php';
//require_once FT_PLUGIN_PATH . 'includes/class-ajax-handlers.php';
require_once FT_PLUGIN_PATH . 'includes/class-prediction-widget.php';
require_once FT_PLUGIN_PATH . 'includes/class-diagnostics.php';
require_once FT_PLUGIN_PATH . 'includes/class-pinnacle-api.php';
require_once FT_PLUGIN_PATH . 'includes/class-value-analyzer.php';
require_once FT_PLUGIN_PATH . 'includes/class-benchmarking.php';
/**
 * Clase principal del plugin
 */
class FootballTipster {
    
    private static $instance = null;
    
    /**
     * Singleton pattern
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor privado
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Hooks de activación y desactivación
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Hooks de WordPress
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('rest_api_init', array($this, 'register_api_routes'));
        add_action('widgets_init', array($this, 'register_widgets'));
        
        // Shortcodes
        add_shortcode('football_predictions', array($this, 'render_predictions_shortcode'));
        add_shortcode('football_predictions_advanced', array($this, 'render_predictions_advanced'));
        
        // Tareas cron
        add_action('ft_retrain_model_weekly', array($this, 'auto_retrain_model'));
        add_action('ft_update_stats', array('FT_Predictor', 'update_team_stats'));
    }
    
    /**
     * Inicialización del plugin
     */
    public function init() {
        // Cargar traducciones
        load_plugin_textdomain('football-tipster', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        
        // Inicializar AJAX handlers
      //  FT_Ajax_Handlers::init();
    }
    
    /**
     * Activación del plugin
     */
    public function activate() {
        // Crear tablas
        $this->create_tables();
        
        // Crear carpetas necesarias
        $this->create_directories();
        
        // Configurar tareas cron
        $this->setup_cron_jobs();
        
        // Generar API key
        if (!get_option('ft_api_key')) {
            update_option('ft_api_key', wp_generate_password(32, false));
        }
        // En football-tipster.php, en el método activate()
require_once FT_PLUGIN_PATH . 'includes/class-value-config.php';
FT_Value_Config::create_table();

        // Flush rewrite rules
        flush_rewrite_rules();
		// Configuración inicial de value betting
if (!get_option('ft_bankroll')) {
    update_option('ft_bankroll', 1000);
    update_option('ft_min_value_threshold', 5.0);
    update_option('ft_min_confidence_threshold', 0.6);
    update_option('ft_max_stake_percentage', 5);
    update_option('ft_kelly_fraction', 0.25);
    update_option('ft_markets_enabled', 'moneyline,total');
    update_option('ft_auto_analyze', 1);
    update_option('ft_pinnacle_sync_frequency', '1hour');
}
    }
    
    /**
     * Desactivación del plugin
     */
    public function deactivate() {
        // Limpiar tareas cron
        wp_clear_scheduled_hook('ft_update_xg_daily');
        wp_clear_scheduled_hook('ft_retrain_model_weekly');
        wp_clear_scheduled_hook('ft_update_stats');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Crear tablas de base de datos
     */
   /**
 * Crear tablas de base de datos
 */
private function create_tables() {
    require_once FT_PLUGIN_PATH . 'includes/database/class-table-creator.php';
    $table_creator = new FT_Table_Creator();
    $table_creator->create_all_tables();
}

    
    /**
     * Crear directorios necesarios
     */
    private function create_directories() {
        $directories = array(
            FT_MODELS_PATH,
            FT_PLUGIN_PATH . 'logs/',
            FT_PLUGIN_PATH . 'temp/'
        );
        
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
                
                // Crear archivo .htaccess para seguridad
                $htaccess_content = "Order deny,allow\nDeny from all";
                file_put_contents($dir . '.htaccess', $htaccess_content);
            }
        }
    }
    
    /**
     * Configurar tareas programadas
     */
    private function setup_cron_jobs() {
        // Actualizar xG diariamente
        if (!wp_next_scheduled('ft_update_xg_daily')) {
            wp_schedule_event(time(), 'daily', 'ft_update_xg_daily');
        }
        
        // Reentrenar modelo semanalmente
        if (!wp_next_scheduled('ft_retrain_model_weekly')) {
            wp_schedule_event(time(), 'weekly', 'ft_retrain_model_weekly');
        }
        
        // Actualizar estadísticas cada 6 horas
        if (!wp_next_scheduled('ft_update_stats')) {
            wp_schedule_event(time(), 'twicedaily', 'ft_update_stats');
        }
    }
    
    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Football Tipster', 'football-tipster'),
            __('Football Tipster', 'football-tipster'),
            'manage_options',
            'football-tipster',
            array($this, 'admin_page'),
            'dashicons-chart-line',
            30
        );
		
        add_submenu_page(
    'football-tipster',
    __('Diagnóstico', 'football-tipster'),
    __('Diagnóstico', 'football-tipster'),
    'manage_options',
    'football-tipster-diagnosis',
    array($this, 'diagnosis_page')
);
        add_submenu_page(
            'football-tipster',
            __('Predicciones', 'football-tipster'),
            __('Predicciones', 'football-tipster'),
            'manage_options',
            'football-tipster-predictions',
            array($this, 'predictions_page')
        );
        
        add_submenu_page(
            'football-tipster',
            __('Importar Datos', 'football-tipster'),
            __('Importar Datos', 'football-tipster'),
            'manage_options',
            'football-tipster-import',
            array($this, 'import_page')
        );
        
        add_submenu_page(
            'football-tipster',
            __('Configuración', 'football-tipster'),
            __('Configuración', 'football-tipster'),
            'manage_options',
            'football-tipster-settings',
            array($this, 'settings_page')
        );
		add_submenu_page(
    'football-tipster',
    __('Value Bets', 'football-tipster'),
    __('Value Bets', 'football-tipster'),
    'manage_options',
    'football-tipster-value-bets',
    array($this, 'value_bets_page')
);

add_submenu_page(
    'football-tipster',
    __('Sincronización', 'football-tipster'),
    __('Sincronización', 'football-tipster'),
    'manage_options',
    'football-tipster-sync',
    array($this, 'sync_page')
);
	add_submenu_page(
    'football-tipster',
    'Benchmarking',
    'Benchmarking',
    'manage_options',
    'football-tipster-benchmarking',
    array($this, 'benchmarking_page')
);
    }
    
    /**
     * Página principal de administración
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Football Tipster - Panel de Control', 'football-tipster'); ?></h1>
            
            <div class="ft-admin-container">
                <div class="ft-section">
                    <h2><?php _e('Estado del Sistema', 'football-tipster'); ?></h2>
                    <div id="ft-system-status">
                        <?php $this->display_system_status(); ?>
                    </div>
                </div>
                
                <div class="ft-section">
                    <h2><?php _e('Entrenar Modelo', 'football-tipster'); ?></h2>
                    <p><?php _e('Entrena el modelo Random Forest con los datos disponibles.', 'football-tipster'); ?></p>
                    <button id="ft-train-model" class="button button-primary">
                        <?php _e('Entrenar Random Forest', 'football-tipster'); ?>
                    </button>
                    <div id="ft-training-status"></div>
                </div>
                
                <div class="ft-section">
                    <h2><?php _e('Actualizar xG', 'football-tipster'); ?></h2>
                    <p><?php _e('Obtiene Expected Goals desde FBref para partidos recientes.', 'football-tipster'); ?></p>
                    <button id="ft-update-xg" class="button">
                        <?php _e('Actualizar xG', 'football-tipster'); ?>
                    </button>
                    <div id="ft-xg-status"></div>
                </div>
                
                <div class="ft-section">
                    <h2><?php _e('Estadísticas del Modelo', 'football-tipster'); ?></h2>
                    <div id="ft-model-stats">
                        <?php $this->display_model_stats(); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Página de predicciones
     */
    public function predictions_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Predicciones', 'football-tipster'); ?></h1>
            <div class="ft-predictions-admin">
                <?php echo do_shortcode('[football_predictions_advanced show_stats="yes"]'); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Página de importación
     */
    public function import_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Importar Datos', 'football-tipster'); ?></h1>
            
            <div class="ft-section">
                <h2><?php _e('Importar desde CSV', 'football-tipster'); ?></h2>
                <form id="ft-import-data" enctype="multipart/form-data">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Archivo CSV', 'football-tipster'); ?></th>
                            <td>
                                <input type="file" name="csv_file" accept=".csv" required>
                                <p class="description">
                                    <?php _e('Formato: Div,Date,Time,HomeTeam,AwayTeam,FTHG,FTAG...', 'football-tipster'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php _e('Importar CSV', 'football-tipster'); ?>
                        </button>
                    </p>
                </form>
                <div id="ft-import-status"></div>
            </div>
            
            <div class="ft-section">
                <h2><?php _e('Importar desde URL', 'football-tipster'); ?></h2>
                <form id="ft-import-url">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('URL del CSV', 'football-tipster'); ?></th>
                            <td>
                                <input type="url" name="csv_url" class="regular-text" placeholder="https://ejemplo.com/datos.csv">
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button">
                            <?php _e('Importar desde URL', 'football-tipster'); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    /**
 * Página de Benchmarking
 */
public function benchmarking_page() {
    if (!class_exists('FT_Benchmarking_Advanced')) {
        require_once FT_PLUGIN_PATH . 'includes/class-benchmarking-advanced.php';
    }
    
    $benchmarking = new FT_Benchmarking_Advanced();
    ?>
    <div class="wrap">
        <h1>🚀 Benchmarking Avanzado</h1>
        
        <div class="card">
            <h3>Configurar Benchmark Avanzado</h3>
            <form id="advanced-benchmark-form">
                <?php wp_nonce_field('ft_nonce', 'nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="season">Temporada a Evaluar</label></th>
                        <td>
                            <select name="season" id="season" required>
                                <option value="">Seleccionar temporada...</option>
                                <?php 
                                $seasons = $benchmarking->get_available_seasons();
                                foreach ($seasons as $season): ?>
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
            <?php 
            $leagues = $benchmarking->get_available_leagues();
            foreach ($leagues as $league): ?>
                <option value="<?php echo esc_attr($league->division); ?>">
                    <?php echo esc_html($league->league_name); ?> 
                    (<?php echo $league->total_matches; ?> partidos)
                </option>
            <?php endforeach; ?>
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
        
        <div id="advanced-benchmark-results" style="display: none;">
            <div class="card">
                <h3>📊 Resultados del Benchmark Avanzado</h3>
                <div id="advanced-results-content"></div>
            </div>
        </div>
        
        <div class="card">
            <h3>📈 Historial de Benchmarks Avanzados</h3>
            <?php $benchmarking->display_advanced_benchmark_table(); ?>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Verificar datos cuando se selecciona una temporada
        $('#season').on('change', function() {
            const season = $(this).val();
            if (season) {
                $.post(ajaxurl, {
                    action: 'ft_verify_season_data',
                    season: season,
                    nonce: $('input[name="nonce"]').val()
                }, function(response) {
                    if (response.success) {
                        const data = response.data.data;
                        let info = `
                            <div class="season-info">
                                <strong>Datos disponibles para ${season}:</strong><br>
                                📊 Total partidos: ${data.total_matches}<br>
                                ✅ Con resultados: ${data.with_results}<br>
                                💰 Con cuotas B365: ${data.with_b365_odds}<br>
                                📈 Con estadísticas: ${data.with_stats}<br>
                                📅 Período: ${data.first_match} a ${data.last_match}<br>
                                🏆 Ligas: ${data.leagues}
                            </div>
                        `;
                        
                        $('.season-info').remove();
                        $(info).insertAfter('#season').slideDown();
                    }
                });
            }
        });

        $('#advanced-benchmark-form').on('submit', function(e) {
            e.preventDefault();
            
            const $button = $('#run-advanced-benchmark');
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('⏳ Ejecutando benchmark...');
            $('#advanced-benchmark-results').hide();
            
            const formData = {
                action: 'ft_run_advanced_benchmark',
                season: $('#season').val(),
                model_type: $('#model_type').val(),
                league: $('#league').val(),
                exclude_draws: $('#exclude_draws').is(':checked'),
                include_over_under: $('#include_over_under').is(':checked'),
                include_asian_handicap: $('#include_asian_handicap').is(':checked'),
                nonce: $('input[name="nonce"]').val()
            };
            
            $.post(ajaxurl, formData)
                .done(function(response) {
                    if (response.success) {
                        displayAdvancedResults(response.data);
                        $('#advanced-benchmark-results').show();
                        
                        // Recargar tabla de historial
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                })
                .fail(function() {
                    alert('Error de conexión');
                })
                .always(function() {
                    $button.prop('disabled', false).text(originalText);
                });
        });
        
        function displayAdvancedResults(data) {
            const summary = data.summary;
            const marketBreakdown = summary.market_breakdown;
            
            let html = `
                <div class="benchmark-summary">
                    <div class="summary-grid">
                        <div class="summary-item">
                            <h4>📊 Precisión General</h4>
                            <span class="big-number">${summary.accuracy}%</span>
                        </div>
                        <div class="summary-item">
                            <h4>💰 ROI Total</h4>
                            <span class="big-number ${summary.roi > 0 ? 'positive' : 'negative'}">${summary.roi}%</span>
                        </div>
                        <div class="summary-item">
                            <h4>🎯 Apuestas Totales</h4>
                            <span class="big-number">${summary.total_bets}</span>
                        </div>
                        <div class="summary-item">
                            <h4>📈 Win Rate</h4>
                            <span class="big-number">${summary.win_rate}%</span>
                        </div>
                    </div>
                    
                    <h4>📊 Desglose por Mercado</h4>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th>Mercado</th>
                                <th>Apuestas</th>
                                <th>Win Rate</th>
                                <th>ROI</th>
                                <th>Beneficio</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            Object.keys(marketBreakdown).forEach(market => {
                const data = marketBreakdown[market];
                if (data.bets > 0) {
                    html += `
                        <tr>
                            <td><strong>${market.toUpperCase()}</strong></td>
                            <td>${data.bets}</td>
                            <td>${data.win_rate}%</td>
                            <td class="${data.roi > 0 ? 'positive' : 'negative'}">${data.roi}%</td>
                            <td>€${data.profit.toFixed(2)}</td>
                        </tr>
                    `;
                }
            });
            
            html += `
                        </tbody>
                    </table>
                    
                    <p>
                        <strong>Total de detalles de apuestas registrados:</strong> ${summary.betting_details_count}
                    </p>
                </div>
            `;
            
            $('#advanced-results-content').html(html);
        }
    });
    
    function showAdvancedBenchmarkDetails(benchmarkId) {
        // AJAX call para mostrar detalles completos partido por partido
        jQuery.post(ajaxurl, {
            action: 'get_advanced_benchmark_details',
            benchmark_id: benchmarkId,
            nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
        }, function(response) {
            if (response.success) {
                showBenchmarkDetailsModal(response.data);
            }
        });
    }
    
    function showBenchmarkDetailsModal(data) {
        // Crear modal con detalles partido por partido
        const modal = document.createElement('div');
        modal.className = 'benchmark-details-modal';
        modal.innerHTML = `
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2>📊 Detalles Partido por Partido</h2>
                <div class="details-content">
                    ${formatBettingDetails(data.betting_details)}
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        modal.style.display = 'block';
        
        modal.querySelector('.close').onclick = function() {
            document.body.removeChild(modal);
        };
    }
    
    function formatBettingDetails(bettingDetails) {
        let html = `
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Partido</th>
                        <th>Mercado</th>
                        <th>Apuesta</th>
                        <th>Resultado</th>
                        <th>Cuota</th>
                        <th>Value</th>
                        <th>Stake</th>
                        <th>Beneficio</th>
                        <th>Explicación</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        bettingDetails.forEach(bet => {
            html += `
                <tr class="${bet.won ? 'winning-bet' : 'losing-bet'}">
                    <td>${bet.date}</td>
                    <td>
                        <strong>${bet.home_team}</strong> vs <strong>${bet.away_team}</strong><br>
                        <small>${bet.home_goals}-${bet.away_goals}</small>
                    </td>
                    <td>${bet.market.toUpperCase()}</td>
                    <td>
                        ${bet.bet_type.toUpperCase()}
                        ${bet.line ? ` (${bet.line})` : ''}
                    </td>
                    <td>
                        <span class="${bet.won ? 'win' : 'loss'}">
                            ${bet.won ? '✅ WIN' : '❌ LOSS'}
                        </span>
                    </td>
                    <td>${bet.odds}</td>
                    <td>${(bet.value * 100).toFixed(1)}%</td>
                    <td>€${bet.stake_amount} (${bet.stake_level})</td>
                    <td class="${bet.profit > 0 ? 'profit' : 'loss'}">
                        €${bet.profit.toFixed(2)}
                    </td>
                    <td><small>${bet.explanation}</small></td>
                </tr>
            `;
        });
        
        html += '</tbody></table>';
        return html;
    }
    </script>

    <style>
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin: 20px 0;
    }
    
    .summary-item {
        text-align: center;
        padding: 20px;
        background: #f9f9f9;
        border-radius: 8px;
    }
    
    .big-number {
        display: block;
        font-size: 2em;
        font-weight: bold;
        margin-top: 10px;
    }
    
    .big-number.positive { color: #00a32a; }
    .big-number.negative { color: #d63638; }
    
    .positive { color: #00a32a; font-weight: bold; }
    .negative { color: #d63638; font-weight: bold; }
    
    .benchmark-details-modal {
        position: fixed;
        z-index: 100000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }
    
    .benchmark-details-modal .modal-content {
        background-color: #fefefe;
        margin: 2% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 95%;
        max-height: 90vh;
        overflow-y: auto;
        border-radius: 8px;
    }
    
    .winning-bet { background-color: #f0f8f0; }
    .losing-bet { background-color: #fdf0f0; }
    
    .win { color: #00a32a; font-weight: bold; }
    .loss { color: #d63638; font-weight: bold; }
    .profit { color: #00a32a; font-weight: bold; }
    
    .season-info {
        background: #e8f4fd;
        border: 1px solid #0073aa;
        padding: 10px;
        margin: 10px 0;
        border-radius: 4px;
    }
    </style>
    <?php
}
    /**
     * Página de configuración
     */
    public function settings_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }
        
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php _e('Configuración', 'football-tipster'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('ft_settings', 'ft_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('API Key', 'football-tipster'); ?></th>
                        <td>
                            <input type="text" name="api_key" value="<?php echo esc_attr(get_option('ft_api_key')); ?>" class="regular-text" readonly>
                            <p class="description"><?php _e('Clave para acceso a la API REST.', 'football-tipster'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Deportes habilitados', 'football-tipster'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="sports[]" value="football" <?php checked(in_array('football', $settings['sports'])); ?>>
                                <?php _e('Fútbol', 'football-tipster'); ?>
                            </label><br/>
                            <label>
                                <input type="checkbox" name="sports[]" value="basketball" <?php checked(in_array('basketball', $settings['sports'])); ?>>
                                <?php _e('Baloncesto', 'football-tipster'); ?>
                            </label><br/>
                            <label>
                                <input type="checkbox" name="sports[]" value="tennis" <?php checked(in_array('tennis', $settings['sports'])); ?>>
                                <?php _e('Tenis', 'football-tipster'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Actualización automática xG', 'football-tipster'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_update_xg" value="1" <?php checked($settings['auto_update_xg']); ?>>
                                <?php _e('Actualizar xG automáticamente', 'football-tipster'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="<?php _e('Guardar Configuración', 'football-tipster'); ?>">
                </p>
            </form>
        </div>
        <?php
    }
    
	/**
 * Página de diagnóstico
 */
public function diagnosis_page() {
    if (!class_exists('FT_Diagnostics')) {
        echo '<div class="wrap"><h1>Error</h1><p>Clase FT_Diagnostics no encontrada.</p></div>';
        return;
    }
    
    $diagnosis = FT_Diagnostics::run_full_diagnosis();
    ?>
    <div class="wrap">
        <h1>🔍 Football Tipster - Diagnóstico</h1>
        
        <!-- Base de Datos -->
        <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <h2>🗄️ Base de Datos</h2>
            <table class="widefat">
                <tr>
                    <td><strong>Conexión:</strong></td>
                    <td><?php echo $diagnosis['database']['connection'] === 'OK' ? '✅' : '❌'; ?> <?php echo $diagnosis['database']['connection']; ?></td>
                </tr>
                <?php if (isset($diagnosis['database']['tables'])): ?>
                    <?php foreach ($diagnosis['database']['tables'] as $table => $status): ?>
                    <tr>
                        <td><strong>Tabla <?php echo $table; ?>:</strong></td>
                        <td><?php echo $status === 'OK' ? '✅' : '❌'; ?> <?php echo $status; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
        
        <!-- Tests -->
        <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <h2>📊 Test CSV</h2>
            <button id="ft-test-csv" class="button button-primary">Ejecutar Test CSV</button>
            <div id="ft-test-result"></div>
        </div>
        
       <script>
jQuery(document).ready(function($) {
    console.log('=== DEBUG AJAX ===');
    console.log('ajaxurl existe:', typeof ajaxurl !== 'undefined');
    console.log('ajaxurl valor:', ajaxurl);
    console.log('jQuery cargado:', typeof $ !== 'undefined');
    
    $('#ft-test-csv').click(function(e) {
        e.preventDefault();
        console.log('Botón clickeado');
        
        var $btn = $(this);
        $btn.prop('disabled', true).text('Ejecutando...');
        
        var ajax_url = '<?php echo admin_url('admin-ajax.php'); ?>';
        console.log('URL manual:', ajax_url);
        
        $.ajax({
            url: ajax_url,
            type: 'POST',
            data: {
                action: 'ft_debug'
            },
            timeout: 10000,
            beforeSend: function() {
                console.log('Enviando petición...');
            },
            success: function(data, textStatus, xhr) {
                console.log('✅ Éxito:', data);
                $('#ft-test-result').html('<div style="background: #ccffcc; padding: 10px;">✅ AJAX funciona: ' + data + '</div>');
            },
            error: function(xhr, status, error) {
                console.log('❌ Error:', error);
                console.log('Status:', status);
                console.log('XHR status code:', xhr.status);
                
                var errorMsg = '<div style="background: #ffcccc; padding: 10px;">';
                errorMsg += '<strong>❌ Error AJAX:</strong><br/>';
                errorMsg += 'Status: ' + status + '<br/>';
                errorMsg += 'Error: ' + error + '<br/>';
                errorMsg += 'XHR Status: ' + xhr.status + '<br/>';
                errorMsg += '</div>';
                
                $('#ft-test-result').html(errorMsg);
            },
            complete: function() {
                $btn.prop('disabled', false).text('Ejecutar Test CSV');
            }
        });
    });
});
</script>
    </div>
<script>
console.log('Testing jQuery:', typeof jQuery);
console.log('Testing $:', typeof $);
jQuery(document).ready(function($) {
    console.log('jQuery ready ejecutado');
    console.log('Formulario encontrado:', $('#ft-benchmark-form').length);
    console.log('ajaxurl disponible:', typeof ajaxurl, ajaxurl);
});
</script>
    <?php
}

/**
 * Página de Value Bets
 */
public function value_bets_page() {
    // Obtener estadísticas
    $stats = $this->get_value_bets_stats();
    ?>
    <div class="wrap">
        <h1>💰 Value Bets Dashboard</h1>
        
        <!-- Estadísticas principales -->
        <div class="ft-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            <div class="ft-stat-card" style="background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3 style="margin: 0; color: #28a745;">🎯 Value Bets Activos</h3>
                <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo $stats['active_bets']; ?></div>
            </div>
            <div class="ft-stat-card" style="background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3 style="margin: 0; color: #007cba;">📊 Valor Promedio</h3>
                <div style="font-size: 32px; font-weight: bold; color: #007cba;"><?php echo $stats['avg_value']; ?>%</div>
            </div>
            <div class="ft-stat-card" style="background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3 style="margin: 0; color: #dc3545;">🔥 Top Value</h3>
                <div style="font-size: 32px; font-weight: bold; color: #dc3545;"><?php echo $stats['max_value']; ?>%</div>
            </div>
            <div class="ft-stat-card" style="background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h3 style="margin: 0; color: #6f42c1;">⚡ Próximos Partidos</h3>
                <div style="font-size: 32px; font-weight: bold; color: #6f42c1;"><?php echo $stats['upcoming_fixtures']; ?></div>
            </div>
        </div>
        
        <!-- Controles -->
        <div class="ft-controls" style="background: white; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <button id="ft-sync-pinnacle" class="button button-primary">🔄 Sincronizar Pinnacle</button>
                <button id="ft-analyze-value" class="button button-secondary">🎯 Analizar Value Bets</button>
                <button id="ft-refresh-data" class="button">♻️ Actualizar Dashboard</button>
                
                <div style="margin-left: auto;">
                    <select id="ft-filter-market" style="margin-right: 10px;">
                        <option value="all">Todos los mercados</option>
                        <option value="moneyline">Moneyline (1X2)</option>
                        <option value="total">Totales (O/U)</option>
                        <option value="spread">Handicap</option>
                    </select>
                    
                    <select id="ft-filter-min-value">
                        <option value="0">Min Value: 0%</option>
                        <option value="5" selected>Min Value: 5%</option>
                        <option value="10">Min Value: 10%</option>
                        <option value="15">Min Value: 15%</option>
                        <option value="20">Min Value: 20%</option>
                    </select>
                </div>
            </div>
            
            <div id="ft-sync-status" style="margin-top: 15px;"></div>
        </div>
        
        <!-- Tabla de Value Bets -->
        <div class="ft-value-bets-container" style="background: white; padding: 20px; border-radius: 8px;">
            <h2>🏆 Mejores Oportunidades de Value</h2>
            <div id="ft-value-bets-table">
                <div class="ft-loading">Cargando value bets...</div>
            </div>
        </div>
        
        <!-- Próximos partidos sin analizar -->
        <div class="ft-upcoming-fixtures" style="background: white; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h2>📅 Próximos Partidos</h2>
            <div id="ft-upcoming-table">
                <div class="ft-loading">Cargando próximos partidos...</div>
            </div>
        </div>
    </div>
    
    <style>
    .ft-loading {
        text-align: center;
        padding: 40px;
        color: #666;
    }
    
    .ft-value-bet-row {
        border-bottom: 1px solid #eee;
        padding: 15px 0;
        display: grid;
        grid-template-columns: 2fr 1fr 80px 80px 80px 80px 100px;
        gap: 15px;
        align-items: center;
    }
    
    .ft-value-bet-row:hover {
        background: #f9f9f9;
    }
    
    .ft-match-info h4 {
        margin: 0 0 5px 0;
        font-size: 14px;
    }
    
    .ft-match-meta {
        font-size: 12px;
        color: #666;
    }
    
    .ft-bet-type {
        font-weight: bold;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        text-align: center;
    }
    
    .ft-bet-type.moneyline { background: #e3f2fd; color: #1976d2; }
    .ft-bet-type.total { background: #f3e5f5; color: #7b1fa2; }
    .ft-bet-type.spread { background: #e8f5e8; color: #388e3c; }
    
    .ft-value-high { color: #d32f2f; font-weight: bold; }
    .ft-value-medium { color: #f57c00; font-weight: bold; }
    .ft-value-low { color: #388e3c; font-weight: bold; }
    
    .ft-confidence-high { color: #4caf50; }
    .ft-confidence-medium { color: #ff9800; }
    .ft-confidence-low { color: #f44336; }
    
    .ft-table-header {
        display: grid;
        grid-template-columns: 2fr 1fr 80px 80px 80px 80px 100px;
        gap: 15px;
        padding: 10px 0;
        border-bottom: 2px solid #ddd;
        font-weight: bold;
        background: #f5f5f5;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Cargar datos iniciales
        loadValueBets();
        loadUpcomingFixtures();
        
        // Event handlers
        $('#ft-sync-pinnacle').click(syncPinnacle);
        $('#ft-analyze-value').click(analyzeValue);
        $('#ft-refresh-data').click(refreshDashboard);
        $('#ft-filter-market, #ft-filter-min-value').change(loadValueBets);
        
        function loadValueBets() {
            $('#ft-value-bets-table').html('<div class="ft-loading">Cargando value bets...</div>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ft_get_value_bets_admin',
                    nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>',
                    market_filter: $('#ft-filter-market').val(),
                    min_value: $('#ft-filter-min-value').val()
                },
                success: function(response) {
                    if (response.success) {
                        displayValueBets(response.data);
                    } else {
                        $('#ft-value-bets-table').html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $('#ft-value-bets-table').html('<div class="notice notice-error"><p>Error al cargar value bets</p></div>');
                }
            });
        }
        
        function displayValueBets(bets) {
            if (!bets || bets.length === 0) {
                $('#ft-value-bets-table').html('<p>No se encontraron value bets con los filtros actuales.</p>');
                return;
            }
            
            let html = '<div class="ft-table-header">';
            html += '<div>Partido</div>';
            html += '<div>Apuesta</div>';
            html += '<div>Valor</div>';
            html += '<div>Cuota</div>';
            html += '<div>Prob.</div>';
            html += '<div>Conf.</div>';
            html += '<div>Stake</div>';
            html += '</div>';
            
            bets.forEach(function(bet) {
                const valueClass = bet.value_percentage >= 15 ? 'ft-value-high' : 
                                bet.value_percentage >= 8 ? 'ft-value-medium' : 'ft-value-low';
                
                const confClass = bet.confidence_score >= 0.8 ? 'ft-confidence-high' :
                                bet.confidence_score >= 0.6 ? 'ft-confidence-medium' : 'ft-confidence-low';
                
                html += '<div class="ft-value-bet-row">';
                html += '<div class="ft-match-info">';
                html += '<h4>' + bet.home_team + ' vs ' + bet.away_team + '</h4>';
                html += '<div class="ft-match-meta">' + bet.league + ' • ' + formatDateTime(bet.start_time) + '</div>';
                html += '</div>';
                
                html += '<div>';
                html += '<span class="ft-bet-type ' + bet.market_type + '">' + getBetDescription(bet) + '</span>';
                html += '</div>';
                
                html += '<div class="' + valueClass + '">' + bet.value_percentage + '%</div>';
                html += '<div>' + parseFloat(bet.decimal_odds).toFixed(2) + '</div>';
                html += '<div>' + (bet.our_probability * 100).toFixed(1) + '%</div>';
                html += '<div class="' + confClass + '">' + (bet.confidence_score * 100).toFixed(0) + '%</div>';
                html += '<div>€' + parseFloat(bet.recommended_stake || 0).toFixed(0) + '</div>';
                html += '</div>';
            });
            
            $('#ft-value-bets-table').html(html);
        }
        
        function loadUpcomingFixtures() {
            $('#ft-upcoming-table').html('<div class="ft-loading">Cargando próximos partidos...</div>');
            
            $.post(ajaxurl, {
                action: 'ft_get_upcoming_fixtures',
                nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    displayUpcomingFixtures(response.data);
                }
            });
        }
        
        function displayUpcomingFixtures(fixtures) {
            if (!fixtures || fixtures.length === 0) {
                $('#ft-upcoming-table').html('<p>No hay próximos partidos cargados.</p>');
                return;
            }
            
            let html = '<table class="widefat"><thead><tr>';
            html += '<th>Partido</th><th>Liga</th><th>Fecha</th><th>Estado</th><th>Acciones</th>';
            html += '</tr></thead><tbody>';
            
            fixtures.forEach(function(fixture) {
                html += '<tr>';
                html += '<td><strong>' + fixture.home_team + ' vs ' + fixture.away_team + '</strong></td>';
                html += '<td>' + fixture.league + '</td>';
                html += '<td>' + formatDateTime(fixture.start_time) + '</td>';
                html += '<td><span class="ft-status-' + fixture.status + '">' + fixture.status + '</span></td>';
                html += '<td><button class="button button-small ft-predict-fixture" data-fixture-id="' + fixture.id + '">Predecir</button></td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            $('#ft-upcoming-table').html(html);
        }
        
        function syncPinnacle() {
            const $btn = $('#ft-sync-pinnacle');
            $btn.prop('disabled', true).text('🔄 Sincronizando...');
            $('#ft-sync-status').html('<div class="notice notice-info"><p>Sincronizando datos de Pinnacle...</p></div>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 60000, // 1 minuto
                data: {
                    action: 'ft_sync_pinnacle_data',
                    nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('#ft-sync-status').html('<div class="notice notice-success"><p>✅ ' + response.data.message + '</p></div>');
                        setTimeout(refreshDashboard, 2000);
                    } else {
                        $('#ft-sync-status').html('<div class="notice notice-error"><p>❌ Error: ' + response.data + '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    $('#ft-sync-status').html('<div class="notice notice-error"><p>❌ Error de conexión: ' + error + '</p></div>');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('🔄 Sincronizar Pinnacle');
                }
            });
        }
        
        function analyzeValue() {
            const $btn = $('#ft-analyze-value');
            $btn.prop('disabled', true).text('🎯 Analizando...');
            $('#ft-sync-status').html('<div class="notice notice-info"><p>Analizando value bets...</p></div>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 120000, // 2 minutos
                data: {
                    action: 'ft_analyze_value_bets',
                    nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        $('#ft-sync-status').html('<div class="notice notice-success"><p>✅ Análisis completado: ' + 
                                                data.value_bets_found + ' value bets encontrados de ' + 
                                                data.processed_fixtures + ' partidos analizados</p></div>');
                        setTimeout(refreshDashboard, 2000);
                    } else {
                        $('#ft-sync-status').html('<div class="notice notice-error"><p>❌ Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $('#ft-sync-status').html('<div class="notice notice-error"><p>❌ Error al analizar value bets</p></div>');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('🎯 Analizar Value Bets');
                }
            });
        }
        
        function refreshDashboard() {
            location.reload();
        }
        
        function getBetDescription(bet) {
            switch(bet.market_type) {
                case 'moneyline':
                    const betTypes = {home: '1', draw: 'X', away: '2'};
                    return betTypes[bet.bet_type] || bet.bet_type;
                case 'total':
                    return bet.bet_type === 'over' ? 'O' + bet.line_value : 'U' + bet.line_value;
                case 'spread':
                    return bet.bet_type + ' ' + bet.line_value;
                default:
                    return bet.bet_type;
            }
        }
        
        function formatDateTime(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString('es-ES', {hour: '2-digit', minute: '2-digit'});
        }
        
        // Auto-refresh cada 5 minutos
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                loadValueBets();
            }
        }, 300000);
    });
    </script>
    <?php
}
/**
 * Página de Sincronización y Configuración
 */
public function sync_page() {
    // Procesar formulario si se envió
    if (isset($_POST['submit_pinnacle_config'])) {
        $this->save_pinnacle_config();
    }
    
    if (isset($_POST['submit_value_config'])) {
        $this->save_value_config();
    }
    
    // Obtener configuración actual
    $pinnacle_config = $this->get_pinnacle_config();
    $value_config = $this->get_value_config();
    $sync_logs = $this->get_sync_logs();
    ?>
    <div class="wrap">
        <h1>⚙️ Configuración y Sincronización</h1>
        
        <!-- Test de conexión -->
        <div class="ft-connection-test" style="background: white; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h2>🔗 Test de Conexión</h2>
            <p>Verifica que las credenciales de Pinnacle funcionan correctamente.</p>
            <button id="ft-test-pinnacle" class="button button-primary">🧪 Test Conexión Pinnacle</button>
            <div id="ft-connection-result" style="margin-top: 15px;"></div>
        </div>
        
        <!-- Configuración de Pinnacle -->
        <div class="ft-pinnacle-config" style="background: white; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h2>🏟️ Configuración Pinnacle API</h2>
            <form method="post" action="">
                <?php wp_nonce_field('ft_pinnacle_config', 'ft_pinnacle_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="pinnacle_username">Usuario Pinnacle</label>
                        </th>
                        <td>
                            <input type="text" id="pinnacle_username" name="pinnacle_username" 
                                   value="<?php echo esc_attr($pinnacle_config['username']); ?>" 
                                   class="regular-text" required>
                            <p class="description">Tu nombre de usuario de la API de Pinnacle</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="pinnacle_password">Password Pinnacle</label>
                        </th>
                        <td>
                            <input type="password" id="pinnacle_password" name="pinnacle_password" 
                                   value="<?php echo esc_attr($pinnacle_config['password']); ?>" 
                                   class="regular-text" required>
                            <p class="description">Tu contraseña de la API de Pinnacle</p>
                        </td>
                    </tr>
                    <tr>
    <th scope="row">
        <label for="pinnacle_leagues">Ligas a Sincronizar</label>
    </th>
    <td>
        <select id="pinnacle_leagues_select" name="pinnacle_leagues[]" class="large-text" multiple="multiple" style="min-height: 120px;">
            <!-- Opciones vía JS -->
        </select>
        <!-- Hidden para almacenar los valores seleccionados (JSON con IDs) -->
        <input type="hidden" id="pinnacle_leagues" name="pinnacle_leagues" value="<?php echo esc_attr($pinnacle_config['leagues']); ?>">
        <p class="description">Selecciona una o varias ligas. Tus selecciones se guardarán y se usarán para la sincronización.</p>
    </td>
</tr>
                        <th scope="row">
                            <label for="sync_frequency">Frecuencia de Sincronización</label>
                        </th>
                        <td>
                            <select id="sync_frequency" name="sync_frequency">
                                <option value="15min" <?php selected($pinnacle_config['sync_frequency'], '15min'); ?>>Cada 15 minutos</option>
                                <option value="30min" <?php selected($pinnacle_config['sync_frequency'], '30min'); ?>>Cada 30 minutos</option>
                                <option value="1hour" <?php selected($pinnacle_config['sync_frequency'], '1hour'); ?>>Cada hora</option>
                                <option value="2hours" <?php selected($pinnacle_config['sync_frequency'], '2hours'); ?>>Cada 2 horas</option>
                                <option value="manual" <?php selected($pinnacle_config['sync_frequency'], 'manual'); ?>>Solo Manual</option>
                            </select>
                            <p class="description">Con qué frecuencia sincronizar automáticamente con Pinnacle</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit_pinnacle_config" class="button-primary" value="💾 Guardar Configuración Pinnacle">
                </p>
            </form>
        </div>
        
        <!-- Configuración de Value Betting (actualización) -->
<div class="ft-value-config" style="background: white; padding: 20px; margin: 20px 0; border-radius: 8px;">
    <h2>💰 Configuración Value Betting</h2>
    <form method="post" action="">
        <?php wp_nonce_field('ft_value_config', 'ft_value_nonce'); ?>
        
        <h3>📊 Parámetros Básicos</h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="bankroll">Bankroll (€)</label>
                </th>
                <td>
                    <input type="number" id="bankroll" name="bankroll" 
                           value="<?php echo esc_attr($value_config['bankroll']); ?>" 
                           min="100" step="50" required>
                    <p class="description">Tu capital total disponible para apuestas</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="min_value_threshold">Valor Mínimo (%)</label>
                </th>
                <td>
                    <input type="number" id="min_value_threshold" name="min_value_threshold" 
                           value="<?php echo esc_attr($value_config['min_value']); ?>" 
                           min="1" max="50" step="0.5" required>
                    <p class="description">Porcentaje mínimo de valor para considerar una apuesta</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="min_confidence_threshold">Confianza Mínima</label>
                </th>
                <td>
                    <input type="number" id="min_confidence_threshold" name="min_confidence_threshold" 
                           value="<?php echo esc_attr($value_config['min_confidence']); ?>" 
                           min="0.1" max="1.0" step="0.05" required>
                    <p class="description">Nivel mínimo de confianza (0.1 - 1.0)</p>
                </td>
            </tr>
        </table>
        
        <h3>💵 Gestión de Stakes</h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="stake_system">Sistema de Stakes</label>
                </th>
                <td>
                    <select id="stake_system" name="stake_system">
                        <option value="fixed" <?php selected($value_config['stake_system'], 'fixed'); ?>>Fijo</option>
                        <option value="variable" <?php selected($value_config['stake_system'], 'variable'); ?>>Variable (1-5)</option>
                        <option value="kelly" <?php selected($value_config['stake_system'], 'kelly'); ?>>Kelly Criterion</option>
                    </select>
                    <p class="description">Cómo calcular el tamaño de las apuestas</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="base_unit">Unidad Base (€)</label>
                </th>
                <td>
                    <input type="number" id="base_unit" name="base_unit" 
                           value="<?php echo esc_attr($value_config['base_unit']); ?>" 
                           min="1" step="1" required>
                    <p class="description">Tamaño de la unidad base de apuesta</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="max_stake_percentage">Stake Máximo (%)</label>
                </th>
                <td>
                    <input type="number" id="max_stake_percentage" name="max_stake_percentage" 
                           value="<?php echo esc_attr($value_config['max_stake_percentage']); ?>" 
                           min="1" max="10" step="0.5" required>
                    <p class="description">Porcentaje máximo del bankroll por apuesta</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="kelly_fraction">Fracción Kelly</label>
                </th>
                <td>
                    <select id="kelly_fraction" name="kelly_fraction">
                        <option value="0.10" <?php selected($value_config['kelly_fraction'], '0.10'); ?>>10% (Muy Conservador)</option>
                        <option value="0.25" <?php selected($value_config['kelly_fraction'], '0.25'); ?>>25% (Conservador)</option>
                        <option value="0.50" <?php selected($value_config['kelly_fraction'], '0.50'); ?>>50% (Moderado)</option>
                        <option value="0.75" <?php selected($value_config['kelly_fraction'], '0.75'); ?>>75% (Agresivo)</option>
                        <option value="1.00" <?php selected($value_config['kelly_fraction'], '1.00'); ?>>100% (Muy Agresivo)</option>
                    </select>
                    <p class="description">Fracción del Kelly Criterion a usar (menor = más seguro)</p>
                </td>
            </tr>
        </table>
        
        <h3>🎯 Filtros de Apuestas</h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label>Rango de Cuotas</label>
                </th>
                <td>
                    <label>Mínima: 
                        <input type="number" name="min_odds" 
                               value="<?php echo esc_attr($value_config['min_odds']); ?>" 
                               min="1.01" max="5" step="0.1" style="width: 80px;">
                    </label>
                    <label style="margin-left: 20px;">Máxima: 
                        <input type="number" name="max_odds" 
                               value="<?php echo esc_attr($value_config['max_odds']); ?>" 
                               min="1.5" max="20" step="0.1" style="width: 80px;">
                    </label>
                    <p class="description">Solo considerar apuestas dentro de este rango de cuotas</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="max_daily_bets">Máximo Apuestas/Día</label>
                </th>
                <td>
                    <input type="number" id="max_daily_bets" name="max_daily_bets" 
                           value="<?php echo esc_attr($value_config['max_daily_bets']); ?>" 
                           min="1" max="50" step="1">
                    <p class="description">Número máximo de apuestas por día</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="markets_enabled">Mercados Habilitados</label>
                </th>
                <td>
                    <?php $enabled_markets = explode(',', $value_config['markets_enabled']); ?>
                    <label>
                        <input type="checkbox" name="markets_enabled[]" value="moneyline" 
                               <?php checked(in_array('moneyline', $enabled_markets)); ?>>
                        Moneyline (1X2)
                    </label><br/>
                    <label>
                        <input type="checkbox" name="markets_enabled[]" value="total" 
                               <?php checked(in_array('total', $enabled_markets)); ?>>
                        Totales (Over/Under)
                    </label><br/>
                    <label>
                        <input type="checkbox" name="markets_enabled[]" value="spread" 
                               <?php checked(in_array('spread', $enabled_markets)); ?>>
                        Handicap (Spread)
                    </label>
                    <p class="description">Qué tipos de mercados analizar para value bets</p>
                </td>
            </tr>
        </table>
        
        <h3>🛡️ Gestión de Riesgos</h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label>Stop Loss</label>
                </th>
                <td>
                    <label>Diario: €
                        <input type="number" name="stop_loss_daily" 
                               value="<?php echo esc_attr($value_config['stop_loss_daily']); ?>" 
                               min="10" step="10" style="width: 100px;">
                    </label>
                    <label style="margin-left: 20px;">Semanal: €
                        <input type="number" name="stop_loss_weekly" 
                               value="<?php echo esc_attr($value_config['stop_loss_weekly']); ?>" 
                               min="50" step="10" style="width: 100px;">
                    </label>
                    <p class="description">Pérdida máxima antes de parar las apuestas</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="min_bankroll_percentage">Bankroll Mínimo (%)</label>
                </th>
                <td>
                    <input type="number" id="min_bankroll_percentage" name="min_bankroll_percentage" 
                           value="<?php echo esc_attr($value_config['min_bankroll_percentage']); ?>" 
                           min="10" max="50" step="5">
                    <p class="description">No apostar si el bankroll cae por debajo de este % del inicial</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="streak_protection">Protección de Rachas</label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="streak_protection" name="streak_protection" value="1" 
                               <?php checked($value_config['streak_protection']); ?>>
                        Reducir stakes después de 3 pérdidas consecutivas
                    </label>
                </td>
            </tr>
        </table>
        
        <h3>⚙️ Automatización</h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="auto_analyze">Análisis Automático</label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="auto_analyze" name="auto_analyze" value="1" 
                               <?php checked($value_config['auto_analyze']); ?>>
                        Analizar automáticamente después de cada sincronización
                    </label>
                    <p class="description">Si está habilitado, se ejecutará el análisis de value bets después de sincronizar con Pinnacle</p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <input type="submit" name="submit_value_config" class="button-primary" value="💾 Guardar Configuración Value Betting">
        </p>
    </form>
</div>
        
        <!-- Sincronización Manual -->
        <div class="ft-manual-sync" style="background: white; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h2>🔄 Sincronización Manual</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <button id="ft-sync-leagues" class="button button-secondary">📋 Sincronizar Ligas</button>
                <button id="ft-sync-fixtures" class="button button-secondary">⚽ Sincronizar Partidos</button>
                <button id="ft-sync-odds" class="button button-secondary">💹 Sincronizar Cuotas</button>
                <button id="ft-full-sync" class="button button-primary">🔄 Sincronización Completa</button>
            </div>
            <div id="ft-manual-sync-result" style="margin-top: 15px;"></div>
        </div>
        
        <!-- Logs de Sincronización -->
        <div class="ft-sync-logs" style="background: white; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h2>📜 Logs de Sincronización</h2>
            <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 15px;">
                <p>Últimas 20 operaciones de sincronización:</p>
                <button id="ft-clear-logs" class="button">🗑️ Limpiar Logs</button>
            </div>
            
            <?php if (!empty($sync_logs)): ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Operación</th>
                        <th>Estado</th>
                        <th>Detalles</th>
                        <th>Duración</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sync_logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html(date('d/m/Y H:i:s', strtotime($log->created_at))); ?></td>
                        <td>
                            <span class="ft-operation-<?php echo esc_attr($log->operation); ?>">
                                <?php echo esc_html($this->get_operation_label($log->operation)); ?>
                            </span>
                        </td>
                        <td>
                            <span class="ft-status-<?php echo esc_attr($log->status); ?>">
                                <?php echo $log->status === 'success' ? '✅' : '❌'; ?>
                                <?php echo esc_html(ucfirst($log->status)); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($log->message); ?></td>
                        <td><?php echo $log->duration ? esc_html($log->duration . 's') : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p>No hay logs de sincronización todavía.</p>
            <?php endif; ?>
        </div>
        
        <!-- Estado del Sistema -->
        <div class="ft-system-status" style="background: white; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <h2>📊 Estado del Sistema</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <?php $system_status = $this->get_system_status(); ?>
                
                <div class="ft-status-card">
                    <h4>🔗 Última Sincronización</h4>
                    <div><?php echo $system_status['last_sync'] ? date('d/m/Y H:i', strtotime($system_status['last_sync'])) : 'Nunca'; ?></div>
                </div>
                
                <div class="ft-status-card">
                    <h4>⚽ Partidos Activos</h4>
                    <div><?php echo number_format($system_status['active_fixtures']); ?></div>
                </div>
                
                <div class="ft-status-card">
                    <h4>💹 Cuotas Disponibles</h4>
                    <div><?php echo number_format($system_status['available_odds']); ?></div>
                </div>
                
                <div class="ft-status-card">
                    <h4>💰 Value Bets Activos</h4>
                    <div><?php echo number_format($system_status['active_value_bets']); ?></div>
                </div>
            </div>
        </div>
  
    
    <style>
    .ft-operation-sync_leagues { color: #007cba; }
    .ft-operation-sync_fixtures { color: #00a32a; }
    .ft-operation-sync_odds { color: #dba617; }
    .ft-operation-analyze_value { color: #8b2985; }
    
    .ft-status-success { color: #00a32a; }
    .ft-status-error { color: #d63638; }
    .ft-status-warning { color: #dba617; }
    
    .ft-status-card {
        text-align: center;
        padding: 15px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    
    .ft-status-card h4 {
        margin: 0 0 10px 0;
        font-size: 14px;
        color: #666;
    }
    
    .ft-status-card div {
        font-size: 24px;
        font-weight: bold;
        color: #333;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Test de conexión Pinnacle
        $('#ft-test-pinnacle').click(function() {
            const $btn = $(this);
            $btn.prop('disabled', true).text('🔄 Testando...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ft_test_pinnacle_connection',
                    nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
                },
                success: function(response) {
                    let html = '<div class="notice notice-' + (response.success ? 'success' : 'error') + '">';
                    html += '<p><strong>' + (response.success ? '✅ Conexión exitosa' : '❌ Error de conexión') + '</strong></p>';
                    
                    if (response.success && response.data) {
                        html += '<p>Ligas disponibles: ' + response.data.leagues_count + '</p>';
                        if (response.data.sample_leagues) {
                            html += '<p>Ejemplos: ' + response.data.sample_leagues.map(l => l.name).join(', ') + '</p>';
                        }
                    } else if (response.data) {
                        html += '<p>' + response.data + '</p>';
                    }
                    
                    html += '</div>';
                    $('#ft-connection-result').html(html);
                },
                error: function() {
                    $('#ft-connection-result').html('<div class="notice notice-error"><p>❌ Error de conexión</p></div>');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('🧪 Test Conexión Pinnacle');
                }
            });
        });
        
        // Sincronización manual
        $('#ft-sync-leagues').click(() => runManualSync('leagues', '📋 Sincronizando ligas...'));
        $('#ft-sync-fixtures').click(() => runManualSync('fixtures', '⚽ Sincronizando partidos...'));
        $('#ft-sync-odds').click(() => runManualSync('odds', '💹 Sincronizando cuotas...'));
        $('#ft-full-sync').click(() => runManualSync('full', '🔄 Sincronización completa...'));
        
        function runManualSync(type, message) {
            const $result = $('#ft-manual-sync-result');
            $result.html('<div class="notice notice-info"><p>' + message + '</p></div>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 180000, // 3 minutos
                data: {
                    action: 'ft_manual_sync',
                    sync_type: type,
                    nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
                },
                success: function(response) {
                    const statusClass = response.success ? 'success' : 'error';
                    const icon = response.success ? '✅' : '❌';
                    $result.html('<div class="notice notice-' + statusClass + '"><p>' + icon + ' ' + response.data + '</p></div>');
                },
                error: function() {
                    $result.html('<div class="notice notice-error"><p>❌ Error en la sincronización</p></div>');
                }
            });
        }
        
        // Limpiar logs
        $('#ft-clear-logs').click(function() {
            if (confirm('¿Estás seguro de que quieres limpiar todos los logs?')) {
                $.post(ajaxurl, {
                    action: 'ft_clear_sync_logs',
                    nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    }
                });
            }
        });
    });
    </script>
    <?php
}
/**
 * Guardar configuración de Pinnacle
 */
private function save_pinnacle_config() {
    if (!wp_verify_nonce($_POST['ft_pinnacle_nonce'], 'ft_pinnacle_config')) {
        return;
    }
    
    $config = array(
        'username' => sanitize_text_field($_POST['pinnacle_username']),
        'password' => sanitize_text_field($_POST['pinnacle_password']),
        'leagues' => sanitize_textarea_field($_POST['pinnacle_leagues']),
        'sync_frequency' => sanitize_text_field($_POST['sync_frequency'])
    );
    
    update_option('ft_pinnacle_username', $config['username']);
    update_option('ft_pinnacle_password', $config['password']);
    update_option('ft_pinnacle_leagues', $config['leagues']);
    update_option('ft_pinnacle_sync_frequency', $config['sync_frequency']);
    
    // Reconfigurar cron jobs
    $this->setup_sync_cron($config['sync_frequency']);
    
    echo '<div class="notice notice-success"><p>✅ Configuración de Pinnacle guardada correctamente.</p></div>';
}

/**
 * Guardar configuración de value betting
 */
private function save_value_config() {
    if (!wp_verify_nonce($_POST['ft_value_nonce'], 'ft_value_config')) {
        return;
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'ft_value_config';
    
    // Crear tabla si no existe
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id int(11) NOT NULL AUTO_INCREMENT,
        min_value decimal(5,2) DEFAULT 10.00,
        min_confidence decimal(4,3) DEFAULT 0.400,
        max_stake_percentage decimal(5,2) DEFAULT 5.00,
        kelly_fraction decimal(4,3) DEFAULT 0.250,
        markets_enabled varchar(100) DEFAULT 'moneyline,total',
        auto_analyze tinyint(1) DEFAULT 1,
        min_odds decimal(6,3) DEFAULT 1.600,
        max_odds decimal(6,3) DEFAULT 4.000,
        stake_system varchar(20) DEFAULT 'variable',
        base_unit decimal(10,2) DEFAULT 10.00,
        max_daily_bets int(11) DEFAULT 10,
        stop_loss_daily decimal(10,2) DEFAULT 100.00,
        stop_loss_weekly decimal(10,2) DEFAULT 300.00,
        min_bankroll_percentage decimal(5,2) DEFAULT 20.00,
        avoid_teams text DEFAULT NULL,
        preferred_leagues text DEFAULT NULL,
        time_restrictions text DEFAULT NULL,
        streak_protection tinyint(1) DEFAULT 1,
        updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Preparar datos
    $markets_enabled = isset($_POST['markets_enabled']) ? implode(',', $_POST['markets_enabled']) : '';
    
    $data = array(
        'min_value' => floatval($_POST['min_value_threshold']),
        'min_confidence' => floatval($_POST['min_confidence_threshold']),
        'max_stake_percentage' => floatval($_POST['max_stake_percentage']),
        'kelly_fraction' => floatval($_POST['kelly_fraction']),
        'markets_enabled' => $markets_enabled,
        'auto_analyze' => isset($_POST['auto_analyze']) ? 1 : 0,
        'min_odds' => floatval($_POST['min_odds'] ?? 1.6),
        'max_odds' => floatval($_POST['max_odds'] ?? 4.0),
        'stake_system' => sanitize_text_field($_POST['stake_system'] ?? 'variable'),
        'base_unit' => floatval($_POST['base_unit'] ?? 10),
        'max_daily_bets' => intval($_POST['max_daily_bets'] ?? 10),
        'stop_loss_daily' => floatval($_POST['stop_loss_daily'] ?? 100),
        'stop_loss_weekly' => floatval($_POST['stop_loss_weekly'] ?? 300),
        'min_bankroll_percentage' => floatval($_POST['min_bankroll_percentage'] ?? 20),
        'streak_protection' => isset($_POST['streak_protection']) ? 1 : 0
    );
    
    // Verificar si existe configuración
    $exists = $wpdb->get_var("SELECT id FROM $table LIMIT 1");
    
    if ($exists) {
        $wpdb->update($table, $data, array('id' => $exists));
    } else {
        $wpdb->insert($table, $data);
    }
    
    // Guardar también el bankroll por separado
    update_option('ft_bankroll', floatval($_POST['bankroll']));
    
    echo '<div class="notice notice-success"><p>✅ Configuración de Value Betting guardada correctamente.</p></div>';
}
/**
 * Obtener configuración de Pinnacle
 */
private function get_pinnacle_config() {
    return array(
        'username' => get_option('ft_pinnacle_username', ''),
        'password' => get_option('ft_pinnacle_password', ''),
        'leagues' => get_option('ft_pinnacle_leagues', ''),
        'sync_frequency' => get_option('ft_pinnacle_sync_frequency', '1hour')
    );
}

/**
 * Obtener configuración de value betting
 */
private function get_value_config() {
    global $wpdb;
    $table = $wpdb->prefix . 'ft_value_config';
    
    // Obtener configuración de la tabla
    $config = $wpdb->get_row("SELECT * FROM $table LIMIT 1", ARRAY_A);
    
    // Si no existe, usar valores por defecto
    if (!$config) {
        $config = array(
            'min_value' => 10.0,
            'min_confidence' => 0.400,
            'max_stake_percentage' => 5.0,
            'kelly_fraction' => 0.250,
            'markets_enabled' => 'moneyline,total',
            'auto_analyze' => 1,
            'min_odds' => 1.6,
            'max_odds' => 4.0,
            'stake_system' => 'variable',
            'base_unit' => 10.0,
            'max_daily_bets' => 10,
            'stop_loss_daily' => 100.0,
            'stop_loss_weekly' => 300.0,
            'min_bankroll_percentage' => 20.0,
            'streak_protection' => 1
        );
    }
    
    // Añadir bankroll
    $config['bankroll'] = get_option('ft_bankroll', 1000);
    
    return $config;
}

/**
 * Obtener logs de sincronización
 */
private function get_sync_logs() {
    global $wpdb;
    
    // Crear tabla de logs si no existe
    $table = $wpdb->prefix . 'ft_sync_logs';
    $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
        id int(11) NOT NULL AUTO_INCREMENT,
        operation varchar(50) NOT NULL,
        status varchar(20) NOT NULL,
        message text,
        duration int(11) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_created (created_at)
    )");
    
    return $wpdb->get_results(
        "SELECT * FROM $table ORDER BY created_at DESC LIMIT 20"
    );
}

/**
 * Obtener estado del sistema
 */
private function get_system_status() {
    global $wpdb;
    
    return array(
        'last_sync' => get_option('ft_last_sync_time'),
        'active_fixtures' => $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ft_fixtures 
             WHERE start_time > NOW() AND status = 'upcoming'"
        ),
        'available_odds' => $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ft_odds o
             INNER JOIN {$wpdb->prefix}ft_fixtures f ON o.fixture_id = f.id
             WHERE f.start_time > NOW()"
        ),
        'active_value_bets' => $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ft_value_bets vb
             INNER JOIN {$wpdb->prefix}ft_fixtures f ON vb.fixture_id = f.id
             WHERE f.start_time > NOW() AND vb.status = 'active'"
        )
    );
}

/**
 * Obtener etiqueta de operación
 */
private function get_operation_label($operation) {
    $labels = array(
        'sync_leagues' => 'Sincronizar Ligas',
        'sync_fixtures' => 'Sincronizar Partidos',
        'sync_odds' => 'Sincronizar Cuotas',
        'analyze_value' => 'Analizar Value Bets',
        'full_sync' => 'Sincronización Completa'
    );
    
    return $labels[$operation] ?? $operation;
}

/**
 * Configurar cron jobs de sincronización
 */
private function setup_sync_cron($frequency) {
    // Limpiar cron jobs existentes
    wp_clear_scheduled_hook('ft_auto_sync');
    
    if ($frequency === 'manual') {
        return;
    }
    
    $intervals = array(
        '15min' => 15 * 60,
        '30min' => 30 * 60,
        '1hour' => 60 * 60,
        '2hours' => 2 * 60 * 60
    );
    
    if (isset($intervals[$frequency])) {
        wp_schedule_event(time(), $frequency, 'ft_auto_sync');
        
        // Registrar intervalo personalizado si no existe
        add_filter('cron_schedules', function($schedules) use ($frequency, $intervals) {
            if (!isset($schedules[$frequency])) {
                $schedules[$frequency] = array(
                    'interval' => $intervals[$frequency],
                    'display' => ucfirst(str_replace(['min', 'hour'], [' minutos', ' hora'], $frequency))
                );
            }
            return $schedules;
        });
    }
}
/**
 * Obtener estadísticas de value bets
 */
private function get_value_bets_stats() {
    global $wpdb;
    
    $stats = array();
    
    // Value bets activos
    $stats['active_bets'] = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ft_value_bets vb
         INNER JOIN {$wpdb->prefix}ft_fixtures f ON vb.fixture_id = f.id
         WHERE f.start_time > NOW() AND vb.status = 'active'"
    );
    
    // Valor promedio
    $avg_value = $wpdb->get_var(
        "SELECT AVG(value_percentage) FROM {$wpdb->prefix}ft_value_bets vb
         INNER JOIN {$wpdb->prefix}ft_fixtures f ON vb.fixture_id = f.id  
         WHERE f.start_time > NOW() AND vb.status = 'active'"
    );
    $stats['avg_value'] = $avg_value ? round($avg_value, 1) : 0;
    
    // Valor máximo
    $max_value = $wpdb->get_var(
        "SELECT MAX(value_percentage) FROM {$wpdb->prefix}ft_value_bets vb
         INNER JOIN {$wpdb->prefix}ft_fixtures f ON vb.fixture_id = f.id
         WHERE f.start_time > NOW() AND vb.status = 'active'"
    );
    $stats['max_value'] = $max_value ? round($max_value, 1) : 0;
    
    // Próximos partidos
    $stats['upcoming_fixtures'] = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ft_fixtures 
         WHERE start_time > NOW() AND start_time < DATE_ADD(NOW(), INTERVAL 7 DAY)"
    );
    
    return $stats;
}
    /**
     * Mostrar estado del sistema
     */
    private function display_system_status() {
        // Verificar Python
        $python_status = shell_exec('/usr/bin/python3.8 --version 2>&1');
        $python_ok = strpos($python_status, 'Python 3') !== false;
        
        // Verificar librerías Python
        $pandas_status = shell_exec('/usr/bin/python3.8 -c "import pandas; print(pandas.__version__)" 2>&1');
        $sklearn_status = shell_exec('/usr/bin/python3.8 -c "import sklearn; print(sklearn.__version__)" 2>&1');
        
        // Verificar modelo entrenado
        $model_exists = file_exists(FT_MODELS_PATH . 'football_rf_advanced.pkl');
        
        // Contar registros
        global $wpdb;
        $matches_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ft_matches_advanced");
        $predictions_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ft_predictions");
        
        ?>
        <table class="widefat">
            <tr>
                <td><?php _e('Python 3', 'football-tipster'); ?></td>
                <td><?php echo $python_ok ? '✅' : '❌'; ?> <?php echo esc_html($python_status); ?></td>
            </tr>
            <tr>
                <td><?php _e('Pandas', 'football-tipster'); ?></td>
                <td><?php echo $pandas_status ? '✅' : '❌'; ?> <?php echo esc_html($pandas_status); ?></td>
            </tr>
            <tr>
                <td><?php _e('Scikit-learn', 'football-tipster'); ?></td>
                <td><?php echo $sklearn_status ? '✅' : '❌'; ?> <?php echo esc_html($sklearn_status); ?></td>
            </tr>
            <tr>
                <td><?php _e('Modelo entrenado', 'football-tipster'); ?></td>
                <td><?php echo $model_exists ? '✅' : '❌'; ?></td>
            </tr>
            <tr>
                <td><?php _e('Partidos en BD', 'football-tipster'); ?></td>
                <td><?php echo number_format($matches_count); ?></td>
            </tr>
            <tr>
                <td><?php _e('Predicciones realizadas', 'football-tipster'); ?></td>
                <td><?php echo number_format($predictions_count); ?></td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Mostrar estadísticas del modelo
     */
    private function display_model_stats() {
        $metadata_file = FT_MODELS_PATH . 'model_metadata.json';
        
        if (!file_exists($metadata_file)) {
            echo '<p>' . __('No hay modelo entrenado.', 'football-tipster') . '</p>';
            return;
        }
        
        $metadata = json_decode(file_get_contents($metadata_file), true);
        
        if (!$metadata) {
            echo '<p>' . __('Error al leer metadatos del modelo.', 'football-tipster') . '</p>';
            return;
        }
        
        ?>
        <table class="widefat">
            <tr>
                <td><?php _e('Fecha de entrenamiento', 'football-tipster'); ?></td>
                <td><?php echo esc_html(date('d/m/Y H:i', strtotime($metadata['training_date']))); ?></td>
            </tr>
            <tr>
                <td><?php _e('Precisión', 'football-tipster'); ?></td>
                <td><?php echo esc_html(number_format($metadata['performance']['accuracy'] * 100, 2)); ?>%</td>
            </tr>
            <tr>
                <td><?php _e('Características utilizadas', 'football-tipster'); ?></td>
                <td><?php echo esc_html(count($metadata['features'])); ?></td>
            </tr>
        </table>
        <?php
    }
    
    /**
     * Obtener configuración
     */
    private function get_settings() {
        return array(
            'sports' => get_option('ft_enabled_sports', array('football')),
            'auto_update_xg' => get_option('ft_auto_update_xg', true)
        );
    }
    
    /**
     * Guardar configuración
     */
    private function save_settings() {
        if (!wp_verify_nonce($_POST['ft_settings_nonce'], 'ft_settings')) {
            return;
        }
        
        $sports = isset($_POST['sports']) ? array_map('sanitize_text_field', $_POST['sports']) : array();
        $auto_update_xg = isset($_POST['auto_update_xg']);
        
        update_option('ft_enabled_sports', $sports);
        update_option('ft_auto_update_xg', $auto_update_xg);
        
        echo '<div class="notice notice-success"><p>' . __('Configuración guardada.', 'football-tipster') . '</p></div>';
    }
    
    /**
     * Cargar scripts del frontend
     */
    public function enqueue_frontend_scripts() {
        wp_enqueue_script('ft-main', FT_PLUGIN_URL . 'assets/js/main.js', array('jquery'), FT_PLUGIN_VERSION, true);
        wp_enqueue_style('ft-styles', FT_PLUGIN_URL . 'assets/css/styles.css', array(), FT_PLUGIN_VERSION);
        
        wp_localize_script('ft-main', 'ft_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ft_nonce'),
            'plugin_url' => FT_PLUGIN_URL
        ));
    }
    
    /**
     * Cargar scripts del admin
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'football-tipster') === false) {
            return;
        }
        
        wp_enqueue_script('ft-admin', FT_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), FT_PLUGIN_VERSION, true);
        wp_enqueue_style('ft-admin-styles', FT_PLUGIN_URL . 'assets/css/admin.css', array(), FT_PLUGIN_VERSION);
        
        wp_localize_script('ft-admin', 'ft_admin_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ft_nonce')
        ));
    }
    
    /**
     * Shortcode básico
     */
    public function render_predictions_shortcode($atts) {
        $atts = shortcode_atts(array(
            'league' => 'all',
            'show_stats' => 'yes',
            'show_form' => 'yes'
        ), $atts);
        
        ob_start();
        ?>
        <div class="ft-predictions-container" data-league="<?php echo esc_attr($atts['league']); ?>">
            <?php if ($atts['show_form'] === 'yes'): ?>
            <div class="ft-prediction-form">
                <select id="ft-home-team" class="ft-team-select">
                    <option value=""><?php _e('Seleccionar equipo local', 'football-tipster'); ?></option>
                </select>
                <span class="ft-vs">VS</span>
                <select id="ft-away-team" class="ft-team-select">
                    <option value=""><?php _e('Seleccionar equipo visitante', 'football-tipster'); ?></option>
                </select>
                <button id="ft-predict-btn" class="ft-button">
                    <?php _e('Predecir Resultado', 'football-tipster'); ?>
                </button>
            </div>
            <?php endif; ?>
            
            <div id="ft-prediction-result"></div>
            
            <?php if ($atts['show_stats'] === 'yes'): ?>
            <div class="ft-recent-predictions">
                <h3><?php _e('Predicciones Recientes', 'football-tipster'); ?></h3>
                <div id="ft-recent-list">
                    <?php echo $this->get_recent_predictions(); ?>
                </div>
            </div>
            <?php endif; ?>
			        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode avanzado
     */
    public function render_predictions_advanced($atts) {
        $atts = shortcode_atts(array(
            'league' => 'all',
            'show_stats' => 'yes',
            'show_form' => 'yes',
            'sport' => 'football'
        ), $atts);
        
        ob_start();
        ?>
        <div class="ft-predictions-container-advanced" data-league="<?php echo esc_attr($atts['league']); ?>" data-sport="<?php echo esc_attr($atts['sport']); ?>">
            <?php if ($atts['show_form'] === 'yes'): ?>
            <div class="ft-prediction-form">
                <select id="ft-league-filter" class="ft-league-select">
                    <option value="all"><?php _e('Todas las ligas', 'football-tipster'); ?></option>
                    <option value="E0">Premier League</option>
                    <option value="SP1">La Liga</option>
                    <option value="I1">Serie A</option>
                    <option value="D1">Bundesliga</option>
                    <option value="F1">Ligue 1</option>
                </select>
                
                <select id="ft-home-team" class="ft-team-select">
                    <option value=""><?php _e('Seleccionar equipo local', 'football-tipster'); ?></option>
                </select>
                
                <span class="ft-vs">VS</span>
                
                <select id="ft-away-team" class="ft-team-select">
                    <option value=""><?php _e('Seleccionar equipo visitante', 'football-tipster'); ?></option>
                </select>
                
                <button id="ft-predict-btn" class="ft-button">
                    <span class="ft-btn-text"><?php _e('Predecir Resultado', 'football-tipster'); ?></span>
                    <span class="ft-btn-loading" style="display:none;"><?php _e('Analizando...', 'football-tipster'); ?></span>
                </button>
            </div>
            <?php endif; ?>
            
            <div id="ft-prediction-result"></div>
            
            <?php if ($atts['show_stats'] === 'yes'): ?>
            <div class="ft-recent-predictions">
                <h3><?php _e('Predicciones Recientes', 'football-tipster'); ?></h3>
                <div id="ft-recent-list">
                    <?php echo $this->get_recent_predictions(15); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Obtener predicciones recientes
     */
    private function get_recent_predictions($limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_predictions';
        
        $predictions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             ORDER BY predicted_at DESC 
             LIMIT %d",
            $limit
        ));
        
        if (empty($predictions)) {
            return '<p>' . __('No hay predicciones recientes.', 'football-tipster') . '</p>';
        }
        
        $html = '<div class="ft-predictions-list">';
        
        foreach ($predictions as $pred) {
            $prediction_text = array(
                'H' => __('Victoria Local', 'football-tipster'),
                'D' => __('Empate', 'football-tipster'), 
                'A' => __('Victoria Visitante', 'football-tipster')
            );
            
            $status_class = '';
            $status_text = __('Pendiente', 'football-tipster');
            
            if ($pred->actual_result) {
                if ($pred->prediction === $pred->actual_result) {
                    $status_class = 'correct';
                    $status_text = '✅ ' . __('Correcto', 'football-tipster');
                } else {
                    $status_class = 'incorrect';
                    $status_text = '❌ ' . __('Incorrecto', 'football-tipster');
                }
            }
            
            $html .= sprintf(
                '<div class="ft-prediction-item %s">
                    <div class="ft-match">
                        <strong>%s vs %s</strong>
                        <span class="ft-date">%s</span>
                    </div>
                    <div class="ft-prediction">
                        <span class="ft-pred-text">%s</span>
                        <span class="ft-confidence">(%.1f%%)</span>
                    </div>
                    <div class="ft-status %s">%s</div>
                </div>',
                $status_class,
                esc_html($pred->home_team),
                esc_html($pred->away_team),
                date_i18n('d/m/Y H:i', strtotime($pred->predicted_at)),
                isset($prediction_text[$pred->prediction]) ? $prediction_text[$pred->prediction] : $pred->prediction,
                $pred->probability * 100,
                $status_class,
                $status_text
            );
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Registrar widgets
     */
    public function register_widgets() {
        register_widget('FT_Prediction_Widget');
    }
    
    /**
     * Registrar rutas API REST
     */
    public function register_api_routes() {
        // Endpoint para predicciones
        register_rest_route('football-tipster/v1', '/predict', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_predict'),
            'permission_callback' => array($this, 'api_permissions'),
            'args' => array(
                'home_team' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'away_team' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'sport' => array(
                    'default' => 'football',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
        
        // Endpoint para obtener equipos
        register_rest_route('football-tipster/v1', '/teams', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_teams'),
            'permission_callback' => array($this, 'api_permissions'),
            'args' => array(
                'sport' => array(
                    'default' => 'football',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'league' => array(
                    'default' => 'all',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
        
        // Endpoint para estadísticas
        register_rest_route('football-tipster/v1', '/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_stats'),
            'permission_callback' => array($this, 'api_permissions'),
        ));
    }
    
    /**
     * API: Hacer predicción
     */
    public function api_predict($request) {
        $home_team = $request->get_param('home_team');
        $away_team = $request->get_param('away_team');
        $sport = $request->get_param('sport');
        
        try {
            $prediction = FT_Predictor::predict_match($home_team, $away_team, $sport);
            
            if (isset($prediction['error'])) {
                return new WP_Error('prediction_error', $prediction['error'], array('status' => 400));
            }
            
            return rest_ensure_response($prediction);
            
        } catch (Exception $e) {
            return new WP_Error('internal_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    /**
     * API: Obtener equipos
     */
    public function api_get_teams($request) {
        $sport = $request->get_param('sport');
        $league = $request->get_param('league');
        
        global $wpdb;
        $table = $wpdb->prefix . 'ft_matches_advanced';
        
        $where_clauses = array("1=1");
        $params = array();
        
        if ($sport !== 'all') {
            $where_clauses[] = "sport = %s";
            $params[] = $sport;
        }
        
        if ($league !== 'all') {
            $where_clauses[] = "division = %s";
            $params[] = $league;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        $sql = "SELECT DISTINCT home_team FROM $table WHERE $where_sql 
                UNION 
                SELECT DISTINCT away_team FROM $table WHERE $where_sql 
                ORDER BY home_team";
        
        if (!empty($params)) {
            $teams = $wpdb->get_col($wpdb->prepare($sql, array_merge($params, $params)));
        } else {
            $teams = $wpdb->get_col($sql);
        }
        
        return rest_ensure_response($teams);
    }
    
    /**
     * API: Obtener estadísticas
     */
    public function api_get_stats($request) {
        global $wpdb;
        
        $stats = array();
        
        // Estadísticas generales
        $stats['total_matches'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ft_matches_advanced");
        $stats['total_predictions'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ft_predictions");
        $stats['correct_predictions'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ft_predictions WHERE is_correct = 1");
        
        // Precisión
        if ($stats['total_predictions'] > 0) {
            $stats['accuracy'] = round(($stats['correct_predictions'] / $stats['total_predictions']) * 100, 2);
        } else {
            $stats['accuracy'] = 0;
        }
        
        // Predicciones por tipo
        $prediction_types = $wpdb->get_results(
            "SELECT prediction, COUNT(*) as count 
             FROM {$wpdb->prefix}ft_predictions 
             GROUP BY prediction"
        );
        
        $stats['predictions_by_type'] = array();
        foreach ($prediction_types as $type) {
            $stats['predictions_by_type'][$type->prediction] = (int) $type->count;
        }
        
        // Modelo info
        $metadata_file = FT_MODELS_PATH . 'model_metadata.json';
        if (file_exists($metadata_file)) {
            $metadata = json_decode(file_get_contents($metadata_file), true);
            if ($metadata) {
                $stats['model_info'] = array(
                    'training_date' => $metadata['training_date'],
                    'features_count' => count($metadata['features']),
                    'model_accuracy' => isset($metadata['performance']['accuracy']) ? $metadata['performance']['accuracy'] : null
                );
            }
        }
        
        return rest_ensure_response($stats);
    }
    
    /**
     * Verificar permisos API
     */
    public function api_permissions($request) {
        // Para endpoints GET públicos, permitir acceso
        if ($request->get_method() === 'GET') {
            return true;
        }
        
        // Para POST, verificar API key
        $api_key = $request->get_header('X-API-Key');
        
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', __('Se requiere API key', 'football-tipster'), array('status' => 401));
        }
        
        $valid_key = get_option('ft_api_key');
        
        if ($api_key !== $valid_key) {
            return new WP_Error('invalid_api_key', __('API key inválida', 'football-tipster'), array('status' => 401));
        }
        
        return true;
    }
    
    /**
     * Reentrenar modelo automáticamente
     */
    public function auto_retrain_model() {
        // Log de inicio
        error_log('Football Tipster: Iniciando reentrenamiento automático');
        
        try {
            // Obtener configuración de base de datos
            $db_config = array(
                'host' => DB_HOST,
                'user' => DB_USER,
                'password' => DB_PASSWORD,
                'database' => DB_NAME
            );
            
            // Crear archivo temporal con configuración
            $config_file = FT_PYTHON_PATH . 'db_config_auto.json';
            file_put_contents($config_file, json_encode($db_config));
            
            // Ejecutar script Python
            $python_script = FT_PYTHON_PATH . 'train_model_advanced.py';
            $command = escapeshellcmd("cd " . FT_PYTHON_PATH . " && /usr/bin/python3.8 $python_script 2>&1");
            
            $output = shell_exec($command);
            
            // Eliminar archivo de configuración temporal
            if (file_exists($config_file)) {
                unlink($config_file);
            }
            
            // Log del resultado
            if ($output) {
                error_log('Football Tipster: Reentrenamiento completado - ' . $output);
            } else {
                error_log('Football Tipster: Error en reentrenamiento - Sin output');
            }
            
        } catch (Exception $e) {
            error_log('Football Tipster: Error en reentrenamiento automático - ' . $e->getMessage());
        }
    }
    
    /**
     * Funciones de utilidad
     */
    
    /**
     * Log personalizado
     */
    public static function log($message, $level = 'info') {
        if (WP_DEBUG) {
            $log_file = FT_PLUGIN_PATH . 'logs/debug.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Obtener versión del modelo
     */
    public static function get_model_version() {
        $metadata_file = FT_MODELS_PATH . 'model_metadata.json';
        
        if (!file_exists($metadata_file)) {
            return null;
        }
        
        $metadata = json_decode(file_get_contents($metadata_file), true);
        return isset($metadata['training_date']) ? $metadata['training_date'] : null;
    }
    
    /**
     * Verificar si el modelo está disponible
     */
    public static function is_model_available($sport = 'football') {
        $model_file = FT_MODELS_PATH . $sport . '_rf_advanced.pkl';
        return file_exists($model_file);
    }
    
    /**
     * Limpiar datos antiguos
     */
    public function cleanup_old_data() {
        global $wpdb;
        
        // Eliminar predicciones antiguas (más de 1 año)
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ft_predictions 
             WHERE predicted_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)"
        );
        
        // Eliminar partidos muy antiguos (más de 5 años)
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ft_matches_advanced 
             WHERE date < DATE_SUB(NOW(), INTERVAL 5 YEAR)"
        );
        
        self::log('Limpieza de datos antiguos completada');
    }
}

// Inicializar el plugin
FootballTipster::get_instance();

// Hook para limpieza periódica
if (!wp_next_scheduled('ft_cleanup_old_data')) {
    wp_schedule_event(time(), 'monthly', 'ft_cleanup_old_data');
}
add_action('ft_cleanup_old_data', array('FootballTipster', 'cleanup_old_data'));

/**
 * Funciones auxiliares globales
 */

/**
 * Obtener instancia del plugin
 */
function ft_get_instance() {
    return FootballTipster::get_instance();
}

/**
 * Verificar si el plugin está configurado correctamente
 */
function ft_is_configured() {
    return FootballTipster::is_model_available() && 
           shell_exec('/usr/bin/python3.8 --version') !== null;
}

/**
 * Obtener equipos disponibles
 */
function ft_get_teams($sport = 'football', $league = 'all') {
    global $wpdb;
    $table = $wpdb->prefix . 'ft_matches_advanced';
    
    $where_clauses = array();
    $params = array();
    
    if ($sport !== 'all') {
        $where_clauses[] = "sport = %s";
        $params[] = $sport;
    }
    
    if ($league !== 'all') {
        $where_clauses[] = "division = %s";
        $params[] = $league;
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    $sql = "SELECT DISTINCT home_team FROM $table $where_sql 
            UNION 
            SELECT DISTINCT away_team FROM $table $where_sql 
            ORDER BY home_team";
    
    if (!empty($params)) {
        return $wpdb->get_col($wpdb->prepare($sql, array_merge($params, $params)));
    } else {
        return $wpdb->get_col($sql);
    }
}

/**
 * Función para hacer predicción directa
 */
function ft_predict($home_team, $away_team, $sport = 'football') {
    return FT_Predictor::predict_match($home_team, $away_team, $sport);
}

/**
 * Hook de desinstalación
 */
register_uninstall_hook(__FILE__, 'ft_uninstall');

function ft_uninstall() {
    global $wpdb;
    
    // Eliminar tablas
    $tables = array(
        $wpdb->prefix . 'ft_matches_advanced',
        $wpdb->prefix . 'ft_team_stats_advanced', 
        $wpdb->prefix . 'ft_predictions',
        $wpdb->prefix . 'ft_config'
    );
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }
    
    // Eliminar opciones
    delete_option('ft_api_key');
    delete_option('ft_enabled_sports');
    delete_option('ft_auto_update_xg');
    
    // Limpiar tareas cron
    wp_clear_scheduled_hook('ft_update_xg_daily');
    wp_clear_scheduled_hook('ft_retrain_model_weekly');
    wp_clear_scheduled_hook('ft_update_stats');
    wp_clear_scheduled_hook('ft_cleanup_old_data');
}
// Cargar diagnósticos
require_once FT_PLUGIN_PATH . 'includes/class-diagnostics.php';

// Agregar página de diagnóstico al menú admin
add_action('admin_menu', function() {
    add_submenu_page(
        'football-tipster',
        'Diagnóstico',
        'Diagnóstico',
        'manage_options',
        'football-tipster-diagnosis',
        function() {
            ?>
            <div class="wrap">
                <h1>Football Tipster - Diagnóstico del Sistema</h1>
                
                <div class="ft-diagnosis-container">
                    <?php
                    $diagnosis = FT_Diagnostics::run_full_diagnosis();
                    ?>
                    
                    <!-- Base de Datos -->
                    <div class="ft-section">
                        <h2>🗄️ Base de Datos</h2>
                        <table class="widefat">
                            <tr>
                                <td><strong>Conexión:</strong></td>
                                <td><?php echo $diagnosis['database']['connection'] === 'OK' ? '✅' : '❌'; ?> <?php echo $diagnosis['database']['connection']; ?></td>
                            </tr>
                            <?php foreach ($diagnosis['database']['tables'] as $table => $status): ?>
                            <tr>
                                <td><strong>Tabla <?php echo $table; ?>:</strong></td>
                                <td>
                                    <?php echo $status === 'OK' ? '✅' : '❌'; ?> <?php echo $status; ?>
                                    <?php if ($status === 'OK' && isset($diagnosis['database']['table_counts'][$table])): ?>
                                        (<?php echo number_format($diagnosis['database']['table_counts'][$table]); ?> registros)
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        
                        <?php if ($diagnosis['database']['connection'] !== 'OK'): ?>
                        <div class="ft-error">
                            <strong>⚠️ Error de conexión a base de datos</strong><br/>
                            Configuración actual:<br/>
                            - Host: <?php echo DB_HOST; ?><br/>
                            - Usuario: <?php echo DB_USER; ?><br/>
                            - Base de datos: <?php echo DB_NAME; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Permisos -->
                    <div class="ft-section">
                        <h2>🔐 Permisos de Archivos</h2>
                        <table class="widefat">
                            <?php foreach ($diagnosis['permissions'] as $dir => $info): ?>
                            <tr>
                                <td><strong><?php echo $dir; ?>:</strong></td>
                                <td>
                                    <?php if (is_array($info)): ?>
                                        <?php echo $info['readable'] && $info['writable'] ? '✅' : '❌'; ?>
                                        Lectura: <?php echo $info['readable'] ? 'Sí' : 'No'; ?> | 
                                        Escritura: <?php echo $info['writable'] ? 'Sí' : 'No'; ?> | 
                                        Permisos: <?php echo $info['permissions']; ?>
                                    <?php else: ?>
                                        ❌ <?php echo $info; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    
                    <!-- Python -->
                    <div class="ft-section">
                        <h2>🐍 Python</h2>
                        <table class="widefat">
                            <tr>
                                <td><strong>Python:</strong></td>
                                <td><?php echo strpos($diagnosis['python']['python_version'], 'Python 3') !== false ? '✅' : '❌'; ?> <?php echo $diagnosis['python']['python_version']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Pip:</strong></td>
                                <td><?php echo strpos($diagnosis['python']['pip_version'], 'pip') !== false ? '✅' : '❌'; ?> <?php echo $diagnosis['python']['pip_version']; ?></td>
                            </tr>
                            <?php foreach ($diagnosis['python']['libraries'] as $lib => $version): ?>
                            <tr>
                                <td><strong><?php echo $lib; ?>:</strong></td>
                                <td><?php echo $version !== 'NOT_INSTALLED' ? '✅' : '❌'; ?> <?php echo $version; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    
                    <!-- Test CSV -->
                    <div class="ft-section">
                        <h2>📊 Test Importación CSV</h2>
                        <button id="ft-test-csv" class="button button-primary">Ejecutar Test CSV</button>
                        <div id="ft-test-csv-result"></div>
                    </div>
                    
                    <!-- Acciones correctivas -->
                    <div class="ft-section">
                        <h2>🔧 Acciones Correctivas</h2>
                        <button id="ft-recreate-tables" class="button">Recrear Tablas</button>
                        <button id="ft-fix-permissions" class="button">Corregir Permisos</button>
                        <div id="ft-actions-result"></div>
                    </div>
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    $('#ft-test-csv').click(function() {
                        $(this).prop('disabled', true).text('Ejecutando...');
                        $.post(ajaxurl, {
                            action: 'ft_test_csv_import',
                            nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
                        }, function(response) {
                            $('#ft-test-csv-result').html('<pre>' + JSON.stringify(response, null, 2) + '</pre>');
                            $('#ft-test-csv').prop('disabled', false).text('Ejecutar Test CSV');
                        });
                    });
                    
                    $('#ft-recreate-tables').click(function() {
                        if (confirm('¿Estás seguro? Esto recreará todas las tablas.')) {
                            $(this).prop('disabled', true).text('Recreando...');
                            $.post(ajaxurl, {
                                action: 'ft_recreate_tables',
                                nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
                            }, function(response) {
                                $('#ft-actions-result').html('<div class="notice notice-' + (response.success ? 'success' : 'error') + '"><p>' + response.data + '</p></div>');
                                $('#ft-recreate-tables').prop('disabled', false).text('Recrear Tablas');
                                if (response.success) {
                                    location.reload();
                                }
                            });
                        }
                    });
                });
                </script>
            </div>
            <?php
        }
    );
});

// AJAX handlers para diagnóstico
add_action('wp_ajax_ft_test_csv_import', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_die('Permisos insuficientes');
    }
    
    $result = FT_Diagnostics::test_csv_import();
    wp_send_json($result);
});

add_action('wp_ajax_ft_recreate_tables', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_die('Permisos insuficientes');
    }
    
    try {
        $plugin = FootballTipster::get_instance();
        // Usar reflexión para acceder al método privado
        $reflection = new ReflectionClass($plugin);
        $method = $reflection->getMethod('create_tables');
        $method->setAccessible(true);
        $method->invoke($plugin);
        
        wp_send_json_success('Tablas recreadas exitosamente');
    } catch (Exception $e) {
        wp_send_json_error('Error al recrear tablas: ' . $e->getMessage());
    }
});

// AJAX handler para test CSV
add_action('wp_ajax_ft_test_csv_import', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        $result = FT_Diagnostics::test_csv_import();
        wp_send_json($result);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

// Handler AJAX súper simple
add_action('wp_ajax_ft_debug', function() {
    status_header(200);
    header('Content-Type: text/plain');
    echo 'AJAX funcionando - ' . current_time('mysql');
    wp_die();
});

add_action('wp_ajax_ft_test_simple', function() {
    status_header(200);
    header('Content-Type: application/json');
    
    $result = array(
        'success' => true,
        'message' => 'Test exitoso',
        'time' => current_time('mysql')
    );
    
    echo json_encode($result);
    wp_die();
});
// AJAX handler para análisis de valor
add_action('wp_ajax_ft_analyze_value_bets', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        $analyzer = new FT_Value_Analyzer();
        $result = $analyzer->analyze_all_fixtures(50);
        wp_send_json_success($result);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

// AJAX handler para obtener top value bets
add_action('wp_ajax_ft_get_value_bets', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    
    try {
        $analyzer = new FT_Value_Analyzer();
        $value_bets = $analyzer->get_top_value_bets(20);
        wp_send_json_success($value_bets);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

// Handler público para value bets (sin nonce para API)
add_action('wp_ajax_nopriv_ft_get_value_bets', function() {
    try {
        $analyzer = new FT_Value_Analyzer();
        $value_bets = $analyzer->get_top_value_bets(10);
        wp_send_json_success($value_bets);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});
// AJAX handler para value bets admin
add_action('wp_ajax_ft_get_value_bets_admin', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        global $wpdb;
        
        $market_filter = sanitize_text_field($_POST['market_filter'] ?? 'all');
        $min_value = floatval($_POST['min_value'] ?? 0);
        
        $where_clauses = array("f.start_time > NOW()", "vb.status = 'active'");
        $params = array();
        
        if ($market_filter !== 'all') {
            $where_clauses[] = "vb.market_type = %s";
            $params[] = $market_filter;
        }
        
        if ($min_value > 0) {
            $where_clauses[] = "vb.value_percentage >= %f";
            $params[] = $min_value;
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
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
            WHERE $where_sql
            ORDER BY vb.value_percentage DESC, vb.confidence_score DESC
            LIMIT 50
        ";
        
        if (!empty($params)) {
            $value_bets = $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            $value_bets = $wpdb->get_results($sql);
        }
        
        wp_send_json_success($value_bets);
        
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

// AJAX handler para próximos partidos
add_action('wp_ajax_ft_get_upcoming_fixtures', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        global $wpdb;
        
        $fixtures = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ft_fixtures 
             WHERE start_time > NOW() 
             AND start_time < DATE_ADD(NOW(), INTERVAL 7 DAY)
             ORDER BY start_time ASC 
             LIMIT 20"
        );
        
        wp_send_json_success($fixtures);
        
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

// AJAX handler para sincronizar datos de Pinnacle
add_action('wp_ajax_ft_sync_pinnacle_data', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        // Verificar que tenemos credenciales de Pinnacle
        $username = get_option('ft_pinnacle_username');
        $password = get_option('ft_pinnacle_password');
        
        if (!$username || !$password) {
            wp_send_json_error('Faltan credenciales de Pinnacle. Ve a Configuración para agregarlas.');
        }
        
        $api = new FT_Pinnacle_API($username, $password);
        
        // Sincronizar fixtures
        $fixtures_synced = $api->sync_fixtures();
        
        // Sincronizar odds
        $odds_synced = $api->sync_odds();
        
        wp_send_json_success(array(
            'message' => "Sincronización completada: $fixtures_synced fixtures y $odds_synced odds actualizados",
            'fixtures_synced' => $fixtures_synced,
            'odds_synced' => $odds_synced
        ));
        
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});
// AJAX handler para test de conexión Pinnacle
add_action('wp_ajax_ft_test_pinnacle_connection', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        $username = get_option('ft_pinnacle_username');
        $password = get_option('ft_pinnacle_password');
        
        if (!$username || !$password) {
            wp_send_json_error('Faltan credenciales de Pinnacle');
        }
        
        $api = new FT_Pinnacle_API($username, $password);
        $result = $api->test_connection();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
        
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});

// AJAX handler para sincronización manual
// AJAX handler para sincronización manual (continuación)
add_action('wp_ajax_ft_manual_sync', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    $sync_type = sanitize_text_field($_POST['sync_type']);
    
    try {
        $start_time = microtime(true);
        
        $username = get_option('ft_pinnacle_username');
        $password = get_option('ft_pinnacle_password');
        
        if (!$username || !$password) {
            wp_send_json_error('Faltan credenciales de Pinnacle');
        }
        
        $api = new FT_Pinnacle_API($username, $password);
        $leagues = get_option('ft_pinnacle_leagues');
        $league_ids = !empty($leagues) ? explode(',', $leagues) : null;
        
        $message = '';
        $operation = '';
        
        switch ($sync_type) {
            case 'leagues':
                $operation = 'sync_leagues';
                $leagues_data = $api->get_leagues();
                $message = 'Sincronizadas ' . count($leagues_data) . ' ligas';
                break;
                
            case 'fixtures':
                $operation = 'sync_fixtures';
                $synced = $api->sync_fixtures($league_ids);
                $message = "Sincronizados $synced partidos";
                break;
                
            case 'odds':
                $operation = 'sync_odds';
                $synced = $api->sync_odds($league_ids);
                $message = "Sincronizadas $synced cuotas";
                break;
                
            case 'full':
                $operation = 'full_sync';
                
                // Sincronizar fixtures
                $fixtures_synced = $api->sync_fixtures($league_ids);
                
                // Sincronizar odds
                $odds_synced = $api->sync_odds($league_ids);
                
                // Analizar value bets si está habilitado
                $value_bets_found = 0;
                if (get_option('ft_auto_analyze')) {
                    $analyzer = new FT_Value_Analyzer();
                    $analysis = $analyzer->analyze_all_fixtures(100);
                    $value_bets_found = $analysis['value_bets_found'];
                }
                
                $message = "Sincronización completa: $fixtures_synced partidos, $odds_synced cuotas";
                if ($value_bets_found > 0) {
                    $message .= ", $value_bets_found value bets encontrados";
                }
                break;
                
            default:
                wp_send_json_error('Tipo de sincronización no válido');
                return;
        }
        
        $duration = round(microtime(true) - $start_time, 2);
        
        // Guardar log
        ft_log_sync_operation($operation, 'success', $message, $duration);
        
        // Actualizar última sincronización
        update_option('ft_last_sync_time', current_time('mysql'));
        
        wp_send_json_success($message);
        
    } catch (Exception $e) {
        $duration = round(microtime(true) - $start_time, 2);
        ft_log_sync_operation($operation ?? 'unknown', 'error', $e->getMessage(), $duration);
        wp_send_json_error($e->getMessage());
    }
});

// AJAX handler para limpiar logs
add_action('wp_ajax_ft_clear_sync_logs', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'ft_sync_logs';
    $wpdb->query("TRUNCATE TABLE $table");
    
    wp_send_json_success('Logs limpiados');
});

// Hook para sincronización automática
add_action('ft_auto_sync', function() {
    try {
        $username = get_option('ft_pinnacle_username');
        $password = get_option('ft_pinnacle_password');
        
        if (!$username || !$password) {
            ft_log_sync_operation('auto_sync', 'error', 'Faltan credenciales de Pinnacle');
            return;
        }
        
        $start_time = microtime(true);
        
        $api = new FT_Pinnacle_API($username, $password);
        $leagues = get_option('ft_pinnacle_leagues');
        $league_ids = !empty($leagues) ? explode(',', $leagues) : null;
        
        // Sincronizar fixtures y odds
        $fixtures_synced = $api->sync_fixtures($league_ids);
        $odds_synced = $api->sync_odds($league_ids);
        
        // Analizar value bets automáticamente si está habilitado
        $value_bets_found = 0;
        if (get_option('ft_auto_analyze')) {
            $analyzer = new FT_Value_Analyzer();
            $analysis = $analyzer->analyze_all_fixtures(50);
            $value_bets_found = $analysis['value_bets_found'];
        }
        
        $duration = round(microtime(true) - $start_time, 2);
        $message = "Auto-sync: $fixtures_synced partidos, $odds_synced cuotas";
        if ($value_bets_found > 0) {
            $message .= ", $value_bets_found value bets";
        }
        
        ft_log_sync_operation('auto_sync', 'success', $message, $duration);
        update_option('ft_last_sync_time', current_time('mysql'));
        
    } catch (Exception $e) {
        $duration = round(microtime(true) - $start_time, 2);
        ft_log_sync_operation('auto_sync', 'error', $e->getMessage(), $duration);
    }
});
/**
 * Función helper para registrar operaciones de sincronización
 */
function ft_log_sync_operation($operation, $status, $message, $duration = null) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'ft_sync_logs';
    
    // Crear tabla si no existe
    $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
        id int(11) NOT NULL AUTO_INCREMENT,
        operation varchar(50) NOT NULL,
        status varchar(20) NOT NULL,
        message text,
        duration decimal(8,2) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_created (created_at)
    )");
    
    $wpdb->insert($table, array(
        'operation' => $operation,
        'status' => $status,
        'message' => $message,
        'duration' => $duration,
        'created_at' => current_time('mysql')
    ));
    
    // Mantener solo los últimos 100 logs
    $wpdb->query("DELETE FROM $table WHERE id NOT IN (SELECT * FROM (SELECT id FROM $table ORDER BY created_at DESC LIMIT 100) t)");
}
// AJAX handler para importación CSV (VERIFICAR QUE EXISTE)

// AJAX handler para importación CSV desde URL
add_action('wp_ajax_ft_import_csv_url', function() {
    error_log('FT: Iniciando import desde URL');
    
    try {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'ft_nonce')) {
            wp_send_json_error('Error de seguridad');
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos suficientes');
            return;
        }
        
        // Obtener URL
        $csv_url = sanitize_url($_POST['csv_url']);
        if (empty($csv_url)) {
            wp_send_json_error('URL no válida');
            return;
        }
        
        error_log('FT: URL recibida: ' . $csv_url);
        
        // Validar que sea una URL válida
        if (!filter_var($csv_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error('Formato de URL no válido');
            return;
        }
        
        // Verificar que la clase existe
        if (!class_exists('FT_CSV_Importer')) {
            wp_send_json_error('Error: Clase importador no encontrada');
            return;
        }
        
        error_log('FT: Iniciando descarga desde URL');
        
        // Procesar CSV desde URL
        $importer = new FT_CSV_Importer($csv_url);
        $result = $importer->import_from_url($csv_url);
        
        error_log('FT: Resultado importación URL: ' . print_r($result, true));
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['error']);
        }
        
    } catch (Exception $e) {
        error_log('FT: Excepción en import URL: ' . $e->getMessage());
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});

// AJAX handler para entrenar modelo
// AJAX handler para entrenar modelo - VERSIÓN MEJORADA
add_action('wp_ajax_ft_train_model', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        error_log('FT: Iniciando entrenamiento de modelo');
        
        // Configurar límites
        ini_set('memory_limit', '1G');
        ini_set('max_execution_time', 600);
        
        // Crear configuración de BD
        $db_config = array(
            'host' => DB_HOST,
            'user' => DB_USER,
            'password' => DB_PASSWORD,
            'database' => DB_NAME,
	    	'port' => 3306,
			'table_prefix' => $wpdb->prefix


        );
        if (strpos(DB_HOST, ':') !== false) {
    list($db_host, $port) = explode(':', DB_HOST, 2);
    if (is_numeric($port)) {
        $db_port = (int) $port;
    }
}

        $config_file = FT_PYTHON_PATH . 'db_config_temp.json';
		 error_log($config_file);
            if (!file_put_contents($config_file, json_encode($db_config))) {
        error_log("❌ No se pudo crear $config_file");
        wp_send_json_error("No se pudo crear archivo de configuración para Python");
    }
       
        // Usar script corregido
        $python_script = FT_PYTHON_PATH . 'train_model_fixed.py';
        $command = "cd " . FT_PYTHON_PATH . " && /usr/bin/python3.8 " . $python_script . " 2>&1";
        
        error_log('FT: Comando: ' . $command);
        
        $output = shell_exec($command);
        
        // Limpiar
        if (file_exists($config_file)) {
            unlink($config_file);
        }
        
        error_log('FT: Output: ' . $output);
        
        if (strpos($output, '🎉 Entrenamiento completado') !== false) {
            preg_match('/Precisión final: ([\d.]+)%/', $output, $matches);
            $accuracy = isset($matches[1]) ? floatval($matches[1]) / 100 : 0;
            
            wp_send_json_success(array(
                'message' => 'Modelo entrenado exitosamente',
                'accuracy' => $accuracy,
                'output' => nl2br($output)
            ));
        } else {
            wp_send_json_error('Error: ' . $output);
        }
        
    } catch (Exception $e) {
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});


/**
 * Calculadora de Expected Goals (xG) para Football Tipster
 * Basada en datos estadísticos de Football-Data.co.uk
 */

class FootballTipster_xG_Calculator {
    
    /**
     * Calcula xG para un equipo basado en estadísticas del partido
     * 
     * @param array $stats Estadísticas del partido
     * @return float xG calculado
     */
    public function calculate_xG($stats) {
        // Inicializar xG base
        $xG = 0;
        
        // 1. xG base por tiros
        $shots = isset($stats['shots']) ? (int)$stats['shots'] : 0;
        $shots_on_target = isset($stats['shots_on_target']) ? (int)$stats['shots_on_target'] : 0;
        $woodwork = isset($stats['woodwork']) ? (int)$stats['woodwork'] : 0;
        
        // Calidad de tiro base
        if ($shots > 0) {
            $shot_quality = ($shots_on_target + $woodwork) / $shots;
        } else {
            $shot_quality = 0;
        }
        
        // xG base por tiros (valor empírico ajustado)
        $xG += $shots * 0.08; // Cada tiro tiene valor base de 0.08 xG
        
        // 2. Bonus por tiros a puerta
        $xG += $shots_on_target * 0.12; // Tiros a puerta valen más
        
        // 3. Bonus por tiros al palo (casi gol)
        $xG += $woodwork * 0.4; // Tiros al palo tienen alta probabilidad
        
        // 4. Ajuste por corners (presión ofensiva)
        $corners = isset($stats['corners']) ? (int)$stats['corners'] : 0;
        $xG += $corners * 0.03; // Cada corner añade pequeño valor
        
        // 5. Ajuste por faltas cometidas por rival (presión en área)
        $rival_fouls = isset($stats['rival_fouls']) ? (int)$stats['rival_fouls'] : 0;
        if ($rival_fouls > 15) { // Muchas faltas = presión
            $xG += ($rival_fouls - 15) * 0.02;
        }
        
        // 6. Ajuste por eficiencia ofensiva
        if ($shots > 0) {
            $efficiency = $shots_on_target / $shots;
            if ($efficiency > 0.4) { // Muy eficiente
                $xG *= 1.1;
            } elseif ($efficiency < 0.2) { // Poco eficiente
                $xG *= 0.9;
            }
        }
        
        // 7. Ajuste por dominio territorial (corners como proxy)
        if ($corners > 8) { // Muchos corners = dominio
            $xG *= 1.05;
        }
        
        // 8. Límites realistas
        $xG = max(0, $xG); // No puede ser negativo
        $xG = min(5, $xG); // Máximo realista de 5 xG
        
        return round($xG, 2);
    }
    
    /**
     * Calcula xG para ambos equipos de un partido
     * 
     * @param array $match_data Datos del partido de Football-Data
     * @return array ['home_xG' => float, 'away_xG' => float]
     */
    public function calculate_match_xG($match_data) {
        // Preparar datos del equipo local
        $home_stats = array(
            'shots' => isset($match_data['hs']) ? $match_data['hs'] : (isset($match_data['HS']) ? $match_data['HS'] : 0),
            'shots_on_target' => isset($match_data['hst']) ? $match_data['hst'] : (isset($match_data['HST']) ? $match_data['HST'] : 0),
            'woodwork' => isset($match_data['hhw']) ? $match_data['hhw'] : (isset($match_data['HHW']) ? $match_data['HHW'] : 0),
            'corners' => isset($match_data['hc']) ? $match_data['hc'] : (isset($match_data['HC']) ? $match_data['HC'] : 0),
            'rival_fouls' => isset($match_data['af']) ? $match_data['af'] : (isset($match_data['AF']) ? $match_data['AF'] : 0),
        );
        
        // Preparar datos del equipo visitante
        $away_stats = array(
            'shots' => isset($match_data['as_shots']) ? $match_data['as_shots'] : (isset($match_data['AS']) ? $match_data['AS'] : 0),
            'shots_on_target' => isset($match_data['ast']) ? $match_data['ast'] : (isset($match_data['AST']) ? $match_data['AST'] : 0),
            'woodwork' => isset($match_data['ahw']) ? $match_data['ahw'] : (isset($match_data['AHW']) ? $match_data['AHW'] : 0),
            'corners' => isset($match_data['ac']) ? $match_data['ac'] : (isset($match_data['AC']) ? $match_data['AC'] : 0),
            'rival_fouls' => isset($match_data['hf']) ? $match_data['hf'] : (isset($match_data['HF']) ? $match_data['HF'] : 0),
        );
        
        return array(
            'home_xG' => $this->calculate_xG($home_stats),
            'away_xG' => $this->calculate_xG($away_stats)
        );
    }
    
    /**
     * Actualiza xG para todos los partidos sin xG en la base de datos
     */
    public function update_missing_xG() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ft_matches_advanced';
        
        // Obtener partidos sin xG
        $matches = $wpdb->get_results("
            SELECT * FROM $table_name 
            WHERE (home_xG IS NULL OR away_xG IS NULL) 
            AND HS IS NOT NULL 
            AND as_shots IS NOT NULL
            LIMIT 100
        ");
        
        $updated = 0;
        
        foreach ($matches as $match) {
            $match_array = (array) $match;
            $xG_data = $this->calculate_match_xG($match_array);
            
            $wpdb->update(
                $table_name,
                array(
                    'home_xG' => $xG_data['home_xG'],
                    'away_xG' => $xG_data['away_xG']
                ),
                array('id' => $match->id)
            );
            
            $updated++;
        }
        
        return $updated;
    }
    
    /**
     * Calcula precisión del modelo xG comparando con goles reales
     */
    public function calculate_xG_accuracy() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ft_matches_advanced';
        
        $matches = $wpdb->get_results("
            SELECT FTHG, FTAG, home_xG, away_xG 
            FROM $table_name 
            WHERE home_xG IS NOT NULL 
            AND away_xG IS NOT NULL 
            AND FTHG IS NOT NULL 
            AND FTAG IS NOT NULL
            LIMIT 1000
        ");
        
        $total_error = 0;
        $count = 0;
        
        foreach ($matches as $match) {
            $home_error = abs($match->FTHG - $match->home_xG);
            $away_error = abs($match->FTAG - $match->away_xG);
            
            $total_error += $home_error + $away_error;
            $count += 2; // Contamos local y visitante
        }
        
        if ($count > 0) {
            $avg_error = $total_error / $count;
            $accuracy = max(0, 100 - ($avg_error * 50)); // Convertir a porcentaje
            return round($accuracy, 1);
        }
        
        return 0;
    }
    
    /**
     * Obtiene estadísticas del modelo xG
     */
    public function get_xG_stats() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ft_matches_advanced';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_matches,
                SUM(CASE WHEN home_xG IS NOT NULL AND away_xG IS NOT NULL THEN 1 ELSE 0 END) as matches_with_xG,
                AVG(home_xG) as avg_home_xG,
                AVG(away_xG) as avg_away_xG
            FROM $table_name
        ");
        
        return array(
            'total_matches' => $stats->total_matches,
            'matches_with_xG' => $stats->matches_with_xG,
            'coverage' => $stats->total_matches > 0 ? round(($stats->matches_with_xG / $stats->total_matches) * 100, 1) : 0,
            'avg_home_xG' => round($stats->avg_home_xG, 2),
            'avg_away_xG' => round($stats->avg_away_xG, 2),
            'accuracy' => $this->calculate_xG_accuracy()
        );
    }
}

// Función para usar en el plugin
function ft_calculate_xG_for_match($match_data) {
    if (!class_exists('FootballTipster_xG_Calculator')) {
        require_once FT_PLUGIN_PATH . 'includes/class-xg-calculator.php';
    }
    $calculator = new FootballTipster_xG_Calculator();
    return $calculator->calculate_match_xG($match_data);
}

// Función para actualizar xG masivamente
function ft_update_all_xG() {
    if (!class_exists('FootballTipster_xG_Calculator')) {
        require_once FT_PLUGIN_PATH . 'includes/class-xg-calculator.php';
    }
    $calculator = new FootballTipster_xG_Calculator();
    return $calculator->update_missing_xG();
}
// Función para obtener estadísticas del xG
function ft_get_xG_statistics() {
    if (!class_exists('FootballTipster_xG_Calculator')) {
        require_once FT_PLUGIN_PATH . 'includes/class-xg-calculator.php';
    }
    $calculator = new FootballTipster_xG_Calculator();
    return $calculator->get_xG_stats();
}
/**
 * Handlers AJAX para el sistema de xG propio
 * AÑADIR ESTE CÓDIGO AL FINAL DE football-tipster.php
 */

// AJAX handler para actualizar xG de partidos existentes
add_action('wp_ajax_ft_update_xg_bulk', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        error_log('FT: Iniciando actualización masiva de xG');
        
        // Cargar la clase si no está cargada
        if (!class_exists('FootballTipster_xG_Calculator')) {
            require_once FT_PLUGIN_PATH . 'includes/class-xg-calculator.php';
        }
        
        $calculator = new FootballTipster_xG_Calculator();
        $updated = $calculator->update_missing_xG();
        
        error_log('FT: xG actualizado para ' . $updated . ' partidos');
        
        wp_send_json_success(array(
            'message' => sprintf(__('%d partidos actualizados con xG calculado', 'football-tipster'), $updated),
            'updated' => $updated
        ));
        
    } catch (Exception $e) {
        error_log('FT: Error en actualización xG: ' . $e->getMessage());
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});

// AJAX handler para obtener estadísticas de xG
add_action('wp_ajax_ft_get_xg_stats', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        if (!class_exists('FootballTipster_xG_Calculator')) {
            require_once FT_PLUGIN_PATH . 'includes/class-xg-calculator.php';
        }
        
        $calculator = new FootballTipster_xG_Calculator();
        $stats = $calculator->get_xG_stats();
        
        wp_send_json_success($stats);
        
    } catch (Exception $e) {
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});

// AJAX handler para recalcular xG específico
add_action('wp_ajax_ft_recalculate_xg', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    $match_id = intval($_POST['match_id']);
    if (!$match_id) {
        wp_send_json_error('ID de partido no válido');
    }
    
    try {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_matches_advanced';
        
        // Obtener datos del partido
        $match = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $match_id
        ), ARRAY_A);
        
        if (!$match) {
            wp_send_json_error('Partido no encontrado');
        }
        
        if (!class_exists('FootballTipster_xG_Calculator')) {
            require_once FT_PLUGIN_PATH . 'includes/class-xg-calculator.php';
        }
        
        $calculator = new FootballTipster_xG_Calculator();
        $xg_data = $calculator->calculate_match_xG($match);
        
        // Actualizar en base de datos
        $wpdb->update(
            $table,
            array(
                'home_xg' => $xg_data['home_xG'],
                'away_xg' => $xg_data['away_xG']
            ),
            array('id' => $match_id)
        );
        
        wp_send_json_success(array(
            'message' => 'xG recalculado exitosamente',
            'home_xg' => $xg_data['home_xG'],
            'away_xg' => $xg_data['away_xG']
        ));
        
    } catch (Exception $e) {
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});

// Modificar el AJAX handler de importación CSV existente para mostrar estadísticas de xG
add_action('wp_ajax_ft_import_csv', function() {
    error_log('FT: Iniciando AJAX import CSV con xG');
    
    try {
        if (!wp_verify_nonce($_POST['nonce'], 'ft_nonce')) {
            wp_send_json_error('Error de seguridad');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos suficientes');
            return;
        }
        
        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error('No se recibió ningún archivo');
            return;
        }
        
        $uploaded_file = $_FILES['csv_file'];
        
        $file_type = wp_check_filetype($uploaded_file['name']);
        if ($file_type['ext'] !== 'csv') {
            wp_send_json_error('Solo se permiten archivos CSV');
            return;
        }
        
        // Usar el nuevo importador con xG
        if (!class_exists('FT_CSV_Importer')) {
            require_once FT_PLUGIN_PATH . 'includes/class-csv-importer.php';
        }
        
        $importer = new FT_CSV_Importer();
        $result = $importer->import_from_file($uploaded_file['tmp_name']);
        
        if ($result['success']) {
            // Añadir estadísticas de xG al mensaje
            $message = $result['message'];
            if (isset($result['xg_calculated'])) {
                $message .= ' (' . $result['xg_calculated'] . ' con xG calculado automáticamente)';
            }
            
            wp_send_json_success($message);
        } else {
            wp_send_json_error($result['error']);
        }
        
    } catch (Exception $e) {
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});

// Modificar el AJAX handler de importación desde URL
add_action('wp_ajax_ft_import_csv_url', function() {
    error_log('FT: Iniciando import desde URL con xG');
    
    try {
        if (!wp_verify_nonce($_POST['nonce'], 'ft_nonce')) {
            wp_send_json_error('Error de seguridad');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos suficientes');
            return;
        }
        
        $csv_url = sanitize_url($_POST['csv_url']);
        if (empty($csv_url)) {
            wp_send_json_error('URL no válida');
            return;
        }
        
        if (!filter_var($csv_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error('Formato de URL no válido');
            return;
        }
        
        // Usar el nuevo importador con xG
        if (!class_exists('FT_CSV_Importer')) {
            require_once FT_PLUGIN_PATH . 'includes/class-csv-importer.php';
        }
        
        $importer = new FT_CSV_Importer();
        $result = $importer->import_from_url($csv_url);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['error']);
        }
        
    } catch (Exception $e) {
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});

add_action('wp_ajax_ft_get_pinnacle_leagues', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No tienes permisos.');
    }

    require_once FT_PLUGIN_PATH . 'includes/class-pinnacle-api.php';
    $api = new FT_Pinnacle_API();
    $leagues = $api->get_leagues();

    // Devuelve: [ [id => ..., name => ...], ... ]
    $out = [];
    foreach ($leagues as $l) {
        $out[] = [
            'id' => $l['id'],
            'name' => $l['name']
        ];
    }

    wp_send_json_success($out);
});

add_action('wp_ajax_ft_run_benchmark', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    $season = sanitize_text_field($_POST['season']);
    $model_type = sanitize_text_field($_POST['model_type']);
    $league = sanitize_text_field($_POST['league'] ?? 'all');  // CORREGIDO: Ya estaba bien

    error_log('FT Benchmark: Iniciando para temporada ' . $season . ' liga ' . $league);
    
    if (!$season || !in_array($model_type, ['with_xg', 'without_xg'])) {
        wp_send_json_error('Parámetros inválidos');
    }
    
    try {
        // Aumentar límites para el benchmark
        ini_set('memory_limit', '1G');
        ini_set('max_execution_time', 600);
        
        if (!class_exists('FT_Benchmarking')) {
            require_once FT_PLUGIN_PATH . 'includes/class-benchmarking.php';
        }
        
        $benchmarking = new FT_Benchmarking();
        
        // CORRECCIÓN: Pasar los 3 parámetros correctamente
        $result = $benchmarking->run_season_benchmark($season, $model_type, $league);
      
        if (isset($result['error'])) {
            wp_send_json_error($result['error']);
        } else {
            wp_send_json_success($result);
        }
        
    } catch (Exception $e) {
        error_log('FT Benchmark Error: ' . $e->getMessage());
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});
// AJAX handler para obtener detalles de benchmark

add_action('wp_ajax_ft_get_benchmark_details', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    $benchmark_id = intval($_POST['benchmark_id']);
    
    try {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_benchmarks';
        
        $benchmark = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $benchmark_id
        ));
        
        if (!$benchmark) {
            wp_send_json_error('Benchmark no encontrado');
        }
        
        $metadata = json_decode($benchmark->metadata, true);
        
        wp_send_json_success(array(
            'benchmark' => $benchmark,
            'metadata' => $metadata,
            'betting_details' => isset($metadata['betting_details']) ? $metadata['betting_details'] : array()
        ));
        
    } catch (Exception $e) {
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});

// AJAX handler para limpiar benchmarks antiguos
add_action('wp_ajax_ft_clear_benchmarks', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_benchmarks';
        
        $deleted = $wpdb->query("DELETE FROM $table WHERE test_date < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        
        wp_send_json_success(array(
            'message' => "Eliminados $deleted benchmarks antiguos (más de 30 días)"
        ));
        
    } catch (Exception $e) {
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});

// AJAX handler para comparar modelos
add_action('wp_ajax_ft_compare_models', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    try {
        global $wpdb;
        $table = $wpdb->prefix . 'ft_benchmarks';
        
        // Obtener últimos benchmarks de cada tipo
        $with_xg = $wpdb->get_row(
            "SELECT * FROM $table WHERE model_type = 'with_xg' ORDER BY test_date DESC LIMIT 1"
        );
        
        $without_xg = $wpdb->get_row(
            "SELECT * FROM $table WHERE model_type = 'without_xg' ORDER BY test_date DESC LIMIT 1"
        );
        
        $comparison = array(
            'with_xg' => $with_xg,
            'without_xg' => $without_xg
        );
        
        wp_send_json_success($comparison);
        
    } catch (Exception $e) {
        wp_send_json_error('Error: ' . $e->getMessage());
    }
});
// Añadir en football-tipster.php después de los otros AJAX handlers
add_action('wp_ajax_ft_get_pinnacle_leagues', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No tienes permisos.');
    }

    $api = new FT_Pinnacle_API();
    $leagues = $api->get_leagues();
    
    wp_send_json_success($leagues);
});




/**
 * Entrenamiento paso a paso con debugging
 * Shortcode: [ft_step_train]
 */

add_shortcode('ft_step_train', 'ft_step_by_step_training');

function ft_step_by_step_training() {
    if (!current_user_can('manage_options')) {
        return '<p>No tienes permisos para esta acción.</p>';
    }
    
    ob_start();
    ?>
    <div style="max-width: 900px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 8px;">
        <h2>🔍 Entrenamiento Paso a Paso con Debug</h2>
        
        <div id="step-container">
            <h3>Paso 1: Verificación del Sistema</h3>
            <?php
            $plugin_path = WP_CONTENT_DIR . '/plugins/football-tipster/';
            
            // Verificar Python
            echo "<h4>Python:</h4>";
            $python_test = shell_exec('/usr/bin/python3.8 --version 2>&1');
            echo "<pre>Python: " . ($python_test ?: 'NO ENCONTRADO') . "</pre>";
            
            // Verificar librerías Python
            echo "<h4>Librerías Python:</h4>";
            $libs_test = shell_exec('/usr/bin/python3.8 -c "import pandas; print(\'pandas OK\')" 2>&1');
            echo "<pre>Pandas: " . $libs_test . "</pre>";
            
            $sklearn_test = shell_exec('/usr/bin/python3.8 -c "import sklearn; print(\'sklearn OK\')" 2>&1');
            echo "<pre>Sklearn: " . $sklearn_test . "</pre>";
            
            // Verificar acceso a BD desde PHP
            echo "<h4>Base de datos:</h4>";
            global $wpdb;
            $test_query = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ft_matches_advanced");
            echo "<pre>Registros en BD: " . $test_query . "</pre>";
            ?>
            
            <button onclick="runStep2()" class="button button-primary" style="margin-top: 20px;">
                ▶️ Continuar con Paso 2: Test de Python
            </button>
        </div>
        
        <div id="step2" style="display: none; margin-top: 30px;">
            <h3>Paso 2: Test de conexión Python-MySQL</h3>
            <pre id="python-test-output" style="background: #000; color: #0f0; padding: 10px;"></pre>
            <button onclick="runStep3()" class="button button-primary" style="margin-top: 10px; display: none;" id="btn-step3">
                ▶️ Continuar con Paso 3: Entrenamiento
            </button>
        </div>
        
        <div id="step3" style="display: none; margin-top: 30px;">
            <h3>Paso 3: Entrenamiento del Modelo</h3>
            <pre id="training-output" style="background: #000; color: #0f0; padding: 10px; max-height: 500px; overflow-y: auto;"></pre>
        </div>
        
        <div id="final-result" style="margin-top: 30px;"></div>
    </div>
    
    <script>
    function runStep2() {
        document.getElementById('step2').style.display = 'block';
        const output = document.getElementById('python-test-output');
        output.textContent = 'Probando conexión Python-MySQL...';
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ft_test_python_mysql',
                nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    output.textContent = response.data.output;
                    if (response.data.can_continue) {
                        document.getElementById('btn-step3').style.display = 'inline-block';
                    }
                } else {
                    output.textContent = 'ERROR: ' + response.data;
                }
            },
            error: function() {
                output.textContent = 'Error de conexión AJAX';
            }
        });
    }
    
    function runStep3() {
        document.getElementById('step3').style.display = 'block';
        const output = document.getElementById('training-output');
        output.textContent = 'Iniciando entrenamiento simplificado...\n';
        
        // Hacer polling para obtener actualizaciones
        let pollCount = 0;
        const maxPolls = 60; // 5 minutos máximo
        
        function pollTraining() {
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ft_simple_train',
                    step: pollCount === 0 ? 'start' : 'check',
                    nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
                },
                success: function(response) {
                    if (response.data.output) {
                        output.textContent += response.data.output + '\n';
                        output.scrollTop = output.scrollHeight;
                    }
                    
                    if (response.data.status === 'running' && pollCount < maxPolls) {
                        pollCount++;
                        setTimeout(pollTraining, 5000); // Revisar cada 5 segundos
                    } else if (response.data.status === 'complete') {
                        output.textContent += '\n✅ ENTRENAMIENTO COMPLETADO\n';
                        showFinalResult(true);
                    } else if (response.data.status === 'error') {
                        output.textContent += '\n❌ ERROR EN ENTRENAMIENTO\n';
                        showFinalResult(false);
                    } else if (pollCount >= maxPolls) {
                        output.textContent += '\n⏱️ Tiempo de espera agotado\n';
                        showFinalResult(false);
                    }
                },
                error: function() {
                    output.textContent += '\n❌ Error de conexión\n';
                    showFinalResult(false);
                }
            });
        }
        
        pollTraining();
    }
    
    function showFinalResult(success) {
        const resultDiv = document.getElementById('final-result');
        if (success) {
            resultDiv.innerHTML = `
                <div style="background: #d4edda; color: #155724; padding: 20px; border-radius: 5px;">
                    <h3>✅ Modelo Entrenado Exitosamente</h3>
                    <p>El modelo Random Forest ha sido entrenado y guardado correctamente.</p>
                    <button onclick="location.href='<?php echo admin_url('admin.php?page=football-tipster'); ?>'" class="button button-primary">
                        Ir al Panel de Control
                    </button>
                </div>
            `;
        } else {
            resultDiv.innerHTML = `
                <div style="background: #f8d7da; color: #721c24; padding: 20px; border-radius: 5px;">
                    <h3>❌ Error en el Entrenamiento</h3>
                    <p>Revisa el output anterior para más detalles.</p>
                    <button onclick="location.reload()" class="button">
                        Reintentar
                    </button>
                </div>
            `;
        }
    }
    </script>
    <?php
    return ob_get_clean();
}

// AJAX: Test Python-MySQL
add_action('wp_ajax_ft_test_python_mysql', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    $plugin_path = WP_CONTENT_DIR . '/plugins/football-tipster/';
    
    // Crear script de test
    $test_script = '#!/usr/bin/env python3
import sys
import json

print("1. Importando librerías...")
try:
    plugin_libs = "/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs"
    if plugin_libs not in sys.path:
        sys.path.insert(0, plugin_libs)
    
    import mysql.connector
    import pandas as pd
    import numpy as np
    from sklearn.ensemble import RandomForestClassifier
    print("✅ Librerías importadas correctamente")
except Exception as e:
    print(f"❌ Error importando librerías: {e}")
    sys.exit(1)

print("\n2. Cargando configuración...")
try:
    with open("db_config.json", "r") as f:
        config = json.load(f)
    print("✅ Configuración cargada")
    print(f"   Host: {config[\'host\']}")
    print(f"   Database: {config[\'database\']}")
except Exception as e:
    print(f"❌ Error cargando configuración: {e}")
    sys.exit(1)

print("\n3. Conectando a MySQL...")
try:
    host = config["host"]
    port = 3306
    if ":" in host:
        host, port = host.split(":")
        port = int(port)
    
    conn = mysql.connector.connect(
        host=host,
        port=port,
        user=config["user"],
        password=config["password"],
        database=config["database"]
    )
    print("✅ Conexión establecida")
    
    cursor = conn.cursor()
    cursor.execute("SELECT COUNT(*) FROM wp_ft_matches_advanced WHERE fthg IS NOT NULL")
    count = cursor.fetchone()[0]
    print(f"✅ Partidos en BD: {count}")
    
    cursor.close()
    conn.close()
    
except Exception as e:
    print(f"❌ Error conectando a MySQL: {e}")
    sys.exit(1)

print("\n✅ TODAS LAS PRUEBAS PASADAS - Sistema listo para entrenar")
';
    
    $test_file = $plugin_path . 'python/test_system.py';
    file_put_contents($test_file, $test_script);
    chmod($test_file, 0755);
    
    // Ejecutar test
    $command = "cd {$plugin_path}python && /usr/bin/python3.8 test_system.py 2>&1";
    $output = shell_exec($command);
    
    // Limpiar
    unlink($test_file);
    
    $can_continue = strpos($output, 'TODAS LAS PRUEBAS PASADAS') !== false;
    
    wp_send_json_success(array(
        'output' => $output,
        'can_continue' => $can_continue
    ));
});

// AJAX: Entrenamiento simple
add_action('wp_ajax_ft_simple_train', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    $step = $_POST['step'];
    $plugin_path = WP_CONTENT_DIR . '/plugins/football-tipster/';
    $status_file = $plugin_path . 'temp/training_status.txt';
    
    if ($step === 'start') {
        // Crear directorio temp si no existe
        if (!file_exists($plugin_path . 'temp')) {
            mkdir($plugin_path . 'temp', 0755, true);
        }
        
        // Script de entrenamiento ultra-simple
        $train_script = '#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys
import json
import pickle
import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from datetime import datetime

# Status file para comunicación
status_file = "../temp/training_status.txt"

def write_status(msg):
    print(msg)
    with open(status_file, "a") as f:
        f.write(msg + "\\n")

write_status("🚀 Iniciando entrenamiento simplificado...")

try:
    # Librerías
    plugin_libs = "/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs"
    if plugin_libs not in sys.path:
        sys.path.insert(0, plugin_libs)
    
    import mysql.connector
    
    # Configuración
    write_status("📋 Cargando configuración...")
    with open("db_config.json", "r") as f:
        config = json.load(f)
    
    # Conexión
    write_status("🔌 Conectando a base de datos...")
    host = config["host"]
    port = 3306
    if ":" in host:
        host, port = host.split(":")
        port = int(port)
    
    conn = mysql.connector.connect(
        host=host, port=port,
        user=config["user"],
        password=config["password"],
        database=config["database"]
    )
    
    # Cargar datos mínimos
    write_status("📊 Cargando datos...")
    query = """
    SELECT fthg, ftag, ftr, hs, as_shots 
    FROM wp_ft_matches_advanced 
    WHERE fthg IS NOT NULL 
    AND ftag IS NOT NULL 
    AND ftr IS NOT NULL
    LIMIT 5000
    """
    
    df = pd.read_sql(query, conn)
    conn.close()
    
    write_status(f"✅ {len(df)} partidos cargados")
    
    # Features súper simples
    write_status("🔧 Preparando datos...")
    X = df[[\'hs\', \'as_shots\']].fillna(10).values
    
    # Target
    y = df[\'ftr\'].map({\'H\': 2, \'D\': 1, \'A\': 0}).values
    
    # Dividir datos
    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)
    
    # Entrenar
    write_status("🤖 Entrenando modelo...")
    model = RandomForestClassifier(n_estimators=50, max_depth=5, random_state=42)
    model.fit(X_train, y_train)
    
    accuracy = model.score(X_test, y_test)
    write_status(f"📈 Precisión: {accuracy:.2%}")
    
    # Guardar
    write_status("💾 Guardando modelo...")
    with open("../models/football_rf_advanced.pkl", "wb") as f:
        pickle.dump(model, f)
    
    write_status("✅ COMPLETADO")
    
except Exception as e:
    write_status(f"❌ ERROR: {str(e)}")
    import traceback
    write_status(traceback.format_exc())
';
        
        $train_file = $plugin_path . 'python/simple_train.py';
        file_put_contents($train_file, $train_script);
        chmod($train_file, 0755);
        
        // Limpiar status anterior
        file_put_contents($status_file, '');
        
        // Ejecutar en background
        $command = "cd {$plugin_path}python && /usr/bin/python3.8 simple_train.py > /dev/null 2>&1 &";
        exec($command);
        
        wp_send_json_success(array(
            'status' => 'running',
            'output' => 'Entrenamiento iniciado...'
        ));
        
    } else {
        // Verificar status
        if (file_exists($status_file)) {
            $status_content = file_get_contents($status_file);
            $lines = explode("\n", trim($status_content));
            $last_line = end($lines);
            
            if (strpos($last_line, 'COMPLETADO') !== false) {
                wp_send_json_success(array(
                    'status' => 'complete',
                    'output' => $status_content
                ));
            } elseif (strpos($last_line, 'ERROR') !== false) {
                wp_send_json_success(array(
                    'status' => 'error',
                    'output' => $status_content
                ));
            } else {
                wp_send_json_success(array(
                    'status' => 'running',
                    'output' => $status_content
                ));
            }
        } else {
            wp_send_json_success(array(
                'status' => 'running',
                'output' => 'Esperando inicio...'
            ));
        }
    }
});


add_shortcode('ft_mysql_diagnose', 'ft_mysql_diagnosis');

function ft_mysql_diagnosis() {
    if (!current_user_can('manage_options')) {
        return '<p>No tienes permisos.</p>';
    }
    
    ob_start();
    ?>
    <div style="max-width: 800px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 8px;">
        <h2>🔍 Diagnóstico de Conexión MySQL</h2>
        
        <h3>1. Configuración de WordPress:</h3>
        <pre style="background: #f5f5f5; padding: 10px;">
Host: <?php echo DB_HOST; ?>
Usuario: <?php echo DB_USER; ?>
Base de datos: <?php echo DB_NAME; ?>
        </pre>
        
        <?php
        // Analizar host y puerto
        $host = DB_HOST;
        $port = 3306;
        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', DB_HOST, 2);
        }
        ?>
        
        <h3>2. Host y Puerto parseados:</h3>
        <pre style="background: #f5f5f5; padding: 10px;">
Host limpio: <?php echo $host; ?>
Puerto: <?php echo $port; ?>
        </pre>
        
        <h3>3. Test de conexión desde PHP:</h3>
        <?php
        try {
            $test_conn = new mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port);
            if ($test_conn->connect_error) {
                echo "<p style='color: red;'>❌ Error: " . $test_conn->connect_error . "</p>";
            } else {
                echo "<p style='color: green;'>✅ Conexión PHP exitosa</p>";
                $test_conn->close();
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Excepción: " . $e->getMessage() . "</p>";
        }
        ?>
        
        <h3>4. Archivo db_config.json actual:</h3>
        <?php
        $plugin_path = WP_CONTENT_DIR . '/plugins/football-tipster/';
        $config_file = $plugin_path . 'python/db_config.json';
        
        if (file_exists($config_file)) {
            $config = file_get_contents($config_file);
            echo "<pre style='background: #f5f5f5; padding: 10px;'>" . htmlspecialchars($config) . "</pre>";
        } else {
            echo "<p style='color: red;'>❌ Archivo no existe</p>";
        }
        ?>
        
        <h3>5. Test de Python básico:</h3>
        <button onclick="testPythonBasic()" class="button button-primary">🐍 Test Python Básico</button>
        <pre id="python-basic-output" style="background: #000; color: #0f0; padding: 10px; display: none;"></pre>
        
        <h3>6. Test de mysql-connector:</h3>
        <button onclick="testMysqlConnector()" class="button button-primary">🔌 Test MySQL Connector</button>
        <pre id="mysql-connector-output" style="background: #000; color: #0f0; padding: 10px; display: none;"></pre>
        
        <h3>7. Test de conexión simple:</h3>
        <button onclick="testSimpleConnection()" class="button button-primary">🎯 Test Conexión Simple</button>
        <pre id="simple-connection-output" style="background: #000; color: #0f0; padding: 10px; display: none;"></pre>
        
        <h3>8. Solución alternativa:</h3>
        <button onclick="createAlternativeConfig()" class="button button-secondary">🔧 Crear Configuración Alternativa</button>
        <div id="alternative-result" style="margin-top: 10px;"></div>
    </div>
    
    <script>
    function testPythonBasic() {
        const output = document.getElementById('python-basic-output');
        output.style.display = 'block';
        output.textContent = 'Ejecutando test...';
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ft_test_python_basic',
                nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
            },
            success: function(response) {
                output.textContent = response.data.output || 'Sin respuesta';
            },
            error: function() {
                output.textContent = 'Error AJAX';
            }
        });
    }
    
    function testMysqlConnector() {
        const output = document.getElementById('mysql-connector-output');
        output.style.display = 'block';
        output.textContent = 'Ejecutando test...';
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ft_test_mysql_connector',
                nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
            },
            success: function(response) {
                output.textContent = response.data.output || 'Sin respuesta';
            },
            error: function() {
                output.textContent = 'Error AJAX';
            }
        });
    }
    
    function testSimpleConnection() {
        const output = document.getElementById('simple-connection-output');
        output.style.display = 'block';
        output.textContent = 'Ejecutando test...';
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ft_test_simple_connection',
                nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
            },
            timeout: 30000,
            success: function(response) {
                output.textContent = response.data.output || 'Sin respuesta';
            },
            error: function(xhr, status) {
                output.textContent = 'Error: ' + status;
            }
        });
    }
    
    function createAlternativeConfig() {
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ft_create_alternative_config',
                nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
            },
            success: function(response) {
                const div = document.getElementById('alternative-result');
                if (response.success) {
                    div.innerHTML = '<p style="color: green;">✅ ' + response.data.message + '</p>';
                } else {
                    div.innerHTML = '<p style="color: red;">❌ ' + response.data + '</p>';
                }
            }
        });
    }
    </script>
    <?php
    return ob_get_clean();
}

// Test Python básico
add_action('wp_ajax_ft_test_python_basic', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    
    $command = '/usr/bin/python3.8 -c "print(\'Python funciona OK\')" 2>&1';
    $output = shell_exec($command);
    
    wp_send_json_success(array('output' => $output));
});

// Test mysql-connector
add_action('wp_ajax_ft_test_mysql_connector', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    
    $plugin_path = WP_CONTENT_DIR . '/plugins/football-tipster/';
    
    $test = '
import sys
plugin_libs = "/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs"
if plugin_libs not in sys.path:
    sys.path.insert(0, plugin_libs)

try:
    import mysql.connector
    print("✅ mysql.connector importado correctamente")
    print(f"Versión: {mysql.connector.__version__}")
except Exception as e:
    print(f"❌ Error importando mysql.connector: {e}")
';
    
    $command = "cd {$plugin_path}python && /usr/bin/python3.8 -c '$test' 2>&1";
    $output = shell_exec($command);
    
    wp_send_json_success(array('output' => $output));
});

// Test conexión simple
add_action('wp_ajax_ft_test_simple_connection', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    
    $plugin_path = WP_CONTENT_DIR . '/plugins/football-tipster/';
    
    // Obtener configuración limpia
    $host = DB_HOST;
    $port = 3306;
    if (strpos($host, ':') !== false) {
        list($host, $port) = explode(':', DB_HOST, 2);
    }
    
    $test_script = '#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys
import json

plugin_libs = "/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs"
if plugin_libs not in sys.path:
    sys.path.insert(0, plugin_libs)

print("1. Importando mysql.connector...")
try:
    import mysql.connector
    print("✅ Importado correctamente")
except Exception as e:
    print(f"❌ Error: {e}")
    sys.exit(1)

print("\\n2. Intentando conexión directa...")
try:
    # Configuración directa
    config = {
        "host": "' . $host . '",
        "port": ' . $port . ',
        "user": "' . DB_USER . '",
        "password": "' . DB_PASSWORD . '",
        "database": "' . DB_NAME . '"
    }
    
    print(f"Host: {config[\'host\']}")
    print(f"Puerto: {config[\'port\']}")
    print(f"Usuario: {config[\'user\']}")
    print(f"Base de datos: {config[\'database\']}")
    
    print("\\n3. Conectando...")
    conn = mysql.connector.connect(**config)
    
    print("✅ Conexión exitosa!")
    
    cursor = conn.cursor()
    cursor.execute("SELECT VERSION()")
    version = cursor.fetchone()
    print(f"MySQL versión: {version[0]}")
    
    cursor.execute("SELECT COUNT(*) FROM wp_ft_matches_advanced")
    count = cursor.fetchone()[0]
    print(f"Registros en tabla: {count}")
    
    cursor.close()
    conn.close()
    
    print("\\n✅ TODO FUNCIONANDO CORRECTAMENTE")
    
except mysql.connector.Error as err:
    print(f"\\n❌ Error MySQL: {err}")
    print(f"Código de error: {err.errno}")
    print(f"SQLState: {err.sqlstate}")
    print(f"Mensaje: {err.msg}")
except Exception as e:
    print(f"\\n❌ Error general: {e}")
    import traceback
    traceback.print_exc()
';
    
    $test_file = $plugin_path . 'python/test_connection.py';
    file_put_contents($test_file, $test_script);
    chmod($test_file, 0755);
    
    $command = "cd {$plugin_path}python && /usr/bin/python3.8 test_connection.py 2>&1";
    $output = shell_exec($command);
    
    unlink($test_file);
    
    wp_send_json_success(array('output' => $output));
});

// Crear configuración alternativa
add_action('wp_ajax_ft_create_alternative_config', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    
    $plugin_path = WP_CONTENT_DIR . '/plugins/football-tipster/';
    
    // Configuración limpia
    $host = DB_HOST;
    $port = 3306;
    if (strpos($host, ':') !== false) {
        list($host, $port) = explode(':', DB_HOST, 2);
    }
    
    // Crear múltiples versiones de configuración
    $configs = array(
        // Versión 1: Con puerto separado
        'db_config.json' => array(
            'host' => $host,
            'port' => (int)$port,
            'user' => DB_USER,
            'password' => DB_PASSWORD,
            'database' => DB_NAME
        ),
        // Versión 2: Host con puerto
        'db_config_alt.json' => array(
            'host' => $host . ':' . $port,
            'user' => DB_USER,
            'password' => DB_PASSWORD,
            'database' => DB_NAME
        ),
        // Versión 3: Solo localhost
        'db_config_local.json' => array(
            'host' => 'localhost',
            'port' => (int)$port,
            'user' => DB_USER,
            'password' => DB_PASSWORD,
            'database' => DB_NAME
        )
    );
    
    foreach ($configs as $filename => $config) {
        $file_path = $plugin_path . 'python/' . $filename;
        file_put_contents($file_path, json_encode($config, JSON_PRETTY_PRINT));
    }
    
    wp_send_json_success(array(
        'message' => 'Configuraciones alternativas creadas. Prueba los tests de nuevo.'
    ));
});



/**
 * Solución de emergencia - Entrenar modelo sin conexión Python-MySQL
 * Shortcode: [ft_emergency_train]
 */

add_shortcode('ft_emergency_train', 'ft_emergency_training');

function ft_emergency_training() {
    if (!current_user_can('manage_options')) {
        return '<p>No tienes permisos.</p>';
    }
    
    ob_start();
    ?>
    <div style="max-width: 800px; margin: 20px auto; padding: 20px; background: #fff; border-radius: 8px;">
        <h2>🚨 Entrenamiento de Emergencia</h2>
        
        <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin-bottom: 20px;">
            <p><strong>⚠️ Modo de emergencia:</strong> Este método exporta los datos a CSV y entrena el modelo sin usar conexión MySQL desde Python.</p>
        </div>
        
        <h3>Paso 1: Exportar datos a CSV</h3>
        <button onclick="exportDataToCSV()" class="button button-primary" id="btn-export">
            📥 Exportar Datos a CSV
        </button>
        <div id="export-result" style="margin-top: 10px;"></div>
        
        <h3 style="margin-top: 30px;">Paso 2: Entrenar modelo con CSV</h3>
        <button onclick="trainWithCSV()" class="button button-primary" id="btn-train" disabled>
            🤖 Entrenar Modelo
        </button>
        <div id="train-result" style="margin-top: 10px;"></div>
        
        <h3 style="margin-top: 30px;">Paso 3: Verificar modelo</h3>
        <button onclick="verifyModel()" class="button button-secondary" id="btn-verify" disabled>
            ✅ Verificar Modelo
        </button>
        <div id="verify-result" style="margin-top: 10px;"></div>
        
        <div id="final-status" style="margin-top: 30px;"></div>
    </div>
    
    <script>
    function exportDataToCSV() {
        const btn = document.getElementById('btn-export');
        const resultDiv = document.getElementById('export-result');
        
        btn.disabled = true;
        btn.textContent = '⏳ Exportando...';
        resultDiv.innerHTML = '<p>Exportando datos de la base de datos...</p>';
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ft_export_training_data',
                nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.innerHTML = '<div style="color: green;">✅ ' + response.data.message + '</div>';
                    document.getElementById('btn-train').disabled = false;
                } else {
                    resultDiv.innerHTML = '<div style="color: red;">❌ Error: ' + response.data + '</div>';
                }
                btn.textContent = '📥 Exportar Datos a CSV';
                btn.disabled = false;
            },
            error: function() {
                resultDiv.innerHTML = '<div style="color: red;">❌ Error de conexión</div>';
                btn.textContent = '📥 Exportar Datos a CSV';
                btn.disabled = false;
            }
        });
    }
    
    function trainWithCSV() {
        const btn = document.getElementById('btn-train');
        const resultDiv = document.getElementById('train-result');
        
        btn.disabled = true;
        btn.textContent = '⏳ Entrenando...';
        resultDiv.innerHTML = '<p>Entrenando modelo con datos CSV...</p>';
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ft_train_from_csv',
                nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
            },
            timeout: 300000, // 5 minutos
            success: function(response) {
                if (response.success) {
                    resultDiv.innerHTML = '<div style="color: green;">✅ ' + response.data.message + '</div>';
                    if (response.data.output) {
                        resultDiv.innerHTML += '<pre style="background: #f5f5f5; padding: 10px; margin-top: 10px;">' + response.data.output + '</pre>';
                    }
                    document.getElementById('btn-verify').disabled = false;
                } else {
                    resultDiv.innerHTML = '<div style="color: red;">❌ Error: ' + response.data + '</div>';
                }
                btn.textContent = '🤖 Entrenar Modelo';
                btn.disabled = false;
            },
            error: function(xhr, status) {
                resultDiv.innerHTML = '<div style="color: red;">❌ Error: ' + status + '</div>';
                btn.textContent = '🤖 Entrenar Modelo';
                btn.disabled = false;
            }
        });
    }
    
    function verifyModel() {
        const btn = document.getElementById('btn-verify');
        const resultDiv = document.getElementById('verify-result');
        
        btn.disabled = true;
        btn.textContent = '⏳ Verificando...';
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ft_verify_trained_model',
                nonce: '<?php echo wp_create_nonce('ft_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.innerHTML = '<div style="color: green;">✅ ' + response.data.message + '</div>';
                    resultDiv.innerHTML += '<pre style="background: #f5f5f5; padding: 10px; margin-top: 10px;">' + 
                                         JSON.stringify(response.data.details, null, 2) + '</pre>';
                    
                    document.getElementById('final-status').innerHTML = `
                        <div style="background: #d4edda; color: #155724; padding: 20px; border-radius: 5px;">
                            <h3>✅ Modelo Entrenado Exitosamente</h3>
                            <p>El modelo está listo para usar. Ahora puedes probar el benchmark.</p>
                            <button onclick="window.location.href='<?php echo admin_url('admin.php?page=football-tipster-benchmarking'); ?>'" 
                                    class="button button-primary">
                                Ir a Benchmarking
                            </button>
                        </div>
                    `;
                } else {
                    resultDiv.innerHTML = '<div style="color: red;">❌ ' + response.data + '</div>';
                }
                btn.textContent = '✅ Verificar Modelo';
                btn.disabled = false;
            }
        });
    }
    </script>
    <?php
    return ob_get_clean();
}

// AJAX: Exportar datos a CSV
add_action('wp_ajax_ft_export_training_data', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    global $wpdb;
    $plugin_path = WP_CONTENT_DIR . '/plugins/football-tipster/';
    
    try {
        // Obtener datos
        $data = $wpdb->get_results("
            SELECT 
                season, date, home_team, away_team,
                fthg, ftag, ftr,
                hs, as_shots, hst, ast,
                hc, ac, hf, af,
                hy, ay, hr, ar,
                home_xg, away_xg
            FROM {$wpdb->prefix}ft_matches_advanced
            WHERE fthg IS NOT NULL 
            AND ftag IS NOT NULL
            AND ftr IS NOT NULL
            ORDER BY date DESC
            LIMIT 10000
        ", ARRAY_A);
        
        if (empty($data)) {
            wp_send_json_error('No hay datos para exportar');
        }
        
        // Crear CSV
        $csv_file = $plugin_path . 'temp/training_data.csv';
        
        // Crear directorio si no existe
        if (!file_exists($plugin_path . 'temp')) {
            mkdir($plugin_path . 'temp', 0755, true);
        }
        
        $fp = fopen($csv_file, 'w');
        
        // Headers
        fputcsv($fp, array_keys($data[0]));
        
        // Datos
        foreach ($data as $row) {
            fputcsv($fp, $row);
        }
        
        fclose($fp);
        
        wp_send_json_success(array(
            'message' => 'Exportados ' . count($data) . ' registros a CSV',
            'file' => 'temp/training_data.csv'
        ));
        
    } catch (Exception $e) {
        wp_send_json_error('Error exportando: ' . $e->getMessage());
    }
});

// AJAX: Entrenar desde CSV
add_action('wp_ajax_ft_train_from_csv', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    $plugin_path = WP_CONTENT_DIR . '/plugins/football-tipster/';
    
    // Script de entrenamiento sin MySQL
    $train_script = '#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Entrenamiento de emergencia desde CSV
"""

import sys
import pickle
import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from datetime import datetime
import json

print("🚀 Iniciando entrenamiento desde CSV...")

try:
    # Cargar CSV
    print("📊 Cargando datos...")
    df = pd.read_csv("../temp/training_data.csv")
    print(f"✅ {len(df)} registros cargados")
    
    # Preparar features
    print("🔧 Preparando features...")
    
    # Llenar valores faltantes
    numeric_columns = ["hs", "as_shots", "hst", "ast", "hc", "ac", "hf", "af", "hy", "ay", "hr", "ar"]
    for col in numeric_columns:
        if col in df.columns:
            df[col] = pd.to_numeric(df[col], errors="coerce").fillna(df[col].median() if col in df else 0)
    
    # Features básicas
    features = []
    feature_names = []
    
    # Estadísticas de tiros
    if "hs" in df.columns and "as_shots" in df.columns:
        features.append(df["hs"].values)
        features.append(df["as_shots"].values)
        feature_names.extend(["home_shots", "away_shots"])
    
    # Tiros a puerta
    if "hst" in df.columns and "ast" in df.columns:
        features.append(df["hst"].values)
        features.append(df["ast"].values)
        feature_names.extend(["home_shots_target", "away_shots_target"])
    
    # Corners
    if "hc" in df.columns and "ac" in df.columns:
        features.append(df["hc"].values)
        features.append(df["ac"].values)
        feature_names.extend(["home_corners", "away_corners"])
    
    # Faltas
    if "hf" in df.columns and "af" in df.columns:
        features.append(df["hf"].values)
        features.append(df["af"].values)
        feature_names.extend(["home_fouls", "away_fouls"])
    
    # xG si existe
    if "home_xg" in df.columns and "away_xg" in df.columns:
        df["home_xg"] = pd.to_numeric(df["home_xg"], errors="coerce").fillna(1.5)
        df["away_xg"] = pd.to_numeric(df["away_xg"], errors="coerce").fillna(1.5)
        features.append(df["home_xg"].values)
        features.append(df["away_xg"].values)
        feature_names.extend(["home_xg", "away_xg"])
    
    # Crear matriz de features
    X = np.column_stack(features)
    print(f"📐 Shape de features: {X.shape}")
    print(f"📋 Features utilizadas: {feature_names}")
    
    # Target
    y = df["ftr"].map({"H": 2, "D": 1, "A": 0}).values
    
    # Eliminar NaN
    mask = ~np.isnan(X).any(axis=1) & ~np.isnan(y)
    X = X[mask]
    y = y[mask]
    
    print(f"✅ Datos finales: {len(X)} muestras")
    
    # Dividir datos
    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)
    
    # Entrenar modelo
    print("🤖 Entrenando Random Forest...")
    model = RandomForestClassifier(
        n_estimators=100,
        max_depth=10,
        min_samples_split=5,
        min_samples_leaf=2,
        random_state=42,
        n_jobs=-1
    )
    
    model.fit(X_train, y_train)
    
    # Evaluar
    accuracy = model.score(X_test, y_test)
    print(f"📈 Precisión en test: {accuracy:.2%}")
    
    # Guardar modelo
    print("💾 Guardando modelo...")
    model_data = {
        "model": model,
        "features": feature_names,
        "accuracy": accuracy,
        "n_samples": len(X),
        "training_date": datetime.now().isoformat()
    }
    
    with open("../models/football_rf_advanced.pkl", "wb") as f:
        pickle.dump(model_data, f)
    
    # Guardar metadata
    metadata = {
        "training_date": datetime.now().isoformat(),
        "features": feature_names,
        "n_features": len(feature_names),
        "n_samples": len(X),
        "performance": {
            "accuracy": accuracy
        }
    }
    
    with open("../models/model_metadata.json", "w") as f:
        json.dump(metadata, f, indent=2)
    
    print("✅ MODELO ENTRENADO EXITOSAMENTE")
    print(f"📊 Resumen:")
    print(f"   - Features: {len(feature_names)}")
    print(f"   - Muestras: {len(X)}")
    print(f"   - Precisión: {accuracy:.2%}")
    
except Exception as e:
    print(f"❌ ERROR: {str(e)}")
    import traceback
    traceback.print_exc()
';
    
    $script_file = $plugin_path . 'python/train_from_csv.py';
    file_put_contents($script_file, $train_script);
    chmod($script_file, 0755);
    
    // Ejecutar
    $command = "cd {$plugin_path}python && /usr/bin/python3.8 train_from_csv.py 2>&1";
    $output = shell_exec($command);
    
    // Verificar si se creó el modelo
    if (file_exists($plugin_path . 'models/football_rf_advanced.pkl')) {
        wp_send_json_success(array(
            'message' => 'Modelo entrenado correctamente',
            'output' => $output
        ));
    } else {
        wp_send_json_error("No se pudo crear el modelo. Output:\n" . $output);
    }
});

// AJAX: Verificar modelo
add_action('wp_ajax_ft_verify_trained_model', function() {
    check_ajax_referer('ft_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Sin permisos');
    }
    
    $plugin_path = WP_CONTENT_DIR . '/plugins/football-tipster/';
    $model_path = $plugin_path . 'models/football_rf_advanced.pkl';
    
    if (!file_exists($model_path)) {
        wp_send_json_error('El modelo no existe');
    }
    
    // Verificar con PHP primero
    $size = filesize($model_path);
    $modified = date('Y-m-d H:i:s', filemtime($model_path));
    
    // Leer metadata si existe
    $metadata_path = $plugin_path . 'models/model_metadata.json';
    $metadata = null;
    if (file_exists($metadata_path)) {
        $metadata = json_decode(file_get_contents($metadata_path), true);
    }
    
    wp_send_json_success(array(
        'message' => 'Modelo verificado',
        'details' => array(
            'size' => round($size / 1024 / 1024, 2) . ' MB',
            'modified' => $modified,
            'metadata' => $metadata
        )
    ));
});

// Inicializar el plugin
FootballTipster::get_instance();

?>

			

        