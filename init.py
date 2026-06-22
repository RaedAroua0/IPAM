"""
init.py
-------
Initialise la base de données MySQL avec :
  - 3 sites (un par groupe)
  - 1 routeur PE par site (Cisco 4221)
  - 1 plage /24 par site (164.166.1-3.0/24)
  - Tous les sous-réseaux /28 générés automatiquement

Usage : python init.py
"""

import ipaddress
import subprocess
import sys
import pymysql

# ── Config BDD ─────────────────────────────────────────────
DB_HOST = "192.168.1.145"   # IP du serveur MySQL central
DB_PORT = 3306
DB_NAME = "ipam"
DB_USER = "root"
DB_PASS = "changeme"

# ── Données initiales ──────────────────────────────────────
SITES = [
    {"id_site": 1, "nom_site": "Site Paris",    "groupe": 1},
    {"id_site": 2, "nom_site": "Site Lyon",     "groupe": 2},
    {"id_site": 3, "nom_site": "Site Bordeaux", "groupe": 3},
]

ROUTEURS = [
    {"id_routeur_pe": 1, "id_site": 1, "nom": "PE-Paris",    "modele": "Cisco 4221", "adresse_loopback": "10.0.0.1"},
    {"id_routeur_pe": 2, "id_site": 2, "nom": "PE-Lyon",     "modele": "Cisco 4221", "adresse_loopback": "10.0.0.2"},
    {"id_routeur_pe": 3, "id_site": 3, "nom": "PE-Bordeaux", "modele": "Cisco 4221", "adresse_loopback": "10.0.0.3"},
]

PLAGES = [
    {"id_plage": 1, "id_site": 1, "reseau": "164.166.1.0", "prefixe": 24},
    {"id_plage": 2, "id_site": 2, "reseau": "164.166.2.0", "prefixe": 24},
    {"id_plage": 3, "id_site": 3, "reseau": "164.166.3.0", "prefixe": 24},
]


# ── Gestion des services ───────────────────────────────────
def start_service(service):
    print(f"\n── Démarrage de {service} {'─' * (40 - len(service))}")
    try:
        # Vérifie si le service tourne déjà
        result = subprocess.run(
            ["systemctl", "is-active", service],
            capture_output=True, text=True
        )
        if result.stdout.strip() == "active":
            print(f"  [SKIP]  {service} est déjà actif.")
            return

        # Démarre le service
        subprocess.run(["sudo", "systemctl", "start", service], check=True)
        print(f"  [OK]    {service} démarré avec succès.")

    except subprocess.CalledProcessError as e:
        print(f"  [ERREUR] Impossible de démarrer {service} : {e}")
        print(f"           Lance manuellement : sudo systemctl start {service}")
        sys.exit(1)
    except FileNotFoundError:
        print(f"  [ERREUR] systemctl introuvable — es-tu bien sur Linux ?")
        sys.exit(1)

def run_sql_file(filepath="init_db.sql"):
    print("\n── Exécution du fichier SQL ───────────────────────────")
    try:
        with open(filepath, "r") as f:
            result = subprocess.run(
                ["mysql", "-h", DB_HOST, "-P", str(DB_PORT), "-u", DB_USER, f"-p{DB_PASS}"],
                stdin=f,
                capture_output=True,
                text=True
            )
        if result.returncode == 0:
            print(f"  [OK]    {filepath} exécuté avec succès.")
        else:
            print(f"  [ERREUR] {result.stderr}")
            sys.exit(1)
    except FileNotFoundError:
        print(f"  [ERREUR] Fichier introuvable : {filepath}")
        sys.exit(1)
        
def get_connection():
    return pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        db=DB_NAME,
        user=DB_USER,
        password=DB_PASS,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )


def seed_sites(cursor):
    print("\n── Sites ─────────────────────────────────────────────")
    for s in SITES:
        cursor.execute("SELECT 1 FROM SITE WHERE id_site = %s", (s["id_site"],))
        if cursor.fetchone():
            print(f"  [SKIP]  {s['nom_site']} déjà présent.")
        else:
            cursor.execute(
                "INSERT INTO SITE (id_site, nom_site, groupe) VALUES (%s, %s, %s)",
                (s["id_site"], s["nom_site"], s["groupe"]),
            )
            print(f"  [OK]    {s['nom_site']} (groupe {s['groupe']}) créé.")


def seed_routeurs(cursor):
    print("\n── Routeurs PE ───────────────────────────────────────")
    for r in ROUTEURS:
        cursor.execute("SELECT 1 FROM ROUTEUR_PE WHERE id_routeur_pe = %s", (r["id_routeur_pe"],))
        if cursor.fetchone():
            print(f"  [SKIP]  {r['nom']} déjà présent.")
        else:
            cursor.execute(
                "INSERT INTO ROUTEUR_PE (id_routeur_pe, id_site, nom, modele, adresse_loopback) VALUES (%s, %s, %s, %s, %s)",
                (r["id_routeur_pe"], r["id_site"], r["nom"], r["modele"], r["adresse_loopback"]),
            )
            print(f"  [OK]    {r['nom']} ({r['adresse_loopback']}) créé.")


def seed_plages(cursor):
    print("\n── Plages d'adresses ─────────────────────────────────")
    for p in PLAGES:
        cursor.execute("SELECT 1 FROM PLAGE_ADRESSES WHERE id_plage = %s", (p["id_plage"],))
        if cursor.fetchone():
            print(f"  [SKIP]  {p['reseau']}/{p['prefixe']} déjà présente.")
        else:
            cursor.execute(
                "INSERT INTO PLAGE_ADRESSES (id_plage, id_site, reseau, prefixe) VALUES (%s, %s, %s, %s)",
                (p["id_plage"], p["id_site"], p["reseau"], p["prefixe"]),
            )
            print(f"  [OK]    {p['reseau']}/{p['prefixe']} → site {p['id_site']} créée.")


def generate_subnets(cursor):
    print("\n── Génération des sous-réseaux /28 ───────────────────")
    total = 0
    for p in PLAGES:
        network = ipaddress.IPv4Network(f"{p['reseau']}/{p['prefixe']}", strict=False)
        inserted = 0
        for subnet in network.subnets(new_prefix=28):
            adresse_reseau = str(subnet.network_address)
            premiere_ip    = str(subnet.network_address + 1)

            cursor.execute(
                "SELECT 1 FROM SOUS_RESEAU WHERE adresse_reseau = %s AND id_plage = %s",
                (adresse_reseau, p["id_plage"]),
            )
            if cursor.fetchone():
                continue

            cursor.execute(
                "INSERT INTO SOUS_RESEAU (id_plage, adresse_reseau, premiere_ip, prefixe, etat) VALUES (%s, %s, %s, 28, 'libre')",
                (p["id_plage"], adresse_reseau, premiere_ip),
            )
            inserted += 1

        total += inserted
        if inserted:
            print(f"  [OK]    {p['reseau']}/{p['prefixe']} → {inserted} sous-réseaux /28 insérés.")
        else:
            print(f"  [SKIP]  {p['reseau']}/{p['prefixe']} déjà générée.")

    print(f"\n  Total : {total} sous-réseaux insérés.")


def main():
    print("╔══════════════════════════════════════╗")
    print("║        IPAM — Initialisation         ║")
    print("╚══════════════════════════════════════╝")

    # ── 1. Démarrage MySQL ─────────────────────────────────
    start_service("mysql")
    run_sql_file()
    conn = None
    cursor = None

    # ── 2. Initialisation BDD ──────────────────────────────
    try:
        conn   = get_connection()
        cursor = conn.cursor()

        seed_sites(cursor)
        seed_routeurs(cursor)
        seed_plages(cursor)
        generate_subnets(cursor)

        conn.commit()
        print("\n✔  Initialisation terminée avec succès.")

    except pymysql.MySQLError as e:
        print(f"\n[ERREUR MySQL] {e}")
        raise
    finally:
        cursor.close()
        conn.close()

    # ── 3. Démarrage Apache2 ───────────────────────────────
    start_service("apache2")

    print("\n✔  Tous les services sont actifs.")
    print("   Accès à l'application : http://localhost/\n")


if __name__ == "__main__":
    try:
        import pymysql
    except ImportError:
        print("[!] pymysql non installé. Lance : pip install pymysql")
        exit(1)

    main()
