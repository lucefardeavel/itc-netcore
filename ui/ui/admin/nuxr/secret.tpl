{* WP6C (owner amendment G): a masked value with an explicit, accessible reveal (touch + keyboard; hover remains a
   convenience for fine pointers). Presentation only: the value is exactly what this page already delivered to this
   role - nothing new is exposed. Params: nuxr_secret (value), nuxr_secret_label (what it is). *}
<span class="nuxr-secret"><span class="nuxr-secret-value nuxr-mono" dir="ltr">{$nuxr_secret|escape}</span><button type="button" class="nuxr-reveal" data-nuxr-reveal aria-pressed="false" aria-label="{Lang::T('Show')} {$nuxr_secret_label|escape}"><i class="fa fa-eye" aria-hidden="true"></i></button></span>
