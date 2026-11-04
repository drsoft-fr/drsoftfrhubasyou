{**
 * Back office › order page: parcels received from HubAsYou.
 *}
<div class="card mt-2" id="drsoftfrhubasyou-shipments">
  <div class="card-header">
    <h3 class="card-header-title">
      {l s='HubAsYou parcels' d='Modules.Drsoftfrhubasyou.Admin'} ({$hubasyou_shipments|count})
    </h3>
  </div>
  <div class="card-body">
    {if $hubasyou_state_warning}
      <div class="alert alert-warning" role="alert">
        <p class="alert-text">{$hubasyou_state_warning|escape:'html':'UTF-8'}</p>
      </div>
    {/if}
    {if $hubasyou_shipments}
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>{l s='Parcel' d='Modules.Drsoftfrhubasyou.Admin'}</th>
              <th>{l s='Products' d='Modules.Drsoftfrhubasyou.Admin'}</th>
              <th>{l s='Tracking' d='Modules.Drsoftfrhubasyou.Admin'}</th>
              <th>{l s='Dates' d='Modules.Drsoftfrhubasyou.Admin'}</th>
            </tr>
          </thead>
          <tbody>
            {foreach $hubasyou_shipments as $shipment}
              <tr>
                <td class="text-nowrap">
                  <strong>{$shipment.external_reference|escape:'html':'UTF-8'}</strong><br>
                  <span class="badge {if $shipment.status == 'delivered'}badge-success{elseif $shipment.status == 'exception'}badge-danger{else}badge-info{/if}">
                    {$shipment.status_label|escape:'html':'UTF-8'}
                  </span>
                  {if !empty($shipment.edit_url) || !empty($shipment.delete_url)}
                    <div class="mt-2">
                      {if !empty($shipment.edit_url)}
                        <a href="{$shipment.edit_url|escape:'html':'UTF-8'}" class="btn btn-sm btn-outline-secondary">{l s='Edit' d='Admin.Actions'}</a>
                      {/if}
                      {if !empty($shipment.delete_url)}
                        <a href="{$shipment.delete_url|escape:'html':'UTF-8'}" class="btn btn-sm btn-outline-danger">{l s='Delete' d='Admin.Actions'}</a>
                      {/if}
                    </div>
                  {/if}
                </td>
                <td>
                  {foreach $shipment.lines as $line}
                    <div>{$line.quantity|intval} × {$line.product_name|escape:'html':'UTF-8'}{if $line.product_reference} <span class="text-muted">({$line.product_reference|escape:'html':'UTF-8'})</span>{/if}</div>
                  {/foreach}
                </td>
                <td class="text-nowrap">
                  {$shipment.carrier_name|escape:'html':'UTF-8'}<br>
                  {if $shipment.tracking_url}
                    <a href="{$shipment.tracking_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$shipment.tracking_number|escape:'html':'UTF-8'}</a>
                  {else}
                    {$shipment.tracking_number|escape:'html':'UTF-8'}
                  {/if}
                </td>
                <td class="text-nowrap small">
                  {if $shipment.shipped_at}{l s='Shipped:' d='Modules.Drsoftfrhubasyou.Admin'} {$shipment.shipped_at|escape:'html':'UTF-8'}<br>{/if}
                  {if $shipment.delivered_at}{l s='Delivered:' d='Modules.Drsoftfrhubasyou.Admin'} {$shipment.delivered_at|escape:'html':'UTF-8'}<br>{/if}
                  {if $shipment.mail_sent_at}{l s='E-mail:' d='Modules.Drsoftfrhubasyou.Admin'} {$shipment.mail_sent_at|escape:'html':'UTF-8'}{else}{l s='No e-mail sent' d='Modules.Drsoftfrhubasyou.Admin'}{/if}
                </td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      </div>
    {else}
      <p class="mb-0">{l s='No parcel received from HubAsYou yet.' d='Modules.Drsoftfrhubasyou.Admin'}</p>
    {/if}
  </div>
</div>
