"""
Script para hacer predicciones en tiempo real
"""

import sys
import json
import joblib
import numpy as np
import mysql.connector
from datetime import datetime, timedelta

def get_team_recent_stats(team_name, db_config):
    """
    Obtiene estadísticas recientes de un equipo
    """
    connection = mysql.connector.connect(**db_config)
    cursor = connection.cursor(dictionary=True)
    
    # Query para obtener estadísticas de los últimos 5 partidos
    query = """
    SELECT 
        AVG(CASE WHEN home_team = %s THEN fthg ELSE ftag END) as avg_goals,
        AVG(CASE WHEN home_team = %s THEN hs ELSE as_shots END) as avg_shots,
        AVG(CASE WHEN home_team = %s THEN hst ELSE ast END) as avg_shots_target,
        AVG(CASE WHEN home_team = %s THEN hc ELSE ac END) as avg_corners,
        AVG(CASE WHEN home_team = %s THEN home_xg ELSE away_xg END) as avg_xg
    FROM wp_ft_matches_advanced
    WHERE (home_team = %s OR away_team = %s)
    AND date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
    ORDER BY date DESC
    LIMIT 5
    """
    
    params = [team_name] * 7
    cursor.execute(query, params)
    stats = cursor.fetchone()
    
    connection.close()
    return stats

def get_h2h_stats(home_team, away_team, db_config):
    """
    Obtiene estadísticas de enfrentamientos directos
    """
    connection = mysql.connector.connect(**db_config)
    cursor = connection.cursor(dictionary=True)
    
    query = """
    SELECT 
        COUNT(*) as total_matches,
        SUM(CASE WHEN ftr = 'H' THEN 1 ELSE 0 END) as home_wins,
        SUM(CASE WHEN ftr = 'D' THEN 1 ELSE 0 END) as draws,
        SUM(CASE WHEN ftr = 'A' THEN 1 ELSE 0 END) as away_wins
    FROM wp_ft_matches_advanced
    WHERE home_team = %s AND away_team = %s
    AND date >= DATE_SUB(NOW(), INTERVAL 5 YEAR)
    """
    
    cursor.execute(query, (home_team, away_team))
    h2h = cursor.fetchone()
    
    connection.close()
    return h2h

def predict_match(home_team, away_team, db_config):
    """
    Predice el resultado de un partido
    """
    # Cargar modelo y escalador
    model = joblib.load('../models/football_rf_advanced.pkl')
    scaler = joblib.load('../models/scaler.pkl')
    
    # Cargar metadatos para saber qué características usar
    with open('../models/model_metadata.json', 'r') as f:
        metadata = json.load(f)
    
    feature_names = metadata['features']
    
    # Obtener estadísticas de los equipos
    home_stats = get_team_recent_stats(home_team, db_config)
    away_stats = get_team_recent_stats(away_team, db_config)
    h2h_stats = get_h2h_stats(home_team, away_team, db_config)
    
    # Crear vector de características
    features = {}
    
    # Mapear estadísticas a características
    features['home_avg_goals_l5'] = home_stats['avg_goals'] or 1.5
    features['away_avg_goals_l5'] = away_stats['avg_goals'] or 1.5
    features['home_avg_shots_l5'] = home_stats['avg_shots'] or 10
    features['away_avg_shots_l5'] = away_stats['avg_shots'] or 10
    features['home_avg_shots_target_l5'] = home_stats['avg_shots_target'] or 4
    
    # Calcular características derivadas
    features['home_shots_accuracy'] = features['home_avg_shots_target_l5'] / features['home_avg_shots_l5']
    features['away_shots_accuracy'] = away_stats['avg_shots_target'] / away_stats['avg_shots'] if away_stats['avg_shots'] else 0.4
    
    features['home_goals_per_shot'] = features['home_avg_goals_l5'] / features['home_avg_shots_l5']
    features['away_goals_per_shot'] = features['away_avg_goals_l5'] / features['away_avg_shots_l5']
    
    # H2H
    features['h2h_matches'] = h2h_stats['total_matches'] or 0
    features['h2h_home_wins'] = h2h_stats['home_wins'] or 0
    
    # xG si está disponible
    features['home_xg'] = home_stats['avg_xg'] or features['home_avg_goals_l5']
    features['away_xg'] = away_stats['avg_xg'] or features['away_avg_goals_l5']
    features['home_xg_diff'] = 0
    features['away_xg_diff'] = 0
    
    # Valores por defecto para características faltantes
    features['corners_diff'] = 0
    features['home_aggression'] = 15
    features['away_aggression'] = 15
    
    # Crear array con las características en el orden correcto
    feature_vector = []
    for feature_name in feature_names:
        if feature_name in features:
            feature_vector.append(features[feature_name])
        else:
            feature_vector.append(0)  # Valor por defecto
    
    # Convertir a numpy array y escalar
    X = np.array(feature_vector).reshape(1, -1)
    X_scaled = scaler.transform(X)
    
    # Hacer predicción
    prediction = model.predict(X_scaled)[0]
    probabilities = model.predict_proba(X_scaled)[0]
    
    # Obtener clases
    classes = model.classes_
    prob_dict = dict(zip(classes, probabilities))
    
    # Análisis adicional
    feature_contributions = analyze_prediction(model, X_scaled, feature_names, features)
    
    result = {
        'prediction': prediction,
        'confidence': float(max(probabilities)),
        'probabilities': {
            'home_win': float(prob_dict.get('H', 0)),
            'draw': float(prob_dict.get('D', 0)),
            'away_win': float(prob_dict.get('A', 0))
        },
        'feature_analysis': feature_contributions,
        'team_stats': {
            'home': home_stats,
            'away': away_stats,
            'h2h': h2h_stats
        }
    }
    
    return result

def analyze_prediction(model, X, feature_names, feature_values):
    """
    Analiza qué características contribuyeron más a la predicción
    """
    # Obtener importancia de características para esta predicción
    importances = model.feature_importances_
    
    # Calcular contribución de cada característica
    contributions = []
    for i, (name, importance) in enumerate(zip(feature_names, importances)):
        if name in feature_values:
            contribution = {
                'feature': name,
                'value': feature_values[name],
                'importance': float(importance),
                'contribution': float(importance * X[0][i])
            }
            contributions.append(contribution)
    
    # Ordenar por contribución absoluta
    contributions.sort(key=lambda x: abs(x['contribution']), reverse=True)
    
    return contributions[:10]  # Top 10

# Punto de entrada
if __name__ == "__main__":
    if len(sys.argv) < 3:
        print(json.dumps({'error': 'Faltan parámetros'}))
        sys.exit(1)
    
    home_team = sys.argv[1]
    away_team = sys.argv[2]
    
    # Configuración de base de datos
    db_config = {
        'host': 'localhost',
        'user': 'tu_usuario',
        'password': 'tu_password',
        'database': 'tu_base_datos'
    }
    
    try:
        result = predict_match(home_team, away_team, db_config)
        print(json.dumps(result))
    except Exception as e:
        print(json.dumps({'error': str(e)}))