#!/usr/bin/env python3
"""
Script para entrenar modelo Random Forest con datos reales de WordPress
"""

#!/usr/bin/env python3
"""
Script para entrenar modelo Random Forest con datos reales de WordPress
"""

import sys
import os

# AGREGAR RUTA DE LIBRERÍAS INSTALADAS
plugin_libs = '/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs'
if os.path.exists(plugin_libs):
    sys.path.insert(0, plugin_libs)
    print(f"✅ Agregada ruta de librerías: {plugin_libs}")
else:
    print(f"⚠️  Ruta de librerías no encontrada: {plugin_libs}")

# Ahora sí importar las librerías
import json
import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, classification_report
import joblib
import mysql.connector
from datetime import datetime
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
    """Conectar a la base de datos WordPress"""
    
    # Intentar leer configuración temporal
    config_file = 'db_config_temp.json'
    if os.path.exists(config_file):
        with open(config_file, 'r') as f:
            config = json.load(f)
    else:
        # Configuración por defecto
        config = {
            'host': 'localhost',
            'user': 'root',
            'password': '',
            'database': 'wordpress'
        }
    
    return mysql.connector.connect(**config)
def load_training_data():
    """Cargar datos de entrenamiento desde WordPress"""
    print("🔄 Cargando datos de entrenamiento...")
    
    connection = connect_database()
    cursor = connection.cursor()
    
    # Query para obtener datos con estadísticas calculadas
    query = """
    SELECT 
        m.home_team,
        m.away_team,
        m.ftr as result,
        
        -- Estadísticas del equipo local
        (SELECT COUNT(*) FROM wp_ft_matches_advanced m2 
         WHERE m2.home_team = m.home_team AND m2.date < m.date) as home_matches_played,
        (SELECT COUNT(*) FROM wp_ft_matches_advanced m2 
         WHERE m2.home_team = m.home_team AND m2.ftr = 'H' AND m2.date < m.date) as home_wins,
        (SELECT COUNT(*) FROM wp_ft_matches_advanced m2 
         WHERE m2.home_team = m.home_team AND m2.ftr = 'D' AND m2.date < m.date) as home_draws,
        (SELECT AVG(m2.fthg) FROM wp_ft_matches_advanced m2 
         WHERE m2.home_team = m.home_team AND m2.date < m.date) as home_avg_goals_for,
        (SELECT AVG(m2.ftag) FROM wp_ft_matches_advanced m2 
         WHERE m2.home_team = m.home_team AND m2.date < m.date) as home_avg_goals_against,
         
        -- Estadísticas del equipo visitante
        (SELECT COUNT(*) FROM wp_ft_matches_advanced m2 
         WHERE m2.away_team = m.away_team AND m2.date < m.date) as away_matches_played,
        (SELECT COUNT(*) FROM wp_ft_matches_advanced m2 
         WHERE m2.away_team = m.away_team AND m2.ftr = 'A' AND m2.date < m.date) as away_wins,
        (SELECT COUNT(*) FROM wp_ft_matches_advanced m2 
         WHERE m2.away_team = m.away_team AND m2.ftr = 'D' AND m2.date < m.date) as away_draws,
        (SELECT AVG(m2.ftag) FROM wp_ft_matches_advanced m2 
         WHERE m2.away_team = m.away_team AND m2.date < m.date) as away_avg_goals_for,
        (SELECT AVG(m2.fthg) FROM wp_ft_matches_advanced m2 
         WHERE m2.away_team = m.away_team AND m2.date < m.date) as away_avg_goals_against,
         
        -- Estadísticas del partido actual
        m.hs as home_shots,
        m.as_shots as away_shots,
        m.hc as home_corners,
        m.ac as away_corners
        
    FROM wp_ft_matches_advanced m
    WHERE m.ftr IS NOT NULL 
    AND m.ftr IN ('H', 'D', 'A')
    ORDER BY m.date
    """
    
    cursor.execute(query)
    results = cursor.fetchall()
    
    # Convertir a DataFrame
    columns = ['home_team', 'away_team', 'result', 'home_matches', 'home_wins', 'home_draws',
              'home_goals_for', 'home_goals_against', 'away_matches', 'away_wins', 'away_draws',
              'away_goals_for', 'away_goals_against', 'home_shots', 'away_shots', 
              'home_corners', 'away_corners']
    
    df = pd.DataFrame(results, columns=columns)
    
    print(f"✅ Cargados {len(df)} partidos")
    
    connection.close()
    return df

def prepare_features(df):
    """Preparar características para el modelo"""
    print("🔧 Preparando características...")
    
    # Eliminar filas con datos faltantes
    df = df.dropna()
    
    # Calcular ratios y estadísticas derivadas
    df['home_win_rate'] = df['home_wins'] / (df['home_matches'] + 1)
    df['home_draw_rate'] = df['home_draws'] / (df['home_matches'] + 1)
    df['away_win_rate'] = df['away_wins'] / (df['away_matches'] + 1)
    df['away_draw_rate'] = df['away_draws'] / (df['away_matches'] + 1)
    
    df['home_goals_diff'] = df['home_goals_for'] - df['home_goals_against']
    df['away_goals_diff'] = df['away_goals_for'] - df['away_goals_against']
    
    # Características finales
    features = [
        'home_win_rate', 'home_draw_rate', 'home_goals_for', 'home_goals_against',
        'away_win_rate', 'away_draw_rate', 'away_goals_for', 'away_goals_against',
        'home_goals_diff', 'away_goals_diff', 'home_shots', 'away_shots',
        'home_corners', 'away_corners'
    ]
    
    # Rellenar NaN con valores por defecto
    for feature in features:
        df[feature] = df[feature].fillna(df[feature].median())
    
    X = df[features]
    y = df['result']
    
    print(f"✅ Preparadas {len(features)} características para {len(X)} partidos")
    return X, y, features

def train_random_forest(X, y):
    """Entrenar modelo Random Forest"""
    print("🌲 Entrenando Random Forest...")
    
    # Dividir datos
    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)
    
    # Crear y entrenar modelo
    model = RandomForestClassifier(
        n_estimators=100,
        max_depth=10,
        min_samples_split=5,
        min_samples_leaf=2,
        random_state=42,
        n_jobs=-1
    )
    
    model.fit(X_train, y_train)
    
    # Evaluar modelo
    y_pred = model.predict(X_test)
    accuracy = accuracy_score(y_test, y_pred)
    
    print(f"✅ Modelo entrenado con precisión: {accuracy:.2%}")
    print("\n📊 Informe de clasificación:")
    print(classification_report(y_test, y_pred))
    
    return model, accuracy

def save_model(model, features, accuracy):
    """Guardar modelo y metadatos"""
    models_dir = '../models'
    if not os.path.exists(models_dir):
        os.makedirs(models_dir)
    
    # Guardar modelo
    model_path = os.path.join(models_dir, 'football_model.pkl')
    joblib.dump(model, model_path)
    
    # Guardar metadatos
    metadata = {
        'model_type': 'RandomForestClassifier',
        'features': features,
        'accuracy': float(accuracy),
        'training_date': datetime.now().isoformat(),
        'n_estimators': model.n_estimators,
        'max_depth': model.max_depth
    }
    
    metadata_path = os.path.join(models_dir, 'football_metadata.json')
    with open(metadata_path, 'w') as f:
        json.dump(metadata, f, indent=2)
    
    print(f"✅ Modelo guardado en: {model_path}")
    print(f"✅ Metadatos guardados en: {metadata_path}")

def main():
    print("🚀 Iniciando entrenamiento de Random Forest")
    print("=" * 50)
    
    try:
        # 1. Cargar datos
        df = load_training_data()
        
        # 2. Preparar características
        X, y, features = prepare_features(df)
        
        # 3. Entrenar modelo
        model, accuracy = train_random_forest(X, y)
        
        # 4. Guardar modelo
        save_model(model, features, accuracy)
        
        print("\n🎉 Entrenamiento completado exitosamente!")
        print(f"📈 Precisión final: {accuracy:.2%}")
        
    except Exception as e:
        print(f"❌ Error durante el entrenamiento: {str(e)}")
        return False
    
    return True

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)