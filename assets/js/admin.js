/**
 * JavaScript para el panel de administración de Football Tipster
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        console.log('Football Tipster Admin JS cargado');
        
        // Manejar importación de CSV
        $('#ft-import-data').on('submit', function(e) {
            e.preventDefault();
            
            const form = this;
            const $submitButton = $(form).find('button[type="submit"]');
            const $status = $('#ft-import-status');
            
            // Verificar que hay archivo seleccionado
            const fileInput = $(form).find('input[type="file"]')[0];
            if (!fileInput.files || !fileInput.files[0]) {
                $status.html('<div class="notice notice-error"><p>❌ Por favor selecciona un archivo CSV</p></div>');
                return;
            }
            
            // Verificar extensión
            const fileName = fileInput.files[0].name.toLowerCase();
            if (!fileName.endsWith('.csv')) {
                $status.html('<div class="notice notice-error"><p>❌ Solo se permiten archivos CSV</p></div>');
                return;
            }
            
            // Preparar FormData
            const formData = new FormData();
            formData.append('action', 'ft_import_csv');
            formData.append('nonce', ft_admin_ajax.nonce);
            formData.append('csv_file', fileInput.files[0]);
            
            // Cambiar estado del botón
            $submitButton.prop('disabled', true).text('⏳ Importando...');
            $status.html('<div class="notice notice-info"><p>📂 Importando archivo CSV...</p></div>');
            
            // Hacer petición AJAX
            $.ajax({
                url: ft_admin_ajax.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 120000, // 2 minutos
                success: function(response) {
                    console.log('Respuesta importación:', response);
                    
                    if (response.success) {
                        $status.html('<div class="notice notice-success"><p>✅ ' + response.data + '</p></div>');
                        
                        // Limpiar formulario
                        form.reset();
                        
                        // Actualizar página después de 3 segundos
                        setTimeout(function() {
                            if (confirm('Importación completada. ¿Recargar página para ver estadísticas actualizadas?')) {
                                location.reload();
                            }
                        }, 3000);
                        
                    } else {
                        $status.html('<div class="notice notice-error"><p>❌ Error: ' + (response.data || 'Error desconocido') + '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error AJAX importación:', error, xhr.responseText);
                    
                    let errorMsg = 'Error de conexión';
                    if (xhr.responseText) {
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            errorMsg = errorResponse.data || errorResponse.message || xhr.responseText;
                        } catch (e) {
                            errorMsg = xhr.responseText.substring(0, 200);
                        }
                    }
                    
                    $status.html('<div class="notice notice-error"><p>❌ Error: ' + errorMsg + '</p></div>');
                },
                complete: function() {
                    $submitButton.prop('disabled', false).text('📤 Importar CSV');
                }
            });
        });
        
      // Manejar formulario de URL (VERSIÓN CORREGIDA)
$('#ft-import-url').on('submit', function(e) {
    e.preventDefault();
    
    const $form = $(this);
    const $submitButton = $form.find('button[type="submit"]');
    const $status = $('#ft-import-status');
    const csvUrl = $form.find('input[name="csv_url"]').val().trim();
    
    if (!csvUrl) {
        $status.html('<div class="notice notice-error"><p>❌ Por favor introduce una URL válida</p></div>');
        return;
    }
    
    // Validar URL básica
    try {
        new URL(csvUrl);
    } catch {
        $status.html('<div class="notice notice-error"><p>❌ Formato de URL no válido</p></div>');
        return;
    }
    
    $submitButton.prop('disabled', true).text('⏳ Descargando...');
    $status.html('<div class="notice notice-info"><p>🌐 Descargando CSV desde URL...</p></div>');
    
    $.ajax({
        url: ft_admin_ajax.ajax_url,
        type: 'POST',
        data: {
            action: 'ft_import_csv_url',
            nonce: ft_admin_ajax.nonce,
            csv_url: csvUrl
        },
        timeout: 180000, // 3 minutos
        success: function(response) {
            console.log('Respuesta URL:', response);
            
            if (response.success) {
                $status.html('<div class="notice notice-success"><p>✅ ' + response.data + '</p></div>');
                $form[0].reset();
                
                // Preguntar si recargar
                setTimeout(function() {
                    if (confirm('Importación desde URL completada. ¿Recargar página?')) {
                        location.reload();
                    }
                }, 2000);
                
            } else {
                $status.html('<div class="notice notice-error"><p>❌ Error: ' + (response.data || 'Error desconocido') + '</p></div>');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error AJAX URL:', error, xhr.responseText);
            
            let errorMsg = 'Error de conexión al descargar desde URL';
            if (status === 'timeout') {
                errorMsg = 'Timeout: La descarga tardó demasiado';
            } else if (xhr.responseText) {
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    errorMsg = errorResponse.data || errorResponse.message || 'Error del servidor';
                } catch (e) {
                    errorMsg = 'Error del servidor';
                }
            }
            
            $status.html('<div class="notice notice-error"><p>❌ ' + errorMsg + '</p></div>');
        },
        complete: function() {
            $submitButton.prop('disabled', false).text('📥 Importar desde URL');
        }
    });
});
        
        // Entrenar modelo
        $('#ft-train-model').on('click', function() {
            const $button = $(this);
            const $status = $('#ft-training-status');
            
            $button.prop('disabled', true).text('🔄 Entrenando...');
            $status.html('<div class="notice notice-info"><p>🤖 Entrenando modelo Random Forest... Esto puede tardar varios minutos.</p></div>');
            
            $.ajax({
                url: ft_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ft_train_model',
                    nonce: ft_admin_ajax.nonce
                },
                timeout: 300000, // 5 minutos
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        $status.html('<div class="notice notice-success"><p>✅ Modelo entrenado exitosamente<br/>' +
                                   'Precisión: ' + (data.accuracy * 100).toFixed(2) + '%<br/>' +
                                   'Características: ' + (data.features ? data.features.length : 'N/A') + '</p></div>');
                    } else {
                        $status.html('<div class="notice notice-error"><p>❌ Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $status.html('<div class="notice notice-error"><p>❌ Error al entrenar el modelo</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('🤖 Entrenar Random Forest');
                }
            });
        });
        
        // Actualizar xG
        $('#ft-update-xg').on('click', function() {
            const $button = $(this);
            const $status = $('#ft-xg-status');
            
            $button.prop('disabled', true).text('🔄 Actualizando...');
            $status.html('<div class="notice notice-info"><p>📡 Obteniendo xG desde FBref...</p></div>');
            
            $.ajax({
                url: ft_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ft_update_xg',
                    nonce: ft_admin_ajax.nonce
                },
                timeout: 120000, // 2 minutos
                success: function(response) {
                    if (response.success) {
                        $status.html('<div class="notice notice-success"><p>✅ xG actualizado: ' + response.data.updated + ' partidos procesados</p></div>');
                    } else {
                        $status.html('<div class="notice notice-error"><p>❌ Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $status.html('<div class="notice notice-error"><p>❌ Error al actualizar xG</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('📡 Actualizar xG');
                }
            });
        });
    });
   
	jQuery(document).ready(function($) {
    // 1. Cargar las ligas desde AJAX y poblar el select
    function loadPinnacleLeagues() {
        $.post(ajaxurl, {
            action: 'ft_get_pinnacle_leagues'
        }, function(response) {
            if (response.success && response.data.length > 0) {
                let $select = $('#pinnacle_leagues_select');
                $select.empty();

                // Opciones ya seleccionadas guardadas
                let saved = $('#pinnacle_leagues').val() ? $('#pinnacle_leagues').val().split(',') : [];

                $.each(response.data, function(i, league) {
                    // League id -> valor; League name -> texto
                    let selected = saved.includes(league.id.toString()) ? ' selected' : '';
                    $select.append('<option value="' + league.id + '"' + selected + '>' + league.name + ' (' + league.id + ')</option>');
                });
            }
        });
    }

    loadPinnacleLeagues();

    // 2. Mantener sincronizado el hidden input que guarda los IDs seleccionados
    $('#pinnacle_leagues_select').on('change', function() {
        let vals = $(this).val();
        $('#pinnacle_leagues').val(vals ? vals.join(',') : '');
    });
});
})(jQuery);