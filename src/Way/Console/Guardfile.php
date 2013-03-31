<?php namespace Way\Console;

use Illuminate\Filesystem\Filesystem;

class FileNotFoundException extends \Exception {}

class Guardfile {

	/**
	 * Filesystem Instance
	 *
	 * @var Illuminate\Filesystem\Filesystem
	 */
	protected $file;

	/**
	 * Base path to where Guardfile is store
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * Constructor
	 *
	 * @param  Filesystem $file
	 * @param  string $path
	 * @return void
	 */
	public function __construct(Filesystem $file, $path)
	{
		$this->file = $file;
		$this->path	= $path;
	}

	/**
	 * Get the path to the Guardfile
	 *
	 * @return string
	 */
	public function getPath()
	{
		return $this->path.'/Guardfile';
	}

	/**
	 * Get contents of Guardfile
	 *
	 * @return string
	 */
	public function getContents()
	{
		return $this->file->get($this->getPath());
	}

	/**
	 * Update contents of Guardfile
	 *
	 * @param string $contents
	 * @return void
	 */
	public function put($contents)
	{
		$this->file->put($this->getPath(), $contents);
	}

	/**
	 * Get a guard configuration option
	 *
	 * @param  string $option
	 * @return mixed
	 */
	protected function getConfigOption($option)
	{
		return \Config::get("guard-laravel::guard.{$option}");
	}

	/**
	 * Get stubs for requested plugins
	 *
	 * @param  array  $plugins
	 * @return string
	 */
	public function getStubs(array $plugins)
	{
		$stubs = array();

		foreach($plugins as $plugin)
		{
			$stubs[] = $this->compile($this->getPluginStub($plugin), $plugin);
		}

	    // Now, we'll stitch them all together
		return implode("\n\n", $stubs);
	}

	/**
	 * Gets the stub for a Guard plugin
	 *
	 * @param  string $plugin
	 * @return string
	 */
	public function getPluginStub($plugin)
	{
		$stubPath = __DIR__ . "/stubs/guard-{$plugin}-stub.txt";

		if (file_exists($stubPath))
		{
			return $this->file->get($stubPath);
		}

		throw new FileNotFoundException('Plugin name or stub not recognized');
	}

	/**
	 * Perform search and replace on stub
	 * with data from user config
	 *
	 * @param  string $stub
	 * @param  string $plugin
	 * @return string
	 */
	public function compile($stub, $plugin)
	{
		$stub = $this->applyPathsToStub($stub);
		$stub = $this->applyOptions($stub, $plugin);

		// If we're updating the concat guard plugin,
		// then we need to update the file list, too
		if (starts_with($plugin, 'concat'))
		{
			$stub = $this->applyFileList($stub, substr($plugin, 7));
		}

		return $stub;
	}

	/**
	 * Replace template tags in stub
	 *
	 * @param  string $stub
	 * @return string
	 */
	public function applyPathsToStub($stub)
	{
		return preg_replace_callback('/{{([a-z]+?)Path}}/i', function($matches) {
			$language = $matches[1];

			return $this->getConfigOption("{$language}_path");
		}, $stub);
	}

	/**
	 * Set options for guard plugin
	 *
	 * @param  string $stub
	 * @param string $plugin
	 * @return string
	 */
	protected function applyOptions($stub, $plugin)
	{
		$pluginOptions = $this->getConfigOption("guard_options.{$plugin}");

		// If options have been set for this guard
		// then format them as Ruby, and search+replace
		if (! empty($pluginOptions))
		{
			$rubyFormattedOptions = $this->attributes($pluginOptions);
			$stub = str_replace('{{options}}', ', ' . implode(', ', $rubyFormattedOptions), $stub);
		}

		// Otherwise, no options specified.
		$stub = str_replace('{{options}}', '', $stub);

		return $stub;
	}

	/**
	 * Replace stub with updated file list
	 *
	 * @param  string $stub
	 * @param string language [css|js]
	 * @return string
	 */
	protected function applyFileList($stub, $language)
	{
		$files = $this->getFilesToConcat($language);

		return str_replace('{{files}}', implode(' ', $files), $stub);
	}

	/**
	 * Format PHP array to Ruby syntax
	 *
	 * @param  array $pluginOptions
	 * @return array
	 */
	protected function attributes(array $pluginOptions)
	{
		$rubyFormattedOptions = array();

		foreach($pluginOptions as $key => $val)
		{
			$val = var_export($val, true);

			// Some values can be set as symbols
			// If so, we need to convert them for Ruby
			if (starts_with($val, "':"))
			{
				// ':compressed' to :compressed
				$val = trim($val, "'");
			}

			$rubyFormattedOptions[] =  ":$key => $val";
		}

		return $rubyFormattedOptions;
	}

	/**
	 * Update plugin signature and save file
	 *
	 * @param  string $plugin
	 * @return void
	 */
	public function updateSignature($plugin)
	{
		$stub = $this->compile($this->getPluginStub($plugin), $plugin);

		// Concat plugins need special search+replace.
		if (starts_with($plugin, 'concat'))
		{
			$language = substr($plugin, 7);
			$stub = preg_replace('/guard :concat, type: "' . $language . '".+/i', $stub, $this->getContents());
		}
		else
		{
			$module = $plugin === 'refresher' ? '(module.+?)?' : '';
			$stub = preg_replace("/{$module}guard :" . $plugin . ".+?(?=\\n\\n|$)/us", $stub, $this->getContents());
		}

		$this->put($stub);
	}

	/**
	 * Get list of files to concat
	 *
	 * @param  string $language
	 * @return string
	 */
	public function getFilesToConcat($language)
	{
		$files = $this->getConfigOption("{$language}_concat");

		return $this->removeFileExtensions($this->removeMergedFilesFromList($files));
	}

	/**
	 * We don't want merged file to ever be included.
	 *
	 * @param  array  $fileList
	 * @return array
	 */
	protected function removeMergedFilesFromList(array $fileList)
	{
		return array_filter($fileList, function($file)
		{
			return ! preg_match('/\.min\.(js|css)$/i', $file);
		});
	}

	/**
	 * Removes extensions from array of files
	 *
	 * @param  array $fileList
	 * @return string
	 */
	public function removeFileExtensions(array $fileList)
	{
		return array_map(function($file)
		{
			// If no extension is present, then it's set in the
			// config file, like vendor/jquery. In those cases,
			// just return the $file as it is.
			if (! pathinfo($file, PATHINFO_EXTENSION)) return $file;

			return pathinfo($file, PATHINFO_FILENAME);
		}, $fileList);
	}

}