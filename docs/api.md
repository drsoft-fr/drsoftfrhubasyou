# API de suivi des colis — HubAsYou → boutique PrestaShop

Contrat de l'API exposée par le module PrestaShop `drsoftfrhubasyou` pour recevoir, **colis par colis**, le suivi des commandes expédiées par HubAsYou.

Version du contrat : 1.0 (2026-11-03), module 1.0.0, PrestaShop 9.1.

## 1. Authentification

L'API repose sur l'**API d'administration de PrestaShop 9** (OAuth2, flux « client credentials »), en HTTPS uniquement.

Côté marchand (BO) :

1. API d'administration activée (Paramètres avancés › API d'administration, carte Paramètres en bas de page ; le module `ps_apiresources` doit être installé et actif, et la réécriture d'URL activée). En mode multiboutique seulement, activer aussi « Admin API - Multistore » (fonction en bêta de PrestaShop, dans Paramètres avancés › Fonctionnalités nouvelles et expérimentales) : sans elle, toute l'API répond `404`.
2. **Paramètres avancés › API d'administration › Ajouter un client API**, avec les droits `hubasyou_shipment_read` et `hubasyou_shipment_write` (module `drsoftfrhubasyou`).
3. Transmettre à HubAsYou l'identifiant et le secret du client.

Côté HubAsYou, demander un jeton :

```http
POST https://<boutique>/admin-api/access_token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id=<id>&client_secret=<secret>&scope=hubasyou_shipment_read hubasyou_shipment_write
```

Réponse : `{"token_type": "Bearer", "expires_in": 3600, "access_token": "…"}`. Le jeton s'envoie dans chaque appel (`Authorization: Bearer <access_token>`) ; à son expiration, en demander un nouveau.

**Identifiants des commandes et des lignes** : `orderId` et `orderDetailId` sont les identifiants PrestaShop. HubAsYou les obtient par le webservice de PrestaShop (`GET /api/orders/<id>`, ressource `associations.order_rows`, champ `id` de chaque ligne), avec une clé webservice créée par le marchand (Paramètres avancés › Webservice).

## 2. Points d'entrée

Base : `https://<boutique>/admin-api`. Corps et réponses en JSON (`Content-Type: application/json`).

**Si la boutique utilise le mode multiboutique** : chaque appel (sauf la demande de jeton) doit préciser une boutique, par exemple `POST /hubasyou/shipments?allShops=1` ou `?shopId=2`, sinon PrestaShop répond `400`. Le choix est sans effet sur le colis : les réglages appliqués sont toujours ceux de la boutique de la commande.

| Méthode | URL | Droit | Rôle |
| --- | --- | --- | --- |
| `POST` | `/hubasyou/shipments` | `hubasyou_shipment_write` | déclarer un colis |
| `PATCH` | `/hubasyou/shipments/{shipmentId}` | `hubasyou_shipment_write` | mettre à jour un colis (état, suivi, dates) |
| `DELETE` | `/hubasyou/shipments/{shipmentId}` | `hubasyou_shipment_write` | supprimer un colis (contenu erroné) ; réponse `204` sans corps |
| `GET` | `/hubasyou/shipments/{shipmentId}` | `hubasyou_shipment_read` | lire un colis |
| `GET` | `/hubasyou/shipments` | `hubasyou_shipment_read` | lister les colis, du plus récent au plus ancien |

Paramètres de la liste : `orderId`, `externalReference` (filtres), `limit` (10 par défaut, 100 au plus), `offset`. Une valeur invalide est refusée (`400`, `detail` nomme le paramètre), jamais ignorée : `?orderId=abc` ne renvoie pas les colis de toutes les commandes. Réponse : `{"totalItems": 2, "limit": 10, "items": [<colis>, …], …}`. **Pour parcourir la liste, avancer `offset` du nombre de colis reçus**, pas de la valeur de `limit` : le champ `limit` répète la valeur demandée (`?limit=500` renvoie 100 colis et annonce `"limit": 500`).

## 3. Le colis

```json
{
  "shipmentId": 12,
  "externalReference": "HAY-000981",
  "orderId": 45,
  "carrierName": "DPD",
  "trackingNumber": "250123456789",
  "trackingUrl": "https://tracking.dpd.fr/250123456789",
  "status": "shipped",
  "shippedAt": "2026-11-03 14:05:00",
  "lines": [
    { "orderDetailId": 101, "quantity": 1 },
    { "orderDetailId": 102, "quantity": 2 }
  ]
}
```

| Champ | Type | Création | Règles |
| --- | --- | --- | --- |
| `shipmentId` | entier | — (attribué) | identifiant du colis dans la boutique, à conserver pour les mises à jour |
| `externalReference` | texte, 64 max. | **obligatoire** | identifiant du colis chez HubAsYou ; **unique**, comparé à l'identique (`HAY-1` et `hay-1` sont deux colis, dans le filtre de la liste aussi) ; non modifiable |
| `orderId` | entier | **obligatoire** | commande existante ; non modifiable |
| `carrierName` | texte, 64 max. | **obligatoire** | nom du transporteur affiché au client |
| `trackingNumber` | texte, 64 max. | **obligatoire** | numéro de suivi |
| `trackingUrl` | URL http(s), 255 max. | facultatif | page de suivi du transporteur, lien affiché au client |
| `status` | texte | **obligatoire** | `shipped`, `in_transit`, `delivered`, `exception` |
| `shippedAt` | date | facultatif | date d'expédition ; par défaut, date de réception du premier état `shipped`, `in_transit` ou `delivered` (pour un colis déclaré livré à une date passée : sa date de livraison) |
| `deliveredAt` | date | facultatif | date de livraison, **seulement avec l'état `delivered`** ; par défaut, date de réception de cet état ; jamais avant `shippedAt` |
| `lines` | liste | **obligatoire**, au moins une ligne | contenu du colis ; non modifiable |
| `lines[].orderDetailId` | entier | obligatoire | ligne de **cette** commande ; jamais un produit virtuel |
| `lines[].quantity` | entier ≥ 1 | obligatoire | unités de la ligne dans ce colis |

Textes (`externalReference`, `carrierName`, `trackingNumber`) : une seule ligne de texte visible. Les espaces et retours à la ligne au début et à la fin sont retirés. Ailleurs dans le texte, sont refusés (`422`) : retour à la ligne, tabulation, caractère NUL et autres caractères de contrôle, caractères invisibles (espace sans largeur, inversion du sens d'écriture…). Par exemple, `"DPD\nExpress"` est refusé, `"DPD Express 🚚"` est accepté.

Dates : envoyées en ISO 8601, **décalage horaire obligatoire** (`2026-11-05T10:12:00+01:00` ou `2026-11-05T09:12:00Z`). Sont refusées (`422`) : une date sans décalage, une date qui n'existe pas (31 février), une date avant l'an 2000 ou à plus d'un jour dans le futur. Elles sont enregistrées et renvoyées au format `AAAA-MM-JJ HH:MM:SS`, converties à l'heure de la boutique (heure d'été comprise) : les deux exemples donnent `2026-11-05 10:12:00` pour une boutique à Paris. Un champ vide (`deliveredAt` d'un colis non livré, `trackingUrl` absente) n'apparaît pas dans la réponse.

Règles de cohérence :

- une ligne de commande peut être répartie sur plusieurs colis, mais **la somme des quantités mises en colis ne peut pas dépasser la quantité commandée** (une unité remboursée ou retournée reste acceptée dans un colis : le colis est bien parti) ;
- la même ligne envoyée deux fois dans un colis est additionnée ;
- le contenu d'un colis (`externalReference`, `orderId`, `lines`) ne se modifie pas : un `PATCH` avec une autre valeur est refusé en entier (`422`, rien n'est appliqué) ; renvoyer les mêmes valeurs est accepté. En cas d'erreur, **supprimer le colis** (`DELETE`) puis le déclarer à nouveau ; sa `externalReference` redevient libre et ses unités peuvent être remises en colis. Le marchand peut faire de même depuis le BO (fiche de la commande, colis HubAsYou : « Supprimer »), et y corriger le suivi (« Modifier » : transporteur, numéro, URL, état) ;
- une suppression n'annule ni l'e-mail déjà envoyé au client ni l'état de la commande ;
- **colis perdu et colis de remplacement** : un colis perdu garde ses articles, quel que soit son état, `exception` compris. Le colis qui le remplace est donc refusé tant que le colis perdu existe (`422`, quantité dépassée). Il faut **supprimer le colis perdu, puis déclarer le nouveau**. La suppression est inscrite au journal de la boutique, avec la référence et le contenu du colis. Cette règle empêche une commande de compter plus d'articles que commandé si le colis perdu réapparaît : il ne peut plus s'ajouter. Exemple complet ci-dessous (§ 4) ;
- un `PATCH` ne change que les champs envoyés ; une date déjà enregistrée n'est pas écrasée par la date par défaut ;
- tant qu'un colis n'est pas livré, son état peut changer librement (`shipped`, `in_transit`, `exception`, `delivered`, dans n'importe quel ordre) ;
- **un colis livré ne change plus d'état** : un `PATCH` qui demande `shipped`, `in_transit` ou `exception` est refusé (`422`, rien n'est appliqué). À ce moment, la commande est déjà livrée et le client a reçu l'e-mail de livraison : un état plus ancien les contredirait. Exemple : `HAY-000981` est `delivered` le 5 novembre ; le 7, le transporteur signale un problème : le `PATCH` vers `exception` est refusé. Pour corriger un `delivered` envoyé par erreur, **supprimer le colis** puis le déclarer à nouveau. Restent permis sur un colis livré : renvoyer `delivered`, corriger le transporteur, le numéro, l'URL ou les dates. Le marchand, lui, peut toujours changer l'état depuis le BO ;
- les dates restent cohérentes : `deliveredAt` n'est accepté qu'avec l'état `delivered` (envoyé ou déjà enregistré), la livraison ne peut pas précéder l'expédition (`422` sinon), et un colis qui quitte l'état `delivered` (depuis le BO) perd sa date de livraison (une nouvelle est fixée s'il le redevient).

## 4. Exemples

Déclarer un colis :

```http
POST /admin-api/hubasyou/shipments
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "externalReference": "HAY-000981",
  "orderId": 45,
  "carrierName": "DPD",
  "trackingNumber": "250123456789",
  "trackingUrl": "https://tracking.dpd.fr/250123456789",
  "status": "shipped",
  "lines": [ { "orderDetailId": 101, "quantity": 1 } ]
}
```

Réponse `201 Created` : le colis complet, avec son `shipmentId`.

Signaler la livraison :

```http
PATCH /admin-api/hubasyou/shipments/12
Authorization: Bearer <access_token>
Content-Type: application/json

{ "status": "delivered", "deliveredAt": "2026-11-05T10:12:00+01:00" }
```

Réponse `200 OK` : le colis complet.

Remplacer un colis perdu. La commande 45 contient une affiche (ligne `101`, 1 unité) et une tasse (ligne `102`, 1 unité), parties dans deux colis : `HAY-000981` (`shipmentId` 12, l'affiche) et `HAY-000982` (`shipmentId` 13, la tasse).

1. L'affiche arrive : `PATCH /hubasyou/shipments/12` avec `{"status": "delivered"}`. La commande reste « Expédié » : la tasse est encore attendue.
2. Le transporteur perd la tasse : `PATCH /hubasyou/shipments/13` avec `{"status": "exception"}`. La commande reste « Expédié », et le client ne reçoit pas l'e-mail de livraison.
3. HubAsYou envoie une nouvelle tasse dans `HAY-000990`. Le déclarer tout de suite est refusé, car la tasse est encore dans le colis perdu :

   ```http
   POST /admin-api/hubasyou/shipments
   { "externalReference": "HAY-000990", "orderId": 45, "carrierName": "DPD", "trackingNumber": "250123456790", "status": "shipped", "lines": [ { "orderDetailId": 102, "quantity": 1 } ] }
   ```

   Réponse `422` : `{"detail": "Order line 102: 1 unit(s) ordered, 1 already in parcels, 1 sent.", …}`.
4. Supprimer le colis perdu : `DELETE /hubasyou/shipments/13`, réponse `204`. Le journal de la boutique garde sa trace : `parcel #13 (HAY-000982) of order #45 deleted by API client "hubasyou": status "exception", …`.
5. Renvoyer le `POST` de l'étape 3 : réponse `201`, avec le nouveau `shipmentId` (par exemple 14). Le client reçoit l'e-mail « votre colis est en route » avec le nouveau numéro de suivi.
6. La nouvelle tasse arrive : `PATCH /hubasyou/shipments/14` avec `{"status": "delivered"}`. La commande passe « Livré », et le client reçoit l'e-mail de livraison avec `HAY-000981` et `HAY-000990`, sans le colis perdu.

Si l'ancien colis réapparaît ensuite, un `POST` de `HAY-000982` est refusé (`422`, quantité dépassée) : la tasse est déjà dans `HAY-000990`.

## 5. Codes de réponse

**Se fier au code HTTP**. Pour les refus du module (`404`, `422` métier, `503`), `detail` donne la raison du refus et `status` répète le code. Ces textes sont fixes et ne reprennent jamais une valeur texte envoyée dans la requête ; seuls des identifiants, des quantités et des noms de champs peuvent y figurer. Pour les réponses de PrestaShop lui-même (`400`, `401`, `403`, `405`, `500`), seul le code est garanti : le texte et le champ `status` du corps dépendent de la configuration de la boutique. Par exemple, un JSON mal formé répond `400` avec `"status": 500` dans le corps.

| Code | Cas | Corps |
| --- | --- | --- |
| `200` | lecture ou mise à jour réussie | le colis |
| `201` | colis créé, ou le même colis renvoyé (voir « Renvoi sans risque ») | le colis |
| `204` | colis supprimé | — |
| `400` | JSON mal formé, ou champ d'un type incorrect | message |
| `400` | paramètre de la liste invalide (`orderId`, `externalReference`, `limit`, `offset`) | `detail` : par exemple `The value of orderId is not valid.` |
| `401` | jeton absent, invalide ou expiré | message |
| `403` | jeton sans le droit demandé | message |
| `404` | colis introuvable ; ou identifiant mal écrit dans l'URL (`1.5`, `01`, `abc`…), qui ne désigne aucun colis | `detail` : par exemple `Parcel 12 not found.` ; pour un identifiant mal écrit, seul le code est garanti |
| `422` | champ manquant ou invalide | liste `[{"propertyPath": "status", "message": "…"}, …]` |
| `422` | refus métier : retour à la ligne ou caractère invisible dans un texte, date mal écrite, inexistante ou hors limites, `externalReference` déjà utilisée par un colis au contenu différent, commande inconnue, ligne d'une autre commande, produit virtuel, quantité dépassée, `PATCH` qui change le contenu du colis, `PATCH` qui fait quitter l'état `delivered` | `detail` : message en anglais, par exemple `Order line 8: 2 unit(s) ordered, 1 already in parcels, 2 sent.` |
| `500` | **temporaire** : erreur technique de la boutique, base de données indisponible par exemple. La réponse ne dit rien de la demande | message générique ; renvoyer la même requête plus tard |
| `503` | **temporaire** : une autre requête met à jour les colis de la même commande depuis plus de 15 secondes | `detail` ; renvoyer la même requête un peu plus tard |

**Requêtes simultanées** : les requêtes qui touchent les colis d'une même commande sont traitées l'une après l'autre (une requête attend la précédente jusqu'à 15 secondes, puis répond `503`). HubAsYou peut donc envoyer en parallèle les colis d'une commande : les quantités, l'état de la commande et les e-mails restent justes.

**Renvoi sans risque** : en cas de doute (délai dépassé, coupure, `500`, `503`), HubAsYou peut renvoyer la même requête. Pour un `503`, attendre quelques secondes, en allongeant l'attente à chaque nouvel essai.

Un `POST` renvoyé pour un colis déjà enregistré répond `201` avec le colis existant. Il suffit que la commande, les lignes, le transporteur, le numéro et l'URL de suivi soient les mêmes. Exemple : la boutique s'arrête juste après avoir enregistré le colis, avant l'e-mail ; le renvoi fait partir l'e-mail et change l'état de la commande, sans doublon. Rien n'est écrasé : si le colis est déjà `delivered`, un `POST` en retard qui dit `shipped` ne le fait pas reculer, et la réponse montre l'état réel.

Si le contenu diffère, la réponse est un `422` sur `externalReference` : soit la référence sert déjà à un autre colis, soit le colis a été corrigé depuis par un `PATCH`. Retrouver alors le colis avec `GET /hubasyou/shipments?externalReference=HAY-000981`.

## 6. Ce qui se passe dans la boutique

- **Compte client** : le détail de la commande affiche chaque colis (produits, transporteur, numéro et lien de suivi, état, date).
- **E-mail par colis** : au premier état `shipped` ou `in_transit` d'un colis, le client reçoit un e-mail dans la langue de sa commande, avec les produits du colis et le lien de suivi. Une seule fois par colis. Rien pour l'état `exception`, ni pour un colis déclaré directement `delivered` : il est déjà arrivé (il figure dans le compte client et dans l'e-mail de livraison).
- **Commande entièrement livrée** : quand toutes les unités encore dues sont dans des colis livrés et qu'aucun colis n'est en route (`shipped`, `in_transit`). Les produits virtuels et les unités annulées, remboursées ou retournées ne sont pas attendus ; une commande dont rien n'a été livré n'est jamais considérée comme livrée. Un colis perdu doit donc passer en `exception` (avant d'être `delivered`) ou être supprimé. Le calcul est refait à chaque colis enregistré ou supprimé, et à chaque remboursement ou retour fait dans le BO. Exemple : un colis livré, l'autre perdu et remboursé ; dès que le colis perdu est supprimé, la commande est entièrement livrée.
- **E-mail de livraison** : quand la commande est entièrement livrée, le client reçoit un e-mail « Votre commande est livrée » avec le récapitulatif des colis livrés (un colis en `exception` n'y figure pas). Une seule fois par commande.
- Chacun de ces deux e-mails peut être désactivé par le marchand, ou renvoyé par lui depuis le BO.
- **État de la commande** (réglable par le marchand) : « Expédié » au premier colis parti, puis « Livré » quand la commande est entièrement livrée. Chaque état n'est appliqué qu'une fois ; la commande ne revient jamais en arrière. Le marchand choisit aussi les états **depuis lesquels** le module peut changer celui de la commande : une commande annulée ou en attente de paiement, par exemple, garde son état (le colis est tout de même enregistré, et le marchand est averti sur la fiche de la commande).
- **BO** : la fiche de la commande liste les colis, avec la date d'envoi de l'e-mail ; le marchand peut y modifier le suivi d'un colis ou le supprimer (mêmes règles et mêmes effets que l'API). Une mise à jour envoyée par HubAsYou n'est jamais écrasée par un formulaire du BO ouvert avant elle : l'enregistrement du marchand est alors refusé.
