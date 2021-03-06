<?php
/**
 * Compiler.php
 *
 * @copyright      More in license.md
 * @license        https://www.ipublikuj.eu
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 * @package        iPublikuj:AssetsLoader!
 * @subpackage     Compilers
 * @since          1.0.0
 *
 * @date           29.12.13
 */

declare(strict_types = 1);

namespace IPub\AssetsLoader\Compilers;

use Nette;
use Nette\Utils;

use IPub\AssetsLoader\Caching;
use IPub\AssetsLoader\Diagnostics;
use IPub\AssetsLoader\Entities;
use IPub\AssetsLoader\Exceptions;
use IPub\AssetsLoader\Filters;

abstract class Compiler
{
	/**
	 * Implement nette smart magic
	 */
	use Nette\SmartObject;

	/**
	 * Define compilers types
	 */
	protected const TYPE_CSS = 'css';
	protected const TYPE_JS = 'js';

	/**
	 * @var string
	 */
	protected $type;

	/**
	 * Compressed file name
	 *
	 * @var string
	 */
	protected $filename;

	/**
	 * @var Filters\Content\IContentFilter[]
	 */
	protected $contentFilters = [];

	/**
	 * @var Filters\Files\IFilesFilter[]
	 */
	protected $fileFilters = [];

	/**
	 * @var Caching\AssetCache
	 */
	protected $cache;

	/**
	 * @var Diagnostics\Panel
	 */
	protected $debugPanel;

	/**
	 * @param Caching\AssetCache $cache
	 * @param Diagnostics\Panel $debugPanel
	 */
	public function __construct(Caching\AssetCache $cache, Diagnostics\Panel $debugPanel = NULL)
	{
		$this->cache = $cache;
		$this->debugPanel = $debugPanel;
	}

	/**
	 * @param Filters\IFilter $filter
	 *
	 * @return void
	 *
	 * @throws Exceptions\InvalidArgumentException
	 */
	public function addFilter(Filters\IFilter $filter) : void
	{
		// Filter is content type filter
		if ($filter instanceof Filters\Content\IContentFilter) {
			$this->contentFilters[] = $filter;

			// Filter is file type filter
		} else if ($filter instanceof Filters\Files\IFilesFilter) {
			$this->fileFilters[] = $filter;

		} else {
			throw new Exceptions\InvalidArgumentException('Unknown filter.');
		}
	}

	/**
	 * @param Filters\Content\IContentFilter $filter
	 *
	 * @return void
	 */
	public function addContentFilter(Filters\Content\IContentFilter $filter) : void
	{
		$this->contentFilters[] = $filter;
	}

	/**
	 * @return array
	 */
	public function getContentFilters() : array
	{
		return $this->contentFilters;
	}

	/**
	 * @param Filters\Files\IFilesFilter $filter
	 *
	 * @return void
	 */
	public function addFilesFilter(Filters\Files\IFilesFilter $filter) : void
	{
		$this->fileFilters[] = $filter;
	}

	/**
	 * @return array
	 */
	public function getFilesFilters() : array
	{
		return $this->fileFilters;
	}

	/**
	 * Load content and save file
	 *
	 * @param Entities\IFile[] $files
	 * @param string $contentType
	 *
	 * @return Utils\ArrayHash
	 */
	public function generate(array $files, string $contentType) : Utils\ArrayHash
	{
		// Init vars
		$name = $this->getFilename($files);
		$hash = $this->getHash($files);
		$lastModified = $this->getLastModified($files);

		if ($this->cache->load($hash) === NULL) {
			$before = memory_get_peak_usage();
			$content = $this->getContent($files);

			// Add compiled files into diagnostics panel
			if ($this->debugPanel) {
				$this->debugPanel->addFile($files, $hash, $this->type, $lastModified, memory_get_peak_usage() - $before);
			}

			$this->cache->save(
				$hash,
				[
					Caching\AssetCache::CONTENT_TYPE => $contentType,
					Caching\AssetCache::CONTENT      => $content
				],
				[
					Caching\AssetCache::TAGS  => ['ipub.assetsloader', 'ipub.assetsloader.assets'],
					Caching\AssetCache::FILES => array_keys($files)
				]
			);
		}

		return Utils\ArrayHash::from([
			'file'         => $name,
			'hash'         => $hash,
			'lastModified' => $lastModified,
			'sourceFiles'  => $files,
		]);
	}

	/**
	 * Get last modified timestamp of newest file
	 *
	 * @param array $files
	 *
	 * @return int
	 */
	protected function getLastModified(array $files) : int
	{
		$modified = 0;

		/** @var Entities\IFile $file */
		foreach ($files as $file) {
			$modified = max($modified, filemtime($file->getPath()));
		}

		return $modified;
	}

	/**
	 * Create generated file filename
	 *
	 * @param array $files
	 *
	 * @return string
	 */
	protected function getFilename(array $files) : string
	{
		$name = $this->getHash($files);

		if (count($files) === 1) {
			$file = reset($files);
			$name .= '-' . pathinfo($file->getPath(), PATHINFO_FILENAME);
		}

		return sprintf($this->filename, $name);
	}

	/**
	 * Create files collection hash
	 *
	 * @param array $files
	 *
	 * @return string
	 */
	protected function getHash(array $files) : string
	{
		$tmp = [];

		foreach ($files as $file) {
			$tmp[] = (string) $file . $file->getFileSize();
		}

		return substr(md5(implode(';', $tmp)), 0, 12);
	}

	/**
	 * Load file content
	 *
	 * @param Entities\IFile $file
	 *
	 * @return string
	 */
	protected function loadFile(Entities\IFile $file) : string
	{
		$content = file_get_contents($file->getPath());

		foreach ($this->fileFilters as $filter) {
			$content = call_user_func($filter, $content, $this, $file);
		}

		return $content;
	}

	/**
	 * Get joined content of all files
	 *
	 * @param Entities\IFile[] $files
	 *
	 * @return string
	 */
	protected function getContent(array $files) : string
	{
		// Load content
		$content = '';

		foreach ($files as $file) {
			$content .= PHP_EOL . $this->loadFile($file);
		}

		// Apply content filters
		foreach ($this->contentFilters as $filter) {
			$content = call_user_func($filter, $content, $this);
		}

		return $content;
	}
}
