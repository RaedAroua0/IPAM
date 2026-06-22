-- ============================================================
--  IPAM - Script de création de la base de données MySQL
-- ============================================================

CREATE DATABASE IF NOT EXISTS ipam CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ipam;

-- ------------------------------------------------------------
--  SITE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS SITE (
    id_site     INT          PRIMARY KEY,
    nom_site    VARCHAR(50)  NOT NULL,
    groupe      INT          NOT NULL CHECK (groupe IN (1, 2, 3))
);

-- ------------------------------------------------------------
--  ROUTEUR_PE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ROUTEUR_PE (
    id_routeur_pe    INT          PRIMARY KEY,
    id_site          INT          NOT NULL,
    nom              VARCHAR(100) NOT NULL,
    modele           VARCHAR(100) DEFAULT 'Cisco 4221',
    adresse_loopback VARCHAR(20),
    FOREIGN KEY (id_site) REFERENCES SITE(id_site)
);

-- ------------------------------------------------------------
--  PLAGE_ADRESSES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS PLAGE_ADRESSES (
    id_plage    INT         PRIMARY KEY,
    id_site     INT         NOT NULL,
    reseau      VARCHAR(20) NOT NULL,
    prefixe     INT         NOT NULL DEFAULT 24,
    FOREIGN KEY (id_site) REFERENCES SITE(id_site)
);

-- ------------------------------------------------------------
--  SOUS_RESEAU
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS SOUS_RESEAU (
    id_sous_reseau  INT         PRIMARY KEY AUTO_INCREMENT,
    id_plage        INT         NOT NULL,
    adresse_reseau  VARCHAR(20) NOT NULL,
    premiere_ip     VARCHAR(20) NOT NULL,
    prefixe         INT         NOT NULL DEFAULT 28,
    etat            ENUM('libre', 'reserve', 'alloue') NOT NULL DEFAULT 'libre',
    FOREIGN KEY (id_plage) REFERENCES PLAGE_ADRESSES(id_plage)
);

-- ------------------------------------------------------------
--  CLIENT
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS CLIENT (
    id_client       INT          PRIMARY KEY AUTO_INCREMENT,
    id_sous_reseau  INT,
    id_routeur_pe   INT,
    nom             VARCHAR(100) NOT NULL,
    vlan            INT          UNIQUE,        -- ← CORRECTION : unicité du VLAN
    nom_vrf         VARCHAR(100) UNIQUE,        -- ← BONUS : unicité de la VRF aussi
    date_creation   DATE         DEFAULT (CURRENT_DATE),
    FOREIGN KEY (id_sous_reseau) REFERENCES SOUS_RESEAU(id_sous_reseau),
    FOREIGN KEY (id_routeur_pe)  REFERENCES ROUTEUR_PE(id_routeur_pe)
);
