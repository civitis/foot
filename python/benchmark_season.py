#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Football Tipster - Benchmark con registro detallado de apuestas
"""

import sys
import json
import numpy as np
import pandas as pd
import warnings
warnings.filterwarnings('ignore')

# Agregar path para librerías
plugin_libs = '/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs'
if plugin_libs not in sys.path:
    sys.path.insert(0, plugin_libs)

import mysql.connector
from sklearn.ensemble import RandomForestClassifier

def get_team_historical_stats(cursor, table_name, team, date, is_home=True, last_n_games=10):
    """
    Obtiene estadísticas históricas de un equipo ANTES de una fecha
    """
    if is_home:
        query = f"""
        SELECT 
            COUNT(*) as matches,
            SUM(CASE WHEN ftr = 'H' THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN ftr = 'D' THEN 1 ELSE 0 END) as draws,
            AVG(fthg) as avg_goals_for,
            AVG(ftag) as avg_goals_against,
            AVG(hs) as avg_shots,
            AVG(hst) as avg_shots_target,
            AVG(hc) as avg_corners,
            AVG(hf) as avg_fouls
        FROM {table_name}
        WHERE home_team = %s 
        AND date < %s
        AND date >= DATE_SUB(%s, INTERVAL 365 DAY)
        AND ftr IS NOT NULL
        ORDER BY date DESC
        LIMIT %s
        """
    else:
        query = f"""
        SELECT 
            COUNT(*) as matches,
            SUM(CASE WHEN ftr = 'A' THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN ftr = 'D' THEN 1 ELSE 0 END) as draws,
            AVG(ftag) as avg_goals_for,
            AVG(fthg) as avg_goals_against,
            AVG(as_shots) as avg_shots,
            AVG(ast) as avg_shots_target,
            AVG(ac) as avg_corners,
            AVG(af) as avg_fouls
        FROM {table_name}
        WHERE away_team = %s 
        AND date < %s
        AND date >= DATE_SUB(%s, INTERVAL 365 DAY)
        AND ftr IS NOT NULL
        ORDER BY date DESC
        LIMIT %s
        """
    
    cursor.execute(query, (team, date, date, last_n_games))
    result = cursor.fetchone()
    
    if result and result[0] > 0:
        return {
            'matches': int(result[0]),
            'win_rate': float(result[1]) / result[0] if result[0] > 0 else 0.33,
            'draw_rate': float(result[2]) / result[0] if result[0] > 0 else 0.33,
            'avg_goals_for': float(result[3]) if result[3] else 1.2,
            'avg_goals_against': float(result[4]) if result[4] else 1.2,
            'avg_shots': float(result[5]) if result[5] else 10.0,
            'avg_shots_target': float(result[6]) if result[6] else 4.0,
            'avg_corners': float(result[7]) if result[7] else 5.0,
            'avg_fouls': float(result[8]) if result[8] else 12.0
        }
    else:
        return {
            'matches': 0,
            'win_rate': 0.33,
            'draw_rate': 0.33,
            'avg_goals_for': 1.2,
            'avg_goals_against': 1.2,
            'avg_shots': 10.0,
            'avg_shots_target': 4.0,
            'avg_corners': 5.0,
            'avg_fouls': 12.0
        }

def get_recent_form(cursor, table_name, team, date, last_n=5):
    """
    Obtiene la forma reciente del equipo
    """
    query = f"""
    SELECT 
        CASE 
            WHEN home_team = %s AND ftr = 'H' THEN 3
            WHEN away_team = %s AND ftr = 'A' THEN 3
            WHEN ftr = 'D' THEN 1
            ELSE 0
        END as points
    FROM {table_name}
    WHERE (home_team = %s OR away_team = %s)
    AND date < %s
    AND ftr IS NOT NULL
    ORDER BY date DESC
    LIMIT %s
    """
    
    cursor.execute(query, (team, team, team, team, date, last_n))
    results = cursor.fetchall()
    
    if results:
        points = [r[0] for r in results]
        return sum(points) / len(points)
    return 1.0

def prepare_features_for_match(cursor, table_name, home_team, away_team, match_date):
    """
    Prepara features para un partido usando SOLO datos históricos
    """
    home_stats_as_home = get_team_historical_stats(cursor, table_name, home_team, match_date, True)
    home_stats_as_away = get_team_historical_stats(cursor, table_name, home_team, match_date, False)
    home_form = get_recent_form(cursor, table_name, home_team, match_date)
    
    away_stats_as_home = get_team_historical_stats(cursor, table_name, away_team, match_date, True)
    away_stats_as_away = get_team_historical_stats(cursor, table_name, away_team, match_date, False)
    away_form = get_recent_form(cursor, table_name, away_team, match_date)
    
    features = [
        home_stats_as_home['win_rate'],
        home_stats_as_home['draw_rate'],
        home_stats_as_home['avg_goals_for'],
        home_stats_as_home['avg_goals_against'],
        home_stats_as_home['avg_shots'],
        home_stats_as_home['avg_shots_target'],
        home_stats_as_home['avg_corners'],
        home_form,
        
        away_stats_as_away['win_rate'],
        away_stats_as_away['draw_rate'],
        away_stats_as_away['avg_goals_for'],
        away_stats_as_away['avg_goals_against'],
        away_stats_as_away['avg_shots'],
        away_stats_as_away['avg_shots_target'],
        away_stats_as_away['avg_corners'],
        away_form,
        
        home_stats_as_home['avg_goals_for'] - away_stats_as_away['avg_goals_against'],
        away_stats_as_away['avg_goals_for'] - home_stats_as_home['avg_goals_against'],
        home_stats_as_home['avg_shots'] - away_stats_as_away['avg_shots'],
        home_form - away_form
    ]
    
    return features

def main():
    """Función principal del benchmark"""
    try:
        if len(sys.argv) < 3:
            print(json.dumps({"error": "Uso: benchmark_season.py <temporada> <tipo_modelo> [liga]"}))
            return
        
        test_season = sys.argv[1]
        model_type = sys.argv[2]
        league_filter = sys.argv[3] if len(sys.argv) > 3 and sys.argv[3] != 'all' else None
        
        # Cargar configuración
        with open('db_config.json', 'r') as f:
            db_config = json.load(f)
        
        table_prefix = db_config.get('table_prefix', 'PP0Fhoci_')
        table_name = f"{table_prefix}ft_matches_advanced"
        
        # Conectar a BD
        host = db_config['host']
        port = 3306
        if ':' in host:
            host_parts = host.split(':')
            host = host_parts[0]
            port = int(host_parts[1])
        
        conn = mysql.connector.connect(
            host=host,
            port=port,
            database=db_config['database'],
            user=db_config['user'],
            password=db_config['password']
        )
        cursor = conn.cursor()
        
        # Obtener rango de fechas
        date_query = f"""
        SELECT MIN(date) as min_date, MAX(date) as max_date
        FROM {table_name}
        WHERE season = %s
        AND fthg IS NOT NULL
        """
        cursor.execute(date_query, (test_season,))
        date_result = cursor.fetchone()
        
        if not date_result or not date_result[0]:
            print(json.dumps({"error": f"No se encontraron datos para la temporada {test_season}"}))
            return
        
        test_start_date = date_result[0]
        
        # ENTRENAMIENTO
        league_condition = f"AND division = '{league_filter}'" if league_filter else ""
        
        train_query = f"""
        SELECT 
            date, home_team, away_team, ftr
        FROM {table_name}
        WHERE date < %s
        AND ftr IN ('H', 'D', 'A')
        AND home_team IS NOT NULL 
        AND away_team IS NOT NULL
        {league_condition}
        ORDER BY date DESC
        LIMIT 5000
        """
        
        cursor.execute(train_query, (test_start_date,))
        train_matches = cursor.fetchall()
        
        if len(train_matches) < 500:
            print(json.dumps({"error": f"Datos de entrenamiento insuficientes: solo {len(train_matches)} partidos"}))
            return
        
        # Preparar features de entrenamiento
        X_train = []
        y_train = []
        result_map = {'H': 2, 'D': 1, 'A': 0}
        
        for match_date, home_team, away_team, result in train_matches:
            features = prepare_features_for_match(cursor, table_name, home_team, away_team, match_date)
            X_train.append(features)
            y_train.append(result_map[result])
        
        X_train = np.array(X_train)
        y_train = np.array(y_train)
        
        # Entrenar modelo
        model = RandomForestClassifier(
            n_estimators=100,
            max_depth=10,
            min_samples_split=20,
            min_samples_leaf=10,
            random_state=42,
            n_jobs=-1
        )
        model.fit(X_train, y_train)
        
        # EVALUACIÓN
        test_query = f"""
        SELECT 
            date, home_team, away_team, ftr,
            COALESCE(b365h, bwh) as odds_home,
            COALESCE(b365d, bwd) as odds_draw,
            COALESCE(b365a, bwa) as odds_away
        FROM {table_name}
        WHERE season = %s
        AND ftr IN ('H', 'D', 'A')
        AND home_team IS NOT NULL 
        AND away_team IS NOT NULL
        {league_condition}
        ORDER BY date
        """
        
        cursor.execute(test_query, (test_season,))
        test_matches = cursor.fetchall()
        
        if not test_matches:
            print(json.dumps({"error": f"No hay partidos para evaluar en {test_season}"}))
            return
        
        # Evaluar predicciones y registrar apuestas
        predictions = []
        probabilities = []
        y_test = []
        betting_details = []  # Nuevo: detalles de cada apuesta
        
        # Configuración de apuestas más estricta
        initial_bankroll = 1000
        current_bankroll = initial_bankroll
        stake = 10
        total_bets = 0
        winning_bets = 0
        
        # Criterios más estrictos
        min_value = 0.15      # Requiere 15% de value (más estricto)
        min_confidence = 0.45  # Confianza mínima del 45%
        max_odds = 3.5        # No apostar a cuotas superiores a 3.5
        min_odds = 1.7        # No apostar a cuotas inferiores a 1.7
        
        for match_date, home_team, away_team, result, odds_h, odds_d, odds_a in test_matches:
            # Preparar features
            features = prepare_features_for_match(cursor, table_name, home_team, away_team, match_date)
            
            # Predecir
            pred = model.predict([features])[0]
            prob = model.predict_proba([features])[0]
            
            predictions.append(pred)
            probabilities.append(prob)
            y_test.append(result_map[result])
            
            # Procesar apuesta
            if odds_h and odds_d and odds_a:
                odds = [float(odds_a), float(odds_d), float(odds_h)]  # 0=Away, 1=Draw, 2=Home
                
                # Buscar la mejor apuesta
                best_value = -1
                best_bet = None
                best_odd = None
                
                for outcome in range(3):
                    if min_odds <= odds[outcome] <= max_odds:
                        our_prob = prob[outcome]
                        if our_prob >= min_confidence:
                            market_prob = 1 / odds[outcome]
                            value = our_prob - market_prob
                            
                            if value > best_value and value >= min_value:
                                best_value = value
                                best_bet = outcome
                                best_odd = odds[outcome]
                
                # Registrar apuesta si hay value
                if best_bet is not None:
                    total_bets += 1
                    bet_won = (best_bet == result_map[result])
                    
                    if bet_won:
                        profit = stake * (best_odd - 1)
                        current_bankroll += profit
                        winning_bets += 1
                    else:
                        profit = -stake
                        current_bankroll -= stake
                    
                    # Guardar detalles de la apuesta
                    betting_details.append({
                        'date': str(match_date),
                        'home_team': home_team,
                        'away_team': away_team,
                        'prediction': ['A', 'D', 'H'][best_bet],
                        'actual_result': result,
                        'odds': round(best_odd, 2),
                        'stake': stake,
                        'confidence': round(prob[best_bet], 3),
                        'value': round(best_value, 3),
                        'profit': round(profit, 2),
                        'won': bet_won,
                        'bankroll': round(current_bankroll, 2)
                    })
        
        predictions = np.array(predictions)
        y_test = np.array(y_test)
        
        # Calcular métricas
        correct = np.sum(predictions == y_test)
        accuracy = correct / len(y_test)
        
        # Métricas por tipo
        home_wins_total = np.sum(y_test == 2)
        draws_total = np.sum(y_test == 1)
        away_wins_total = np.sum(y_test == 0)
        
        home_wins_correct = np.sum((predictions == 2) & (y_test == 2))
        draws_correct = np.sum((predictions == 1) & (y_test == 1))
        away_wins_correct = np.sum((predictions == 0) & (y_test == 0))
        
        profit_loss = current_bankroll - initial_bankroll
        roi = (profit_loss / (total_bets * stake) * 100) if total_bets > 0 else 0
        
        # Resultados
        results = {
            'test_metrics': {
                'overall_accuracy': float(accuracy),
                'total_predictions': len(predictions),
                'correct_predictions': int(correct),
                'home_wins': {
                    'total': int(home_wins_total),
                    'correct': int(home_wins_correct),
                    'accuracy': float(home_wins_correct / home_wins_total) if home_wins_total > 0 else 0
                },
                'draws': {
                    'total': int(draws_total),
                    'correct': int(draws_correct),
                    'accuracy': float(draws_correct / draws_total) if draws_total > 0 else 0
                },
                'away_wins': {
                    'total': int(away_wins_total),
                    'correct': int(away_wins_correct),
                    'accuracy': float(away_wins_correct / away_wins_total) if away_wins_total > 0 else 0
                }
            },
            'value_betting': {
                'initial_bankroll': initial_bankroll,
                'final_bankroll': round(current_bankroll, 2),
                'total_bets': total_bets,
                'winning_bets': winning_bets,
                'roi': round(roi, 1),
                'profit_loss': round(profit_loss, 2),
                'win_rate': float(winning_bets / total_bets) if total_bets > 0 else 0,
                'stake_per_bet': stake,
                'betting_criteria': {
                    'min_value': min_value,
                    'min_confidence': min_confidence,
                    'min_odds': min_odds,
                    'max_odds': max_odds
                }
            },
            'betting_details': betting_details  # Nuevo: incluir detalles
        }
        
        print(json.dumps(results))
        
        cursor.close()
        conn.close()
        
    except Exception as e:
        print(json.dumps({"error": str(e)}))

if __name__ == "__main__":
    main()