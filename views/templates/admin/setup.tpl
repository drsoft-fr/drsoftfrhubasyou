{**
 * Configuration page: how to give HubAsYou its access.
 *}
<div class="panel">
  <h3><i class="icon-key"></i> {l s='Access for HubAsYou' d='Modules.Drsoftfrhubasyou.Admin'}</h3>

  {if !$admin_api_enabled}
    <div class="alert alert-warning">
      {l s='The Admin API is disabled: enable it in Advanced Parameters › Admin API (Configuration).' d='Modules.Drsoftfrhubasyou.Admin'}
    </div>
  {/if}

  <ol>
    <li>{l s='Advanced Parameters › Admin API › Add new API client.' d='Modules.Drsoftfrhubasyou.Admin'}</li>
    <li>{l s='Tick the scopes hubasyou_shipment_read and hubasyou_shipment_write (module drsoftfrhubasyou).' d='Modules.Drsoftfrhubasyou.Admin'}</li>
    <li>{l s='Send the client ID and secret to HubAsYou, with the API address:' d='Modules.Drsoftfrhubasyou.Admin'} <code>{$api_base_url|escape:'html':'UTF-8'}</code></li>
    <li>{l s='To find the ids of the orders and order lines, HubAsYou can use a webservice key (Advanced Parameters › Webservice, resource "orders").' d='Modules.Drsoftfrhubasyou.Admin'}</li>
  </ol>
</div>
