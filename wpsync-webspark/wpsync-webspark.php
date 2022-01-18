<?php
/**
 * Plugin Name: wpsync-webspark
 * Description: Parsing website
 * Version: 1.0.0
 *
 */
function my_parser($url)
{
    $content = file_get_contents($url);
    $myarray = json_decode($content, true);

    my_parser_while($myarray);
}

function my_parser_while($out)
{

    $array = array();

    foreach ($out as $job) {
        $sku = $job['sku'];
        $name = $job['name'];
        $description = $job['description'];
        $price = $job['price'];
        $picture = $job['picture'];
        $in_stock = $job['in_stock'];

        if (!empty($sku) and !empty($name) and !empty($description) and !empty($price) and !empty($picture) and !empty($in_stock)) {

            my_parser_insert($sku, $name, $description, $price, $picture, $in_stock);

        }


    }
}

function my_parser_insert($sku, $name, $description, $price, $picture, $in_stock)
{
    try {
        $status = get_item_from_db($sku);
        if (!$status['exist']) {
            $product = new WC_Product_Simple();
            $product->set_name($name);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_price($price);
            $product->set_regular_price($price);
            $product->set_sold_individually(true);
            $product->set_sku($sku);
            $product->set_description($description);

            $product->set_manage_stock(true);
            $product->set_stock_quantity($in_stock);

            $product->save();

            upload_image($picture, $product->get_id(), true);
        } else {

            $product = new WC_Product($status['idProduct']);
            $product->set_name($name);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_price($price);
            $product->set_sold_individually(true);
            $product->set_sku($sku);
            $product->set_description($description);

            $product->set_manage_stock(true);
            $product->set_stock_quantity($in_stock);

            $product->save();

        }
    } catch (\Exception $ex) {

    }


}

function upload_image($image_url, $attach_to_post = 0, $add_to_media = true)
{
    $remote_image = fopen($image_url, 'r');

    if (!$remote_image) return false;

    $meta = stream_get_meta_data($remote_image);

    $image_meta = false;
    $image_filetype = false;

    if ($meta && !empty($meta['wrapper_data'])) {
        foreach ($meta['wrapper_data'] as $v) {
            if (preg_match('/Content\-Type: ?((image)\/?(jpe?g|png|gif|bmp))/i', $v, $matches)) {
                $image_meta = $matches[1];
                $image_filetype = $matches[3];
            }
        }
    }

// Resource did not provide an image.
    if (!$image_meta) return false;

    $v = basename($image_url);
    if ($v && strlen($v) > 6) {
        // Create a filename from the URL's file, if it is long enough
        $path = $v;
    } else {
        // Short filenames should use the path from the URL (not domain)
        $url_parsed = parse_url($image_url);
        $path = isset($url_parsed['path']) ? $url_parsed['path'] : $image_url;
    }

    $path = preg_replace('/(https?:|\/|www\.|\.[a-zA-Z]{2,4}$)/i', '', $path);
    $filename_no_ext = sanitize_title_with_dashes($path, '', 'save');

    $extension = $image_filetype;
    $filename = $filename_no_ext . "." . $extension;

// Simulate uploading a file through $_FILES. We need a temporary file for this.
    $stream_content = stream_get_contents($remote_image);

    $tmp = tmpfile();
    $tmp_path = stream_get_meta_data($tmp)['uri'];
    fwrite($tmp, $stream_content);
    fseek($tmp, 0); // If we don't do this, WordPress thinks the file is empty

    $fake_FILE = array(
        'name' => $filename,
        'type' => 'image/' . $extension,
        'tmp_name' => $tmp_path,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($stream_content),
    );

// Trick is_uploaded_file() by adding it to the superglobal
    $_FILES[basename($tmp_path)] = $fake_FILE;

// For wp_handle_upload to work:
    include_once ABSPATH . 'wp-admin/includes/media.php';
    include_once ABSPATH . 'wp-admin/includes/file.php';
    include_once ABSPATH . 'wp-admin/includes/image.php';

    $result = wp_handle_upload($fake_FILE, array(
        'test_form' => false,
        'action' => 'local',
    ));

    fclose($tmp); // Close tmp file
    @unlink($tmp_path); // Delete the tmp file. Closing it should also delete it, so hide any warnings with @
    unset($_FILES[basename($tmp_path)]); // Clean up our $_FILES mess.

    fclose($remote_image); // Close the opened image resource

    $result['attachment_id'] = 0;

    if (empty($result['error']) && $add_to_media) {
        $args = array(
            'post_title' => $filename_no_ext,
            'post_content' => '',
            'post_status' => 'publish',
            'post_mime_type' => $result['type'],
        );

        $result['attachment_id'] = wp_insert_attachment($args, $result['file'], $attach_to_post);

        $attach_data = wp_generate_attachment_metadata($result['attachment_id'], $result['file']);
        wp_update_attachment_metadata($result['attachment_id'], $attach_data);

        if (is_wp_error($result['attachment_id'])) {
            $result['attachment_id'] = 0;
        }
    }

    return $result;
}

function get_item_from_db($skuCode)
{
    $products = wc_get_products(array(
        'limit' => -1,
        'status' => 'publish',
    ));


    foreach ($products as $product) {
        $currentSku = strtolower($product->get_sku());
        $skuCode = strtolower($skuCode);
        $status = ['exist' => false, 'idProduct' => null];
        if ($currentSku === $skuCode) {
            $status = ['exist' => true, 'idProduct' => $product->get_id()];
        }
    }
    return $status;
}

add_filter('cron_schedules', 'add_every_one_hours');
function add_every_one_hours($schedules)
{
    $schedules['every_one_hours'] = array(
        'interval'  => 3600,
        'display'   => __('Every 60 Minutes', 'textdomain')
    );
    return $schedules;
}

// Schedule an action if it's not already scheduled
if (!wp_next_scheduled('add_every_one_hours')) {
    wp_schedule_event(time(), 'every_one_hours', 'add_every_one_hours');
}

// Hook into that action that'll fire every five minutes
add_action('add_every_one_hours', 'every_six_minutes_event_func');
function every_six_minutes_event_func()
{
    my_parser("https://my.api.mockaroo.com/products.json?key=89b23a40");
}
