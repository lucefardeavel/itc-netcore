{if $register}
    <div class="form-group">
        <label>{ucwords(str_replace('_',' ', $field['name']))|escape}</label>
        {if $field['type'] == 'option'}
            <select class="form-control" {if $field['required'] == 1} required{/if} name="{$field['name']|escape}" style="width: 100%">
                {assign var="opts" value=explode(',', $field['value'])}
                {foreach $opts as $opt}
                    <option value="{$opt|escape}">{$opt|escape}</option>
                {/foreach}
            </select>
        {elseif $field['image'] == 'image'}
            <input type="file" class="form-control" {if $field['required'] == 1} required{/if} name="{$field['name']|escape}"
                style="width: 100%" placeholder="{$field['placeholder']|escape}" accept="image/*">
        {else}
            <input type="{$field['type']|escape}" class="form-control" {if $field['required'] == 1} required{/if}
                name="{$field['name']|escape}" value="{$field['value']|escape}" style="width: 100%" placeholder="{$field['placeholder']|escape}">
        {/if}
    </div>
{else}
    <div class="form-group">
        <label class="col-md-3 control-label">{ucwords(str_replace('_',' ', $field['name']))|escape}</label>
        <div class="col-md-9">
            {if $field['type'] == 'option'}
                <select class="form-control" {if $field['required'] == 1} required{/if} name="{$field['name']|escape}"
                    style="width: 100%">
                    {assign var="opts" value=explode(',', $field['value'])}
                    {foreach $opts as $opt}
                        <option value="{$opt|escape}" {if $attrs[$field['name']] == $opt}selected{/if}>{$opt|escape}</option>
                    {/foreach}
                </select>
            {elseif $field['image'] == 'image'}
                <input type="file" class="form-control" {if $field['required'] == 1} required{/if} name="{$field['name']|escape}"
                    style="width: 100%" placeholder="{$field['placeholder']|escape}" accept="image/*">
            {else}
                <input type="{$field['type']|escape}" class="form-control" {if $field['required'] == 1} required{/if}
                    name="{$field['name']|escape}" value="{$attrs[$field['name']]|escape}" style="width: 100%" placeholder="{$field['placeholder']|escape}">
            {/if}
        </div>
    </div>
{/if}
