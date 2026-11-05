# drSoft.fr HubAsYou

Module PrestaShop 9 qui reçoit, colis par colis, le suivi des commandes expédiées par HubAsYou, par l'**API d'administration** de PrestaShop (OAuth2). Contrat de l'API : [`docs/api.md`](docs/api.md).

- **API** : `POST`, `PATCH`, `GET`, `DELETE /admin-api/hubasyou/shipments…`, droits `hubasyou_shipment_read` / `hubasyou_shipment_write`.
- **Compte client** : les colis dans le détail de la commande (crochet `displayOrderDetail`).
- **BO** : les colis dans la fiche de la commande (crochet `displayAdminOrderMain`), avec « Modifier » (transporteur, numéro et URL de suivi, état) et « Supprimer » (page de confirmation) : contrôleur `AdminDrSoftFrHubAsYouShipment`, onglet caché sous Commandes. Le contenu d'un colis ne se modifie pas : on le supprime et HubAsYou le renvoie. Si le colis change pendant qu'un employé le modifie (mise à jour de HubAsYou, autre employé), son enregistrement est refusé et le formulaire montre les valeurs actuelles (version du colis vérifiée à l'enregistrement).
- **E-mails** au client, chacun désactivable dans la configuration, 7 langues (fr, en, de, it, es, nl, pt) :
    - au départ de chaque colis, une fois par colis (modèle `drsoftfrhubasyou_shipment`) ; rien pour un colis reçu déjà livré ;
    - quand tout est livré, une fois par commande, avec le récapitulatif des colis (modèle `drsoftfrhubasyou_delivered`). L'état « Livré » de PrestaShop n'envoie pas d'e-mail par défaut : c'est cet e-mail qui prévient le client.
- **Quand une commande est-elle considérée comme livrée ?** Quand le client a reçu tout ce qu'il doit recevoir et qu'aucun colis n'est encore en route. Le module n'attend pas les produits virtuels (qu'on ne peut pas mettre dans un colis), ni les articles annulés, remboursés ou retournés.
    - Exemple : 3 articles commandés, 2 livrés, le 3e remboursé. La commande passe en « Livré », même si le remboursement est fait après la livraison du dernier colis.
    - Colis perdu : tant qu'il reste « Expédié » ou « En transit », la commande ne passe pas en « Livré », même si le client a été remboursé. Il faut passer ce colis en « Problème de livraison » ou le supprimer.
    - Geste commercial : dans un remboursement partiel, PrestaShop considère l'article comme remboursé dès qu'une quantité est saisie, et le module ne l'attend plus. S'il n'est pas encore parti, la commande peut passer en « Livré » trop tôt. Pour un simple geste commercial, utiliser plutôt un bon d'achat.
    - Retour partiel : 2 articles identiques commandés, un livré puis retourné, l'autre pas encore parti. Le module ne peut pas savoir lequel des deux a été retourné, et la commande peut passer en « Livré » trop tôt.
    - Droits : le recalcul après un remboursement ne se fait que si l'employé a le droit « Voir » sur le module (voir Installation). Sinon, il se fait à la réception du colis suivant.
- **État de la commande** : « Expédié » au premier colis parti, « Livré » quand la commande est entièrement livrée (états réglables), **seulement depuis les états cochés dans la configuration** (par défaut : paiement accepté, paiement à distance accepté, en attente de réapprovisionnement (payé), en cours de préparation, expédié). Une commande annulée ou en attente de paiement garde son état ; un avertissement s'affiche sur sa fiche et dans le journal.

- **Journal** : chaque colis ajouté, modifié ou supprimé est inscrit dans Paramètres avancés › Journaux, avec son auteur (client API ou employé), sa commande et ce qui a changé. Exemple : `parcel #12 (HAY-000981) of order #45 edited by API client "hubasyou": status: "shipped" -> "delivered"`. Ces lignes ont le niveau « Informatif » : elles ne s'enregistrent que si le niveau minimal du journal le permet (`PS_MIN_LOGGER_LEVEL_IN_DB`).

## Installation

1. `make package`, puis BO › Modules › Gestionnaire de modules › Envoyer un module : `dist/drsoftfrhubasyou-<version>.zip` (l'archive contient `vendor/`, l'autoloader composer).
2. BO › Modules › drSoft.fr HubAsYou › Configurer : états de commande appliqués, états que le module peut changer, e-mails ; la page rappelle les étapes de l'accès de HubAsYou.
3. Commandes › États : si l'état choisi pour le premier colis envoie déjà un e-mail (« Expédié » : modèle `shipped`), le décocher pour éviter un double e-mail au client.
4. Paramètres avancés › API d'administration : créer le client API de HubAsYou avec les deux droits du module.
5. Paramètres avancés › Équipe › Permissions, pour chaque profil autre que SuperAdmin (qui a tous les droits) :
    - onglet **Modules**, module drSoft.fr HubAsYou : « Voir », sans quoi la fiche de la commande n'affiche pas les colis (PrestaShop n'exécute les crochets du BO d'un module que pour les profils qui peuvent le voir) ;
    - onglet **Commandes › Colis HubAsYou** : « Voir » pour ouvrir un colis, « Modifier » pour le bouton « Modifier », « Supprimer » pour le bouton « Supprimer ». Les boutons ne s'affichent qu'avec le droit correspondant, et le contrôleur le vérifie de nouveau à l'enregistrement.

**Plusieurs boutiques** : les réglages s'enregistrent pour la boutique choisie dans le BO au moment de l'enregistrement (ou pour toutes). Une commande suit toujours les réglages de sa propre boutique. Exemple : si les e-mails sont coupés pour la boutique B, aucune commande de B n'en déclenche, quel que soit l'appel reçu. PrestaShop coupe l'API d'administration en multiboutique tant que « Admin API - Multistore » (fonction en bêta) n'est pas activée dans Paramètres avancés › Fonctionnalités nouvelles et expérimentales ; HubAsYou doit ensuite ajouter `?allShops=1` à ses appels (voir `docs/api.md`).

En développement, après un `git clone` dans `modules/` : `composer dump-autoload` (aucune dépendance), puis installer le module.

## Personnalisation

- Gabarit du compte client : `themes/<thème>/modules/drsoftfrhubasyou/views/templates/hook/order-detail.tpl` (classes `hubasyou-shipments`, `hubasyou-shipment__…`).
- E-mails : `themes/<thème>/modules/drsoftfrhubasyou/mails/<iso>/drsoftfrhubasyou_shipment.html|txt` et `drsoftfrhubasyou_delivered.html|txt` (variables : `{firstname}`, `{lastname}`, `{order_name}`, `{shipments_html}` / `{shipments_txt}` pour le second ; pour le premier, `{carrier_name_html}`, `{tracking_number_html}`, `{tracking_link_html}`, `{products_html}` dans le gabarit HTML et `{carrier_name}`, `{tracking_number}`, `{tracking_url}`, `{products_txt}` dans le gabarit texte). PrestaShop met les mêmes valeurs, telles quelles, dans les deux gabarits : dans le HTML, n'utiliser que les variables `…_html`, échappées ; les autres sont brutes, pour le texte.
- E-mails d'état de PrestaShop : réglés dans Commandes › États (« Envoyer un e-mail au client »), indépendamment du module. Pour ne pas les envoyer, décocher l'e-mail de l'état : le changement d'état automatique continue.
- Libellés : `translations/<locale>/ModulesDrsoftfrhubasyouShop.<locale>.xlf` (BO › International › Traductions, module drsoftfrhubasyou).

## Structure

- `src/ApiPlatform/Resources/` : ressources de l'API (lues par PrestaShop pour tout module actif). **Pas de `index.php` dans ce dossier** : API Platform exécute chaque fichier qu'il y trouve.
- `controllers/admin/` : page du BO (modifier, supprimer), qui envoie les mêmes commandes CQRS que l'API.
- `src/Application/` : commandes et requêtes CQRS (`AddShipmentCommand`, `EditShipmentCommand`, `DeleteShipmentCommand`, `GetShipmentForEditing`, `ListShipments`), leurs gestionnaires (`#[AsCommandHandler]`, `#[AsQueryHandler]`) et `ShipmentEffects`, qui enchaîne les effets d'un colis enregistré (e-mails, état de la commande), recalculés à chaque fois depuis les colis enregistrés.
- `src/Domain/` : règles métier sans accès à la base : identifiant et états du colis, avancement de la commande (`OrderShippingProgress`), règles des champs (`ShipmentRules`, vérifiées par les commandes : mêmes règles pour l'API et le BO), des quantités (`ShipmentQuantities`) et des dates (`ShipmentDates`), exceptions (422 / 404 ; codes pour les messages traduits du BO).
- `src/Infrastructure/` : tout ce qui touche PrestaShop : tables du module et du cœur (`Persistence/` : `drsoft_fr_hubasyou_shipment`, `…_shipment_line`, `…_order_mail` : envoi de l'e-mail de livraison ; lignes et historique des commandes), e-mails (`Mail/ShipmentMailer`), changement d'état (`Order/OrderStateWriter`), verrou par commande (`Lock/OrderLock`, `GET_LOCK` de la base : les colis d'une même commande sont traités une requête à la fois), journal (`Log/ShipmentJournal`), réglages, installation.
- `config/services.yml` : services, chargés par tous les noyaux de PrestaShop (BO, API).

La désinstallation supprime les colis reçus.

## Choix

Le cœur de PrestaShop 9.1 a ses propres colis (`shipment`, fonction « improved_shipment », en bêta et désactivée) : le module ne s'en sert pas, pour ne pas changer la création de toutes les commandes. Son format (colis, `orderDetailId`, `quantity`) est le même, pour pouvoir y passer plus tard.
