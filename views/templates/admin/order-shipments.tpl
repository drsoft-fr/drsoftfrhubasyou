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
                  {if !empty($shipment.mail_url)}
                    <form method="post" action="{$shipment.mail_url|escape:'html':'UTF-8'}" class="mt-1 mb-0">
                      <button type="submit" name="submitHubAsYouShipmentMail" value="1" class="btn btn-sm btn-outline-secondary">
                        {if $shipment.mail_sent_at}
                          {l s='Send the e-mail again' d='Modules.Drsoftfrhubasyou.Admin'}
                        {else}
                          {l s='Send the e-mail' d='Modules.Drsoftfrhubasyou.Admin'}
                        {/if}
                      </button>
                    </form>
                  {/if}
                </td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      </div>
      {if $hubasyou_delivered_mail_at || $hubasyou_delivered_mail_allowed}
        <div class="d-flex flex-wrap align-items-center justify-content-between mt-2">
          <p class="small mb-0">
            {if $hubasyou_delivered_mail_at}
              {l s='Order delivered e-mail: %date%' sprintf=['%date%' => $hubasyou_delivered_mail_at] d='Modules.Drsoftfrhubasyou.Admin'}
            {else}
              {l s='Order delivered e-mail: not sent' d='Modules.Drsoftfrhubasyou.Admin'}
            {/if}
          </p>
          {if $hubasyou_delivered_mail_url}
            <form method="post" action="{$hubasyou_delivered_mail_url|escape:'html':'UTF-8'}" class="mb-0">
              <button type="submit" name="submitHubAsYouDeliveredMail" value="1" class="btn btn-sm btn-outline-secondary">
                {if $hubasyou_delivered_mail_at}
                  {l s='Send the e-mail again' d='Modules.Drsoftfrhubasyou.Admin'}
                {else}
                  {l s='Send the e-mail' d='Modules.Drsoftfrhubasyou.Admin'}
                {/if}
              </button>
            </form>
          {/if}
        </div>
      {/if}
    {else}
      <p class="mb-0">{l s='No parcel received from HubAsYou yet.' d='Modules.Drsoftfrhubasyou.Admin'}</p>
    {/if}
    {if $hubasyou_journal}
      <div class="mt-4">
        <h4>{l s='History of the parcels' d='Modules.Drsoftfrhubasyou.Admin'}</h4>
        {include file='./journal-table.tpl'}
        {if $hubasyou_journal_total > $hubasyou_journal|count}
          <div class="d-flex flex-wrap align-items-center justify-content-between mt-2">
            <p class="small text-muted mb-0" id="drsoftfrhubasyou-journal-count">
              {l s='The %shown% most recent lines out of %total%.' sprintf=['%shown%' => $hubasyou_journal|count, '%total%' => $hubasyou_journal_total|intval] d='Modules.Drsoftfrhubasyou.Admin'}
            </p>
            {if $hubasyou_journal_url}
              <a href="{$hubasyou_journal_url|escape:'html':'UTF-8'}" class="btn btn-sm btn-outline-secondary">{l s='See the whole history' d='Modules.Drsoftfrhubasyou.Admin'}</a>
            {/if}
          </div>
        {/if}
      </div>
    {/if}
  </div>
</div>
