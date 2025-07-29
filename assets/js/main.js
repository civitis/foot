//**
 * JavaScript principal para Football Tipster (Frontend)
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        console.log('Football Tipster Main JS cargado');
        
        // Inicializar solo si estamos en una página con predicciones
        if ($('.ft-predictions-container').length > 0) {
            initPredictions();
        }
    });
    
    function initPredictions() {
        // Cargar equipos disponibles
        loadTeams();
        
        // Event handlers
        $('#ft-predict-btn').on('click', makePrediction);
        $('#ft-home-team, #ft-away-team').on('change', validateTeamSelection);
    }
    
    function loadTeams() {
        if (typeof ft_ajax === 'undefined') {
            console.error('ft_ajax no está definido');
            return;
        }
        
        $.ajax({
            url: ft_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ft_get_teams',
                nonce: ft_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    populateTeamSelects(response.data);
                }
            },
            error: function() {
                console.error('Error cargando equipos');
            }
        });
    }
    
    function populateTeamSelects(teams) {
        const $homeSelect = $('#ft-home-team');
        const $awaySelect = $('#ft-away-team');
        
        $homeSelect.empty().append('<option value="">Seleccionar equipo local</option>');
        $awaySelect.empty().append('<option value="">Seleccionar equipo visitante</option>');
        
        teams.forEach(function(team) {
            $homeSelect.append('<option value="' + team + '">' + team + '</option>');
            $awaySelect.append('<option value="' + team + '">' + team + '</option>');
        });
    }
    
    function makePrediction() {
        const homeTeam = $('#ft-home-team').val();
        const awayTeam = $('#ft-away-team').val();
        
        if (!homeTeam || !awayTeam) {
            showAlert('Por favor selecciona ambos equipos', 'warning');
            return;
        }
        
        if (homeTeam === awayTeam) {
            showAlert('Los equipos deben ser diferentes', 'warning');
            return;
        }
        
        showLoading();
        
        $.ajax({
            url: ft_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ft_get_prediction',
                home_team: homeTeam,
                away_team: awayTeam,
                nonce: ft_ajax.nonce
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    displayPrediction(response.data);
                } else {
                    showAlert(response.data || 'Error en la predicción', 'error');
                }
            },
            error: function() {
                hideLoading();
                showAlert('Error de conexión', 'error');
            }
        });
    }
    
    function displayPrediction(data) {
        // Implementar display de predicción
        $('#ft-prediction-result').html('<div class="ft-prediction-success">Predicción: ' + data.prediction + '</div>');
    }
    
    function validateTeamSelection() {
        const homeTeam = $('#ft-home-team').val();
        const awayTeam = $('#ft-away-team').val();
        
        $('#ft-predict-btn').prop('disabled', !homeTeam || !awayTeam || homeTeam === awayTeam);
    }
    
    function showLoading() {
        $('#ft-prediction-result').html('<div class="ft-loading">Analizando datos...</div>');
    }
    
    function hideLoading() {
        // Se maneja en displayPrediction
    }
    
    function showAlert(message, type) {
        const alertClass = 'ft-alert-' + type;
        const $alert = $('<div class="ft-alert ' + alertClass + '">' + message + '</div>');
        
        $('#ft-prediction-result').html($alert);
        
        setTimeout(function() {
            $alert.fadeOut();
        }, 5000);
    }
    
})(jQuery);