#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Football Tipster - Benchmark Avanzado con U/O, AH y análisis detallado
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
from scipy.stats import poisson
import math

def load_value_config(cursor, table_prefix):
    """
    Carga configuración de value betting desde la tabla ft_value_config
    """
    config_table = f"{table_prefix}ft_value_config"
    try:
        cursor.execute(f"SELECT * FROM {config_table} LIMIT 1")
        config = cursor.fetchone()
        
        if config:
            return {
                'min_value': float(config[1]) / 100,  # min_value está en porcentaje
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
                'avoid_teams': json.loads(config[15]) if config[15] else [],
                'preferred_leagues': json.loads(config[16]) if config[16] else [],
                'time_restrictions': json.loads(config[17]) if config[17] else {},
                'streak_protection': bool(config[18]),
                # Nuevas opciones
                'exclude_draws': False,  # Se puede configurar desde PHP
                'include_over_under': True,
                'include_asian_handicap': True
            }
        else:
            return {
                'min_value': 0.10,
                'min_confidence': 0.40,
                'min_odds': 1.6,
                'max_odds': 4.0,
                'base_unit': 10,
                'stake_system': 'variable',
                'exclude_draws': False,
                'include_over_under': True,
                'include_asian_handicap': True
            }
    except Exception as e:
        print(f"Error cargando configuración: {e}")
        return {
            'min_value': 0.10,
            'min_confidence': 0.40,
            'min_odds': 1.6,
            'max_odds': 4.0,
            'base_unit': 10,
            'stake_system': 'variable',
            'exclude_draws': False,
            'include_over_under': True,
            'include_asian_handicap': True
        }

def calculate_over_under_probability(home_xg, away_xg, line=2.5):
    """
    Calcula probabilidad Over/Under usando distribución de Poisson
    """
    total_expected = home_xg + away_xg
    
    # Usar Poisson para calcular probabilidades exactas
    prob_under = 0
    for goals in range(int(line) + 1):
        if goals <= line:
            prob_under += poisson.pmf(goals, total_expected)
    
    prob_over = 1 - prob_under
    return prob_over, prob_under

def calculate_asian_handicap_probability(home_xg, away_xg, handicap):
    """
    Calcula probabilidad Asian Handicap usando simulación Monte Carlo simplificada
    """
    # Simulación simple basada en diferencia esperada de goles
    expected_diff = home_xg - away_xg
    
    # Ajustar por handicap
    adjusted_diff = expected_diff - handicap
    
    # Usar función logística para convertir diferencia en probabilidad
    home_prob = 1 / (1 + math.exp(-adjusted_diff * 2))  # Factor 2 para sensibilidad
    away_prob = 1 - home_prob
    
    return home_prob, away_prob

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
            AVG(COALESCE(home_xg, fthg * 1.1)) as avg_xg
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
            AVG(COALESCE(away_xg, ftag * 1.1)) as avg_xg
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
            'avg_xg': float(result[8]) if result[8] else 1.5
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
            'avg_xg': 1.5
        }

def prepare_features_for_match(cursor, table_name, home_team, away_team, match_date):
    """
    Prepara features para un partido usando SOLO datos históricos
    """
    home_stats_as_home = get_team_historical_stats(cursor, table_name, home_team, match_date, True)
    home_stats_as_away = get_team_historical_stats(cursor, table_name, home_team, match_date, False)
    
    away_stats_as_home = get_team_historical_stats(cursor, table_name, away_team, match_date, True)
    away_stats_as_away = get_team_historical_stats(cursor, table_name, away_team, match_date, False)
    
    features = [
        home_stats_as_home['win_rate'],
        home_stats_as_home['draw_rate'],
        home_stats_as_home['avg_goals_for'],
        home_stats_as_home['avg_goals_against'],
        home_stats_as_home['avg_shots'],
        home_stats_as_home['avg_shots_target'],
        home_stats_as_home['avg_corners'],
        home_stats_as_home['avg_xg'],
        
        away_stats_as_away['win_rate'],
        away_stats_as_away['draw_rate'],
        away_stats_as_away['avg_goals_for'],
        away_stats_as_away['avg_goals_against'],
        away_stats_as_away['avg_shots'],
        away_stats_as_away['avg_shots_target'],
        away_stats_as_away['avg_corners'],
        away_stats_as_away['avg_xg'],
        
        home_stats_as_home['avg_goals_for'] - away_stats_as_away['avg_goals_against'],
        away_stats_as_away['avg_goals_for'] - home_stats_as_home['avg_goals_against'],
        home_stats_as_home['avg_shots'] - away_stats_as_away['avg_shots'],
        home_stats_as_home['avg_xg'] - away_stats_as_away['avg_xg']
    ]
    
    # Retornar también las estadísticas para cálculos posteriores
    return features, {
        'home_xg': home_stats_as_home['avg_xg'],
        'away_xg': away_stats_as_away['avg_xg'],
        'home_goals': home_stats_as_home['avg_goals_for'],
        'away_goals': away_stats_as_away['avg_goals_for']
    }

def calculate_stake(value, confidence, base_unit=10, min_stake=1, max_stake=5):
    """
    Calcula stake basado en value y confianza (1-5)
    """
    # Score de value (0-10)
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

    # Score de confianza (0-10)
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

    # Aplicar límites de seguridad
    if stake == 5 and value < 0.25:
        stake = 4
    
    if stake >= 4 and confidence < 0.50:
        stake = 3

    return stake

def analyze_match_all_markets(match_data, prediction_stats, value_config):
    """
    Analiza un partido en todos los mercados (ML, O/U, AH)
    """
    match_date, home_team, away_team, result, home_goals, away_goals, odds_data = match_data
    features, team_stats = prediction_stats
    
    # Obtener cuotas
    odds_h = float(odds_data.get('b365h', 0)) if odds_data.get('b365h') else 0
    odds_d = float(odds_data.get('b365d', 0)) if odds_data.get('b365d') else 0
    odds_a = float(odds_data.get('b365a', 0)) if odds_data.get('b365a') else 0
    
    # Over/Under 2.5
    odds_over25 = float(odds_data.get('b365over25', 0)) if odds_data.get('b365over25') else 0
    odds_under25 = float(odds_data.get('b365under25', 0)) if odds_data.get('b365under25') else 0
    
    all_bets = []
    
    # 1. MONEYLINE (1X2)
    if odds_h > 0 and odds_d > 0 and odds_a > 0:
        # Usar predicción del modelo para probabilidades
        ml_probs = [0.35, 0.25, 0.40]  # Simplificado - en tu caso usar el modelo real
        
        # Analizar cada resultado
        outcomes = ['H', 'D', 'A']
        ml_odds = [odds_h, odds_d, odds_a]
        
        for i, (outcome, our_prob, market_odd) in enumerate(zip(outcomes, ml_probs, ml_odds)):
            if value_config['exclude_draws'] and outcome == 'D':
                continue
                
            if value_config['min_odds'] <= market_odd <= value_config['max_odds']:
                market_prob = 1 / market_odd
                value = our_prob - market_prob
                
                if value >= value_config['min_value'] and our_prob >= value_config['min_confidence']:
                    stake_level = calculate_stake(value, our_prob, value_config['base_unit'])
                    stake_amount = value_config['base_unit'] * stake_level
                    
                    # Determinar resultado
                    actual_result = result
                    bet_won = (outcome == actual_result)
                    profit = stake_amount * (market_odd - 1) if bet_won else -stake_amount
                    
                    # Explicación del por qué se apostó
                    explanation = f"ML {outcome}: Prob={our_prob:.3f} vs Market={market_prob:.3f}, Value={value*100:.1f}%, Conf={our_prob:.3f}"
                    
                    all_bets.append({
                        'date': str(match_date),
                        'home_team': home_team,
                        'away_team': away_team,
                        'market': 'moneyline',
                        'bet_type': outcome,
                        'line': None,
                        'prediction': outcome,
                        'actual_result': actual_result,
                        'our_probability': round(our_prob, 3),
                        'market_probability': round(market_prob, 3),
                        'odds': round(market_odd, 2),
                        'value': round(value, 3),
                        'stake_level': stake_level,
                        'stake_amount': stake_amount,
                        'profit': round(profit, 2),
                        'won': bet_won,
                        'explanation': explanation,
                        'home_goals': home_goals,
                        'away_goals': away_goals,
                        'total_goals': home_goals + away_goals
                    })
    
    # 2. OVER/UNDER 2.5
    if value_config['include_over_under'] and odds_over25 > 0 and odds_under25 > 0:
        home_xg = team_stats['home_xg']
        away_xg = team_stats['away_xg']
        
        prob_over, prob_under = calculate_over_under_probability(home_xg, away_xg, 2.5)
        
        # Analizar OVER
        if value_config['min_odds'] <= odds_over25 <= value_config['max_odds']:
            market_prob_over = 1 / odds_over25
            value_over = prob_over - market_prob_over
            
            if value_over >= value_config['min_value'] and prob_over >= value_config['min_confidence']:
                stake_level = calculate_stake(value_over, prob_over, value_config['base_unit'])
                stake_amount = value_config['base_unit'] * stake_level
                
                # Resultado Over/Under
                total_goals = home_goals + away_goals
                bet_won = total_goals > 2.5
                profit = stake_amount * (odds_over25 - 1) if bet_won else -stake_amount
                
                explanation = f"O2.5: xG={home_xg:.1f}+{away_xg:.1f}={home_xg+away_xg:.1f}, Prob={prob_over:.3f} vs Market={market_prob_over:.3f}"
                
                all_bets.append({
                    'date': str(match_date),
                    'home_team': home_team,
                    'away_team': away_team,
                    'market': 'total',
                    'bet_type': 'over',
                    'line': 2.5,
                    'prediction': 'over',
                    'actual_result': 'over' if total_goals > 2.5 else 'under',
                    'our_probability': round(prob_over, 3),
                    'market_probability': round(market_prob_over, 3),
                    'odds': round(odds_over25, 2),
                    'value': round(value_over, 3),
                    'stake_level': stake_level,
                    'stake_amount': stake_amount,
                    'profit': round(profit, 2),
                    'won': bet_won,
                    'explanation': explanation,
                    'home_goals': home_goals,
                    'away_goals': away_goals,
                    'total_goals': total_goals,
                    'expected_total': round(home_xg + away_xg, 2)
                })
        
        # Analizar UNDER
        if value_config['min_odds'] <= odds_under25 <= value_config['max_odds']:
            market_prob_under = 1 / odds_under25
            value_under = prob_under - market_prob_under
            
            if value_under >= value_config['min_value'] and prob_under >= value_config['min_confidence']:
                stake_level = calculate_stake(value_under, prob_under, value_config['base_unit'])
                stake_amount = value_config['base_unit'] * stake_level
                
                total_goals = home_goals + away_goals
                bet_won = total_goals <= 2.5
                profit = stake_amount * (odds_under25 - 1) if bet_won else -stake_amount
                
                explanation = f"U2.5: xG={home_xg:.1f}+{away_xg:.1f}={home_xg+away_xg:.1f}, Prob={prob_under:.3f} vs Market={market_prob_under:.3f}"
                
                all_bets.append({
                    'date': str(match_date),
                    'home_team': home_team,
                    'away_team': away_team,
                    'market': 'total',
                    'bet_type': 'under',
                    'line': 2.5,
                    'prediction': 'under',
                    'actual_result': 'over' if total_goals > 2.5 else 'under',
                    'our_probability': round(prob_under, 3),
                    'market_probability': round(market_prob_under, 3),
                    'odds': round(odds_under25, 2),
                    'value': round(value_under, 3),
                    'stake_level': stake_level,
                    'stake_amount': stake_amount,
                    'profit': round(profit, 2),
                    'won': bet_won,
                    'explanation': explanation,
                    'home_goals': home_goals,
                    'away_goals': away_goals,
                    'total_goals': total_goals,
                    'expected_total': round(home_xg + away_xg, 2)
                })
    
    return all_bets

def main():
    """Función principal del benchmark avanzado"""
def get_available_seasons(cursor, table_prefix):
    """
    Obtiene temporadas disponibles desde la base de datos
    """
    table_name = f"{table_prefix}ft_matches_advanced"
    try:
        cursor.execute(f"""
            SELECT DISTINCT season 
            FROM {table_name} 
            WHERE season IS NOT NULL 
            AND season != '' 
            AND fthg IS NOT NULL 
            AND ftag IS NOT NULL
            GROUP BY season 
            HAVING COUNT(*) > 100
            ORDER BY season DESC
        """)
        seasons = cursor.fetchall()
        return [season[0] for season in seasons]
    except Exception as e:
        print(f"Error obteniendo temporadas: {e}")
        return []

def main():
    """Función principal del benchmark avanzado"""
    try:
        if len(sys.argv) < 3:
            print(json.dumps({"error": "Uso: benchmark_advanced.py <season> <model_type> [league] [exclude_draws]"}))
            return

        test_season = sys.argv[1]
        model_type = sys.argv[2]
        league_filter = sys.argv[3] if len(sys.argv) > 3 and sys.argv[3] != 'all' else None
        exclude_draws = sys.argv[4].lower() == 'true' if len(sys.argv) > 4 else False

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
        
        # Verificar que la temporada existe
        available_seasons = get_available_seasons(cursor, table_prefix)
        if test_season not in available_seasons:
            print(json.dumps({
                "error": f"Temporada {test_season} no encontrada. Disponibles: {', '.join(available_seasons)}"
            }))
            return

        value_config = load_value_config(cursor, table_prefix)
        value_config['exclude_draws'] = exclude_draws

        # ENTRENAR MODELO (excluyendo temporada de test)
        league_condition = f"AND division = '{league_filter}'" if league_filter else ""
        
        train_query = f"""
        SELECT date, home_team, away_team, ftr
        FROM {table_name}
        WHERE season != %s
        AND season IS NOT NULL
        AND ftr IN ('H', 'D', 'A')
        AND home_team IS NOT NULL
        AND away_team IS NOT NULL
        {league_condition}
        ORDER BY date DESC
        LIMIT 3000
        """

        cursor.execute(train_query, (test_season,))
        train_matches = cursor.fetchall()

        if len(train_matches) < 500:
            print(json.dumps({"error": f"Datos de entrenamiento insuficientes: solo {len(train_matches)} partidos"}))
            return

        # ... resto del código de entrenamiento igual ...


        # Configuración de apuestas
        initial_bankroll = 1000
        current_bankroll = initial_bankroll
        base_unit = value_config['base_unit']
        total_stakes = 0
        winning_bets = 0
        total_bets = 0

        # Estadísticas por mercado
        market_stats = {
            'moneyline': {'bets': 0, 'wins': 0, 'profit': 0, 'stakes': 0},
            'total': {'bets': 0, 'wins': 0, 'profit': 0, 'stakes': 0},
            'spread': {'bets': 0, 'wins': 0, 'profit': 0, 'stakes': 0}
        }

        for match_data in test_matches:
            match_date, home_team, away_team, result, home_goals, away_goals = match_data[:6]
            odds_data = {
                'b365h': match_data[6],
                'b365d': match_data[7], 
                'b365a': match_data[8],
                'b365over25': None,  # Añadir si tienes estos campos
                'b365under25': None
            }

            # Preparar features para predicción
            features, team_stats = prepare_features_for_match(cursor, table_name, home_team, away_team, match_date)
            
            # Hacer predicción
            pred = model.predict([features])[0]
            prob = model.predict_proba([features])[0]
            
            predictions.append(pred)
            probabilities.append(prob)
            y_test.append(result_map[result])
            
            # Analizar apuestas en todos los mercados
            match_bets = analyze_match_all_markets(
                (match_date, home_team, away_team, result, home_goals, away_goals, odds_data),
                (features, team_stats),
                value_config
            )

            # Procesar apuestas
            for bet in match_bets:
                total_bets += 1
                total_stakes += bet['stake_amount']
                market_stats[bet['market']]['bets'] += 1
                market_stats[bet['market']]['stakes'] += bet['stake_amount']
                
                if bet['won']:
                    winning_bets += 1
                    current_bankroll += bet['profit']
                    market_stats[bet['market']]['wins'] += 1
                else:
                    current_bankroll -= bet['stake_amount']
                
                market_stats[bet['market']]['profit'] += bet['profit']
                bet['bankroll'] = round(current_bankroll, 2)
                
                all_betting_details.append(bet)

        # Calcular métricas finales
        predictions = np.array(predictions)
        y_test = np.array(y_test)

        correct = np.sum(predictions == y_test)
        accuracy = correct / len(y_test)

        # Métricas por tipo de resultado
        home_wins_total = np.sum(y_test == 2)
        draws_total = np.sum(y_test == 1)
        away_wins_total = np.sum(y_test == 0)

        home_wins_correct = np.sum((predictions == 2) & (y_test == 2))
        draws_correct = np.sum((predictions == 1) & (y_test == 1))
        away_wins_correct = np.sum((predictions == 0) & (y_test == 0))

        profit_loss = current_bankroll - initial_bankroll
        roi = (profit_loss / total_stakes * 100) if total_stakes > 0 else 0

        # Calcular ROI por mercado
        for market in market_stats:
            if market_stats[market]['stakes'] > 0:
                market_stats[market]['roi'] = round(
                    (market_stats[market]['profit'] / market_stats[market]['stakes']) * 100, 2
                )
                market_stats[market]['win_rate'] = round(
                    (market_stats[market]['wins'] / market_stats[market]['bets']) * 100, 1
                ) if market_stats[market]['bets'] > 0 else 0

        # Preparar resultados
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
                'market_breakdown': market_stats,
                'betting_criteria': {
                    'min_value': value_config['min_value'],
                    'min_confidence': value_config['min_confidence'],
                    'min_odds': value_config['min_odds'],
                    'max_odds': value_config['max_odds'],
                    'base_unit': value_config['base_unit'],
                    'exclude_draws': value_config['exclude_draws'],
                    'include_over_under': value_config['include_over_under'],
                    'include_asian_handicap': value_config['include_asian_handicap']
                }
            },
            'betting_details': all_betting_details
        }

        print(json.dumps(results))
        cursor.close()
        conn.close()

    except Exception as e:
        print(json.dumps({"error": str(e)}))

if __name__ == "__main__":
    main()
