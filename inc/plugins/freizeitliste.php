<?php
if(!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

require_once MYBB_ROOT.'inc/plugins/freizeitliste/core.php';

$plugins->add_hook('global_start', 'freizeitliste_global_start');
$plugins->add_hook('index_start', 'freizeitliste_index_alert');
$plugins->add_hook('index_end', 'freizeitliste_index_alert_fallback');
$plugins->add_hook('modcp_nav', 'freizeitliste_modcp_nav');
$plugins->add_hook('modcp_start', 'freizeitliste_modcp_router');
$plugins->add_hook('admin_config_menu', 'freizeitliste_admin_config_menu');
$plugins->add_hook('admin_config_action_handler', 'freizeitliste_admin_action_handler');
$plugins->add_hook('admin_config_permissions', 'freizeitliste_admin_permissions');

function freizeitliste_info()
{
    return array(
        'name'          => 'Freizeitliste',
        'description'   => 'Freizeitliste für RPG-Foren mit ACP-, ModCP- und Frontend-Funktionen.',
        'website'       => 'https://github.com/pand0rica/freizeitliste',
        'author'        => 'pand0rica',
        'authorsite'    => 'https://ko-fi.com/pand0rica',
        'version'       => '1.0.0',
        'compatibility' => '18*'
    );
}

function freizeitliste_is_installed()
{
    global $db;
    return $db->table_exists('freizeit_categories');
}

function freizeitliste_install()
{
    global $db;

    $collation = $db->build_create_table_collation();

    if(!$db->table_exists('freizeit_categories')) {
        $db->write_query("CREATE TABLE `".TABLE_PREFIX."freizeit_categories` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `description` text NULL,
            `displayorder` int(10) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB {$collation}");
    }

    if(!$db->table_exists('freizeit_entries')) {
        $db->write_query("CREATE TABLE `".TABLE_PREFIX."freizeit_entries` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `category_id` int(10) unsigned NOT NULL,
            `title` varchar(255) NOT NULL,
            `ort` varchar(255) NOT NULL,
            `zeit` varchar(255) NOT NULL,
            `beschreibung` text NOT NULL,
            `created_by` int(10) unsigned NOT NULL,
            `status` enum('pending','approved') NOT NULL DEFAULT 'pending',
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `category_status` (`category_id`,`status`)
        ) ENGINE=InnoDB {$collation}");
    }

    if(!$db->table_exists('freizeit_contacts')) {
        $db->write_query("CREATE TABLE `".TABLE_PREFIX."freizeit_contacts` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `entry_id` int(10) unsigned NOT NULL,
            `user_id` int(10) unsigned NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `entry_user` (`entry_id`,`user_id`)
        ) ENGINE=InnoDB {$collation}");
    }

    if(!$db->table_exists('freizeit_participants')) {
        $db->write_query("CREATE TABLE `".TABLE_PREFIX."freizeit_participants` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `entry_id` int(10) unsigned NOT NULL,
            `user_id` int(10) unsigned NOT NULL,
            `rolle` varchar(255) NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `entry_user` (`entry_id`,`user_id`)
        ) ENGINE=InnoDB {$collation}");
    }

    if(!$db->table_exists('freizeit_pending_changes')) {
        $db->write_query("CREATE TABLE `".TABLE_PREFIX."freizeit_pending_changes` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `entry_id` int(10) unsigned NOT NULL,
            `type` enum('new_entry','contact_change') NOT NULL,
            `payload` text NOT NULL,
            `created_by` int(10) unsigned NOT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `entry_type` (`entry_id`,`type`)
        ) ENGINE=InnoDB {$collation}");
    }

    freizeitliste_create_settings();
    freizeitliste_insert_templates();
}

function freizeitliste_uninstall()
{
    global $db;

    freizeitliste_remove_settings();
    freizeitliste_remove_templates();

    if($db->table_exists('freizeit_pending_changes')) {
        $db->drop_table('freizeit_pending_changes');
    }
    if($db->table_exists('freizeit_participants')) {
        $db->drop_table('freizeit_participants');
    }
    if($db->table_exists('freizeit_contacts')) {
        $db->drop_table('freizeit_contacts');
    }
    if($db->table_exists('freizeit_entries')) {
        $db->drop_table('freizeit_entries');
    }
    if($db->table_exists('freizeit_categories')) {
        $db->drop_table('freizeit_categories');
    }
}

function freizeitliste_activate()
{
    require_once MYBB_ROOT.'inc/adminfunctions_templates.php';

    // Tables, settings and templates are owned by install()/uninstall() so that a
    // temporary deactivate/activate never loses the configured group permissions.
    // activate()/deactivate() only own the index template edit (exact mirror pair).
    find_replace_templatesets('index', '#'.preg_quote('{$forums}').'#', '{$freizeit_red_alert}{$forums}');
}

function freizeitliste_deactivate()
{
    require_once MYBB_ROOT.'inc/adminfunctions_templates.php';

    find_replace_templatesets('index', '#'.preg_quote('{$freizeit_red_alert}{$forums}').'#', '{$forums}');

}

function freizeitliste_create_settings()
{
    global $db;

    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='freizeitliste'"), 'gid');
    if($gid) {
        return;
    }

    $insert_group = array(
        'name' => 'freizeitliste',
        'title' => 'Freizeitliste',
        'description' => 'Einstellungen für das Freizeitliste-Plugin',
        'disporder' => 90,
        'isdefault' => 0
    );
    $gid = (int)$db->insert_query('settinggroups', $insert_group);

    $settings = array(
        array(
            'name' => 'freizeitliste_submit_groups',
            'title' => 'Gruppen mit Einreichungsrecht',
            'description' => 'Diese Benutzergruppen dürfen neue Freizeitmöglichkeiten einreichen.',
            'optionscode' => 'groupselect',
            'value' => '4',
            'disporder' => 1
        ),
        array(
            'name' => 'freizeitliste_modcp_groups',
            'title' => 'Gruppen mit ModCP-Freigaben',
            'description' => 'Diese Benutzergruppen dürfen Freigaben und Kontaktänderungen im ModCP durchführen.',
            'optionscode' => 'groupselect',
            'value' => '4',
            'disporder' => 2
        ),
        array(
            'name' => 'freizeitliste_view_participants_groups',
            'title' => 'Gruppen für Teilnehmerlisten',
            'description' => 'Diese Gruppen dürfen Teilnehmerlisten einsehen.',
            'optionscode' => 'groupselect',
            'value' => '2,3,4,6',
            'disporder' => 3
        )
    );

    foreach($settings as $setting) {
        $setting['gid'] = $gid;
        $db->insert_query('settings', $setting);
    }

    rebuild_settings();
}

function freizeitliste_remove_settings()
{
    global $db;

    $gid = (int)$db->fetch_field($db->simple_select('settinggroups', 'gid', "name='freizeitliste'"), 'gid');
    if(!$gid) {
        return;
    }

    $db->delete_query('settings', "gid='{$gid}'");
    $db->delete_query('settinggroups', "gid='{$gid}'");
    rebuild_settings();
}

function freizeitliste_insert_templates()
{
    global $db;

    $templates = array(
        'freizeit_overview' => '<html><head><title>{$lang->freizeitliste_page_title}</title>{$headerinclude}</head><body>{$header}{$freizeit_feedback_html}<div class="forum" style="margin-bottom: 12px;">{$freizeit_submit_form}</div>{$freizeit_categories_html}{$footer}</body></html>',
        'freizeit_category_block' => '<div class="thead"><strong>{$category_name}</strong></div><div class="tcat">{$category_description}</div><div class="forumlist">{$category_entries}</div><br />',
        'freizeit_entry_block' => '<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder" style="margin-bottom: 10px;"><tr><td class="thead" colspan="2"><strong>{$entry_title}</strong></td></tr><tr><td class="trow1" width="25%"><strong>{$lang->freizeit_ort}</strong></td><td class="trow1">{$entry_ort}</td></tr><tr><td class="trow2"><strong>{$lang->freizeit_zeit}</strong></td><td class="trow2">{$entry_zeit}</td></tr><tr><td class="trow1"><strong>{$lang->freizeit_beschreibung}</strong></td><td class="trow1">{$entry_beschreibung}</td></tr><tr><td class="trow2"><strong>{$lang->freizeit_contacts}</strong></td><td class="trow2">{$entry_contacts}</td></tr><tr><td class="trow1"><strong>{$lang->freizeit_participants}</strong></td><td class="trow1">{$entry_participants}</td></tr><tr><td class="trow2" colspan="2">{$entry_participation_form}</td></tr></table>',
        'freizeit_participants_list' => '<ul style="margin:0; padding-left: 18px;">{$participants_items}</ul>',
        'freizeit_submit_form' =>'<form action="freizeit.php" method="post"><input type="hidden" name="my_post_key" value="{$mybb->post_code}" /><input type="hidden" name="action" value="submit" /><table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder"><tr><td class="thead" colspan="2"><strong>{$lang->freizeit_submit_title}</strong></td></tr><tr><td class="trow1" width="25%">{$lang->freizeit_title}</td><td class="trow1"><input type="text" class="textbox" name="title" required="required" /></td></tr><tr><td class="trow2">{$lang->freizeit_category}</td><td class="trow2"><select name="category_id">{$category_options}</select></td></tr><tr><td class="trow1">{$lang->freizeit_ort}</td><td class="trow1"><input type="text" class="textbox" name="ort" required="required" /></td></tr><tr><td class="trow2">{$lang->freizeit_zeit}</td><td class="trow2"><input type="text" class="textbox" name="zeit" required="required" /></td></tr><tr><td class="trow1">{$lang->freizeit_beschreibung}</td><td class="trow1"><textarea name="beschreibung" rows="5" cols="60"></textarea></td></tr><tr><td class="trow2" colspan="2" align="center"><input type="submit" class="button" value="{$lang->freizeit_submit_button}" /></td></tr></table></form>',
        'modcp_freizeit_queue' => '<html>
	<head>
		<title>{$lang->freizeit_modcp_queue}</title>
		{$headerinclude}
	</head>
	<body>
		{$header}
		<table width="100%" border="0" align="center">
			<tr>
				{$modcp_nav}
				<td valign="top">
					<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder" width="100%">
						<tr>
							<td class="thead" colspan="6">
								<strong>{$lang->freizeit_modcp_queue}</strong>
							</td>
						</tr>
						<tr>
							<td class="tcat"><strong>ID</strong></td>
							<td class="tcat"><strong>{$lang->freizeit_type}</strong></td>
							<td class="tcat"><strong>{$lang->freizeit_title}</strong></td>
							<td class="tcat"><strong>{$lang->freizeit_created_by}</strong></td>
							<td class="tcat"><strong>{$lang->freizeit_created_at}</strong></td>
							<td class="tcat"><strong>{$lang->freizeit_action}</strong></td>
						</tr>
						{$queue_rows}
					</table>
				</td>
			</tr>
		</table>
		{$footer}
	</body>
</html>',
        'modcp_freizeit_edit_contacts' => '<html>
	<head>
		<title>{$lang->freizeit_modcp_contacts}</title>
		{$headerinclude}
	</head>
	<body>
		{$header}
		<table width="100%" border="0" align="center">
			<tr>
				{$modcp_nav}
				<td valign="top">{$contacts_content}</td>
			</tr>
		</table>
		{$footer}
	</body>
</html>'
    );

    foreach($templates as $title => $template) {
        $escaped_title = $db->escape_string($title);
        $escaped_template = $db->escape_string($template);
        $exists = (int)$db->fetch_field($db->simple_select('templates', 'tid', "title='".$escaped_title."'"), 'tid');
        if($exists > 0) {
            $db->update_query('templates', array(
                'template' => $escaped_template,
                'dateline' => TIME_NOW
            ), "tid='{$exists}'");
        } else {
            $insert = array(
                'title' => $escaped_title,
                'template' => $escaped_template,
                'sid' => -1,
                'version' => '',
                'dateline' => TIME_NOW
            );
            $db->insert_query('templates', $insert);
        }
    }
}

function freizeitliste_remove_templates()
{
    global $db;

    $titles = array(
        'freizeit_overview',
        'freizeit_category_block',
        'freizeit_entry_block',
        'freizeit_participants_list',
        'freizeit_submit_form',
        'modcp_freizeit_queue',
        'modcp_freizeit_edit_contacts'
    );

    foreach($titles as $title) {
        $db->delete_query('templates', "title='".$db->escape_string($title)."'");
    }
}

function freizeitliste_global_start()
{
    if(defined('THIS_SCRIPT') && THIS_SCRIPT === 'freizeit.php') {
        FreizeitlisteCore::load_language();
    }
}

function freizeitliste_index_alert()
{
    global $db, $mybb, $lang, $freizeit_red_alert;

    $freizeit_red_alert = '';
    if(!FreizeitlisteCore::is_team_member()) {
        return;
    }

    FreizeitlisteCore::load_language();
    FreizeitlisteCore::ensure_pending_queue_integrity();

    $pending_changes_count = (int)$db->fetch_field($db->simple_select('freizeit_pending_changes', 'COUNT(id) AS pending_count'), 'pending_count');
    $pending_entries_count = (int)$db->fetch_field($db->simple_select('freizeit_entries', 'COUNT(id) AS pending_entries', "status='pending'"), 'pending_entries');
    $pending_count = max($pending_changes_count, $pending_entries_count);
    if($pending_count < 1) {
        return;
    }

    $url = 'modcp.php?action=freizeit_queue';
    $freizeit_red_alert = '<div class="red_alert" style="margin-bottom:10px;">'
        .'<a href="'.$url.'">'.$lang->sprintf($lang->freizeit_pending_alert, my_number_format($pending_count)).'</a>'
        .'</div>';
}

function freizeitliste_index_alert_fallback()
{
    global $index, $freizeit_red_alert;

    if(trim((string)$freizeit_red_alert) === '') {
        return;
    }

    if(strpos($index, $freizeit_red_alert) !== false) {
        return;
    }

    $index = $freizeit_red_alert.$index;
}

function freizeitliste_modcp_nav()
{
    global $templates, $lang, $modcp_nav_users, $modcp_nav_user, $modcp_nav_forums_posts, $modcp_nav_misc;

    if(!FreizeitlisteCore::is_team_member()) {
        return;
    }

    FreizeitlisteCore::load_language();

    // MyBB builds these section variables before the modcp_nav hook fires and merges them
    // into $modcp_nav afterwards; append to the "misc" section (or the first one available).
    $targets = array('modcp_nav_misc', 'modcp_nav_forums_posts', 'modcp_nav_user', 'modcp_nav_users');
    $target_var = 'modcp_nav_misc';
    foreach($targets as $candidate) {
        if(isset($GLOBALS[$candidate])) {
            $target_var = $candidate;
            break;
        }
    }

    $current_nav = isset($GLOBALS[$target_var]) ? (string)$GLOBALS[$target_var] : '';

    // Idempotency guard: never inject the links twice.
    if(strpos($current_nav, 'action=freizeit_queue') !== false) {
        return;
    }

    $modcp_nav_template = isset($templates) ? (string)$templates->get('modcp_nav') : '';
    $is_list_layout = (strpos($modcp_nav_template, '<li') !== false) || (strpos($current_nav, '<li') !== false);

    if($is_list_layout) {
        $GLOBALS[$target_var] .= '<li><a href="modcp.php?action=freizeit_queue" class="modcp_nav_item">'.$lang->freizeit_modcp_queue.'</a></li>'
            .'<li><a href="modcp.php?action=freizeit_contacts" class="modcp_nav_item">'.$lang->freizeit_modcp_contacts.'</a></li>';
    } else {
        $GLOBALS[$target_var] .= '<tr><td class="trow1 smalltext"><a href="modcp.php?action=freizeit_queue">'.$lang->freizeit_modcp_queue.'</a></td></tr>'
            .'<tr><td class="trow1 smalltext"><a href="modcp.php?action=freizeit_contacts">'.$lang->freizeit_modcp_contacts.'</a></td></tr>';
    }
}

function freizeitliste_modcp_router()
{
    global $mybb, $lang;

    if(!in_array($mybb->get_input('action'), array('freizeit_queue', 'freizeit_contacts'))) {
        return;
    }

    if(!FreizeitlisteCore::is_team_member()) {
        error_no_permission();
    }

    FreizeitlisteCore::load_language();

    if($mybb->get_input('action') === 'freizeit_queue') {
        if($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            $pending_id = (int)$mybb->get_input('pending_id');
            $decision = $mybb->get_input('decision');

            if($pending_id > 0 && $decision === 'approve') {
                FreizeitlisteCore::approve_pending($pending_id);
                redirect('modcp.php?action=freizeit_queue', $lang->freizeit_success_approved);
            } elseif($pending_id > 0 && $decision === 'reject') {
                FreizeitlisteCore::reject_pending($pending_id);
                redirect('modcp.php?action=freizeit_queue', $lang->freizeit_success_rejected);
            }
        }

        FreizeitlisteCore::render_modcp_queue_page();
    }

    if($mybb->get_input('action') === 'freizeit_contacts') {
        if($mybb->request_method === 'post') {
            verify_post_check($mybb->get_input('my_post_key'));
            FreizeitlisteCore::handle_modcp_contacts_post();
        }

        FreizeitlisteCore::render_modcp_contacts_page();
    }
}

function freizeitliste_admin_config_menu(&$sub_menu)
{
    $sub_menu[] = array(
        'id' => 'freizeitliste',
        'title' => 'Freizeitliste',
        'link' => 'index.php?module=config-freizeitliste'
    );
}

function freizeitliste_admin_action_handler(&$actions)
{
    $actions['freizeitliste'] = array(
        'active' => 'freizeitliste',
        'file' => 'freizeitliste.php'
    );
}

function freizeitliste_admin_permissions(&$admin_permissions)
{
    $admin_permissions['freizeitliste'] = 'Kann Freizeitliste-Kategorien verwalten';
}
