{*
* Multilingual Configuration Partial Template
*
* @author    Kiyoh
* @copyright 2024 Kiyoh
* @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
*}

<div class="multilingual-config-section">
    <h4>{l s='Per-Language Settings' mod='kiyoh'}</h4>
    <p class="help-block">
        {l s='Configure language-specific settings for review invitations.' mod='kiyoh'}
    </p>
    
    <div class="language-tabs">
        <ul class="nav nav-tabs" role="tablist">
            {foreach from=$languages item=language}
                <li role="presentation" {if $language@first}class="active"{/if}>
                    <a href="#lang-{$language.iso_code}" aria-controls="lang-{$language.iso_code}" role="tab" data-toggle="tab">
                        <img src="../img/l/{$language.id_lang}.jpg" alt="{$language.name}" class="language-flag">
                        {$language.name}
                    </a>
                </li>
            {/foreach}
        </ul>
        
        <div class="tab-content">
            {foreach from=$languages item=language}
                <div role="tabpanel" class="tab-pane {if $language@first}active{/if}" id="lang-{$language.iso_code}">
                    <div class="form-group">
                        <label for="KIYOH_EMAIL_TEMPLATE_{$language.iso_code|upper}">
                            {l s='Email Template Override' mod='kiyoh'}
                        </label>
                        <textarea 
                            id="KIYOH_EMAIL_TEMPLATE_{$language.iso_code|upper}" 
                            name="KIYOH_EMAIL_TEMPLATE_{$language.iso_code|upper}" 
                            class="form-control" 
                            rows="4"
                            placeholder="{l s='Leave empty to use platform default template' mod='kiyoh'}"
                        >{if isset($email_templates[$language.iso_code])}{$email_templates[$language.iso_code]|escape:'html':'UTF-8'}{/if}</textarea>
                        <div class="help-block">
                            {l s='Custom email template for this language. Leave empty to use the platform default.' mod='kiyoh'}
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_DELAY_DAYS_{$language.iso_code|upper}">
                            {l s='Language-specific Delay' mod='kiyoh'}
                        </label>
                        <input 
                            type="number" 
                            id="KIYOH_DELAY_DAYS_{$language.iso_code|upper}" 
                            name="KIYOH_DELAY_DAYS_{$language.iso_code|upper}" 
                            class="form-control" 
                            min="0"
                            placeholder="{l s='Use global setting' mod='kiyoh'}"
                            value="{if isset($delay_days[$language.iso_code])}{$delay_days[$language.iso_code]|escape:'html':'UTF-8'}{/if}"
                        >
                        <div class="help-block">
                            {l s='Override the global delay setting for this language. Leave empty to use global setting.' mod='kiyoh'}
                        </div>
                    </div>
                </div>
            {/foreach}
        </div>
    </div>
</div>