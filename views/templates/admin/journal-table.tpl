{**
 * Back office: lines of the parcel history (order page and history page).
 * $hubasyou_journal: drsoftfrhubasyou::presentJournal()
 *}
<div class="table-responsive" id="drsoftfrhubasyou-journal">
  <table class="table table-sm small mb-0">
    <thead>
      <tr>
        <th>{l s='Date' d='Modules.Drsoftfrhubasyou.Admin'}</th>
        <th>{l s='Parcel' d='Modules.Drsoftfrhubasyou.Admin'}</th>
        <th>{l s='Action' d='Modules.Drsoftfrhubasyou.Admin'}</th>
        <th>{l s='By' d='Modules.Drsoftfrhubasyou.Admin'}</th>
        <th>{l s='Details' d='Modules.Drsoftfrhubasyou.Admin'}</th>
      </tr>
    </thead>
    <tbody>
      {foreach $hubasyou_journal as $entry}
        <tr>
          <td class="text-nowrap">{$entry.date|escape:'html':'UTF-8'}</td>
          <td class="text-nowrap">{$entry.reference|escape:'html':'UTF-8'}</td>
          <td>{$entry.action|escape:'html':'UTF-8'}</td>
          <td>{$entry.actor|escape:'html':'UTF-8'}</td>
          <td>
            {foreach $entry.details as $detail}
              <div>{$detail|escape:'html':'UTF-8'}</div>
            {/foreach}
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>
</div>
