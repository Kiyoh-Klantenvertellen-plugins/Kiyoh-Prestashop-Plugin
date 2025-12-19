{*
* Kiyoh Admin Configuration Template
*
* @author    Kiyoh
* @copyright 2024 Kiyoh
* @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
*}

<div class="kiyoh-config-panel">
    <div class="panel-heading">
        <h3>
            <i class="icon-cogs"></i>
            {l s='Kiyoh Reviews Configuration' mod='kiyoh'}
        </h3>
    </div>
    
    <div class="panel-body">
        {if isset($confirmation)}
            <div class="alert alert-success">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="icon-check"></i> {$confirmation}
            </div>
        {/if}
        
        {if isset($errors) && count($errors)}
            <div class="alert alert-danger">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="icon-warning-sign"></i>
                <strong>{l s='Error:' mod='kiyoh'}</strong>
                <ul>
                    {foreach from=$errors item=error}
                        <li>{$error}</li>
                    {/foreach}
                </ul>
            </div>
        {/if}
        
        <form id="kiyoh-config-form" method="post" action="{$current_index}&token={$token}&configure=kiyoh">
            
            <!-- General Settings Section -->
            <div class="kiyoh-form-section">
                <div class="legend">
                    <i class="icon-cogs"></i>
                    {l s='General Settings' mod='kiyoh'}
                </div>
                <div class="form-content">
                    <div class="form-group">
                        <label for="KIYOH_ENABLED">{l s='Enable Module' mod='kiyoh'}</label>
                        <div class="switch">
                            <input type="checkbox" id="KIYOH_ENABLED" name="KIYOH_ENABLED" value="1" {if $KIYOH_ENABLED}checked{/if}>
                            <span class="slider"></span>
                        </div>
                        <div class="help-block">{l s='Enable or disable the Kiyoh module' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_PLATFORM">{l s='Platform' mod='kiyoh'}</label>
                        <select id="KIYOH_PLATFORM" name="KIYOH_PLATFORM" class="form-control">
                            <option value="kiyoh" {if $KIYOH_PLATFORM == 'kiyoh'}selected{/if}>Kiyoh.com</option>
                            <option value="klantenvertellen" {if $KIYOH_PLATFORM == 'klantenvertellen'}selected{/if}>Klantenvertellen.nl</option>
                        </select>
                        <div class="help-block">{l s='Select the review platform to use' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_LOCATION_ID">{l s='Location ID' mod='kiyoh'} <span class="required">*</span></label>
                        <input type="text" id="KIYOH_LOCATION_ID" name="KIYOH_LOCATION_ID" value="{$KIYOH_LOCATION_ID|escape:'html':'UTF-8'}" class="form-control" required>
                        <div class="help-block">{l s='Your Kiyoh/Klantenvertellen Location ID' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_API_KEY">{l s='API Key' mod='kiyoh'} <span class="required">*</span></label>
                        <input type="text" id="KIYOH_API_KEY" name="KIYOH_API_KEY" value="{$KIYOH_API_KEY|escape:'html':'UTF-8'}" class="form-control" required>
                        <div class="help-block">{l s='Your Kiyoh/Klantenvertellen API Key' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="connection-test-section">
                        <button type="button" class="btn btn-info" onclick="testApiConnection()">
                            <i class="icon-cogs"></i>
                            {l s='Test Connection' mod='kiyoh'}
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Product Sync Settings Section -->
            <div class="kiyoh-form-section">
                <div class="legend">
                    <i class="icon-refresh"></i>
                    {l s='Product Sync Settings' mod='kiyoh'}
                </div>
                <div class="form-content">
                    <div class="form-group">
                        <label for="KIYOH_AUTO_SYNC">{l s='Auto Sync Products' mod='kiyoh'}</label>
                        <div class="switch">
                            <input type="checkbox" id="KIYOH_AUTO_SYNC" name="KIYOH_AUTO_SYNC" value="1" {if $KIYOH_AUTO_SYNC}checked{/if}>
                            <span class="slider"></span>
                        </div>
                        <div class="help-block">{l s='Automatically sync products when they are created or updated' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_EXCLUDED_TYPES">{l s='Excluded Product Types' mod='kiyoh'}</label>
                        <textarea id="KIYOH_EXCLUDED_TYPES" name="KIYOH_EXCLUDED_TYPES" class="form-control" rows="3">{$KIYOH_EXCLUDED_TYPES|escape:'html':'UTF-8'}</textarea>
                        <div class="help-block">{l s='Comma-separated list of product types to exclude from sync (e.g., virtual, downloadable)' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_EXCLUDED_CODES">{l s='Excluded Product Codes' mod='kiyoh'}</label>
                        <textarea id="KIYOH_EXCLUDED_CODES" name="KIYOH_EXCLUDED_CODES" class="form-control" rows="3">{$KIYOH_EXCLUDED_CODES|escape:'html':'UTF-8'}</textarea>
                        <div class="help-block">{l s='Comma-separated list of specific product codes/SKUs to exclude from sync' mod='kiyoh'}</div>
                    </div>
                </div>
            </div>
            
            <!-- Invitation Settings Section -->
            <div class="kiyoh-form-section">
                <div class="legend">
                    <i class="icon-envelope"></i>
                    {l s='Review Invitation Settings' mod='kiyoh'}
                </div>
                <div class="form-content">
                    <div class="form-group">
                        <label for="KIYOH_TRIGGER_STATUS">{l s='Trigger Order Status' mod='kiyoh'}</label>
                        <select id="KIYOH_TRIGGER_STATUS" name="KIYOH_TRIGGER_STATUS[]" class="form-control" multiple>
                            {foreach from=$order_statuses item=status}
                                <option value="{$status.id_order_state}" {if in_array($status.id_order_state, $KIYOH_TRIGGER_STATUS)}selected{/if}>
                                    {$status.name|escape:'html':'UTF-8'}
                                </option>
                            {/foreach}
                        </select>
                        <div class="help-block">{l s='Select which order status changes should trigger review invitations' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_DELAY_DAYS">{l s='Invitation Delay (days)' mod='kiyoh'}</label>
                        <input type="number" id="KIYOH_DELAY_DAYS" name="KIYOH_DELAY_DAYS" value="{$KIYOH_DELAY_DAYS|escape:'html':'UTF-8'}" class="form-control" min="0">
                        <div class="help-block">{l s='Number of days to wait before sending invitation (0 = immediate)' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_INVITATION_TYPE">{l s='Invitation Type' mod='kiyoh'}</label>
                        <select id="KIYOH_INVITATION_TYPE" name="KIYOH_INVITATION_TYPE" class="form-control">
                            <option value="both" {if $KIYOH_INVITATION_TYPE == 'both'}selected{/if}>{l s='Shop + Product Reviews' mod='kiyoh'}</option>
                            <option value="product" {if $KIYOH_INVITATION_TYPE == 'product'}selected{/if}>{l s='Product Reviews Only' mod='kiyoh'}</option>
                        </select>
                        <div class="help-block">{l s='Type of review invitation to send' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_MAX_PRODUCTS">{l s='Max Products per Invitation' mod='kiyoh'}</label>
                        <input type="number" id="KIYOH_MAX_PRODUCTS" name="KIYOH_MAX_PRODUCTS" value="{$KIYOH_MAX_PRODUCTS|escape:'html':'UTF-8'}" class="form-control" min="1" max="10">
                        <div class="help-block">{l s='Maximum number of products to include in each invitation (1-10, recommended: 3-4)' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_PRODUCT_SORT_ORDER">{l s='Product Sort Order' mod='kiyoh'}</label>
                        <select id="KIYOH_PRODUCT_SORT_ORDER" name="KIYOH_PRODUCT_SORT_ORDER" class="form-control">
                            <option value="price_desc" {if $KIYOH_PRODUCT_SORT_ORDER == 'price_desc'}selected{/if}>{l s='Price (High to Low)' mod='kiyoh'}</option>
                            <option value="price_asc" {if $KIYOH_PRODUCT_SORT_ORDER == 'price_asc'}selected{/if}>{l s='Price (Low to High)' mod='kiyoh'}</option>
                            <option value="name_asc" {if $KIYOH_PRODUCT_SORT_ORDER == 'name_asc'}selected{/if}>{l s='Name (A-Z)' mod='kiyoh'}</option>
                            <option value="name_desc" {if $KIYOH_PRODUCT_SORT_ORDER == 'name_desc'}selected{/if}>{l s='Name (Z-A)' mod='kiyoh'}</option>
                            <option value="random" {if $KIYOH_PRODUCT_SORT_ORDER == 'random'}selected{/if}>{l s='Random' mod='kiyoh'}</option>
                        </select>
                        <div class="help-block">{l s='How to sort products when limiting the number included' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_EXCLUDED_GROUPS">{l s='Excluded Customer Groups' mod='kiyoh'}</label>
                        <select id="KIYOH_EXCLUDED_GROUPS" name="KIYOH_EXCLUDED_GROUPS[]" class="form-control" multiple>
                            {foreach from=$customer_groups item=group}
                                <option value="{$group.id_group}" {if in_array($group.id_group, $KIYOH_EXCLUDED_GROUPS)}selected{/if}>
                                    {$group.name|escape:'html':'UTF-8'}
                                </option>
                            {/foreach}
                        </select>
                        <div class="help-block">{l s='Customer groups that should not receive review invitations' mod='kiyoh'}</div>
                    </div>
                </div>
            </div>
            
            <!-- Language Settings Section -->
            <div class="kiyoh-form-section">
                <div class="legend">
                    <i class="icon-language"></i>
                    {l s='Language Settings' mod='kiyoh'}
                </div>
                <div class="form-content">
                    <div class="form-group">
                        <label for="KIYOH_LANGUAGE_AUTO">{l s='Auto-detect Language' mod='kiyoh'}</label>
                        <div class="switch">
                            <input type="checkbox" id="KIYOH_LANGUAGE_AUTO" name="KIYOH_LANGUAGE_AUTO" value="1" {if $KIYOH_LANGUAGE_AUTO}checked{/if}>
                            <span class="slider"></span>
                        </div>
                        <div class="help-block">{l s='Automatically detect customer language from their profile or store settings' mod='kiyoh'}</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="KIYOH_FALLBACK_LANGUAGE">{l s='Fallback Language' mod='kiyoh'}</label>
                        <select id="KIYOH_FALLBACK_LANGUAGE" name="KIYOH_FALLBACK_LANGUAGE" class="form-control">
                            {foreach from=$languages item=language}
                                <option value="{$language.iso_code}" {if $KIYOH_FALLBACK_LANGUAGE == $language.iso_code}selected{/if}>
                                    {$language.name|escape:'html':'UTF-8'}
                                </option>
                            {/foreach}
                        </select>
                        <div class="help-block">{l s='Default language to use when auto-detection fails' mod='kiyoh'}</div>
                    </div>
                </div>
            </div>
            
            <!-- Submit Section -->
            <div class="form-actions">
                <button type="submit" name="submitKiyohConfig" class="btn btn-primary">
                    <i class="icon-save"></i>
                    {l s='Save Configuration' mod='kiyoh'}
                </button>
            </div>
        </form>
    </div>
</div>

<script type="text/javascript">
    // Initialize form when document is ready
    $(document).ready(function() {
        // Load the admin configuration JavaScript
        if (typeof initializeFormBehavior === 'function') {
            initializeFormBehavior();
        }
    });
</script>