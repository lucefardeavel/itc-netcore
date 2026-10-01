<?php

class html_php
{

    public function getWidget($data = null)
    {
        global $ui;
        $ui->assign('card_header', $data['title']);
        ob_start();
        try{
        eval('?>'. $data['content']);
        }catch(Throwable $e){
            // WP2 / OD-14: no exception text or trace in the page; sanitized server log + reference
            echo htmlspecialchars(\Reseller\Security\ErrorReporter::publicMessage(\Reseller\Security\ErrorReporter::report($e, 'widget-html-php')));
        }
        $content = ob_get_clean();
        $ui->assign('card_body', $content);
        return $ui->fetch('widget/card_html.tpl');
    }
}