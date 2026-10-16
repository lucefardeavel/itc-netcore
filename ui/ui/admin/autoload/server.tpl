<option value=''>{Lang::T('Select Routers')}</option>
{if $_c['radius_enable']}
    <option value="radius">Radius</option>
{/if}
{foreach $d as $ds}
	<option value="{$ds['name']|escape}">{$ds['name']|escape}</option>
{/foreach}