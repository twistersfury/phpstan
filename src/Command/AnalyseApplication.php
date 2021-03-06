<?php declare(strict_types = 1);

namespace PHPStan\Command;

use PHPStan\Analyser\Analyser;
use PHPStan\Analyser\Error;
use PHPStan\Cache\Cache;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\File\FileExcluder;
use PHPStan\File\FileHelper;
use Symfony\Component\Console\Style\OutputStyle;
use Symfony\Component\Finder\Finder;

class AnalyseApplication
{

	/** @var \PHPStan\Analyser\Analyser */
	private $analyser;

	/** @var string */
	private $memoryLimitFile;

	/** @var string[] */
	private $fileExtensions;

	/** @var \PHPStan\File\FileHelper */
	private $fileHelper;

	/** @var \PHPStan\File\FileExcluder */
	private $fileExcluder;

	/** @var \PHPStan\Cache\Cache */
	private $cache;

	/** @var bool */
	private $debugMode = false;

	/**
	 * @var OutputStyle
	 */
	private $outputStyle = null;

	/**
	 * @param Analyser $analyser
	 * @param string $memoryLimitFile
	 * @param FileHelper $fileHelper
	 * @param string[] $fileExtensions
	 * @param FileExcluder $fileExcluder
	 */
	public function __construct(
		Analyser $analyser,
		string $memoryLimitFile,
		FileHelper $fileHelper,
		array $fileExtensions,
		FileExcluder $fileExcluder,
		Cache $cache
	)
	{
		$this->analyser = $analyser;
		$this->memoryLimitFile = $memoryLimitFile;
		$this->fileExtensions = $fileExtensions;
		$this->fileHelper = $fileHelper;
		$this->fileExcluder = $fileExcluder;
		$this->cache = $cache;
	}

	/**
	 * @param string[] $paths
	 * @param \Symfony\Component\Console\Style\OutputStyle $style
	 * @param \PHPStan\Command\ErrorFormatter\ErrorFormatter $errorFormatter
	 * @param bool $defaultLevelUsed
	 * @param bool $debug
	 * @param bool $enableCache
	 * @param bool $clearCache
	 * @return int Error code.
	 */
	public function analyse(
		array $paths,
		OutputStyle $style,
		ErrorFormatter $errorFormatter,
		bool $defaultLevelUsed,
		bool $debug,
		bool $enableCache,
		bool $clearCache
	): int
	{
		if (count($paths) === 0) {
			throw new \InvalidArgumentException('At least one path must be specified to analyse.');
		}

		$this->debugMode = $debug;
		$this->outputStyle = $style;

		$errors = [];
		$files = [];

		$this->updateMemoryLimitFile();

		$paths = array_map(function (string $path): string {
			return $this->fileHelper->absolutizePath($path);
		}, $paths);

		$onlyFiles = true;
		foreach ($paths as $path) {
			if (!file_exists($path)) {
				$errors[] = new Error(sprintf('<error>Path %s does not exist</error>', $path), $path, null, false);
			} elseif (is_file($path)) {
				$files[] = $this->fileHelper->normalizePath($path);
			} else {
				$files = $files + $this->loadFiles($path, $onlyFiles, $enableCache, $clearCache);
			}
		}

		$files = array_filter($files, function (string $file): bool {
			return !$this->fileExcluder->isExcludedFromAnalysing($file);
		});

		$this->updateMemoryLimitFile();

		if (!$debug) {
			$progressStarted = false;
			$fileOrder = 0;
			$preFileCallback = null;
			$postFileCallback = function () use ($style, &$progressStarted, $files, &$fileOrder): void {
				if (!$progressStarted) {
					$style->progressStart(count($files));
					$progressStarted = true;
				}
				$style->progressAdvance();
				if ($fileOrder % 100 === 0) {
					$this->updateMemoryLimitFile();
				}
				$fileOrder++;
			};
		} else {
			$preFileCallback = function (string $file) use ($style): void {
				$style->writeln($file);
			};
			$postFileCallback = null;
		}

		$errors = array_merge($errors, $this->analyser->analyse(
			$files,
			$onlyFiles,
			$preFileCallback,
			$postFileCallback,
			$debug
		));

		if (isset($progressStarted) && $progressStarted) {
			$style->progressFinish();
		}

		$fileSpecificErrors = [];
		$notFileSpecificErrors = [];
		foreach ($errors as $error) {
			if (is_string($error)) {
				$notFileSpecificErrors[] = $error;
			} elseif ($error instanceof Error) {
				$fileSpecificErrors[] = $error;
			} else {
				throw new \PHPStan\ShouldNotHappenException();
			}
		}

		return $errorFormatter->formatErrors(
			new AnalysisResult(
				$fileSpecificErrors,
				$notFileSpecificErrors,
				$defaultLevelUsed,
				$this->fileHelper->normalizePath(dirname($paths[0]))
			),
			$style
		);
	}

	private function logMessage($message, $debugMode = false): self
	{
		if (!$debugMode || $debugMode && $this->debugMode) {
			$this->outputStyle->writeln($message);
		}

		return $this;
	}

	private function loadFiles(string $path, &$onlyFiles, bool $enableCache, bool $clearCache): array
	{
		if ($enableCache && !$clearCache) {
			$this->logMessage('Loading Files From Cache...');
			if (($files = $this->cache->load('analyse-files-' . $path)) !== null) {
				$this->logMessage(
					sprintf('Loaded %d files from cache', count($files)),
					true
				);
			}

			return $files;
		}

		$this->logMessage('Scanning File System');

		$files = [];
		$finder = new Finder();
		$finder->followLinks();
		/** @var \SplFileInfo $fileInfo */
		foreach ($finder->files()->name('*.{' . implode(',', $this->fileExtensions) . '}')->in($path) as $fileInfo) {
			$files[] = $this->fileHelper->normalizePath($fileInfo->getPathname());
			$onlyFiles = false;
		}

		$this->logMessage(
			sprintf('Loaded %d files from system', count($files)),
			true
		);

		if ($enableCache) {
			$this->logMessage('Saving Cache');
			$this->cache->save('analyse-files-' . $path, $files);
		}

		return $files;
	}

	private function updateMemoryLimitFile(): void
	{
		$bytes = memory_get_peak_usage(true);
		$megabytes = ceil($bytes / 1024 / 1024);
		file_put_contents($this->memoryLimitFile, sprintf('%d MB', $megabytes));

		if (!function_exists('pcntl_signal_dispatch')) {
			return;
		}

		pcntl_signal_dispatch();
	}

}
