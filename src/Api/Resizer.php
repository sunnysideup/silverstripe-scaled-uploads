<?php

namespace Sunnysideup\ScaledUploads\Api;

use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use Exception;
use ReflectionClass;
use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataList;

class Resizer
{
    use Injectable;
    use Configurable;


    private static $bypass_all = false;

    /**
     *
     * file patterns to skip - e.g. '__resampled'
     * @var array
     */
    private static array $patterns_to_skip = [];

    /**
     * names of folders that should be treated differently
     *
     * @var array
     */
    private static array $custom_folders = [];

    /**
     * names of folders that should be treated differently
     *
     * @var array
     */
    private static array $custom_relations = [];

    /**
     * Maximum width
     *
     * @config
     */
    private static int $max_width = 3600;

    /**
     * Maximum height
     *
     * @config
     */
    private static int $max_height = 2160; // 0.6y of width

    /**
     * Maximum size of the file in megabytes
     *
     * @config
     */
    private static float $max_size_in_mb = 0.8;

    /**
     * Default resize quality
     *
     * @config
     */
    private static float $default_quality = 0.90;

    /**
     * Replace images with WebP format
     *
     * @config
     */
    private static bool $use_webp = true;

    /**
     * Replace images with WebP format
     *
     * @config
     */
    private static bool $keep_original = false;

    /**
     * When trying to get in range for size, we keep reducing the quality by this step.
     * Until the image is small enough.
     * @var float
     */
    private static float $quality_reduction_increment = 0.05;

    /**
     * Force resampling of images even if not stricly necessary
     *
     * @config
     */
    private static bool $force_resampling = false;
    protected bool $dryRun = false;
    protected bool $verbose = false;
    protected bool $bypass;
    protected array $patternsToSkip;
    protected array $customFolders;
    protected array $customRelations;
    protected int|null $maxWidth;
    protected int|null $maxHeight;
    protected float|null $maxSizeInMb;
    protected float $quality;
    protected bool $useWebp;
    protected float $qualityReductionIncrement;
    protected bool $keepOriginal;
    protected bool|null $forceResampling;
    protected Image_Backend $transformed;
    protected $file;
    protected $filePath;
    protected string $tmpImagePath;
    protected string|null $tmpImageContent;
    protected array $originalValues = [];
    private const CUSTOM_VALUES_ALLOWED = [
        'bypass',
        'patternsToSkip',
        'customFolders',
        'customRelations',
        'maxWidth',
        'maxHeight',
        'maxSizeInMb',
        'quality',
        'useWebp',
        'keepOriginal',
        'forceResampling',
    ];



    public function setDryRun(?bool $dryRun): static
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    public function setVerbose(?bool $verbose = true): static
    {
        $this->verbose = $verbose;
        return $this;
    }

    public function setBypassAll(bool $bypass): static
    {
        $this->bypass = $bypass;
        return $this;
    }

    public function setPatternsToSkip(array $array): static
    {
        $this->patternsToSkip = $array;
        return $this;
    }

    public function setCustomFolders(array $folders): static
    {
        $this->customFolders = $folders;
        return $this;
    }

    public function setCustomRelations(array $relations): static
    {
        $this->customRelations = $relations;
        return $this;
    }

    public function setMaxFileSizeInMb(float|int $maxSizeInMb): static
    {
        $this->maxSizeInMb = $maxSizeInMb;
        return $this;
    }

    public function setMaxWidth(int $maxWidth): static
    {
        $this->maxWidth = $maxWidth;
        return $this;
    }
    public function setMaxHeight(int $maxHeight): static
    {
        $this->maxHeight = $maxHeight;
        return $this;
    }

    public function setQuality(float $quality): static
    {
        $this->quality = $quality;
        return $this;
    }

    public function setUseWebp(bool $useWebp): static
    {
        $this->useWebp = $useWebp;
        return $this;
    }

    public function setKeepOriginal(bool $keepOriginal): static
    {
        $this->keepOriginal = $keepOriginal;
        return $this;
    }

    public function setQualityReductionIncrement(float $qualityReductionIncrement): static
    {
        $this->qualityReductionIncrement = $qualityReductionIncrement;
        return $this;
    }

    public function setForceResampling(bool $forceResampling): static
    {
        $this->forceResampling = $forceResampling;
        return $this;
    }


    public function __construct()
    {
        $this->bypass          = $this->config()->get('bypass_all');
        $this->patternsToSkip  = $this->config()->get('patterns_to_skip');
        $this->customFolders   = $this->config()->get('custom_folders');
        $this->customRelations = $this->config()->get('custom_relations');
        $this->maxWidth        = $this->config()->get('max_width');
        $this->maxHeight       = $this->config()->get('max_height');
        $this->maxSizeInMb     = $this->config()->get('max_size_in_mb');
        $this->quality         = $this->config()->get('default_quality');
        $this->useWebp         = $this->config()->get('use_webp');
        $this->keepOriginal    = $this->config()->get('keep_original');
        $this->forceResampling = $this->config()->get('force_resampling');
    }

    public function getAllProperties(): array
    {
        $result = [];
        foreach (self::CUSTOM_VALUES_ALLOWED as $key) {
            $result[$key] = $this->$key;
        }

        return $result;
    }

    /**
     * Scale an image
     *
     *
     * @return null
     */
    public function runFromDbFile(Image $file): Image
    {
        if ($this->dryRun) {
            $this->verbose = true;
        }
        $this->file = $file;
        if ($this->verbose) {
            echo '---' . PHP_EOL;
            if ($this->dryRun) {
                echo 'DRY RUN' . PHP_EOL;
            } else {
                echo 'REAL RUN' . PHP_EOL;
            }
        }
        $this->filePath = $this->file->getFilename();
        if (!$this->filePath) {
            if ($this->verbose) {
                echo 'ERROR: Cannot convert image with ID ' . $file->ID . ' as Filename is empty.' . PHP_EOL;
            }
            return $this->file;
        }
        // we do this first as it may contain the bypass flag
        $this->saveOriginalSettings();
        $this->applyCustomFolders();
        $this->applyCustomRelations();

        if (! $this->canBeConverted($this->filePath, $this->file->getExtension())) {
            if ($this->verbose) {
                echo 'Skipping: ' . $this->filePath . PHP_EOL;
            }
            return $this->file;
        }
        if (
            $this->forceResampling
            // || $this->needsRotating()
            || $this->needsResizing()
            || $this->needsConvertingToWebp()
            || $this->needsCompressing()
            // check if not webp and use webp
        ) {
            if ($this->loadBackend()) {
                $modified = false;
                // clone original

                // If rotation allowed & JPG, test to see if orientation needs switching
                $modified = $this->convertToWebp() ? true : $modified;
                $modified = $this->resize() ? true : $modified;
                $modified = $this->compress() ? true : $modified;
                if ($modified || $this->forceResampling) {
                    $this->writeToFile();
                }

                @unlink($this->tmpImagePath); // delete tmp file
            } else {
                if ($this->verbose) {
                    echo 'ERROR: Cannot load backend for: ' . $this->filePath . PHP_EOL;
                }
            }
        } else {
            if ($this->verbose) {
                echo 'No need to resize / convert: ' . $this->filePath . PHP_EOL;
            }
        }
        return $file;
    }


    protected function canBeConverted(string $filePath, string $extension): bool
    {

        if ($this->bypass) {
            return false;
        }
        if (! $this->file->getIsImage()) {
            return false;
        }
        foreach ($this->patternsToSkip as $pattern) {
            // Detect if the pattern is likely a regex
            if ($this->looksLikeRegex($pattern)) {
                // Treat it as a regex
                if (preg_match($pattern, $filePath)) {
                    return false;
                }
            } else {
                if (strpos($filePath, $pattern) !== false) {
                    return false;
                }
            }
        }
        return true;
    }

    protected function saveOriginalSettings()
    {
        // Check if original values need to be restored
        if (!empty($this->originalValues)) {
            foreach ($this->originalValues as $key => $value) {
                $this->$key = $value; // Restore original values
            }
            $this->originalValues = []; // Clear after restoration
        }
    }


    /**
     *
     * Allows you to add custom settings at runtime without changing the config layer
     * @return void
     */
    protected function applyCustomFolders(?array $moreCustomValues = []): void
    {
        $filePath = $this->filePath;
        $folder = trim(strval(dirname($filePath)), DIRECTORY_SEPARATOR);
        // Check if original values need to be restored
        if (!empty($this->originalValues)) {
            foreach ($this->originalValues as $key => $value) {
                $this->$key = $value; // Restore original values
            }
            $this->originalValues = []; // Clear after restoration
        }

        // Apply custom folder settings if available
        if (!empty($this->customFolders[$folder]) && is_array($this->customFolders[$folder])) {
            $this->applyCustomRules($this->customFolders[$folder]);
            $this->applyCustomRules($moreCustomValues);
        }
    }

    /**
     *
     * Allows you to add custom settings at runtime without changing the config layer
     * @return void
     */
    protected function applyCustomRelations(?array $moreCustomValues = []): void
    {
        $filePath = $this->filePath;
        $folder = trim(strval(dirname($filePath)), DIRECTORY_SEPARATOR);
        $customRelationKey = $this->getCustomRelationsKey();
        // Apply custom folder settings if available
        if (!empty($this->customRelations[$customRelationKey]) && is_array($this->customRelations[$customRelationKey])) {
            $this->applyCustomRules($this->customFolders[$customRelationKey]);
            $this->applyCustomRules($moreCustomValues);
        }
    }


    protected function applyCustomRules(array $toApply)
    {
        foreach ($toApply as $key => $val) {
            //snakeToCamelCase
            $key = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (!in_array($key, self::CUSTOM_VALUES_ALLOWED)) {
                user_error(
                    'Invalid custom folder setting: ' . $key . '.' .
                        'Allowed values are: ' . print_r(self::CUSTOM_VALUES_ALLOWED, 1),
                    E_USER_WARNING
                );
            }
            // Store the original value if not already stored
            $this->originalValues[$key] = $this->$key ?? null;
            // Apply the custom value
            $this->$key = $val;
        }
    }

    protected function looksLikeRegex(string $pattern): bool
    {
        $delimiters = ['/', '#', '~', '%']; // Common regex delimiters
        $firstChar = $pattern[0] ?? '';
        $lastChar = substr($pattern, -1);

        // Check if the first and last characters are the same and part of known delimiters
        return in_array($firstChar, $delimiters, true) && $firstChar === $lastChar;
    }

    protected function loadBackend(?Image $file = null): bool
    {
        // reset path, just in case...
        if (!$file) {
            $file = $this->file;
        }
        if (!$file) {
            if ($this->verbose) {
                echo 'ERROR: No file found to load backend.' . PHP_EOL;
            }
            return false;
        }
        $backend = $file->getImageBackend();
        if (! $backend) {
            if ($this->verbose) {
                echo 'ERROR: No backend found for file: ' . $file->getFilename() . PHP_EOL;
            }
            return false;
        }
        $this->transformed = $backend;

        // temporary location for image manipulation
        $this->tmpImagePath = TEMP_FOLDER . '/resampled-' . mt_rand(100000, 999999) . '.' . $file->getExtension();

        $this->tmpImageContent = $this->transformed->getImageResource();
        if ($this->tmpImageContent !== null) {

            // write to tmp file
            @file_put_contents($this->tmpImagePath, $this->tmpImageContent);

            $this->transformed->loadFrom($this->tmpImagePath);

            if ($this->transformed->getImageResource()) {
                return true;
            }
        }
        return false;
    }


    public function needsResizing(): bool
    {
        return ($this->maxWidth && $this->file->getWidth() > $this->maxWidth)
            || ($this->maxHeight && $this->file->getHeight() > $this->maxHeight);
    }

    public function needsConvertingToWebp(): bool
    {
        return $this->useWebp && $this->file->getExtension() !== 'webp';
    }

    public function needsCompressing(): bool
    {
        return ($this->maxSizeInMb && $this->file->getAbsoluteSize() > $this->maxSizeInMb * 1024 * 1024);
    }

    protected function resize(): bool
    {
        $modified = false;
        // resize to max values
        if ($this->transformed && $this->needsResizing()) {
            if ($this->verbose) {
                echo 'Resizing to a max of ' . ($this->maxWidth ?: '[any width]') . 'x' . ($this->maxHeight ?: '[any height]') . ': ' . $this->filePath . PHP_EOL;
            }
            if ($this->dryRun) {
                return false;
            }
            $modified = true;
            if ($this->maxWidth && $this->maxHeight) {
                $this->transformed = $this->transformed->resizeRatio($this->maxWidth, $this->maxHeight);
            } elseif ($this->maxWidth) {
                $this->transformed = $this->transformed->resizeByWidth($this->maxWidth);
            } else {
                $this->transformed = $this->transformed->resizeByHeight($this->maxHeight);
            }
        }
        return $modified;
    }

    protected function convertToWebp(): bool
    {
        $modified = false;
        // Convert to WebP and save
        if ($this->transformed && $this->needsConvertingToWebp()) {
            if ($this->verbose) {
                echo 'Converting to webp: ' . $this->filePath . PHP_EOL;
            }
            if ($this->dryRun) {
                return false;
            }
            $modified = true;
            if ($this->keepOriginal) {
                $folder = Controller::join_links(
                    Director::baseFolder(),
                    '.original_assets/',
                    $this->file->Parent()?->getFilename()
                );
                if (! file_exists($folder)) {
                    mkdir($folder, 0777, true);
                }
                if (! file_exists($folder)) {
                    user_error('Could not create folder: ' . $folder, E_USER_WARNING);
                    return false;
                }
                $newName = $folder . DIRECTORY_SEPARATOR . $this->file->Name;
                $x = 2;
                while (file_exists($newName)) {
                    $pathInfo = pathinfo($newName);
                    // Create the new filename by inserting '.2' before the extension
                    $newName = $pathInfo['dirname'] . DIRECTORY_SEPARATOR . $pathInfo['filename'] . '-v' . $x . '.' . $pathInfo['extension'];
                    $x++;
                }
                if ($this->verbose) {
                    echo 'Copying original file to ' . $folder . DIRECTORY_SEPARATOR . $this->file->Name . PHP_EOL;
                }
                $string = $this->file->getString();
                file_put_contents($newName, $string);
                if (!file_exists($newName)) {
                    user_error('Could not copy original file to ' . $folder . DIRECTORY_SEPARATOR . $this->file->Name, E_USER_WARNING);
                    return false;
                }
                try {
                } catch (Exception $e) {
                    if ($this->verbose) {
                        echo 'ERROR: Cannot copy original file: ' . $e->getMessage() . PHP_EOL;
                        echo 'to ' . $folder . DIRECTORY_SEPARATOR . $this->file->Name . PHP_EOL;
                        return false;
                    }
                }
            }
            /**
             * @var  DBFile $tmpFile $tmpFile
             */
            if ($this->file->hasMethod('Convert')) {
                $tmpFile = $this->file->Convert('webp');
                $this->deleteOldFile();
                $this->file->File = $tmpFile;
                $this->filePath .= '.webp';
                $this->file->setFromString($tmpFile->getImageBackend()->getImageResource(), $this->filePath);
                $this->saveAndPublish($this->file);
                $this->loadBackend();
            }
        }
        return $modified;
    }

    protected function compress(): bool
    {
        $modified = false;
        if (empty($this->qualityReductionIncrement)) {
            $this->qualityReductionIncrement = Config::inst()->get(static::class, 'quality_reduction_increment') ?: 0.05;
        }
        // Check if WebP is smaller
        if ($this->transformed && $this->needsCompressing() && $this->qualityReductionIncrement > 0) {
            if ($this->verbose) {
                echo 'Compressing to ' . $this->maxSizeInMb . 'MB: ' . $this->filePath . PHP_EOL;
            }
            if ($this->dryRun) {
                return false;
            }
            $this->transformed->writeTo($this->tmpImagePath);
            $sizeCheck = $this->fileIsTooBig($this->tmpImagePath);
            $step = 1;
            while ($sizeCheck && $step > 0) {
                // reduce quality
                $modified = true;
                unlink($this->tmpImagePath);
                $this->transformed->setQuality($this->quality * $step * 100);
                $this->transformed->writeTo($this->tmpImagePath);
                // new round
                $sizeCheck = $this->fileIsTooBig($this->tmpImagePath);
                $step -= $this->qualityReductionIncrement;
            }
        }
        return $modified;
    }


    protected function writeToFile()
    {
        if ($this->dryRun) {
            return;
        }
        // write to tmp file and then overwrite original
        if ($this->transformed) {
            $this->transformed->writeTo($this->tmpImagePath);
            // if !legacy_filenames then delete original, else rogue copies are left on filesystem
            if (file_exists($this->tmpImagePath)) {
                $this->deleteOldFile();
                $this->file->setFromLocalFile($this->tmpImagePath, $this->filePath); // set new image
                $this->saveAndPublish($this->file);
            }
        }
    }

    protected function deleteOldFile()
    {
        if ($this->dryRun) {
            return;
        }
        if (!Config::inst()->get(FlysystemAssetStore::class, 'legacy_filenames')) {
            $this->file->File->deleteFile();
        }
    }

    protected function fileIsTooBig(string $filePath): bool
    {
        $fileSize = filesize($filePath);
        $maxSize = $this->maxSizeInMb * 1024 * 1024;
        if ($fileSize > $maxSize) {
            return true;
        }
        return false;
    }

    protected function saveAndPublish(Image $image)
    {
        if ($this->dryRun) {
            return;
        }
        $isPublished = $image->isPublished()  && ! $image->isModifiedOnDraft();
        $image->write();
        if ($isPublished) {
            $image->publishSingle();
        }
    }


    public function getCustomRelationsKey(): ?string
    {
        $list = $this->file->findAllRelatedData();
        $count = $list->count();
        if ($count > 1) {
            return null;
        } else {
            $item = $list->first();
            $classes = $this->getRelationsWithSpecialRules();
            foreach ($classes as $classNameAndFieldKey => $details) {
                $className = $details['ClassName'] ?? 'ERROR';
                if ($item instanceof $className) {
                    $fieldName = $details['FieldOrMethodName'] ?? 'ERROR';
                    $image = null;
                    if ($item->hasMethod($fieldName)) {
                        $image = $item->$fieldName();
                        if ($image instanceof DataList) {
                            $image = $image->filter('ID', $this->file->ID);
                        } elseif ($image instanceof Image) {
                            // do nothing
                        } else {
                            user_error('ERROR: ' . $fieldName . ' is not a valid method or field on ' . $className . ' to get an image.');
                        }
                    }
                    if ($image && $image->exists() && $this->file->ID === $image->ID) {
                        return $classNameAndFieldKey;
                    }
                }
            }
        }
        return null;
    }

    protected static $classes_with_images;

    public function getRelationsWithSpecialRules(): array
    {
        if (!isset(self::$classes_with_images)) {
            self::$classes_with_images = [];
            $all = Config::inst()->get(static::class, 'custom_relations');
            foreach (array_keys($all) as $usedByItem) {
                $usedByItemArray = explode('.', $usedByItem);
                $usedByClass = $usedByItemArray[0] ?? '';
                $usedByFieldOrMethod = $usedByItemArray[1] ?? '';
                if (!$usedByClass || !class_exists($usedByClass)) {
                    user_error('ERROR: ' . $usedByClass . ' is not a valid class');
                    continue;
                }
                if (!$usedByFieldOrMethod) {
                    user_error('ERROR: ' . $usedByClass . ' is not a valid class');
                    continue;
                }
                self::$classes_with_images[$usedByItem] = [
                    'ClassName' => $usedByClass,
                    'FieldOrMethodName' => $usedByFieldOrMethod,
                ];
            }
        }

        return self::$classes_with_images;
    }
}
