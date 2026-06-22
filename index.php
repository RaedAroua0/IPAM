<?php
require_once 'db.php';

// ── Suppression client ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $db = get_db();
    $id = (int) $_POST['delete_id'];

    // Libérer le sous-réseau avant suppression
    $sr = $db->prepare("SELECT id_sous_reseau FROM CLIENT WHERE id_client = ?");
    $sr->execute([$id]);
    $row = $sr->fetch();
    if ($row && $row['id_sous_reseau']) {
        $db->prepare("UPDATE SOUS_RESEAU SET etat = 'libre' WHERE id_sous_reseau = ?")
           ->execute([$row['id_sous_reseau']]);
    }
    $db->prepare("DELETE FROM CLIENT WHERE id_client = ?")->execute([$id]);
    header('Location: index.php');
    exit;
}

// ── Données ────────────────────────────────────────────────
$db = get_db();

$clients = $db->query("
    SELECT c.*, sr.adresse_reseau, sr.premiere_ip, sr.prefixe,
           r.nom AS routeur_nom, s.nom_site, s.groupe
    FROM CLIENT c
    LEFT JOIN SOUS_RESEAU sr    ON c.id_sous_reseau = sr.id_sous_reseau
    LEFT JOIN ROUTEUR_PE r      ON c.id_routeur_pe  = r.id_routeur_pe
    LEFT JOIN PLAGE_ADRESSES pa ON sr.id_plage       = pa.id_plage
    LEFT JOIN SITE s            ON pa.id_site         = s.id_site
    ORDER BY c.id_client
")->fetchAll();

$sites = $db->query("SELECT * FROM SITE ORDER BY id_site")->fetchAll();

$stats = $db->query("
    SELECT
        SUM(etat = 'libre')   AS libres,
        SUM(etat = 'alloue')  AS alloues
    FROM SOUS_RESEAU
")->fetch();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clients — IPAM</title>
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
        <a href="index.php" class="active"><i class="bi bi-people-fill"></i> Clients</a>
        <?php foreach ($sites as $s): ?>
        <a href="site.php?id=<?= $s['id_site'] ?>">
            <i class="bi bi-building"></i> <?= htmlspecialchars($s['nom_site']) ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <a href="nouveau_client.php" class="btn-new">
        <i class="bi bi-plus-circle-fill"></i> Nouveau client
    </a>
</header>

<div class="container">

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card blue">
            <div class="icon"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="val"><?= count($clients) ?></div>
                <div class="lbl">Clients</div>
            </div>
        </div>
        <div class="stat-card green">
            <div class="icon"><i class="bi bi-hdd-network"></i></div>
            <div>
                <div class="val"><?= (int)$stats['libres'] ?></div>
                <div class="lbl">Sous-réseaux libres</div>
            </div>
        </div>
        <div class="stat-card red">
            <div class="icon"><i class="bi bi-hdd-fill"></i></div>
            <div>
                <div class="val"><?= (int)$stats['alloues'] ?></div>
                <div class="lbl">Sous-réseaux alloués</div>
            </div>
        </div>
        <div class="stat-card orange">
            <div class="icon"><i class="bi bi-building"></i></div>
            <div>
                <div class="val"><?= count($sites) ?></div>
                <div class="lbl">Sites</div>
            </div>
        </div>
    </div>

    <!-- Liste clients -->
    <div class="page-header">
        <h2><i class="bi bi-people-fill"></i> Tous les clients</h2>
        <span class="badge-count"><?= count($clients) ?> client(s)</span>
    </div>

    <?php if (count($clients) > 0): ?>
    <div class="card">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#ID</th>
                    <th>Nom</th>
                    <th>VLAN</th>
                    <th>Sous-réseau</th>
                    <th>Première IP</th>
                    <th>Routeur PE</th>
                    <th>Site</th>
                    <th>Créé le</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clients as $c): ?>
                <tr>
                    <td><code><?= $c['id_client'] ?></code></td>
                    <td><strong><?= htmlspecialchars($c['nom']) ?></strong></td>
                    <td><span class="badge badge-info"><?= $c['vlan'] ?></span></td>
                    <td>
                        <?php if ($c['adresse_reseau']): ?>
                            <code><?= $c['adresse_reseau'] ?>/<?= $c['prefixe'] ?></code>
                        <?php else: ?>
                            <span style="color:#adb5bd">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['premiere_ip']): ?>
                            <code><?= $c['premiere_ip'] ?></code>
                        <?php else: ?>
                            <span style="color:#adb5bd">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($c['routeur_nom'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($c['nom_site'] ?? '—') ?></td>
                    <td><small style="color:#6c757d"><?= $c['date_creation'] ?></small></td>
                    <td style="text-align:right; white-space:nowrap">
                        <a href="client_detail.php?id=<?= $c['id_client'] ?>"
                           class="btn btn-primary btn-icon">
                            <i class="bi bi-eye"></i>
                        </a>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Supprimer <?= htmlspecialchars($c['nom'], ENT_QUOTES) ?> ?')">
                            <input type="hidden" name="delete_id" value="<?= $c['id_client'] ?>">
                            <button class="btn btn-danger btn-icon" type="submit">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
    <div class="card">
        <div class="empty-state">
            <span class="empty-icon"><i class="bi bi-inbox"></i></span>
            Aucun client enregistré.
            <a href="nouveau_client.php">Ajouter le premier client →</a>
        </div>
    </div>
    <?php endif; ?>

</div>

<div id="toast"></div>
<script src="main.js"></script>
</body>
</html>