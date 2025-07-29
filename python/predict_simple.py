#!/usr/bin/env python3
"""
Script simple para hacer predicciones sin Random Forest
Para empezar sin necesidad de entrenar modelo
"""

import sys
import json
import numpy as np

def simple_prediction(features):
    """
    Modelo simple basado en estadísticas sin ML
    """
    
    # Extraer características principales
    home_win_rate = features[0]      # Win rate local
    home_draw_rate = features[1]     # Draw rate local  
    home_goals_for = features[2]     # Goles a favor local
    home_goals_against = features[3] # Goles en contra local
    
    away_win_rate = features[8]      # Win rate visitante
    away_draw_rate = features[9]     # Draw rate visitante
    away_goals_for = features[10]    # Goles a favor visitante
    away_goals_against = features[11] # Goles en contra visitante
    
    # Attack vs Defense
    home_attack_vs_away_defense = features[16]
    away_attack_vs_home_defense = features[17]
    
    # Cálculo simple de probabilidades
    
    # Ventaja de jugar en casa
    home_advantage = 0.1
    
    # Fuerza relativa basada en win rate
    home_strength = home_win_rate + home_advantage
    away_strength = away_win_rate
    
    # Ajustar por diferencial de goles
    goal_diff_factor = (home_goals_for - home_goals_against) - (away_goals_for - away_goals_against)
    goal_diff_factor = max(-2, min(2, goal_diff_factor)) / 10  # Normalizar
    
    # Probabilidades base
    total_strength = home_strength + away_strength + (home_draw_rate + away_draw_rate) / 2
    
    if total_strength <= 0:
        # Valores por defecto si no hay datos suficientes
        prob_home = 0.45
        prob_draw = 0.25
        prob_away = 0.30
    else:
        prob_home = (home_strength + goal_diff_factor + home_advantage) / (total_strength + 0.5)
        prob_away = away_strength / (total_strength + 0.5)
        prob_draw = 1 - prob_home - prob_away
    
    # Normalizar probabilidades
    total_prob = prob_home + prob_draw + prob_away
    if total_prob > 0:
        prob_home /= total_prob
        prob_draw /= total_prob  
        prob_away /= total_prob
    
    # Asegurar que estén en rango válido
    prob_home = max(0.05, min(0.90, prob_home))
    prob_away = max(0.05, min(0.90, prob_away))
    prob_draw = max(0.05, min(0.90, prob_draw))
    
    # Renormalizar
    total = prob_home + prob_draw + prob_away
    prob_home /= total
    prob_draw /= total
    prob_away /= total
    
    # Determinar predicción
    if prob_home > prob_draw and prob_home > prob_away:
        prediction = 'H'
        confidence = prob_home
    elif prob_away > prob_draw and prob_away > prob_home:
        prediction = 'A'  
        confidence = prob_away
    else:
        prediction = 'D'
        confidence = prob_draw
    
    return {
        'prediction': prediction,
        'confidence': float(confidence),
        'probabilities': {
            'home_win': float(prob_home),
            'draw': float(prob_draw),
            'away_win': float(prob_away)
        },
        'model_type': 'simple_statistical',
        'features_used': len(features)
    }

def main():
    if len(sys.argv) != 3:
        print(json.dumps({'error': 'Uso: python3 predict_simple.py model_path features_json'}))
        return
    
    try:
        # model_path no se usa en este modelo simple
        model_path = sys.argv[1]
        features_json = sys.argv[2]
        
        # Decodificar características
        features = json.loads(features_json)
        
        # Verificar que tenemos suficientes características
        if len(features) < 18:
            print(json.dumps({'error': f'Se requieren 18 características, recibidas: {len(features)}'}))
            return
        
        # Hacer predicción
        result = simple_prediction(features)
        
        # Devolver resultado como JSON
        print(json.dumps(result))
        
    except json.JSONDecodeError as e:
        print(json.dumps({'error': f'Error decodificando JSON: {str(e)}'}))
    except Exception as e:
        print(json.dumps({'error': f'Error en predicción: {str(e)}'}))

if __name__ == "__main__":
    main()