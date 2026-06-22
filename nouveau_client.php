<?php
require_once 'db.php';

$db    = get_db();
$error = null;

// ── Chargement des sites ─────────────────────────────────────
$sites = $db->query("SELECT * FROM SITE ORDER BY id_site")->fetchAll();

// ── Soumission du formulaire ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom     = trim($_POST['nom']     ?? '');
    $nom_vrf = 'VRF_' . strtoupper(preg_replace('/[^A-Z0-9]/i', '_', trim($_POST['nom'] ?? '')));
    $id_site = (int)($_POST['id_site']?? 0);

    // ── Validation ───────────────────────────────────────────
    if (!$nom)        $error = "Le nom du client est obligatoire.";
    elseif (!$id_site)$error = "Veuillez sélectionner un site.";

    // ── Récupération automatique du routeur PE via le site ───
    $id_routeur = 0;
    if (!$error && $id_site) {
        $r = $db->prepare("SELECT id_routeur_pe FROM ROUTEUR_PE WHERE id_site = ?");
        $r->execute([$id_site]);
        $id_routeur = (int)$r->fetchColumn();
        if (!$id_routeur) $error = "Aucun routeur PE associé à ce site.";
    }

    // ── Vérif unicité VRF ────────────────────────────────────
    if (!$error) {
        $chk = $db->prepare("SELECT id_client FROM CLIENT WHERE nom_vrf = ?");
        $chk->execute([$nom_vrf]);
        if ($chk->fetch()) $error = "Ce nom VRF ($nom_vrf) est déjà utilisé.";
    }

    // ── Cherche un sous-réseau libre sur le site choisi ──────
    if (!$error) {
        $sr = $db->prepare("
            SELECT sr.id_sous_reseau, sr.adresse_reseau, sr.premiere_ip, sr.prefixe
            FROM SOUS_RESEAU sr
            JOIN PLAGE_ADRESSES pa ON sr.id_plage = pa.id_plage
            WHERE pa.id_site = ? AND sr.etat = 'libre'
            ORDER BY sr.id_sous_reseau
            LIMIT 1
        ");
        $sr->execute([$id_site]);
        $subnet = $sr->fetch();

        if (!$subnet) {
            $error = "Aucun sous-réseau libre disponible sur ce site.";
        }
    }

    // ── Insertion ─────────────────────────────────────────────
    if (!$error) {
        // Marque le sous-réseau comme alloué
        $db->prepare("UPDATE SOUS_RESEAU SET etat = 'alloue' WHERE id_sous_reseau = ?")
           ->execute([$subnet['id_sous_reseau']]);

        // Crée le client (VLAN = 0 provisoire, mis à jour après)
        $ins = $db->prepare("
            INSERT INTO CLIENT (nom, vlan, nom_vrf, id_sous_reseau, id_routeur_pe, date_creation)
            VALUES (?, 0, ?, ?, ?, NOW())
        ");
        $ins->execute([
            $nom,
            $nom_vrf,
            $subnet['id_sous_reseau'],
            $id_routeur,
        ]);

        $new_id = $db->lastInsertId();

        // VLAN = id_client (incrémental, conforme CDC)
        $db->prepare("UPDATE CLIENT SET vlan = ? WHERE id_client = ?")
           ->execute([$new_id, $new_id]);

        header("Location: client_detail.php?id=$new_id&created=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau client — IPAM</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>

<!-- Navbar -->
<header class="navbar">
    <a class="brand" href="index.php">
        <i class="bi bi-diagram-3-fill"></i> IPAM
    </a>
    <nav>
        <a href="index.php"><i class="bi bi-people-fill"></i> Clients</a>
        <?php foreach ($sites as $s): ?>
        <a href="site.php?id=<?= $s['id_site'] ?>">
            <i class="bi bi-building"></i> <?= htmlspecialchars($s['nom_site']) ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <a href="nouveau_client.php" class="btn-new active-nav">
        <i class="bi bi-plus-circle-fill"></i> Nouveau client
    </a>
</header>

<div class="container">

    <a href="index.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Retour à la liste
    </a>

    <div class="page-header">
        <h2><i class="bi bi-person-plus-fill"></i> Créer un nouveau client</h2>
    </div>

    <!-- Erreur éventuelle -->
    <?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="form-layout">

        <!-- Formulaire principal -->
        <div class="card">
            <div class="card-header blue">
                <span><i class="bi bi-person-fill"></i> Informations client</span>
            </div>
            <div class="card-body">
                <form method="post" id="form-client">

                    <div class="form-group">
                        <label for="nom">Nom du client <span class="required">*</span></label>
                        <input type="text" id="nom" name="nom" class="form-control"
                               placeholder="ex : Acme Corp"
                               value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>"
                               oninput="autoVrf(this.value)"
                               required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>VLAN</label>
                            <div class="routeur-badge has-value">
                                <i class="bi bi-hash"></i>
                                <span>Attribué automatiquement (= numéro client)</span>
                            </div>
                            <span class="hint">Le VLAN sera identique à l'identifiant client généré.</span>
                        </div>

                        <div class="form-group">
                            <label>Nom VRF</label>
                            <div class="routeur-badge has-value" id="vrf-display">
                                <i class="bi bi-diagram-2"></i>
                                <span id="vrf-text">Calculé automatiquement depuis le nom du client</span>
                            </div>
                            <input type="hidden" id="nom_vrf" name="nom_vrf" value="">
                            <span class="hint">Format : VRF_NOM_CLIENT</span>
                        </div>
                    </div>

                    <!-- Site — pleine largeur -->
                    <div class="form-group">
                        <label for="id_site">Site <span class="required">*</span></label>
                        <select id="id_site" name="id_site" class="form-select" required
                                onchange="updateRouteurInfo(this.value)">
                            <option value="">— Choisir un site —</option>
                            <?php foreach ($sites as $s): ?>
                            <option value="<?= $s['id_site'] ?>"
                                <?= (($_POST['id_site'] ?? '') == $s['id_site']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['nom_site']) ?> (Groupe <?= $s['groupe'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint">Le premier sous-réseau /28 libre sera alloué automatiquement.</span>
                    </div>

                    <!-- Routeur PE déduit automatiquement, affiché en lecture seule -->
                    <div class="form-group">
                        <label>Routeur PE associé</label>
                        <div id="routeur-info" class="routeur-badge">
                            <i class="bi bi-hdd-network"></i>
                            <span id="routeur-text">Sélectionnez un site pour voir le routeur PE.</span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="index.php" class="btn btn-outline">
                            <i class="bi bi-x"></i> Annuler
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg"></i> Créer le client
                        </button>
                    </div>

                </form>
            </div>
        </div>

        <!-- Panneau info -->
        <div class="side-info">
            <div class="card">
                <div class="card-header dark">
                    <span><i class="bi bi-info-circle"></i> Ce qui sera créé</span>
                </div>
                <div class="card-body">
                    <ul class="info-list">
                        <li>
                            <i class="bi bi-hdd-network-fill" style="color:#28a745"></i>
                            Un sous-réseau <code>/28</code> libre est alloué automatiquement sur le site choisi.
                        </li>
                        <li>
                            <i class="bi bi-diagram-2-fill" style="color:#0d6efd"></i>
                            Une VRF dédiée est associée au routeur PE du site sélectionné.
                        </li>
                        <li>
                            <i class="bi bi-file-code-fill" style="color:#6f42c1"></i>
                            La configuration Cisco IOS est générée automatiquement.
                        </li>
                        <li>
                            <i class="bi bi-hash" style="color:#fd7e14"></i>
                            Le Route Distinguisher sera <code>65556:&lt;id_client&gt;</code>.
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Disponibilité par site -->
            <div class="card" style="margin-top:1rem">
                <div class="card-header dark">
                    <span><i class="bi bi-bar-chart-fill"></i> Sous-réseaux disponibles</span>
                </div>
                <div class="card-body" style="padding:0">
                    <table class="kv-table">
                        <?php
                        $dispo = $db->query("
                            SELECT s.nom_site, s.groupe,
                                   SUM(sr.etat = 'libre')  AS libres,
                                   SUM(sr.etat = 'alloue') AS alloues
                            FROM SITE s
                            JOIN PLAGE_ADRESSES pa ON pa.id_site = s.id_site
                            JOIN SOUS_RESEAU sr    ON sr.id_plage = pa.id_plage
                            GROUP BY s.id_site
                            ORDER BY s.id_site
                        ")->fetchAll();
                        foreach ($dispo as $d):
                        ?>
                        <tr>
                            <th><?= htmlspecialchars($d['nom_site']) ?></th>
                            <td>
                                <span class="badge badge-libre"><?= $d['libres'] ?> libres</span>
                                <span class="badge badge-alloue" style="margin-left:.3rem"><?= $d['alloues'] ?> alloués</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<div id="toast"></div>
<script src="main.js"></script>
<script>
// ── Mapping site → routeur PE ────────────────────────────────
const routeurMap = {
    <?php
    $pes = $db->query("SELECT id_site, nom, adresse_loopback FROM ROUTEUR_PE")->fetchAll();
    foreach ($pes as $pe):
    ?>
    <?= $pe['id_site'] ?>: "<?= htmlspecialchars($pe['nom']) ?> — <?= htmlspecialchars($pe['adresse_loopback']) ?>",
    <?php endforeach; ?>
};

function updateRouteurInfo(siteId) {
    const span = document.getElementById('routeur-text');
    if (siteId && routeurMap[siteId]) {
        span.textContent = routeurMap[siteId];
        document.getElementById('routeur-info').classList.add('has-value');
    } else {
        span.textContent = 'Sélectionnez un site pour voir le routeur PE.';
        document.getElementById('routeur-info').classList.remove('has-value');
    }
}

// ── Initialisation si valeur déjà sélectionnée (erreur POST) ─
const siteSelect = document.getElementById('id_site');
if (siteSelect.value) updateRouteurInfo(siteSelect.value);

// ── Auto-génération du nom VRF (non modifiable, conforme CDC) ─
function autoVrf(val) {
    const clean = 'VRF_' + val.toUpperCase().replace(/[^A-Z0-9]/g, '_');
    document.getElementById('nom_vrf').value = clean;
    const txt = document.getElementById('vrf-text');
    if (txt) txt.textContent = clean || 'Calculé automatiquement depuis le nom du client';
}
// Appel initial si valeur déjà présente (retour POST)
autoVrf(document.getElementById('nom').value);
</script>

<style>
/* ── Styles spécifiques à cette page ────────────────────────── */
.form-layout {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 1.2rem;
    align-items: start;
}

@media (max-width: 900px) {
    .form-layout { grid-template-columns: 1fr; }
    .side-info { order: -1; }
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

@media (max-width: 600px) {
    .form-row { grid-template-columns: 1fr; }
}

.required { color: #dc3545; }

.routeur-badge {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .5rem .75rem;
    border-radius: 6px;
    background: #f1f3f5;
    border: 1px solid #dee2e6;
    color: #868e96;
    font-size: .9rem;
    min-height: 38px;
    transition: all .2s;
}

.routeur-badge.has-value {
    background: #e7f5ff;
    border-color: #74c0fc;
    color: #1971c2;
    font-weight: 500;
}

.routeur-badge i {
    font-size: 1rem;
    flex-shrink: 0;
}

.info-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: .85rem;
}

.info-list li {
    display: flex;
    align-items: flex-start;
    gap: .6rem;
    font-size: .88rem;
    color: #495057;
    line-height: 1.5;
}

.info-list li i {
    font-size: 1rem;
    margin-top: .1rem;
    flex-shrink: 0;
}
</style>

</body>
</html>
