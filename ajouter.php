<?php
// ============================================================
//  ajouter.php - IHM de creation d'un client
// ============================================================
require_once __DIR__ . '/entete.php';

$message = null;
$erreur  = null;
$nouveau = null;

// Traitement du formulaire (methode POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nom = $_POST['nom'] ?? '';
        $id  = ajouterClient($nom);          // <-- automatisation : /28 + VLAN + VRF
        $nouveau = getClient($id);
        $message = "Client #{$id} cree avec succes.";
    } catch (Throwable $e) {
        $erreur = $e->getMessage();
    }
}
?>

<div class="card">
    <h2>Nouveau client</h2>

    <?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($erreur):  ?><div class="alert err"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

    <form method="post" action="ajouter.php">
        <label for="nom">Nom du client (servira de nom de VRF)</label>
        <input type="text" id="nom" name="nom" placeholder="ex: ClientAlpha" required
               pattern="[A-Za-z0-9_\-]{2,100}">
        <button type="submit" class="btn">Creer le client</button>
        <a href="index.php" class="btn ghost">Annuler</a>
    </form>
    <p class="muted" style="margin-top:12px;">
        L'adresse (sous-reseau /28), le VLAN et la VRF sont attribues automatiquement.
    </p>
</div>

<?php if ($nouveau): ?>
<div class="card">
    <h2>Informations generees</h2>
    <div class="kv">
        <div class="k">Numero client</div><div><?= htmlspecialchars((string) $nouveau['id_client']) ?></div>
        <div class="k">Nom</div><div><?= htmlspecialchars($nouveau['nom']) ?></div>
        <div class="k">VLAN</div><div><?= htmlspecialchars((string) $nouveau['vlan']) ?></div>
        <div class="k">VRF</div><div><?= htmlspecialchars($nouveau['nom_vrf']) ?></div>
        <div class="k">Route Distinguisher</div><div><code><?= htmlspecialchars(AS_SOCIETE . ':' . $nouveau['id_client']) ?></code></div>
        <div class="k">Sous-reseau attribue</div><div><code><?= htmlspecialchars($nouveau['adresse_reseau']) ?>/<?= htmlspecialchars((string) $nouveau['prefixe']) ?></code></div>
        <div class="k">Adresse sous-interface</div><div><code><?= htmlspecialchars($nouveau['premiere_ip']) ?></code></div>
        <div class="k">Routeur PE</div><div><?= htmlspecialchars($nouveau['nom_pe']) ?></div>
    </div>

    <h2 style="margin-top:20px;">Configuration a appliquer sur le PE</h2>
    <pre class="config"><?= htmlspecialchars(genererConfig($nouveau)) ?></pre>

    <p style="margin-top:16px;">
        <a class="btn ghost" href="index.php">Voir la liste des clients</a>
    </p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/pied.php'; ?>
