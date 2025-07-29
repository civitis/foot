"""
Script avanzado para entrenar modelo Random Forest con estadísticas completas
Explicado para programadores PHP
"""

import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split, cross_val_score
from sklearn.preprocessing import StandardScaler
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix
import joblib
import mysql.connector
from datetime import datetime, timedelta
import json
import warnings
warnings.filterwarnings('ignore')

class AdvancedFootballPredictor:
    """
    Clase principal para el predictor avanzado
    En PHP sería: class AdvancedFootballPredictor { }
    """
    
    def __init__(self, db_config):
        """
        Constructor - como __construct() en PHP
        
        Args:
            db_config: diccionario con configuración de base de datos
        """
        self.db_config = db_config
        self.model = None
        self.scaler = StandardScaler()  # Para normalizar datos
        self.feature_names = []
        
    def connect_db(self):
        """
        Conecta a la base de datos MySQL
        Similar a $wpdb en WordPress
        """
        return mysql.connector.connect(
            host=self.db_config['host'],
            user=self.db_config['user'],
            password=self.db_config['password'],
            database=self.db_config['database']
        )
    
    def load_match_data(self):
        """
        Carga todos los datos de partidos con estadísticas avanzadas
        """
        connection = self.connect_db()
        
        # Query SQL compleja para obtener todos los datos necesarios
        # Incluye estadísticas históricas de los últimos 5 partidos
        query = """
        SELECT 
            m.*,
            -- Estadísticas del equipo local en últimos 5 partidos
            (SELECT AVG(CASE WHEN m2.home_team = m.home_team THEN m2.fthg ELSE m2.ftag END)
             FROM wp_ft_matches_advanced m2 
             WHERE (m2.home_team = m.home_team OR m2.away_team = m.home_team)
             AND m2.date < m.date 
             ORDER BY m2.date DESC LIMIT 5) as home_avg_goals_l5,
            
            (SELECT AVG(CASE WHEN m2.home_team = m.home_team THEN m2.hs ELSE m2.as_shots END)
             FROM wp_ft_matches_advanced m2 
             WHERE (m2.home_team = m.home_team OR m2.away_team = m.home_team)
             AND m2.date < m.date 
             ORDER BY m2.date DESC LIMIT 5) as home_avg_shots_l5,
            
            (SELECT AVG(CASE WHEN m2.home_team = m.home_team THEN m2.hst ELSE m2.ast END)
             FROM wp_ft_matches_advanced m2 
             WHERE (m2.home_team = m.home_team OR m2.away_team = m.home_team)
             AND m2.date < m.date 
             ORDER BY m2.date DESC LIMIT 5) as home_avg_shots_target_l5,
            
            -- Estadísticas del equipo visitante en últimos 5 partidos
            (SELECT AVG(CASE WHEN m2.home_team = m.away_team THEN m2.fthg ELSE m2.ftag END)
             FROM wp_ft_matches_advanced m2 
             WHERE (m2.home_team = m.away_team OR m2.away_team = m.away_team)
             AND m2.date < m.date 
             ORDER BY m2.date DESC LIMIT 5) as away_avg_goals_l5,
            
            (SELECT AVG(CASE WHEN m2.home_team = m.away_team THEN m2.hs ELSE m2.as_shots END)
             FROM wp_ft_matches_advanced m2 
             WHERE (m2.home_team = m.away_team OR m2.away_team = m.away_team)
             AND m2.date < m.date 
             ORDER BY m2.date DESC LIMIT 5) as away_avg_shots_l5,
            
            -- Enfrentamientos directos
            (SELECT COUNT(*) 
             FROM wp_ft_matches_advanced m2 
             WHERE m2.home_team = m.home_team AND m2.away_team = m.away_team
             AND m2.date < m.date) as h2h_matches,
            
            (SELECT SUM(CASE WHEN m2.ftr = 'H' THEN 1 ELSE 0 END)
             FROM wp_ft_matches_advanced m2 
             WHERE m2.home_team = m.home_team AND m2.away_team = m.away_team
             AND m2.date < m.date) as h2h_home_wins
            
        FROM wp_ft_matches_advanced m
        WHERE m.fthg IS NOT NULL 
        AND m.ftag IS NOT NULL
        AND m.date > DATE_SUB(NOW(), INTERVAL 5 YEAR)
        ORDER BY m.date
        """
        
        # Cargar datos en DataFrame (como array asociativo en PHP)
        df = pd.read_sql(query, connection)
        connection.close()
        
        return df
    
    def create_features(self, df):
        """
        Crea características (features) para el modelo
        Transforma datos brutos en información útil para predicción
        """
        
        # Crear nuevas características calculadas
        # En PHP sería: $df['home_shots_accuracy'] = $df['hst'] / $df['hs'];
        
        # Precisión de tiros
        df['home_shots_accuracy'] = df['hst'] / df['hs'].replace(0, 1)
        df['away_shots_accuracy'] = df['ast'] / df['as_shots'].replace(0, 1)
        
        # Eficiencia goleadora
        df['home_goals_per_shot'] = df['fthg'] / df['hs'].replace(0, 1)
        df['away_goals_per_shot'] = df['ftag'] / df['as_shots'].replace(0, 1)
        
        # Diferencia de córners
        df['corners_diff'] = df['hc'] - df['ac']
        
        # Agresividad (faltas + tarjetas)
        df['home_aggression'] = df['hf'] + (df['hy'] * 2) + (df['hr'] * 5)
        df['away_aggression'] = df['af'] + (df['ay'] * 2) + (df['ar'] * 5)
        
        # Si tenemos xG
        df['home_xg_diff'] = df['home_xg'] - df['fthg']  # Diferencia entre xG y goles reales
        df['away_xg_diff'] = df['away_xg'] - df['ftag']
        
        # Momentum (resultados recientes ponderados)
        # Más peso a partidos más recientes
        
        # Lista de todas las características a usar
        self.feature_names = [
            # Estadísticas básicas
            'home_avg_goals_l5', 'away_avg_goals_l5',
            'home_avg_shots_l5', 'away_avg_shots_l5',
            'home_avg_shots_target_l5',
            
            # Estadísticas del partido
            'home_shots_accuracy', 'away_shots_accuracy',
            'home_goals_per_shot', 'away_goals_per_shot',
            'corners_diff',
            'home_aggression', 'away_aggression',
            
            # Head to head
            'h2h_matches', 'h2h_home_wins',
            
            # xG si está disponible
            'home_xg', 'away_xg',
            'home_xg_diff', 'away_xg_diff'
        ]
        
     # Eliminar características que no existen o tienen muchos NaN
        available_features = []
        for feature in self.feature_names:
            if feature in df.columns:
                # Verificar que no más del 50% sean valores nulos
                if df[feature].notna().sum() / len(df) > 0.5:
                    available_features.append(feature)
        
        self.feature_names = available_features
        
        # Rellenar valores faltantes con la media
        # En PHP: $df[$feature] = $df[$feature] ?? $promedio;
        for feature in self.feature_names:
            df[feature] = df[feature].fillna(df[feature].mean())
        
        return df
    
    def train(self):
        """
        Entrena el modelo Random Forest
        """
        print("🔄 Cargando datos...")
        df = self.load_match_data()
        
        print("🔧 Creando características...")
        df = self.create_features(df)
        
        # Preparar datos para entrenamiento
        # X = características, y = resultado
        X = df[self.feature_names]
        y = df['ftr']  # H, D, o A
        
        # Normalizar datos (importante para mejor rendimiento)
        X_scaled = self.scaler.fit_transform(X)
        
        # Dividir en entrenamiento y prueba (80/20)
        X_train, X_test, y_train, y_test = train_test_split(
            X_scaled, y, test_size=0.2, random_state=42, stratify=y
        )
        
        print("🌲 Entrenando Random Forest...")
        
        # Configurar Random Forest con parámetros optimizados
        self.model = RandomForestClassifier(
            n_estimators=200,      # 200 árboles
            max_depth=15,          # Profundidad máxima
            min_samples_split=10,  # Mínimo de muestras para dividir
            min_samples_leaf=5,    # Mínimo de muestras en hoja
            max_features='sqrt',   # sqrt de características en cada división
            random_state=42,
            n_jobs=-1              # Usar todos los procesadores
        )
        
        # Entrenar
        self.model.fit(X_train, y_train)
        
        # Evaluar
        print("\n📊 Evaluación del modelo:")
        y_pred = self.model.predict(X_test)
        
        # Precisión general
        accuracy = accuracy_score(y_test, y_pred)
        print(f"Precisión: {accuracy * 100:.2f}%")
        
        # Reporte detallado
        print("\nReporte de clasificación:")
        print(classification_report(y_test, y_pred))
        
        # Validación cruzada (más robusta)
        cv_scores = cross_val_score(self.model, X_scaled, y, cv=5)
        print(f"\nValidación cruzada (5-fold): {cv_scores.mean():.2f} (+/- {cv_scores.std() * 2:.2f})")
        
        # Importancia de características
        self.analyze_feature_importance()
        
        # Guardar modelo
        self.save_model()
        
        return accuracy
    
    def analyze_feature_importance(self):
        """
        Analiza qué características son más importantes
        """
        importances = self.model.feature_importances_
        indices = np.argsort(importances)[::-1]
        
        print("\n🎯 Top 10 características más importantes:")
        for i in range(min(10, len(self.feature_names))):
            print(f"{i+1}. {self.feature_names[indices[i]]}: {importances[indices[i]]:.4f}")
    
    def save_model(self):
        """
        Guarda el modelo y metadatos
        """
        # Guardar modelo
        joblib.dump(self.model, '../models/football_rf_advanced.pkl')
        
        # Guardar escalador
        joblib.dump(self.scaler, '../models/scaler.pkl')
        
        # Guardar metadatos
        metadata = {
            'features': self.feature_names,
            'training_date': datetime.now().isoformat(),
            'model_params': self.model.get_params(),
            'performance': {
                'accuracy': float(self.model.score(
                    self.scaler.transform(self.load_match_data()[self.feature_names]),
                    self.load_match_data()['ftr']
                ))
            }
        }
        
        with open('../models/model_metadata.json', 'w') as f:
            json.dump(metadata, f, indent=4)
        
        print("\n✅ Modelo guardado exitosamente!")

# Configuración de base de datos - obtener de wp-config.php
db_config = {
    'host': 'localhost',
    'user': 'tu_usuario',
    'password': 'tu_password',
    'database': 'tu_base_datos'
}

# Ejecutar si se llama directamente
if __name__ == "__main__":
    predictor = AdvancedFootballPredictor(db_config)
    predictor.train()