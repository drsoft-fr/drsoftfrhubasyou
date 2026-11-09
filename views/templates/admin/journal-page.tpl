{**
 * Back office: the whole history of an order's parcels, newest first, one page at a time.
 *}
<div class="panel">
  <h3><i class="icon-time"></i> {l s='History of the parcels of order #%id%' sprintf=['%id%' => $hubasyou_order_id|intval] d='Modules.Drsoftfrhubasyou.Admin'}</h3>

  {if $hubasyou_journal}
    <p class="text-muted">
      {if $hubasyou_journal_pages > 1}
        {l s='Lines %first% to %last% out of %total%, most recent first.' sprintf=['%first%' => $hubasyou_journal_first|intval, '%last%' => $hubasyou_journal_last|intval, '%total%' => $hubasyou_journal_total|intval] d='Modules.Drsoftfrhubasyou.Admin'}
      {else}
        {l s='%total% line(s), most recent first.' sprintf=['%total%' => $hubasyou_journal_total|intval] d='Modules.Drsoftfrhubasyou.Admin'}
      {/if}
    </p>
    {include file='./journal-table.tpl'}
  {else}
    <p>{l s='No line in the history of this order.' d='Modules.Drsoftfrhubasyou.Admin'}</p>
  {/if}

  {if $hubasyou_journal_pages > 1}
    <nav id="drsoftfrhubasyou-journal-pages" aria-label="{l s='Pages of the history' d='Modules.Drsoftfrhubasyou.Admin'}">
      <ul class="pagination">
        {foreach $hubasyou_journal_links as $link}
          <li class="page-item{if $link.current} active{elseif !$link.url} disabled{/if}">
            {if $link.url && !$link.current}
              <a class="page-link" href="{$link.url|escape:'html':'UTF-8'}">{$link.label|escape:'html':'UTF-8'}</a>
            {else}
              <span class="page-link"{if $link.current} aria-current="page"{/if}>{$link.label|escape:'html':'UTF-8'}</span>
            {/if}
          </li>
        {/foreach}
      </ul>
    </nav>
  {/if}

  <div class="panel-footer">
    <a href="{$hubasyou_order_url|escape:'html':'UTF-8'}" class="btn btn-default">
      <i class="process-icon-back"></i> {l s='Back to the order' d='Modules.Drsoftfrhubasyou.Admin'}
    </a>
  </div>
</div>
