{**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Back office: confirmation before deleting a parcel.
 *}
<div class="panel">
  <h3><i class="icon-trash"></i> {l s='Delete parcel %reference%?' sprintf=['%reference%' => $hubasyou_shipment.external_reference] d='Modules.Drsoftfrhubasyou.Admin'}</h3>

  <p>
    <strong>{l s='Products' d='Modules.Drsoftfrhubasyou.Admin'}</strong> : {$hubasyou_products|escape:'html':'UTF-8'}<br>
    <strong>{l s='Tracking' d='Modules.Drsoftfrhubasyou.Admin'}</strong> : {$hubasyou_shipment.carrier_name|escape:'html':'UTF-8'} {$hubasyou_shipment.tracking_number|escape:'html':'UTF-8'}
  </p>

  <div class="alert alert-warning">
    <ul class="mb-0">
      <li>{l s='The parcel disappears from the customer account and its units can be put in another parcel.' d='Modules.Drsoftfrhubasyou.Admin'}</li>
      <li>{l s='HubAsYou can send it again with the same reference.' d='Modules.Drsoftfrhubasyou.Admin'}</li>
      <li>{l s='The e-mail already sent to the customer and the order state are not undone: change the state by hand if needed.' d='Modules.Drsoftfrhubasyou.Admin'}</li>
    </ul>
  </div>

  <form method="post" action="{$hubasyou_action_url|escape:'html':'UTF-8'}">
    <a href="{$hubasyou_order_url|escape:'html':'UTF-8'}" class="btn btn-default">
      <i class="process-icon-cancel"></i> {l s='Cancel' d='Admin.Actions'}
    </a>
    <button type="submit" name="submitHubAsYouShipmentDelete" value="1" class="btn btn-danger pull-right">
      <i class="process-icon-delete"></i> {l s='Delete the parcel' d='Modules.Drsoftfrhubasyou.Admin'}
    </button>
  </form>
</div>
