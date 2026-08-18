<?php
if(!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

class FreizeitlisteCore
{
    public static function load_language($admin = false)
    {
        global $lang;

        if($admin === true) {
            $lang->load('freizeitliste', true);
        }

        $lang->load('freizeitliste');

        if(isset($lang->freizeit_title) && trim((string)$lang->freizeit_title) !== '') {
            return;
        }

        $fallback_packs = array('deutsch_du', 'deutsch_sie', 'english');
        foreach($fallback_packs as $pack) {
            $file = MYBB_ROOT.'inc/languages/'.$pack.'/freizeitliste.lang.php';
            if(!file_exists($file)) {
                continue;
            }

            $l = array();
            require $file;
            if(!is_array($l) || empty($l)) {
                continue;
            }

            foreach($l as $key => $value) {
                if(!isset($lang->{$key}) || $lang->{$key} === '') {
                    $lang->{$key} = $value;
                }
            }
            return;
        }
    }

    public static function has_group_permission($setting_name, $user = null)
    {
        global $mybb;

        if($user === null) {
            $user = $mybb->user;
        }

        if(!isset($user['usergroup'])) {
            return false;
        }

        $groups = trim((string)$mybb->settings[$setting_name]);
        if($groups === '') {
            return false;
        }

        $normalized_groups = strtolower(str_replace(' ', '', $groups));
        if($normalized_groups === '-1' || $normalized_groups === 'all' || $normalized_groups === '*') {
            return true;
        }

        $allowed_groups = array();
        foreach(explode(',', $groups) as $group_id) {
            if(trim((string)$group_id) === '-1') {
                return true;
            }

            $group_id = (int)trim($group_id);
            if($group_id > 0) {
                $allowed_groups[] = $group_id;
            }
        }

        if(empty($allowed_groups)) {
            return false;
        }

        $user_groups = array((int)$user['usergroup']);
        $additional_groups = trim((string)$user['additionalgroups']);
        if($additional_groups !== '') {
            foreach(explode(',', $additional_groups) as $group_id) {
                $group_id = (int)trim($group_id);
                if($group_id > 0) {
                    $user_groups[] = $group_id;
                }
            }
        }

        $user_groups = array_unique($user_groups);

        return !empty(array_intersect($allowed_groups, $user_groups));
    }

    public static function can_submit()
    {
        return self::has_group_permission('freizeitliste_submit_groups');
    }

    public static function is_team_member()
    {
        return self::has_group_permission('freizeitliste_modcp_groups');
    }

    public static function can_view_participants()
    {
        return self::has_group_permission('freizeitliste_view_participants_groups');
    }

    public static function handle_submit_post()
    {
        global $mybb, $db, $lang;

        if((int)$mybb->user['uid'] < 1) {
            error_no_permission();
        }

        if(!self::can_submit()) {
            error_no_permission();
        }

        verify_post_check($mybb->get_input('my_post_key'));

        $title = trim($mybb->get_input('title'));
        $category_id = (int)$mybb->get_input('category_id');
        $ort = trim($mybb->get_input('ort'));
        $zeit = trim($mybb->get_input('zeit'));
        $beschreibung = trim($mybb->get_input('beschreibung'));

        if($title === '' || $category_id < 1 || $ort === '' || $zeit === '') {
            error($lang->freizeit_error_missing_fields);
        }

        $category_exists = (int)$db->fetch_field($db->simple_select('freizeit_categories', 'id', "id='{$category_id}'"), 'id');
        if(!$category_exists) {
            error($lang->freizeit_error_invalid_category);
        }

        $insert_entry = array(
            'category_id' => $category_id,
            'title' => $db->escape_string($title),
            'ort' => $db->escape_string($ort),
            'zeit' => $db->escape_string($zeit),
            'beschreibung' => $db->escape_string($beschreibung),
            'created_by' => (int)$mybb->user['uid'],
            'status' => 'pending',
            'created_at' => $db->escape_string(self::now())
        );
        $entry_id = (int)$db->insert_query('freizeit_entries', $insert_entry);

        $payload = array(
            'title' => $title,
            'category_id' => $category_id,
            'ort' => $ort,
            'zeit' => $zeit
        );

        self::insert_pending_change($entry_id, 'new_entry', $payload, (int)$mybb->user['uid']);

        redirect('freizeit.php?submitted=1', $lang->freizeit_success_submission_sent);
    }

    public static function handle_participation_post()
    {
        global $mybb, $db, $lang;

        if((int)$mybb->user['uid'] < 1) {
            error_no_permission();
        }

        verify_post_check($mybb->get_input('my_post_key'));

        $action = $mybb->get_input('participation_action');
        $entry_id = (int)$mybb->get_input('entry_id');
        $rolle = trim($mybb->get_input('rolle'));

        $entry = $db->fetch_array($db->simple_select('freizeit_entries', 'id,status', "id='{$entry_id}'"));
        if(!(int)$entry['id'] || $entry['status'] !== 'approved') {
            error($lang->freizeit_error_invalid_entry);
        }

        $uid = (int)$mybb->user['uid'];

        // 'join' and 'role' share the same upsert logic; only the success message differs.
        if($action === 'join' || $action === 'role') {
            $exists = (int)$db->fetch_field($db->simple_select('freizeit_participants', 'id', "entry_id = {$entry_id} AND user_id = {$uid}"), 'id');
            if($exists) {
                $db->update_query('freizeit_participants', array('rolle' => $db->escape_string($rolle)), 'id = '.$exists);
            } else {
                $db->insert_query('freizeit_participants', array(
                    'entry_id' => $entry_id,
                    'user_id' => $uid,
                    'rolle' => $db->escape_string($rolle)
                ));
            }
            $message = $action === 'join' ? $lang->freizeit_success_joined : $lang->freizeit_success_role_updated;
            redirect('freizeit.php', $message);
        }

        if($action === 'leave') {
            $db->delete_query('freizeit_participants', "entry_id = {$entry_id} AND user_id = {$uid}");
            redirect('freizeit.php', $lang->freizeit_success_left);
        }

        error($lang->freizeit_error_invalid_action);
    }

    public static function fetch_category_structure()
    {
        global $db;

        $categories = array();

        $cat_query = $db->simple_select('freizeit_categories', '*', '', array('order_by' => 'displayorder, id', 'order_dir' => 'ASC'));
        while($category = $db->fetch_array($cat_query)) {
            $category['entries'] = array();
            $categories[(int)$category['id']] = $category;
        }

        if(empty($categories)) {
            return array();
        }

        $entry_query = $db->simple_select('freizeit_entries', '*', "status='approved'", array('order_by' => 'id', 'order_dir' => 'ASC'));
        while($entry = $db->fetch_array($entry_query)) {
            $category_id = (int)$entry['category_id'];
            if(!isset($categories[$category_id])) {
                continue;
            }
            $entry['contacts'] = self::fetch_contacts((int)$entry['id']);
            $entry['participants'] = self::fetch_participants((int)$entry['id']);
            $categories[$category_id]['entries'][] = $entry;
        }

        return $categories;
    }

    public static function fetch_contacts($entry_id)
    {
        global $db;

        $contacts = array();
        $query = $db->query(
            "SELECT c.user_id, u.username, u.usergroup, u.displaygroup
            FROM ".TABLE_PREFIX."freizeit_contacts c
            LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=c.user_id)
            WHERE c.entry_id='".(int)$entry_id."'
            ORDER BY u.username ASC"
        );

        while($row = $db->fetch_array($query)) {
            $contacts[] = $row;
        }

        return $contacts;
    }

    public static function fetch_participants($entry_id)
    {
        global $db;

        $participants = array();
        $query = $db->query(
            "SELECT p.user_id, p.rolle, u.username, u.usergroup, u.displaygroup
            FROM ".TABLE_PREFIX."freizeit_participants p
            LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=p.user_id)
            WHERE p.entry_id='".(int)$entry_id."'
            ORDER BY u.username ASC"
        );

        while($row = $db->fetch_array($query)) {
            $participants[] = $row;
        }

        return $participants;
    }

    public static function build_submit_form()
    {
        global $templates, $db, $lang, $mybb, $theme;

        if(!self::can_submit()) {
            return '';
        }

        $category_options = '';
        $query = $db->simple_select('freizeit_categories', 'id,name', '', array('order_by' => 'displayorder, id', 'order_dir' => 'ASC'));
        while($category = $db->fetch_array($query)) {
            $category_options .= '<option value="'.(int)$category['id'].'">'.htmlspecialchars_uni($category['name']).'</option>';
        }

        eval('$html = "'.$templates->get('freizeit_submit_form').'";');
        return $html;
    }

    public static function build_overview_html()
    {
        global $templates, $lang, $mybb, $theme;

        $categories = self::fetch_category_structure();
        if(empty($categories)) {
            return '<div class="trow1">'.$lang->freizeit_no_categories.'</div>';
        }

        $result = '';
        foreach($categories as $category) {
            $category_name = htmlspecialchars_uni($category['name']);
            $category_description = nl2br(htmlspecialchars_uni($category['description']));

            $category_entries = '';
            if(empty($category['entries'])) {
                $category_entries = '<div class="trow1">'.$lang->freizeit_no_entries.'</div>';
            } else {
                foreach($category['entries'] as $entry) {
                    $entry_title = htmlspecialchars_uni($entry['title']);
                    $entry_ort = htmlspecialchars_uni($entry['ort']);
                    $entry_zeit = htmlspecialchars_uni($entry['zeit']);
                    $entry_beschreibung = nl2br(htmlspecialchars_uni($entry['beschreibung']));

                    $entry_contacts = self::format_user_list($entry['contacts'], $lang->freizeit_no_contacts);
                    if(self::can_view_participants()) {
                        $entry_participants = self::build_participants_html($entry['participants']);
                    } else {
                        $entry_participants = $lang->freizeit_participants_hidden;
                    }

                    $entry_participation_form = self::build_participation_form((int)$entry['id']);

                    eval('$category_entries .= "'.$templates->get('freizeit_entry_block').'";');
                }
            }

            eval('$result .= "'.$templates->get('freizeit_category_block').'";');
        }

        return $result;
    }

    public static function build_participants_html(array $participants)
    {
        global $templates, $lang;

        if(empty($participants)) {
            return $lang->freizeit_no_participants;
        }

        $participants_items = '';
        foreach($participants as $participant) {
            $participants_items .= '<li>'.self::build_profile_link($participant);
            $rolle = trim((string)$participant['rolle']);
            if($rolle !== '') {
                $participants_items .= ' ('.htmlspecialchars_uni($rolle).')';
            }
            $participants_items .= '</li>';
        }

        eval('$html = "'.$templates->get('freizeit_participants_list').'";');
        return $html;
    }

    public static function build_participation_form($entry_id)
    {
        global $mybb, $lang;

        if((int)$mybb->user['uid'] < 1) {
            return $lang->freizeit_login_for_participation;
        }

        $entry_id = (int)$entry_id;
        $my_post_key = $mybb->post_code;
        $button_join = htmlspecialchars_uni($lang->freizeit_join);
        $button_leave = htmlspecialchars_uni($lang->freizeit_leave);
        $button_role = htmlspecialchars_uni($lang->freizeit_update_role);

        return '<form action="freizeit.php" method="post" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">'
            .'<input type="hidden" name="my_post_key" value="'.$my_post_key.'" />'
            .'<input type="hidden" name="entry_id" value="'.$entry_id.'" />'
            .'<input type="text" class="textbox" name="rolle" placeholder="'.htmlspecialchars_uni($lang->freizeit_role_placeholder).'" />'
            .'<button type="submit" class="button" name="participation_action" value="join">'.$button_join.'</button>'
            .'<button type="submit" class="button" name="participation_action" value="leave">'.$button_leave.'</button>'
            .'<button type="submit" class="button" name="participation_action" value="role">'.$button_role.'</button>'
            .'</form>';
    }

    public static function format_user_list(array $users, $empty_text)
    {
        if(empty($users)) {
            return $empty_text;
        }

        $formatted = array();
        foreach($users as $user) {
            $formatted[] = self::build_profile_link($user);
        }

        return implode(', ', $formatted);
    }

    public static function build_profile_link(array $user)
    {
        $username = htmlspecialchars_uni((string)$user['username']);
        $uid = (int)$user['user_id'];

        if($uid < 1 || $username === '') {
            return htmlspecialchars_uni('Unbekannt');
        }

        $formatted = format_name($username, (int)$user['usergroup'], (int)$user['displaygroup']);
        return build_profile_link($formatted, $uid);
    }

    public static function insert_pending_change($entry_id, $type, array $payload, $created_by)
    {
        global $db;

        $insert = array(
            'entry_id' => (int)$entry_id,
            'type' => $db->escape_string($type),
            'payload' => $db->escape_string(json_encode($payload)),
            'created_by' => (int)$created_by,
            'created_at' => $db->escape_string(self::now())
        );

        return (int)$db->insert_query('freizeit_pending_changes', $insert);
    }

    public static function ensure_pending_queue_integrity()
    {
        global $db;

        $query = $db->query(
            "SELECT e.id, e.title, e.category_id, e.ort, e.zeit, e.created_by, e.created_at
            FROM ".TABLE_PREFIX."freizeit_entries e
            LEFT JOIN ".TABLE_PREFIX."freizeit_pending_changes p
                ON (p.entry_id=e.id AND p.type='new_entry')
            WHERE e.status='pending' AND p.id IS NULL"
        );

        while($entry = $db->fetch_array($query)) {
            $payload = array(
                'title' => (string)$entry['title'],
                'category_id' => (int)$entry['category_id'],
                'ort' => (string)$entry['ort'],
                'zeit' => (string)$entry['zeit']
            );

            $created_by = (int)$entry['created_by'];
            $created_at = (string)$entry['created_at'];
            if($created_at === '') {
                $created_at = self::now();
            }

            $insert = array(
                'entry_id' => (int)$entry['id'],
                'type' => $db->escape_string('new_entry'),
                'payload' => $db->escape_string(json_encode($payload)),
                'created_by' => $created_by,
                'created_at' => $db->escape_string($created_at)
            );

            $db->insert_query('freizeit_pending_changes', $insert);
        }
    }

    public static function fetch_pending_changes()
    {
        global $db;

        self::ensure_pending_queue_integrity();

        $rows = array();
        $query = $db->query(
            "SELECT p.*, e.title, e.ort, e.zeit, e.beschreibung, u.username
            FROM ".TABLE_PREFIX."freizeit_pending_changes p
            LEFT JOIN ".TABLE_PREFIX."freizeit_entries e ON (e.id=p.entry_id)
            LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=p.created_by)
            ORDER BY p.created_at ASC"
        );

        while($row = $db->fetch_array($query)) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function approve_pending($pending_id)
    {
        global $db;

        $pending_id = (int)$pending_id;
        $pending = $db->fetch_array($db->simple_select('freizeit_pending_changes', '*', "id='{$pending_id}'"));
        if(!(int)$pending['id']) {
            return false;
        }

        if($pending['type'] === 'new_entry') {
            $db->update_query('freizeit_entries', array('status' => 'approved'), "id='".(int)$pending['entry_id']."'");
        }

        if($pending['type'] === 'contact_change') {
            $payload = json_decode($pending['payload'], true);
            if(is_array($payload) && isset($payload['action'], $payload['user_id'])) {
                $entry_id = (int)$pending['entry_id'];
                $user_id = (int)$payload['user_id'];

                if($payload['action'] === 'add') {
                    $exists = (int)$db->fetch_field($db->simple_select('freizeit_contacts', 'id', "entry_id='{$entry_id}' AND user_id='{$user_id}'"), 'id');
                    if(!$exists) {
                        $db->insert_query('freizeit_contacts', array('entry_id' => $entry_id, 'user_id' => $user_id));
                    }
                }

                if($payload['action'] === 'remove') {
                    $db->delete_query('freizeit_contacts', "entry_id='{$entry_id}' AND user_id='{$user_id}'");
                }
            }
        }

        $db->delete_query('freizeit_pending_changes', "id='{$pending_id}'");
        return true;
    }

    public static function reject_pending($pending_id)
    {
        global $db;

        $pending_id = (int)$pending_id;
        $pending = $db->fetch_array($db->simple_select('freizeit_pending_changes', '*', "id='{$pending_id}'"));
        if(!(int)$pending['id']) {
            return false;
        }

        if($pending['type'] === 'new_entry') {
            $entry_id = (int)$pending['entry_id'];
            $db->delete_query('freizeit_contacts', "entry_id='{$entry_id}'");
            $db->delete_query('freizeit_participants', "entry_id='{$entry_id}'");
            $db->delete_query('freizeit_entries', "id='{$entry_id}'");
        }

        $db->delete_query('freizeit_pending_changes', "id='{$pending_id}'");
        return true;
    }

    public static function render_modcp_queue_page()
    {
        global $templates, $lang, $headerinclude, $header, $footer, $theme, $mybb, $modcp_nav;

        $changes = self::fetch_pending_changes();
        $queue_rows = '';

        if(empty($changes)) {
            $queue_rows .= '<tr><td class="trow1" colspan="6">'.$lang->freizeit_no_pending_changes.'</td></tr>';
        } else {
            foreach($changes as $change) {
                $pending_id = (int)$change['id'];
                if($change['type'] === 'new_entry') {
                    $type = $lang->freizeit_type_new_entry;
                } elseif($change['type'] === 'contact_change') {
                    $type = $lang->freizeit_type_contact_change;
                } else {
                    $type = htmlspecialchars_uni((string)$change['type']);
                }
                $title = htmlspecialchars_uni((string)$change['title']);
                $creator = htmlspecialchars_uni((string)$change['username']);
                $created_at = htmlspecialchars_uni((string)$change['created_at']);

                if($change['type'] === 'new_entry') {
                    $ort = htmlspecialchars_uni((string)$change['ort']);
                    $zeit = htmlspecialchars_uni((string)$change['zeit']);
                    $beschreibung = nl2br(htmlspecialchars_uni((string)$change['beschreibung']));

                    $details = '<div class="smalltext" style="margin-top:4px;">'
                        .'<strong>'.$lang->freizeit_ort.':</strong> '.$ort.'<br />'
                        .'<strong>'.$lang->freizeit_zeit.':</strong> '.$zeit.'<br />'
                        .'<strong>'.$lang->freizeit_beschreibung.':</strong> '.$beschreibung
                        .'</div>';

                    $title .= $details;
                }

                $actions = '<form method="post" action="modcp.php?action=freizeit_queue" style="display:inline-block;">'
                    .'<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />'
                    .'<input type="hidden" name="pending_id" value="'.$pending_id.'" />'
                    .'<button type="submit" class="button" name="decision" value="approve">'.$lang->freizeit_approve.'</button>'
                    .' <button type="submit" class="button" name="decision" value="reject">'.$lang->freizeit_reject.'</button>'
                    .'</form>';

                $queue_rows .= '<tr>'
                    .'<td class="trow1">'.$pending_id.'</td>'
                    .'<td class="trow1">'.$type.'</td>'
                    .'<td class="trow1">'.$title.'</td>'
                    .'<td class="trow1">'.$creator.'</td>'
                    .'<td class="trow1">'.$created_at.'</td>'
                    .'<td class="trow1">'.$actions.'</td>'
                    .'</tr>';
            }
        }

        eval('$page = "'.$templates->get('modcp_freizeit_queue').'";');

        output_page($page);
        exit;
    }

    public static function handle_modcp_contacts_post()
    {
        global $mybb, $db, $lang;

        $entry_id = (int)$mybb->get_input('entry_id');
        $contact_action = $mybb->get_input('contact_action');

        $entry = $db->fetch_array($db->simple_select('freizeit_entries', 'id,status', "id='{$entry_id}'"));
        if(!(int)$entry['id'] || $entry['status'] !== 'approved') {
            error($lang->freizeit_error_invalid_entry);
        }

        if($contact_action === 'add') {
            $username = trim($mybb->get_input('username'));
            if($username === '') {
                error($lang->freizeit_error_missing_user);
            }

            $escaped = $db->escape_string($username);
            $user = $db->fetch_array($db->simple_select('users', 'uid,username', "username='{$escaped}'"));
            if(!(int)$user['uid']) {
                error($lang->freizeit_error_user_not_found);
            }

            self::insert_pending_change($entry_id, 'contact_change', array(
                'action' => 'add',
                'user_id' => (int)$user['uid']
            ), (int)$mybb->user['uid']);

            redirect('modcp.php?action=freizeit_contacts', $lang->freizeit_success_contact_change_queued);
        }

        if($contact_action === 'remove') {
            $user_id = (int)$mybb->get_input('user_id');
            $exists = (int)$db->fetch_field($db->simple_select('freizeit_contacts', 'id', "entry_id='{$entry_id}' AND user_id='{$user_id}'"), 'id');
            if(!$exists) {
                error($lang->freizeit_error_contact_not_found);
            }

            self::insert_pending_change($entry_id, 'contact_change', array(
                'action' => 'remove',
                'user_id' => $user_id
            ), (int)$mybb->user['uid']);

            redirect('modcp.php?action=freizeit_contacts', $lang->freizeit_success_contact_change_queued);
        }

        error($lang->freizeit_error_invalid_action);
    }

    public static function render_modcp_contacts_page()
    {
        global $templates, $lang, $headerinclude, $header, $footer, $theme, $mybb, $db, $modcp_nav;

        $contacts_content = '';

        $entries = $db->simple_select('freizeit_entries', 'id,title', "status='approved'", array('order_by' => 'title', 'order_dir' => 'ASC'));
        while($entry = $db->fetch_array($entries)) {
            $entry_id = (int)$entry['id'];
            $title = htmlspecialchars_uni($entry['title']);
            $current_contacts = self::fetch_contacts($entry_id);
            $contacts = self::format_user_list($current_contacts, $lang->freizeit_no_contacts);

            $contacts_content .= '<table border="0" cellspacing="'.$theme['borderwidth'].'" cellpadding="'.$theme['tablespace'].'" class="tborder" width="100%" style="margin-bottom:10px;">'
                .'<tr><td class="thead" colspan="2"><strong>'.$title.'</strong></td></tr>'
                .'<tr><td class="trow1" width="25%">'.$lang->freizeit_contacts.'</td><td class="trow1">'.$contacts.'</td></tr>'
                .'<tr><td class="trow2">'.$lang->freizeit_add_contact.'</td><td class="trow2">'
                .'<form action="modcp.php?action=freizeit_contacts" method="post">'
                .'<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />'
                .'<input type="hidden" name="entry_id" value="'.$entry_id.'" />'
                .'<input type="hidden" name="contact_action" value="add" />'
                .'<input type="text" class="textbox" name="username" /> '
                .'<input type="submit" class="button" value="'.$lang->freizeit_queue_contact_add.'" />'
                .'</form>'
                .'</td></tr>';

            if(!empty($current_contacts)) {
                $contacts_content .= '<tr><td class="trow1">'.$lang->freizeit_remove_contact.'</td><td class="trow1">';
                foreach($current_contacts as $contact) {
                    $contacts_content .= '<form action="modcp.php?action=freizeit_contacts" method="post" style="display:inline-block; margin-right:6px;">'
                        .'<input type="hidden" name="my_post_key" value="'.$mybb->post_code.'" />'
                        .'<input type="hidden" name="entry_id" value="'.$entry_id.'" />'
                        .'<input type="hidden" name="contact_action" value="remove" />'
                        .'<input type="hidden" name="user_id" value="'.(int)$contact['user_id'].'" />'
                        .'<button type="submit" class="button">'.$lang->freizeit_queue_contact_remove.': '.self::build_profile_link($contact).'</button>'
                        .'</form>';
                }
                $contacts_content .= '</td></tr>';
            }

            $contacts_content .= '</table>';
        }

        if($contacts_content === '') {
            $contacts_content = '<div class="trow1">'.$lang->freizeit_no_entries.'</div>';
        }

        eval('$page = "'.$templates->get('modcp_freizeit_edit_contacts').'";');

        output_page($page);
        exit;
    }

    public static function now()
    {
        return date('Y-m-d H:i:s', TIME_NOW);
    }
}
