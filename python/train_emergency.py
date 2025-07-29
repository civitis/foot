    return '#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script de emergencia para entrenar el modelo
"""

import sys
import json
import pickle
import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from datetime import datetime

# Path de librerías
plugin_libs = "/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs"
if plugin_libs not in sys.path:
    sys.path.insert(0, plugin_libs)

import mysql.connector

print("🚀 Script de entrenamiento de emergencia iniciado")

try:
    # Cargar configuración
    with open("db_config.json", "r") as f:
        config = json.load(f)
    
    # Conectar a BD
    print("📊 Conectando a base de datos...")
    
    # Manejar puerto
    host = config["host"]
    port = 3306
    if ":" in host:
        host, port = host.split(":")
        port = int(port)
    
    conn = mysql.connector.connect(
        host=host,
        port=port,
        user=config["user"],
        password=config["password"],
        database=config["database"]
    )
    
    # Cargar datos
    print("📥 Cargando datos...")
    query = """
    SELECT * FROM wp_ft_matches_advanced 
    WHERE fthg IS NOT NULL 
    AND ftag IS NOT NULL
    AND hs IS NOT NULL
    """
    
    df = pd.read_sql(query, conn)
    conn.close()
    
    print(f"✅ {len(df)} partidos cargados")
    
    # Crear features básicas
    print("🔧 Creando features...")
    features = []
    
    # Features por equipo (simplificado)
    for team in df["home_team"].unique():
        home_matches = df[df["home_team"] == team]
        if len(home_matches) > 0:
            features.append({
                "team": team,
                "avg_goals_home": home_matches["fthg"].mean(),
                "avg_conceded_home": home_matches["ftag"].mean()
            })
    
    # Crear dataset de entrenamiento (simplificado)
    X = []
    y = []
    
    for _, match in df.iterrows():
        # Features muy básicas
        row_features = [
            match["hs"] if pd.notna(match["hs"]) else 10,
            match["as_shots"] if pd.notna(match["as_shots"]) else 10,
            match["hst"] if pd.notna(match["hst"]) else 3,
            match["ast"] if pd.notna(match["ast"]) else 3,
            match["hc"] if pd.notna(match["hc"]) else 5,
            match["ac"] if pd.notna(match["ac"]) else 5
        ]
        
        # Añadir más features dummy para llegar a 20
        while len(row_features) < 20:
            row_features.append(0)
        
        X.append(row_features)
        
        # Target
        if match["ftr"] == "H":
            y.append(2)
        elif match["ftr"] == "D":
            y.append(1)
        else:
            y.append(0)
    
    X = np.array(X)
    y = np.array(y)
    
    print(f"📐 Shape X: {X.shape}, Shape y: {y.shape}")
    
    # Entrenar modelo
    print("🤖 Entrenando Random Forest...")
    model = RandomForestClassifier(
        n_estimators=100,
        max_depth=10,
        random_state=42,
        n_jobs=-1
    )
    
    X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)
    model.fit(X_train, y_train)
    
    accuracy = model.score(X_test, y_test)
    print(f"✅ Precisión: {accuracy:.2%}")
    
    # Guardar modelo
    print("💾 Guardando modelo...")
    model_data = {
        "model": model,
        "features": [f"feature_{i}" for i in range(20)],
        "training_date": datetime.now().isoformat(),
        "accuracy": accuracy,
        "n_samples": len(X)
    }
    
    with open("../models/football_rf_advanced.pkl", "wb") as f:
        pickle.dump(model_data, f)
    
    # Guardar metadata
    metadata = {
        "training_date": datetime.now().isoformat(),
        "features": model_data["features"],
        "performance": {
            "accuracy": accuracy
        }
    }
    
    with open("../models/model_metadata.json", "w") as f:
        json.dump(metadata, f, indent=2)
    
    print("🎉 ¡Entrenamiento completado exitosamente!")
    
except Exception as e:
    print(f"❌ Error: {str(e)}")
    import traceback
    traceback.print_exc()
';