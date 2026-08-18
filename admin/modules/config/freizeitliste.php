<?php
/**
 * Freizeitliste – ACP-Modul (config).
 *
 * Verwaltung der Kategorien (anlegen, bearbeiten, löschen, sortieren).
 *
 * @package freizeitliste
 */

if(!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

require_once MYBB_ROOT.'inc/plugins/freizeitliste/core.php';

// ACP-Sprachdatei laden (admin + Frontend-Fallback). Ohne diesen Aufruf bleiben
// alle $lang->freizeit_*-Strings im ACP leer (Breadcrumb, Tabs, Header, Labels).
FreizeitlisteCore::load_language(true);

$page->add_breadcrumb_item($lang->freizeit_acp_categories, 'index.php?module=config-freizeitliste');

if(!check_admin_permissions(array('module' => 'config', 'action' => 'freizeitliste'))) {
    flash_message($lang->error_no_permission, 'error');
    admin_redirect('index.php?module=home');
}

if($mybb->request_method === 'post') {
    verify_post_check($mybb->get_input('my_post_key'));

    $edit_id_post = (int)$mybb->get_input('edit_id');
    if($edit_id_post > 0) {
        admin_redirect('index.php?module=config-freizeitliste&edit_id='.$edit_id_post);
    }

    $delete_id_post = (int)$mybb->get_input('delete_id');
    if($delete_id_post > 0) {
        $db->delete_query('freizeit_categories', 'id = '.$delete_id_post);
        flash_message($lang->freizeit_success_category_deleted, 'success');
        admin_redirect('index.php?module=config-freizeitliste');
    }

    $do = $mybb->get_input('do');
    if($do === 'add') {
        $insert = array(
            'name' => $db->escape_string(trim($mybb->get_input('name'))),
            'description' => $db->escape_string(trim($mybb->get_input('description'))),
            'displayorder' => (int)$mybb->get_input('displayorder')
        );
        if($insert['name'] !== '') {
            $db->insert_query('freizeit_categories', $insert);
            flash_message($lang->freizeit_success_category_added, 'success');
        }
    } elseif($do === 'edit') {
        $category_id = (int)$mybb->get_input('category_id');
        $update = array(
            'name' => $db->escape_string(trim($mybb->get_input('name'))),
            'description' => $db->escape_string(trim($mybb->get_input('description'))),
            'displayorder' => (int)$mybb->get_input('displayorder')
        );
        $db->update_query('freizeit_categories', $update, 'id = '.$category_id);
        flash_message($lang->freizeit_success_category_updated, 'success');
    } elseif($do === 'delete') {
        $category_id = (int)$mybb->get_input('category_id');
        $db->delete_query('freizeit_categories', 'id = '.$category_id);
        flash_message($lang->freizeit_success_category_deleted, 'success');
    } elseif($do === 'save_order') {
        $orders = $mybb->get_input('displayorder', MyBB::INPUT_ARRAY);
        if(is_array($orders)) {
            foreach($orders as $category_id => $order) {
                $db->update_query('freizeit_categories', array('displayorder' => (int)$order), 'id = '.(int)$category_id);
            }
        }
        flash_message($lang->freizeit_success_order_saved, 'success');
    }

    admin_redirect('index.php?module=config-freizeitliste');
}

$sub_tabs['freizeitliste'] = array(
    'title' => $lang->freizeit_acp_categories,
    'link' => 'index.php?module=config-freizeitliste',
    'description' => $lang->freizeit_acp_categories_desc
);

$page->output_header($lang->freizeit_acp_categories);
$page->output_nav_tabs($sub_tabs, 'freizeitliste');

$query = $db->simple_select('freizeit_categories', '*', '', array('order_by' => 'displayorder, id', 'order_dir' => 'ASC'));

$edit_id = (int)$mybb->get_input('edit_id');
$edit_category = array();
if($edit_id > 0) {
    $edit_category = $db->fetch_array($db->simple_select('freizeit_categories', '*', 'id = '.$edit_id));
    if(!(int)$edit_category['id']) {
        $edit_id = 0;
        $edit_category = array();
    }
}

$table = new Table;
$table->construct_header($lang->freizeit_category_name);
$table->construct_header($lang->freizeit_category_description);
$table->construct_header($lang->freizeit_category_displayorder, array('class' => 'align_center', 'width' => 120));
$table->construct_header($lang->freizeit_action, array('class' => 'align_center', 'width' => 260));

$order_form = new Form('index.php?module=config-freizeitliste', 'post');
echo $order_form->generate_hidden_field('my_post_key', $mybb->post_code);
echo $order_form->generate_hidden_field('do', 'save_order');

while($category = $db->fetch_array($query)) {
    $category_name = htmlspecialchars_uni($category['name']);
    $category_description = nl2br(htmlspecialchars_uni($category['description']));
    $category_id = (int)$category['id'];

    $table->construct_cell($category_name);
    $table->construct_cell($category_description);
    $table->construct_cell('<input type="number" class="text_input" style="width: 70px;" name="displayorder['.$category_id.']" value="'.(int)$category['displayorder'].'" />', array('class' => 'align_center'));

    $edit_button = '<button type="submit" class="button" name="edit_id" value="'.$category_id.'">'.$lang->edit.'</button>';
    $delete_button = '<button type="submit" class="button" name="delete_id" value="'.$category_id.'" onclick="return confirm(\''.$lang->freizeit_confirm_delete_category.'\');">'.$lang->delete.'</button>';

    $table->construct_cell($edit_button.' '.$delete_button, array('class' => 'align_center'));
    $table->construct_row();
}

if($table->num_rows() === 0) {
    $table->construct_cell($lang->freizeit_no_categories, array('colspan' => 4));
    $table->construct_row();
}

$table->output($lang->freizeit_acp_categories);
echo '<div style="margin-top:8px; text-align:center;">'.$order_form->generate_submit_button($lang->freizeit_save_order).'</div>';
$order_form->end();

$form = new Form('index.php?module=config-freizeitliste', 'post');
echo $form->generate_hidden_field('my_post_key', $mybb->post_code);
if($edit_id > 0) {
    echo $form->generate_hidden_field('do', 'edit');
    echo $form->generate_hidden_field('category_id', $edit_id);
    $form_title = $lang->freizeit_edit_category;
    $submit_label = $lang->freizeit_save_changes;
    $default_name = htmlspecialchars_uni($edit_category['name']);
    $default_description = htmlspecialchars_uni($edit_category['description']);
    $default_order = (int)$edit_category['displayorder'];
} else {
    echo $form->generate_hidden_field('do', 'add');
    $form_title = $lang->freizeit_add_category;
    $submit_label = $lang->freizeit_add_category;
    $default_name = '';
    $default_description = '';
    $default_order = 0;
}

$form_container = new FormContainer($form_title);
$form_container->output_row($lang->freizeit_category_name, '', $form->generate_text_box('name', $default_name), 'name');
$form_container->output_row($lang->freizeit_category_description, '', $form->generate_text_area('description', $default_description, array('rows' => 4, 'cols' => 60)), 'description');
$form_container->output_row($lang->freizeit_category_displayorder, '', $form->generate_numeric_field('displayorder', $default_order, array('min' => 0)), 'displayorder');
$form_container->end();
$buttons = array();
$buttons[] = $form->generate_submit_button($submit_label);
if($edit_id > 0) {
    $buttons[] = '<a class="button" href="index.php?module=config-freizeitliste">'.$lang->cancel.'</a>';
}
$form->output_submit_wrapper($buttons);
$form->end();

$page->output_footer();
