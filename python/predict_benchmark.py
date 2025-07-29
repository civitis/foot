#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Football Tipster - Benchmark por temporada
Entrena modelo excluyendo una temporada y evalúa predicciones
"""

import sys
import json
import pickle
import numpy as np
import pandas as pd
from datetime import datetime
import warnings
warnings.filterwarnings('ignore')

# Agregar path para librerías
plugin_libs = '/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs'
if plugin_libs not in sys.path:
    sys.path.insert(0, plugin_libs)

import mysql.connector
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split

def main():
    """Función principal del benchmark"""
    if len(sys.argv) < 3:
        print("ERROR: Uso: benchmark_season.py <temporada> <tipo_modelo>")
        sys.exit(1)
    
    exclude_season = sys.argv[1]
    model_type = sys.argv[2]  # 'with_xg' o 'without_xg'
    
    print("🏆 INICIANDO BENCHMARK")
    print("=" * 50)
    print(f"🚫 Excluyendo temporada: {exclude_season}")
    print(f"🤖 Tipo de modelo: {model_type}")
    
    try:
        # Cargar configuración
        with open('db_config.json', 'r') as f:
            db_config = json.load(f)
        
        # Obtener prefijo de tabla
        table_prefix = db_config.get('table_prefix', 'wp_')
        
        # Conectar a BD
        print(f"🔌 Conectando a la base de datos...")
        
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
        print("✅ Conexión establecida")
        
        # PASO 1: ENTRENAR MODELO
        print(f"\n📊 ENTRENANDO MODELO (sin {exclude_season})...")
        
        # Cargar datos de entrenamiento
        train_query = f"""
        SELECT * FROM {table_prefix}ft_matches_advanced
        WHERE season != %s
        AND season IS NOT NULL
        AND fthg IS NOT NULL
        AND ftag IS NOT NULL
        AND hs IS NOT NULL
        AND as_shots IS NOT NULL
        {f"AND division = '{league_filter}'" if league_filter else ""}
        ORDER BY date DESC
        LIMIT 5000
        """
        
        cursor = conn.cursor(dictionary=True)
        cursor.execute(train_query, (exclude_season,))
        training_data = cursor.fetchall()
        
        if not training_data:
            print("❌ No hay datos de entrenamiento")
            sys.exit(1)
        
        print(f"✅ {len(training_data)} partidos para entrenamiento")
        
        # Preparar datos de entrenamiento
        df_train = pd.DataFrame(training_data)
        X_train, y_train = prepare_features(df_train, model_type)
        
        print(f"📐 Shape entrenamiento: X={X_train.shape}, y={y_train.shape}")
        
        # Entrenar modelo
        print("🤖 Entrenando Random Forest...")
        model = RandomForestClassifier(
            n_estimators=100,
            max_depth=10,
            min_samples_split=5,
            random_state=42,
            n_jobs=-1
        )
        
        # Split para validación
        X_tr, X_val, y_tr, y_val = train_test_split(X_train, y_train, test_size=0.2, random_state=42)
        model.fit(X_tr, y_tr)
        
        train_acc = model.score(X_val, y_val)
        print(f"📈 Precisión validación: {train_acc:.4f}")
        
        # PASO 2: PREDECIR TEMPORADA DE TEST
        print(f"\n🎯 PREDICIENDO TEMPORADA {exclude_season}...")
        
        # Cargar datos de test
        test_query = f"""
        SELECT * FROM {table_prefix}ft_matches_advanced
        WHERE season = %s
        AND fthg IS NOT NULL
        AND ftag IS NOT NULL
        AND hs IS NOT NULL
        AND as_shots IS NOT NULL
        {f"AND division = '{league_filter}'" if league_filter else ""}
        ORDER BY date
        """
        
        cursor.execute(test_query, (exclude_season,))
        test_data = cursor.fetchall()
        
        if not test_data:
            print(f"❌ No hay datos para la temporada {exclude_season}")
            cursor.close()
            conn.close()
            sys.exit(1)
        
        print(f"✅ {len(test_data)} partidos para evaluar")
        
        # Preparar datos de test
        df_test = pd.DataFrame(test_data)
        X_test, y_test = prepare_features(df_test, model_type)
        
        print(f"📐 Shape test: X={X_test.shape}, y={y_test.shape}")
        
        # Hacer predicciones
        predictions = model.predict(X_test)
        probabilities = model.predict_proba(X_test)
        
        # Calcular métricas
        correct = np.sum(predictions == y_test)
        accuracy = correct / len(y_test) if len(y_test) > 0 else 0
        
        print(f"✅ Predicciones completadas")
        print(f"📊 Precisión: {accuracy:.4f}")
        print(f"✅ Correctas: {correct} / {len(y_test)}")
        
        # Preparar resultados detallados
        results = {
            'test_metrics': {
                'overall_accuracy': float(accuracy),
                'total_predictions': len(predictions),
                'correct_predictions': int(correct),
                'home_wins': calculate_result_metrics(y_test, predictions, 2),
                'draws': calculate_result_metrics(y_test, predictions, 1),
                'away_wins': calculate_result_metrics(y_test, predictions, 0),
                'high_confidence': calculate_confidence_metrics(probabilities, predictions, y_test)
            },
            'value_betting': simulate_value_betting_simple(probabilities, y_test, df_test)
        }
        
        # Imprimir resultados
        print("\n📊 RESULTADOS DEL BENCHMARK:")
        print(json.dumps(results, indent=2))
        
        cursor.close()
        conn.close()
        
        print("\n✅ BENCHMARK COMPLETADO")
        
    except Exception as e:
        print(f"❌ ERROR: {str(e)}")
        import traceback
        traceback.print_exc()
        sys.exit(1)

def prepare_features(df, model_type):
    """Preparar features para el modelo"""
    # Features básicas siempre incluidas
    feature_cols = [
        'hs', 'as_shots',      # Tiros
        'hst', 'ast',          # Tiros a puerta
        'hc', 'ac',            # Corners
        'hf', 'af',            # Faltas
        'hy', 'ay',            # Tarjetas amarillas
        'hr', 'ar'             # Tarjetas rojas
    ]
    
    # Añadir xG si el modelo lo usa
    if model_type == 'with_xg':
        feature_cols.extend(['home_xg', 'away_xg'])
    
    # Crear copia para no modificar original
    df_copy = df.copy()
    
    # Llenar valores faltantes
    for col in feature_cols:
        if col in df_copy.columns:
            df_copy[col] = pd.to_numeric(df_copy[col], errors='coerce')
            if df_copy[col].isna().all():
                df_copy[col] = 0
            else:
                df_copy[col] = df_copy[col].fillna(df_copy[col].median())
        else:
            df_copy[col] = 0
    
    # Crear matriz de features
    X = df_copy[feature_cols].values
    
    # Target
    y = df_copy['ftr'].map({'H': 2, 'D': 1, 'A': 0}).values
    
    # Eliminar filas con NaN
    mask = ~np.isnan(X).any(axis=1) & ~np.isnan(y)
    X = X[mask]
    y = y[mask]
    
    return X, y

def calculate_result_metrics(y_true, y_pred, result_type):
    """Calcular métricas para un tipo de resultado específico"""
    # Cuando PREDECIMOS este resultado
    predicted_mask = y_pred == result_type
    total_predicted = np.sum(predicted_mask)
    
    if total_predicted == 0:
        return {
            'total': 0,
            'correct': 0,
            'accuracy': 0
        }
    
    # De las veces que predijimos este resultado, ¿cuántas acertamos?
    correct_predictions = np.sum((y_pred == result_type) & (y_true == result_type))
    
    return {
        'total': int(total_predicted),
        'correct': int(correct_predictions),
        'accuracy': float(correct_predictions / total_predicted)
    }

def calculate_confidence_metrics(probabilities, predictions, y_true):
    """Calcular métricas para predicciones de alta confianza"""
    max_probs = np.max(probabilities, axis=1)
    high_conf_mask = max_probs > 0.6
    
    if np.sum(high_conf_mask) == 0:
        return {'total': 0, 'correct': 0, 'accuracy': 0}
    
    high_conf_correct = np.sum((predictions[high_conf_mask] == y_true[high_conf_mask]))
    high_conf_total = np.sum(high_conf_mask)
    
    return {
        'total': int(high_conf_total),
        'correct': int(high_conf_correct),
        'accuracy': float(high_conf_correct / high_conf_total)
    }

def simulate_value_betting_simple(probabilities, y_true, df_test):
    """Simular value betting con cuotas REALES de la base de datos"""
    initial_bankroll = 1000
    bankroll = initial_bankroll
    stake_percentage = 0.02  # 2% del bankroll INICIAL
    min_value = 0.05  # 5% de valor mínimo
    
    # Usar stake fijo basado en bankroll inicial
    fixed_stake = initial_bankroll * stake_percentage  # €20 fijo
    
    bets = []
    skipped = 0
    
    for i in range(len(probabilities)):
        # Obtener el partido correspondiente
        match = df_test.iloc[i]
        
        # Obtener cuotas reales (manejar NULLs correctamente)
        odds = {}
        
        # Home odds
        home_odd = match.get('b365h')
        if home_odd is None or home_odd == '':
            home_odd = match.get('bwh')
        if home_odd is None or home_odd == '':
            home_odd = 0
        odds[2] = float(home_odd) if home_odd else 0
        
        # Draw odds
        draw_odd = match.get('b365d')
        if draw_odd is None or draw_odd == '':
            draw_odd = match.get('bwd')
        if draw_odd is None or draw_odd == '':
            draw_odd = 0
        odds[1] = float(draw_odd) if draw_odd else 0
        
        # Away odds
        away_odd = match.get('b365a')
        if away_odd is None or away_odd == '':
            away_odd = match.get('bwa')
        if away_odd is None or away_odd == '':
            away_odd = 0
        odds[0] = float(away_odd) if away_odd else 0
        
        # Saltar si no hay cuotas disponibles
        if any(o == 0 or o < 1.01 for o in odds.values()):
            skipped += 1
            continue
        
        # Nuestras probabilidades
        our_probs = probabilities[i]
        
        # Buscar value bets
        best_value = -1
        best_outcome = None
        best_odd = None
        
        for outcome in range(3):
            our_prob = our_probs[outcome]
            market_odd = odds[outcome]
            
            # Probabilidad implícita del mercado
            market_prob = 1 / market_odd
            
            # Calcular valor: diferencia entre nuestra probabilidad y la del mercado
            value = our_prob - market_prob
            
            # Buscar la mejor apuesta de valor
            if value > best_value and value > min_value and our_prob > 0.35:
                best_value = value
                best_outcome = outcome
                best_odd = market_odd
        
        # Apostar si hay valor
        if best_outcome is not None:
            # Usar stake fijo o lo que quede del bankroll
            stake = min(fixed_stake, bankroll)
            
            if stake <= 0:
                break  # Bancarrota
            
            if y_true[i] == best_outcome:
                # Ganamos
                profit = stake * (best_odd - 1)
                bankroll += profit
                result = 'win'
            else:
                # Perdemos
                bankroll -= stake
                result = 'loss'
            
            bets.append({
                'match': f"{match['home_team']} vs {match['away_team']}",
                'date': str(match['date']),
                'outcome': ['Away', 'Draw', 'Home'][best_outcome],
                'our_prob': round(our_probs[best_outcome], 3),
                'market_odd': round(best_odd, 2),
                'market_prob': round(1/best_odd, 3),
                'value': round(best_value * 100, 1),
                'stake': round(stake, 2),
                'profit': round(profit if result == 'win' else -stake, 2),
                'result': result,
                'bankroll': round(bankroll, 2)
            })
    
    total_bets = len(bets)
    winning_bets = sum(1 for b in bets if b['result'] == 'win')
    total_staked = sum(b['stake'] for b in bets)
    
    # Calcular ROI correctamente
    net_profit = bankroll - initial_bankroll
    roi = (net_profit / total_staked * 100) if total_staked > 0 else 0
    
    # Estadísticas adicionales
    avg_odds_backed = sum(b['market_odd'] for b in bets) / len(bets) if bets else 0
    avg_value = sum(b['value'] for b in bets) / len(bets) if bets else 0
    
    # Ordenar las mejores apuestas por valor
    best_bets = sorted(bets, key=lambda x: x['value'], reverse=True)[:10]
    
    return {
        'initial_bankroll': initial_bankroll,
        'final_bankroll': round(bankroll, 2),
        'profit_loss': round(net_profit, 2),
        'roi': round(roi, 2),
        'total_bets': total_bets,
        'winning_bets': winning_bets,
        'win_rate': round(winning_bets / total_bets * 100, 1) if total_bets > 0 else 0,
        'total_staked': round(total_staked, 2),
        'avg_stake': round(total_staked / total_bets, 2) if total_bets > 0 else 0,
        'avg_odds': round(avg_odds_backed, 2),
        'avg_value': round(avg_value, 1),
        'matches_with_odds': len(probabilities) - skipped,
        'matches_skipped': skipped,
        'best_value_bets': best_bets[:5],  # Solo top 5
        'worst_loss': min(bets, key=lambda x: x['profit'])['profit'] if bets else 0,
        'best_win': max(bets, key=lambda x: x['profit'])['profit'] if bets else 0,
        'note': 'Value betting con stake fijo y cuotas reales'
    }

if __name__ == "__main__":
    main()