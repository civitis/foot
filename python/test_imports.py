#!/usr/bin/python3.8
import sys
import os

# Agregar ruta de librerías
plugin_libs = '/var/www/vhosts/virtualrolldice.com/httpdocs/wp-content/plugins/football-tipster/python-libs'
if os.path.exists(plugin_libs):
    sys.path.insert(0, plugin_libs)
    print(f"✅ Ruta agregada: {plugin_libs}")

print("🧪 Probando imports...")

try:
    import numpy as np
    print(f"✅ numpy {np.__version__}")
except Exception as e:
    print(f"❌ numpy: {e}")

try:
    import pandas as pd
    print(f"✅ pandas {pd.__version__}")
except Exception as e:
    print(f"❌ pandas: {e}")

try:
    from sklearn.ensemble import RandomForestClassifier
    print("✅ scikit-learn OK")
except Exception as e:
    print(f"❌ scikit-learn: {e}")

try:
    import joblib
    print(f"✅ joblib {joblib.__version__}")
except Exception as e:
    print(f"❌ joblib: {e}")

try:
    import mysql.connector
    print(f"✅ mysql-connector {mysql.connector.__version__}")
except Exception as e:
    print(f"❌ mysql-connector: {e}")

print("🏁 Test completado")