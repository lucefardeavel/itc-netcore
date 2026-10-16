<option value=''>{Lang::T('Select Pool')}</option>
{foreach $d as $ds}
<option value="{$ds['pool_name']|escape}">{$ds['pool_name']|escape}{if $routers==''} - {$ds['routers']|escape}{/if}</option>
{/foreach}