<?php
require_once 'db.php';

$db      = get_db();
$site_id = (int)($_GET['id'] ?? 0);

$site = $db->prepare("SELECT * FROM SITE WHERE id_site = ?");
$site->execute([$site_id]);
$site = $site->fetch();

if (!$site) {
    header('Location: index.php');
    exit;
}

$clients = $db->prepare("
    SELECT c.*, sr.adresse_reseau, sr.premiere_ip, sr.prefixe,
           r.nom AS routeur_nom
    FROM CLIENT c
    LEFT JOIN SOUS_RESEAU sr    ON c.id_sous_reseau = sr.id_sous_reseau
    LEFT JOIN ROUTEUR_PE r      ON c.id_routeur_pe  = r.id_routeur_pe
    LEFT JOIN PLAGE_ADRESSES pa ON sr.id_plage       = pa.id_plage
    WHERE pa.id_site = ?
    ORDER BY c.id_client
");
$clients->execute([$site_id]);
$clients = $clients->fetchAll();

$sites = $db->query("SELECT * FROM SITE ORDER BY id_site")->fetchAll();

// ── Suppression ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $del = (int)$_POST['delete_id'];
    $sr  = $db->prepare("SELECT id_sous_reseau FROM CLIENT WHERE id_client = ?");
    $sr->execute([$del]);
    $row = $sr->fetch();
    if ($row && $row['id_sous_reseau']) {
        $db->prepare("UPDATE SOUS_RESEAU SET etat = 'libre' WHERE id_sous_reseau = ?")
           ->execute([$row['id_sous_reseau']]);
    }
    $db->prepare("DELETE FROM CLIENT WHERE id_client = ?")->execute([$del]);
    header("Location: site.php?id=$site_id");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site['nom_site']) ?> — IPAM</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>

<header class="navbar">
    <a class="brand" href="index.php">
        <i class="bi bi-diagram-3-fill"></i> IPAM
    </a>
    <nav>
        <a href="index.php"><i class="bi bi-people-fill"></i> Clients</a>
        <?php foreach ($sites as $s): ?>
        <a href="site.php?id=<?= $s['id_site'] ?>"
           class="<?= $s['id_site'] == $site_id ? 'active' : '' ?>">
            <i class="bi bi-building"></i> <?= htmlspecialchars($s['nom_site']) ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <a href="nouveau_client.php" class="btn-new">
        <i class="bi bi-plus-circle-fill"></i> Nouveau client
    </a>
</header>

<div class="container">

    <div class="page-header">
        <h2>
            <i class="bi bi-building"></i>
            <?= htmlspecialchars($site['nom_site']) ?>
            <span class="badge badge-grey" style="font-size:.75rem">Groupe <?= $site['groupe'] ?></span>
        </h2>
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
                    <th>VRF</th>
                    <th>Sous-réseau</th>
                    <th>Première IP</th>
                    <th>Routeur PE</th>
                    <th>Créé le</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clients as $c): ?>
                <tr>
                    <td><code><?= $c['id_client'] ?></code></td>
                    <td><strong><?= htmlspecialchars($c['nom']) ?></strong></td>
                    <td><span class="badge badge-info"><?= $c['vlan'] ?? '—' ?></span></td>
                    <td>
                        <?php if ($c['nom_vrf']): ?>
                            <code><?= htmlspecialchars($c['nom_vrf']) ?></code>
                        <?php else: ?>
                            <span style="color:#adb5bd">—</span>
                        <?php endif; ?>
                    </td>
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
                    <td><small style="color:#6c757d"><?= $c['date_creation'] ?></small></td>
                    <td style="text-align:right;white-space:nowrap">
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
            <span class="empty-icon"><i class="bi bi-geo-alt"></i></span>
            Aucun client sur ce site.
            <a href="nouveau_client.php">Ajouter un client →</a>
        </div>
    </div>
    <?php endif; ?>

</div>

<div id="toast"></div>
<script src="main.js"></script>
</body>
</html>
