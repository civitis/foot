#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Football Tipster - Script de predicción de partidos
Usa el modelo Random Forest entrenado para predecir resultados
"""

import sys
import json
import pickle
import numpy as np
import pandas as pd
from datetime import datetime, timedelta
import warnings
warnings.filterwarnings('ignore')

# Agregar path para librerías personalizadas
plugin_libs = '/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs'
if plugin_libs not in sys.path:
    sys.path.insert(0, plugin_libs)

import mysql.connector
from mysql.connector import Error

def get_db_connection(config):
    """Crear conexión a la base de datos"""
    try:
        # Extraer puerto si está en el host
        host = config['host']
        port = 3306  # Puerto por defecto
        
        if ':' in host:
            host_parts = host.split(':')
            host = host_parts[0]
            port = int(host_parts[1])
        
        connection = mysql.connector.connect(
            host=host,
            port=port,
            database=config['database'],
            user=config['user'],
            password=config['password'],
            charset='utf8mb4',
            use_unicode=True
        )
        return connection
    except Error as e:
        print(f"Error conectando a MySQL: {e}")
        return None

def calculate_team_stats(team, is_home, matches_df, n_matches=10):
    """Calcular estadísticas del equipo para los últimos n partidos"""
    if is_home:
        team_matches = matches_df[matches_df['home_team'] == team].tail(n_matches)
    else:
        team_matches = matches_df[matches_df['away_team'] == team].tail(n_matches)
    
    if len(team_matches) == 0:
        return None
    
    stats = {}
    
    if is_home:
        # Estadísticas como local
        stats['avg_goals_for'] = team_matches['fthg'].mean()
        stats['avg_goals_against'] = team_matches['ftag'].mean()
        stats['avg_shots_for'] = team_matches['hs'].mean() if 'hs' in team_matches else 10
        stats['avg_shots_against'] = team_matches['as_shots'].mean() if 'as_shots' in team_matches else 10
        stats['avg_corners_for'] = team_matches['hc'].mean() if 'hc' in team_matches else 5
        stats['avg_corners_against'] = team_matches['ac'].mean() if 'ac' in team_matches else 5
        stats['avg_xg_for'] = team_matches['home_xg'].mean() if 'home_xg' in team_matches else 1.5
        stats['avg_xg_against'] = team_matches['away_xg'].mean() if 'away_xg' in team_matches else 1.5
        
        # Forma reciente
        results = []
        for _, match in team_matches.iterrows():
            if match['ftr'] == 'H':
                results.append(3)
            elif match['ftr'] == 'D':
                results.append(1)
            else:
                results.append(0)
    else:
        # Estadísticas como visitante
        stats['avg_goals_for'] = team_matches['ftag'].mean()
        stats['avg_goals_against'] = team_matches['fthg'].mean()
        stats['avg_shots_for'] = team_matches['as_shots'].mean() if 'as_shots' in team_matches else 10
        stats['avg_shots_against'] = team_matches['hs'].mean() if 'hs' in team_matches else 10
        stats['avg_corners_for'] = team_matches['ac'].mean() if 'ac' in team_matches else 5
        stats['avg_corners_against'] = team_matches['hc'].mean() if 'hc' in team_matches else 5
        stats['avg_xg_for'] = team_matches['away_xg'].mean() if 'away_xg' in team_matches else 1.5
        stats['avg_xg_against'] = team_matches['home_xg'].mean() if 'home_xg' in team_matches else 1.5
        
        # Forma reciente
        results = []
        for _, match in team_matches.iterrows():
            if match['ftr'] == 'A':
                results.append(3)
            elif match['ftr'] == 'D':
                results.append(1)
            else:
                results.append(0)
    
    stats['form_points'] = np.mean(results[-5:]) if results else 1.0
    stats['win_rate'] = len([r for r in results if r == 3]) / len(results) if results else 0.33
    
    return stats

def predict_match(home_team, away_team, connection):
    """Predecir el resultado de un partido"""
    try:
        cursor = connection.cursor(dictionary=True)
        
        # Obtener partidos históricos
        query = """
            SELECT * FROM wp_ft_matches_advanced 
            WHERE (home_team = %s OR away_team = %s OR home_team = %s OR away_team = %s)
            AND fthg IS NOT NULL AND ftag IS NOT NULL
            ORDER BY date DESC
            LIMIT 100
        """
        
        cursor.execute(query, (home_team, home_team, away_team, away_team))
        matches = cursor.fetchall()
        
        if not matches:
            return {'error': f'No hay datos históricos para {home_team} o {away_team}'}
        
        # Convertir a DataFrame
        matches_df = pd.DataFrame(matches)
        
        # Calcular estadísticas
        home_stats = calculate_team_stats(home_team, True, matches_df)
        away_stats = calculate_team_stats(away_team, False, matches_df)
        
        if not home_stats or not away_stats:
            return {'error': 'No hay suficientes datos para hacer la predicción'}
        
        # Crear features para el modelo
        features = []
        
        # Features básicas
        features.extend([
            home_stats['avg_goals_for'],
            home_stats['avg_goals_against'],
            away_stats['avg_goals_for'],
            away_stats['avg_goals_against'],
            home_stats['avg_shots_for'],
            home_stats['avg_shots_against'],
            away_stats['avg_shots_for'],
            away_stats['avg_shots_against'],
            home_stats['avg_corners_for'],
            home_stats['avg_corners_against'],
            away_stats['avg_corners_for'],
            away_stats['avg_corners_against'],
            home_stats['form_points'],
            away_stats['form_points'],
            home_stats['win_rate'],
            away_stats['win_rate']
        ])
        
        # xG features si están disponibles
        if 'avg_xg_for' in home_stats:
            features.extend([
                home_stats['avg_xg_for'],
                home_stats['avg_xg_against'],
                away_stats['avg_xg_for'],
                away_stats['avg_xg_against']
            ])
        
        # Cargar modelo
        model_path = '/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/models/football_rf_advanced.pkl'
        
        try:
            with open(model_path, 'rb') as f:
                model_data = pickle.load(f)
                
            if isinstance(model_data, dict):
                model = model_data['model']
                feature_names = model_data.get('features', [])
            else:
                model = model_data
                feature_names = []
            
        except Exception as e:
            return {'error': f'Error cargando el modelo: {str(e)}'}
        
        # Ajustar número de features
        n_features_expected = model.n_features_in_
        n_features_actual = len(features)
        
        if n_features_actual < n_features_expected:
            # Agregar features dummy si faltan
            features.extend([0] * (n_features_expected - n_features_actual))
        elif n_features_actual > n_features_expected:
            # Recortar si sobran
            features = features[:n_features_expected]
        
        # Hacer predicción
        X = np.array(features).reshape(1, -1)
        
        # Predicción
        prediction = model.predict(X)[0]
        probabilities = model.predict_proba(X)[0]
        
        # Mapear clases
        class_mapping = {0: 'A', 1: 'D', 2: 'H'}  # Ajustar según tu modelo
        
        # Encontrar el índice de la predicción
        pred_index = int(prediction)
        predicted_result = class_mapping.get(pred_index, 'H')
        
        # Crear diccionario de probabilidades
        prob_dict = {}
        for idx, prob in enumerate(probabilities):
            result = class_mapping.get(idx, 'H')
            prob_dict[result] = float(prob)
        
        # Asegurar que todas las clases estén presentes
        for result in ['H', 'D', 'A']:
            if result not in prob_dict:
                prob_dict[result] = 0.0
        
        # Obtener la probabilidad de la predicción
        prediction_probability = prob_dict.get(predicted_result, max(probabilities))
        
        result = {
            'success': True,
            'prediction': predicted_result,
            'probability': float(prediction_probability),
            'probabilities': prob_dict,
            'home_team': home_team,
            'away_team': away_team,
            'features_used': len(features),
            'model_features': n_features_expected
        }
        
        return result
        
    except Exception as e:
        return {'error': f'Error en la predicción: {str(e)}', 'details': str(e)}
    finally:
        if cursor:
            cursor.close()

def main():
    """Función principal"""
    if len(sys.argv) < 3:
        print(json.dumps({'error': 'Uso: predict_match.py <equipo_local> <equipo_visitante>'}))
        sys.exit(1)
    
    home_team = sys.argv[1]
    away_team = sys.argv[2]
    
    # Cargar configuración de BD
    try:
        with open('/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python/db_config.json', 'r') as f:
            db_config = json.load(f)
    except:
        print(json.dumps({'error': 'No se pudo cargar la configuración de la base de datos'}))
        sys.exit(1)
    
    # Conectar a BD
    connection = get_db_connection(db_config)
    if not connection:
        print(json.dumps({'error': 'No se pudo conectar a la base de datos'}))
        sys.exit(1)
    
    try:
        # Hacer predicción
        result = predict_match(home_team, away_team, connection)
        print(json.dumps(result))
    finally:
        if connection and connection.is_connected():
            connection.close()

if __name__ == "__main__":
    main()