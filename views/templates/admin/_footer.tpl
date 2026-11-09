{**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Back office: signature at the bottom of the screens of the module (configuration, parcel
 * forms, history). $hubasyou_footer: drsoftfrhubasyou::renderFooter()
 *}
<div class="panel mt-3" id="drsoftfrhubasyou-footer">
  <p class="text-center mb-0">
    {l s='Module:' d='Modules.Drsoftfrhubasyou.Admin'} {$hubasyou_footer.name|escape:'html':'UTF-8'}
    - v{$hubasyou_footer.version|escape:'html':'UTF-8'}
    - {l s='Author:' d='Modules.Drsoftfrhubasyou.Admin'} {$hubasyou_footer.author|escape:'html':'UTF-8'}
    - <a href="mailto:{$hubasyou_footer.email|escape:'html':'UTF-8'}">{$hubasyou_footer.email|escape:'html':'UTF-8'}</a>
    - <a href="{$hubasyou_footer.repository|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='GitHub repository' d='Modules.Drsoftfrhubasyou.Admin'}</a>
    - <a href="{$hubasyou_footer.issues|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{l s='Report an issue' d='Modules.Drsoftfrhubasyou.Admin'}</a>
  </p>
  <p class="text-center mt-3 mb-0">
    <a href="https://www.drsoft.fr" target="_blank" rel="noopener noreferrer">
      <img src="{$hubasyou_footer.logo|escape:'html':'UTF-8'}" alt="drSoft.fr" width="128" height="44" loading="lazy">
    </a>
  </p>
</div>
