<?php defined('SYSPATH') or die('No direct script access.');
/**
 * @package   Modules
 * @category  Imagefly
 * @author	Fady Khalife
 * @uses	  Image Module
 */

class ImageFly
{
	/**
	 * @var  array	   This modules config options
	 */
	protected $config = NULL;

	/**
	 * @var  string	  Stores the path to the cache directory which is either whats set in the config "cache_dir"
	 *				   or processed sub directories when the "mimic_source_dir" config option id set to TRUE
	 */
	protected $cache_dir = NULL;

	/**
	 * @var  object	  Kohana image instance
	 */
	protected $image = NULL;

	/**
	 * @var  boolean	 A flag for weither we should serve the default or cached image
	 */
	protected $serve_default = FALSE;

	/**
	 * @var  boolean  Whether a default file should be served when the image
	 *                dimensions are the same as the params
	 */
	protected $serve_default_on_same_dimensions = FALSE;

	/**
	 * @var  string	  The source filepath and filename
	 */
	protected $source_file = NULL;

	/**
	 * @var  array	   Stores the URL params in the following format
	 *				   w = Width (int)
	 *				   h = Height (int)
	 *				   c = Crop (bool)
	 *				   q = Quality (int)
	 */
	protected $url_params = array();

	/**
	 * @var  array  Original URL params (no modifcations to h or w)
	 */
	protected $url_params_original;

	/**
	 * @var  string	  Last modified Unix timestamp of the source file
	 */
	protected $source_modified = NULL;

	/**
	 * @var  string	  The cached filename with path ($this->cache_dir)
	 */
	protected $cached_file = NULL;

	/**
	 * @var  array	   The params from Request
	 */
	protected $params = NULL;

	/**
	 * Constructorbot
	 *
	 * @param  array  $params	Array to overwrite 'params' and 'imagepath'.
	 *						   Defaults to Request::current()->param()
	 */
	public function __construct(array $params=NULL, array $default_param_values=array())
	{
		// Load params from argument
		if ($params)
		{
			$this->params = $params;
		}
		// Load params from current request
		else
		{
			$this->params = Request::current()->param();
		}

		// Prevent unnecessary warnings on servers that are set to display E_STRICT errors, these will damage the image data.
		error_reporting(error_reporting() & ~E_STRICT);

		// Set the config
		$this->config = Kohana::$config->load('imagefly');

		// Try to create the cache directory if it does not exist
		$this->_create_cache_dir();

		// Parse and set the image modify params
		$this->_set_params($default_param_values);

		// Set the source file modified timestamp
		$this->source_modified = filemtime($this->source_file);

		// Try to create the mimic directory structure if required
		$this->_create_mimic_cache_dir();

		// Set the cached filepath with filename
		$this->cached_file = $this->cache_dir.$this->_encoded_filename();

		// Create a modified cache file or dont...
		if ( ! $this->_cached_exists() AND $this->_cached_required())
		{
			$this->_create_cached();
		}

		// Serve the image file
		$this->_serve_file();
	}

	/**
	 * Try to create the config cache dir if required
	 * Set $cache_dir
	 */
	private function _create_cache_dir()
	{
		if( ! file_exists($this->config['cache_dir']))
		{
			try
			{
				mkdir($this->config['cache_dir'], 0755, TRUE);
			}
			catch(Exception $e)
			{
				// The dir still not exists (we check cause sometimes two
				// requests are handled at the same time)
				if( ! file_exists($this->config['cache_dir']))
				{
					// Rethrow exception again
					throw $e;
				}
			}
		}

		// Set the cache dir
		$this->cache_dir = $this->config['cache_dir'];
	}

	/**
	 * Try to create the mimic cache dir from the source path if required
	 * Set $cache_dir
	 */
	private function _create_mimic_cache_dir()
	{
		if ($this->config['mimic_source_dir'])
		{
			// Get the dir from the source file
			$mimic_dir = $this->config['cache_dir'].pathinfo($this->source_file, PATHINFO_DIRNAME);

			// Try to create if it does not exist
			if( ! file_exists($mimic_dir))
			{
				try
				{
					mkdir($mimic_dir, 0755, TRUE);
				}
				catch(Exception $e)
				{
					// The dir still not exists (we check cause sometimes two
					// requests are handled at the same time)
					if( ! file_exists($mimic_dir))
					{
						// Rethrow exception again
						throw $e;
					}
				}
			}

			// Set the cache dir, with trailling slash
			$this->cache_dir = $mimic_dir.'/';
		}
	}

	/**
	 * Sets the operations params from the url
	 * w = Width (int)
	 * h = Height (int)
	 * c = Crop (bool)
	 */
	private function _set_params(array $default_param_values=array())
	{
		// Get values from request
		$params = Arr::get($this->params, 'params');
		$filepath = Arr::get($this->params, 'imagepath');

		// If it has params and its enforcing params, ensure it's a match
		if ($this->config['enforce_presets'] AND
			! in_array($params, $this->config['presets'])
		)
		{
			throw new HTTP_Exception_404('The requested URL :uri was not found on this server.',
													array(':uri' => Request::$current->uri()));
		}

		$this->image = Image::factory($filepath);

		// The parameters are separated by hyphens
		$raw_params = array();
		if ($params)
		{
			$raw_params = explode('-', $params);
		}

		// Set default param values
		$this->url_params['w'] = Arr::get($default_param_values, 'w', NULL);
		$this->url_params['h'] = Arr::get($default_param_values, 'h', NULL);
		$this->url_params['c'] = Arr::get($default_param_values, 'c', FALSE);
		$this->url_params['q'] = Arr::get($default_param_values, 'c', NULL);

		// Store default params to original params
		$this->url_params_original = $this->url_params;

		// Update param values from passed values
		foreach ($raw_params as $raw_param)
		{
			$name = $raw_param[0];
			$value = substr($raw_param, 1, strlen($raw_param) - 1);
			if ($name == 'c')
			{
				$this->url_params[$name] = TRUE;
				$this->url_params_original[$name] = TRUE;

				// When croping, we must have a width and height to pass to imagecreatetruecolor method
				// Make width the height or vice versa if either is not passed
				if (empty($this->url_params['w']))
				{
					$this->url_params['w'] = $this->url_params['h'];
				}
				if (empty($this->url_params['h']))
				{
					$this->url_params['h'] = $this->url_params['w'];
				}
			}
			else
			{
				$this->url_params[$name] = $value;
				$this->url_params_original[$name] = $value;
			}
		}

		//Do not scale up images
		if (!$this->config['scale_up'])
		{
			if ($this->url_params['w'] > $this->image->width) $this->url_params['w'] = $this->image->width;
			if ($this->url_params['h'] > $this->image->height) $this->url_params['h'] = $this->image->height;
		}

		// Set the url filepath
		$this->source_file = $filepath;
	}

	/**
	 * Checks if a physical version of the cached image exists
	 *
	 * @return boolean
	 */
	private function _cached_exists()
	{
		return file_exists($this->cached_file);
	}

	/**
	 * Checks that the param dimensions are are lower then current image dimensions
	 *
	 * @return boolean
	 */
	private function _cached_required()
	{

		$image_info = getimagesize($this->source_file);

		// Try reading exif information for orientation key
		try
		{
			$exif = exif_read_data($this->source_file);
			// Has orientation exif info we need to fix, thus cache the file
			if ( ! empty($exif['Orientation']) AND
				in_array($exif['Orientation'], array(8,3,6)))
			{
				// We will cache to fix any orientation issues
				return TRUE;
			}
		}
		// Ignore any error
		catch (\Exception $ex) {}

		// Same width and height or no params at all
	   	if ( ($this->serve_default_on_same_dimensions AND
	   		  $this->url_params['w'] == $image_info[0] AND ($this->url_params['h'] == $image_info[1]))
	   		OR
			( ! $this->url_params['w'] AND ! $this->url_params['h'])
		)
		{
			$this->serve_default = TRUE;
 			return FALSE;
		}

		return TRUE;
	}

	/**
	 * Returns a hash of the filepath and params plus last modified of source to be used as a unique filename
	 *
	 * @return  string
	 */
	private function _encoded_filename()
	{
		// Get extension from file
		$ext = strtolower(pathinfo($this->source_file, PATHINFO_EXTENSION));

		// No extension
		if ( ! $ext)
		{
			// Get from mime
			$mime = File::mime($this->source_file);

			// Mime is "application/octet-stream", this happens in some cases
			// due to bad server versions of ImageMagick. We assume this
			// module is used for images, so default to jpg. In most cases
			// this will make sure things will keep functioning
			if ($mime === 'application/octet-stream')
			{
				// Fallback to jpg
				$ext = 'jpg';
			}
			// Normal mime
			else
			{
				// Try get extension using Kohana method
               	try
                {
                        // application/octet-stream
                        $ext = File::ext_by_mime($mime);

                        // Fix multiple jpg types from Kohana mime array
                        if ($ext == 'jpe')
                        {
                                $ext = 'jpg';
                        }
                }
                // Exception, find proper extension ourself
                catch (\Exception $ex)
                {
                        // Is bmp message
                        if (strpos(strtolower($mime), 'bmp'))
                        {
                                $ext = 'jpg';
                        }
                        // Is png image
                        elseif (strpos(strtolower($mime), 'png'))
                        {
                                $ext = 'png';
                        }
                        // Is gif image
                        elseif (strpos(strtolower($mime), 'gif'))
                        {
                                $ext = 'gif';
                        }
                        // No matches, fallback to jpg
                        else
                        {
                                $ext = 'jpg';
                        }
                }
			}
		}

		// $encode = md5($this->source_file.http_build_query($this->url_params));
		$encode = strtolower(pathinfo($this->source_file, PATHINFO_FILENAME));

		// Build the parts of the filename
		// $encoded_name = $encode.'-'.$this->source_modified.'.'.$ext;
		$encoded_name = $encode.'_'.http_build_query($this->url_params_original).'.'.$ext;

		return $encoded_name;
	}

	/**
	 * Creates a cached cropped/resized version of the file
	 */
	private function _create_cached()
	{
		// Do not strip exif info by default
		$strip_exif = FALSE;

		// Check whether we need to rotate
		try
		{
			$exif = exif_read_data($this->source_file);
			if( ! empty($exif['Orientation']))
			{
			    switch($exif['Orientation']) {
					case 3:
						$this->image->rotate(180);
						$strip_exif = TRUE;
					break;
					case 6:
						$this->image->rotate(90);
						$strip_exif = TRUE;
					break;
					case 8:
						$this->image->rotate(-90);
						$strip_exif = TRUE;
					break;
			    }
			}
		}
		// Ignore any error
		catch (\Exception $ex) {}

		if($this->url_params['c'])
		{
			// Resize to highest width or height with overflow on the larger side
			$this->image->resize($this->url_params['w'], $this->url_params['h'], Image::INVERSE);

			// Crop any overflow from the larger side
			$this->image->crop($this->url_params['w'], $this->url_params['h']);
		}
		elseif($this->url_params['w'] OR $this->url_params['h'])
		{
			// Just Resize
			$this->image->resize($this->url_params['w'], $this->url_params['h']);
		}

		// Save
		if($this->url_params['q'])
		{
			//Save image with quality param
			$this->image->save($this->cached_file, $this->url_params['q']);
		}
		else
		{
			//Save image with default quality
			$this->image->save($this->cached_file);
		}

		// Strip any exif info when we used Imagick driver (GD removes by default)
		if ($this->image instanceof Image_Imagick AND $strip_exif)
		{
			// Try cleaning file using Imagick
			try
			{
				$img = new Imagick($this->cached_file);
				$img->stripImage();
				$img->writeImage($this->cached_file);
				$img->destroy();
			}
			// Catch imagick exception
			catch (ImagickException $ex)
			{
				// do nothing. We do not want to die on simple read errors
			}

		}

		// Loop exec commands when there are any in the config
		if ($exec_commands = $this->config['exec_commands'])
		foreach ($exec_commands as $cmd)
		{
			exec($cmd.' "'.$this->cached_file.'"', $output);
		}
	}

	/**
	 * Create the image HTTP headers
	 *
	 * @param  string	 path to the file to server (either default or cached version)
	 */
	private function _create_headers($file_data)
	{
		// Create the required header vars
		$last_modified = gmdate('D, d M Y H:i:s', filemtime($file_data)).' GMT';
		$content_type = File::mime($file_data);
		$content_length = filesize($file_data);
		$expires = gmdate('D, d M Y H:i:s', (time() + $this->config['cache_expire'])).' GMT';
		$max_age = 'max-age='.$this->config['cache_expire'].', public';

		// Some required headers
		header("Last-Modified: $last_modified");
		header("Content-Type: $content_type");
		header("Content-Length: $content_length");

		// How long to hold in the browser cache
		header("Expires: $expires");

		/**
		 * Public in the Cache-Control lets proxies know that it is okay to
		 * cache this content. If this is being served over HTTPS, there may be
		 * sensitive content and therefore should probably not be cached by
		 * proxy servers.
		 */
		header("Cache-Control: $max_age");

		// Set the 304 Not Modified if required
		$this->_modified_headers($last_modified);

		/**
		 * The "Connection: close" header allows us to serve the file and let
		 * the browser finish processing the script so we can do extra work
		 * without making the user wait. This header must come last or the file
		 * size will not properly work for images in the browser's cache
		 */
		header("Connection: close");
	}

	/**
	 * Rerurns 304 Not Modified HTTP headers if required and exits
	 *
	 * @param  string  header formatted date
	 */
	private function _modified_headers($last_modified)
	{
		$modified_since = (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']))
			? stripslashes($_SERVER['HTTP_IF_MODIFIED_SINCE'])
			: FALSE;

		if ( ! $modified_since OR $modified_since != $last_modified)
			return;

		// Nothing has changed since their last request - serve a 304 and exit
		header('HTTP/1.1 304 Not Modified');
		header('Connection: close');
		exit();
	}

	/**
	 * Decide which filesource we are using and serve
	 */
	private function _serve_file()
	{
		// Set either the source or cache file as our datasource
		if ($this->serve_default)
		{
			$file_data = $this->source_file;
		}
		else
		{
			$file_data = $this->cached_file;
		}

		// Output the file
		$this->_output_file($file_data);
	}

	/**
	 * Outputs the cached image file and exits
	 *
	 * @param  string	 path to the file to server (either default or cached version)
	 */
	private function _output_file($file_data)
	{
		// Create the headers
		$this->_create_headers($file_data);

		// Get the file data
		$data = file_get_contents($file_data);

		// Send the image to the browser in bite-sized chunks
		$chunk_size = 1024 * 8;
		$fp = fopen('php://memory', 'r+b');

		// Process file data
		fwrite($fp, $data);
		rewind($fp);
		while ( ! feof($fp))
		{
			echo fread($fp, $chunk_size);
			flush();
		}
		fclose($fp);

		exit();
	}
}
