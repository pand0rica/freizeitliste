<?php
define('IN_MYBB', 1);
define('THIS_SCRIPT', 'freizeit.php');

require_once './global.php';
require_once MYBB_ROOT.'inc/plugins/freizeitliste/core.php';

FreizeitlisteCore::load_language();
add_breadcrumb($lang->freizeitliste_page_title, 'freizeit.php');

$action = $mybb->get_input('action');
$freizeit_feedback_html = '';

if($mybb->request_method === 'post') {
    if($action === 'submit') {
        FreizeitlisteCore::handle_submit_post();
    }

    if(in_array($mybb->get_input('participation_action'), array('join', 'leave', 'role'))) {
        FreizeitlisteCore::handle_participation_post();
    }
}

if((int)$mybb->get_input('submitted') === 1) {
    $freizeit_feedback_html = '<div class="pm_alert" style="margin-bottom:10px;">'.htmlspecialchars_uni($lang->freizeit_success_submission_sent).'</div>';
}

$freizeit_submit_form = FreizeitlisteCore::build_submit_form();
$freizeit_categories_html = FreizeitlisteCore::build_overview_html();

eval('$page = "'.$templates->get('freizeit_overview').'";');
output_page($page);
