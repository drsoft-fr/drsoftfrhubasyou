# API de suivi des colis — HubAsYou → boutique PrestaShop

Contrat de l'API exposée par le module PrestaShop `drsoftfrhubasyou` pour recevoir, **colis par colis**, le suivi des commandes expédiées par HubAsYou.

Version du contrat : 1.0 (2026-11-03), module 1.0.0, PrestaShop 9.1.

## 1. Authentification

L'API repose sur l'**API d'administration de PrestaShop 9** (OAuth2, flux « client credentials »), en HTTPS uniquement.

Côté marchand (BO) :

1. API d'administration activée (Paramètres avancés › Fonctionnalités nouvelles et expérimentales).
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

| Méthode | URL | Droit | Rôle |
| --- | --- | --- | --- |
| `POST` | `/hubasyou/shipments` | `hubasyou_shipment_write` | déclarer un colis |
| `PATCH` | `/hubasyou/shipments/{shipmentId}` | `hubasyou_shipment_write` | mettre à jour un colis (état, suivi, dates) |
| `DELETE` | `/hubasyou/shipments/{shipmentId}` | `hubasyou_shipment_write` | supprimer un colis (contenu erroné) ; réponse `204` sans corps |
| `GET` | `/hubasyou/shipments/{shipmentId}` | `hubasyou_shipment_read` | lire un colis |
| `GET` | `/hubasyou/shipments` | `hubasyou_shipment_read` | lister les colis, du plus récent au plus ancien |

Paramètres de la liste : `orderId`, `externalReference` (filtres), `limit` (10 par défaut, 100 au plus), `offset`. Réponse : `{"totalItems": 2, "limit": 10, "items": [<colis>, …], …}`.

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
| `externalReference` | texte, 64 max. | **obligatoire** | identifiant du colis chez HubAsYou ; **unique** ; non modifiable |
| `orderId` | entier | **obligatoire** | commande existante ; non modifiable |
| `carrierName` | texte, 64 max. | **obligatoire** | nom du transporteur affiché au client |
| `trackingNumber` | texte, 64 max. | **obligatoire** | numéro de suivi |
| `trackingUrl` | URL http(s), 255 max. | facultatif | page de suivi du transporteur, lien affiché au client |
| `status` | texte | **obligatoire** | `shipped`, `in_transit`, `delivered`, `exception` |
| `shippedAt` | date | facultatif | date d'expédition ; par défaut, date de réception du premier état `shipped`, `in_transit` ou `delivered` |
| `deliveredAt` | date | facultatif | date de livraison ; par défaut, date de réception de l'état `delivered` |
| `lines` | liste | **obligatoire**, au moins une ligne | contenu du colis ; non modifiable |
| `lines[].orderDetailId` | entier | obligatoire | ligne de **cette** commande |
| `lines[].quantity` | entier ≥ 1 | obligatoire | unités de la ligne dans ce colis |

Dates : envoyées en ISO 8601, **avec leur décalage horaire** (`2026-11-05T10:12:00+01:00` ou `2026-11-05T09:12:00Z`) ; enregistrées et renvoyées au format `AAAA-MM-JJ HH:MM:SS`, converties à l'heure de la boutique (heure d'été comprise) : les deux exemples donnent `2026-11-05 10:12:00` pour une boutique à Paris. Un champ vide (`deliveredAt` d'un colis non livré, `trackingUrl` absente) n'apparaît pas dans la réponse.

Règles de cohérence :

- une ligne de commande peut être répartie sur plusieurs colis, mais **la somme des quantités mises en colis ne peut pas dépasser la quantité commandée** ;
- la même ligne envoyée deux fois dans un colis est additionnée ;
- le contenu d'un colis (`externalReference`, `orderId`, `lines`) ne se modifie pas : en cas d'erreur, **supprimer le colis** (`DELETE`) puis le déclarer à nouveau ; sa `externalReference` redevient libre et ses unités peuvent être remises en colis. Le marchand peut faire de même depuis le BO (fiche de la commande, colis HubAsYou : « Supprimer »), et y corriger le suivi (« Modifier » : transporteur, numéro, URL, état) ;
- une suppression n'annule ni l'e-mail déjà envoyé au client ni l'état de la commande ;
- un `PATCH` ne change que les champs envoyés ; une date déjà enregistrée n'est pas écrasée par la date par défaut.

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

## 5. Codes de réponse

**Se fier au code HTTP** : dans le corps des erreurs, le champ `status` vaut toujours `500` (format d'erreur de PrestaShop), quel que soit le code réel.

| Code | Cas | Corps |
| --- | --- | --- |
| `200` | lecture ou mise à jour réussie | le colis |
| `201` | colis créé | le colis |
| `204` | colis supprimé | — |
| `401` | jeton absent, invalide ou expiré | message |
| `403` | jeton sans le droit demandé | `detail` : `Invalid scope.` |
| `404` | colis introuvable | `detail` |
| `422` | champ manquant ou invalide | liste `[{"propertyPath": "status", "message": "…"}, …]` |
| `422` | refus métier : `externalReference` déjà utilisée, commande inconnue, ligne d'une autre commande, quantité dépassée | `detail` : message en anglais, par exemple `Order line 8: 2 unit(s) ordered, 1 already in parcels, 2 sent.` |
| `503` | **temporaire** : une autre requête met à jour les colis de la même commande depuis plus de 15 secondes | `detail` ; renvoyer la même requête un peu plus tard |

**Requêtes simultanées** : les requêtes qui touchent les colis d'une même commande sont traitées l'une après l'autre (une requête attend la précédente jusqu'à 15 secondes, puis répond `503`). HubAsYou peut donc envoyer en parallèle les colis d'une commande : les quantités, l'état de la commande et les e-mails restent justes.

**Renvoi sans risque** : en cas de doute (délai dépassé, coupure, `503`), HubAsYou peut renvoyer la même requête. Pour un `503`, attendre quelques secondes, en allongeant l'attente à chaque nouvel essai. Si le colis avait déjà été enregistré, la réponse est un `422` sur `externalReference` : retrouver alors le colis avec `GET /hubasyou/shipments?externalReference=HAY-000981`.

## 6. Ce qui se passe dans la boutique

- **Compte client** : le détail de la commande affiche chaque colis (produits, transporteur, numéro et lien de suivi, état, date).
- **E-mail par colis** : au premier état `shipped`, `in_transit` ou `delivered` d'un colis, le client reçoit un e-mail dans la langue de sa commande, avec les produits du colis et le lien de suivi. Une seule fois par colis. Rien pour l'état `exception`.
- **E-mail de livraison** : quand toutes les unités de la commande sont dans des colis livrés, le client reçoit un e-mail « Votre commande est livrée » avec le récapitulatif des colis. Une seule fois par commande.
- Chacun de ces deux e-mails peut être désactivé par le marchand.
- **État de la commande** (réglable par le marchand) : « Expédié » au premier colis parti, puis « Livré » quand toutes les unités de la commande sont dans des colis livrés. Chaque état n'est appliqué qu'une fois ; la commande ne revient jamais en arrière. Le marchand choisit aussi les états **depuis lesquels** le module peut changer celui de la commande : une commande annulée ou en attente de paiement, par exemple, garde son état (le colis est tout de même enregistré, et le marchand est averti sur la fiche de la commande).
- **BO** : la fiche de la commande liste les colis, avec la date d'envoi de l'e-mail ; le marchand peut y modifier le suivi d'un colis ou le supprimer (mêmes règles et mêmes effets que l'API). Une mise à jour envoyée par HubAsYou n'est jamais écrasée par un formulaire du BO ouvert avant elle : l'enregistrement du marchand est alors refusé.
