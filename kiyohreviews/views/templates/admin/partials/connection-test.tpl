{*
* Connection Test Partial Template
*
* @author    Kiyoh
* @copyright 2024 Kiyoh
* @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
*}

<div class="connection-test-section">
    <h4>{l s='API Connection Test' mod='kiyoh'}</h4>
    <p class="help-block">
        {l s='Test your API connection to ensure the module can communicate with the selected platform.' mod='kiyoh'}
    </p>
    
    <div id="connection-status" class="connection-status" style="display: none;">
        <div class="status-indicator"></div>
        <span class="status-message"></span>
    </div>
    
    <div class="test-buttons">
        <button type="button" class="btn btn-info" onclick="testApiConnection()">
            <i class="icon-cogs"></i>
            {l s='Test Connection' mod='kiyoh'}
        </button>
        
        <button type="button" class="btn btn-secondary" onclick="clearConnectionStatus()">
            <i class="icon-refresh"></i>
            {l s='Clear Status' mod='kiyoh'}
        </button>
    </div>
    
    <div class="connection-help">
        <small class="text-muted">
            {l s='Make sure to save your settings before testing the connection.' mod='kiyoh'}
        </small>
    </div>
</div>

<script type="text/javascript">
function clearConnectionStatus() {
    $('#connection-status').hide();
    $('.status-indicator').removeClass('success error warning');
    $('.status-message').text('');
}
</script>