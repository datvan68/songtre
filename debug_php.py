import subprocess
import os

script_path = os.path.join(os.path.dirname(__file__), 'scratch', 'verify_campaign_scoring.php')
try:
    print(f"Running PHP script: {script_path}")
    res = subprocess.run(["php", script_path], capture_output=True, text=True)
    print("STDOUT:")
    print(res.stdout)
    print("STDERR:")
    print(res.stderr)
except Exception as e:
    print("Error running php script:", e)
