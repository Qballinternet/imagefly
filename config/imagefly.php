<?php defined('SYSPATH') or die('No direct script access.');
/**
 * @package   Modules
 * @category  Imagefly
 * @author    Fady Khalife
 * @uses      Image Module
 */

return array
(
    'cache_expire'     => 7 * 24 * 60 * 60,
    'cache_dir'        => 'media/cache/',
    'mimic_source_dir' => TRUE,
    'enforce_presets'  => TRUE,
	'scale_up'		   => FALSE,
    'presets'          => array(
        'w320-h240-c-q60',
    ),

     // Exec commands that expect a file at the end like <command> <file>
    'exec_commands' => array(
        // 'jpegoptim -p -m90 --strip-all',
        // 'optipng',
    ),
);
