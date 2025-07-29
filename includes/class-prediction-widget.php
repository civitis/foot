<?php
class FT_Prediction_Widget extends WP_Widget {
    
    public function __construct() {
        parent::__construct(
            'ft_prediction_widget',
            'Football Tipster - Predicciones',
            array('description' => 'Muestra predicciones de partidos')
        );
    }
    
    public function widget($args, $instance) {
        echo $args['before_widget'];
        
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }
        
        $this->display_upcoming_matches($instance);
        
        echo $args['after_widget'];
    }
    
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : 'Próximos Partidos';
        $league = !empty($instance['league']) ? $instance['league'] : 'all';
        $limit = !empty($instance['limit']) ? $instance['limit'] : 5;
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>">Título:</label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('league'); ?>">Liga:</label>
            <select class="widefat" id="<?php echo $this->get_field_id('league'); ?>" name="<?php echo $this->get_field_name('league'); ?>">
                <option value="all" <?php selected($league, 'all'); ?>>Todas</option>
                <option value="E0" <?php selected($league, 'E0'); ?>>Premier League</option>
                <option value="SP1" <?php selected($league, 'SP1'); ?>>La Liga</option>
            </select>
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('limit'); ?>">Número de partidos:</label>
            <input class="widefat" id="<?php echo $this->get_field_id('limit'); ?>" name="<?php echo $this->get_field_name('limit'); ?>" type="number" value="<?php echo esc_attr($limit); ?>" min="1" max="10">
        </p>
        <?php
    }
    
    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? sanitize_text_field($new_instance['title']) : '';
        $instance['league'] = (!empty($new_instance['league'])) ? sanitize_text_field($new_instance['league']) : 'all';
        $instance['limit'] = (!empty($new_instance['limit'])) ? absint($new_instance['limit']) : 5;
        
        return $instance;
    }
    
    private function display_upcoming_matches($instance) {
        // Aquí podrías integrar con una API de fixtures
        // Por ahora, mostrar predicciones recientes
        global $wpdb;
        $table = $wpdb->prefix . 'ft_predictions';
        
        $sql = "SELECT * FROM $table WHERE predicted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY predicted_at DESC LIMIT %d";
        $predictions = $wpdb->get_results($wpdb->prepare($sql, $instance['limit']));
        
        if (empty($predictions)) {
            echo '<p>No hay predicciones disponibles.</p>';
            return;
        }
        
        echo '<ul class="ft-widget-predictions">';
        foreach ($predictions as $pred) {
            $prediction_text = array(
                'H' => '1',
                'D' => 'X',
                'A' => '2'
            );
            
            printf(
                '<li><strong>%s vs %s</strong><br/>
                Predicción: <span class="ft-pred-%s">%s</span> (%.0f%%)</li>',
                esc_html($pred->home_team),
                esc_html($pred->away_team),
                esc_attr($pred->prediction),
                $prediction_text[$pred->prediction],
                $pred->probability * 100
            );
        }
        echo '</ul>';
    }
}

// Registrar widget
function register_ft_widgets() {
    register_widget('FT_Prediction_Widget');
}
add_action('widgets_init', 'register_ft_widgets');