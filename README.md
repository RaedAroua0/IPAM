# IPAM — IP Address Management

Plateforme simplifiée de gestion d'adresses IP développée dans le cadre de la SAE 2.03 (IUT de Villetaneuse, 2025-2026).

---

## Sommaire

1. [Présentation du projet](#1-présentation-du-projet)
2. [Architecture technique](#2-architecture-technique)
3. [Prérequis](#3-prérequis)
4. [Installation et lancement](#4-installation-et-lancement)
5. [Utilisation de l'application](#5-utilisation-de-lapplication)
6. [Base de données](#6-base-de-données)
7. [Plan d'adressage](#7-plan-dadressage)
8. [Génération de configuration Cisco](#8-génération-de-configuration-cisco)
9. [Choix techniques](#9-choix-techniques)
10. [Tests et validation](#10-tests-et-validation)
11. [Limites et évolutions possibles](#11-limites-et-évolutions-possibles)

---

## 1. Présentation du projet

Cette plateforme IPAM permet à une start-up fournisseur d'accès Internet de gérer l'attribution d'adresses IP à ses clients. Elle couvre les besoins suivants :

- attribution automatique d'un sous-réseau `/28` à chaque nouveau client ;
- stockage centralisé dans une base MySQL accessible depuis tous les sites ;
- interface web pour consulter, créer et supprimer des clients ;
- génération automatique de la configuration Cisco IOS associée (VRF, sous-interface, MP-BGP, OSPF).

---

## 2. Architecture technique

```
┌─────────────────────────────────────────────────────┐
│                   Navigateur web                    │
└───────────────────────┬─────────────────────────────┘
                        │ HTTP
┌───────────────────────▼─────────────────────────────┐
│              Serveur Apache2 + PHP 8.x              │
│  index.php  │  site.php  │  nouveau_client.php      │
│  client_detail.php  │  db.php  │  style.css         │
└───────────────────────┬─────────────────────────────┘
                        │ PDO / MySQL
┌───────────────────────▼─────────────────────────────┐
│           Serveur MySQL central (192.168.1.145)     │
│  Base de données : ipam                             │
└─────────────────────────────────────────────────────┘
```

**Fichiers du projet :**

| Fichier               | Rôle                                                    |
|-----------------------|---------------------------------------------------------|
| `init.py`             | Script d'initialisation : schéma SQL, données de base, génération des /28 |
| `init_db.sql`         | Script SQL de création des tables                       |
| `db.php`              | Connexion PDO à la base MySQL                           |
| `index.php`           | Page d'accueil — liste de tous les clients              |
| `site.php`            | Vue filtrée par site                                    |
| `nouveau_client.php`  | Formulaire de création d'un client                      |
| `client_detail.php`   | Fiche détaillée d'un client + config Cisco générée      |
| `style.css`           | Feuille de style globale                                |
| `main.js`             | Interactions front-end (toast, auto-dismiss)            |

---

## 3. Prérequis

### Serveur

| Composant  | Version minimum | Notes                        |
|------------|-----------------|------------------------------|
| Linux      | Ubuntu 22.04+   | Debian compatible             |
| Apache2    | 2.4+            | Module `mod_rewrite` activé  |
| PHP        | 8.0+            | Extension `pdo_mysql` requise |
| MySQL      | 8.0+            | Accessible sur le réseau     |
| Python     | 3.8+            | Pour le script d'initialisation |
| pymysql    | 1.0+            | `pip install pymysql`        |

### Vérifier que les extensions PHP sont présentes

```bash
php -m | grep pdo
# Doit afficher : pdo_mysql
```

---

### Installation des dépendances système

```bash
# Installer Apache2, PHP et le driver MySQL
apt install apache2 php8.3 libapache2-mod-php8.3 php8.3-mysql -y

# Activer le module PHP et redémarrer Apache
systemctl restart apache2

# Installer pymysql pour le script d'initialisation
pip install pymysql --break-system-packages
```

### Configurer MariaDB pour les connexions réseau

Si votre serveur MySQL/MariaDB est sur une machine distante, deux étapes sont nécessaires :

**1. Autoriser l'écoute réseau** dans `/etc/mysql/mariadb.conf.d/50-server.cnf` :
```ini
bind-address = 0.0.0.0
```

**2. Accorder les droits à l'utilisateur depuis le réseau** :
```sql
CREATE USER 'root'@'%' IDENTIFIED BY 'votre_mot_de_passe';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

Puis redémarrer MariaDB :
```bash
systemctl restart mariadb
```

## 4. Installation et lancement

### Étape 1 — Récupérer les fichiers

Copier le dossier `IPAM/` dans la racine web d'Apache :

```bash
sudo cp -r IPAM/ /var/www/html/ipam
sudo chown -R www-data:www-data /var/www/html/ipam
```

### Étape 2 — Configurer la connexion à la base

Éditer `db.php` et adapter les paramètres à votre environnement :

```php
define('DB_HOST', '192.168.1.145');   // IP du serveur MySQL central
define('DB_PORT', '3306');
define('DB_NAME', 'ipam');
define('DB_USER', 'root');
define('DB_PASS', 'changeme');        // À remplacer par un mot de passe sécurisé
```

Faire de même dans `init.py` (variables `DB_HOST`, `DB_USER`, `DB_PASS`).

### Étape 3 — Initialiser la base de données

```bash
pip install pymysql
python3 init.py
```

Ce script effectue automatiquement les opérations suivantes :

1. démarre le service MySQL s'il n'est pas actif ;
2. exécute `init_db.sql` pour créer les tables ;
3. insère les 3 sites (Paris, Lyon, Bordeaux) et leurs routeurs PE ;
4. génère tous les sous-réseaux `/28` à partir des plages `/24` ;
5. démarre Apache2.

Sortie attendue :

```
╔══════════════════════════════════════╗
║        IPAM — Initialisation         ║
╚══════════════════════════════════════╝

── Démarrage de mysql ──────────────────
  [OK]    mysql démarré avec succès.
── Exécution du fichier SQL ────────────
  [OK]    init_db.sql exécuté avec succès.
── Sites ───────────────────────────────
  [OK]    Site Paris (groupe 1) créé.
  [OK]    Site Lyon (groupe 2) créé.
  [OK]    Site Bordeaux (groupe 3) créé.
...
  Total : 48 sous-réseaux insérés.

✔  Initialisation terminée avec succès.
✔  Tous les services sont actifs.
   Accès à l'application : http://localhost/
```

### Étape 4 — Accéder à l'application

Ouvrir un navigateur et aller sur :

```
http://localhost/ipam/
```

ou, depuis un autre poste sur le réseau :

```
http://<IP_DU_SERVEUR>/ipam/
```

---

## 5. Utilisation de l'application

### Page d'accueil — liste des clients (`index.php`)

Affiche un tableau de bord avec :

- le nombre total de clients ;
- le nombre de sous-réseaux libres et alloués ;
- le nombre de sites.

Le tableau liste tous les clients avec leurs informations réseau. Deux actions sont disponibles par ligne : **voir le détail** (icône œil) ou **supprimer** (icône poubelle, avec confirmation).

### Vue par site (`site.php?id=<N>`)

Accessible via la barre de navigation (Sites Paris / Lyon / Bordeaux). Affiche uniquement les clients rattachés au site sélectionné.

### Créer un client (`nouveau_client.php`)

1. Saisir le **nom du client**.
2. Sélectionner le **site** (groupe 1, 2 ou 3).
3. Cliquer sur **Créer le client**.

Le système effectue automatiquement :

- calcul du VLAN (= identifiant client, incrémental) ;
- génération du nom VRF (`VRF_NOM_CLIENT`) ;
- attribution du premier sous-réseau `/28` libre sur le site choisi ;
- association au routeur PE du site ;
- génération de la configuration Cisco IOS.

### Fiche client (`client_detail.php?id=<N>`)

Affiche :

- les informations client (ID, nom, VLAN, VRF, Route Distinguisher, date de création) ;
- les informations réseau (sous-réseau, première IP/passerelle, masque, routeur PE, loopback) ;
- la **configuration Cisco IOS complète** copiable en un clic.

La suppression d'un client depuis cette page libère automatiquement son sous-réseau (état `libre`).

---

## 6. Base de données

### Schéma

```
SITE ──< PLAGE_ADRESSES ──< SOUS_RESEAU
  │                              │
  └──< ROUTEUR_PE ──────────< CLIENT >──┘
```

### Tables

**`SITE`** — les 3 sites de production

| Colonne   | Type    | Description              |
|-----------|---------|--------------------------|
| id_site   | INT PK  | Identifiant              |
| nom_site  | VARCHAR | Nom du site              |
| groupe    | INT     | Numéro de groupe (1, 2, 3) |

**`ROUTEUR_PE`** — routeurs Provider Edge (Cisco 4221)

| Colonne          | Type    | Description                |
|------------------|---------|----------------------------|
| id_routeur_pe    | INT PK  |                            |
| id_site          | INT FK  | Site associé               |
| nom              | VARCHAR | Nom du routeur             |
| modele           | VARCHAR | Modèle (Cisco 4221)        |
| adresse_loopback | VARCHAR | IP de loopback OSPF        |

**`PLAGE_ADRESSES`** — plages /24 par site

| Colonne  | Type    | Description            |
|----------|---------|------------------------|
| id_plage | INT PK  |                        |
| id_site  | INT FK  |                        |
| reseau   | VARCHAR | Adresse réseau (ex. 164.166.1.0) |
| prefixe  | INT     | 24                     |

**`SOUS_RESEAU`** — sous-réseaux /28 générés

| Colonne        | Type                      | Description                     |
|----------------|---------------------------|---------------------------------|
| id_sous_reseau | INT PK AUTO_INCREMENT     |                                 |
| id_plage       | INT FK                    |                                 |
| adresse_reseau | VARCHAR                   | Ex. 164.166.1.0                 |
| premiere_ip    | VARCHAR                   | Première IP utilisable          |
| prefixe        | INT                       | 28                              |
| etat           | ENUM(libre, reserve, alloue) | État d'allocation            |

**`CLIENT`** — clients de l'opérateur

| Colonne        | Type        | Description                        |
|----------------|-------------|------------------------------------|
| id_client      | INT PK AUTO | Identifiant incrémental = VLAN      |
| id_sous_reseau | INT FK      | Sous-réseau alloué                 |
| id_routeur_pe  | INT FK      | Routeur PE du site                 |
| nom            | VARCHAR     | Nom du client                      |
| vlan           | INT UNIQUE  | VLAN = id_client                   |
| nom_vrf        | VARCHAR     | Nom de la VRF (unique)             |
| date_creation  | DATE        | Date d'ajout                       |

---

## 7. Plan d'adressage

La société dispose de la plage publique `164.166.0.0/16`.

| Groupe | Site         | Plage allouée      | Sous-réseaux /28 disponibles |
|--------|--------------|--------------------|------------------------------|
| 1      | Site Paris   | 164.166.1.0/24     | 16                           |
| 2      | Site Lyon    | 164.166.2.0/24     | 16                           |
| 3      | Site Bordeaux| 164.166.3.0/24     | 16                           |

Chaque plage /24 génère **16 sous-réseaux /28** de 14 adresses hôtes chacun. La première adresse utilisable du sous-réseau est attribuée comme passerelle (adresse de sous-interface PE).

---

## 8. Génération de configuration Cisco

Pour chaque client, la plateforme génère automatiquement la configuration IOS suivante :

```cisco
! ── 1. Définition de la VRF
ip vrf VRF_CLIENT
 rd 65556:<id_client>
 route-target export 65556:<id_client>
 route-target import 65556:<id_client>

! ── 2. Sous-interface vers le client
interface GigabitEthernet0/0.<vlan>
 encapsulation dot1Q <vlan>
 ip vrf forwarding VRF_CLIENT
 ip address <premiere_ip> 255.255.255.240
 no shutdown

! ── 3. MP-BGP
router bgp 65556
 address-family vpnv4
  neighbor <IP_PE_VOISIN> activate
  neighbor <IP_PE_VOISIN> send-community extended
 address-family ipv4 vrf VRF_CLIENT
  redistribute connected

! ── 4. OSPF
router ospf 1
 network <LOOPBACK_IP> 0.0.0.0 area 0
```

**Paramètres fixes :**

- AS de l'opérateur : `65556`
- Route Distinguisher : `65556:<id_client>`
- Numéro de VLAN = Numéro de client (incrémental)
- Masque de sous-réseau : `255.255.255.240` (/28)

---

## 9. Choix techniques

| Composant      | Technologie choisie | Justification                                      |
|----------------|---------------------|----------------------------------------------------|
| Backend        | PHP 8 (PDO)         | Compatible Apache, syntaxe simple, PDO sécurisé    |
| Base de données| MySQL 8             | SQL standard, accessible en réseau, performance    |
| Frontend       | HTML/CSS/JS natif   | Pas de dépendance lourde, chargement rapide        |
| Icônes         | Bootstrap Icons CDN | Légères, variées, sans JS requis                   |
| Init BDD       | Python 3 + pymysql  | Automatisation complète, lisible, multiplateforme  |
| Génération /28 | `ipaddress` (Python)| Module stdlib précis, pas de bibliothèque externe  |

La configuration Cisco est générée côté serveur PHP sous forme de texte brut. Elle est affichée dans un bloc `<pre>` et copiable en un clic. Le push automatique sur les routeurs (via SSH/Netmiko) n'est pas implémenté dans ce POC : la configuration est destinée à être copiée-collée par l'administrateur réseau.

---

## 10. Tests et validation

### Tests fonctionnels réalisés

| Scénario                                             | Résultat attendu                                         | Résultat obtenu |
|------------------------------------------------------|----------------------------------------------------------|-----------------|
| Créer un client sur Site Paris                       | Sous-réseau /28 alloué, VLAN = id_client, VRF créée      | ✓               |
| Créer un second client sur le même site              | Sous-réseau /28 suivant alloué                           | ✓               |
| Supprimer un client                                  | Sous-réseau repasse en état `libre`                      | ✓               |
| Recréer un client après suppression                  | Le sous-réseau libéré est réattribué en premier          | ✓               |
| Créer un client avec un nom déjà utilisé (même VRF) | Message d'erreur affiché, pas d'insertion                | ✓               |
| Consulter la vue Site Lyon                           | Seuls les clients du site Lyon sont affichés             | ✓               |
| Épuiser tous les /28 d'un site (16 clients)          | Message "Aucun sous-réseau libre disponible"             | ✓               |
| Accéder à un id_client inexistant en URL             | Redirection automatique vers index.php                   | ✓               |

### Vérification de la base de données

```sql
-- Vérifier les sous-réseaux libres
SELECT COUNT(*) FROM SOUS_RESEAU WHERE etat = 'libre';

-- Vérifier la cohérence VLAN = id_client
SELECT id_client, vlan FROM CLIENT WHERE id_client != vlan;
-- Doit retourner 0 lignes

-- Vérifier qu'aucun sous-réseau n'est alloué à plusieurs clients
SELECT id_sous_reseau, COUNT(*) FROM CLIENT
GROUP BY id_sous_reseau HAVING COUNT(*) > 1;
-- Doit retourner 0 lignes
```

---

## 11. Limites et évolutions possibles

- **Sécurité** : les identifiants de connexion MySQL sont actuellement en clair dans les fichiers de configuration. En production, ils devraient être déplacés dans un fichier `.env` non versionné.
- **Push automatique sur routeur** : la configuration Cisco est générée mais non déployée automatiquement. Une évolution avec `Netmiko` permettrait de pousser la config directement via SSH sur les routeurs PE.
- **État "réservé"** : l'ENUM prévoit l'état `reserve` (préréservation avant signature de contrat) mais cette fonctionnalité n'est pas encore exposée dans l'interface.
- **Authentification** : l'application ne comporte pas de système de connexion. En production, un accès authentifié serait nécessaire.
- **Multi-routeurs par site** : le schéma supporte un seul routeur PE par site. Une évolution permettrait d'en associer plusieurs avec répartition de charge.
