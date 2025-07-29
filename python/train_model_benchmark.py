#!/usr/bin/env python3.8
"""
Script para entrenar modelo de benchmarking - EXCLUYE temporada específica
"""

import sys
import os

plugin_libs = '/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs'
if os.path.exists(plugin_libs):
    sys.path.insert(0, plugin_libs)

import json
import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, classification_report
import joblib
import mysql.connector
from datetime import datetime

def connect_database():
    print("🔌 Conectando a la base de datos...")
    config_file = 'db_config.json'
    
    if os.path.exists(config_file):
        with open(config_file, 'r') as f:
            config = json.load(f)
    else:
        print("❌ No se encontró configuración de BD")
        sys.exit(1)

    # Corrección HOST/PORT
    if 'host' in config and ':' in str(config['host']):
        host_part, port_part = str(config['host']).split(':', 1)
        config['host'] = host_part
        try:
            config['port'] = int(port_part)
        except Exception:
            config['port'] = 3306

    print(f"🟢 Conectando a {config['host']}:{config.get('port', 3306)}")
    
    try:
        connection = mysql.connector.connect(**config)
        print("✅ Conexión establecida")
        return connection
    except Exception as e:
        print(f"❌ Error de conexión: {e}")
        raise

def load_training_data(exclude_season, model_type):
    print(f"📊 Cargando datos de entrenamiento (excluyendo temporada {exclude_season})...")
    
    connection = connect_database()
    cursor = connection.cursor()

    # Query básico sin la temporada de prueba
    base_query = """
        SELECT 
            season,
            home_team,
            away_team,
            ftr as result,
            COALESCE(hs, 10) as home_shots,
            COALESCE(as_shots, 10) as away_shots,
            COALESCE(hst, 4) as home_shots_target,
            COALESCE(ast, 4) as away_shots_target,
            COALESCE(hc, 5) as home_corners,
            COALESCE(ac, 5) as away_corners,
            COALESCE(hf, 12) as home_fouls,
            COALESCE(af, 12) as away_fouls,
            COALESCE(hy, 2) as home_yellows,
            COALESCE(ay, 2) as away_yellows,
            COALESCE(hhw, 0) as home_woodwork,
            COALESCE(ahw, 0) as away_woodwork
    """
    
    # Añadir xG solo si el modelo lo requiere
    if model_type == 'with_xg':
        base_query += """,
            COALESCE(home_xg, 0) as home_xg,
            COALESCE(away_xg, 0) as away_xg
        """
    
    # Obtener nombre de tabla dinámicamente
    cursor.execute("SHOW TABLES LIKE '%ft_matches_advanced'")
    table_result = cursor.fetchone()
    if not table_result:
        print("❌ No se encontró tabla ft_matches_advanced")
        sys.exit(1)
    
    table_name = table_result[0]
    
    query = base_query + f"""
        FROM {table_name}
        WHERE ftr IS NOT NULL 
        AND ftr IN ('H', 'D', 'A')
        AND season IS NOT NULL
        AND season != %s
        ORDER BY date ASC
        LIMIT 3000
    """
    
    cursor.execute(query, (exclude_season,))
    results = cursor.fetchall()
    
    # Definir columnas según el tipo de modelo
    base_columns = ['season', 'home_team', 'away_team', 'result', 'home_shots', 'away_shots',
                   'home_shots_target', 'away_shots_target', 'home_corners', 'away_corners',
                   'home_fouls', 'away_fouls', 'home_yellows', 'away_yellows', 
                   'home_woodwork', 'away_woodwork']
    
    if model_type == 'with_xg':
        base_columns.extend(['home_xg', 'away_xg'])
    
    df = pd.DataFrame(results, columns=base_columns)
    
    print(f"✅ Cargados {len(df)} partidos (excluyendo temporada {exclude_season})")
    print(f"📊 Temporadas en entrenamiento: {sorted(df['season'].unique())}")
    
    connection.close()
    return df

def prepare_features(df, model_type):
    print(f"🔧 Preparando características para modelo {model_type}...")
    
    # Calcular estadísticas por equipo
    team_stats = {}
    
    for _, row in df.iterrows():
        home_team = row['home_team']
        away_team = row['away_team']
        
        # Inicializar si no existe
        for team in [home_team, away_team]:
            if team not in team_stats:
                team_stats[team] = {
                    'shots': [], 'shots_target': [], 'corners': [], 'fouls': [],
                    'yellows': [], 'woodwork': [], 'results_as_home': [],
                    'results_as_away': []
                }
                if model_type == 'with_xg':
                    team_stats[team]['xg'] = []
        
        # Recopilar estadísticas
        team_stats[home_team]['shots'].append(row['home_shots'])
        team_stats[home_team]['shots_target'].append(row['home_shots_target'])
        team_stats[home_team]['corners'].append(row['home_corners'])
        team_stats[home_team]['fouls'].append(row['home_fouls'])
        team_stats[home_team]['yellows'].append(row['home_yellows'])
        team_stats[home_team]['woodwork'].append(row['home_woodwork'])
        team_stats[home_team]['results_as_home'].append(row['result'])
        
        team_stats[away_team]['shots'].append(row['away_shots'])
        team_stats[away_team]['shots_target'].append(row['away_shots_target'])
        team_stats[away_team]['corners'].append(row['away_corners'])
        team_stats[away_team]['fouls'].append(row['away_fouls'])
        team_stats[away_team]['yellows'].append(row['away_yellows'])
        team_stats[away_team]['woodwork'].append(row['away_woodwork'])
        team_stats[away_team]['results_as_away'].append(row['result'])
        
        if model_type == 'with_xg':
            team_stats[home_team]['xg'].append(row['home_xg'])
            team_stats[away_team]['xg'].append(row['away_xg'])
    
    # Calcular medias por equipo
    team_averages = {}
    for team, stats in team_stats.items():
        if len(stats['shots']) > 0:
            team_averages[team] = {
                'avg_shots': np.mean(stats['shots']),
                'avg_shots_target': np.mean(stats['shots_target']),
                'avg_corners': np.mean(stats['corners']),
                'avg_fouls': np.mean(stats['fouls']),
                'avg_yellows': np.mean(stats['yellows']),
                'avg_woodwork': np.mean(stats['woodwork']),
                'home_win_rate': sum(1 for r in stats['results_as_home'] if r == 'H') / max(1, len(stats['results_as_home'])),
                'away_win_rate': sum(1 for r in stats['results_as_away'] if r == 'A') / max(1, len(stats['results_as_away']))
            }
            
            if model_type == 'with_xg':
                team_averages[team]['avg_xg'] = np.mean(stats['xg'])
    
    # Crear features para cada partido
    features = []
    targets = []
    
    print(f"🎯 Creando features para {len(df)} partidos...")
    
    for _, row in df.iterrows():
        home_team = row['home_team']
        away_team = row['away_team']
        
        # Verificar que ambos equipos tengan estadísticas
        if home_team not in team_averages or away_team not in team_averages:
            continue
        
        home_stats = team_averages[home_team]
        away_stats = team_averages[away_team]
        
        # Features básicas
        match_features = [
            # Estadísticas local
            home_stats['avg_shots'],
            home_stats['avg_shots_target'],
            home_stats['avg_corners'],
            home_stats['avg_fouls'],
            home_stats['avg_yellows'],
            home_stats['avg_woodwork'],
            home_stats['home_win_rate'],
            home_stats['away_win_rate'],
            
            # Estadísticas visitante
            away_stats['avg_shots'],
            away_stats['avg_shots_target'],
            away_stats['avg_corners'],
            away_stats['avg_fouls'],
            away_stats['avg_yellows'],
            away_stats['avg_woodwork'],
            away_stats['home_win_rate'],
            away_stats['away_win_rate'],
            
            # Features comparativas
            home_stats['avg_shots'] - away_stats['avg_shots'],
            home_stats['avg_shots_target'] - away_stats['avg_shots_target'],
            home_stats['avg_corners'] - away_stats['avg_corners'],
            home_stats['home_win_rate'] - away_stats['away_win_rate'],
            
            # Ratios
            home_stats['avg_shots'] / max(1, away_stats['avg_shots']),
            home_stats['avg_shots_target'] / max(1, away_stats['avg_shots_target'])
        ]
        
        # Añadir features de xG si es necesario
        if model_type == 'with_xg':
            match_features.extend([
                home_stats['avg_xg'],
                away_stats['avg_xg'],
                home_stats['avg_xg'] - away_stats['avg_xg'],
                home_stats['avg_xg'] / max(0.1, away_stats['avg_xg'])
            ])
        
        features.append(match_features)
        targets.append(row['result'])
    
    print(f"✅ Features creadas: {len(features)} partidos, {len(match_features)} características por partido")
    
    return np.array(features), np.array(targets)

def train_benchmark_model(X, y, model_type):
    print(f"🚂 Entrenando modelo Random Forest para benchmarking...")
    
    # Split en entrenamiento y validación
    X_train, X_val, y_train, y_val = train_test_split(X, y, test_size=0.2, random_state=42, stratify=y)
    
    print(f"📊 Datos de entrenamiento: {len(X_train)} partidos")
    print(f"📊 Datos de validación: {len(X_val)} partidos")
    
    # Configurar modelo
    rf = RandomForestClassifier(
        n_estimators=100,
        max_depth=8,
        min_samples_split=10,
        min_samples_leaf=5,
        random_state=42,
        n_jobs=-1
    )
    
    # Entrenar
    print("🎯 Entrenando Random Forest...")
    rf.fit(X_train, y_train)
    
    # Evaluar
    train_pred = rf.predict(X_train)
    val_pred = rf.predict(X_val)
    
    train_accuracy = accuracy_score(y_train, train_pred)
    val_accuracy = accuracy_score(y_val, val_pred)
    
    print(f"📈 Precisión entrenamiento: {train_accuracy:.4f}")
    print(f"📈 Precisión validación: {val_accuracy:.4f}")
    
    # Guardar modelo
    model_path = '../models/benchmark_model.pkl'
    joblib.dump(rf, model_path)
    print(f"💾 Modelo guardado en {model_path}")
    
    # Guardar metadatos
    metadata = {
        'model_type': model_type,
        'training_date': datetime.now().isoformat(),
        'training_accuracy': train_accuracy,
        'validation_accuracy': val_accuracy,
        'n_features': X.shape[1],
        'n_samples': len(X),
        'feature_names': get_feature_names(model_type)
    }
    
    metadata_path = '../models/benchmark_metadata.json'
    with open(metadata_path, 'w') as f:
        json.dump(metadata, f, indent=2)
    
    print(f"📄 Metadatos guardados en {metadata_path}")
    
    return val_accuracy

def get_feature_names(model_type):
    base_features = [
        'home_avg_shots', 'home_avg_shots_target', 'home_avg_corners', 'home_avg_fouls',
        'home_avg_yellows', 'home_avg_woodwork', 'home_home_win_rate', 'home_away_win_rate',
        'away_avg_shots', 'away_avg_shots_target', 'away_avg_corners', 'away_avg_fouls',
        'away_avg_yellows', 'away_avg_woodwork', 'away_home_win_rate', 'away_away_win_rate',
        'shots_diff', 'shots_target_diff', 'corners_diff', 'win_rate_diff',
        'shots_ratio', 'shots_target_ratio'
    ]
    
    if model_type == 'with_xg':
        base_features.extend(['home_avg_xg', 'away_avg_xg', 'xg_diff', 'xg_ratio'])
    
    return base_features

def main():
    try:
        print("🏆 INICIANDO ENTRENAMIENTO DE BENCHMARKING")
        print("=" * 50)
        
        # Obtener parámetros
        model_type = sys.argv[1] if len(sys.argv) > 1 else 'with_xg'
        
        # Leer temporada a excluir
        exclude_season = None
        if os.path.exists('exclude_season.txt'):
            with open('exclude_season.txt', 'r') as f:
                exclude_season = f.read().strip()
        else:
            print("❌ No se encontró archivo exclude_season.txt")
            sys.exit(1)
        
        print(f"🚫 Excluyendo temporada: {exclude_season}")
        print(f"🤖 Tipo de modelo: {model_type}")
        
        # 1. Cargar datos
        df = load_training_data(exclude_season, model_type)
        
        if len(df) < 100:
            print(f"❌ Datos insuficientes: {len(df)} partidos")
            sys.exit(1)
        
        # 2. Preparar features
        X, y = prepare_features(df, model_type)
        
        if len(X) < 50:
            print(f"❌ Features insuficientes: {len(X)} partidos válidos")
            sys.exit(1)
        
        # 3. Entrenar modelo
        val_accuracy = train_benchmark_model(X, y, model_type)
        
        # 4. Verificar distribución de clases
        unique, counts = np.unique(y, return_counts=True)
        print(f"📊 Distribución de resultados:")
        for result, count in zip(unique, counts):
            percentage = (count / len(y)) * 100
            print(f"   {result}: {count} ({percentage:.1f}%)")
        
        print("=" * 50)
        print("🎉 ENTRENAMIENTO COMPLETADO")
        print(f"Training accuracy: {val_accuracy:.4f}")
        print("BENCHMARK_SUCCESS")
        
    except Exception as e:
        print(f"❌ ERROR: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)

if __name__ == "__main__":
    main()