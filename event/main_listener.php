<?php
/**
 *
 * Avatar Resize. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2018, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */
namespace ger\avatarresize\event;

/**
 * @ignore
 */
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Magic OGP event listener
 */
class main_listener implements EventSubscriberInterface
{

    public $config;
    protected $phpbb_root_path;
    protected $php_ext;

    static public function getSubscribedEvents()
    {
        return array(
            'core.avatar_driver_upload_move_file_before' => 'resize',
        );
    }

    public function __construct(\phpbb\config\config $config, $phpbb_root_path, $php_ext)
    {
        $this->config = $config;
        $this->phpbb_root_path = $phpbb_root_path;
        $this->php_ext = $php_ext;
    }

    /**
     * Resize too large avatar
     * @param array $event
     */
    public function resize($event)
    {
        // Decide if we need to do anything at all
        $dimension = @getimagesize($event['filedata']['filename']);

        if ($dimension === false) 
        {
            return false;
        }

        list($width, $height, $type) = $dimension;

        if (empty($width) || empty($height)) 
        {
            return false;
        }

        $max_width = $this->config['avatar_max_width'];
        $max_height = $this->config['avatar_max_height'];

        if (($width > $max_width) || ($height > $max_height)) {
            // Keep proportions
            if ($width > $height) 
            {
                $new_height = round($height * ($max_width / $width));
                $new_width = $max_width;
            } 
            else 
            {
                $new_height = $max_height;
                $new_width = round($width * ($max_height / $height));
            }

            $data = $this->do_resize($event['filedata'], $type, $new_width, $new_height, $width, $height);
        } 
        else 
        {
            // Nothing to do
            return false;
        }
        if ($data !== false) 
        {

            $upload_ary = array(
                'tmp_name' => $data['destination'],
                'size' => $data['filesize'],
                'name' => $event['filedata']['physical_filename'],
                'type' => $event['filedata']['mimetype'],
            );
            $event['file']->set_upload_ary($upload_ary);
        }
    }

    /**
     * Resizing magic happens here
     * @param array $filedata
     * @param int $type
     * @param int $new_width
     * @param int $new_height
     * @param int $orig_width
     * @param int $orig_height
     * @return array
     */
    private function do_resize($filedata, $type, $new_width, $new_height, $orig_width, $orig_height)
    {
        $used_imagick = false;
        $destination = $filedata['filename'] . '_resized';
        // Only use ImageMagick if defined and the passthru function not disabled
        if ($this->config['img_imagick'] && function_exists('passthru')) 
        {
            if (substr($this->config['img_imagick'], -1) !== '/') 
            {
                $this->config['img_imagick'] .= '/';
            }

            @passthru(escapeshellcmd($this->config['img_imagick']) . 'convert' . ((defined('PHP_OS') && preg_match('#^win#i', PHP_OS)) ? '.exe' : '') . ' -quality 85 -geometry ' . $new_width . 'x' . $new_height . ' "' . str_replace('\\', '/', $filedata['filename']) . '" "' . str_replace('\\', '/', $destination) . '"');

            if (file_exists($destination)) 
            {
                $used_imagick = true;
            }
        }

        if (!$used_imagick) 
        {
            if (!function_exists('get_supported_image_types')) 
            {
                include_once($this->phpbb_root_path . 'includes/functions_posting.' . $this->php_ext);
            }
            $type = get_supported_image_types($type);

            if ($type['gd']) 
            {
                // If the type is not supported, we are not able to create a thumbnail
                if ($type['format'] === false) 
                {
                    return false;
                }

                switch ($type['format']) 
                {
                    case IMG_GIF:
                        $image = @imagecreatefromgif($filedata['filename']);
                        break;

                    case IMG_JPG:
                        @ini_set('gd.jpeg_ignore_warning', 1);
                        $image = @imagecreatefromjpeg($filedata['filename']);
                        break;

                    case IMG_PNG:
                        $image = @imagecreatefrompng($filedata['filename']);
                        break;

                    case IMG_WBMP:
                        $image = @imagecreatefromwbmp($filedata['filename']);
                        break;
                }

                if (empty($image)) 
                {
                    return false;
                }

                if ($type['version'] == 1) 
                {
                    $new_image = imagecreate($new_width, $new_height);

                    if ($new_image === false) 
                    {
                        return false;
                    }

                    imagecopyresized($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
                } 
                else 
                {
                    $new_image = imagecreatetruecolor($new_width, $new_height);

                    if ($new_image === false) 
                    {
                        return false;
                    }

                    // Preserve alpha transparency (png for example)
                    @imagealphablending($new_image, false);
                    @imagesavealpha($new_image, true);
                    imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
                }

                // If we are in safe mode create the destination file prior to using the gd functions to circumvent a PHP bug
                if (@ini_get('safe_mode') || @strtolower(ini_get('safe_mode')) == 'on') 
                {
                    @touch($destination);
                }

                switch ($type['format']) 
                {
                    case IMG_GIF:
                        imagegif($new_image, $destination);
                        break;

                    case IMG_JPG:
                        imagejpeg($new_image, $destination, 90);
                        break;

                    case IMG_PNG:
                        imagepng($new_image, $destination);
                        break;

                    case IMG_WBMP:
                        imagewbmp($new_image, $destination);
                        break;
                }
                imagedestroy($new_image);
            } 
            else 
            {
                return false;
            }
        }

        if (!file_exists($destination)) 
        {
            return false;
        }

        return array(
            'destination' => $destination,
            'filesize' => filesize($destination),
        );
    }
}