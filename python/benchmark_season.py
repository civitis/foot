#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Football Tipster - Benchmark con sistema de stake variable (1-5)
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



def calculate_stake(value, confidence, min_stake=1, max_stake=5):
    """
    Calcula el stake (1-5) basado en value y confianza
    
    Sistema:
    - Value es el factor principal (60% del peso)
    - Confianza es el factor secundario (40% del peso)
    
    Stake 1: Value 10-15% y Confianza 40-45%
    Stake 2: Value 15-20% y Confianza 45-50%
    Stake 3: Value 20-25% y Confianza 50-55%
    Stake 4: Value 25-30% y Confianza 55-60%
    Stake 5: Value >30% y Confianza >60%
    """
    
    # Calcular score de value (0-10)
    if value < 0.10:
        value_score = 0
    elif value < 0.15:
        value_score = 2
    elif value < 0.20:
        value_score = 4
    elif value < 0.25:
        value_score = 6
    elif value < 0.30:
        value_score = 8
    else:
        value_score = 10
    
    # Calcular score de confianza (0-10)
    if confidence < 0.40:
        confidence_score = 0
    elif confidence < 0.45:
        confidence_score = 2
    elif confidence < 0.50:
        confidence_score = 4
    elif confidence < 0.55:
        confidence_score = 6
    elif confidence < 0.60:
        confidence_score = 8
    else:
        confidence_score = 10
    
    # Combinar scores (60% value, 40% confianza)
    combined_score = (value_score * 0.6) + (confidence_score * 0.4)
    
    # Convertir a stake 1-5
    if combined_score < 2:
        stake = 1
    elif combined_score < 4:
        stake = 2
    elif combined_score < 6:
        stake = 3
    elif combined_score < 8:
        stake = 4
    else:
        stake = 5
    
    # Aplicar límites adicionales de seguridad
    # No apostar stake 5 a cuotas muy altas (riesgo)
    if stake == 5 and value < 0.25:
        stake = 4
    
    # No apostar stake alto si la confianza es muy baja
    if stake >= 4 and confidence < 0.50:
        stake = 3
    
    return stake

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

def load_value_config(cursor, table_prefix):
    """
    Carga la configuración de value betting desde la base de datos
    """
    config_table = f"{table_prefix}ft_value_config"
    
    try:
        cursor.execute(f"SELECT * FROM {config_table} LIMIT 1")
        config = cursor.fetchone()
        
        if config:
            return {
                'min_value': float(config[1]) / 100,  # Convertir porcentaje a decimal
                'min_confidence': float(config[2]),
                'max_stake_percentage': float(config[3]) / 100,
                'kelly_fraction': float(config[4]),
                'markets_enabled': config[5].split(',') if config[5] else ['moneyline'],
                'auto_analyze': bool(config[6]),
                'min_odds': float(config[7]),
                'max_odds': float(config[8]),
                'stake_system': config[9],
                'base_unit': float(config[10]),
                'max_daily_bets': int(config[11]),
                'stop_loss_daily': float(config[12]),
                'stop_loss_weekly': float(config[13]),
                'min_bankroll_percentage': float(config[14]) / 100,
                'streak_protection': bool(config[18])
            }
        else:
            # Valores por defecto si no hay configuración
            return {
                'min_value': 0.10,
                'min_confidence': 0.40,
                'min_odds': 1.6,
                'max_odds': 4.0,
                'base_unit': 10,
                'stake_system': 'variable'
            }
    except:
        # Valores por defecto en caso de error
        return {
            'min_value': 0.10,
            'min_confidence': 0.40,
            'min_odds': 1.6,
            'max_odds': 4.0,
            'base_unit': 10,
            'stake_system': 'variable'
        }


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
        value_config = load_value_config(cursor, table_prefix)
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
        betting_details = []
        
        # Configuración de apuestas con stake variable
        initial_bankroll = 1000
        current_bankroll = initial_bankroll
        base_unit = 10  # Unidad base de apuesta
        total_stakes = 0
        winning_bets = 0
        total_bets = 0
        
        # Criterios de apuesta
        # Usar la configuración en lugar de valores hardcodeados
        min_value = value_config['min_value']
        min_confidence = value_config['min_confidence']
        min_odds = value_config['min_odds']
        max_odds = value_config['max_odds']
        base_unit = value_config['base_unit']
        
        # Estadísticas por stake
        stakes_distribution = {1: 0, 2: 0, 3: 0, 4: 0, 5: 0}
        stakes_roi = {1: 0, 2: 0, 3: 0, 4: 0, 5: 0}
        stakes_profit = {1: 0, 2: 0, 3: 0, 4: 0, 5: 0}
        
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
                odds = [float(odds_a), float(odds_d), float(odds_h)]
                
                # Buscar la mejor apuesta
                best_value = -1
                best_bet = None
                best_odd = None
                best_confidence = 0
                
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
                                best_confidence = our_prob
                
                # Realizar apuesta si hay value
                if best_bet is not None:
                    # Calcular stake variable (1-5)
                    stake_level = calculate_stake(best_value, best_confidence)
                    stake_amount = base_unit * stake_level
                    
                    total_bets += 1
                    total_stakes += stake_amount
                    stakes_distribution[stake_level] += 1
                    
                    bet_won = (best_bet == result_map[result])
                    
                    if bet_won:
                        profit = stake_amount * (best_odd - 1)
                        current_bankroll += profit
                        winning_bets += 1
                        stakes_profit[stake_level] += profit
                    else:
                        profit = -stake_amount
                        current_bankroll -= stake_amount
                        stakes_profit[stake_level] -= stake_amount
                    
                    # Guardar detalles
                    betting_details.append({
                        'date': str(match_date),
                        'home_team': home_team,
                        'away_team': away_team,
                        'prediction': ['A', 'D', 'H'][best_bet],
                        'actual_result': result,
                        'odds': round(best_odd, 2),
                        'stake_level': stake_level,
                        'stake_amount': stake_amount,
                        'confidence': round(best_confidence, 3),
                        'value': round(best_value, 3),
                        'profit': round(profit, 2),
                        'won': bet_won,
                        'bankroll': round(current_bankroll, 2)
                    })
        
        # Calcular ROI por stake
        for stake in range(1, 6):
            if stakes_distribution[stake] > 0:
                stakes_roi[stake] = (stakes_profit[stake] / (stakes_distribution[stake] * base_unit * stake)) * 100
        
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
        roi = (profit_loss / total_stakes * 100) if total_stakes > 0 else 0
        
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
                'total_stakes': round(total_stakes, 2),
                'avg_stake': round(total_stakes / total_bets, 2) if total_bets > 0 else 0,
                'betting_criteria': {
                    'min_value': min_value,
                    'min_confidence': min_confidence,
                    'min_odds': min_odds,
                    'max_odds': max_odds,
                    'base_unit': base_unit
                },
                'stakes_distribution': stakes_distribution,
                'stakes_roi': {k: round(v, 1) for k, v in stakes_roi.items()},
                'stakes_profit': {k: round(v, 2) for k, v in stakes_profit.items()}
            },
            'betting_details': betting_details
        }
        
        print(json.dumps(results))
        
        cursor.close()
        conn.close()
        
    except Exception as e:
        print(json.dumps({"error": str(e)}))

if __name__ == "__main__":
    main()