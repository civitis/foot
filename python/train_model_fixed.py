#!/usr/bin/env python3.8
"""
Script para entrenar modelo Random Forest - VERSIÓN MEJORADA CON xG
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
    config = None
    if os.path.exists(config_file):
        with open(config_file, 'r') as f:
            config = json.load(f)
            print(f"✅ Configuración cargada: {config}")
    else:
        print("⚠️  Usando configuración por defecto")
        config = {
            'host': '127.0.0.1',
            'user': 'wp_epegn',
            'password': '~b&DelI9G8Zz&7JG',
            'database': 'PP0Fhoci',
            'port': 3306
        }

    # --- CORRECCIÓN HOST / PORT ---
    if 'host' in config and ':' in str(config['host']):
        host_part, port_part = str(config['host']).split(':', 1)
        config['host'] = host_part
        try:
            config['port'] = int(port_part)
        except Exception:
            config['port'] = 3306
    if 'port' in config and isinstance(config['port'], str):
        try:
            config['port'] = int(config['port'])
        except Exception:
            config['port'] = 3306

    print(f"🟢 Conectando a host={config['host']} port={config.get('port',3306)} db={config['database']}")
    try:
        connection = mysql.connector.connect(**config)
        print("✅ Conexión establecida")
        return connection
    except Exception as e:
        print(f"❌ Error de conexión: {e}")
        raise

def load_training_data():
    print("📊 Cargando datos de entrenamiento...")    
    connection = connect_database()
    cursor = connection.cursor()

    # QUERY MEJORADA: Incluye xG y más estadísticas
    query = """
        SELECT 
            home_team,
            away_team,
            ftr as result,
            fthg as home_goals,
            ftag as away_goals,
            COALESCE(hs, 10) as home_shots,
            COALESCE(as_shots, 10) as away_shots,
            COALESCE(hst, 4) as home_shots_target,
            COALESCE(ast, 4) as away_shots_target,
            COALESCE(hc, 5) as home_corners,
            COALESCE(ac, 5) as away_corners,
            COALESCE(home_xg, 0) as home_xg,
            COALESCE(away_xg, 0) as away_xg,
            COALESCE(hf, 12) as home_fouls,
            COALESCE(af, 12) as away_fouls,
            COALESCE(hy, 2) as home_yellows,
            COALESCE(ay, 2) as away_yellows,
            COALESCE(hhw, 0) as home_woodwork,
            COALESCE(ahw, 0) as away_woodwork
        FROM PP0Fhoci_ft_matches_advanced 
        WHERE ftr IS NOT NULL 
        AND ftr IN ('H', 'D', 'A')
        AND fthg IS NOT NULL 
        AND ftag IS NOT NULL
        LIMIT 2000
    """
    
    cursor.execute(query)
    results = cursor.fetchall()
    
    columns = ['home_team', 'away_team', 'result', 'home_goals', 'away_goals',
              'home_shots', 'away_shots', 'home_shots_target', 'away_shots_target',
              'home_corners', 'away_corners', 'home_xg', 'away_xg', 
              'home_fouls', 'away_fouls', 'home_yellows', 'away_yellows',
              'home_woodwork', 'away_woodwork']
    
    df = pd.DataFrame(results, columns=columns)
    print(f"✅ Cargados {len(df)} partidos")
    
    # Estadísticas de xG
    xg_available = df[(df['home_xg'] > 0) | (df['away_xg'] > 0)]
    print(f"📊 Partidos con xG disponible: {len(xg_available)} ({len(xg_available)/len(df)*100:.1f}%)")
    
    connection.close()
    return df

def prepare_features(df):
    print("🔧 Preparando características...")
    
    # ✨ FEATURES MEJORADAS CON xG ✨
    features = [
        # Estadísticas básicas
        'home_goals', 'away_goals',
        'home_shots', 'away_shots',
        'home_shots_target', 'away_shots_target',
        'home_corners', 'away_corners',
        
        # 🎯 xG - LA NUEVA ESTRELLA
        'home_xg', 'away_xg',
        
        # Estadísticas adicionales
        'home_fouls', 'away_fouls',
        'home_yellows', 'away_yellows',
        'home_woodwork', 'away_woodwork',
        
        # Features calculadas - MEJORADAS
        'home_attack_strength', 'away_attack_strength',
        'home_accuracy', 'away_accuracy',
        'shots_ratio', 'corners_ratio',
        
        # 🚀 NUEVAS FEATURES CON xG
        'xg_ratio', 'xg_difference',
        'xg_efficiency_home', 'xg_efficiency_away',
        'xg_vs_shots_home', 'xg_vs_shots_away',
        'total_xg', 'xg_balance'
    ]
    
    # Calcular features básicas
    df['home_attack_strength'] = df['home_goals'] / df['home_shots']
    df['away_attack_strength'] = df['away_goals'] / df['away_shots']
    df['home_accuracy'] = df['home_shots_target'] / df['home_shots']
    df['away_accuracy'] = df['away_shots_target'] / df['away_shots']
    df['shots_ratio'] = df['home_shots'] / (df['home_shots'] + df['away_shots'])
    df['corners_ratio'] = df['home_corners'] / (df['home_corners'] + df['away_corners'])
    
    # 🎯 CALCULAR NUEVAS FEATURES CON xG
    df['xg_ratio'] = df['home_xg'] / (df['home_xg'] + df['away_xg'] + 0.1)  # +0.1 para evitar división por 0
    df['xg_difference'] = df['home_xg'] - df['away_xg']
    df['xg_efficiency_home'] = df['home_goals'] / (df['home_xg'] + 0.1)  # Goles vs xG esperado
    df['xg_efficiency_away'] = df['away_goals'] / (df['away_xg'] + 0.1)
    df['xg_vs_shots_home'] = df['home_xg'] / (df['home_shots'] + 0.1)  # Calidad de tiro
    df['xg_vs_shots_away'] = df['away_xg'] / (df['away_shots'] + 0.1)
    df['total_xg'] = df['home_xg'] + df['away_xg']
    df['xg_balance'] = abs(df['home_xg'] - df['away_xg']) / (df['total_xg'] + 0.1)
    
    # Llenar valores NaN
    df = df.fillna(0.5)
    
    # Preparar datos
    X = df[features]
    y = df['result']
    
    print("🧬 Características usadas:")
    for ix, feat in enumerate(features):
        if 'xg' in feat.lower():
            print(f"{ix+1}. {feat} ⭐ (xG feature)")
        else:
            print(f"{ix+1}. {feat}")
    
    print(f"✅ Preparadas {len(features)} características para {len(X)} partidos")
    print(f"🎯 Features con xG: {len([f for f in features if 'xg' in f.lower()])}")
    
    return X, y, features

def train_random_forest(X, y, features):
    print("🌲 Entrenando Random Forest...")   
    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)
    print(f"📊 Datos de entrenamiento: {len(X_train)}")
    print(f"📊 Datos de prueba: {len(X_test)}")
    
    # Modelo mejorado con más árboles para aprovechar las nuevas features
    model = RandomForestClassifier(
        n_estimators=100,  # Aumentado de 50 a 100
        max_depth=10,      # Aumentado de 8 a 10
        min_samples_split=8,  # Reducido de 10 a 8
        random_state=42,
        n_jobs=1
    )
    
    model.fit(X_train, y_train)
    print("✅ Entrenamiento completado")
    
    y_pred = model.predict(X_test)
    accuracy = accuracy_score(y_test, y_pred)
    
    print(f"📈 Precisión del modelo: {accuracy:.2%}")
    print("🧬 Importancia de características:")
    
    # Mostrar features ordenadas por importancia
    feature_importance = list(zip(features, model.feature_importances_))
    feature_importance.sort(key=lambda x: x[1], reverse=True)
    
    print("\n🏆 TOP 10 características más importantes:")
    for i, (feat, imp) in enumerate(feature_importance[:10]):
        star = "⭐" if 'xg' in feat.lower() else "  "
        print(f"{i+1:2d}. {feat:20s} {star} {imp:.4f}")
    
    print(f"\n🎯 Importancia total de features xG: {sum(imp for feat, imp in feature_importance if 'xg' in feat.lower()):.4f}")
    
    return model, accuracy

def save_model(model, features, accuracy):
    print("💾 Guardando modelo...")
    models_dir = '../models'
    if not os.path.exists(models_dir):
        os.makedirs(models_dir)
    
    # Cambiar nombre para distinguir del modelo anterior
    model_path = os.path.join(models_dir, 'football_rf_advanced.pkl')
    joblib.dump(model, model_path)
    
    # Metadatos mejorados
    metadata = {
        'features': features,
        'accuracy': float(accuracy),
        'training_date': datetime.now().isoformat(),
        'model_type': 'RandomForestClassifier',
        'version': '2.0_with_xG',
        'features_count': len(features),
        'xg_features_count': len([f for f in features if 'xg' in f.lower()]),
        'performance': {
            'accuracy': float(accuracy),
            'improvement': 'Uses xG for better predictions'
        }
    }
    
    metadata_path = os.path.join(models_dir, 'model_metadata.json')
    with open(metadata_path, 'w') as f:
        json.dump(metadata, f, indent=2)
    
    print(f"✅ Modelo guardado: {model_path}")
    print(f"✅ Metadatos guardados: {metadata_path}")

def main():
    print("🚀 Iniciando entrenamiento Random Forest con xG")
    print("=" * 60)
    try:
        print(f"NumPy version: {np.__version__}")
        print(f"Pandas version: {pd.__version__}")
        
        df = load_training_data()
        X, y, features = prepare_features(df)
        model, accuracy = train_random_forest(X, y, features)
        save_model(model, features, accuracy)
        
        print(f"\n🎉 Entrenamiento completado exitosamente!")
        print(f"📈 Precisión final: {accuracy:.2%}")
        print(f"🎯 Features con xG incluidas: {len([f for f in features if 'xg' in f.lower()])}")
        print(f"🚀 Modelo mejorado guardado como 'football_rf_advanced.pkl'")
        
        return True
    except Exception as e:
        print(f"❌ Error: {str(e)}")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)