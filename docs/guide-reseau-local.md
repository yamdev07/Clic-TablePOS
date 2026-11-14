# Clic&Table — Installation et lancement sur le réseau local

L'application tourne sur **un seul PC** (le « PC serveur »). Tous les autres appareils
(tablette cuisine, téléphones des serveurs, poste caisse) s'y connectent par le **Wi-Fi**
avec un simple navigateur : rien à installer sur eux.

```
 Tablette cuisine ─┐
 Téléphone serveur ─┼── Wi-Fi ──► PC serveur (API :8000 · temps réel :6001 · application :5173)
 Poste caisse ─────┘
```

---

## 1. Mise à jour du PC serveur (une seule fois)

Dans `clicettable-backend` :

```powershell
git pull
composer install
php artisan migrate
```

Dans le fichier `.env`, vérifier ces deux lignes (le port 8080 est souvent pris par Apache/XAMPP) :

```
REVERB_PORT=6001
REVERB_SERVER_PORT=6001
```

puis :

```powershell
php artisan config:clear
```

Dans `clicettable-frontend` :

```powershell
git pull
npm install
npm run build
```

## 2. Ouvrir le pare-feu Windows (une seule fois)

PowerShell **en administrateur** :

```powershell
New-NetFirewallRule -DisplayName "ClicTable" -Direction Inbound -Protocol TCP -LocalPort 8000,6001,5173 -Action Allow -Profile Private,Domain
```

Le Wi-Fi de l'hôtel doit être déclaré comme réseau **Privé** dans Windows
(Paramètres → Réseau et Internet → Wi-Fi → propriétés du réseau → Profil réseau : Privé).

## 3. Lancer l'application (à chaque démarrage)

Deux fenêtres PowerShell, laissées ouvertes pendant tout le service :

```powershell
# Fenêtre 1 — dans clicettable-backend : API + temps réel
composer lan
```

```powershell
# Fenêtre 2 — dans clicettable-frontend : l'application
npm run preview
```

## 4. Trouver l'adresse à donner aux employés

```powershell
ipconfig
```

Relever l'**Adresse IPv4** de la carte Wi-Fi, par exemple `192.168.1.20`.
Sur chaque appareil, ouvrir dans le navigateur :

```
http://192.168.1.20:5173
```

> Conseil : demander à l'informaticien de l'hôtel de **réserver cette adresse IP** pour le PC
> serveur dans la box/routeur. Sinon elle peut changer et il faudra redonner la nouvelle adresse.
> Ajouter la page à l'écran d'accueil des tablettes/téléphones pour l'ouvrir comme une appli.

---

## 5. Déroulement d'un service (formation)

Avant le premier service, l'administrateur :

1. renseigne **Mon restaurant** (nom, adresse, téléphone) : ce sont les informations imprimées sur les factures ;
2. crée **un compte par employé** dans *Utilisateurs* (Serveur, Cuisinier, Caissier, Gérant).

| Étape | Qui | Écran | Ce qui se passe |
|---|---|---|---|
| 1 | Serveur | *Tableau de bord* → table libre → **+ Nouvelle commande** → choisir les plats → **Valider** | La commande part en cuisine, statut **Ouverte** |
| 2 | Cuisinier | *Cuisine* — colonne **En attente** → **Commencer** | La commande passe **En préparation** |
| 3 | Cuisinier | colonne **En cuisson** → **Prêt** | Quand tous les plats sont prêts, la commande passe **Prête** et le serveur est alerté 🔔 |
| 4 | Serveur | *Tableau de bord* → table en rouge → **✓ Servir** | La commande passe **Servie** |
| 5 | Caissier | *Paiements* → **💰 Encaisser** | La commande est **Payée**, la table redevient libre |

- **Rupture de stock** : le cuisinier clique 🚫 sur le plat. Le plat est retiré du menu
  et de la commande (il n'est pas facturé).
- **Ajout d'un plat** sur une commande déjà prête : elle repasse automatiquement en préparation.
- Les écrans se mettent à jour **instantanément**. Si le temps réel est coupé, ils se
  rafraîchissent quand même toutes les 15 secondes.

## 6. En cas de problème

| Symptôme | Cause probable | Solution |
|---|---|---|
| La page ne s'ouvre pas sur la tablette | Pare-feu ou mauvaise adresse | Refaire l'étape 2, vérifier l'IP (étape 4), tablette sur le **même Wi-Fi** |
| « Connexion impossible » après connexion | La fenêtre 1 (`composer lan`) est fermée | La relancer |
| Les écrans ne bougent qu'après 15 s | Temps réel bloqué | Vérifier le port 6001 dans le pare-feu et le `.env` |
| `Failed to listen on ... 6001` au lancement | Port déjà utilisé | Fermer l'ancienne fenêtre `composer lan` restée ouverte |
