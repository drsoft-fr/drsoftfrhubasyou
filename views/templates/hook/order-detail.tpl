{**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Parcels of the order (customer account › order detail).
 * Overridable in themes/<theme>/modules/drsoftfrhubasyou/views/templates/hook/order-detail.tpl
 *
 * Front office: Smarty escapes every printed variable (escape_html), and PrestaShop turns
 * the escape modifier into a no-op there: it is written anyway, to say what is expected.
 * The values given to sprintf in a translation are never escaped: give them values
 * escaped in PHP (tracking_number_html) or safe ones (dates, numbers).
 *}
<section class="box hubasyou-shipments" id="hubasyou-shipments">
  <h3 class="h4 hubasyou-shipments__title">
    {if $hubasyou_shipments|count > 1}
      {l s='Your parcels' d='Modules.Drsoftfrhubasyou.Shop'}
    {else}
      {l s='Your parcel' d='Modules.Drsoftfrhubasyou.Shop'}
    {/if}
  </h3>

  <ol class="hubasyou-shipments__list">
    {foreach $hubasyou_shipments as $shipment}
      <li class="hubasyou-shipment hubasyou-shipment--{$shipment.status|escape:'html':'UTF-8'}">
        <p class="hubasyou-shipment__head">
          {if $hubasyou_shipments|count > 1}
            <span class="hubasyou-shipment__index">{l s='Parcel %number%' sprintf=['%number%' => $shipment@iteration] d='Modules.Drsoftfrhubasyou.Shop'}</span>
          {/if}
          <span class="hubasyou-shipment__status">{$shipment.status_label|escape:'html':'UTF-8'}</span>
          {if $shipment.status == 'delivered' && $shipment.delivered_at}
            <span class="hubasyou-shipment__date">{l s='on %date%' sprintf=['%date%' => $shipment.delivered_at] d='Modules.Drsoftfrhubasyou.Shop'}</span>
          {elseif $shipment.shipped_at}
            <span class="hubasyou-shipment__date">{l s='Shipped on %date%' sprintf=['%date%' => $shipment.shipped_at] d='Modules.Drsoftfrhubasyou.Shop'}</span>
          {/if}
        </p>

        <ul class="hubasyou-shipment__products">
          {foreach $shipment.lines as $line}
            <li>{$line.quantity|intval} × {$line.product_name|escape:'html':'UTF-8'}</li>
          {/foreach}
        </ul>

        <p class="hubasyou-shipment__tracking">
          {$shipment.carrier_name|escape:'html':'UTF-8'} ·
          {if $shipment.tracking_url}
            <a href="{$shipment.tracking_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">
              {l s='Track parcel %number%' sprintf=['%number%' => $shipment.tracking_number_html] d='Modules.Drsoftfrhubasyou.Shop'}
            </a>
          {else}
            {l s='Tracking number: %number%' sprintf=['%number%' => $shipment.tracking_number_html] d='Modules.Drsoftfrhubasyou.Shop'}
          {/if}
        </p>
      </li>
    {/foreach}
  </ol>
</section>
